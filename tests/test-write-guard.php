<?php
/**
 * Write guard tests for U1 (R41, R42, R43, R47, R50).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Capabilities;
use SaveWithoutPublish\Drift;
use SaveWithoutPublish\Merge;
use SaveWithoutPublish\Merge_Marker;
use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Status;
use SaveWithoutPublish\Write_Guard;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proves the abort mechanism the guard is built on, before anything builds on it.
 *
 * The plan's first assumption is that `wp_insert_post_empty_content` fires early
 * enough on every supported path that the live row is still untouched when it
 * aborts. Everything else in U1 is worthless if that is false, so it is asserted
 * against real WordPress here rather than assumed.
 */
class Test_Write_Guard_Assumption extends WP_UnitTestCase {

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Publishes a post as an administrator.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Launches in June.',
				'post_excerpt' => 'A short season.',
			)
		);
	}

	/**
	 * Aborts every write reaching `wp_insert_post`.
	 *
	 * @return void
	 */
	private function abort_every_write(): void {
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
	}

	/**
	 * The whole live row, as stored.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> The row.
	 */
	private function row( int $post_id ): array {
		clean_post_cache( $post_id );

		return (array) get_post( $post_id, ARRAY_A );
	}

	/**
	 * A direct `wp_update_post()` aborts with the live row untouched.
	 */
	public function test_direct_update_aborts_before_the_row_is_written(): void {
		$before = $this->row( $this->live_id );

		$this->abort_every_write();

		$result = wp_update_post(
			array(
				'ID'           => $this->live_id,
				'post_content' => 'Launches in July.',
			),
			true
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'empty_content', $result->get_error_code() );
		$this->assertSame( $before, $this->row( $this->live_id ) );
	}

	/**
	 * Without `$wp_error` the same abort reports as `0`, still writing nothing.
	 */
	public function test_direct_update_without_wp_error_returns_zero(): void {
		$before = $this->row( $this->live_id );

		$this->abort_every_write();

		$result = wp_update_post(
			array(
				'ID'         => $this->live_id,
				'post_title' => 'Autumn collection',
			)
		);

		$this->assertSame( 0, $result );
		$this->assertSame( $before, $this->row( $this->live_id ) );
	}

	/**
	 * The classic editor's own write path aborts the same way.
	 *
	 * Two things are true here and only one of them is comfortable. The row is
	 * untouched, which is the assumption under test. But `edit_post()` returns
	 * the post ID whatever `wp_update_post()` said -- it keeps the ID it started
	 * with and only reads the return value to decide whether to retry with
	 * invalid text stripped -- so the abort is invisible to post.php. That is why
	 * U3 refuses on the classic surfaces rather than trusting the guard's abort to
	 * be noticed there.
	 */
	public function test_classic_edit_post_aborts_before_the_row_is_written(): void {
		$before = $this->row( $this->live_id );

		$this->abort_every_write();

		$result = edit_post(
			array(
				'post_ID'     => $this->live_id,
				'post_type'   => 'post',
				'post_title'  => 'Autumn collection',
				'content'     => 'Launches in September.',
				'post_status' => 'publish',
				'excerpt'     => 'A short season.',
				'_wpnonce'    => wp_create_nonce( 'update-post_' . $this->live_id ),
			)
		);

		$this->assertSame( $this->live_id, $result );
		$this->assertSame( $before, $this->row( $this->live_id ) );
	}

	/**
	 * A REST update aborts the same way, and the live row is untouched.
	 */
	public function test_rest_update_aborts_before_the_row_is_written(): void {
		$before = $this->row( $this->live_id );

		$this->abort_every_write();

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->live_id );
		$request->set_param( 'content', 'Launches in July.' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( $before, $this->row( $this->live_id ) );
	}

	/**
	 * The abort precedes the filter that shapes the row, so nothing downstream runs.
	 */
	public function test_abort_precedes_wp_insert_post_data(): void {
		$reached = false;

		add_filter(
			'wp_insert_post_data',
			static function ( $data ) use ( &$reached ) {
				$reached = true;
				return $data;
			}
		);

		$this->abort_every_write();

		wp_update_post(
			array(
				'ID'           => $this->live_id,
				'post_content' => 'Launches in July.',
			)
		);

		$this->assertFalse( $reached, 'wp_insert_post_data ran after the empty-content abort.' );
	}
}

/**
 * Proves Tier 1: once a staged copy exists, no write on any transport touches the
 * live post's staged fields, and none touches the copy's either (R41, R55).
 *
 * The refusal replaced a divert, and these tests are where the difference is
 * pinned. The divert also kept the live post intact, so a suite that only checked
 * the live row would have passed on both behaviours. What it did not keep intact
 * was the staged copy: the diverted value was composed against the published row,
 * so it reverted staged work it never carried. Every test below therefore asserts
 * *both* rows, and the staged-copy assertion is the load-bearing one.
 */
class Test_Write_Guard extends WP_UnitTestCase {

	/**
	 * The live post's stored content.
	 *
	 * Carries a quote and a backslash on purpose. The guard sits on both sides of
	 * core's unslash, so every value it moves has to be slashed or unslashed
	 * deliberately, and plain text would prove nothing about that.
	 */
	private const LIVE_CONTENT = 'Launches in June. Nadia said "hold" \\ until then.';

	/**
	 * The content an incoming write carries, with the same hazards.
	 */
	private const INCOMING_CONTENT = 'Launches in July. Nadia said "ship it" \\ now.';

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * A user who can publish, so nothing here depends on the entry rule.
	 *
	 * @var int
	 */
	private int $publisher_id;

	/**
	 * An editor whose saves would stage, for the capability-blind assertions.
	 *
	 * @var int
	 */
	private int $editor_id;

	/**
	 * Events fired during a test, oldest first.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $captured = array();

	/**
	 * Publishes a post and subscribes to every event this unit can fire.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->publisher_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// No role holds the direct-publish capability, so a user who publishes
		// rather than stages is one a site granted it to.
		get_user_by( 'id', $this->publisher_id )->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );

		$editor = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$editor->add_cap( 'publish_posts', false );
		$this->editor_id = $editor->ID;

		wp_set_current_user( $this->publisher_id );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => wp_slash( self::LIVE_CONTENT ),
				'post_excerpt' => 'A short season.',
			)
		);

		$this->captured = array();

		add_action(
			'swpub_write_staged',
			function ( $staged_copy_id, $live_id, $channel, $user_id ): void {
				$this->captured[] = array(
					'event'          => 'swpub_write_staged',
					'staged_copy_id' => (int) $staged_copy_id,
					'live_id'        => (int) $live_id,
					'channel'        => (string) $channel,
					'user_id'        => (int) $user_id,
				);
			},
			10,
			4
		);

		add_action(
			'swpub_write_blocked',
			function ( $staged_copy_id, $live_id, $channel, $user_id ): void {
				$this->captured[] = array(
					'event'          => 'swpub_write_blocked',
					'staged_copy_id' => (int) $staged_copy_id,
					'live_id'        => (int) $live_id,
					'channel'        => (string) $channel,
					'user_id'        => (int) $user_id,
				);
			},
			10,
			4
		);

		add_action(
			'swpub_staging_write_failed',
			function ( $live_id, $reason, $user_id ): void {
				$this->captured[] = array(
					'event'   => 'swpub_staging_write_failed',
					'live_id' => (int) $live_id,
					'reason'  => (string) $reason,
					'user_id' => (int) $user_id,
				);
			},
			10,
			3
		);

		add_action(
			'swpub_published_via_carveout',
			function ( $live_id, $channel, $user_id ): void {
				$this->captured[] = array(
					'event'   => 'swpub_published_via_carveout',
					'live_id' => (int) $live_id,
					'channel' => (string) $channel,
					'user_id' => (int) $user_id,
				);
			},
			10,
			3
		);

		foreach ( array( 'swpub_staged_created', 'swpub_staged_edited' ) as $event ) {
			add_action(
				$event,
				function () use ( $event ): void {
					$this->captured[] = array( 'event' => $event );
				}
			);
		}
	}

	/**
	 * Establishes a staged copy for the live post.
	 *
	 * @param int|null $live_id Post to stage, defaulting to the fixture.
	 * @return WP_Post The staged copy.
	 */
	private function stage( ?int $live_id = null ): WP_Post {
		$staged_copy = Staged_Copy_Repository::establish( get_post( $live_id ?? $this->live_id ) );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );

		$this->captured = array();

		return $staged_copy;
	}

	/**
	 * Back-dates the live post's modified columns.
	 *
	 * Without this the fixture and the write land in the same second, the two
	 * timestamps match by accident, and a test about whether the guard froze
	 * `post_modified` passes whatever the guard did. Core overwrites these columns
	 * on every update and exposes no API for setting them, so it is a direct write.
	 *
	 * @param string $when GMT timestamp to stamp the post with.
	 * @return string The value written.
	 */
	private function age_live( string $when = '2026-08-13 09:00:00' ): string {
		global $wpdb;

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

		return $when;
	}

	/**
	 * A stored field, read past the cache.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name.
	 * @return string The stored value.
	 */
	private function stored( int $post_id, string $field ): string {
		clean_post_cache( $post_id );

		return (string) get_post_field( $field, $post_id );
	}

	/**
	 * Every captured event of one name.
	 *
	 * @param string $event Event name.
	 * @return array<int, array<string, mixed>> Matching records.
	 */
	private function captured( string $event ): array {
		return array_values(
			array_filter(
				$this->captured,
				static function ( array $record ) use ( $event ): bool {
					return $event === $record['event'];
				}
			)
		);
	}

	/**
	 * Dispatches a REST update, optionally cookie-authenticated.
	 *
	 * Without the nonce this is what an application password or OAuth client
	 * looks like at the guard: a REST write with no editor behind it.
	 *
	 * @param array $body       Fields to send.
	 * @param bool  $with_nonce Whether to send a valid `wp_rest` nonce.
	 * @return \WP_REST_Response The response.
	 */
	private function rest_update( array $body, bool $with_nonce = false ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->live_id );

		if ( $with_nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}

		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Covers AE24, server half. A token REST write is refused, and told why.
	 *
	 * The transport an integration actually uses is the one that gets the good
	 * error, because `Fork::refuse_locked_live_post()` sits outside the cookie
	 * gate. Underneath it the guard would abort too, but only with core's
	 * `empty_content` identity, which names the wrong problem.
	 */
	public function test_token_rest_write_is_refused_with_both_rows_intact(): void {
		$staged_copy = $this->stage();

		$response = $this->rest_update( array( 'content' => self::INCOMING_CONTENT ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'swpub_live_locked', $response->as_error()->get_error_code() );
		$this->assertSame( 409, $response->get_status() );

		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );
		$this->assertSame( array(), $this->captured( 'swpub_write_staged' ) );

		$blocked = $this->captured( 'swpub_write_blocked' );

		$this->assertCount( 1, $blocked );
		$this->assertSame( $staged_copy->ID, $blocked[0]['staged_copy_id'] );
		$this->assertSame( $this->live_id, $blocked[0]['live_id'] );
		$this->assertSame( 'rest-token', $blocked[0]['channel'] );
		$this->assertSame( $this->publisher_id, $blocked[0]['user_id'] );
	}

	/**
	 * The refusal carries the way out, for a person and for a machine.
	 *
	 * `swpub_live_locked` is documented as public contract in
	 * `Staged_Copy_Repository::live_locked_error()`, and a client that renders the
	 * refusal needs somewhere to send its reader without knowing this plugin's
	 * routes.
	 */
	public function test_the_refusal_names_the_staged_copy_and_where_to_edit_it(): void {
		$staged_copy = $this->stage();

		$data = $this->rest_update( array( 'content' => self::INCOMING_CONTENT ) )->as_error()->get_error_data();

		$this->assertSame( $this->live_id, $data['live_id'] );
		$this->assertSame( $staged_copy->ID, $data['staged_copy_id'] );
		$this->assertStringContainsString( (string) $staged_copy->ID, $data['edit_url'] );
	}

	/**
	 * The editor's cookie save meets the same refusal, not the fork.
	 *
	 * It used to meet `Fork::maybe_fork()`, which answered by writing the request
	 * into the existing copy and reporting 409 `swpub_staged` -- the destructive
	 * path, wearing a success-shaped error. `refuse_locked_live_post()` runs one
	 * priority earlier and refuses before anything is written, so both transports
	 * now get the same code for the same situation.
	 */
	public function test_a_cookie_rest_save_meets_the_same_refusal(): void {
		$staged_copy = $this->stage();

		$response = $this->rest_update( array( 'content' => self::INCOMING_CONTENT ), true );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'swpub_live_locked', $response->as_error()->get_error_code() );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );
		$this->assertSame( array(), $this->captured( 'swpub_write_staged' ) );
		$this->assertCount( 1, $this->captured( 'swpub_write_blocked' ) );
	}

	/**
	 * The regression this plan exists for: a contained write must not read as drift.
	 *
	 * Core recomputes both modified columns on every update, and drift is a
	 * byte-for-byte comparison against a baseline frozen at fork. Left alone, the
	 * first contained write would make every later merge ask the author to confirm
	 * past a change that never happened.
	 */
	public function test_a_refused_write_does_not_register_as_drift(): void {
		$before = $this->age_live();

		$staged_copy = $this->stage();

		$this->assertSame( $before, Drift::baseline( $staged_copy->ID ) );
		$this->assertFalse( Drift::has_drifted( $staged_copy->ID ) );

		$this->rest_update( array( 'content' => self::INCOMING_CONTENT ) );

		$this->assertSame( $before, $this->stored( $this->live_id, 'post_modified_gmt' ) );
		$this->assertSame( $before, $this->stored( $this->live_id, 'post_modified' ) );
		$this->assertFalse( Drift::has_drifted( $staged_copy->ID ), 'A contained write registered as drift.' );
	}

	/**
	 * A write carrying both kinds of field is refused whole, not split.
	 *
	 * The slug would have been allowed on its own. Applying that half while
	 * refusing the other would be a partial write nobody asked for, and the
	 * editor would have to infer which half survived from the row rather than
	 * from what they were told. `Field_Lock` settled this question the other way
	 * round for the staged copy (AE12: refuse, never silently strip), and the
	 * answer is the same here for the same reason.
	 */
	public function test_a_split_write_is_refused_whole(): void {
		$before = $this->age_live();

		$staged_copy = $this->stage();

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $this->live_id,
					'post_content' => self::INCOMING_CONTENT,
					'post_name'    => 'summer-collection',
				)
			),
			true
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );
		$this->assertNotSame( 'summer-collection', $this->stored( $this->live_id, 'post_name' ) );
		$this->assertSame( $before, $this->stored( $this->live_id, 'post_modified_gmt' ) );
		$this->assertCount( 1, $this->captured( 'swpub_write_blocked' ) );
	}

	/**
	 * The bug this rule exists for: a live write leaves staged work alone.
	 *
	 * The staged copy here holds edits to two fields. Under the divert, a save
	 * from the published post replaced the staged body with the published one --
	 * because the request was composed against the published row and had never
	 * seen the staged words -- and per-field diffing did not help, since `content`
	 * is one field. Both staged fields have to come through untouched.
	 */
	public function test_a_live_write_leaves_the_staged_copy_untouched(): void {
		$staged_copy = $this->stage();

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $staged_copy->ID,
					'post_title'   => 'Meridian Active, Autumn collection',
					'post_content' => 'Staged body, rewritten in full.',
				)
			)
		);

		$this->captured = array();

		$this->rest_update( array( 'content' => self::INCOMING_CONTENT ) );

		$this->assertSame( 'Meridian Active, Autumn collection', $this->stored( $staged_copy->ID, 'post_title' ) );
		$this->assertSame( 'Staged body, rewritten in full.', $this->stored( $staged_copy->ID, 'post_content' ) );
		$this->assertSame( 'Meridian Active, Summer collection', $this->stored( $this->live_id, 'post_title' ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
	}

	/**
	 * Quotes and backslashes survive the establishing divert byte-identically.
	 *
	 * On the first write, which is the one divert this guard still performs. The
	 * guard sits on both sides of core's unslash, so this is where that is proved;
	 * a later write has nothing to slash because it is refused before it moves.
	 */
	public function test_slashed_content_survives_the_first_divert_byte_identically(): void {
		wp_set_current_user( $this->editor_id );

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $this->live_id,
					'post_content' => self::INCOMING_CONTENT,
					'post_excerpt' => 'A "short" season \\ still.',
				)
			),
			true
		);

		$this->assertNotWPError( $result );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );
		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );
		$this->assertSame( 'A "short" season \\ still.', $this->stored( $staged_copy->ID, 'post_excerpt' ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( 'A short season.', $this->stored( $this->live_id, 'post_excerpt' ) );
	}

	/**
	 * A direct `wp_update_post()` by a user who cannot publish is refused.
	 */
	public function test_direct_update_by_a_non_capability_user_is_refused(): void {
		$staged_copy = $this->stage();

		wp_set_current_user( $this->editor_id );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );
		$this->assertCount( 1, $this->captured( 'swpub_write_blocked' ) );
	}

	/**
	 * KD13. The same call by someone who may publish is refused too.
	 *
	 * The rule does not read the capability. Publishing over a staged copy would
	 * leave one post with two pending versions moving apart, and would do it
	 * against a drift baseline the same writer had just overwritten.
	 */
	public function test_direct_update_by_a_capability_holder_is_also_refused(): void {
		$staged_copy = $this->stage();

		wp_set_current_user( $this->publisher_id );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );

		$blocked = $this->captured( 'swpub_write_blocked' );

		$this->assertCount( 1, $blocked );
		$this->assertSame( 'internal', $blocked[0]['channel'] );
	}

	/**
	 * Covers AE27. A write touching no staged field behaves exactly as core.
	 */
	public function test_a_write_changing_only_other_fields_passes_through(): void {
		$staged_copy = $this->stage();

		$term_id = self::factory()->category->create( array( 'name' => 'Launches' ) );

		$result = wp_update_post(
			array(
				'ID'            => $this->live_id,
				'post_name'     => 'summer-collection',
				'post_category' => array( $term_id ),
				'meta_input'    => array( 'swpub_test_marker' => 'set' ),
			),
			true
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'summer-collection', $this->stored( $this->live_id, 'post_name' ) );
		$this->assertSame( 'set', get_post_meta( $this->live_id, 'swpub_test_marker', true ) );
		$this->assertTrue( has_category( $term_id, $this->live_id ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * Covers AE29. The rule holds with no authenticated user at all.
	 */
	public function test_a_user_less_write_to_a_post_with_a_copy_is_refused(): void {
		$staged_copy = $this->stage();

		wp_set_current_user( 0 );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );

		$blocked = $this->captured( 'swpub_write_blocked' );

		$this->assertCount( 1, $blocked );
		$this->assertSame( 0, $blocked[0]['user_id'] );
	}

	/**
	 * Covers AE29, second half. R43: the same write with no copy publishes as core.
	 *
	 * The plugin will not establish a staged copy that no human is waiting on, and
	 * there is no user here whose capability it could read. U2 owns this branch;
	 * this asserts what it must not break.
	 */
	public function test_a_user_less_write_to_a_post_with_no_copy_publishes(): void {
		wp_set_current_user( 0 );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * The merge's own write reaches the live post, and only because it is sanctioned.
	 */
	public function test_a_merge_writes_the_live_post_under_sanction(): void {
		$staged_copy = $this->stage();

		wp_update_post( wp_slash( array( 'ID' => $staged_copy->ID, 'post_content' => self::INCOMING_CONTENT ) ) );

		$merged = Merge::apply( $staged_copy->ID );

		$this->assertNotWPError( $merged );
		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
	}

	/**
	 * The same write without the sanction is refused, which is what proves it load-bearing.
	 */
	public function test_the_same_live_write_without_the_sanction_is_refused(): void {
		$staged_copy = $this->stage();

		wp_update_post( wp_slash( array( 'ID' => $staged_copy->ID, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->captured = array();

		// Exactly what Merge::write() does, minus the sanction.
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $this->live_id,
					'post_content' => self::INCOMING_CONTENT,
				)
			),
			true
		);

		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertCount( 1, $this->captured( 'swpub_write_blocked' ) );
	}

	/**
	 * The sanction is scoped to one post, so a nested write elsewhere is still refused.
	 *
	 * A merge fires core's whole `save_post` chain. A boolean sanction would let a
	 * listener writing to some other post ride past that post's containment.
	 */
	public function test_the_sanction_does_not_cover_a_second_post(): void {
		$other_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => wp_slash( self::LIVE_CONTENT ),
			)
		);

		$this->stage();
		$other_copy = $this->stage( $other_id );

		Write_Guard::sanction(
			$this->live_id,
			function () use ( $other_id ): void {
				wp_update_post( wp_slash( array( 'ID' => $other_id, 'post_content' => self::INCOMING_CONTENT ) ) );
			}
		);

		$this->assertSame( self::LIVE_CONTENT, $this->stored( $other_id, 'post_content' ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $other_copy->ID, 'post_content' ) );
	}

	/**
	 * A sanction cannot outlive the call that took it, even when that call throws.
	 *
	 * Left armed, it would stand as an open bypass across the iterations of
	 * `wp swpub repair --all` or a cron-driven resume.
	 */
	public function test_the_sanction_is_cleared_even_when_the_callback_throws(): void {
		$staged_copy = $this->stage();

		try {
			Write_Guard::sanction(
				$this->live_id,
				static function (): void {
					throw new \RuntimeException( 'merge exploded' );
				}
			);
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );
	}

	/**
	 * The classic editor is not told a refused save succeeded.
	 *
	 * `edit_post()` ignores what `wp_update_post()` returned, so core redirects
	 * with `message=1` -- "Post updated." -- over a save that wrote nothing.
	 * `Admin_Surfaces` strips that and flags the redirect instead, and this is
	 * where the record it reads from is pinned.
	 */
	public function test_a_refused_write_is_recorded_for_the_redirect(): void {
		$staged_copy = $this->stage();

		$this->assertSame( 0, Write_Guard::write_blocked_for( $this->live_id ) );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( $staged_copy->ID, Write_Guard::write_blocked_for( $this->live_id ) );

		$location = \SaveWithoutPublish\Admin_Surfaces::report_refused_save(
			'post.php?post=' . $this->live_id . '&action=edit&message=1',
			$this->live_id
		);

		$this->assertStringNotContainsString( 'message=1', $location );
		$this->assertStringContainsString( 'swpub_blocked=1', $location );
	}

	/**
	 * A save that touched no staged field keeps core's own redirect.
	 *
	 * The published post is the only place terms, meta, and the slug can be
	 * changed while a copy exists, so those saves have to stay ordinary all the
	 * way through -- including the message the editor is sent back with.
	 */
	public function test_a_passthrough_save_is_not_recorded_as_refused(): void {
		$this->stage();

		wp_update_post( array( 'ID' => $this->live_id, 'post_name' => 'summer-collection' ), true );

		$this->assertSame( 0, Write_Guard::write_blocked_for( $this->live_id ) );
		$this->assertSame( 'summer-collection', $this->stored( $this->live_id, 'post_name' ) );

		$location = \SaveWithoutPublish\Admin_Surfaces::report_refused_save( 'post.php?message=1', $this->live_id );

		$this->assertSame( 'post.php?message=1', $location );
	}

	/**
	 * Status transitions are exempt by construction, so trash and untrash still work.
	 */
	public function test_trash_and_untrash_are_unaffected(): void {
		$this->stage();

		wp_trash_post( $this->live_id );

		$this->assertSame( 'trash', $this->stored( $this->live_id, 'post_status' ) );

		wp_untrash_post( $this->live_id );
		wp_publish_post( $this->live_id );

		$this->assertSame( 'publish', $this->stored( $this->live_id, 'post_status' ) );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( array(), $this->captured( 'swpub_write_staged' ) );
	}

	/**
	 * Saving a revision never matches the guard.
	 */
	public function test_saving_a_revision_never_matches(): void {
		$staged_copy = $this->stage();

		wp_update_post( wp_slash( array( 'ID' => $staged_copy->ID, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->captured = array();

		$revision_id = wp_save_post_revision( $this->live_id );

		$this->assertNotWPError( $revision_id );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( array(), $this->captured( 'swpub_write_staged' ) );
	}

	/**
	 * Reparenting an attachment never matches either.
	 */
	public function test_reparenting_an_attachment_never_matches(): void {
		$this->stage();

		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'meridian.jpg',
				'post_parent'    => 0,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Meridian',
			)
		);

		$this->captured = array();

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $this->live_id,
				)
			),
			true
		);

		$this->assertNotWPError( $result );
		$this->assertSame( $this->live_id, (int) get_post_field( 'post_parent', $attachment_id ) );
		$this->assertSame( array(), $this->captured( 'swpub_write_staged' ) );
	}

	/**
	 * R47. A failed staged-copy write aborts the whole update rather than publishing.
	 *
	 * On the first write, which is the only path that still writes to a copy. A
	 * later write never gets that far, so there is no failure of this shape left
	 * to have.
	 */
	public function test_a_failed_staged_copy_write_aborts_the_update(): void {
		wp_set_current_user( $this->editor_id );

		// Fails the write to whatever staged copy the guard establishes, at the
		// same seam core would. Named by status rather than by ID, because the
		// copy does not exist yet when the filter is attached.
		add_filter(
			'wp_insert_post_empty_content',
			static function ( $empty, $postarr ) {
				if ( isset( $postarr['post_status'] ) && Status::NAME === $postarr['post_status'] ) {
					return true;
				}

				return $empty;
			},
			20,
			2
		);

		$result = wp_update_post(
			wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ),
			true
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( array(), $this->captured( 'swpub_write_staged' ) );

		$failures = $this->captured( 'swpub_staging_write_failed' );

		$this->assertNotEmpty( $failures );
		$this->assertSame( $this->live_id, $failures[0]['live_id'] );
		$this->assertSame( $this->editor_id, $failures[0]['user_id'] );
	}

	/**
	 * The same failure without `$wp_error` reports as `0`, and still writes nothing.
	 */
	public function test_a_failed_staged_copy_write_reports_zero_without_wp_error(): void {
		wp_set_current_user( $this->editor_id );

		// Fails the write to whatever staged copy the guard establishes, at the
		// same seam core would. Named by status rather than by ID, because the
		// copy does not exist yet when the filter is attached.
		add_filter(
			'wp_insert_post_empty_content',
			static function ( $empty, $postarr ) {
				if ( isset( $postarr['post_status'] ) && Status::NAME === $postarr['post_status'] ) {
					return true;
				}

				return $empty;
			},
			20,
			2
		);

		$result = wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( 0, $result );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
	}

	/**
	 * An arming dropped by somebody else's abort does not leak onto the next write.
	 *
	 * The divert stands -- the copy holds the change -- but the restore it armed
	 * belongs to a write that never happened, and applying it to a later write in
	 * the same request would put stale text back.
	 *
	 * Runs on the first write, since that is the only divert that still arms a
	 * restore.
	 */
	public function test_an_arming_abandoned_by_another_abort_does_not_leak(): void {
		wp_set_current_user( $this->editor_id );

		$live_id = $this->live_id;

		// Aborts the live write only, after the divert at priority 10 has run.
		$abort = static function ( $empty, $postarr ) use ( $live_id ) {
			if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === $live_id ) {
				return true;
			}

			return $empty;
		};

		add_filter( 'wp_insert_post_empty_content', $abort, 20, 2 );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		remove_filter( 'wp_insert_post_empty_content', $abort, 20 );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );
		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );

		// A sanctioned live write now, the way a merge writes. A stale arming
		// would put the pre-abort snapshot back over it.
		Write_Guard::sanction(
			$this->live_id,
			function (): void {
				wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => 'Published straight through.' ) ) );
			}
		);

		$this->assertSame( 'Published straight through.', $this->stored( $this->live_id, 'post_content' ) );
	}

	/**
	 * The kill switch bails the guard like every other surface.
	 */
	public function test_the_kill_switch_bails_the_guard(): void {
		$this->stage();

		add_filter( 'swpub_is_enabled', '__return_false' );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( array(), $this->captured( 'swpub_write_staged' ) );
	}

	/**
	 * A post type without revisions is not contained, because it could not be reviewed.
	 */
	public function test_a_type_without_revisions_is_not_contained(): void {
		register_post_type(
			'swpub_norev',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt' ),
			)
		);

		$live_id = self::factory()->post->create(
			array(
				'post_type'    => 'swpub_norev',
				'post_status'  => 'publish',
				'post_content' => wp_slash( self::LIVE_CONTENT ),
			)
		);

		// Linked by hand: `can_establish()` would refuse this type, which is the
		// point -- containment must not depend on a copy it would never create.
		$copy_id = self::factory()->post->create(
			array(
				'post_type'   => 'swpub_norev',
				'post_status' => \SaveWithoutPublish\Status::NAME,
			)
		);

		update_post_meta( $live_id, Staged_Copy_Repository::STAGED_COPY_META, $copy_id );
		update_post_meta( $copy_id, Staged_Copy_Repository::LIVE_META, $live_id );

		wp_update_post( wp_slash( array( 'ID' => $live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $live_id, 'post_content' ) );

		unregister_post_type( 'swpub_norev' );
	}

	/**
	 * Covers AE25. A programmatic first write publishes, and says so.
	 *
	 * The seam KD16 leaves open on purpose: a token client running as a user who
	 * cannot publish writes to a post nobody has staged, and the write lands live
	 * exactly as core would. The event is the whole price of that -- a bypass with
	 * no record is a hole, not a seam.
	 */
	public function test_a_programmatic_first_write_publishes_and_is_audited(): void {
		wp_set_current_user( $this->editor_id );

		$this->rest_update( array( 'content' => self::INCOMING_CONTENT ) );

		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );

		$carveouts = $this->captured( 'swpub_published_via_carveout' );

		$this->assertCount( 1, $carveouts );
		$this->assertSame( $this->live_id, $carveouts[0]['live_id'] );
		$this->assertSame( 'rest-token', $carveouts[0]['channel'] );
		$this->assertSame( $this->editor_id, $carveouts[0]['user_id'] );
		$this->assertSame( array(), $this->captured( 'swpub_write_staged' ) );
	}

	/**
	 * Covers AE25, second half. One filter closes the seam for the whole site.
	 */
	public function test_the_closure_filter_makes_a_programmatic_first_write_stage(): void {
		wp_set_current_user( $this->editor_id );

		add_filter( 'swpub_enforce_programmatic_first_save', '__return_true' );

		$this->rest_update( array( 'content' => self::INCOMING_CONTENT ) );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );

		$engagements = $this->captured( 'swpub_write_staged' );

		$this->assertCount( 1, $engagements );
		$this->assertSame( 'rest-token', $engagements[0]['channel'] );
		$this->assertSame( array(), $this->captured( 'swpub_published_via_carveout' ) );
	}

	/**
	 * A capability-holder's first save is an ordinary save, and stays silent.
	 *
	 * It has never fired an event and must not start: the carve-out event means
	 * "somebody published who could not have", and this user could.
	 */
	public function test_a_capability_holder_first_write_is_not_a_carve_out(): void {
		wp_set_current_user( $this->publisher_id );

		$this->rest_update( array( 'content' => self::INCOMING_CONTENT ) );

		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * The same save through a plain `wp_update_post()` publishes too.
	 *
	 * The transport decides nothing for someone who may publish. This is the
	 * behaviour that was already true and must stay true.
	 */
	public function test_a_capability_holder_first_write_publishes_on_any_transport(): void {
		wp_set_current_user( $this->publisher_id );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * An unrecognized transport stages rather than publishes (KTD30b).
	 *
	 * A plain `wp_update_post()` by someone who cannot publish is the shape a
	 * front-end editor or a custom admin screen has at this seam. The carve-out
	 * names the transports it covers, and this is not one of them.
	 */
	public function test_an_internal_first_write_stages(): void {
		wp_set_current_user( $this->editor_id );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );

		$engagements = $this->captured( 'swpub_write_staged' );

		$this->assertCount( 1, $engagements );
		$this->assertSame( 'internal', $engagements[0]['channel'] );
		$this->assertSame( array(), $this->captured( 'swpub_published_via_carveout' ) );
	}

	/**
	 * A Classic Editor first save stages, and the event names that transport.
	 *
	 * U3 owns where the user lands afterwards; the data layer's answer is here.
	 */
	public function test_a_classic_editor_first_write_stages(): void {
		wp_set_current_user( $this->editor_id );

		$pagenow = $GLOBALS['pagenow'] ?? null;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The admin screen a classic save arrives on is the only signal core offers, so the test has to stand where that save stands. Restored in the `finally` below.
		$GLOBALS['pagenow'] = 'post.php';

		try {
			wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );
		} finally {
			if ( null === $pagenow ) {
				unset( $GLOBALS['pagenow'] );
			} else {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Puts back what the line above borrowed.
				$GLOBALS['pagenow'] = $pagenow;
			}
		}

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );

		$engagements = $this->captured( 'swpub_write_staged' );

		$this->assertCount( 1, $engagements );
		$this->assertSame( $staged_copy->ID, $engagements[0]['staged_copy_id'] );
		$this->assertSame( 'classic', $engagements[0]['channel'] );
	}

	/**
	 * A type staging would refuse to cover publishes instead of getting a copy.
	 *
	 * `establish()` enforces no preconditions of its own, so the guard asks the
	 * same question the deliberate entry points ask. This type supports revisions,
	 * so containment would hold for a copy it had -- but it is outside
	 * `staged_post_types()`, so `Field_Lock` never attaches, and a copy nobody
	 * locks can be flipped to publish as a second public post.
	 */
	public function test_a_type_staging_cannot_cover_publishes_instead_of_staging(): void {
		register_post_type(
			'swpub_norest',
			array(
				'public'       => true,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor', 'excerpt', 'revisions' ),
			)
		);

		$live_id = self::factory()->post->create(
			array(
				'post_type'    => 'swpub_norest',
				'post_status'  => 'publish',
				'post_content' => wp_slash( self::LIVE_CONTENT ),
			)
		);

		wp_set_current_user( $this->editor_id );

		wp_update_post( wp_slash( array( 'ID' => $live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $live_id, 'post_content' ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $live_id ) );
		$this->assertSame( array(), $this->captured );

		unregister_post_type( 'swpub_norest' );
	}

	/**
	 * A publish staging could never have caught is not announced as a carve-out.
	 *
	 * The preconditions are asked before the seam, and that order is what the
	 * event means. This write publishes because staging was unavailable for the
	 * post, not because the seam is open -- so a site that saw it announced as a
	 * carve-out would close the seam expecting it to stop, and it would not.
	 */
	public function test_a_publish_staging_could_not_cover_is_not_a_carve_out(): void {
		Merge_Marker::start( $this->live_id, 0 );

		wp_set_current_user( $this->editor_id );

		$this->rest_update( array( 'content' => self::INCOMING_CONTENT ) );

		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame( array(), $this->captured( 'swpub_published_via_carveout' ) );
	}

	/**
	 * A first write arriving mid-merge publishes rather than staging into the merge.
	 *
	 * The merge owns the post until it finishes, and the copy it is adopting is
	 * about to be deleted. A copy established underneath it would be adopted and
	 * deleted too, losing the change without saying so.
	 */
	public function test_a_first_write_during_a_merge_establishes_nothing(): void {
		Merge_Marker::start( $this->live_id, 0 );

		wp_set_current_user( $this->editor_id );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => self::INCOMING_CONTENT ) ) );

		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame( array(), $this->captured );
	}
}

/**
 * The autosave bail, on its own because it needs a constant it cannot take back.
 *
 * `DOING_AUTOSAVE` is a define, so it lasts the rest of the process. This class is
 * declared last in the last test file the suite loads, which keeps the blast
 * radius to nothing. If a test file ever sorts after `test-write-guard.php`, this
 * is what will have poisoned it.
 */
class Test_Write_Guard_Autosave extends WP_UnitTestCase {

	/**
	 * An autosave write is not contained, even on a post with a staged copy.
	 */
	public function test_an_autosave_write_bails(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Launches in June.',
			)
		);

		$staged_copy = Staged_Copy_Repository::establish( get_post( $live_id ) );

		$fired = false;

		add_action(
			'swpub_write_staged',
			static function () use ( &$fired ): void {
				$fired = true;
			}
		);

		if ( ! defined( 'DOING_AUTOSAVE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core's own constant, which core offers no other way to simulate: there is no wp_doing_autosave().
			define( 'DOING_AUTOSAVE', true );
		}

		wp_update_post( wp_slash( array( 'ID' => $live_id, 'post_content' => 'Launches in July.' ) ) );

		clean_post_cache( $live_id );
		clean_post_cache( $staged_copy->ID );

		$this->assertSame( 'Launches in July.', (string) get_post_field( 'post_content', $live_id ) );
		$this->assertSame( 'Launches in June.', (string) get_post_field( 'post_content', $staged_copy->ID ) );
		$this->assertFalse( $fired );
	}
}
