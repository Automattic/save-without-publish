<?php
/**
 * Block editor asset registration.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the editor bundle.
 *
 * Only on the block editor, and only for a post type that can stage, so the
 * script is absent everywhere it would do nothing.
 */
final class Editor_Assets {

	/**
	 * Script handle.
	 */
	private const HANDLE = 'swpub-editor';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueues the built bundle and its generated dependencies.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! is_enabled() ) {
			return;
		}

		$asset_file = plugin_dir_path( PLUGIN_FILE ) . 'build/index.asset.php';

		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/index.js', PLUGIN_FILE ),
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'save-without-publish' );

		/*
		 * The Summary panel rows are rendered with core's own row classes, so
		 * this sheet only cancels what core's extensibility slot adds on top of
		 * them. It depends on the editor's stylesheet because it overrides
		 * declarations from it and has to be read after them.
		 */
		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'build/index.css', PLUGIN_FILE ),
			array( 'wp-edit-post' ),
			$asset['version'] ?? VERSION
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.swpubEditor = ' . wp_json_encode( self::context() ) . ';',
			'before'
		);
	}

	/**
	 * The staging context for the post being edited.
	 *
	 * Answers the two questions the editor cannot work out for itself: whether
	 * this post is a staged copy, and whether the post it is looking at already has
	 * one. The second is what lets a second editor learn before saving rather
	 * than by saving.
	 *
	 * @return array<string, mixed> Context for the editor bundle.
	 */
	private static function context(): array {
		$post = get_post();

		$context = array(
			'canPublish' => false,
			'isStaged'   => false,
			'justForked' => isset( $_GET['swpub_forked'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display hint.
			'justPublished' => isset( $_GET['swpub_published'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display hint.
			'liveId'     => 0,
			'stagedCopyId'   => 0,
		);

		if ( ! $post instanceof \WP_Post ) {
			return $context;
		}

		if ( Status::is_staged( $post ) ) {
			$live = Staged_Copy_Repository::find_live_for_staged_copy( $post->ID );

			$context['isStaged']   = true;
			$context['stagedCopyId']   = $post->ID;
			$context['liveId']     = $live instanceof \WP_Post ? $live->ID : 0;
			$context['liveEdit']   = $live instanceof \WP_Post ? (string) get_edit_post_link( $live->ID, 'raw' ) : '';
			$context['liveView']   = $live instanceof \WP_Post ? (string) get_permalink( $live->ID ) : '';
			$stranding = Transitions::stranding( $post->ID );

			$context['stranded']      = ! $live instanceof \WP_Post || 'publish' !== $live->post_status;

			/*
			 * Reported for the live post this copy stages, not for the copy.
			 * Nothing on a staged copy branches on it today, because every save
			 * here joins this copy whoever makes it, and it is shipped so a
			 * surface added later reads the same answer as the write path.
			 */
			$context['canPublishDirectly'] = $live instanceof \WP_Post
				&& Capabilities::current_user_can_publish_directly( $live->ID );
			$context['strandReason']  = $stranding ? (string) $stranding['reason'] : '';
			$context['strandedTitle'] = $stranding ? (string) $stranding['live_title'] : '';
			$context['compareUrl'] = Review_Link::for_staged_copy( $post->ID );
			$context['baselineRevisionId'] = Review_Link::baseline_revision_id( $post->ID );

			/*
			 * For the editor's own reactive link (`routes.js`'s
			 * `useReviewUrl()`), so a save that lands mid-session rebuilds the
			 * right kind of URL rather than always the in-editor one. The
			 * base is shippable with no revision IDs at all -- `revision.php`
			 * takes `from` and `to` as query arguments, not path segments --
			 * so it costs nothing to send even where `compareUrl` is empty.
			 */
			$context['reviewSurface']       = Review_Link::surface();
			$context['classicRevisionBase'] = admin_url( 'revision.php' );

			/*
			 * Discarding is offered here rather than on the posts list, where it
			 * made the row wrap. It is a decision about this copy, so it belongs
			 * where this copy is open. The URL is nonced and leads to the same
			 * confirmation screen the row action used, so nothing about how a
			 * discard is authorized changes with where it starts.
			 */
			/*
			 * Decoded on the way out. `wp_nonce_url()` HTML-escapes its
			 * separators for use in markup, but this URL is handed to React,
			 * which sets `href` as a DOM property and never decodes entities. A
			 * link carrying a literal `&#038;` puts the nonce inside the
			 * previous parameter's value, and every discard is refused as an
			 * expired link.
			 */
			$context['discardUrl'] = $live instanceof \WP_Post && Capabilities::current_user_can_manage( $post->ID )
				? html_entity_decode( Post_List::discard_url( $live->ID ) )
				: '';

			/*
			 * Drift state is resolved server-side and shipped with the page. The
			 * timestamp is the exact state the editor is being shown, and a merge
			 * confirming against it must send this value back unchanged (KTD15).
			 */
			$context['drifted']      = Drift::has_drifted( $post->ID );
			$context['forkedAt']     = Drift::baseline( $post->ID );
			$context['liveModified'] = $live instanceof \WP_Post ? $live->post_modified_gmt : '';
			$context['historyUrl']   = $live instanceof \WP_Post ? Drift::history_url( $live->ID ) : '';

			return $context;
		}

		/*
		 * Whether this save would publish or stage, answered server-side. The
		 * editor renders the way in only where staging is something to ask for;
		 * where it happens anyway, offering it would be offering a choice
		 * nobody has. A type outside `staged_post_types()` gets no context at
		 * all: the fork and field lock never attach for it, so offering the
		 * stage control there would create an uncontained copy.
		 */
		if ( 'publish' === $post->post_status && in_array( $post->post_type, staged_post_types(), true ) ) {
			$context['liveId']             = $post->ID;
			$context['canPublishDirectly'] = Capabilities::current_user_can_publish_directly( $post->ID );

			/*
			 * Core's own publish capability, which is a different question from
			 * the one above and has been since the bypass became a capability of
			 * this plugin's own. Core reads this one to decide the status it
			 * puts on a save, so the editor needs it to tell a status core
			 * injected from a status the editor chose (`fork-navigation.js`).
			 */
			$context['canPublish'] = current_user_can( 'publish_post', $post->ID );

			/*
			 * Where this post reads, and what core calls going there. Both are
			 * for the notice that arrives after a merge, which says what
			 * publishing did and offers the same way of looking at it that core
			 * offers after any save. The label comes from the post type so it
			 * says "View Post" on a post and "View Page" on a page, in whatever
			 * language the rest of the screen is in.
			 */
			$post_type = get_post_type_object( $post->post_type );

			$context['liveView']  = (string) get_permalink( $post->ID );
			$context['viewLabel'] = $post_type && isset( $post_type->labels->view_item )
				? (string) $post_type->labels->view_item
				: '';
		}

		$staged_copy = Staged_Copy_Repository::find_for_live( $post->ID );

		if ( $staged_copy instanceof \WP_Post ) {
			$author = get_userdata( (int) $staged_copy->post_author );

			$context['liveId']     = $post->ID;
			$context['stagedCopyId']   = $staged_copy->ID;
			$context['stagedCopyEdit'] = (string) get_edit_post_link( $staged_copy->ID, 'raw' );
			$context['stagedBy']   = $author ? $author->display_name : '';
			$context['stagedAt']   = (string) get_post_modified_time( 'c', true, $staged_copy );
			$context['compareUrl'] = Review_Link::for_staged_copy( $staged_copy->ID );
		}

		return $context;
	}
}
