<?php
/**
 * Drift detection and the one-time override.
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
 * Answers one question: has the published post moved since this staged copy forked?
 *
 * Detection compares the live post's GMT modified time against the value the
 * fork recorded (KTD11). GMT because a site timezone change shifts local
 * timestamps and would break the comparison in both directions. Timestamps
 * rather than content because a merge that silently overwrites someone's edit
 * is the failure this product exists to prevent, and an inconsequential touch
 * reported as drift costs one confirmation.
 */
final class Drift {

	/**
	 * The parameter a merge sends to confirm against a state it was shown.
	 */
	public const OVERRIDE_PARAM = 'swpub_seen_modified_gmt';

	/**
	 * The exact shape of a GMT timestamp in `post_modified_gmt`.
	 */
	private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

	/**
	 * The live post's GMT modified time as it was when the staged copy forked.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return string The recorded timestamp, or an empty string when none was recorded.
	 */
	public static function baseline( int $staged_copy_id ): string {
		return (string) get_post_meta( $staged_copy_id, Staged_Copy_Repository::FORK_BASELINE_META, true );
	}

	/**
	 * Whether the live post has changed since the fork.
	 *
	 * A missing baseline counts as drift. It should be impossible -- the fork
	 * writes it inside the insert -- but guessing "no drift" from missing
	 * evidence is the one wrong answer here.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return bool True when the live post moved.
	 */
	public static function has_drifted( int $staged_copy_id ): bool {
		$live = Staged_Copy_Repository::find_live_for_staged_copy( $staged_copy_id );

		if ( ! $live instanceof WP_Post ) {
			return false;
		}

		$baseline = self::baseline( $staged_copy_id );

		if ( '' === $baseline ) {
			return true;
		}

		return $baseline !== $live->post_modified_gmt;
	}

	/**
	 * The gate a merge calls immediately before it writes.
	 *
	 * Re-reads the live post from the database rather than trusting anything
	 * loaded earlier in the request, which closes the window between the check
	 * and the write that R21 leaves open by design: an ungated writer can touch
	 * the live post at any moment, including between an editor's drift check and
	 * their merge.
	 *
	 * @param int         $staged_copy_id Staged copy post ID.
	 * @param string|null $override  The GMT timestamp the editor confirmed against, if any.
	 * @return true|WP_Error True when the merge may proceed.
	 */
	public static function check( int $staged_copy_id, ?string $override = null ) {
		$live = self::fresh_live( $staged_copy_id );

		if ( ! $live instanceof WP_Post ) {
			return new WP_Error(
				'swpub_no_live_post',
				__( 'The published post this stages no longer exists.', 'save-without-publish' ),
				array( 'status' => 409 )
			);
		}

		$confirmed = null;

		if ( null !== $override && '' !== $override ) {
			if ( ! self::is_timestamp( $override ) ) {
				return new WP_Error(
					'swpub_bad_override',
					__( 'The confirmation did not name a valid published state.', 'save-without-publish' ),
					array( 'status' => 400 )
				);
			}

			$confirmed = $override;
		}

		if ( ! self::has_drifted( $staged_copy_id ) ) {
			// No drift, so there is nothing for a confirmation to consume. A
			// merge carrying one is an ordinary merge, not a bypass.
			return true;
		}

		/*
		 * Equality, not "not older" (KTD15). An ordering test lets a client send
		 * a timestamp far in the future and satisfy the check on every request,
		 * forever, which turns the override into an unconditional bypass.
		 */
		if ( null !== $confirmed && $confirmed === $live->post_modified_gmt ) {
			return true;
		}

		return new WP_Error(
			'swpub_drift',
			__( 'The published post changed after these edits were staged. Review the change before publishing.', 'save-without-publish' ),
			array(
				'status'        => 409,
				'live_id'       => $live->ID,
				'forked_at'     => self::baseline( $staged_copy_id ),
				'live_modified' => $live->post_modified_gmt,
				'history_url'   => self::history_url( $live->ID ),
			)
		);
	}

	/**
	 * The live post's own history, on whichever revisions screen reviews a
	 * staged change (`Review_Link::surface()`).
	 *
	 * This is what a refused merge offers (R13). It is core's screen,
	 * unmodified, opened on the most recent revision -- which for drift is
	 * exactly the change being asked about, because that revision is the
	 * write that caused it. Core names its own "revision before this one"
	 * on both surfaces; only the destination screen differs.
	 *
	 * @param int $live_id Live post ID.
	 * @return string The URL, or an empty string when the post has no revisions.
	 */
	public static function history_url( int $live_id ): string {
		$latest = wp_get_latest_revision_id_and_total_count( $live_id );

		if ( is_wp_error( $latest ) || empty( $latest['latest_id'] ) ) {
			return '';
		}

		if ( 'classic' === Review_Link::surface() ) {
			return Review_Link::classic_revision_url( (int) $latest['latest_id'] );
		}

		return Review_Link::for_revision( $live_id, (int) $latest['latest_id'] );
	}

	/**
	 * Re-reads the live post past any cache.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return WP_Post|null The live post, or null when the link is broken.
	 */
	private static function fresh_live( int $staged_copy_id ): ?WP_Post {
		$live = Staged_Copy_Repository::find_live_for_staged_copy( $staged_copy_id );

		if ( ! $live instanceof WP_Post ) {
			return null;
		}

		clean_post_cache( $live->ID );

		return Staged_Copy_Repository::find_live_for_staged_copy( $staged_copy_id );
	}

	/**
	 * Whether a value is exactly a `post_modified_gmt` timestamp.
	 *
	 * Format is checked before value so nothing is coerced: PHP would happily
	 * compare an integer or an array-shaped value and produce an answer.
	 *
	 * @param string $value Candidate timestamp.
	 * @return bool True when the value has the exact expected shape.
	 */
	private static function is_timestamp( string $value ): bool {
		return 1 === preg_match( self::TIMESTAMP_PATTERN, $value );
	}
}
