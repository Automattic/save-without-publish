<?php
/**
 * Publishing a staged copy at a set time.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A schedule is a deferred "Publish changes" by a named person (VIPPROD-1247).
 *
 * Stored on the copy, fired by one cron event, run through the ordinary merge
 * with that person set as the acting user, and refused for every reason the
 * merge refuses an editor's own click. Nothing here is a second publishing
 * path: `fire()` calls `Merge::apply()` unchanged, so every gate the merge
 * already enforces -- capability, drift, `swpub_pre_merge` -- still runs.
 *
 * The scheduled time lives in its own meta, never on the copy's `post_date`.
 * That field already means something else: it is the fork timestamp
 * `Merge_Marker` records as `forked_at`, and `Field_Lock` refuses a staged
 * copy's date for exactly that reason.
 *
 * A run is one of three things, and the distinction is load-bearing:
 *
 * - **Skipped.** Not due yet, no longer scheduled, or a merge already in
 *   flight. Nothing is touched and the schedule stands, because none of these
 *   say the schedule is over -- the next tick, or the merge that is already
 *   running, is still going to honour it.
 * - **Refused.** The schedule cannot be kept: the copy or its published post
 *   is no longer in a state that can be merged, the scheduler has lost the
 *   capability, or the merge itself refused (most often drift). The copy is
 *   kept, marked with why, and unscheduled -- refused once, not retried on
 *   every tick.
 * - **Ran.** The merge applied. The copy no longer exists.
 */
final class Scheduled_Publish {

	/**
	 * Meta on the staged copy naming when it should publish, GMT.
	 *
	 * Presence is what "scheduled" means; absence is what "not scheduled" means.
	 */
	public const AT_META = '_swpub_publish_at';

	/**
	 * Meta on the staged copy naming who scheduled it.
	 *
	 * That person is set as the acting user when the schedule fires (KTD13):
	 * the merge's own capability check then runs exactly as it does for a
	 * click, against the published post, so a scheduler who has since lost
	 * the capability is refused rather than merged as nobody.
	 */
	public const BY_META = '_swpub_publish_scheduled_by';

	/**
	 * Meta on the staged copy recording why a scheduled run was refused.
	 *
	 * `array{ reason: string, code: string, message: string, scheduled_for: string, attempted_at: string }`.
	 * Written only on a refusal, cleared by the next successful `schedule()`.
	 */
	public const REFUSED_META = '_swpub_schedule_refused';

	/**
	 * The cron hook a schedule arms.
	 *
	 * Args are the copy ID alone, so `wp_clear_scheduled_hook( self::HOOK,
	 * array( $copy_id ) )` clears every event for that one copy -- what a
	 * reschedule and an unschedule both do first.
	 */
	public const HOOK = 'swpub_publish_scheduled';

	/**
	 * Seconds a "future" time may already be in the past by.
	 *
	 * A schedule request that took a moment to arrive should not be refused
	 * for having gone stale in transit. The same window covers a `fire()`
	 * that runs a few seconds ahead of its own scheduled time, which core's
	 * own cron dispatch can do.
	 */
	private const GRACE = 60;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'fire' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'clear_on_delete' ), 10, 2 );
	}

	/**
	 * Schedules a staged copy to publish at a set time, as a named person.
	 *
	 * Validation runs cheapest and least specific first, so a caller gets the
	 * most useful refusal for the state the pair is actually in rather than
	 * the first thing that happens to be wrong.
	 *
	 * @param int    $copy_id Staged copy post ID.
	 * @param string $at      When to publish. ISO 8601; a value with no
	 *                        offset is treated as site-local, exactly as
	 *                        `date` is over REST.
	 * @param int    $user_id The user scheduling it, who becomes the acting
	 *                        user when it fires.
	 * @return array{at_gmt: string, at_local: string, by: int}|WP_Error
	 */
	public static function schedule( int $copy_id, string $at, int $user_id ) {
		if ( ! is_enabled() ) {
			return new WP_Error(
				'swpub_disabled',
				__( 'Staging is currently disabled.', 'save-without-publish' ),
				array( 'status' => 403 )
			);
		}

		$copy = get_post( $copy_id );

		if ( ! $copy instanceof WP_Post || ! Status::is_staged( $copy ) ) {
			return new WP_Error(
				'swpub_not_staged',
				__( 'There is nothing staged to publish.', 'save-without-publish' ),
				array( 'status' => 404 )
			);
		}

		if ( $user_id <= 0 || ! user_can( $user_id, 'edit_post', $copy_id ) ) {
			return new WP_Error(
				'swpub_forbidden',
				__( 'You are not allowed to publish these changes.', 'save-without-publish' ),
				array( 'status' => 403 )
			);
		}

		$live = Staged_Copy_Repository::find_live_for_staged_copy( $copy_id );

		if ( ! $live instanceof WP_Post ) {
			return new WP_Error(
				'swpub_no_live_post',
				__( 'The published post this stages no longer exists.', 'save-without-publish' ),
				array( 'status' => 409 )
			);
		}

		if ( 'publish' !== $live->post_status ) {
			return new WP_Error(
				'swpub_not_published',
				__( 'The published post is no longer published, so there is nothing to update.', 'save-without-publish' ),
				array( 'status' => 409 )
			);
		}

		if ( null !== Transitions::stranding( $copy_id ) ) {
			return new WP_Error(
				'swpub_stranded',
				__( 'These changes cannot be published right now.', 'save-without-publish' ),
				array( 'status' => 409 )
			);
		}

		if ( null !== Merge_Marker::get( $live->ID ) ) {
			return new WP_Error(
				'swpub_merge_in_progress',
				__( 'These changes are already being published.', 'save-without-publish' ),
				array( 'status' => 409 )
			);
		}

		$parsed = rest_get_date_with_gmt( $at );

		if ( null === $parsed ) {
			return new WP_Error(
				'swpub_bad_time',
				__( 'That is not a date WordPress can schedule.', 'save-without-publish' ),
				array( 'status' => 400 )
			);
		}

		list( $local, $gmt ) = $parsed;
		$timestamp            = strtotime( $gmt . ' GMT' );

		if ( false === $timestamp || $timestamp < time() - self::GRACE ) {
			return new WP_Error(
				'swpub_time_past',
				__( 'That time has already passed.', 'save-without-publish' ),
				array( 'status' => 400 )
			);
		}

		wp_clear_scheduled_hook( self::HOOK, array( $copy_id ) );

		update_post_meta( $copy_id, self::AT_META, $gmt );
		update_post_meta( $copy_id, self::BY_META, $user_id );
		delete_post_meta( $copy_id, self::REFUSED_META );

		if ( ! wp_schedule_single_event( $timestamp, self::HOOK, array( $copy_id ) ) ) {
			// A schedule with no event behind it is a promise nothing will
			// keep, so it is not left standing.
			delete_post_meta( $copy_id, self::AT_META );
			delete_post_meta( $copy_id, self::BY_META );

			return new WP_Error(
				'swpub_cron_failed',
				__( 'The publish could not be scheduled.', 'save-without-publish' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'at_gmt'   => $gmt,
			'at_local' => $local,
			'by'       => $user_id,
		);
	}

	/**
	 * Cancels a schedule.
	 *
	 * Idempotent, deliberately: a caller unscheduling a copy that is not
	 * scheduled is not doing anything wrong, and every other action here
	 * answers the same way about a state that already holds.
	 *
	 * @param int $copy_id Staged copy post ID.
	 * @return true True whether or not anything was scheduled.
	 */
	public static function unschedule( int $copy_id ): bool {
		wp_clear_scheduled_hook( self::HOOK, array( $copy_id ) );

		delete_post_meta( $copy_id, self::AT_META );
		delete_post_meta( $copy_id, self::BY_META );

		return true;
	}

	/**
	 * The copy's current schedule, if it has one.
	 *
	 * @param int $copy_id Staged copy post ID.
	 * @return array{at_gmt: string, at_local: string, by: int, late_by: int}|null
	 */
	public static function scheduled( int $copy_id ): ?array {
		$at_gmt = (string) get_post_meta( $copy_id, self::AT_META, true );

		if ( '' === $at_gmt ) {
			return null;
		}

		$timestamp = strtotime( $at_gmt . ' GMT' );

		return array(
			'at_gmt'   => $at_gmt,
			'at_local' => get_date_from_gmt( $at_gmt ),
			'by'       => (int) get_post_meta( $copy_id, self::BY_META, true ),
			'late_by'  => false === $timestamp ? 0 : max( 0, time() - $timestamp ),
		);
	}

	/**
	 * Why the last scheduled run was refused, if it was.
	 *
	 * @param int $copy_id Staged copy post ID.
	 * @return array{reason: string, code: string, message: string, scheduled_for: string, attempted_at: string}|null
	 */
	public static function refusal( int $copy_id ): ?array {
		$record = get_post_meta( $copy_id, self::REFUSED_META, true );

		return is_array( $record ) && ! empty( $record['reason'] ) ? $record : null;
	}

	/**
	 * The cron callback: runs a due schedule through the merge.
	 *
	 * Every early exit is a skip -- the schedule is left exactly as it was,
	 * because none of these say the schedule is over. A refusal is the
	 * opposite: the copy is kept, marked, and unscheduled, because trying
	 * again on the next tick would not change the answer.
	 *
	 * @param int $copy_id Staged copy post ID.
	 * @return array{outcome: string, reason: string, result: array<string, mixed>|WP_Error|null} What happened.
	 */
	public static function fire( int $copy_id ): array {
		$sched = self::scheduled( $copy_id );

		if ( null === $sched ) {
			// An event outlived its schedule -- unschedule() or a later
			// reschedule cleared the meta but an old event still fired.
			// Harmless; there is nothing left to act on.
			return self::skipped( 'unscheduled' );
		}

		$timestamp = strtotime( $sched['at_gmt'] . ' GMT' );

		if ( false !== $timestamp && $timestamp > time() + self::GRACE ) {
			// A stale event from before a reschedule. The real one is still
			// queued for the new time; this one is not it.
			return self::skipped( 'not_due' );
		}

		$copy = get_post( $copy_id );

		if ( ! $copy instanceof WP_Post || ! Status::is_staged( $copy ) ) {
			// Published or discarded by hand before the schedule caught up.
			// There is no copy to mark, but the event and its meta are
			// cleared and the attempt is recorded.
			return self::refused( $copy_id, 0, 'not_staged', null, $sched );
		}

		if ( ! is_enabled() ) {
			return self::refused( $copy_id, 0, 'disabled', null, $sched );
		}

		$live = Staged_Copy_Repository::find_live_for_staged_copy( $copy_id );

		if ( ! $live instanceof WP_Post ) {
			return self::refused( $copy_id, 0, 'no_live_post', null, $sched );
		}

		if ( 'publish' !== $live->post_status ) {
			return self::refused( $copy_id, $live->ID, 'not_published', null, $sched );
		}

		if ( null !== Transitions::stranding( $copy_id ) ) {
			// Reachable only when the live post is `publish` again but its
			// stranding mark was never cleared -- ordinarily automatic, the
			// moment a write republishes it, but not for a write that skips
			// `transition_post_status` entirely (a direct database write, a
			// migration). Checked in the same order `schedule()` checks it,
			// for the same reason: the two facts are otherwise mutually
			// exclusive, so which is checked first almost never matters, but
			// "almost" is not "never".
			return self::refused( $copy_id, $live->ID, 'stranded', null, $sched );
		}

		if ( null !== Merge_Marker::get( $live->ID ) ) {
			// Merge_Resume or another run already owns this pair. Leave the
			// schedule alone; whichever merge is in flight will finish it.
			return self::skipped( 'merge_in_progress' );
		}

		$actor = (int) $sched['by'];

		if ( $actor <= 0 || ! user_can( $actor, 'edit_post', $copy_id ) ) {
			return self::refused( $copy_id, $live->ID, 'actor', null, $sched );
		}

		$previous_user = get_current_user_id();
		wp_set_current_user( $actor );

		try {
			$override = null;

			if ( Drift::has_drifted( $copy_id ) ) {
				$kind = Drift::kind( $copy_id, $live );

				/**
				 * Filters whether a scheduled publish proceeds over drift.
				 *
				 * Default false: the run refuses exactly as `Drift::check()`
				 * refuses an editor who has not confirmed. The copy is kept,
				 * marked, and unscheduled, so a human decides.
				 *
				 * Return true to let the schedule proceed anyway -- the
				 * staged words win, and the merge fires `swpub_drift_overridden`
				 * exactly as an editor's own confirmation does.
				 *
				 *     add_filter(
				 *         'swpub_scheduled_publish_overrides_drift',
				 *         function ( $override, $copy_id, $live_id, $kind ) {
				 *             // Publish past a category or featured-image
				 *             // change; still ask a human about text.
				 *             return 'content' !== $kind;
				 *         },
				 *         10,
				 *         4
				 *     );
				 *
				 * @since 0.1.0
				 *
				 * @param bool   $override Whether to proceed. Default false.
				 * @param int    $copy_id  Staged copy post ID.
				 * @param int    $live_id  Published post ID.
				 * @param string $kind     `content`, `other`, or `unknown` (VIPPROD-752).
				 */
				$overrides = (bool) apply_filters(
					'swpub_scheduled_publish_overrides_drift',
					false,
					$copy_id,
					$live->ID,
					$kind
				);

				if ( $overrides ) {
					// Fresh read, not the $live fetched above: the state
					// confirmed against has to be the state at the moment of
					// confirming, the same guarantee Drift::check() enforces
					// for an editor's own confirmation.
					$fresh = get_post( $live->ID );

					if ( $fresh instanceof WP_Post ) {
						$override = Drift::state( $fresh );
					}
				}
			}

			$result = Merge::apply( $copy_id, $override, array( 'scheduled_for' => $sched['at_gmt'] ) );
		} finally {
			wp_set_current_user( $previous_user );
		}

		if ( is_wp_error( $result ) ) {
			if ( 'swpub_merge_in_progress' === $result->get_error_code() ) {
				// Lost the single-flight claim to a concurrent run. That run
				// will finish the merge; this schedule has nothing left to do
				// and nothing to be refused for.
				return self::skipped( 'merge_in_progress' );
			}

			return self::refused( $copy_id, $live->ID, $result->get_error_code(), $result, $sched );
		}

		$late_by = false === $timestamp ? 0 : max( 0, time() - $timestamp );

		Events::scheduled_publish_ran( $live->ID, $copy_id, $sched['at_gmt'], $late_by, $actor );

		return array(
			'outcome' => 'ran',
			'reason'  => '',
			'result'  => $result,
		);
	}

	/**
	 * Fires every due schedule.
	 *
	 * The one place a sweep over staged copies is allowed, for the same
	 * reason `CLI::inventory()` is: a deliberate operator action, not a
	 * background query (R24). This is what `wp swpub run-due` calls, and what
	 * a system cron line calls on a site running `DISABLE_WP_CRON`.
	 *
	 * @return array<int, array{outcome: string, reason: string, result: array<string, mixed>|WP_Error|null}> Outcomes, keyed by staged copy ID.
	 */
	public static function run_due(): array {
		$now      = time();
		$outcomes = array();

		foreach ( CLI::staged_copies() as $copy ) {
			$at_gmt = (string) get_post_meta( $copy->ID, self::AT_META, true );

			if ( '' === $at_gmt ) {
				continue;
			}

			$timestamp = strtotime( $at_gmt . ' GMT' );

			if ( false !== $timestamp && $timestamp > $now + self::GRACE ) {
				continue;
			}

			$outcomes[ $copy->ID ] = self::fire( $copy->ID );
		}

		return $outcomes;
	}

	/**
	 * A staged copy leaving takes its cron event with it.
	 *
	 * Without this, a merged or discarded copy's event still fires on a
	 * deleted ID: harmless, since `fire()`'s own checks exit at the missing
	 * post, but it is noise in `wp cron event list` an operator would
	 * otherwise have to explain.
	 *
	 * @param int          $post_id The post being deleted.
	 * @param WP_Post|null $post    The post.
	 * @return void
	 */
	public static function clear_on_delete( $post_id, $post = null ): void {
		$post = $post instanceof WP_Post ? $post : get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post || ! Status::is_staged( $post ) ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK, array( (int) $post_id ) );
	}

	/**
	 * Builds a skipped outcome. Touches nothing.
	 *
	 * @param string $reason Why nothing happened.
	 * @return array{outcome: string, reason: string, result: null}
	 */
	private static function skipped( string $reason ): array {
		return array(
			'outcome' => 'skipped',
			'reason'  => $reason,
			'result'  => null,
		);
	}

	/**
	 * Marks a schedule as refused, unschedules it, and announces it.
	 *
	 * @param int             $copy_id Staged copy post ID.
	 * @param int             $live_id Published post ID, or 0 when it cannot
	 *                                 be resolved.
	 * @param string          $reason  Why it was refused.
	 * @param WP_Error|null   $error   The merge's own refusal, when there was one.
	 * @param array<string, mixed> $sched The schedule being refused.
	 * @return array{outcome: string, reason: string, result: WP_Error|null}
	 */
	private static function refused( int $copy_id, int $live_id, string $reason, ?WP_Error $error, array $sched ): array {
		if ( get_post( $copy_id ) instanceof WP_Post ) {
			update_post_meta(
				$copy_id,
				self::REFUSED_META,
				array(
					'reason'        => $reason,
					'code'          => $error instanceof WP_Error ? $error->get_error_code() : $reason,
					'message'       => $error instanceof WP_Error ? $error->get_error_message() : '',
					'scheduled_for' => (string) ( $sched['at_gmt'] ?? '' ),
					'attempted_at'  => gmdate( 'Y-m-d H:i:s' ),
				)
			);

			delete_post_meta( $copy_id, self::AT_META );
			delete_post_meta( $copy_id, self::BY_META );
		}

		wp_clear_scheduled_hook( self::HOOK, array( $copy_id ) );

		Events::scheduled_publish_refused( $copy_id, $live_id, $reason, $error );

		return array(
			'outcome' => 'refused',
			'reason'  => $reason,
			'result'  => $error,
		);
	}
}
