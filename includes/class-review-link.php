<?php
/**
 * The route to the review surface.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Post;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the URL that reviews a staged change (R23).
 *
 * Shared rather than owned by whichever screen happens to link there. Two
 * screens offering "review this change" and landing on different comparisons
 * would be two review surfaces wearing one name.
 */
final class Review_Link {

	/**
	 * Registers the one filter this surface needs.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'rest_revision_query', array( __CLASS__, 'span_staged_revisions' ), 10, 2 );
	}

	/**
	 * Narrows a staged copy's revision list to the two ends of the change.
	 *
	 * The editor's revisions view has no `from` and `to`: it diffs a revision
	 * against whichever revision precedes it in the list core hands it. So the
	 * span is made in the list rather than in the URL. For a staged copy the
	 * list becomes exactly two entries, the fork-time baseline and the newest
	 * staged save, which makes "the revision before this one" mean "as
	 * published" and the view show the whole staged change in one reading.
	 *
	 * Only staged copies, and only their own revisions. A published post's list
	 * is untouched, because its history is its history.
	 *
	 * Nothing is deleted and nothing is hidden from anyone who asks another way.
	 * The intermediate saves stay in the database with their authors and
	 * timestamps, the classic revisions screen still lists every one of them,
	 * and publishing still adopts them all into the published post's history.
	 * What narrows is one view of them.
	 *
	 * @param array           $args    WP_Query arguments for the revision query.
	 * @param WP_REST_Request $request The REST request.
	 * @return array The arguments to query with.
	 */
	public static function span_staged_revisions( $args, $request ): array {
		$args   = (array) $args;
		$parent = isset( $args['post_parent'] ) ? (int) $args['post_parent'] : 0;

		if ( ! Status::is_staged( $parent ) ) {
			return $args;
		}

		$ends = self::span_for( $parent );

		if ( count( $ends ) < 2 ) {
			return $args;
		}

		/*
		 * `post__in` rather than a date range, because the two ends are known by
		 * ID and a range would also catch anything saved between them. The
		 * ordering core asked for is left alone: the view reads the list in the
		 * order it is given, and reversing it would put the baseline where the
		 * newest save belongs.
		 */
		$args['post__in'] = $ends;

		return $args;
	}

	/**
	 * A staged copy's baseline revision, if it has been seeded.
	 *
	 * The client-side twin of the check in `for_staged_copy()`: something has
	 * to tell the editor's reactive review link (`routes.js`'s
	 * `useReviewUrl()`) apart the moment a real second end appears from the
	 * one revision the copy is born with, and `getCurrentPostLastRevisionId()`
	 * cannot -- it names whichever revision is newest, baseline included, so
	 * on a copy with only its baseline it is already answering "yes" to the
	 * wrong question.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return int The revision ID, or 0 when the copy has none yet.
	 */
	public static function baseline_revision_id( int $staged_copy_id ): int {
		$ends = self::span_for( $staged_copy_id );

		return $ends ? $ends[0] : 0;
	}

	/**
	 * The two ends of a staged copy's change, oldest first.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return int[] The baseline and newest revision IDs, or fewer when there
	 *               are not two of them.
	 */
	private static function span_for( int $staged_copy_id ): array {
		$revisions = self::staged_revisions( $staged_copy_id );

		if ( count( $revisions ) < 2 ) {
			return array_map( static fn ( $revision ) => (int) $revision->ID, $revisions );
		}

		return array( (int) $revisions[0]->ID, (int) end( $revisions )->ID );
	}

	/**
	 * Core's in-editor revisions view, opened on one revision.
	 *
	 * Reached the way core reaches it, which is a post to edit and the revision
	 * to open on. The screen is core's, with the parts that describe a history
	 * hidden for a staged copy, which has two states rather than one: see
	 * `src/editor/revisions-screen.scss` for what goes and why.
	 *
	 * The actions that drive it from JavaScript are private to core, so a URL is
	 * the only way in. It is also the better way in: a link survives the editor
	 * failing to load, and it is what a notice, a row, and an admin screen can
	 * all carry.
	 *
	 * @param int $post_id     The post whose revisions are being read.
	 * @param int $revision_id The revision to open on.
	 * @return string The URL, or an empty string when either ID is missing.
	 */
	public static function for_revision( int $post_id, int $revision_id ): string {
		if ( $post_id <= 0 || $revision_id <= 0 ) {
			return '';
		}

		return add_query_arg(
			array(
				'post'     => $post_id,
				'action'   => 'edit',
				'revision' => $revision_id,
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * The review for a staged copy, which is its newest staged save against
	 * the fork-time baseline.
	 *
	 * The timeline is hidden on a staged copy's revisions view
	 * (`src/editor/revisions-screen.scss`), so there is one reading: this
	 * revision against the one before it, which `span_staged_revisions()`
	 * above has already narrowed to mean "as published". A copy holding only
	 * its baseline has nothing on the other side of that comparison -- one
	 * revision is not a change -- so this answers with nothing to review
	 * rather than a link to a screen that would say "Only one revision
	 * found."
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return string The URL, or an empty string when there is nothing to review.
	 */
	public static function for_staged_copy( int $staged_copy_id ): string {
		if ( $staged_copy_id <= 0 ) {
			return '';
		}

		$ends = self::span_for( $staged_copy_id );

		if ( count( $ends ) < 2 ) {
			return '';
		}

		return self::for_revision( $staged_copy_id, $ends[1] );
	}

	/**
	 * A staged copy's own saves, oldest first.
	 *
	 * Autosaves are a private in-progress copy, not a staged save, and core's
	 * own revisions view does not offer them as a comparison end either.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return WP_Post[] The revisions, oldest first.
	 */
	private static function staged_revisions( int $staged_copy_id ): array {
		return array_values(
			array_filter(
				wp_get_post_revisions( $staged_copy_id, array( 'order' => 'ASC' ) ),
				static function ( $revision ) {
					return ! wp_is_post_autosave( $revision );
				}
			)
		);
	}
}
