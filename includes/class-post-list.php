<?php
/**
 * Staged state in the posts list, and the discard action.
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
 * Shows staged state where editors already look, and owns discarding.
 *
 * Core's posts list already has the two extension points this needs: the post
 * state label beside the title and the row actions beneath it. Using them means
 * staged work is visible without a screen of its own (R23), and an editor finds
 * it while doing something else rather than by going looking.
 *
 * Discard is the other place in the plugin that destroys staged work, so it is
 * gated the same way the merge is: capability, nonce, and an explicit
 * confirmation. Row actions are ordinary links, so without a nonce an
 * irreversible force-delete would be one crafted URL away.
 */
final class Post_List {

	/**
	 * Query arg carrying the live post being discarded.
	 */
	private const ARG_POST = 'swpub_post';

	/**
	 * Admin-post action name.
	 */
	private const ACTION = 'swpub_discard';

	/**
	 * Admin-post action that begins staging.
	 */
	private const STAGE_ACTION = 'swpub_stage';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'the_posts', array( __CLASS__, 'prime_staged_copy_caches' ), 10, 2 );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_discard' ) );
		add_action( 'admin_post_' . self::STAGE_ACTION, array( __CLASS__, 'handle_stage' ) );
		add_action( 'admin_notices', array( __CLASS__, 'discard_notice' ) );
	}

	/**
	 * Loads every staged copy on the screen in one query.
	 *
	 * Without this the label would load each staged copy separately and listing a
	 * hundred posts would issue a hundred extra queries. The pointers
	 * themselves are free: core primes post meta for the whole result set
	 * already, so reading them adds nothing.
	 *
	 * @param WP_Post[] $posts The listed posts.
	 * @param WP_Query  $query The query.
	 * @return WP_Post[] The posts, unchanged.
	 */
	public static function prime_staged_copy_caches( $posts, $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || empty( $posts ) ) {
			return $posts;
		}

		self::prime( $posts );

		return $posts;
	}

	/**
	 * Loads the staged copies belonging to a set of posts.
	 *
	 * Separate from the filter so the gate and the work can each be checked on
	 * their own: a priming step that silently never runs looks identical to one
	 * that works.
	 *
	 * @param WP_Post[] $posts The posts.
	 * @return void
	 */
	public static function prime( array $posts ): void {
		$staged_copy_ids = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$staged_copy_id = (int) get_post_meta( $post->ID, Staged_Copy_Repository::STAGED_COPY_META, true );

			if ( $staged_copy_id > 0 ) {
				$staged_copy_ids[] = $staged_copy_id;
			}
		}

		if ( ! empty( $staged_copy_ids ) ) {
			/*
			 * Meta is primed alongside the posts, not skipped: validating a
			 * pointer reads the staged copy's reverse pointer, so priming only the
			 * post rows would still cost one meta query per row. Terms are not
			 * needed -- nothing here reads them.
			 */
			_prime_post_caches( $staged_copy_ids, false, true );
		}
	}

	/**
	 * Adds the staged label beside the post title.
	 *
	 * @param string[] $states Existing post states.
	 * @param WP_Post  $post   The post.
	 * @return string[] The states.
	 */
	public static function post_state( $states, $post ) {
		if ( ! $post instanceof WP_Post || ! self::has_staged_copy( $post ) ) {
			return $states;
		}

		/*
		 * Core's shape, with the one difference that matters: every state core
		 * adds in `get_post_states()` describes the post itself -- a Draft is a
		 * draft, a Scheduled post is scheduled -- and this one does not. This
		 * post is published and serving readers. What is staged is attached to
		 * it. So the noun stays: a row reading "Staged" claims the post is the
		 * staged thing, which is the opposite of what is true.
		 */
		$states['swpub'] = _x( 'Staged changes', 'post status', 'save-without-publish' );

		if ( 'publish' !== $post->post_status ) {
			$states['swpub_stranded'] = _x( 'Cannot be published', 'post status', 'save-without-publish' );
		}

		return $states;
	}

	/**
	 * Adds the row action that opens the staged copy.
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param WP_Post               $post    The post.
	 * @return array<string, string> The actions.
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $actions;
		}

		if ( ! self::has_staged_copy( $post ) ) {
			/*
			 * Nothing staged yet: the row offers the way in, to exactly the
			 * people whose Update would publish. Anyone else stages by saving,
			 * so showing them "Stage changes" would offer a choice they do not
			 * have. The shared preconditions keep this link honest too --
			 * offering it for a type staging does not cover, or one without
			 * revisions, would offer an action the handler must refuse.
			 */
			if (
				is_enabled()
				&& current_user_can( 'edit_post', $post->ID )
				&& Capabilities::current_user_can_publish_directly( $post->ID )
				&& true === Staged_Copy_Repository::can_establish( $post )
			) {
				$actions[ self::STAGE_ACTION ] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( self::stage_url( $post->ID ) ),
					esc_html__( 'Stage changes', 'save-without-publish' )
				);
			}

			return $actions;
		}

		$staged_copy = Staged_Copy_Repository::find_for_live( $post->ID );

		if ( ! $staged_copy instanceof WP_Post || ! Capabilities::current_user_can_manage( $staged_copy->ID ) ) {
			return $actions;
		}

		/*
		 * One action, not three. Core's own row already carries Edit, Quick
		 * Edit, Trash, and View, and a row reading "... | Review staged changes
		 * | Edit staged changes | Discard staged changes" is a wall of text
		 * wrapping onto a second line. Reviewing and discarding are decisions
		 * about the staged copy, so they are offered where that copy is open.
		 */
		$actions['swpub_open'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( (string) get_edit_post_link( $staged_copy->ID ) ),
			esc_html__( 'Edit staged changes', 'save-without-publish' )
		);

		return $actions;
	}

	/**
	 * The nonced URL that begins staging from the list.
	 *
	 * @param int $live_id Live post ID.
	 * @return string The URL.
	 */
	public static function stage_url( int $live_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'       => self::STAGE_ACTION,
					self::ARG_POST => $live_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::STAGE_ACTION . '_' . $live_id
		);
	}

	/**
	 * Begins staging from the posts list.
	 *
	 * The same establishment the editor's control and the redirected save use,
	 * with nothing to write: the copy holds the published content and the
	 * editor opens on it. Where a copy already exists this lands on it, so a
	 * stale row action never creates a second copy (AE22).
	 *
	 * @return void
	 */
	public static function handle_stage(): void {
		$live_id = isset( $_REQUEST[ self::ARG_POST ] ) ? absint( $_REQUEST[ self::ARG_POST ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified on the next line, against this value.

		check_admin_referer( self::STAGE_ACTION . '_' . $live_id );

		$live = get_post( $live_id );

		if (
			! is_enabled()
			|| ! $live instanceof WP_Post
			|| ! current_user_can( 'edit_post', $live->ID )
		) {
			wp_die( esc_html__( 'There is no published post here to stage a change to.', 'save-without-publish' ) );
		}

		// The same preconditions the REST route enforces, so a stale or
		// crafted URL cannot begin staging the route would refuse.
		$establishable = Staged_Copy_Repository::can_establish( $live );

		if ( is_wp_error( $establishable ) ) {
			wp_die( esc_html( $establishable->get_error_message() ) );
		}

		$staged_copy = Staged_Copy_Repository::establish( $live );

		if ( is_wp_error( $staged_copy ) ) {
			wp_die( esc_html( $staged_copy->get_error_message() ) );
		}

		wp_safe_redirect( (string) get_edit_post_link( $staged_copy->ID, 'raw' ) );
		exit;
	}

	/**
	 * The nonced URL that begins a discard.
	 *
	 * @param int $live_id Live post ID.
	 * @return string The URL.
	 */
	public static function discard_url( int $live_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'       => self::ACTION,
					self::ARG_POST => $live_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $live_id
		);
	}

	/**
	 * Handles a discard request.
	 *
	 * Order is deliberate: capability and nonce are both checked before the
	 * confirmation is even offered, so an unauthorized request never learns
	 * whether staged changes exist.
	 *
	 * @return void
	 */
	public static function handle_discard(): void {
		$live_id = isset( $_REQUEST[ self::ARG_POST ] ) ? absint( $_REQUEST[ self::ARG_POST ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified on the next line, against this value.

		check_admin_referer( self::ACTION . '_' . $live_id );

		$staged_copy = Staged_Copy_Repository::find_for_live( $live_id );

		if ( ! $staged_copy instanceof WP_Post || ! Capabilities::current_user_can_manage( $staged_copy->ID ) ) {
			wp_die(
				esc_html__( 'You are not allowed to discard these staged changes.', 'save-without-publish' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( empty( $_REQUEST['swpub_confirmed'] ) ) {
			self::confirm( $live_id, $staged_copy );
		}

		// Read before the delete; afterwards there is nothing left to ask.
		$post_type = $staged_copy->post_type;

		Staged_Copy_Repository::unlink( $live_id, $staged_copy->ID );

		// Force-delete, never trash (R14). A trashed staged copy keeps a full copy of
		// unreviewed content discoverable in the admin after it was discarded.
		wp_delete_post( $staged_copy->ID, true );

		/*
		 * Back to the post type's list, the way core's row actions behave.
		 *
		 * Not `wp_get_referer()`: the confirmation step means the referer is
		 * this handler's own URL, so redirecting there re-enters the handler
		 * with the work already done and reports a failure for a discard that
		 * actually succeeded. Not the post's editor either, because the block
		 * editor does not surface classic admin notices and the discard would
		 * complete with no visible outcome.
		 */
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'       => $post_type,
					'swpub_discarded' => $live_id,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Shows the confirmation, then stops.
	 *
	 * Staged work cannot be recovered once discarded, and there is no trash to
	 * fall back on, so the confirmation says exactly that rather than asking a
	 * generic "are you sure".
	 *
	 * @param int     $live_id Live post ID.
	 * @param WP_Post $staged_copy  The staged copy.
	 * @return void
	 */
	private static function confirm( int $live_id, WP_Post $staged_copy ): void {
		$message = sprintf(
			'<h1>%s</h1><p>%s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p>',
			esc_html__( 'Discard staged changes?', 'save-without-publish' ),
			esc_html(
				sprintf(
					/* translators: %s: post title. */
					__( 'The staged changes to "%s" will be permanently deleted. They cannot be restored, and the published post keeps serving what it serves now.', 'save-without-publish' ),
					get_the_title( $live_id )
				)
			),
			esc_url( add_query_arg( 'swpub_confirmed', '1', self::discard_url( $live_id ) ) ),
			esc_html__( 'Discard permanently', 'save-without-publish' ),
			esc_url( (string) get_edit_post_link( $staged_copy->ID, 'raw' ) ),
			esc_html__( 'Keep editing', 'save-without-publish' )
		);

		wp_die(
			wp_kses_post( $message ),
			esc_html__( 'Discard staged changes?', 'save-without-publish' ),
			array( 'response' => 200 )
		);
	}

	/**
	 * Confirms a discard happened, on the post the changes were staged against.
	 *
	 * @return void
	 */
	public static function discard_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of an outcome; the action itself was nonced.
		if ( empty( $_GET['swpub_discarded'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'The staged changes were discarded. The published post is unchanged.', 'save-without-publish' )
		);
	}

	/**
	 * Whether a post has a valid staged copy.
	 *
	 * @param WP_Post $post The post.
	 * @return bool True when staged changes exist.
	 */
	private static function has_staged_copy( WP_Post $post ): bool {
		if ( Status::is_staged( $post ) ) {
			return false;
		}

		return Staged_Copy_Repository::find_for_live( $post->ID ) instanceof WP_Post;
	}
}
