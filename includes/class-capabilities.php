<?php
/**
 * Capability mapping for staged copy posts.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Post;
use WP_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves capability checks on a staged copy against its live post (KTD13, R28).
 *
 * A staged copy must never be easier to reach than the post it stages. Left to core,
 * it would be: on an internal status the published-post branch does not apply,
 * so editing a staged copy maps to the weaker unpublished capabilities, and
 * `read_post` has an author branch that grants access before any edit check is
 * reached. Because a staged copy inherits the live post's author at fork, that branch
 * would let a former author read staged content they can no longer edit.
 *
 * The mapped set is enumerated rather than inferred. A generic "map the edit
 * capability" reading would leave the read and delete paths on core's defaults,
 * which is exactly where the author branch lives.
 */
final class Capabilities {

	/**
	 * The capability to write to a published post without staging the change.
	 *
	 * A meta capability, resolved per post, never a capability written onto a
	 * role. Nothing is stored, so nothing is stranded when the plugin is
	 * deactivated and no upgrade routine is needed to change the rule.
	 */
	public const PUBLISH_DIRECTLY = 'swpub_publish_directly';

	/**
	 * The primitive capability for the `post` type, and the prefix for the rest.
	 *
	 * Held by no role. A site that wants someone to write to published posts
	 * without staging grants it deliberately, to the role or the user it means:
	 *
	 *     get_role( 'editor' )->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );
	 *
	 * It is the plugin's own capability rather than the post type's
	 * `publish_posts` because those answer different questions. `publish_posts`
	 * asks whether this user may put content in front of readers at all, which
	 * every editor and author holds and which core's own screens depend on.
	 * This asks whether their ordinary save may go straight to readers with no
	 * staged copy in between, and a site that adopts this plugin has said the
	 * answer is usually no.
	 *
	 * Every post type gets its own, the way core gives each one its own publish
	 * capability, so a grant on posts is not silently a grant on pages. See
	 * `primitive_for()` for the names the other types resolve to.
	 */
	public const PUBLISH_DIRECTLY_POSTS = 'swpub_publish_directly_posts';

	/**
	 * Meta capabilities that resolve against the live post.
	 *
	 * Page variants are included because core maps them separately for
	 * hierarchical post types.
	 */
	private const MAPPED = array(
		'read_post',
		'read_page',
		'edit_post',
		'edit_page',
		'delete_post',
		'delete_page',
	);

	/**
	 * Registers the mapping.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_staged_copy_caps' ), 10, 4 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_publish_directly' ), 10, 4 );
	}

	/**
	 * Maps a staged copy's meta capabilities onto its live post.
	 *
	 * @param string[] $caps    Primitive capabilities required of the user.
	 * @param string   $cap     The meta capability being checked.
	 * @param int      $user_id The user being checked.
	 * @param array    $args    Arguments; `$args[0]` is the post ID for these caps.
	 * @return string[] The primitive capabilities to require.
	 */
	public static function map_staged_copy_caps( $caps, $cap, $user_id, $args ): array {
		$caps = (array) $caps;

		if ( ! in_array( $cap, self::MAPPED, true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$staged_copy_id = (int) $args[0];

		if ( ! Status::is_staged( $staged_copy_id ) ) {
			return $caps;
		}

		$live = Staged_Copy_Repository::find_live_for_staged_copy( $staged_copy_id );

		if ( $live instanceof WP_Post ) {
			/*
			 * Recursion is bounded: the live post is not a staged copy, so the
			 * re-entrant call bails at the check above.
			 */
			return map_meta_cap( 'edit_post', $user_id, $live->ID );
		}

		/*
		 * A stranded staged copy has no live post to resolve against, but denying
		 * outright would make it undiscardable and therefore permanent -- the
		 * opposite of R17, which requires it be surfaced for an editor to clear.
		 * Fall back to the post type's others-authority instead, which is
		 * stricter than core's default for an internal status and still lets
		 * someone clean up.
		 */
		return self::others_capability_for( $staged_copy_id );
	}

	/**
	 * The post type's "edit others" primitive capability, or deny.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return string[] Primitive capabilities.
	 */
	private static function others_capability_for( int $staged_copy_id ): array {
		$post_type = get_post_type_object( (string) get_post_type( $staged_copy_id ) );

		if ( null === $post_type ) {
			return array( 'do_not_allow' );
		}

		return array( $post_type->cap->edit_others_posts );
	}

	/**
	 * Maps the direct-publish capability onto the plugin's own primitive.
	 *
	 * Whoever holds `swpub_publish_directly_posts` may write to a published post
	 * without staging. That is the whole rule, and no role holds it: on a site
	 * that installs this plugin, a save to a published post stages until someone
	 * says otherwise in so many words.
	 *
	 * The filter answers in three states rather than two, because a site needs to
	 * be able to say yes as well as no. There is one seat of authority for "may
	 * this user publish this post directly", and every surface that asks -- the
	 * editor's context, the posts list, the fork seam, and the write guard --
	 * reads this same answer, so the UI and the write path cannot disagree.
	 *
	 * @param string[] $caps    Primitive capabilities required of the user.
	 * @param string   $cap     The meta capability being checked.
	 * @param int      $user_id The user being checked.
	 * @param array    $args    Arguments; `$args[0]` is the post ID for this capability.
	 * @return string[] The primitive capabilities to require.
	 */
	public static function map_publish_directly( $caps, $cap, $user_id, $args ): array {
		if ( self::PUBLISH_DIRECTLY !== $cap ) {
			return (array) $caps;
		}

		$post_id   = empty( $args[0] ) ? 0 : (int) $args[0];
		$post_type = $post_id > 0 ? get_post_type_object( (string) get_post_type( $post_id ) ) : null;

		/**
		 * Filters whether a user may write to a published post without staging.
		 *
		 * Three answers, not two:
		 *
		 * - `null`, the default, falls through to the `swpub_publish_directly_posts`
		 *   capability, which no role holds. Whoever has been granted it publishes
		 *   without staging; everyone else stages.
		 * - `false` forces staging, whatever the user's role says, including a
		 *   role that has been granted the capability:
		 *
		 *       add_filter( 'swpub_can_publish_directly', '__return_false' );
		 *
		 * - `true` grants the bypass per post, to a user who does not hold the
		 *   capability. This is how an integration's writes keep publishing:
		 *   answer for its own account, on the posts it owns, rather than
		 *   granting the capability to a role every human shares.
		 *
		 *       add_filter(
		 *           'swpub_can_publish_directly',
		 *           function ( $decision, $post_id, $user_id ) {
		 *               $sync = get_user_by( 'login', 'acme-sync' );
		 *
		 *               if ( ! $sync || $sync->ID !== $user_id ) {
		 *                   return $decision;
		 *               }
		 *
		 *               // Scoped to the application password it authenticates
		 *               // with, so a stolen browser session is not the same key.
		 *               return null !== rest_get_authenticated_app_password();
		 *           },
		 *           10,
		 *           3
		 *       );
		 *
		 * Decide from server-derived facts only: the user, the post, the site's
		 * own configuration. Never from anything the request controls -- a
		 * User-Agent string, a custom header, a query argument -- because any
		 * caller can send those, which would turn this grant into a bypass anyone
		 * can claim. The signature carries no channel for the same reason: this
		 * filter also answers on read surfaces that render the editor's chrome,
		 * and a per-transport answer would make the UI say one thing and the
		 * write path do another.
		 *
		 * A grant is entry, never exemption. Once a post has a staged copy, every
		 * write to it is contained regardless of what this filter returns.
		 *
		 * Migration note: this fell through to the post type's `publish_posts`
		 * capability until the plugin grew a capability of its own, so a site
		 * that relied on editors and authors publishing without staging grants
		 * `swpub_publish_directly_posts` to say so.
		 *
		 * @param bool|null $decision Whether the bypass is granted. Null falls through
		 *                            to `swpub_publish_directly_posts`.
		 * @param int       $post_id  The published post being written to.
		 * @param int       $user_id  The user being checked.
		 */
		$decision = apply_filters( 'swpub_can_publish_directly', null, $post_id, $user_id );
		$decision = null === $decision ? null : (bool) $decision;

		// A post that does not exist, or a type that is not registered, denies.
		// Guessing at a capability for a post nobody can name would decide the
		// entry rule on a post the plugin cannot check, and a grant is not the
		// place to start guessing.
		if ( false === $decision || null === $post_type ) {
			return array( 'do_not_allow' );
		}

		/*
		 * The grant maps to `exist` rather than returning an allow outright,
		 * because `map_meta_cap` has no way to say yes -- it answers in primitive
		 * capabilities. Core sets `exist` on every user in `WP_User::has_cap()`
		 * after the `user_has_cap` filter has run, so it cannot be filtered away
		 * or removed from a role.
		 */
		if ( true === $decision ) {
			return array( 'exist' );
		}

		return array( self::primitive_for( $post_type ) );
	}

	/**
	 * The direct-publish capability for a post type.
	 *
	 * Built from the type's own publish capability so the plural core computed
	 * is the plural granted: `publish_posts` gives `swpub_publish_directly_posts`,
	 * `publish_pages` gives `swpub_publish_directly_pages`, and a type declaring
	 * `capability_type => 'brief'` gives `swpub_publish_directly_briefs`.
	 *
	 * A type whose publish capability was remapped to something that is not a
	 * `publish_` name keeps that name whole, because a capability nobody can
	 * predict is better than two types quietly sharing one.
	 *
	 * @param WP_Post_Type $post_type The post type being written to.
	 * @return string The primitive capability required.
	 */
	private static function primitive_for( WP_Post_Type $post_type ): string {
		$publish = (string) $post_type->cap->publish_posts;
		$plural  = str_starts_with( $publish, 'publish_' )
			? substr( $publish, strlen( 'publish_' ) )
			: $publish;

		return 'swpub_publish_directly_' . $plural;
	}

	/**
	 * Whether the current user may write to a published post without staging.
	 *
	 * The one predicate the entry rule reads. Everything else about staging
	 * authorization resolves against the live post through the mapping above.
	 *
	 * @param int $live_id Published post ID.
	 * @return bool True when the save may publish rather than stage.
	 */
	public static function current_user_can_publish_directly( int $live_id ): bool {
		if ( $live_id <= 0 ) {
			return false;
		}

		return current_user_can( self::PUBLISH_DIRECTLY, $live_id );
	}

	/**
	 * Whether the current user may act on a staged copy.
	 *
	 * The single authority every plugin route's permission callback resolves to,
	 * so merging, overriding drift, and discarding cannot drift apart from
	 * reading and editing.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return bool True when the current user may act on the staged copy.
	 */
	public static function current_user_can_manage( int $staged_copy_id ): bool {
		if ( $staged_copy_id <= 0 || ! Status::is_staged( $staged_copy_id ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $staged_copy_id );
	}
}
