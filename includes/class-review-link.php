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
	 * Registers the filters this surface needs.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'rest_revision_query', array( __CLASS__, 'span_staged_revisions' ), 10, 2 );
		add_filter( 'wp_prepare_revision_for_js', array( __CLASS__, 'withhold_restore' ), 10, 3 );
	}

	/**
	 * Which of core's two revision screens reviews a staged change.
	 *
	 * The in-editor revisions view (`editor-revisions-header`) does not exist
	 * before WordPress 7.0; below that the plugin still promises 6.8 (the
	 * `Requires at least` header), so review falls back to core's classic
	 * compare screen, `wp-admin/revision.php`, which has been there since
	 * revisions themselves. Both are core's, unmodified (R23) -- this
	 * decides only which one a link opens.
	 *
	 * Filterable rather than hardcoded to the version check, for the one
	 * case the check cannot see: a site running trunk between releases,
	 * where the in-editor view is present but not yet what a site wants
	 * pointed to. A value that is neither answer is treated as `editor`,
	 * the newer and by far the more common case, rather than silently
	 * falling back to a screen nobody asked for.
	 *
	 * @return string `'editor'` or `'classic'`.
	 */
	public static function surface(): string {
		$default = version_compare( get_bloginfo( 'version' ), '7.0', '>=' ) ? 'editor' : 'classic';

		/**
		 * Filters which revision screen reviews a staged change.
		 *
		 * @param string $surface `'editor'` or `'classic'`.
		 */
		$surface = apply_filters( 'swpub_review_surface', $default );

		return 'classic' === $surface ? 'classic' : 'editor';
	}

	/**
	 * Core's classic revisions screen, comparing two revisions directly.
	 *
	 * The one core surface, on any version, that diffs the title and the
	 * excerpt as well as the content -- the in-editor view diffs blocks, and
	 * neither field is one. Reached with `from` and `to`, which the in-editor
	 * view has no equivalent for and this screen has always understood.
	 *
	 * @param int $from The older revision.
	 * @param int $to   The newer revision.
	 * @return string The URL, or an empty string when either ID is missing.
	 */
	public static function classic_compare_url( int $from, int $to ): string {
		if ( $from <= 0 || $to <= 0 ) {
			return '';
		}

		return add_query_arg(
			array(
				'from' => $from,
				'to'   => $to,
			),
			admin_url( 'revision.php' )
		);
	}

	/**
	 * The classic screen, opened on one revision and left to name its own `from`.
	 *
	 * Core defaults `from` to whichever revision came right before the one
	 * named, reading the post's real, unfiltered revision list -- the same
	 * "revision before this one" reading `for_revision()` gets from the
	 * REST collection's filtering on the in-editor surface. With only one
	 * revision to its name core still resolves this, comparing against the
	 * post's current row, so this needs no span of its own the way
	 * `classic_compare_url()` does.
	 *
	 * @param int $revision_id Revision ID.
	 * @return string The URL, or an empty string when there is no revision to open.
	 */
	public static function classic_revision_url( int $revision_id ): string {
		if ( $revision_id <= 0 ) {
			return '';
		}

		return add_query_arg( array( 'revision' => $revision_id ), admin_url( 'revision.php' ) );
	}

	/**
	 * Core's classic screen refuses to restore a staged copy's revisions.
	 *
	 * Restoring has nothing left to mean on a staged copy: its history is two
	 * points, the content as published and the change as staged, so restoring
	 * the newer is a no-op and restoring the older is a discard that leaves
	 * the emptied copy standing -- which "Discard staged changes" already
	 * does properly, by deleting it. `publish-changes.js` takes the same
	 * control over on the in-editor view; this is its twin for the classic
	 * screen, which core drives from `restoreUrl` in the data this filters
	 * rather than from any markup, so withholding it costs no selector.
	 *
	 * Published posts are untouched: their own Restore still means exactly
	 * what core says it means.
	 *
	 * @param array|mixed $data     The data core sends its revisions UI.
	 * @param WP_Post     $revision The revision being prepared.
	 * @param WP_Post     $post     The revision's parent post.
	 * @return array|mixed The data, with `restoreUrl` withheld on a staged copy.
	 */
	public static function withhold_restore( $data, $revision, $post ) {
		if ( is_array( $data ) && $post instanceof WP_Post && Status::is_staged( $post ) ) {
			$data['restoreUrl'] = false;
		}

		return $data;
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
	 * The review for a staged copy: its newest staged save against the
	 * fork-time baseline.
	 *
	 * On the in-editor surface there is one reading, because the timeline is
	 * hidden on a staged copy's revisions view
	 * (`src/editor/revisions-screen.scss`): this revision against the one
	 * before it, which `span_staged_revisions()` above has already narrowed
	 * to mean "as published". On the classic surface -- WordPress below 7.0,
	 * where that view does not exist (`surface()`) -- the same two ends are
	 * named directly, `from` and `to`, since the classic screen has no
	 * "newest" to default to.
	 *
	 * Either way, a copy holding only its baseline has nothing on the other
	 * side of the comparison -- one revision is not a change -- so this
	 * answers with nothing to review rather than a link to a screen that
	 * would say "Only one revision found."
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

		if ( 'classic' === self::surface() ) {
			return self::classic_compare_url( $ends[0], $ends[1] );
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
