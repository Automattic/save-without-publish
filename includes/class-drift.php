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
 * Detection compares the live post's GMT modified time and the fingerprint of
 * its staged fields against the values the fork recorded (KTD11). GMT because
 * a site timezone change shifts local timestamps and would break the
 * comparison in both directions. Both, rather than the timestamp alone,
 * because a merge that silently overwrites someone's edit is the failure this
 * product exists to prevent, and a timestamp move with no content behind it
 * (VIPPROD-752, `kind()`) costs one confirmation for something the merge
 * would never have overwritten anyway.
 */
final class Drift {

	/**
	 * The exact shape of the confirmation token `state()` produces.
	 */
	private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

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
	 * A byte-exact fingerprint of the fields a merge actually writes.
	 *
	 * Not normalised (VIPPROD-752): the merge overwrites bytes, so bytes are
	 * what drift is. This is what lets a content change be told apart from
	 * everything else that can move `post_modified_gmt` -- and, paired with
	 * the timestamp, what lets a content change be caught even when the
	 * timestamp does not move at all, which a direct database write or a
	 * restore from backup can do.
	 *
	 * @param WP_Post $post The post to fingerprint.
	 * @return string A sha256 hex digest of the staged fields.
	 */
	public static function fingerprint( WP_Post $post ): string {
		return hash( 'sha256', $post->post_title . "\0" . $post->post_content . "\0" . $post->post_excerpt );
	}

	/**
	 * The live post's whole comparable state, as one token a confirmation can name.
	 *
	 * Binding the timestamp and the fingerprint together in one hash, rather
	 * than sending each separately, is what makes a single equality check
	 * (KTD15) enough: a confirmation obtained before a content change that
	 * preserved the timestamp names a state that no longer exists once that
	 * change lands, exactly as one obtained before an ordinary edit does.
	 *
	 * @param WP_Post $live The live post.
	 * @return string A sha256 hex digest of its modified time and its fingerprint.
	 */
	public static function state( WP_Post $live ): string {
		return hash( 'sha256', $live->post_modified_gmt . "\0" . self::fingerprint( $live ) );
	}

	/**
	 * What, if anything, changed on the live post since the fork.
	 *
	 * Two independent facts, read together:
	 *
	 * - Did `post_modified_gmt` move from the value recorded at fork?
	 * - Does the live post's current fingerprint match the one recorded at fork?
	 *
	 * A fingerprint mismatch is `'content'` regardless of the timestamp,
	 * because that is the one case a merge can silently overwrite: a direct
	 * database write or a restore from backup can change title, content, or
	 * excerpt without moving `post_modified_gmt` at all. A moved timestamp
	 * with the same fingerprint is `'other'` -- something changed that the
	 * merge never writes, such as a category. A copy with no recorded
	 * fingerprint (backfilled from nothing, or created before VIPPROD-752
	 * and never backfilled) is `'unknown'` when the timestamp moved, the
	 * same answer a missing baseline gets; neither can tell content drift
	 * from anything else.
	 *
	 * @param int          $staged_copy_id Staged copy post ID.
	 * @param WP_Post|null $live      The live post, when already fetched fresh.
	 *                                Fetched again when omitted.
	 * @return string `''` (no drift), `'content'`, `'other'`, or `'unknown'`.
	 */
	public static function kind( int $staged_copy_id, ?WP_Post $live = null ): string {
		$live = $live ?? Staged_Copy_Repository::find_live_for_staged_copy( $staged_copy_id );

		if ( ! $live instanceof WP_Post ) {
			return '';
		}

		$baseline = self::baseline( $staged_copy_id );

		if ( '' === $baseline ) {
			return 'unknown';
		}

		$moved       = $baseline !== $live->post_modified_gmt;
		$fingerprint = (string) get_post_meta( $staged_copy_id, Staged_Copy_Repository::FORK_FINGERPRINT_META, true );

		if ( '' === $fingerprint ) {
			return $moved ? 'unknown' : '';
		}

		if ( $fingerprint !== self::fingerprint( $live ) ) {
			return 'content';
		}

		return $moved ? 'other' : '';
	}

	/**
	 * Whether the live post has changed since the fork.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return bool True when `kind()` is anything but no drift.
	 */
	public static function has_drifted( int $staged_copy_id ): bool {
		return '' !== self::kind( $staged_copy_id );
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
	 * @param string|null $override  The state token the editor confirmed against, if any.
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
			if ( ! self::is_token( $override ) ) {
				return new WP_Error(
					'swpub_bad_override',
					__( 'The confirmation did not name a valid published state.', 'save-without-publish' ),
					array( 'status' => 400 )
				);
			}

			$confirmed = $override;
		}

		$kind = self::kind( $staged_copy_id, $live );

		if ( '' === $kind ) {
			// No drift, so there is nothing for a confirmation to consume. A
			// merge carrying one is an ordinary merge, not a bypass.
			return true;
		}

		/*
		 * Equality, not "not older" (KTD15). An ordering test lets a client send
		 * a token it captured once and satisfy the check on every later request,
		 * forever, which turns the override into an unconditional bypass. Naming
		 * the fingerprint as well as the timestamp closes the same door one layer
		 * up: a token captured before a content change that preserved the
		 * timestamp names a state that no longer exists once that change lands.
		 */
		if ( null !== $confirmed && $confirmed === self::state( $live ) ) {
			return true;
		}

		return new WP_Error(
			'swpub_drift',
			self::message( $kind ),
			array(
				'status'        => 409,
				'live_id'       => $live->ID,
				'drift_kind'    => $kind,
				'forked_at'     => self::baseline( $staged_copy_id ),
				'live_modified' => $live->post_modified_gmt,
				'live_state'    => self::state( $live ),
				// A content change is what the review screen can show; anything
				// else diffs to nothing there, so there is nothing to send an
				// editor to look at.
				'history_url'   => 'other' === $kind ? '' : self::history_url( $live->ID ),
			)
		);
	}

	/**
	 * The refusal's sentence, specific to what actually changed.
	 *
	 * `content` and `unknown` both name a change the merge could overwrite --
	 * `unknown` because there is nothing recorded to say otherwise -- and get
	 * the same words. `other` is the one case that is provably not that: the
	 * merge writes only title, content, and excerpt, so a change that left
	 * those three untouched is not something publishing could overwrite.
	 *
	 * @param string $kind `'content'`, `'other'`, or `'unknown'`.
	 * @return string The message.
	 */
	private static function message( string $kind ): string {
		if ( 'other' === $kind ) {
			return __( 'The published post was updated after these edits were staged, but its title, content, and excerpt are unchanged. Publishing will not overwrite that update.', 'save-without-publish' );
		}

		return __( 'The published post changed after these edits were staged. Review the change before publishing.', 'save-without-publish' );
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
	 * Whether a value is exactly the shape `state()` produces.
	 *
	 * Format is checked before value so nothing is coerced: PHP would happily
	 * compare an integer or an array-shaped value and produce an answer.
	 *
	 * @param string $value Candidate token.
	 * @return bool True when the value has the exact expected shape.
	 */
	private static function is_token( string $value ): bool {
		return 1 === preg_match( self::TOKEN_PATTERN, $value );
	}
}
