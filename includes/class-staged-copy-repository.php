<?php
/**
 * Staged copy creation, lookup, and linking.
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
 * Owns the relationship between a live post and its staged copy.
 *
 * Lookups are primary-key meta reads, never queries (KTD3). Two reasons: a
 * meta_query would be a slow query under VIP standards, and where VIP Search
 * offloads WP_Query to Elasticsearch an internal status is not indexed at all,
 * so a query-based lookup would silently find nothing.
 *
 * No pointer is ever trusted (KTD14). A stale or copied pointer would otherwise
 * redirect a write or a delete onto an unrelated published post, so every
 * resolved target is revalidated and a pointer that fails is deleted.
 */
final class Staged_Copy_Repository {

	/**
	 * Meta on the live post naming its staged copy. Underscore-prefixed, so core
	 * treats it as protected and it is not exposed over REST.
	 */
	public const STAGED_COPY_META = '_swpub_staged_copy_id';

	/**
	 * Meta on the staged copy naming its live post.
	 */
	public const LIVE_META = '_swpub_live_id';

	/**
	 * Meta on the staged copy recording the live post's GMT modified time at fork.
	 *
	 * Written once and never updated (I8), so a drift override consumes only the
	 * drift the editor was actually shown. Lives here rather than in the drift
	 * unit because the fork is the only moment this value is true.
	 */
	public const FORK_BASELINE_META = '_swpub_forked_modified_gmt';

	/**
	 * Fields an editor may change on a staged copy (R29, KD9).
	 *
	 * Everything else is copied at fork for fidelity and locked, so nothing can
	 * be staged that core's revision screen cannot show and the pre-merge
	 * snapshot cannot roll back.
	 */
	public const STAGED_FIELDS = array( 'post_title', 'post_content', 'post_excerpt' );

	/**
	 * Builds the deterministic slug for a live post's staged copy (KTD8).
	 *
	 * Embeds the live post ID, so it is unique by construction and can never
	 * equal the live post's own slug. The slug matters because a staged copy sharing
	 * the live post's path poisons the platform's path-to-post cache, which only
	 * flushes on transitions through published status.
	 *
	 * @param int $live_id Live post ID.
	 * @return string Staged copy slug.
	 */
	public static function staged_copy_slug( int $live_id ): string {
		return 'swpub-staged-' . $live_id;
	}

	/**
	 * Finds the staged copy for a live post, or null.
	 *
	 * @param int $live_id Live post ID.
	 * @return WP_Post|null The staged copy, or null when there is none.
	 */
	public static function find_for_live( int $live_id ): ?WP_Post {
		if ( $live_id <= 0 ) {
			return null;
		}

		$staged_copy_id = (int) get_post_meta( $live_id, self::STAGED_COPY_META, true );

		if ( $staged_copy_id <= 0 ) {
			return null;
		}

		if ( ! self::pointers_agree( $live_id, $staged_copy_id ) ) {
			// A pointer that fails validation is deleted rather than left to
			// redirect a later write or delete.
			delete_post_meta( $live_id, self::STAGED_COPY_META );
			return null;
		}

		return get_post( $staged_copy_id );
	}

	/**
	 * Finds the live post a staged copy stages, or null.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return WP_Post|null The live post, or null when the link is broken.
	 */
	public static function find_live_for_staged_copy( int $staged_copy_id ): ?WP_Post {
		if ( $staged_copy_id <= 0 ) {
			return null;
		}

		$live_id = (int) get_post_meta( $staged_copy_id, self::LIVE_META, true );

		if ( $live_id <= 0 || ! self::pointers_agree( $live_id, $staged_copy_id ) ) {
			return null;
		}

		return get_post( $live_id );
	}

	/**
	 * Whether a live/staged copy pair is genuinely linked.
	 *
	 * The full validation set from KTD14: the target exists, carries the staged
	 * status, matches the live post's type, and its reverse pointer agrees.
	 *
	 * @param int $live_id   Live post ID.
	 * @param int $staged_copy_id Candidate staged copy ID.
	 * @return bool True when the pair is valid.
	 */
	public static function pointers_agree( int $live_id, int $staged_copy_id ): bool {
		if ( $live_id <= 0 || $staged_copy_id <= 0 || $live_id === $staged_copy_id ) {
			return false;
		}

		$live   = get_post( $live_id );
		$staged_copy = get_post( $staged_copy_id );

		if ( ! $live instanceof WP_Post || ! $staged_copy instanceof WP_Post ) {
			return false;
		}

		if ( Status::NAME !== $staged_copy->post_status ) {
			return false;
		}

		if ( $live->post_type !== $staged_copy->post_type ) {
			return false;
		}

		return $live_id === (int) get_post_meta( $staged_copy_id, self::LIVE_META, true );
	}

	/**
	 * Whether staging can be offered or begun for this post at all.
	 *
	 * The deliberate ways into staging -- the REST route and the posts-list
	 * action -- must agree on these preconditions, because each one they miss
	 * is a real hole: a type outside `staged_post_types()` never gets the
	 * field-lock seam, so its staged copy could be flipped to publish over
	 * REST; a type without revisions could stage a change nobody can review or
	 * roll back; and staging into a mid-merge copy loses the change without
	 * saying so. One predicate keeps the entry points from drifting apart.
	 *
	 * The redirected save does not come through here: the fork only attaches
	 * for covered types, and refusing its save would block an editor who never
	 * asked to stage.
	 *
	 * @param WP_Post $live The published post being considered.
	 * @return true|WP_Error True when staging can be established.
	 */
	public static function can_establish( WP_Post $live ) {
		if ( ! in_array( $live->post_type, staged_post_types(), true ) ) {
			return new WP_Error(
				'swpub_type_not_staged',
				__( 'This post type does not stage changes.', 'save-without-publish' ),
				array( 'status' => 400 )
			);
		}

		if ( 'publish' !== $live->post_status ) {
			return new WP_Error(
				'swpub_not_published',
				__( 'There is no published post here to stage a change to.', 'save-without-publish' ),
				array( 'status' => 404 )
			);
		}

		if ( ! post_type_supports( $live->post_type, 'revisions' ) || ! wp_revisions_enabled( $live ) ) {
			return new WP_Error(
				'swpub_no_revisions',
				__( 'This post type does not keep revisions, so a staged change could not be reviewed or rolled back.', 'save-without-publish' ),
				array( 'status' => 400 )
			);
		}

		// A merge owns the post until it finishes. Staging into a copy that is
		// being adopted and deleted would lose the change without saying so.
		if ( null !== Merge_Marker::get( $live->ID ) ) {
			return new WP_Error(
				'swpub_merge_in_progress',
				__( 'A publish is already in progress for this post. Try again once it finishes.', 'save-without-publish' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * The staged copy for a published post, ready to be written to.
	 *
	 * Both ways into staging come through here -- a save that was redirected and
	 * an editor who asked -- so a staged copy is the same object whichever way it
	 * was reached, and there is one creation path to keep correct rather than
	 * two that drift.
	 *
	 * A copy without its baseline revision is worse than no copy: it would look
	 * healthy, and its review screen would have nothing to compare the staged
	 * text against. So a failed baseline rolls the copy back rather than leaving
	 * it for the next save to find.
	 *
	 * @param WP_Post $live The published post being staged.
	 * @return WP_Post|WP_Error The staged copy, or an error when staging cannot be established.
	 */
	public static function establish( WP_Post $live ) {
		$existing = self::find_for_live( $live->ID );

		if ( $existing instanceof WP_Post ) {
			return $existing;
		}

		$staged_copy = self::create( $live );

		if ( is_wp_error( $staged_copy ) ) {
			return $staged_copy;
		}

		if ( ! Baseline_Revision::seed( $staged_copy->ID ) && ! self::has_baseline( $staged_copy->ID ) ) {
			/*
			 * The rollback is a destructive write, so it only fires when the
			 * copy provably has no baseline at all. A refused seed is not that
			 * proof by itself: create() settles a concurrent-create race by
			 * handing the loser the winner's copy, and core skips a revision
			 * that changes nothing -- so seeding an already-seeded copy
			 * returns false. Rolling back on that alone would delete the
			 * winner's live, healthy copy out from under its editor.
			 */
			self::unlink( $live->ID, $staged_copy->ID );
			wp_delete_post( $staged_copy->ID, true );

			return new WP_Error(
				'swpub_baseline_failed',
				__( 'The change was not saved. The published post is unchanged, and your edits are still here.', 'save-without-publish' ),
				array( 'status' => 500 )
			);
		}

		self::delete_pre_staging_autosave( $live->ID );

		return $staged_copy;
	}

	/**
	 * Whether a staged copy already carries its baseline revision.
	 *
	 * Autosaves do not count: the baseline is what the review screen compares
	 * against, and an autosave holds unreviewed staged text, not the published
	 * content the fork copied.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return bool True when a non-autosave revision exists.
	 */
	private static function has_baseline( int $staged_copy_id ): bool {
		foreach ( wp_get_post_revisions( $staged_copy_id ) as $revision ) {
			if ( ! wp_is_post_autosave( $revision ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Removes the staging user's autosave on the published post (KTD21).
	 *
	 * Core autosaves a published post while it is being edited, writing an
	 * autosave revision on the published post whose content is unreviewed staged
	 * text. The editor then offers a one-click restore for it on every load.
	 * Cleaning it when the change is published would be too late, because a
	 * staged copy may never be published.
	 *
	 * @param int $live_id Published post ID.
	 * @return void
	 */
	private static function delete_pre_staging_autosave( int $live_id ): void {
		$autosave = wp_get_post_autosave( $live_id, get_current_user_id() );

		if ( $autosave instanceof WP_Post ) {
			wp_delete_post_revision( $autosave->ID );
		}
	}

	/**
	 * Creates a staged copy for a published post, or returns the existing one.
	 *
	 * @param WP_Post $live The published post being staged.
	 * @return WP_Post|WP_Error The staged copy, or an error when staging cannot be established.
	 */
	public static function create( WP_Post $live ) {
		$existing = self::find_for_live( $live->ID );

		if ( $existing instanceof WP_Post ) {
			return $existing;
		}

		$fields = array(
			'post_type'      => $live->post_type,
			'post_status'    => Status::NAME,
			'post_title'     => $live->post_title,
			'post_content'   => $live->post_content,
			'post_excerpt'   => $live->post_excerpt,
			'post_author'    => $live->post_author,
			'post_parent'    => $live->post_parent,
			'menu_order'     => $live->menu_order,
			'post_name'      => self::staged_copy_slug( $live->ID ),

			// A staged copy is never a discussion surface of its own.
			'comment_status' => 'closed',
			'ping_status'    => 'closed',

			/*
			 * The reverse pointer and the fork baseline are written as part
			 * of the insert, so a staged row never exists without them. A
			 * fatal between two separate writes would otherwise leave an
			 * unreachable, uncleanable staged post holding a full copy of
			 * published content.
			 */
			'meta_input'     => array(
				self::LIVE_META          => $live->ID,
				self::FORK_BASELINE_META => $live->post_modified_gmt,
			),
		);

		/*
		 * Slashed, because `wp_insert_post()` expects slashed data and strips one
		 * level off whatever it is given, while a post read back through
		 * `get_post()` is unslashed. Handing the live row straight over loses a
		 * backslash from the title, content, and excerpt on every fork -- the same
		 * trap `includes/class-media.php` guards against for the same reason.
		 */
		$staged_copy_id = wp_insert_post( wp_slash( $fields ), true );

		if ( is_wp_error( $staged_copy_id ) ) {
			return $staged_copy_id;
		}

		self::copy_locked_fields( $live, (int) $staged_copy_id );

		update_post_meta( $live->ID, self::STAGED_COPY_META, (int) $staged_copy_id );

		/*
		 * Settle the race. Two editors saving at once both read "no staged copy" and
		 * both insert; whichever forward pointer landed last is the winner. The
		 * loser holds no editor content yet, so discarding it is clean.
		 */
		$winner_id = (int) get_post_meta( $live->ID, self::STAGED_COPY_META, true );

		if ( $winner_id !== (int) $staged_copy_id ) {
			wp_delete_post( (int) $staged_copy_id, true );

			$winner = self::find_for_live( $live->ID );

			return $winner instanceof WP_Post ? $winner : new WP_Error(
				'swpub_fork_race_unresolved',
				__( 'Could not establish a staged copy of this post.', 'save-without-publish' )
			);
		}

		$staged_copy = get_post( (int) $staged_copy_id );

		if ( ! $staged_copy instanceof WP_Post ) {
			return new WP_Error(
				'swpub_staged_copy_missing',
				__( 'Could not establish a staged copy of this post.', 'save-without-publish' )
			);
		}

		return $staged_copy;
	}

	/**
	 * Copies the fields an editor cannot stage, so the staged copy reads as a
	 * faithful copy of the live post (KTD18).
	 *
	 * @param WP_Post $live      The live post.
	 * @param int     $staged_copy_id The staged copy's ID.
	 * @return void
	 */
	private static function copy_locked_fields( WP_Post $live, int $staged_copy_id ): void {
		foreach ( get_object_taxonomies( $live->post_type ) as $taxonomy ) {
			$term_ids = wp_get_object_terms( $live->ID, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $term_ids ) ) {
				continue;
			}

			wp_set_object_terms( $staged_copy_id, $term_ids, $taxonomy );
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $live->ID );

		if ( $thumbnail_id > 0 ) {
			set_post_thumbnail( $staged_copy_id, $thumbnail_id );
		}
	}

	/**
	 * Removes both pointers.
	 *
	 * Cleared before the staged copy is deleted, never after: a pointer to a live
	 * staged copy is a recoverable state, while a pointer to a deleted post blocks
	 * editing under the fail-closed rule.
	 *
	 * @param int $live_id   Live post ID.
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return void
	 */
	/**
	 * The refusal every seam gives a write to a post that already has a copy (R55).
	 *
	 * ## The `swpub_live_locked` error code is public contract
	 *
	 * Answered with HTTP 409, a `staged_copy_id` naming the copy that is in the
	 * way, and an `edit_url` pointing at it. Like `swpub_field_locked`, that
	 * shape is API rather than an incident identifier:
	 *
	 * - 409 rather than 403, because nothing is wrong with the caller or its
	 *   permissions. The post is in a state that has to be resolved first, and the
	 *   same request succeeds once it is.
	 * - `edit_url` is the whole remedy for a person, so a client that renders a
	 *   refusal has somewhere to send them without knowing this plugin's routes.
	 * - `staged_copy_id` is the remedy for a machine, which can read the copy,
	 *   publish it, or discard it through the plugin's own routes.
	 *
	 * It will not be renamed. Integrations may match on it.
	 *
	 * Built here rather than at each seam because three of them answer it -- the
	 * REST post endpoint, the staging route, and the classic editor's screen --
	 * and a refusal that reads differently depending on which door you knocked on
	 * is three contracts wearing one name.
	 *
	 * @param WP_Post $live        The published post the write targeted.
	 * @param WP_Post $staged_copy The staged copy standing in its way.
	 * @return WP_Error The refusal.
	 */
	public static function live_locked_error( WP_Post $live, WP_Post $staged_copy ): WP_Error {
		return new WP_Error(
			'swpub_live_locked',
			__(
				'This post has staged changes waiting to be published. Publish or discard them before editing the published post.',
				'save-without-publish'
			),
			array(
				'status'         => 409,
				'live_id'        => $live->ID,
				'staged_copy_id' => $staged_copy->ID,
				'edit_url'       => (string) get_edit_post_link( $staged_copy->ID, 'raw' ),
			)
		);
	}

	public static function unlink( int $live_id, int $staged_copy_id ): void {
		if ( $live_id > 0 ) {
			delete_post_meta( $live_id, self::STAGED_COPY_META );
		}

		if ( $staged_copy_id > 0 ) {
			delete_post_meta( $staged_copy_id, self::LIVE_META );
		}
	}
}
