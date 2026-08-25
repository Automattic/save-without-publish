<?php
/**
 * The merge marker: what a merge was doing when it stopped.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records the phase a merge reached, so an interrupted one can be resumed.
 *
 * Lives on the live post as one protected meta value (KTD16). Absence is
 * meaningful: it means no merge is in flight, which is how recovery tells a
 * healthy staged copy from a wedged one. That is why every phase transition writes
 * before the step it describes, never after -- a marker written afterwards
 * would be absent for exactly the crash it exists to describe.
 *
 * The attempt budget is not defensive padding. The step most likely to fail is
 * the step resume re-runs, so without a cap every load of either post re-runs a
 * failing merge and both posts become unopenable.
 */
final class Merge_Marker {

	/**
	 * Meta key on the live post. Underscore-prefixed, so core treats it as
	 * protected and never exposes it over REST.
	 */
	public const META = '_swpub_merge';

	/**
	 * Phases, in the order the merge performs them.
	 *
	 * Each names the step about to run, not the one just finished.
	 */
	public const PHASE_SNAPSHOTTING = 'snapshotting';
	public const PHASE_ADOPTING     = 'adopting';
	public const PHASE_REPARENTING  = 'reparenting';
	public const PHASE_WRITING      = 'writing';
	public const PHASE_FINISHING    = 'finishing';

	/**
	 * A merge that failed too many times to keep retrying.
	 *
	 * Not a phase of the merge; a terminal state that only U14's CLI clears.
	 */
	public const PHASE_STRANDED = 'stranded';

	/**
	 * Phase order, used to decide what a resume re-enters.
	 */
	public const PHASES = array(
		self::PHASE_SNAPSHOTTING,
		self::PHASE_ADOPTING,
		self::PHASE_REPARENTING,
		self::PHASE_WRITING,
		self::PHASE_FINISHING,
	);

	/**
	 * How many times a merge may be re-attempted before it is stranded.
	 */
	public const ATTEMPT_CAP = 3;

	/**
	 * Seconds between resume attempts.
	 *
	 * A merge interrupted by a timeout will usually time out again immediately;
	 * spacing the retries keeps a slow-but-recoverable merge from burning its
	 * whole budget inside one editor's page loads.
	 */
	public const COOLDOWN = 60;

	/**
	 * Opens a marker before the first mutating step.
	 *
	 * @param int $live_id   Live post ID.
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return void
	 */
	public static function start( int $live_id, int $staged_copy_id ): void {
		update_post_meta(
			$live_id,
			self::META,
			array(
				'phase'        => self::PHASE_SNAPSHOTTING,
				'staged_copy_id'    => $staged_copy_id,
				'attachments'  => array(),
				'attempts'     => 0,
				'attempted_at' => time(),
			)
		);
	}

	/**
	 * Reads the marker, or null when no merge is in flight.
	 *
	 * @param int $live_id Live post ID.
	 * @return array<string, mixed>|null The marker.
	 */
	public static function get( int $live_id ): ?array {
		$marker = get_post_meta( $live_id, self::META, true );

		if ( ! is_array( $marker ) || empty( $marker['phase'] ) ) {
			return null;
		}

		return $marker;
	}

	/**
	 * Moves the marker to the next phase.
	 *
	 * @param int    $live_id Live post ID.
	 * @param string $phase   Phase about to run.
	 * @return void
	 */
	public static function advance( int $live_id, string $phase ): void {
		$marker = self::get( $live_id );

		if ( null === $marker ) {
			return;
		}

		$marker['phase'] = $phase;

		update_post_meta( $live_id, self::META, $marker );
	}

	/**
	 * Records the attachment IDs a merge intends to reparent.
	 *
	 * Written before the reparenting runs, so a resume knows the full set even
	 * if the interruption landed halfway through it.
	 *
	 * @param int   $live_id       Live post ID.
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return void
	 */
	public static function record_attachments( int $live_id, array $attachment_ids ): void {
		$marker = self::get( $live_id );

		if ( null === $marker ) {
			return;
		}

		$marker['attachments'] = array_values( array_map( 'intval', $attachment_ids ) );

		update_post_meta( $live_id, self::META, $marker );
	}

	/**
	 * Records facts about the merge as it progresses.
	 *
	 * The marker doubles as the accumulator for the completion event's payload.
	 * It has to, because most of what that event reports stops being knowable
	 * the moment the staged copy is deleted: who staged it, when it forked, and
	 * which revisions were adopted all come from a post that no longer exists
	 * by the time the event fires.
	 *
	 * @param int                  $live_id Live post ID.
	 * @param array<string, mixed> $data    Values to merge into the marker.
	 * @return void
	 */
	public static function record( int $live_id, array $data ): void {
		$marker = self::get( $live_id );

		if ( null === $marker ) {
			return;
		}

		update_post_meta( $live_id, self::META, array_merge( $marker, $data ) );
	}

	/**
	 * Counts a resume attempt against the budget.
	 *
	 * @param int $live_id Live post ID.
	 * @return int The new attempt count.
	 */
	public static function record_attempt( int $live_id ): int {
		$marker = self::get( $live_id );

		if ( null === $marker ) {
			return 0;
		}

		$marker['attempts']     = (int) ( $marker['attempts'] ?? 0 ) + 1;
		$marker['attempted_at'] = time();

		update_post_meta( $live_id, self::META, $marker );

		return $marker['attempts'];
	}

	/**
	 * Whether the budget is spent.
	 *
	 * @param array<string, mixed> $marker The marker.
	 * @return bool True when no further attempt may run.
	 */
	public static function is_exhausted( array $marker ): bool {
		return (int) ( $marker['attempts'] ?? 0 ) >= self::ATTEMPT_CAP;
	}

	/**
	 * Whether the cooldown since the last attempt has elapsed.
	 *
	 * @param array<string, mixed> $marker The marker.
	 * @return bool True when another attempt may run now.
	 */
	public static function cooldown_elapsed( array $marker ): bool {
		return ( time() - (int) ( $marker['attempted_at'] ?? 0 ) ) >= self::COOLDOWN;
	}

	/**
	 * Flips the marker to stranded.
	 *
	 * Deliberately keeps the marker rather than clearing it: the marker is the
	 * only record of which phase the merge died in, and U14's repair needs it.
	 *
	 * @param int $live_id Live post ID.
	 * @return void
	 */
	public static function strand( int $live_id ): void {
		$marker = self::get( $live_id );

		if ( null === $marker || self::is_stranded( $marker ) ) {
			return;
		}

		// Preserve where it died. Overwriting `phase` with the stranded flag
		// would destroy the one fact a repair needs: which step to re-enter.
		$marker['stalled_phase'] = $marker['phase'];
		$marker['phase']         = self::PHASE_STRANDED;

		update_post_meta( $live_id, self::META, $marker );
	}

	/**
	 * Returns a stranded merge to the phase it died in, with a fresh budget.
	 *
	 * Only U14's CLI calls this, deliberately. Reviving automatically would
	 * defeat the attempt cap and put both posts back in the loop the cap exists
	 * to break; a person running a command has decided the underlying cause is
	 * addressed.
	 *
	 * @param int $live_id Live post ID.
	 * @return bool True when a stranded merge was revived.
	 */
	public static function revive( int $live_id ): bool {
		$marker = self::get( $live_id );

		if ( null === $marker || ! self::is_stranded( $marker ) ) {
			return false;
		}

		$phase = (string) ( $marker['stalled_phase'] ?? self::PHASE_SNAPSHOTTING );

		if ( ! in_array( $phase, self::PHASES, true ) ) {
			$phase = self::PHASE_SNAPSHOTTING;
		}

		$marker['phase']        = $phase;
		$marker['attempts']     = 0;
		$marker['attempted_at'] = 0;

		unset( $marker['stalled_phase'] );

		update_post_meta( $live_id, self::META, $marker );

		return true;
	}

	/**
	 * Whether a marker is stranded.
	 *
	 * @param array<string, mixed> $marker The marker.
	 * @return bool True when only a CLI repair can clear it.
	 */
	public static function is_stranded( array $marker ): bool {
		return self::PHASE_STRANDED === ( $marker['phase'] ?? '' );
	}

	/**
	 * Closes a completed merge and announces it exactly once.
	 *
	 * The marker is the guard. Clearing it and firing the event in one place
	 * means a resume that re-enters a finished merge finds no marker, does
	 * nothing, and fires nothing -- which is what R11's "exactly once per
	 * applied merge" requires when a merge can be entered more than once.
	 *
	 * @param int $live_id Live post ID.
	 * @return bool True when this call completed the merge.
	 */
	public static function complete( int $live_id ): bool {
		$marker = self::get( $live_id );

		if ( null === $marker ) {
			return false;
		}

		delete_post_meta( $live_id, self::META );

		/**
		 * Fires once after a merge has been applied to the published post.
		 *
		 * The staged copy no longer exists by this point, and nothing here is
		 * stored: this is the plugin's one integration surface, so the payload
		 * carries everything a listener would otherwise have to read off a
		 * deleted post. Use it to notify the original author, write an audit
		 * entry, or purge a downstream cache.
		 *
		 * `snapshot_id` is the revision holding the published content from
		 * immediately before the merge, which is the point to restore to if the
		 * merge turns out to have been wrong.
		 *
		 * `drifted` with `override` is the pair that cannot be reconstructed
		 * afterwards: together they record whether the published post had moved
		 * and which exact state the editor confirmed against before overwriting
		 * it.
		 *
		 * @since 0.1.0
		 *
		 * @param int   $live_id Published post ID that received the merge.
		 * @param array $payload {
		 *     What the merge did.
		 *
		 *     @type int    $staged_copy_id   ID of the staged copy, now deleted.
		 *     @type int    $snapshot_id Revision holding the pre-merge published content.
		 *     @type int    $staged_by   User who created the staged copy.
		 *     @type string $forked_at   GMT time the staged copy was created.
		 *     @type int    $merged_by   User who applied the merge.
		 *     @type bool   $drifted     Whether the live post moved while staged.
		 *     @type string $override    The state confirmed against, when it drifted.
		 *     @type int[]  $revisions   Staged revisions adopted onto the live post.
		 *     @type int[]  $attachments Attachments moved onto the live post.
		 * }
		 */
		do_action( 'swpub_merge_completed', $live_id, self::payload( $marker ) );

		return true;
	}

	/**
	 * Shapes the completion event's payload.
	 *
	 * Normalized rather than passing the raw marker, so listeners get a stable
	 * public shape and the marker's internal bookkeeping -- phase, attempt
	 * count, retry timing -- stays private.
	 *
	 * @param array<string, mixed> $marker The completed marker.
	 * @return array<string, mixed> The payload.
	 */
	private static function payload( array $marker ): array {
		return array(
			'staged_copy_id'   => (int) ( $marker['staged_copy_id'] ?? 0 ),
			'snapshot_id' => (int) ( $marker['snapshot_id'] ?? 0 ),
			'staged_by'   => (int) ( $marker['staged_by'] ?? 0 ),
			'forked_at'   => (string) ( $marker['forked_at'] ?? '' ),
			'merged_by'   => (int) ( $marker['merged_by'] ?? 0 ),
			'drifted'     => (bool) ( $marker['drifted'] ?? false ),
			'override'    => (string) ( $marker['override'] ?? '' ),
			'revisions'   => array_values( (array) ( $marker['revisions'] ?? array() ) ),
			'attachments' => array_values( (array) ( $marker['attachments'] ?? array() ) ),
		);
	}

	/**
	 * The staged copy a marker names, but only when the pointers still agree.
	 *
	 * The marker drives the two most destructive steps in the plugin, force
	 * deletion and reparenting, so it is revalidated exactly like a pointer
	 * (KTD14) rather than trusted because it was written by us.
	 *
	 * The one exception is the final phase. The merge clears both pointers
	 * immediately before deleting the staged copy, on purpose, because a pointer to
	 * a deleted post fails every save closed. So a merge interrupted in that
	 * gap leaves a staged copy with no pointers, and a strict check would refuse to
	 * finish it and strand it permanently. In that phase the staged copy is instead
	 * verified as a staged post of the live post's type whose reverse pointer
	 * is absent rather than naming somebody else -- which still cannot resolve
	 * to an unrelated published post.
	 *
	 * @param int                  $live_id        Live post ID.
	 * @param array<string, mixed> $marker         The marker.
	 * @param bool                 $allow_unlinked Whether the pointers may already be cleared.
	 * @return WP_Post|null The staged copy, or null when the marker cannot be acted on.
	 */
	public static function validated_staged_copy( int $live_id, array $marker, bool $allow_unlinked = false ): ?WP_Post {
		$staged_copy_id = (int) ( $marker['staged_copy_id'] ?? 0 );

		if ( $staged_copy_id <= 0 ) {
			return null;
		}

		if ( Staged_Copy_Repository::pointers_agree( $live_id, $staged_copy_id ) ) {
			return get_post( $staged_copy_id );
		}

		if ( ! $allow_unlinked ) {
			return null;
		}

		$staged_copy = get_post( $staged_copy_id );
		$live   = get_post( $live_id );

		if ( ! $staged_copy instanceof WP_Post || ! $live instanceof WP_Post ) {
			return null;
		}

		if ( ! Status::is_staged( $staged_copy ) || $staged_copy->post_type !== $live->post_type ) {
			return null;
		}

		$reverse = (int) get_post_meta( $staged_copy_id, Staged_Copy_Repository::LIVE_META, true );

		return ( 0 === $reverse || $live_id === $reverse ) ? $staged_copy : null;
	}
}
