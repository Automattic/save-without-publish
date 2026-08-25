<?php
/**
 * Applying staged content to the published post.
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
 * The merge: staged content becomes published content.
 *
 * This is the only code in the plugin that writes to a published post or
 * deletes anything, so the ordering below is load-bearing rather than tidy:
 *
 *  - The snapshot is taken first, so there is a restore point before anything
 *    changes. A revision records the state after a save, so without this the
 *    pre-merge content survives only if some earlier save happened to leave a
 *    revision behind.
 *  - Revisions are adopted before the staged copy is deleted, because core's
 *    cleanup on delete finds them by parent with raw SQL nothing can filter.
 *  - Attachments move before the content write, which is the step most likely
 *    to time out on a large post.
 *  - Pointers are cleared before deletion, because a pointer to a live staged copy
 *    is recoverable while a pointer to a deleted post fails every save closed
 *    and makes the published post uneditable.
 *
 * Every step is idempotent (KTD19), which is what lets an interrupted merge be
 * re-entered at its recorded phase rather than reasoned about.
 */
final class Merge {

	/**
	 * Object cache group for the single-flight claim.
	 */
	private const CLAIM_GROUP = 'swpub';

	/**
	 * How long a claim survives if a merge dies holding it.
	 */
	private const CLAIM_TTL = 300;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'swpub_resume_merge', array( __CLASS__, 'resume' ), 10, 3 );
	}

	/**
	 * Applies a staged copy's content to its live post.
	 *
	 * @param int         $staged_copy_id Staged copy post ID.
	 * @param string|null $override  Drift confirmation, naming the state the editor was shown.
	 * @return array<string, mixed>|WP_Error The live post ID and what was merged.
	 */
	public static function apply( int $staged_copy_id, ?string $override = null ) {
		$staged_copy = get_post( $staged_copy_id );

		if ( ! $staged_copy instanceof WP_Post || ! Status::is_staged( $staged_copy ) ) {
			return new WP_Error(
				'swpub_not_staged',
				__( 'There is nothing staged to publish.', 'save-without-publish' ),
				array( 'status' => 404 )
			);
		}

		if ( ! Capabilities::current_user_can_manage( $staged_copy_id ) ) {
			return new WP_Error(
				'swpub_forbidden',
				__( 'You are not allowed to publish these changes.', 'save-without-publish' ),
				array( 'status' => 403 )
			);
		}

		$live = Staged_Copy_Repository::find_live_for_staged_copy( $staged_copy_id );

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

		if ( ! self::claim( $live->ID ) ) {
			return new WP_Error(
				'swpub_merge_in_progress',
				__( 'These changes are already being published.', 'save-without-publish' ),
				array( 'status' => 409 )
			);
		}

		try {
			$drifted = Drift::has_drifted( $staged_copy_id );
			$gate    = Drift::check( $staged_copy_id, $override );

			if ( is_wp_error( $gate ) ) {
				return $gate;
			}

			/**
			 * Filters whether a merge may proceed.
			 *
			 * Return a WP_Error to veto it. A veto happens before any write, so
			 * both posts are left exactly as they were and no merge is recorded
			 * as having started.
			 *
			 * @since 0.1.0
			 *
			 * @param bool|WP_Error $allowed   Whether the merge may proceed.
			 * @param int           $live_id   Published post ID.
			 * @param int           $staged_copy_id Staged copy post ID.
			 */
			$allowed = apply_filters( 'swpub_pre_merge', true, $live->ID, $staged_copy_id );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			if ( true !== $allowed ) {
				return new WP_Error(
					'swpub_merge_vetoed',
					__( 'Publishing these changes was blocked.', 'save-without-publish' ),
					array( 'status' => 409 )
				);
			}

			if ( $drifted ) {
				// Announced before the write, so a listener sees it even if the
				// merge then fails. This is the one moment the plugin knowingly
				// overwrites somebody else's edit.
				Events::drift_overridden( $staged_copy_id, $live->ID, (string) $override );
			}

			Merge_Marker::start( $live->ID, $staged_copy_id );
			Merge_Marker::record(
				$live->ID,
				array(
					'staged_by' => (int) $staged_copy->post_author,
					'forked_at' => (string) $staged_copy->post_date_gmt,
					'merged_by' => get_current_user_id(),
					'drifted'   => $drifted,
					'override'  => $drifted ? (string) $override : '',
				)
			);

			return self::run_from( Merge_Marker::PHASE_SNAPSHOTTING, $live->ID, $staged_copy_id, $override );
		} finally {
			self::release( $live->ID );
		}
	}

	/**
	 * Re-enters an interrupted merge at its recorded phase.
	 *
	 * @param int    $live_id   Live post ID.
	 * @param int    $staged_copy_id Staged copy post ID, already revalidated by the resume routine.
	 * @param string $phase     Phase to re-enter.
	 * @return array<string, mixed>|WP_Error The outcome.
	 */
	public static function resume( int $live_id, int $staged_copy_id, string $phase ) {
		if ( ! self::claim( $live_id ) ) {
			return new WP_Error( 'swpub_merge_in_progress', __( 'These changes are already being published.', 'save-without-publish' ) );
		}

		try {
			/*
			 * Read the confirmation back off the marker rather than resuming
			 * with none. A merge the editor legitimately confirmed past drift
			 * would otherwise fail its own gate on every attempt -- the fork
			 * baseline still differs from the live post, and nothing about the
			 * interruption changed that -- until the attempt budget stranded
			 * it. `--force` could not help either, since reviving only resets
			 * the counter and the next attempt meets the same gate.
			 */
			$marker   = Merge_Marker::get( $live_id );
			$override = $marker ? (string) ( $marker['override'] ?? '' ) : '';

			return self::run_from( $phase, $live_id, $staged_copy_id, '' === $override ? null : $override );
		} finally {
			self::release( $live_id );
		}
	}

	/**
	 * Runs the merge from a phase onwards.
	 *
	 * Deliberately a fall-through rather than a loop: each phase advances the
	 * marker before the step it names, so an interruption anywhere lands on the
	 * step that had not finished.
	 *
	 * @param string      $phase     Phase to start at.
	 * @param int         $live_id   Live post ID.
	 * @param int         $staged_copy_id Staged copy post ID.
	 * @param string|null $override  Drift confirmation, on a first attempt.
	 * @return array<string, mixed>|WP_Error The outcome.
	 */
	private static function run_from( string $phase, int $live_id, int $staged_copy_id, ?string $override ) {
		$from = array_search( $phase, Merge_Marker::PHASES, true );

		if ( false === $from ) {
			return new WP_Error( 'swpub_unknown_phase', __( 'Cannot resume from an unrecognised state.', 'save-without-publish' ) );
		}

		$reached = static function ( string $candidate ) use ( $from ): bool {
			return array_search( $candidate, Merge_Marker::PHASES, true ) >= $from;
		};

		/*
		 * Gate before the first step that touches the live post, not only
		 * before the content write.
		 *
		 * Adoption re-parents the staged revisions onto the live post and
		 * reparenting moves its media, both of which happen earlier. Gating
		 * only at the write meant a third-party edit mid-merge left the live
		 * post carrying staged revisions -- its newest revision holding content
		 * that was never published, which is exactly the state KTD6 exists to
		 * prevent -- and then refused, so nothing cleaned them up.
		 */
		$gate = self::gate( $live_id, $staged_copy_id, $override );

		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		if ( $reached( Merge_Marker::PHASE_SNAPSHOTTING ) ) {
			Merge_Marker::advance( $live_id, Merge_Marker::PHASE_SNAPSHOTTING );
			Merge_Marker::record( $live_id, array( 'snapshot_id' => self::snapshot( $live_id ) ) );
		}

		if ( $reached( Merge_Marker::PHASE_ADOPTING ) ) {
			Merge_Marker::advance( $live_id, Merge_Marker::PHASE_ADOPTING );

			// Recorded before the move, like the attachment set: afterwards the
			// revisions are no longer the staged copy's and the set cannot be
			// worked out again.
			$marker   = Merge_Marker::get( $live_id );
			$existing = (array) ( $marker['revisions'] ?? array() );

			Merge_Marker::record(
				$live_id,
				array(
					'revisions' => array_values(
						array_unique(
							array_merge( $existing, Revision_Adopter::adoptable( $staged_copy_id ) )
						)
					),
				)
			);

			Revision_Adopter::adopt( $live_id, $staged_copy_id );
		}

		if ( $reached( Merge_Marker::PHASE_REPARENTING ) ) {
			Merge_Marker::advance( $live_id, Merge_Marker::PHASE_REPARENTING );

			$marker = Merge_Marker::get( $live_id );

			if ( empty( $marker['attachments'] ) ) {
				Merge_Marker::record_attachments( $live_id, Media::attachments_for( $staged_copy_id ) );
				$marker = Merge_Marker::get( $live_id );
			}

			Media::reparent( $live_id, $staged_copy_id, (array) $marker['attachments'] );
		}

		if ( $reached( Merge_Marker::PHASE_WRITING ) ) {
			Merge_Marker::advance( $live_id, Merge_Marker::PHASE_WRITING );

			$written = self::write( $live_id, $staged_copy_id, $override );

			if ( is_wp_error( $written ) ) {
				return $written;
			}
		}

		Merge_Marker::advance( $live_id, Merge_Marker::PHASE_FINISHING );

		return self::finish( $live_id, $staged_copy_id );
	}

	/**
	 * The drift gate, skipped once there is nothing left to write.
	 *
	 * The order matters and is not a preference. The merge's own write moves
	 * the live post past the fork baseline, so on a resumed merge an
	 * unconditional gate would fire against our own completed write and no
	 * interrupted merge could ever finish. The gate exists to stop a write from
	 * clobbering somebody else's change; where the staged content already
	 * matches the live post there is no write left to do and nothing to
	 * protect. If a third party did edit the live post after ours, the contents
	 * differ, the gate runs, and the merge refuses rather than overwriting them.
	 *
	 * @param int         $live_id   Live post ID.
	 * @param int         $staged_copy_id Staged copy post ID.
	 * @param string|null $override  Drift confirmation.
	 * @return true|WP_Error True when the merge may touch the live post.
	 */
	private static function gate( int $live_id, int $staged_copy_id, ?string $override ) {
		if ( self::staged_content_matches( $live_id, $staged_copy_id ) ) {
			return true;
		}

		return Drift::check( $staged_copy_id, $override );
	}

	/**
	 * Whether the live post already holds the staged content.
	 *
	 * @param int $live_id   Live post ID.
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return bool True when the write has already landed.
	 */
	private static function staged_content_matches( int $live_id, int $staged_copy_id ): bool {
		$live   = get_post( $live_id );
		$staged_copy = get_post( $staged_copy_id );

		if ( ! $live instanceof WP_Post || ! $staged_copy instanceof WP_Post ) {
			return false;
		}

		foreach ( Staged_Copy_Repository::STAGED_FIELDS as $field ) {
			if ( $staged_copy->$field !== $live->$field ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Saves the live post's current content as a restorable revision.
	 *
	 * When the content already matches the newest revision core declines to
	 * write another, which is correct and not a failure: that existing revision
	 * is the restore point, so it is reported as the snapshot.
	 *
	 * @param int $live_id Live post ID.
	 * @return int Revision ID holding the pre-merge content, or 0.
	 */
	private static function snapshot( int $live_id ): int {
		$snapshot_id = wp_save_post_revision( $live_id );

		if ( is_int( $snapshot_id ) && $snapshot_id > 0 ) {
			return $snapshot_id;
		}

		$latest = wp_get_latest_revision_id_and_total_count( $live_id );

		if ( is_wp_error( $latest ) || empty( $latest['latest_id'] ) ) {
			return 0;
		}

		return (int) $latest['latest_id'];
	}

	/**
	 * Writes the staged fields onto the live post.
	 *
	 * The field list is explicit (KTD17). Passing the staged copy's post array would
	 * carry its staged status and its non-colliding slug onto the live post,
	 * unpublishing the article and breaking every inbound link.
	 *
	 * Core's revision on this write is deliberately not suppressed (KTD6).
	 * Suppressing it would leave the pre-merge snapshot as the newest revision
	 * while the post holds merged content, so anything treating the newest
	 * revision as current would silently revert the article.
	 *
	 * @param int         $live_id   Live post ID.
	 * @param int         $staged_copy_id Staged copy post ID.
	 * @param string|null $override  Drift confirmation.
	 * @return true|WP_Error True when written.
	 */
	private static function write( int $live_id, int $staged_copy_id, ?string $override ) {
		$staged_copy = get_post( $staged_copy_id );

		if ( ! $staged_copy instanceof WP_Post ) {
			return new WP_Error( 'swpub_staged_copy_missing', __( 'The staged copy is no longer available.', 'save-without-publish' ) );
		}

		$live = get_post( $live_id );

		if ( ! $live instanceof WP_Post ) {
			return new WP_Error( 'swpub_no_live_post', __( 'The published post no longer exists.', 'save-without-publish' ) );
		}

		$fields = array( 'ID' => $live_id );
		$same   = true;

		foreach ( Staged_Copy_Repository::STAGED_FIELDS as $field ) {
			$fields[ $field ] = $staged_copy->$field;

			if ( $staged_copy->$field !== $live->$field ) {
				$same = false;
			}
		}

		if ( ! $same ) {
			// Re-check immediately before writing (KTD15). R21 leaves this
			// window open by design: an ungated writer can touch the live post
			// at any moment, including since the gate at the top of the merge.
			$gate = self::gate( $live_id, $staged_copy_id, $override );

			if ( is_wp_error( $gate ) ) {
				return $gate;
			}

			/*
			 * The one live write the containment lets through (KTD29). Without
			 * the sanction the guard would divert this straight back into the
			 * staged copy the merge is publishing, and the merge would report
			 * success having changed nothing. Scoped to this post ID and cleared
			 * in a `finally`, so it cannot ride onto another post or outlive the
			 * call.
			 */
			$result = Write_Guard::sanction(
				$live_id,
				static function () use ( $fields ) {
					return wp_update_post( wp_slash( $fields ), true );
				}
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		/*
		 * Idempotent by content equality (KTD19): a resumed merge whose write
		 * already landed skips the write above rather than stacking a second
		 * revision. But core creates the merged revision on `post_updated`,
		 * after the row is written, so an interruption in that gap leaves the
		 * post holding merged content while the newest revision is still the
		 * pre-merge snapshot -- the exact state KTD6 exists to prevent.
		 *
		 * This closes the gap. Core declines to write a revision when the newest
		 * one already matches, so on the ordinary path it is a no-op.
		 */
		wp_save_post_revision( $live_id );

		return true;
	}

	/**
	 * Clears up after a successful write and announces the merge.
	 *
	 * @param int $live_id   Live post ID.
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return array<string, mixed> What the merge did.
	 */
	private static function finish( int $live_id, int $staged_copy_id ): array {
		Revision_Adopter::drop_autosaves( $live_id );
		Revision_Adopter::drop_autosaves( $staged_copy_id );

		// Pointers first: a pointer to a deleted post fails every save closed.
		Staged_Copy_Repository::unlink( $live_id, $staged_copy_id );

		if ( get_post( $staged_copy_id ) instanceof WP_Post ) {
			wp_delete_post( $staged_copy_id, true );
		}

		$marker = Merge_Marker::get( $live_id );

		Merge_Marker::complete( $live_id );

		return array(
			'live_id'     => $live_id,
			'staged_copy_id'   => $staged_copy_id,
			'snapshot_id' => (int) ( $marker['snapshot_id'] ?? 0 ),
			'revisions'   => array_values( (array) ( $marker['revisions'] ?? array() ) ),
			'attachments' => array_values( (array) ( $marker['attachments'] ?? array() ) ),
		);
	}

	/**
	 * Takes the single-flight claim.
	 *
	 * An atomic cache add rather than a read-then-write on meta (KTD22), whose
	 * race window is wide enough to lose the two-simultaneous-merges case.
	 *
	 * The honest limit: a cache flush releases the claim early. That is why
	 * idempotence, not this, is the real defence.
	 *
	 * @param int $live_id Live post ID.
	 * @return bool True when the claim was taken.
	 */
	private static function claim( int $live_id ): bool {
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- CLAIM_TTL is 300, the minimum this sniff asks for; it cannot resolve the constant.
		return (bool) wp_cache_add( 'merge_' . $live_id, 1, self::CLAIM_GROUP, self::CLAIM_TTL );
	}

	/**
	 * Releases the claim.
	 *
	 * @param int $live_id Live post ID.
	 * @return void
	 */
	private static function release( int $live_id ): void {
		wp_cache_delete( 'merge_' . $live_id, self::CLAIM_GROUP );
	}
}
