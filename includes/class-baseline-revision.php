<?php
/**
 * The fork-time baseline revision.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records the live post's content as the staged copy's first revision (R6).
 *
 * This is what makes core's unmodified revision screen a review surface: with a
 * baseline holding the as-published text, the staged copy's own history compares
 * as-published against staged edits, so the plugin ships no diff of its own.
 *
 * Uses core's public revision API rather than the private builder KTD4 names.
 * That decision assumed a revision whose content differs from the post's current
 * state, which is not the case here: the staged copy is created as a copy of the live
 * post, so at the moment this runs its current state *is* the live content and
 * the public call captures exactly the right baseline. KTD4's private dependency
 * and its stop condition are therefore unnecessary and should be retired.
 */
final class Baseline_Revision {

	/**
	 * Seeds the baseline on a freshly created staged copy.
	 *
	 * Must be called before any staged content is written, while the staged copy
	 * still holds the copied live content.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return bool True when a revision was recorded.
	 */
	public static function seed( int $staged_copy_id ): bool {
		if ( $staged_copy_id <= 0 ) {
			return false;
		}

		$revision_id = wp_save_post_revision( $staged_copy_id );

		return is_int( $revision_id ) && $revision_id > 0;
	}
}
