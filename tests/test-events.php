<?php
/**
 * Event surface tests for U11 (R27, R35).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Capabilities;
use SaveWithoutPublish\Merge;
use SaveWithoutPublish\Staged_Copy_Repository;
use WP_Post;
use WP_UnitTestCase;

use function SaveWithoutPublish\is_staged;

/**
 * Proves an integrator can subscribe without the plugin doing the work for them.
 */
class Test_Events extends WP_UnitTestCase {

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * The acting user.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Events captured during a test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $captured = array();

	/**
	 * Publishes a post and listens to every documented action.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Launches in June.',
			)
		);

		$this->captured = array();

		foreach ( array( 'swpub_staged_created', 'swpub_staged_edited' ) as $hook ) {
			add_action(
				$hook,
				function ( $staged_copy_id, $live_id, $user_id ) use ( $hook ): void {
					$this->captured[] = array(
						'hook'      => $hook,
						'staged_copy_id' => $staged_copy_id,
						'live_id'   => $live_id,
						'user_id'   => $user_id,
					);
				},
				10,
				3
			);
		}

		add_action(
			'swpub_drift_overridden',
			function ( $staged_copy_id, $live_id, $user_id, $confirmed ): void {
				$this->captured[] = array(
					'hook'      => 'swpub_drift_overridden',
					'staged_copy_id' => $staged_copy_id,
					'live_id'   => $live_id,
					'user_id'   => $user_id,
					'confirmed' => $confirmed,
				);
			},
			10,
			4
		);
	}

	/**
	 * Captured events for one hook.
	 *
	 * @param string $hook Hook name.
	 * @return array<int, array<string, mixed>> The events.
	 */
	private function events( string $hook ): array {
		return array_values(
			array_filter(
				$this->captured,
				static function ( $event ) use ( $hook ) {
					return $event['hook'] === $hook;
				}
			)
		);
	}

	/**
	 * Creating a staged copy fires once, naming both posts and the acting user.
	 */
	public function test_creation_fires_once_with_the_acting_user(): void {
		$staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;

		$events = $this->events( 'swpub_staged_created' );

		$this->assertCount( 1, $events );
		$this->assertSame( $staged_copy_id, $events[0]['staged_copy_id'] );
		$this->assertSame( $this->live_id, $events[0]['live_id'] );
		$this->assertSame( $this->user_id, $events[0]['user_id'] );

		$this->assertSame( array(), $this->events( 'swpub_staged_edited' ) );
	}

	/**
	 * Later saves fire the edit event, not the creation event again.
	 */
	public function test_later_saves_fire_the_edit_event(): void {
		$staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;

		wp_update_post(
			array(
				'ID'           => $staged_copy_id,
				'post_content' => 'Launches on October 3.',
			)
		);

		$this->assertCount( 1, $this->events( 'swpub_staged_created' ) );

		$edits = $this->events( 'swpub_staged_edited' );

		$this->assertCount( 1, $edits );
		$this->assertSame( $staged_copy_id, $edits[0]['staged_copy_id'] );
		$this->assertSame( $this->live_id, $edits[0]['live_id'] );
		$this->assertSame( $this->user_id, $edits[0]['user_id'] );
	}

	/**
	 * The edit event names whoever actually saved, not whoever staged.
	 *
	 * A second editor revising someone else's staged copy is the case this
	 * whole surface exists to make visible.
	 */
	public function test_the_edit_event_names_the_second_editor(): void {
		$staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;

		$second = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $second );

		wp_update_post(
			array(
				'ID'           => $staged_copy_id,
				'post_content' => 'Revised by somebody else.',
			)
		);

		$edits = $this->events( 'swpub_staged_edited' );

		$this->assertSame( $second, $edits[0]['user_id'] );
		$this->assertNotSame( $this->user_id, $edits[0]['user_id'] );
	}

	/**
	 * Saving an ordinary post fires none of these.
	 */
	public function test_ordinary_posts_fire_nothing(): void {
		// Granted, so this save is the ordinary publish the test is about
		// rather than a staged one, which would fire by design.
		$granted = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$granted->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );
		wp_set_current_user( $granted->ID );

		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_update_post(
			array(
				'ID'           => $other,
				'post_content' => 'An ordinary edit.',
			)
		);

		$this->assertSame( array(), $this->captured );
	}

	/**
	 * The override event fires only when a merge overwrites a change.
	 */
	public function test_the_override_event_fires_only_on_an_overridden_merge(): void {
		global $wpdb;

		$staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;
		wp_update_post(
			array(
				'ID'           => $staged_copy_id,
				'post_content' => 'Launches on October 3.',
			)
		);

		// An ordinary merge announces nothing about drift.
		$clean_live   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$clean_staged_copy = Staged_Copy_Repository::create( get_post( $clean_live ) )->ID;
		wp_update_post(
			array(
				'ID'           => $clean_staged_copy,
				'post_content' => 'Clean staged edit.',
			)
		);
		Merge::apply( $clean_staged_copy );

		$this->assertSame( array(), $this->events( 'swpub_drift_overridden' ) );

		// Now move the published post and merge over it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Core overwrites post_modified_gmt on every update, so it cannot be set through the API.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_modified_gmt' => '2026-08-14 09:00:00' ),
			array( 'ID' => $this->live_id )
		);
		clean_post_cache( $this->live_id );

		Merge::apply( $staged_copy_id, '2026-08-14 09:00:00' );

		$overrides = $this->events( 'swpub_drift_overridden' );

		$this->assertCount( 1, $overrides );
		$this->assertSame( $staged_copy_id, $overrides[0]['staged_copy_id'] );
		$this->assertSame( $this->live_id, $overrides[0]['live_id'] );
		$this->assertSame( $this->user_id, $overrides[0]['user_id'] );
		$this->assertSame( '2026-08-14 09:00:00', $overrides[0]['confirmed'] );
	}

	/**
	 * The merge veto filter receives both post IDs and can abort the merge.
	 */
	public function test_the_veto_filter_receives_both_ids_and_can_abort(): void {
		$staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;
		wp_update_post(
			array(
				'ID'           => $staged_copy_id,
				'post_content' => 'Launches on October 3.',
			)
		);

		$seen = null;

		add_filter(
			'swpub_pre_merge',
			static function ( $allowed, $live_id, $incoming_staged_copy_id ) use ( &$seen ) {
				$seen = array( $live_id, $incoming_staged_copy_id );

				return new \WP_Error( 'blocked', 'Not while the embargo is on.' );
			},
			10,
			3
		);

		$result = Merge::apply( $staged_copy_id );

		$this->assertSame( array( $this->live_id, $staged_copy_id ), $seen );
		$this->assertWPError( $result );
		$this->assertSame( 'blocked', $result->get_error_code() );
		$this->assertSame( 'Launches in June.', get_post( $this->live_id )->post_content );
	}

	/**
	 * The public predicate identifies a staged copy and nothing else.
	 */
	public function test_the_public_predicate_identifies_a_staged_copy(): void {
		$staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;

		$this->assertTrue( is_staged( $staged_copy_id ) );
		$this->assertTrue( is_staged( get_post( $staged_copy_id ) ) );

		$this->assertFalse( is_staged( $this->live_id ) );
		$this->assertFalse( is_staged( get_post( $this->live_id ) ) );
		$this->assertFalse( is_staged( null ) );
		$this->assertFalse( is_staged( 999999 ) );
	}

	/**
	 * The predicate is reachable by name, so a function_exists guard works.
	 *
	 * An integration guards with `function_exists()` so it survives the plugin
	 * being deactivated. That guard is worthless if the function only exists
	 * once something else has happened to autoload its class.
	 */
	public function test_the_predicate_is_reachable_by_name(): void {
		$this->assertTrue( function_exists( 'SaveWithoutPublish\is_staged' ) );
	}

	/**
	 * A staged write is distinguishable from inside core's own save hook.
	 *
	 * This is the whole point of R35: one line, in the hook an integration
	 * already uses.
	 */
	public function test_an_integration_can_exclude_staged_writes_in_one_line(): void {
		$reacted = array();

		add_action(
			'save_post',
			static function ( $post_id, $post ) use ( &$reacted ): void {
				if ( is_staged( $post ) ) {
					return;
				}

				$reacted[] = $post_id;
			},
			10,
			2
		);

		$staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;
		$ordinary  = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertNotContains( $staged_copy_id, $reacted, 'A staged write reached an integration that excluded them.' );
		$this->assertContains( $ordinary, $reacted );
	}
}
