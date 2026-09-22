<?php
/**
 * Scheduled publish tests for VIPPROD-1247.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Drift;
use SaveWithoutPublish\Merge_Marker;
use SaveWithoutPublish\Scheduled_Publish;
use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Status;
use SaveWithoutPublish\Transitions;
use WP_Error;
use WP_Post;
use WP_UnitTestCase;

/**
 * Proves a schedule is a deferred merge by a named person, refused for every
 * reason the merge refuses today, and never retried once refused.
 */
class Test_Scheduled_Publish extends WP_UnitTestCase {

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
	 * The user who staged and schedules the change.
	 *
	 * @var int
	 */
	private int $scheduler_id;

	/**
	 * Events fired during a test, oldest first.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $captured = array();

	/**
	 * Stages a published post with a staged edit, ready to schedule.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->scheduler_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $this->scheduler_id );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Launches in June.',
				'post_excerpt' => 'Summer.',
			)
		);

		$this->staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;

		wp_update_post(
			array(
				'ID'           => $this->staged_copy_id,
				'post_content' => 'Launches on October 3.',
			)
		);

		$this->captured = array();

		foreach (
			array(
				'swpub_scheduled_publish_ran'     => 5,
				'swpub_scheduled_publish_refused' => 5,
				'swpub_merge_completed'           => 2,
				'swpub_drift_overridden'          => 4,
			) as $event => $arg_count
		) {
			add_action(
				$event,
				function ( ...$args ) use ( $event ): void {
					$this->captured[] = array_merge( array( 'event' => $event ), $args );
				},
				10,
				$arg_count
			);
		}
	}

	/**
	 * Every captured record of one event, in the order it fired.
	 *
	 * @param string $event Event name.
	 * @return array<int, array<int, mixed>> Matching records, `event` key stripped.
	 */
	private function captured( string $event ): array {
		return array_values(
			array_map(
				static function ( array $record ): array {
					unset( $record['event'] );
					return array_values( $record );
				},
				array_filter(
					$this->captured,
					static function ( array $record ) use ( $event ): bool {
						return $event === $record['event'];
					}
				)
			)
		);
	}

	/**
	 * Moves the live post's title, content, or excerpt directly, leaving a
	 * `content`-kind drift behind.
	 *
	 * A direct row write, not `wp_update_post()`: the write guard contains
	 * every write to a post with a staged copy (R55), which this fixture
	 * always has.
	 *
	 * @param string $content New content.
	 * @return void
	 */
	private function drift_content( string $content ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Simulating a write the guard would otherwise contain; see test-drift.php for the same pattern.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_content'      => $content,
				'post_modified'     => '2026-08-13 09:00:00',
				'post_modified_gmt' => '2026-08-13 09:00:00',
			),
			array( 'ID' => $this->live_id )
		);

		clean_post_cache( $this->live_id );
	}

	/**
	 * Moves the live post's timestamp without touching a staged field,
	 * leaving an `other`-kind drift behind (a category, in spirit).
	 *
	 * @return void
	 */
	private function drift_other(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Simulating a category (or similar) change, which moves the timestamp without touching a staged field.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_modified_gmt' => '2026-08-13 09:00:00' ),
			array( 'ID' => $this->live_id )
		);

		clean_post_cache( $this->live_id );
	}

	// ---------------------------------------------------------------
	// schedule()
	// ---------------------------------------------------------------

	/**
	 * Scheduling stores both keys and arms exactly one cron event.
	 */
	public function test_schedule_stores_meta_and_arms_one_event(): void {
		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );

		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $at, $this->scheduler_id );

		$this->assertIsArray( $result );
		$this->assertSame( $this->scheduler_id, $result['by'] );

		$this->assertSame( $result['at_gmt'], get_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, true ) );
		$this->assertSame( $this->scheduler_id, (int) get_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, true ) );

		$expected_ts = strtotime( $result['at_gmt'] . ' GMT' );

		$this->assertSame(
			$expected_ts,
			wp_next_scheduled( Scheduled_Publish::HOOK, array( $this->staged_copy_id ) )
		);
	}

	/**
	 * A bare `Y-m-d H:i:s` time is treated as site-local, exactly as `date`
	 * is over REST.
	 */
	public function test_a_bare_time_is_treated_as_site_local(): void {
		update_option( 'timezone_string', 'America/New_York' );

		$future_utc   = time() + HOUR_IN_SECONDS;
		$local_string = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $future_utc ), 'Y-m-d H:i:s' );

		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $local_string, $this->scheduler_id );

		$this->assertIsArray( $result );

		// The stored GMT value should differ from the local string that was
		// sent, by the zone's offset -- proving it was not stored as-is.
		$this->assertNotSame( $local_string, $result['at_gmt'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $future_utc ), $result['at_gmt'] );
	}

	/**
	 * A time carrying `Z` is stored exactly, with no zone conversion.
	 */
	public function test_a_zulu_time_is_stored_as_given(): void {
		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );

		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $future, $this->scheduler_id );

		$this->assertIsArray( $result );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $future ) ), $result['at_gmt'] );
	}

	/**
	 * Rescheduling replaces the event and the meta, not adds to them.
	 */
	public function test_rescheduling_replaces_the_event(): void {
		$first  = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		$second = gmdate( 'Y-m-d\TH:i:s\Z', time() + 2 * HOUR_IN_SECONDS );

		Scheduled_Publish::schedule( $this->staged_copy_id, $first, $this->scheduler_id );
		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $second, $this->scheduler_id );

		$this->assertIsArray( $result );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $second ) ), get_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, true ) );

		$crons = _get_cron_array();
		$count = 0;

		foreach ( (array) $crons as $timestamp => $hooks ) {
			$count += isset( $hooks[ Scheduled_Publish::HOOK ] ) ? count( $hooks[ Scheduled_Publish::HOOK ] ) : 0;
		}

		$this->assertSame( 1, $count, 'More than one event is queued for this copy.' );
	}

	/**
	 * A new schedule clears a previous refusal mark.
	 */
	public function test_scheduling_clears_a_previous_refusal(): void {
		update_post_meta(
			$this->staged_copy_id,
			Scheduled_Publish::REFUSED_META,
			array( 'reason' => 'actor', 'code' => 'actor', 'message' => '', 'scheduled_for' => '', 'attempted_at' => '' )
		);

		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );

		Scheduled_Publish::schedule( $this->staged_copy_id, $at, $this->scheduler_id );

		$this->assertNull( Scheduled_Publish::refusal( $this->staged_copy_id ) );
	}

	/**
	 * A past time is refused, but a time within the grace window is not.
	 */
	public function test_a_past_time_is_refused_but_grace_is_honoured(): void {
		$past = gmdate( 'Y-m-d\TH:i:s\Z', time() - HOUR_IN_SECONDS );

		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $past, $this->scheduler_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swpub_time_past', $result->get_error_code() );

		$grace = gmdate( 'Y-m-d\TH:i:s\Z', time() - 30 );

		$within_grace = Scheduled_Publish::schedule( $this->staged_copy_id, $grace, $this->scheduler_id );

		$this->assertIsArray( $within_grace, 'A time 30 seconds in the past, inside the 60-second grace window, was refused.' );
	}

	/**
	 * A post that is not staged cannot be scheduled.
	 */
	public function test_scheduling_an_unstaged_post_is_refused(): void {
		$at     = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		$result = Scheduled_Publish::schedule( $this->live_id, $at, $this->scheduler_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swpub_not_staged', $result->get_error_code() );
	}

	/**
	 * A user who cannot manage the copy cannot schedule it.
	 */
	public function test_scheduling_by_a_user_who_cannot_manage_is_refused(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$at         = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );

		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $at, $subscriber );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swpub_forbidden', $result->get_error_code() );
	}

	/**
	 * A user ID of 0 is refused, the same as an unauthorized user.
	 */
	public function test_scheduling_with_no_user_is_refused(): void {
		$at     = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $at, 0 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swpub_forbidden', $result->get_error_code() );
	}

	/**
	 * The published post being gone refuses the schedule.
	 */
	public function test_scheduling_with_no_live_post_is_refused(): void {
		wp_delete_post( $this->live_id, true );

		$at     = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $at, $this->scheduler_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swpub_no_live_post', $result->get_error_code() );
	}

	/**
	 * The published post no longer being published refuses the schedule.
	 */
	public function test_scheduling_an_unpublished_live_post_is_refused(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The write guard contains an ordinary status write once a copy exists; simulating what Transitions itself would see.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $this->live_id ) );
		clean_post_cache( $this->live_id );

		$at     = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $at, $this->scheduler_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swpub_not_published', $result->get_error_code() );
	}

	/**
	 * A stranded copy cannot be scheduled.
	 *
	 * Stranding and "not published" are otherwise the same fact -- a normal
	 * write back to `publish` clears the mark the instant it fires
	 * `transition_post_status`, which is why the two never coexist through
	 * an ordinary edit. What is under test here is the one way they can: a
	 * direct database write, which restores `post_status` without running
	 * any hook at all, leaving the mark stuck. `test-drift.php` uses the
	 * same direct-write pattern for the same reason.
	 */
	public function test_scheduling_a_stranded_copy_is_refused(): void {
		global $wpdb;

		wp_trash_post( $this->live_id );

		$this->assertNotNull( Transitions::stranding( $this->staged_copy_id ), 'Precondition: trashing did not strand the copy.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Simulating a restore that never fires transition_post_status, so the stranding mark is never cleared.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $this->live_id ) );
		clean_post_cache( $this->live_id );

		$this->assertSame( 'publish', get_post_field( 'post_status', $this->live_id ), 'Precondition: the live post must read as published.' );

		$at     = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $at, $this->scheduler_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swpub_stranded', $result->get_error_code() );
	}

	/**
	 * A live post already mid-merge cannot be scheduled onto.
	 */
	public function test_scheduling_during_a_merge_in_progress_is_refused(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );

		$at     = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $at, $this->scheduler_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swpub_merge_in_progress', $result->get_error_code() );
	}

	/**
	 * The kill switch refuses scheduling.
	 */
	public function test_scheduling_while_disabled_is_refused(): void {
		add_filter( 'swpub_is_enabled', '__return_false' );

		$at     = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		$result = Scheduled_Publish::schedule( $this->staged_copy_id, $at, $this->scheduler_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swpub_disabled', $result->get_error_code() );
	}

	/**
	 * An unparseable time is refused.
	 */
	public function test_an_unparseable_time_is_refused(): void {
		$result = Scheduled_Publish::schedule( $this->staged_copy_id, 'next thursday', $this->scheduler_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swpub_bad_time', $result->get_error_code() );
	}

	// ---------------------------------------------------------------
	// unschedule()
	// ---------------------------------------------------------------

	/**
	 * Unscheduling clears both keys and the event.
	 */
	public function test_unschedule_clears_the_schedule(): void {
		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		Scheduled_Publish::schedule( $this->staged_copy_id, $at, $this->scheduler_id );

		$this->assertTrue( Scheduled_Publish::unschedule( $this->staged_copy_id ) );

		$this->assertSame( '', get_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, true ) );
		$this->assertSame( '', get_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, true ) );
		$this->assertFalse( wp_next_scheduled( Scheduled_Publish::HOOK, array( $this->staged_copy_id ) ) );
		$this->assertNull( Scheduled_Publish::scheduled( $this->staged_copy_id ) );
	}

	/**
	 * Unscheduling a copy with no schedule is not an error.
	 */
	public function test_unschedule_is_idempotent(): void {
		$this->assertTrue( Scheduled_Publish::unschedule( $this->staged_copy_id ) );
	}

	// ---------------------------------------------------------------
	// fire() -- skipped
	// ---------------------------------------------------------------

	/**
	 * Firing an unscheduled copy is a no-op, not a refusal.
	 */
	public function test_fire_on_an_unscheduled_copy_is_skipped(): void {
		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'skipped', $outcome['outcome'] );
		$this->assertSame( 'unscheduled', $outcome['reason'] );
		$this->assertSame( array(), $this->captured( 'swpub_scheduled_publish_refused' ) );
	}

	/**
	 * A stale event -- one that fires before its schedule's own time,
	 * because the copy was rescheduled later -- is skipped and the real
	 * schedule is left standing.
	 */
	public function test_a_stale_event_before_the_real_schedule_is_skipped(): void {
		$future = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $future );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'skipped', $outcome['outcome'] );
		$this->assertSame( 'not_due', $outcome['reason'] );
		$this->assertSame( $future, get_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, true ), 'The real schedule was cleared by the stale one.' );
		$this->assertSame( Status::NAME, get_post_field( 'post_status', $this->staged_copy_id ) );
	}

	/**
	 * A merge already in flight on the live post is left alone; the schedule
	 * is neither run nor cleared.
	 */
	public function test_fire_during_a_merge_in_progress_is_skipped(): void {
		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		Merge_Marker::start( $this->live_id, $this->staged_copy_id );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'skipped', $outcome['outcome'] );
		$this->assertSame( 'merge_in_progress', $outcome['reason'] );
		$this->assertSame( $due, get_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, true ) );
		$this->assertSame( array(), $this->captured( 'swpub_scheduled_publish_refused' ) );
	}

	// ---------------------------------------------------------------
	// fire() -- ran
	// ---------------------------------------------------------------

	/**
	 * A due, healthy schedule merges: content lands, the copy is gone, the
	 * event fires once with negligible lateness, the merge-completed
	 * payload carries the scheduled time, and the merge ran as the
	 * scheduler even though `fire()` itself runs as nobody.
	 *
	 * `late_by` is asserted as "small" rather than exactly `0`: it is
	 * `time() - $timestamp` read after this test's own setup and the merge
	 * itself have both taken real wall-clock time, so a due time of "now"
	 * is already a second or two old by the time `fire()` computes it.
	 */
	public function test_fire_on_a_due_schedule_merges_as_the_scheduler(): void {
		$due = gmdate( 'Y-m-d H:i:s', time() );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		wp_set_current_user( 0 ); // Simulates cron.

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 0, get_current_user_id(), 'The acting user was not restored.' );
		$this->assertSame( 'ran', $outcome['outcome'] );

		$this->assertSame( 'Launches on October 3.', get_post_field( 'post_content', $this->live_id ) );
		$this->assertNull( get_post( $this->staged_copy_id ) );

		$ran = $this->captured( 'swpub_scheduled_publish_ran' );

		$this->assertCount( 1, $ran );
		$this->assertSame( array( $this->live_id, $this->staged_copy_id, $due ), array_slice( $ran[0], 0, 3 ) );
		$this->assertLessThan( 10, $ran[0][3], 'late_by' );
		$this->assertSame( $this->scheduler_id, $ran[0][4] );

		$completed = $this->captured( 'swpub_merge_completed' );

		$this->assertCount( 1, $completed );
		$this->assertSame( $due, $completed[0][1]['scheduled_for'] );
		$this->assertSame( $this->scheduler_id, $completed[0][1]['merged_by'] );
	}

	/**
	 * The hook is actually attached: firing it through `do_action()`, the
	 * way cron does, has the same effect as calling `fire()` directly.
	 */
	public function test_the_cron_hook_is_attached(): void {
		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant's value is 'swpub_publish_scheduled'; the sniff cannot resolve it statically.
		do_action( Scheduled_Publish::HOOK, $this->staged_copy_id );

		$this->assertNull( get_post( $this->staged_copy_id ) );
		$this->assertCount( 1, $this->captured( 'swpub_scheduled_publish_ran' ) );
	}

	/**
	 * A run well past its scheduled time still publishes, and reports how
	 * late it was.
	 */
	public function test_a_late_run_still_publishes(): void {
		$hour_ago = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $hour_ago );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'ran', $outcome['outcome'] );

		$ran = $this->captured( 'swpub_scheduled_publish_ran' );

		$this->assertCount( 1, $ran );
		$this->assertGreaterThanOrEqual( HOUR_IN_SECONDS, $ran[0][3], 'late_by' );
	}

	// ---------------------------------------------------------------
	// fire() -- refused
	// ---------------------------------------------------------------

	/**
	 * A due schedule whose scheduler has lost the capability is refused,
	 * kept, marked, and unscheduled.
	 */
	public function test_fire_refuses_when_the_scheduler_lost_the_capability(): void {
		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		// Demote the scheduler after scheduling but before it fires.
		wp_update_user( array( 'ID' => $this->scheduler_id, 'role' => 'subscriber' ) );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'refused', $outcome['outcome'] );
		$this->assertSame( 'actor', $outcome['reason'] );

		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
		$this->assertSame( Status::NAME, get_post_field( 'post_status', $this->staged_copy_id ) );

		$refusal = Scheduled_Publish::refusal( $this->staged_copy_id );

		$this->assertSame( 'actor', $refusal['reason'] );
		$this->assertNull( Scheduled_Publish::scheduled( $this->staged_copy_id ) );
		$this->assertFalse( wp_next_scheduled( Scheduled_Publish::HOOK, array( $this->staged_copy_id ) ) );

		$refused = $this->captured( 'swpub_scheduled_publish_refused' );

		$this->assertCount( 1, $refused );
		$this->assertSame( $this->staged_copy_id, $refused[0][0] );
		$this->assertSame( $this->live_id, $refused[0][1] );
		$this->assertSame( 'actor', $refused[0][2] );
	}

	/**
	 * The kill switch refuses a due run.
	 */
	public function test_fire_refuses_while_disabled(): void {
		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		add_filter( 'swpub_is_enabled', '__return_false' );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'refused', $outcome['outcome'] );
		$this->assertSame( 'disabled', $outcome['reason'] );
		$this->assertSame( Status::NAME, get_post_field( 'post_status', $this->staged_copy_id ) );
	}

	/**
	 * A live post that is no longer published refuses the run.
	 */
	public function test_fire_refuses_an_unpublished_live_post(): void {
		global $wpdb;

		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Simulating an unpublish that never went through Transitions, so this stays 'not_published' rather than 'stranded'.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $this->live_id ) );
		clean_post_cache( $this->live_id );
		delete_post_meta( $this->staged_copy_id, Transitions::STRANDED_META );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'refused', $outcome['outcome'] );
		$this->assertSame( 'not_published', $outcome['reason'] );
	}

	/**
	 * A stranding mark that outlived the live post's return to `publish` --
	 * see `test_scheduling_a_stranded_copy_is_refused()` for why a direct
	 * write is what it takes to construct this -- refuses the run with the
	 * stranded reason.
	 */
	public function test_fire_refuses_a_stranded_copy(): void {
		global $wpdb;

		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		wp_trash_post( $this->live_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Simulating a restore that never fires transition_post_status, so the stranding mark is never cleared.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $this->live_id ) );
		clean_post_cache( $this->live_id );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'refused', $outcome['outcome'] );
		$this->assertSame( 'stranded', $outcome['reason'] );
	}

	/**
	 * The copy being published or discarded by hand before its schedule
	 * catches up refuses cleanly.
	 *
	 * Not simulated with `wp_delete_post()`: deleting the row deletes its
	 * meta with it, including the schedule itself, so `fire()`'s very first
	 * check would read no schedule at all and this would only prove the
	 * `unscheduled` skip, not this branch. A direct status write leaves the
	 * row and its schedule meta both in place while the status itself is no
	 * longer staged, which is the actual state this check exists to catch --
	 * the ordinary route there is `trash`, which is the one status change
	 * still reachable once VIPPROD-1246 closed every other one.
	 */
	public function test_fire_refuses_a_copy_no_longer_staged(): void {
		global $wpdb;

		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A status write with the row and its meta left in place, unlike wp_trash_post()'s own deferred force-delete; see the doc-block above.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $this->staged_copy_id ) );
		clean_post_cache( $this->staged_copy_id );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'refused', $outcome['outcome'] );
		$this->assertSame( 'not_staged', $outcome['reason'] );

		$refused = $this->captured( 'swpub_scheduled_publish_refused' );

		$this->assertCount( 1, $refused );
		$this->assertSame( 0, $refused[0][1], 'live_id should be 0 when there is no copy left to resolve it from.' );
	}

	// ---------------------------------------------------------------
	// fire() -- drift
	// ---------------------------------------------------------------

	/**
	 * By default, drift refuses the run exactly as it refuses an
	 * unconfirmed editor: the copy is kept, marked, unscheduled, and
	 * nothing is overridden.
	 */
	public function test_content_drift_refuses_by_default(): void {
		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		$this->drift_content( 'A change nobody staged.' );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'refused', $outcome['outcome'] );
		$this->assertSame( 'swpub_drift', $outcome['reason'] );

		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
		$this->assertSame( 'A change nobody staged.', get_post_field( 'post_content', $this->live_id ) );
		$this->assertSame( array(), $this->captured( 'swpub_drift_overridden' ) );

		$refusal = Scheduled_Publish::refusal( $this->staged_copy_id );

		$this->assertSame( 'swpub_drift', $refusal['code'] );
	}

	/**
	 * The filter opts a site into "staged wins": the merge proceeds, and
	 * fires the same drift-overridden event an editor's own confirmation does.
	 */
	public function test_the_filter_overrides_drift_and_the_merge_proceeds(): void {
		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );

		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		$this->drift_content( 'A change nobody staged.' );

		add_filter( 'swpub_scheduled_publish_overrides_drift', '__return_true' );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'ran', $outcome['outcome'] );
		$this->assertSame( 'Launches on October 3.', get_post_field( 'post_content', $this->live_id ) );
		$this->assertNull( get_post( $this->staged_copy_id ) );
		$this->assertCount( 1, $this->captured( 'swpub_drift_overridden' ) );
	}

	/**
	 * A filter that says yes to `other` drift but no to `content` drift
	 * publishes past the first and refuses the second.
	 */
	public function test_the_filter_can_discriminate_by_drift_kind(): void {
		add_filter(
			'swpub_scheduled_publish_overrides_drift',
			static function ( $override, $copy_id, $live_id, $kind ) {
				return 'other' === $kind;
			},
			10,
			4
		);

		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		$this->drift_other();

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'ran', $outcome['outcome'], 'other-kind drift should have been overridden.' );
	}

	/**
	 * The same discriminating filter still refuses a content change.
	 */
	public function test_the_filter_still_refuses_content_drift_when_scoped_to_other(): void {
		add_filter(
			'swpub_scheduled_publish_overrides_drift',
			static function ( $override, $copy_id, $live_id, $kind ) {
				return 'other' === $kind;
			},
			10,
			4
		);

		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		$this->drift_content( 'A change nobody staged.' );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'refused', $outcome['outcome'], 'content-kind drift should not have been overridden.' );
		$this->assertSame( 'swpub_drift', $outcome['reason'] );
	}

	// ---------------------------------------------------------------
	// fire() -- veto
	// ---------------------------------------------------------------

	/**
	 * `swpub_pre_merge` vetoes a scheduled run exactly as it vetoes a click.
	 */
	public function test_the_merge_veto_refuses_a_scheduled_run(): void {
		add_filter( 'swpub_pre_merge', '__return_false' );

		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		$outcome = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'refused', $outcome['outcome'] );
		$this->assertSame( 'swpub_merge_vetoed', $outcome['reason'] );
	}

	// ---------------------------------------------------------------
	// no retry
	// ---------------------------------------------------------------

	/**
	 * A second tick after a refusal does nothing: the schedule is gone, so
	 * the second `fire()` is a skip, not a second refusal.
	 */
	public function test_a_second_tick_after_refusal_does_nothing(): void {
		$due = gmdate( 'Y-m-d H:i:s', time() - 5 );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::AT_META, $due );
		update_post_meta( $this->staged_copy_id, Scheduled_Publish::BY_META, $this->scheduler_id );

		wp_update_user( array( 'ID' => $this->scheduler_id, 'role' => 'subscriber' ) );

		Scheduled_Publish::fire( $this->staged_copy_id );

		$this->captured = array(); // Reset the capture, isolating the second tick.

		$second = Scheduled_Publish::fire( $this->staged_copy_id );

		$this->assertSame( 'skipped', $second['outcome'] );
		$this->assertSame( 'unscheduled', $second['reason'] );
		$this->assertSame( array(), $this->captured( 'swpub_scheduled_publish_refused' ) );
	}

	// ---------------------------------------------------------------
	// clear_on_delete()
	// ---------------------------------------------------------------

	/**
	 * Deleting a scheduled copy by hand clears its cron event.
	 */
	public function test_deleting_a_scheduled_copy_clears_its_event(): void {
		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		Scheduled_Publish::schedule( $this->staged_copy_id, $at, $this->scheduler_id );

		wp_delete_post( $this->staged_copy_id, true );

		$this->assertFalse( wp_next_scheduled( Scheduled_Publish::HOOK, array( $this->staged_copy_id ) ) );
	}

	/**
	 * A merge, which deletes the copy itself, leaves no orphaned event
	 * behind either.
	 */
	public function test_a_merge_leaves_no_orphaned_event(): void {
		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		Scheduled_Publish::schedule( $this->staged_copy_id, $at, $this->scheduler_id );

		\SaveWithoutPublish\Merge::apply( $this->staged_copy_id );

		$this->assertFalse( wp_next_scheduled( Scheduled_Publish::HOOK, array( $this->staged_copy_id ) ) );
	}

	// ---------------------------------------------------------------
	// run_due()
	// ---------------------------------------------------------------

	/**
	 * `run_due()` fires only the schedules that are actually due.
	 */
	public function test_run_due_fires_only_due_schedules(): void {
		$due_copy = $this->staged_copy_id;
		update_post_meta( $due_copy, Scheduled_Publish::AT_META, gmdate( 'Y-m-d H:i:s', time() - 5 ) );
		update_post_meta( $due_copy, Scheduled_Publish::BY_META, $this->scheduler_id );

		$other_live = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'A second post',
				'post_content' => 'Launches later.',
			)
		);
		$not_due_copy = Staged_Copy_Repository::create( get_post( $other_live ) )->ID;

		update_post_meta( $not_due_copy, Scheduled_Publish::AT_META, gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		update_post_meta( $not_due_copy, Scheduled_Publish::BY_META, $this->scheduler_id );

		$outcomes = Scheduled_Publish::run_due();

		$this->assertArrayHasKey( $due_copy, $outcomes );
		$this->assertSame( 'ran', $outcomes[ $due_copy ]['outcome'] );
		$this->assertArrayNotHasKey( $not_due_copy, $outcomes );
		$this->assertInstanceOf( WP_Post::class, get_post( $not_due_copy ) );
	}
}
