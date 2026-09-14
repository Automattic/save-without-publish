<?php
/**
 * Drift tests for U6 (R12, R13).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Drift;
use SaveWithoutPublish\Staged_Copy_Repository;
use WP_UnitTestCase;

/**
 * Proves a merge cannot overwrite a published change the editor was not shown.
 */
class Test_Drift extends WP_UnitTestCase {

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Its staged copy.
	 *
	 * @var int
	 */
	private int $staged_copy_id;

	/**
	 * Stages a published post.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Launches in June.',
			)
		);

		$this->staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;
	}

	/**
	 * Moves the live post and returns its new GMT modified time.
	 *
	 * A direct row write, not `wp_update_post()`: the write guard contains
	 * every write to a post that already has a staged copy (R55), which
	 * this fixture's `set_up()` always leaves it with, and that containment
	 * is unconditional Tier 1 -- no capability or channel changes it. Before
	 * the fingerprint existed (VIPPROD-752) that made no difference here,
	 * since `wp_update_post()` being silently blocked and `$content` never
	 * landing was invisible to a timestamp-only comparison; now it would
	 * make every "content changed" test below false by construction. The
	 * timestamp is written in the same query rather than left to core,
	 * which sets `post_modified_gmt` to the current time on every update:
	 * without an explicit stamp a test edit lands in the same second as the
	 * fork, the two timestamps match, and a genuine drift test silently
	 * reports no drift.
	 *
	 * @param string $content New content.
	 * @param string $when    GMT timestamp to stamp the edit with.
	 * @return string The live post's new `post_modified_gmt`.
	 */
	private function move_live( string $content, string $when = '2026-08-13 09:00:00' ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- No core API can set this field; core overwrites it on every update. The content is written here too, for the same reason (R55 blocks wp_update_post unconditionally once a copy exists).
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_content'      => $content,
				'post_modified'     => $when,
				'post_modified_gmt' => $when,
			),
			array( 'ID' => $this->live_id )
		);

		clean_post_cache( $this->live_id );

		return get_post( $this->live_id )->post_modified_gmt;
	}

	/**
	 * Changes the live post's content directly, leaving its timestamp
	 * exactly as it was (VIPPROD-752, F1).
	 *
	 * A direct row write, deliberately: this is what a database restore or
	 * a migration tool does, and it is the one case a timestamp comparison
	 * alone cannot see.
	 *
	 * @param string $content New content.
	 * @return void
	 */
	private function change_live_content_without_moving_the_timestamp( string $content ): void {
		global $wpdb;

		$before = get_post( $this->live_id )->post_modified_gmt;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Simulating a restore or direct write that changes content but never touches the timestamp.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $content ),
			array( 'ID' => $this->live_id )
		);

		clean_post_cache( $this->live_id );

		$this->assertSame(
			$before,
			get_post( $this->live_id )->post_modified_gmt,
			'Precondition: the timestamp must not have moved.'
		);
	}

	/**
	 * A freshly forked staged copy has not drifted.
	 */
	public function test_a_fresh_fork_has_not_drifted(): void {
		$this->assertFalse( Drift::has_drifted( $this->staged_copy_id ) );
		$this->assertTrue( Drift::check( $this->staged_copy_id ) );
	}

	/**
	 * The fork writes a fingerprint of the live post's staged fields, not
	 * just its timestamp -- what tells a content change apart from
	 * everything else that can move `post_modified_gmt`, and what catches
	 * one even when the timestamp does not move at all.
	 */
	public function test_a_fresh_fork_carries_a_fingerprint(): void {
		$fingerprint = get_post_meta(
			$this->staged_copy_id,
			Staged_Copy_Repository::FORK_FINGERPRINT_META,
			true
		);

		$this->assertSame( Drift::fingerprint( get_post( $this->live_id ) ), $fingerprint );
	}

	/**
	 * Covers AE3. A live change after the fork refuses the merge.
	 */
	public function test_a_live_change_refuses_the_merge(): void {
		$this->move_live( 'Launches in July.' );

		$this->assertTrue( Drift::has_drifted( $this->staged_copy_id ) );

		$result = Drift::check( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );
	}

	/**
	 * A content change that reaches the row without moving the timestamp
	 * is still caught (VIPPROD-752, F1) -- the one case the timestamp
	 * alone could never see, and the reason the fingerprint exists.
	 */
	public function test_content_changed_behind_a_preserved_timestamp_is_drift(): void {
		$this->change_live_content_without_moving_the_timestamp( 'Launches in July.' );

		$this->assertSame( 'content', Drift::kind( $this->staged_copy_id ) );

		$result = Drift::check( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );
		$this->assertSame( 'content', $result->get_error_data()['drift_kind'] );
	}

	/**
	 * A change to something other than title, content, or excerpt is a
	 * different kind of drift (VIPPROD-752, F2): the merge never writes
	 * those fields, so nothing here is something publishing could
	 * overwrite. It still refuses -- the published post did change -- but
	 * says so honestly, and offers no history link to a diff that would
	 * show nothing.
	 */
	public function test_a_change_to_other_fields_is_other_drift(): void {
		$this->move_live( get_post( $this->live_id )->post_content );

		$this->assertSame( 'other', Drift::kind( $this->staged_copy_id ) );

		$result = Drift::check( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );

		$data = $result->get_error_data();

		$this->assertSame( 'other', $data['drift_kind'] );
		$this->assertSame( '', $data['history_url'] );
		$this->assertStringContainsString( 'unchanged', $result->get_error_message() );
		$this->assertStringContainsString( 'will not overwrite', $result->get_error_message() );
	}

	/**
	 * Covers AE3. A token naming the exact state shown allows the merge.
	 */
	public function test_an_override_naming_the_shown_state_is_accepted(): void {
		$this->move_live( 'Launches in July.' );

		$shown = Drift::state( get_post( $this->live_id ) );

		$this->assertTrue( Drift::check( $this->staged_copy_id, $shown ) );
	}

	/**
	 * The token binds content as well as time (VIPPROD-752): a token
	 * captured before a content change that preserved the timestamp no
	 * longer matches once that change lands, so it cannot be replayed to
	 * override a content drift the timestamp alone did not register.
	 */
	public function test_the_token_binds_content_as_well_as_time(): void {
		// Captured while nothing has drifted yet.
		$stale = Drift::state( get_post( $this->live_id ) );

		$this->change_live_content_without_moving_the_timestamp( 'Launches in July.' );

		$result = Drift::check( $this->staged_copy_id, $stale );

		$this->assertWPError( $result, 'A token from before the content changed must not override it.' );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );

		$fresh = $result->get_error_data()['live_state'];

		$this->assertTrue( Drift::check( $this->staged_copy_id, $fresh ) );
	}

	/**
	 * A token that does not name the live post's current state is refused,
	 * whatever it claims to be.
	 *
	 * This is the whole reason the comparison is equality (KTD15): under an
	 * ordering test on a timestamp a client could send a value far in the
	 * future and pass the check on every request, forever. The state token
	 * this replaces that with has no order to game, so what is left to pin
	 * is that an arbitrary well-formed value is refused outright.
	 */
	public function test_a_token_that_does_not_match_the_live_state_is_refused(): void {
		$this->move_live( 'Launches in July.' );

		$result = Drift::check( $this->staged_copy_id, str_repeat( 'a', 64 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );
	}

	/**
	 * A token naming an earlier published state is refused once the post
	 * has moved again.
	 */
	public function test_an_override_naming_an_earlier_state_is_refused(): void {
		$this->move_live( 'Launches in July.', '2026-08-13 09:00:00' );
		$first = Drift::state( get_post( $this->live_id ) );

		$this->move_live( 'Launches in August.', '2026-08-13 10:00:00' );

		$result = Drift::check( $this->staged_copy_id, $first );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );
	}

	/**
	 * A malformed override is rejected rather than coerced into a comparison.
	 *
	 * @dataProvider malformed_overrides
	 *
	 * @param string $override The malformed value.
	 */
	public function test_a_malformed_override_is_rejected( string $override ): void {
		$this->move_live( 'Launches in July.' );

		$result = Drift::check( $this->staged_copy_id, $override );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_bad_override', $result->get_error_code() );
	}

	/**
	 * Override values that must never reach a comparison.
	 *
	 * A `post_modified_gmt`-shaped timestamp is deliberately included: that
	 * was the override's whole shape before VIPPROD-752, and it must not be
	 * silently accepted as half of the new token.
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public function malformed_overrides(): array {
		return array(
			'not hex'          => array( 'yes' ),
			'too short'        => array( str_repeat( 'a', 63 ) ),
			'too long'         => array( str_repeat( 'a', 65 ) ),
			'uppercase hex'    => array( str_repeat( 'A', 64 ) ),
			'a bare timestamp' => array( '2026-08-13 09:00:00' ),
			'trailing text'    => array( str_repeat( 'a', 64 ) . ' OR 1=1' ),
			'wildcard'         => array( '%' ),
		);
	}

	/**
	 * An override sent when nothing drifted is an ordinary merge, not a bypass.
	 */
	public function test_an_override_without_drift_is_not_a_bypass(): void {
		$this->assertTrue( Drift::check( $this->staged_copy_id, str_repeat( '0', 64 ) ) );
	}

	/**
	 * A token applies to the state it named and nothing later.
	 *
	 * Nothing is consumed or stored, because the baseline is written once at
	 * fork and never updated (I8): the next check re-compares against the same
	 * baseline and the live post's new state, so a second merge after a
	 * further live change refuses again on its own.
	 */
	public function test_an_override_does_not_persist_to_a_later_merge(): void {
		$this->move_live( 'Launches in July.', '2026-08-13 09:00:00' );
		$shown = Drift::state( get_post( $this->live_id ) );

		$this->assertTrue( Drift::check( $this->staged_copy_id, $shown ) );

		$this->move_live( 'Launches in August.', '2026-08-13 10:00:00' );

		$result = Drift::check( $this->staged_copy_id, $shown );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );
	}

	/**
	 * A touch with no content change is `other` drift, not `content`
	 * (VIPPROD-752): the fingerprint is unchanged, so the timestamp moving
	 * on its own is the one case this plugin was already careful never to
	 * mistake for something a merge could overwrite.
	 */
	public function test_a_touch_without_content_change_counts_as_drift(): void {
		$this->move_live( get_post( $this->live_id )->post_content );

		$this->assertSame(
			'Launches in June.',
			get_post( $this->live_id )->post_content,
			'The content should be unchanged; only the timestamp moved.'
		);

		$this->assertTrue( Drift::has_drifted( $this->staged_copy_id ) );
		$this->assertSame( 'other', Drift::kind( $this->staged_copy_id ) );
	}

	/**
	 * A missing baseline counts as drift rather than as agreement.
	 */
	public function test_a_missing_baseline_counts_as_drift(): void {
		delete_post_meta( $this->staged_copy_id, Staged_Copy_Repository::FORK_BASELINE_META );

		$this->assertTrue( Drift::has_drifted( $this->staged_copy_id ) );
		$this->assertSame( 'unknown', Drift::kind( $this->staged_copy_id ) );

		$result = Drift::check( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );
	}

	/**
	 * A copy with no recorded fingerprint reads as `unknown` drift once the
	 * timestamp moves, the same answer a missing baseline gets -- neither
	 * can tell content drift from anything else, so neither claims to.
	 */
	public function test_a_copy_without_a_fingerprint_is_unknown_drift(): void {
		delete_post_meta( $this->staged_copy_id, Staged_Copy_Repository::FORK_FINGERPRINT_META );

		$this->move_live( 'Launches in July.' );

		$this->assertSame( 'unknown', Drift::kind( $this->staged_copy_id ) );

		$result = Drift::check( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'unknown', $result->get_error_data()['drift_kind'] );

		$shown = Drift::state( get_post( $this->live_id ) );

		$this->assertTrue( Drift::check( $this->staged_copy_id, $shown ) );
	}

	/**
	 * A staged copy whose live post is gone cannot merge.
	 */
	public function test_a_staged_copy_with_no_live_post_cannot_merge(): void {
		wp_delete_post( $this->live_id, true );

		$result = Drift::check( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_no_live_post', $result->get_error_code() );
	}

	/**
	 * The refusal carries what the editor needs to review and confirm.
	 */
	public function test_the_refusal_carries_the_state_it_refused_against(): void {
		$shown = $this->move_live( 'Launches in July.' );

		$data = Drift::check( $this->staged_copy_id )->get_error_data();

		$this->assertSame( $shown, $data['live_modified'] );
		$this->assertSame( Drift::state( get_post( $this->live_id ) ), $data['live_state'] );
		$this->assertSame( $this->live_id, $data['live_id'] );
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( 'content', $data['drift_kind'] );
		$this->assertNotSame( $data['forked_at'], $data['live_modified'] );
	}

	/**
	 * The check re-reads the live post rather than trusting a cached copy.
	 *
	 * R21 leaves the window between an editor's drift check and their merge open
	 * by design, since an ungated writer can touch the live post at any moment.
	 * This simulates that: the post is loaded into cache, then changed behind
	 * the request's back.
	 *
	 * Also covers VIPPROD-752, F4: a direct SQL write that moves the
	 * timestamp is caught, and correctly read as `content` drift, since
	 * this write changes content too.
	 */
	public function test_the_check_re_reads_the_live_post(): void {
		global $wpdb;

		// Prime the cache with the pre-change state.
		get_post( $this->live_id );
		$this->assertFalse( Drift::has_drifted( $this->staged_copy_id ) );

		/*
		 * Change the row without touching the cache, as another process would.
		 * Deliberately uncached: leaving the stale value in the cache is the
		 * condition under test.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating an out-of-process write; caching it would erase the condition under test.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_content'      => 'Launches in July.',
				'post_modified_gmt' => '2026-08-13 09:00:00',
			),
			array( 'ID' => $this->live_id )
		);

		$result = Drift::check( $this->staged_copy_id );

		$this->assertWPError( $result, 'The check trusted a stale cached post.' );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );
		$this->assertSame( 'content', $result->get_error_data()['drift_kind'] );
	}

	/**
	 * The refused-merge history link opens whichever screen reviews a staged
	 * change (VIPPROD-753) -- the same choice `Review_Link::for_staged_copy()`
	 * makes, since both are answering "how does someone here look at a
	 * change".
	 *
	 * A post of its own, edited before any copy is staged against it, rather
	 * than the fixture's `live_id`: once a copy exists the write guard
	 * neutralizes a direct content write to the post it stages (R55), which
	 * would leave nothing here for a revision to record.
	 */
	public function test_history_url_follows_the_surface(): void {
		$live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'As published.',
			)
		);

		/*
		 * `wp_update_post()` from here is not the "cli" channel `classify()`
		 * carves out for WP-CLI's own process (KTD30b); called plainly, it is
		 * an unidentified write, and this plugin forks one into a staged
		 * copy rather than writing the post directly -- correctly, and not
		 * what this test is about. The filter is the direct-publish grant's
		 * own escape hatch for exactly this: answering per write rather than
		 * fighting a role's capabilities.
		 */
		add_filter( 'swpub_can_publish_directly', '__return_true' );

		// One edit is enough: both surfaces default their `from` to
		// "whatever came before", core's own reading on either screen, so
		// this proves that reading holds even with a single revision to
		// its name.
		wp_update_post(
			array(
				'ID'           => $live_id,
				'post_content' => 'Someone else moved this.',
			)
		);

		remove_filter( 'swpub_can_publish_directly', '__return_true' );

		$revisions = wp_get_post_revisions( $live_id, array( 'order' => 'ASC' ) );
		$newest    = (int) end( $revisions )->ID;

		$this->assertGreaterThan( 0, $newest, 'Precondition: the edits above have to have left revisions.' );

		add_filter( 'swpub_review_surface', fn () => 'editor' );

		$editor_url = Drift::history_url( $live_id );

		$this->assertStringContainsString( 'post.php', $editor_url );
		$this->assertStringContainsString( 'revision=' . $newest, $editor_url );

		add_filter( 'swpub_review_surface', fn () => 'classic' );

		$classic_url = Drift::history_url( $live_id );

		$this->assertStringContainsString( 'revision.php', $classic_url );
		$this->assertStringContainsString( 'revision=' . $newest, $classic_url );
		$this->assertStringNotContainsString( 'revision.php', $editor_url );
	}
}
