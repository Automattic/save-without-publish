<?php
/**
 * Moving a staged copy's revision history onto the live post.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-parents the staged copy's revisions so staged history survives the merge.
 *
 * This is the step that makes the whole product work. Every staged save the
 * editor made is a revision under the staged copy, carrying its own author and
 * timestamp. Move them and the live post's history reads as a continuous
 * record of who changed what. Skip them and the review trail dies with the
 * staged copy, silently, on a merge that otherwise looks successful.
 *
 * Order is not negotiable: core's cleanup on delete finds revisions by
 * `post_parent` with raw SQL that no filter can intercept (`wp_delete_post()`),
 * so a revision still parented to the staged copy when it is deleted is gone.
 */
final class Revision_Adopter {

	/**
	 * Revisions fetched per query.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Hard stop on paging.
	 */
	private const MAX_PAGES = 100;

	/**
	 * Marks a revision as an autosave, in core's slug convention.
	 */
	private const AUTOSAVE_MARKER = '-autosave-v1';

	/**
	 * Moves the staged copy's regular revisions onto the live post.
	 *
	 * Autosaves are deliberately excluded (KTD5). Adopting one would give the
	 * live post a second autosave and make core offer the editor a restore
	 * nobody asked for, pointing at content that was never reviewed.
	 *
	 * @param int $live_id   Live post ID.
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return int[] IDs of the revisions this call adopted.
	 */
	public static function adopt( int $live_id, int $staged_copy_id ): array {
		if ( $live_id <= 0 || $staged_copy_id <= 0 ) {
			return array();
		}

		$adopted = array();

		foreach ( self::revisions_of( $staged_copy_id ) as $revision ) {
			if ( self::is_autosave( $revision ) ) {
				continue;
			}

			if ( self::reparent( $revision->ID, $live_id, $staged_copy_id ) ) {
				$adopted[] = $revision->ID;
			}
		}

		if ( ! empty( $adopted ) ) {
			clean_post_cache( $live_id );
			clean_post_cache( $staged_copy_id );
		}

		return $adopted;
	}

	/**
	 * The revisions a merge would adopt, without moving them.
	 *
	 * Recorded in the merge marker before adoption runs, the same way the
	 * attachment set is. Once a revision has been re-parented it is no longer
	 * the staged copy's, so a merge interrupted between moving them and recording
	 * what it moved could never work the set out again -- and the completion
	 * event would under-report the very history a listener cannot reconstruct
	 * once the staged copy is gone.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return int[] Revision IDs, oldest first.
	 */
	public static function adoptable( int $staged_copy_id ): array {
		$ids = array();

		foreach ( self::revisions_of( $staged_copy_id ) as $revision ) {
			if ( ! self::is_autosave( $revision ) ) {
				$ids[] = $revision->ID;
			}
		}

		return $ids;
	}

	/**
	 * Deletes every autosave on a post.
	 *
	 * Run on the live post after a merge because its autosaves describe content
	 * that no longer exists: an editor who autosaved before staging would
	 * otherwise be offered a restore back to pre-staged text.
	 *
	 * @param int $post_id Post ID.
	 * @return int How many autosaves were removed.
	 */
	public static function drop_autosaves( int $post_id ): int {
		$dropped = 0;

		foreach ( self::revisions_of( $post_id ) as $revision ) {
			if ( ! self::is_autosave( $revision ) ) {
				continue;
			}

			if ( wp_delete_post_revision( $revision->ID ) ) {
				++$dropped;
			}
		}

		return $dropped;
	}

	/**
	 * Every revision of a post, paged.
	 *
	 * @param int $post_id Parent post ID.
	 * @return WP_Post[] Revisions, oldest first.
	 */
	private static function revisions_of( int $post_id ): array {
		$revisions = array();

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = new WP_Query(
				array(
					'post_type'              => 'revision',
					'post_status'            => 'inherit',
					'post_parent'            => $post_id,
					'posts_per_page'         => self::PAGE_SIZE,
					'offset'                 => $page * self::PAGE_SIZE,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'ignore_sticky_posts'    => true,
				)
			);

			if ( empty( $query->posts ) ) {
				break;
			}

			$revisions = array_merge( $revisions, $query->posts );

			if ( count( $query->posts ) < self::PAGE_SIZE ) {
				break;
			}
		}

		return $revisions;
	}

	/**
	 * Whether a revision is an autosave.
	 *
	 * @param WP_Post $revision The revision.
	 * @return bool True when it is an autosave.
	 */
	private static function is_autosave( WP_Post $revision ): bool {
		return str_contains( $revision->post_name, self::AUTOSAVE_MARKER );
	}

	/**
	 * Points one revision at the live post.
	 *
	 * Parent and slug move together, because core reads a revision's owner from
	 * both. Rewriting only the parent leaves a revision whose slug still names
	 * the staged copy, which core's autosave lookup matches on by name.
	 *
	 * Written with a direct query rather than `wp_update_post()` on purpose.
	 * That function re-runs the whole insert pipeline, which passes the stored
	 * content back through `content_save_pre` and therefore through kses for
	 * any user without `unfiltered_html` -- and on VIP no one has it. A history
	 * rewrite would then quietly strip markup out of revisions it was only
	 * supposed to re-file. This changes two columns and touches no content.
	 *
	 * Because it is a direct query it never traverses `Write_Guard`, and that
	 * must stay true. The guard contains writes to a published post; this moves
	 * revision rows, which are neither published nor the post. Routing it through
	 * `wp_update_post()` to "be safe" would put a history rewrite in front of a
	 * containment predicate that has no opinion about revisions and would have to
	 * grow one.
	 *
	 * @param int $revision_id Revision ID.
	 * @param int $live_id     Live post ID.
	 * @param int $staged_copy_id   Staged copy post ID, used as a guard in the WHERE.
	 * @return bool True when the row moved.
	 */
	private static function reparent( int $revision_id, int $live_id, int $staged_copy_id ): bool {
		global $wpdb;

		/*
		 * The staged copy ID in the WHERE clause is the guard: a revision that is no
		 * longer the staged copy's is not this merge's to move, so zero affected
		 * rows means nothing was adopted here and the caller is told so.
		 *
		 * Idempotence comes from the query rather than from this return value
		 * (KTD19): a resumed merge only ever sees revisions still parented to
		 * the staged copy, so the ones it already moved are never offered again.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core exposes no API to re-file a revision; the cache is cleaned explicitly below.
		$updated = $wpdb->update(
			$wpdb->posts,
			array(
				'post_parent' => $live_id,
				'post_name'   => $live_id . '-revision-v1',
			),
			array(
				'ID'          => $revision_id,
				'post_parent' => $staged_copy_id,
				'post_type'   => 'revision',
			)
		);

		if ( ! $updated ) {
			return false;
		}

		clean_post_cache( $revision_id );

		return true;
	}
}
