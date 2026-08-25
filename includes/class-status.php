<?php
/**
 * The staged post status.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the post status that staged copy posts live in.
 *
 * Containment (R18) is a property of this status, not of plugin code that could
 * be missed. Registering it as internal makes core exclude staged copies from
 * front-end queries, search, feeds, sitemaps, oEmbed, public REST reads, and
 * logged-out permalink access without the plugin filtering any of those
 * individually (KTD2).
 *
 * Deliberately no `pre_get_posts` filtering: duplicating core's own exclusion
 * would add a second mechanism that can drift out of step with the first.
 */
final class Status {

	/**
	 * The registered status name.
	 *
	 * Stored in `wp_posts.post_status`, which is a 20-character column.
	 */
	public const NAME = 'swpub_staged';

	/**
	 * Registers the status.
	 *
	 * Called from `init` at priority 0. Registering this early matters because
	 * asynchronous search indexing consults the registered status list, and a
	 * status registered after that lookup would leave staged copies indexable.
	 *
	 * @return void
	 */
	public static function init(): void {
		register_post_status(
			self::NAME,
			array(
				'label'                     => _x( 'Staged', 'post status', 'save-without-publish' ),

				/*
				 * Every flag is set explicitly rather than left to core's
				 * defaults. Core only infers `internal` when all four of
				 * public/internal/protected/private are null, so relying on
				 * that inference would make the containment guarantee depend
				 * on an absence rather than a statement.
				 */
				'public'                    => false,
				'internal'                  => true,

				/*
				 * Protected is what makes a staged copy previewable, and it is
				 * the narrowest flag that does it. Core's singular query gate
				 * (`WP_Query::get_posts()`) answers a non-public status in three
				 * branches: protected asks for the edit capability and hands back
				 * a preview, private asks for the read capability, and anything
				 * else throws the row away with no capability read at all. With
				 * this false a staged copy 404s at its own permalink for the
				 * editor who just wrote it, which is not containment, it is a
				 * dead link in core's own save notice.
				 *
				 * It widens nothing else. Core reads the flag in four places:
				 * that gate, the admin all-list clause (which also requires
				 * `show_in_admin_all_list`, false above), `get_sample_permalink()`
				 * for the editor's own permalink row, and the verbose page rules,
				 * which cannot match a slug this plugin namespaces. Front-end
				 * loops, search, feeds, sitemaps, oEmbed, and public REST reads
				 * all key off `is_post_status_viewable()`, which refuses internal
				 * and protected alike.
				 */
				'protected'                 => true,

				'private'                   => false,

				// Blocks logged-out permalink access and public REST reads.
				'publicly_queryable'        => false,

				// Keeps staged copies out of site search.
				'exclude_from_search'       => true,

				// Keeps staged copies out of the admin post list; U9 surfaces them
				// on the live post's row instead.
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,

				'date_floating'             => false,
			)
		);
	}

	/**
	 * Whether a post is a staged copy, judged by its status alone.
	 *
	 * Public so integrators can filter staged copies out of their own `save_post` and
	 * `transition_post_status` handlers in one line: a staged copy is a full post
	 * row, so third-party audit logs, webhooks, and indexers observe staged
	 * saves unless they opt out.
	 *
	 * @param int|\WP_Post|null $post Post ID or object. Defaults to global post.
	 * @return bool True when the post carries the staged status.
	 */
	public static function is_staged( $post = null ): bool {
		$post = get_post( $post );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return self::NAME === $post->post_status;
	}
}
