<?php
/**
 * Attachment reparenting across a merge.
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
 * Moves media uploaded while staging onto the live post.
 *
 * An editor who uploads an image into a staged copy gets an attachment parented to
 * the staged copy. The file is already in the shared library and the URL already
 * works, so nothing is duplicated and nothing breaks if this step never runs.
 * What reparenting fixes is ownership: without it the attachment stays tied to
 * a post that is about to be deleted, and the live post it actually belongs to
 * has no record of it.
 *
 * Nothing here deletes an attachment, in any path. A discard leaves uploads in
 * the library on purpose (R20): an editor who abandons a draft edit should not
 * lose the photo they uploaded for it.
 */
final class Media {

	/**
	 * Attachments fetched per query.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Hard stop on paging, so a bad state cannot loop forever.
	 *
	 * Far above any real staged post. A staged copy holding more than this many
	 * uploads is a bug, not a use case.
	 */
	private const MAX_PAGES = 50;

	/**
	 * Every attachment parented to a staged copy.
	 *
	 * Queried by `post_parent`, which is indexed, rather than by meta. Paged
	 * rather than unbounded because an unbounded query is a VIP performance
	 * failure and a memory risk on a post with a large gallery.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return int[] Attachment IDs.
	 */
	public static function attachments_for( int $staged_copy_id ): array {
		if ( $staged_copy_id <= 0 ) {
			return array();
		}

		$ids = array();

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = new WP_Query(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post_parent'            => $staged_copy_id,
					'fields'                 => 'ids',
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

			$ids = array_merge( $ids, array_map( 'intval', $query->posts ) );

			if ( count( $query->posts ) < self::PAGE_SIZE ) {
				break;
			}
		}

		return $ids;
	}

	/**
	 * Moves recorded attachments from the staged copy to the live post.
	 *
	 * Idempotent by attachment ID (KTD19): an attachment already on the live
	 * post is skipped, so a resumed merge finishing a half-done set cannot
	 * double-apply. Each ID is revalidated against the staged copy before it moves,
	 * because the recorded list comes from the merge marker and the marker is
	 * never trusted (KTD14) -- a stale or tampered entry would otherwise
	 * reparent an attachment belonging to an unrelated post.
	 *
	 * @param int   $live_id        Live post ID.
	 * @param int   $staged_copy_id      Staged copy post ID.
	 * @param int[] $attachment_ids Attachment IDs recorded before the move.
	 * @return int How many attachments this call moved.
	 */
	public static function reparent( int $live_id, int $staged_copy_id, array $attachment_ids ): int {
		if ( $live_id <= 0 || $staged_copy_id <= 0 ) {
			return 0;
		}

		$moved = 0;

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;

			if ( ! self::belongs_to_staged_copy( $attachment_id, $staged_copy_id ) ) {
				continue;
			}

			/*
			 * Slashed, even though only two numeric fields are passed.
			 * `wp_update_post()` merges the existing row -- unslashed -- before
			 * re-inserting it, and the insert strips one level of slashes from
			 * the whole array. Without this an attachment whose caption or
			 * description contains a backslash loses one on every merge that
			 * moves it.
			 */
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'          => $attachment_id,
						'post_parent' => $live_id,
					)
				),
				true
			);

			if ( ! is_wp_error( $result ) ) {
				++$moved;
			}
		}

		return $moved;
	}

	/**
	 * Whether an attachment is genuinely a child of this staged copy.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $staged_copy_id     Staged copy post ID.
	 * @return bool True when it may be moved.
	 */
	private static function belongs_to_staged_copy( int $attachment_id, int $staged_copy_id ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$attachment = get_post( $attachment_id );

		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
			return false;
		}

		return $staged_copy_id === (int) $attachment->post_parent;
	}
}
