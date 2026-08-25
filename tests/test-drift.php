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
	 * The timestamp is stamped onto the row afterwards rather than passed to
	 * `wp_update_post`, which ignores it: core sets `post_modified_gmt` to the
	 * current time on every update. Without the stamp a test edit lands in the
	 * same second as the fork, the two timestamps match, and a genuine drift
	 * test silently reports no drift.
	 *
	 * @param string $content New content.
	 * @param string $when    GMT timestamp to stamp the edit with.
	 * @return string The live post's new `post_modified_gmt`.
	 */
	private function move_live( string $content, string $when = '2026-08-13 09:00:00' ): string {
		global $wpdb;

		wp_update_post(
			array(
				'ID'           => $this->live_id,
				'post_content' => $content,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- No core API can set this field; core overwrites it on every update.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $when,
				'post_modified_gmt' => $when,
			),
			array( 'ID' => $this->live_id )
		);

		clean_post_cache( $this->live_id );

		return get_post( $this->live_id )->post_modified_gmt;
	}

	/**
	 * A freshly forked staged copy has not drifted.
	 */
	public function test_a_fresh_fork_has_not_drifted(): void {
		$this->assertFalse( Drift::has_drifted( $this->staged_copy_id ) );
		$this->assertTrue( Drift::check( $this->staged_copy_id ) );
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
	 * Covers AE3. An override naming the exact state shown allows the merge.
	 */
	public function test_an_override_naming_the_shown_state_is_accepted(): void {
		$shown = $this->move_live( 'Launches in July.' );

		$this->assertTrue( Drift::check( $this->staged_copy_id, $shown ) );
	}

	/**
	 * A future timestamp is refused.
	 *
	 * This is the whole reason the comparison is equality rather than "not
	 * older" (KTD15): under an ordering test a client could send a timestamp
	 * years ahead and pass the drift check on every request, forever.
	 */
	public function test_an_override_newer_than_the_live_post_is_refused(): void {
		$this->move_live( 'Launches in July.' );

		$result = Drift::check( $this->staged_copy_id, '2099-01-01 00:00:00' );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );
	}

	/**
	 * An override naming a state older than the current one is refused.
	 */
	public function test_an_override_older_than_the_live_post_is_refused(): void {
		$first = $this->move_live( 'Launches in July.', '2026-08-13 09:00:00' );
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
	 * @return array<string, array{string}> Test cases.
	 */
	public function malformed_overrides(): array {
		return array(
			'not a date'      => array( 'yes' ),
			'wrong separator' => array( '2026-08-13T09:00:00' ),
			'no time'         => array( '2026-08-13' ),
			'trailing text'   => array( '2026-08-13 09:00:00 OR 1=1' ),
			'numeric'         => array( '99999999999' ),
			'wildcard'        => array( '%' ),
		);
	}

	/**
	 * An override sent when nothing drifted is an ordinary merge, not a bypass.
	 */
	public function test_an_override_without_drift_is_not_a_bypass(): void {
		$this->assertTrue( Drift::check( $this->staged_copy_id, '2026-08-13 09:00:00' ) );
	}

	/**
	 * An override applies to the state it named and nothing later.
	 *
	 * Nothing is consumed or stored, because the baseline is written once at
	 * fork and never updated (I8): the next check re-compares against the same
	 * baseline and the live post's new timestamp, so a second merge after a
	 * further live change refuses again on its own.
	 */
	public function test_an_override_does_not_persist_to_a_later_merge(): void {
		$shown = $this->move_live( 'Launches in July.', '2026-08-13 09:00:00' );

		$this->assertTrue( Drift::check( $this->staged_copy_id, $shown ) );

		$this->move_live( 'Launches in August.', '2026-08-13 10:00:00' );

		$result = Drift::check( $this->staged_copy_id, $shown );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );
	}

	/**
	 * A touch with no content change still counts as drift.
	 *
	 * Detection is timestamp-based (KTD11), so this is expected rather than a
	 * defect. It costs one confirmation; comparing content instead would cost
	 * correctness on every field the comparison did not cover.
	 */
	public function test_a_touch_without_content_change_counts_as_drift(): void {
		$this->move_live( get_post( $this->live_id )->post_content );

		$this->assertSame(
			'Launches in June.',
			get_post( $this->live_id )->post_content,
			'The content should be unchanged; only the timestamp moved.'
		);

		$this->assertTrue( Drift::has_drifted( $this->staged_copy_id ) );
	}

	/**
	 * A missing baseline counts as drift rather than as agreement.
	 */
	public function test_a_missing_baseline_counts_as_drift(): void {
		delete_post_meta( $this->staged_copy_id, Staged_Copy_Repository::FORK_BASELINE_META );

		$this->assertTrue( Drift::has_drifted( $this->staged_copy_id ) );

		$result = Drift::check( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );
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
		$this->assertSame( $this->live_id, $data['live_id'] );
		$this->assertSame( 409, $data['status'] );
		$this->assertNotSame( $data['forked_at'], $data['live_modified'] );
	}

	/**
	 * The check re-reads the live post rather than trusting a cached copy.
	 *
	 * R21 leaves the window between an editor's drift check and their merge open
	 * by design, since an ungated writer can touch the live post at any moment.
	 * This simulates that: the post is loaded into cache, then changed behind
	 * the request's back.
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
			array( 'post_modified_gmt' => '2026-08-13 09:00:00' ),
			array( 'ID' => $this->live_id )
		);

		$result = Drift::check( $this->staged_copy_id );

		$this->assertWPError( $result, 'The check trusted a stale cached post.' );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );
	}
}
