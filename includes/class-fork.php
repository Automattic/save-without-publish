<?php
/**
 * Forking a published post on its first save.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Error;
use WP_Post;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirects a save of a published post onto its staged copy (R2, KTD1).
 *
 * Core's REST pre-insert filter is the only seam that runs before the write and
 * still lets the target change: the controller hands the prepared object
 * straight to the update, so rewriting its ID redirects the write. Everything
 * later receives a where-clause already bound to the live post.
 *
 * This is the backstop, not the block editor's protocol (R49). The editor
 * recognises a save that will stage and sends it to `Stage_Route` deliberately,
 * which answers 200 with the staged copy's ID. This path stays underneath that
 * one because the editor's protocol is JavaScript, and JavaScript that failed to
 * load must not be the difference between staging and publishing. When it
 * engages for a save the staging route could have taken, it says so:
 * `swpub_staged_via_backstop` is an alarm the end-to-end suite requires silent.
 */
final class Fork {

	/**
	 * Fields a save may carry onto a staged copy (KTD17, R29).
	 *
	 * Anything else the client sent is dropped. A request-supplied `post_name`
	 * would otherwise overwrite the deterministic slug KTD8 depends on, and a
	 * status would fight the staged status this filter forces.
	 */
	private const ALLOWED_FIELDS = array(
		'post_title',
		'post_content',
		'post_excerpt',
	);

	/**
	 * Registers hooks.
	 *
	 * Called at plugin load, not on `init`. The REST server can be built before
	 * `init` runs, and `rest_api_init` fires when it is built -- registering
	 * this listener any later means it never fires, `attach_filters()` never
	 * runs, and every save publishes straight to the live post with no error.
	 * A containment mechanism that silently does nothing is the worst available
	 * outcome, so the registration must not depend on ordering.
	 *
	 * @return void
	 */
	public static function init(): void {
		/*
		 * Attached from two hooks, both of which run after every post type has
		 * registered, because relying on either alone is fragile: `init` at the
		 * last priority always runs but is too early for a type registered by a
		 * REST-only code path, while `rest_api_init` fires only when the server
		 * is built and never fires again if something built it first. Missing
		 * the attachment is silent -- saves simply publish -- so it is worth
		 * two registrations. `add_filter` de-duplicates identical callbacks, so
		 * whichever runs second is a no-op.
		 */
		add_action( 'init', array( __CLASS__, 'attach_filters' ), PHP_INT_MAX );
		add_action( 'rest_api_init', array( __CLASS__, 'attach_filters' ) );
	}

	/**
	 * Attaches the per-post-type pre-insert filters (KTD23).
	 *
	 * On `rest_api_init` rather than the plugin's own early `init`: the hook is
	 * dynamic and must be attached per post type, and most plugins and themes
	 * register custom types at `init` priority 10 or later. Attaching early
	 * would silently miss them, and a missed type publishes straight to the
	 * live post.
	 *
	 * @return void
	 */
	public static function attach_filters(): void {
		foreach ( staged_post_types() as $post_type ) {
			add_filter( "rest_pre_insert_{$post_type}", array( __CLASS__, 'refuse_locked_live_post' ), 5, 2 );
			add_filter( "rest_pre_insert_{$post_type}", array( __CLASS__, 'maybe_fork' ), 10, 2 );
		}
	}

	/**
	 * Refuses a REST write to a published post that already has a staged copy (R55).
	 *
	 * Ahead of `maybe_fork()` and outside its cookie gate, and both of those are
	 * the point.
	 *
	 * Ahead of it, because the fork path used to answer this case by writing the
	 * request's content into the existing copy and reporting 409 `swpub_staged`.
	 * The request came from an editor looking at the published words, so that
	 * write reverted every staged change it did not carry. The refusal has to land
	 * before anything is written, not as a nicer message afterwards.
	 *
	 * Outside the cookie gate, because R55 holds for every transport and this is
	 * the only seam that can say so in words. `Write_Guard` refuses underneath on
	 * all of them, but only through core's `empty_content` abort, whose message
	 * names the wrong problem. An application password or OAuth client that used
	 * to get 200 and a `swpub` field now gets 409 and a code it can match.
	 *
	 * Autosaves are exempt for the same reason they are exempt everywhere else:
	 * they write a revision row, never the live post, so there is nothing here to
	 * protect and refusing one would break the editor's own recovery.
	 *
	 * @param object          $prepared_post The post object about to be written.
	 * @param WP_REST_Request $request       The request.
	 * @return object|WP_Error The prepared post, or the refusal.
	 */
	public static function refuse_locked_live_post( $prepared_post, $request ) {
		if ( ! is_object( $prepared_post ) || ! is_enabled() ) {
			return $prepared_post;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $prepared_post;
		}

		if ( empty( $prepared_post->ID ) ) {
			return $prepared_post;
		}

		$live = get_post( (int) $prepared_post->ID );

		if ( ! $live instanceof WP_Post || 'publish' !== $live->post_status ) {
			return $prepared_post;
		}

		/*
		 * A save that changes nothing stageable is not blocked (R42). A category
		 * edit, a slug change, or a sticky toggle on the published post touches
		 * nothing a staged copy holds, so there is nothing for the copy to lose
		 * and no reason to make an editor clear it first.
		 */
		if ( ! self::changes_a_staged_field( $prepared_post, $live ) ) {
			return $prepared_post;
		}

		$staged_copy = Staged_Copy_Repository::find_for_live( $live->ID );

		if ( ! $staged_copy instanceof WP_Post ) {
			return $prepared_post;
		}

		/*
		 * Fired here as well as in the guard, because the guard never sees this
		 * write: returning an error from `rest_pre_insert_*` aborts the controller
		 * before `wp_insert_post()`. A site counting refusals has to get the same
		 * count whichever layer caught one, or the REST transports -- the ones an
		 * integration actually uses -- are the ones missing from the log.
		 */
		Events::write_blocked( $staged_copy->ID, $live->ID, Write_Guard::channel() );

		return Staged_Copy_Repository::live_locked_error( $live, $staged_copy );
	}

	/**
	 * Redirects the write to a staged copy when staging applies.
	 *
	 * Returns the prepared post unchanged where staging does not apply, and a
	 * `WP_Error` where staging applies but could not be established (KTD12).
	 * The distinction is the whole fail-closed guarantee: returning the prepared
	 * post on a failure path would write the edit to the live post.
	 *
	 * @param object          $prepared_post The post object about to be written.
	 * @param WP_REST_Request $request       The request.
	 * @return object|WP_Error The post to write, or an error.
	 */
	public static function maybe_fork( $prepared_post, $request ) {
		if ( ! is_object( $prepared_post ) ) {
			return $prepared_post;
		}

		$live = self::live_post_for( $prepared_post, $request );

		if ( ! $live instanceof WP_Post ) {
			return $prepared_post;
		}

		$staged_copy = Staged_Copy_Repository::establish( $live );

		if ( is_wp_error( $staged_copy ) ) {
			return self::staging_failed();
		}

		if ( ! Staged_Copy_Repository::pointers_agree( $live->ID, $staged_copy->ID ) ) {
			return self::staging_failed();
		}

		if ( ! self::write_staged_content( $staged_copy->ID, $prepared_post ) ) {
			return self::staging_failed();
		}

		return self::staged_elsewhere( $live, $staged_copy );
	}

	/**
	 * Writes the staged fields onto the staged copy.
	 *
	 * The plugin owns this write rather than letting the controller do it,
	 * because returning an error from this filter aborts the controller before
	 * its own write. That is the point: the controller would otherwise go on to
	 * write terms, meta, and featured media against the staged copy, and R29 forbids
	 * staging any of those. Aborting it enforces the staged field set at the
	 * only place a crafted request cannot get past.
	 *
	 * @param int    $staged_copy_id     Staged copy post ID.
	 * @param object $prepared_post The prepared post carrying the editor's changes.
	 * @return bool True when the write succeeded.
	 */
	private static function write_staged_content( int $staged_copy_id, $prepared_post ): bool {
		$update = array( 'ID' => $staged_copy_id );

		foreach ( self::ALLOWED_FIELDS as $field ) {
			if ( isset( $prepared_post->$field ) ) {
				$update[ $field ] = $prepared_post->$field;
			}
		}

		if ( count( $update ) === 1 ) {
			// Nothing stageable changed; treat as a successful no-op.
			return true;
		}

		$result = wp_update_post( wp_slash( $update ), true );

		return ! is_wp_error( $result );
	}

	/**
	 * Tells the client its change was staged, and where.
	 *
	 * Returned as an error so the editor never receives a record whose ID does
	 * not match the one it asked for. Handing it a mismatched record leaves its
	 * store unable to reconcile the save: the post stays dirty even though the
	 * write succeeded, which arms the unsaved-changes guard and blocks the
	 * navigation. An error is a shape the editor already understands.
	 *
	 * The editor no longer arrives here on the ordinary path (R49): it recognises
	 * a save that will stage and asks `Stage_Route` for it, which answers 200. So
	 * a save that lands here is a save whose editor protocol did not run, and
	 * `swpub_staged_via_backstop` says so.
	 *
	 * This is the first save only. A save onto a post that already has a copy used
	 * to arrive here too -- including from someone who may publish directly, which
	 * KTD38 treated as a first-class case rather than a fallback -- and it was
	 * answered by writing the request into the copy. R55 refuses that write at
	 * `refuse_locked_live_post()` instead, one priority earlier, so nothing
	 * reaches here with a copy already in place and the alarm has one meaning
	 * again: the editor's protocol did not run.
	 *
	 * A token client never sees this 409 and never did. `live_post_for()` gates
	 * the whole seam on a valid `wp_rest` nonce, so only a cookie-authenticated
	 * editor save reaches it; an application password, OAuth, or XML-RPC write is
	 * contained by `Write_Guard` instead and answered 200 with the `swpub` field
	 * (R48). Worth saying plainly, because the natural assumption from an error
	 * code is that every client can meet it.
	 *
	 * @param WP_Post $live        The published post the save targeted.
	 * @param WP_Post $staged_copy The staged copy holding the staged content.
	 * @return WP_Error The staged-elsewhere signal.
	 */
	private static function staged_elsewhere( WP_Post $live, WP_Post $staged_copy ): WP_Error {
		Events::staged_via_backstop( $staged_copy->ID, $live->ID );

		return new WP_Error(
			'swpub_staged',
			__( 'Your edit was staged instead of updating the published post. Opening the staged copy.', 'save-without-publish' ),
			array(
				'status'    => 409,
				'staged_copy_id' => $staged_copy->ID,
				'edit_url'  => (string) get_edit_post_link( $staged_copy->ID, 'raw' ),
			)
		);
	}

	/**
	 * The published post this save targets, or null when staging does not apply.
	 *
	 * @param object          $prepared_post The prepared post.
	 * @param WP_REST_Request $request       The request.
	 * @return WP_Post|null The live post, or null.
	 */
	private static function live_post_for( $prepared_post, $request ): ?WP_Post {
		if ( ! is_enabled() ) {
			return null;
		}

		/*
		 * Autosave reaches this filter too, because the autosaves controller
		 * calls the same preparation code -- and then reassigns the ID, so a
		 * fork here would be clobbered and the staged copy stranded. That controller
		 * defines DOING_AUTOSAVE before preparing, which is the only signal
		 * core offers; there is no wp_doing_autosave() function.
		 */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return null;
		}

		if ( ! self::is_cookie_authenticated( $request ) ) {
			return null;
		}

		if ( empty( $prepared_post->ID ) ) {
			return null;
		}

		$live = get_post( (int) $prepared_post->ID );

		if ( ! $live instanceof WP_Post || 'publish' !== $live->post_status ) {
			return null;
		}

		if ( ! post_type_supports( $live->post_type, 'revisions' ) ) {
			return null;
		}

		/*
		 * Revisions can also be off site-wide, through WP_POST_REVISIONS or the
		 * `wp_revisions_to_keep` filter, which the post-type check above does
		 * not cover. Staging without them is not degraded, it is impossible:
		 * every staged save is a revision, the review surface is the revision
		 * screen, and the pre-merge snapshot is a revision. So this bails to
		 * ordinary publishing rather than staging into a copy nobody could
		 * review and no merge could roll back.
		 */
		if ( ! wp_revisions_enabled( $live ) ) {
			return null;
		}

		/*
		 * A save that changes nothing stageable is not a staging save (R42). The
		 * editor sends a term change, a slug edit, or a sticky toggle to this
		 * same endpoint, and none of them can live on a staged copy: forking one
		 * would create a copy holding no change, redirect the editor onto it, and
		 * drop the term change on the floor, because this filter aborts the
		 * controller before it writes terms.
		 *
		 * Compared unslashed on both sides: `rest_pre_insert_*` runs before the
		 * controller slashes the prepared post for `wp_insert_post()`, and
		 * `get_post()` returns the row as stored, so both sides are raw here.
		 */
		if ( ! self::changes_a_staged_field( $prepared_post, $live ) ) {
			return null;
		}

		/*
		 * The entry rule, last of all the bails because it is the only one that
		 * asks about the person rather than the request. Someone who may publish
		 * this post publishes it, exactly as core would; staging is theirs to
		 * ask for (Stage_Route), not something a save does to them.
		 *
		 * The staged-copy read is the second lock rather than the working one.
		 * `refuse_locked_live_post()` has already turned every save onto a post
		 * with a copy into a refusal, so a save reaching here has no copy and this
		 * read comes back empty. It stays because the alternative is a capability
		 * holder publishing over a staged copy if that refusal is ever moved,
		 * reordered, or filtered off its hook, and because the cost is nothing:
		 * it runs only when the capability says the save could publish, so the
		 * common path is still a capability check and a primary-key read rather
		 * than both.
		 */
		if (
			Capabilities::current_user_can_publish_directly( $live->ID )
			&& ! Staged_Copy_Repository::find_for_live( $live->ID ) instanceof WP_Post
		) {
			return null;
		}

		return $live;
	}

	/**
	 * Whether the save would change a field a staged copy can hold.
	 *
	 * A field the request did not send is not a change: the REST controller only
	 * populates what arrived. A field that arrived carrying the published post's
	 * own value is not a change either -- the editor resends unchanged attributes
	 * routinely, and treating those as staging work would fork on a save that
	 * touched nothing.
	 *
	 * @param object  $prepared_post The prepared post.
	 * @param WP_Post $live          The published post it targets.
	 * @return bool True when at least one staged field would change.
	 */
	private static function changes_a_staged_field( $prepared_post, WP_Post $live ): bool {
		foreach ( self::ALLOWED_FIELDS as $field ) {
			if ( ! isset( $prepared_post->$field ) ) {
				continue;
			}

			if ( (string) $prepared_post->$field !== (string) $live->$field ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the request is a cookie-authenticated editor save (KTD20, R21).
	 *
	 * A valid `wp_rest` nonce is the signal. Core exposes no block-editor marker
	 * at this seam -- the editor is only a REST client -- and application
	 * password, OAuth, and token clients do not send this nonce, so they pass
	 * through and publish as core would.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool True when the request is cookie-authenticated.
	 */
	private static function is_cookie_authenticated( $request ): bool {
		if ( ! $request instanceof WP_REST_Request || ! is_user_logged_in() ) {
			return false;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return false;
		}

		return false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * The fail-closed error (R19, KTD12).
	 *
	 * @return WP_Error An error carrying a message the editor can act on.
	 */
	private static function staging_failed(): WP_Error {
		return new WP_Error(
			'swpub_staging_failed',
			__( 'Your change was not saved. The published post is unchanged, and your edits are still in the editor -- copy them somewhere safe and try again.', 'save-without-publish' ),
			array( 'status' => 500 )
		);
	}
}
