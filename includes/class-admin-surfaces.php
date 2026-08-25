<?php
/**
 * The admin surfaces that are not the block editor, saying what the data layer did.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Post;
use WP_Screen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quick Edit refuses, and a classic save lands somewhere it can be understood (R44).
 *
 * The write guard made staging true on every transport. That is the guarantee,
 * and it is silent: `edit_post()` returns the post ID whatever `wp_update_post()`
 * returned, so a classic save whose change was contained still reports success
 * and redisplays the published post's own text. Two human surfaces reach the
 * guard without a line of the plugin's JavaScript on the page, and each has to
 * say for itself what happened.
 *
 * Quick Edit says it by refusing (KD15). Its row is rebuilt from the published
 * post, so a staged Quick Edit redisplays the old title with no path to the copy
 * -- an edit that appears to have vanished. There is no honest way to render
 * staging in that row, so the write is refused with an instruction instead, and
 * the refusal is keyed on "this write would divert", never on the capability: a
 * publisher editing a post that already has a staged copy hits the same
 * vanishing row.
 *
 * The classic editor says it by moving: the save lands on the staged copy with
 * an arrival notice carrying the routes the block editor gets as panel fills.
 * Without that the editor arrives on a private duplicate with no notice, no
 * review link, no discard control, and nothing saying where publishing happens.
 *
 * Registered on `init` rather than at file load, unlike `Write_Guard` and
 * `Fork`. Nothing here can be reached before `init`: core registers its own
 * inline-save handler inside `admin-ajax.php`, which runs after the whole load
 * sequence, and `post.php` and `admin-header.php` run later still. There is no
 * write to lose by attaching late.
 */
final class Admin_Surfaces {

	/**
	 * Query arg marking an arrival on a staged copy.
	 *
	 * The one the block editor's context already reads as `justForked`, reused
	 * rather than reinvented so both editors call the same arrival the same
	 * thing. The name is older than the vocabulary; the parameter is what other
	 * links already carry.
	 */
	private const ARRIVAL_ARG = 'swpub_forked';

	/**
	 * Marks a classic redirect as following a refused save (R55).
	 *
	 * Its own argument rather than a value on `ARRIVAL_ARG`, because the two say
	 * opposite things: one means the change was staged and the editor was moved to
	 * it, the other means nothing was written and the editor is still here.
	 */
	private const BLOCKED_ARG = 'swpub_blocked';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Priority 0. The tag is hyphenated -- `wp_ajax_inline_save` is core's
		// callback function, registered on `wp_ajax_inline-save` at priority 1,
		// which is exactly what priority 0 gets in front of.
		add_action( 'wp_ajax_inline-save', array( __CLASS__, 'intercept_quick_edit' ), 0 );

		add_filter( 'redirect_post_location', array( __CLASS__, 'report_refused_save' ), 9, 2 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'redirect_to_staged_copy' ), 10, 2 );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_notices', array( __CLASS__, 'arrival_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'refused_save_notice' ) );
	}

	/**
	 * Refuses a Quick Edit whose change would not land on the published post.
	 *
	 * Verify first, refuse second (KTD33). The nonce and the post's own edit
	 * capability are checked before anything is asked about staging, and both
	 * failures return silently rather than dying: a refusal is an answer, and a
	 * forged `post_ID` would otherwise let any logged-in user probe whether some
	 * other post has staged changes, and fill the audit trail with events for
	 * posts they cannot touch. `Stage_Route::can_stage()` orders itself the same
	 * way for the same reason.
	 *
	 * @return void
	 */
	public static function intercept_quick_edit(): void {
		if ( ! is_enabled() ) {
			return;
		}

		if ( ! check_ajax_referer( 'inlineeditnonce', '_inline_edit', false ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified immediately above; nothing below runs before it.
		$live_id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;

		if ( $live_id <= 0 || ! current_user_can( 'edit_post', $live_id ) ) {
			return;
		}

		$live = get_post( $live_id );

		if ( ! $live instanceof WP_Post ) {
			return;
		}

		/*
		 * The write core is about to make, as `wp_insert_post()` would hand it to
		 * the guard. Only the title and the status come from the form:
		 * `wp_ajax_inline_save()` reads content and excerpt back off the stored
		 * row before it calls `edit_post()`, so the title is the one staged field
		 * Quick Edit can change at all.
		 *
		 * Values stay slashed, because that is the state `$postarr` is in at the
		 * guard's hook and the guard unslashes before it compares.
		 */
		$status = (string) $live->post_status;

		if ( isset( $_POST['keep_private'] ) && 'private' === $_POST['keep_private'] ) {
			$status = 'private';
		} elseif ( isset( $_POST['_status'] ) ) {
			$status = sanitize_key( wp_unslash( $_POST['_status'] ) );
		}

		$title = isset( $_POST['post_title'] ) && is_string( $_POST['post_title'] )
			? $_POST['post_title'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below by `sanitize_post( ..., 'db' )`, which is the same shaping core applies, and comparing anything else would compare bytes core never sees.
			: wp_slash( (string) $live->post_title );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$postarr = sanitize_post(
			array(
				'ID'           => $live_id,
				'post_type'    => $live->post_type,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => wp_slash( (string) $live->post_content ),
				'post_excerpt' => wp_slash( (string) $live->post_excerpt ),
			),
			'db'
		);

		if ( ! Write_Guard::would_divert( (array) $postarr ) ) {
			return;
		}

		self::refuse_quick_edit( $live_id );
	}

	/**
	 * Refuses the write, and does not come back.
	 *
	 * `wp_die()` is the mechanism rather than a chosen one: core's
	 * `inline-edit-post.js` renders any non-row response as the inline error
	 * notice above the row, which is core's own refusal pattern for this screen.
	 * It is confined to this ajax request -- dying anywhere else in the write path
	 * would kill CLI loops, cron runs, and importers mid-batch.
	 *
	 * Called by the guard as well as the handler above, so a refusal reads the
	 * same whether it was caught one hook early or one hook late.
	 *
	 * @param int $live_id Published post ID the write targeted.
	 * @return void
	 */
	public static function refuse_quick_edit( int $live_id ): void {
		$staged_copy = Staged_Copy_Repository::find_for_live( $live_id );

		if ( $staged_copy instanceof WP_Post ) {
			Events::write_blocked( $staged_copy->ID, $live_id, 'quickedit' );

			wp_die( wp_kses_post( self::locked_message( $staged_copy->ID ) ) );
		}

		Events::stage_refused( $live_id, 'quickedit' );

		wp_die( wp_kses_post( self::refusal_message( $live_id ) ) );
	}

	/**
	 * What the refusal says when the write would have staged.
	 *
	 * It leads with the non-save because the editor's typed value is already
	 * gone from the screen, and that is the fact they need first. Then why, then
	 * where to go: "the editor" is the link, so the sentence and the way out are
	 * one thing rather than a sentence followed by a link repeating it.
	 *
	 * @param int $live_id Published post ID the write targeted.
	 * @return string The message, with the link already escaped.
	 */
	private static function refusal_message( int $live_id ): string {
		return sprintf(
			/* translators: %s: the words "the editor", linked to the post's edit screen. */
			__( 'This change was not saved. Changes to a published post are staged, so open the post in %s to stage it.', 'save-without-publish' ),
			self::linked( __( 'the editor', 'save-without-publish' ), (string) get_edit_post_link( $live_id, 'raw' ) )
		);
	}

	/**
	 * What the refusal says when a staged copy already holds this post's title (R55).
	 *
	 * A different sentence from the one above, because a different thing is true
	 * and a different thing has to be done about it. There, the remedy is to open
	 * the published post and stage the change. Here that is exactly the wrong
	 * advice: the published post will refuse the same title, and the place the
	 * change belongs is the copy.
	 *
	 * The title is the only staged field Quick Edit can change -- core reads
	 * content and excerpt back off the stored row before saving -- so the
	 * sentence names it rather than reciting all three.
	 *
	 * @param int $staged_copy_id The staged copy standing in the way.
	 * @return string The message, with the link already escaped.
	 */
	private static function locked_message( int $staged_copy_id ): string {
		return sprintf(
			/* translators: %s: the words "the staged changes", linked to the staged copy's edit screen. */
			__( 'This change was not saved. This post has staged changes waiting to be published, and the title is edited in %s until they are published or discarded.', 'save-without-publish' ),
			self::linked( __( 'the staged changes', 'save-without-publish' ), (string) get_edit_post_link( $staged_copy_id, 'raw' ) )
		);
	}

	/**
	 * Stops the classic editor reporting a refused save as a successful one (R55).
	 *
	 * `edit_post()` ignores what `wp_update_post()` returned -- it reads the value
	 * only to decide whether to retry with stripped text -- so a save the guard
	 * refused still redirects with `message=1`, which renders as "Post updated."
	 * over a post that was not updated. Core offers no hook between the failure
	 * and the redirect, so this is the first place the lie can be caught.
	 *
	 * The editor is left on the published post rather than moved to the staged
	 * copy. Nothing was staged, so moving them would be the same false claim in a
	 * different shape, and their typed text is gone from the screen either way.
	 *
	 * Priority 9, so it runs before the staged-copy redirect and the two cannot
	 * both claim the same save. They are mutually exclusive by construction --
	 * one request cannot have both diverted and been refused for the same post --
	 * and the ordering makes that explicit rather than incidental.
	 *
	 * @param string $location Where core would send the editor.
	 * @param int    $post_id  The post that was saved.
	 * @return string Where the editor is sent.
	 */
	public static function report_refused_save( $location, $post_id ): string {
		$location = (string) $location;

		if ( Write_Guard::write_blocked_for( (int) $post_id ) <= 0 ) {
			return $location;
		}

		return add_query_arg( self::BLOCKED_ARG, '1', remove_query_arg( 'message', $location ) );
	}

	/**
	 * Says that the save was refused, and where the change belongs instead.
	 *
	 * The classic editor's twin of the block editor's `swpub-live-locked` notice,
	 * and it carries the same two facts in the same order: nothing was saved, and
	 * the staged copy is where those fields are edited.
	 *
	 * @return void
	 */
	public static function refused_save_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display hint on a redirect this plugin issued; it renders a notice and nothing else.
		if ( ! isset( $_GET[ self::BLOCKED_ARG ] ) || ! isset( $GLOBALS['post'] ) ) {
			return;
		}

		$live = get_post();

		if ( ! $live instanceof WP_Post ) {
			return;
		}

		$staged_copy = Staged_Copy_Repository::find_for_live( $live->ID );

		if ( ! $staged_copy instanceof WP_Post ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%1$s %2$s</p></div>',
			esc_html__( 'This change was not saved.', 'save-without-publish' ),
			wp_kses_post(
				sprintf(
					/* translators: %s: the words "the staged changes", linked to the staged copy's edit screen. */
					__( 'This post has staged changes waiting to be published, and its title, content, and excerpt are edited in %s until they are published or discarded.', 'save-without-publish' ),
					self::linked( __( 'the staged changes', 'save-without-publish' ), (string) get_edit_post_link( $staged_copy->ID, 'raw' ) )
				)
			)
		);
	}

	/**
	 * Marks the admin page when the post being edited is a staged copy.
	 *
	 * The stylesheet needs to know, and a stylesheet cannot ask. This is the
	 * answer the server already has: the post's own status, which no URL can
	 * claim and no editor state can drift from. A query argument would be a
	 * second answer to the same question, and the one that could be wrong.
	 *
	 * @param string $classes Space-separated class list.
	 * @return string The class list, with ours when this is a staged copy.
	 */
	public static function body_class( $classes ): string {
		$classes = (string) $classes;
		$post    = get_post();

		if ( ! $post instanceof WP_Post || ! Status::is_staged( $post ) ) {
			return $classes;
		}

		return trim( $classes . ' swpub-staged' );
	}

	/**
	 * Sends a classic save that staged to the staged copy it staged into.
	 *
	 * Only for a write this request actually diverted. A staged copy existing is
	 * not the same question: a classic save that changed only a category on a
	 * post that already has one staged nothing, and belongs back on the published
	 * post where core would have put it.
	 *
	 * @param string $location Where core would send the editor.
	 * @param int    $post_id  The post that was saved.
	 * @return string Where the editor is sent.
	 */
	public static function redirect_to_staged_copy( $location, $post_id ): string {
		$location       = (string) $location;
		$staged_copy_id = Write_Guard::staged_copy_engaged( (int) $post_id );

		if ( $staged_copy_id <= 0 ) {
			return $location;
		}

		$edit_url = (string) get_edit_post_link( $staged_copy_id, 'raw' );

		if ( '' === $edit_url ) {
			return $location;
		}

		return add_query_arg( self::ARRIVAL_ARG, '1', $edit_url );
	}

	/**
	 * Says what a staged copy is, on the one editor that has nowhere else to read it.
	 *
	 * Every surface the block editor gets is a plugin fill in a registered slot,
	 * and none of them exist here. So this carries the same sentence and the same
	 * destinations: the published post is the link inside the sentence, the way
	 * the block editor's notice writes it, and reviewing and discarding follow as
	 * the two routes its Summary rows offer.
	 *
	 * It also says where publishing happens, because that is the one thing the
	 * classic editor cannot do and nothing else on this screen would admit it.
	 *
	 * Rendered only where the block editor is not: there it would be a second
	 * notice saying what the fills already say, and core hides it anyway.
	 *
	 * @return void
	 */
	public static function arrival_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof WP_Screen || 'post' !== $screen->base || $screen->is_block_editor() ) {
			return;
		}

		$staged_copy = get_post();

		if ( ! $staged_copy instanceof WP_Post || ! Status::is_staged( $staged_copy ) ) {
			return;
		}

		$live = Staged_Copy_Repository::find_live_for_staged_copy( $staged_copy->ID );

		printf(
			'<div class="notice notice-info"><p>%s</p><p>%s</p>%s</div>',
			wp_kses_post( self::status_sentence( $live ) ),
			esc_html__( 'Publish these changes from the block editor.', 'save-without-publish' ),
			wp_kses_post( self::routes( $staged_copy, $live ) )
		);
	}

	/**
	 * The sentence, matching the block editor's word for word.
	 *
	 * Two sentences rather than one, on the same split `src/editor/staged-notices.js`
	 * makes: someone who has just been moved here needs to be told why they were
	 * moved, and someone who came back to keep working already knows.
	 *
	 * @param WP_Post|null $live The published post, when there still is one.
	 * @return string The sentence, escaped.
	 */
	private static function status_sentence( ?WP_Post $live ): string {
		if ( ! $live instanceof WP_Post || 'publish' !== $live->post_status ) {
			return esc_html__(
				'The post these changes were staged against is no longer published. They are kept here, and can be published once it is published again.',
				'save-without-publish'
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display hint, the same one the block editor's context reads.
		$just_arrived = isset( $_GET[ self::ARRIVAL_ARG ] );

		/*
		 * The front end, which is what these sentences name: what readers are
		 * seeing while the change waits. The edit screen is a different errand,
		 * and the Summary panel's own row is where it is offered.
		 */
		$permalink = (string) get_permalink( $live->ID );

		if ( $just_arrived ) {
			return sprintf(
				/* translators: %s: the words "the published post", linked to that post on the front end. */
				__( 'Your edit was staged instead of updating %s. It keeps serving what it serves now until you publish these changes.', 'save-without-publish' ),
				self::linked( __( 'the published post', 'save-without-publish' ), $permalink )
			);
		}

		return sprintf(
			/* translators: %s: the words "the published post as it is now", linked to that post on the front end. */
			__( 'You are staging edits to a published post. Until you publish them, readers still see %s.', 'save-without-publish' ),
			self::linked( __( 'the published post as it is now', 'save-without-publish' ), $permalink )
		);
	}

	/**
	 * A phrase inside a sentence, linked when there is somewhere to link it to.
	 *
	 * A new tab, because these sentences sit above an editor holding staged work
	 * and reading the published post must not cost whatever is unsaved in this
	 * one. An empty URL leaves the phrase as words: the sentence is what carries
	 * the meaning, and the link is how it is read.
	 *
	 * @param string $label The phrase.
	 * @param string $url   Where it goes, or an empty string.
	 * @return string The phrase, escaped, linked when it can be.
	 */
	private static function linked( string $label, string $url ): string {
		if ( '' === $url ) {
			return esc_html( $label );
		}

		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Reviewing and discarding, as links.
	 *
	 * The published post is deliberately not repeated here: the sentence above
	 * already links it, and offering the same destination twice reads as two
	 * destinations. Discarding is offered only to someone who may actually do it,
	 * and the URL is the same nonced one the posts list and the block editor use,
	 * so nothing about how a discard is authorized changes with where it starts.
	 *
	 * @param WP_Post      $staged_copy The staged copy being edited.
	 * @param WP_Post|null $live        The published post, when there still is one.
	 * @return string The markup, or an empty string when neither route applies.
	 */
	private static function routes( WP_Post $staged_copy, ?WP_Post $live ): string {
		$links = array();

		$compare_url = Review_Link::for_staged_copy( $staged_copy->ID );

		if ( '' !== $compare_url ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $compare_url ),
				esc_html__( 'Review staged changes', 'save-without-publish' )
			);
		}

		if ( $live instanceof WP_Post && Capabilities::current_user_can_manage( $staged_copy->ID ) ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( Post_List::discard_url( $live->ID ) ),
				esc_html__( 'Discard staged changes', 'save-without-publish' )
			);
		}

		if ( empty( $links ) ) {
			return '';
		}

		return '<p>' . implode( ' | ', $links ) . '</p>';
	}
}
