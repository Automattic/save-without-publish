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
			 * "Compare as text" is a route around the in-editor view diffing
			 * blocks, which cannot show a title or excerpt change at all
			 * (VIPPROD-753, F2). `changedFields` decides whether it is worth
			 * offering; `compareTextUrl` is where it goes either way.
			 */
			$context['compareTextUrl'] = Review_Link::compare_as_text_url( $post->ID );
			$context['changedFields']  = Review_Link::changed_fields( $post->ID );

			/*
			 * For the editor's own reactive link (`routes.js`'s
			 * `useReviewUrl()`), so a save that lands mid-session rebuilds the
			 * right kind of URL rather than always the in-editor one. Both
			 * bases are shippable with no revision IDs at all -- each takes
			 * its revision as a query argument -- so they cost nothing to send
			 * even where `compareUrl` is empty, which is exactly when they
			 * are needed: the first save onto a copy that arrived with only
			 * its baseline. The editor base is the copy's own clean edit link
			 * rather than the address bar, which on arrival still carries the
			 * `swpub_forked` flag and would carry it into the review.
			 */
			$context['reviewSurface']       = Review_Link::surface();
			$context['classicRevisionBase'] = admin_url( 'revision.php' );
			$context['editorRevisionBase']  = (string) get_edit_post_link( $post->ID, 'raw' );

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
			 * Drift state is resolved server-side and shipped with the page.
			 * `driftKind` (VIPPROD-752) is what the load-time notice reads to
			 * say what actually changed; `historyUrl` is withheld for
			 * `'other'` the same way the merge's own refusal withholds it --
			 * a change to anything but title, content, or excerpt diffs to
			 * nothing on the review screen.
			 */
			$context['drifted']      = Drift::has_drifted( $post->ID );
			$context['driftKind']    = Drift::kind( $post->ID, $live );
			$context['forkedAt']     = Drift::baseline( $post->ID );
			$context['liveModified'] = $live instanceof \WP_Post ? $live->post_modified_gmt : '';
			$context['historyUrl']   = ( $live instanceof \WP_Post && 'other' !== $context['driftKind'] )
				? Drift::history_url( $live->ID )
				: '';

			/*
			 * Scheduling, read for the copy being edited (VIPPROD-1248). Both
			 * a schedule and a refusal are shipped in the shape the row and
			 * the notices need directly, rather than the raw meta: the row
			 * needs an unambiguous instant for the date picker (`scheduledFor`,
			 * ISO 8601 with an explicit `Z` -- `self::when()`'s GMT input is a
			 * bare MySQL datetime, which a JS date library parses as the
			 * browser's own local time without one) and a formatted sentence
			 * for the notices (`scheduledForLabel`), which is resolved
			 * server-side because only `wp_date()` knows the site's timezone,
			 * its date format, and the locale's month names together.
			 */
			$schedule = Scheduled_Publish::scheduled( $post->ID );
			$refusal  = Scheduled_Publish::refusal( $post->ID );

			$context['scheduledFor']      = $schedule ? self::to_iso( $schedule['at_gmt'] ) : '';
			$context['scheduledForLabel'] = $schedule ? self::when( $schedule['at_gmt'] ) : '';
			$context['scheduledBy']       = $schedule ? self::display_name( $schedule['by'] ) : '';

			/*
			 * Named to match the pair above rather than the raw `at_gmt`/
			 * `scheduled_for` the accessors return: `scheduledForLabel` is
			 * always the formatted sentence-ready string here, never the raw
			 * instant, so a reader cannot mistake this for the same shape as
			 * the top-level `scheduledFor`.
			 */
			$context['scheduleRefused'] = $refusal ? array(
				'reason'            => $refusal['reason'],
				'scheduledForLabel' => self::when( $refusal['scheduled_for'] ),
			) : null;

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
			$context['compareTextUrl'] = Review_Link::compare_as_text_url( $staged_copy->ID );
			$context['changedFields']  = Review_Link::changed_fields( $staged_copy->ID );
			// Nothing on this screen rebuilds the review link, so this is
			// informational: the same answer the staged copy's screen has, so
			// anything reading the context sees one answer on either copy.
			$context['reviewSurface'] = Review_Link::surface();

			/*
			 * The published post's own notice names when the copy will
			 * publish itself (VIPPROD-1248), but nothing else about the
			 * schedule: who scheduled it and why a run was refused are the
			 * staged copy's own business, read on that screen instead.
			 */
			$schedule = Scheduled_Publish::scheduled( $staged_copy->ID );

			$context['scheduledFor']      = $schedule ? self::to_iso( $schedule['at_gmt'] ) : '';
			$context['scheduledForLabel'] = $schedule ? self::when( $schedule['at_gmt'] ) : '';
		}

		return $context;
	}

	/**
	 * A GMT datetime, formatted for the site to read (VIPPROD-1248).
	 *
	 * `wp_date()` rather than `date_i18n()`: it is the one of the two that
	 * takes a timezone argument and resolves the site's, which is what core's
	 * own Publish row also resolves to. Formatted here rather than in the
	 * editor bundle because only this call knows the site's timezone, its
	 * configured date and time formats, and the locale's month names all at
	 * once; rebuilding that in JS from a bare GMT string would print a date
	 * that reads differently from every other date already on the screen.
	 *
	 * @param string $gmt MySQL datetime, GMT.
	 * @return string The formatted date and time, or '' when there is nothing to format.
	 */
	private static function when( string $gmt ): string {
		if ( '' === $gmt ) {
			return '';
		}

		$timestamp = strtotime( $gmt . ' GMT' );

		if ( false === $timestamp ) {
			return '';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * A GMT datetime, as an ISO 8601 string a JS date library parses
	 * correctly (VIPPROD-1248).
	 *
	 * The stored value is a bare MySQL datetime with no timezone marker.
	 * `moment()`, which the block editor's own date picker is built on,
	 * parses a string in that shape as the browser's local time rather than
	 * UTC -- silently, with no error -- so a value that is really GMT would
	 * be read as being in whatever zone the visitor's machine happens to be
	 * in. The explicit `Z` is what removes that ambiguity for any date
	 * library, not just this plugin's own reading of it.
	 *
	 * @param string $gmt MySQL datetime, GMT.
	 * @return string The same instant, as `Y-m-d\TH:i:sZ`, or '' when there
	 *                is nothing to convert.
	 */
	private static function to_iso( string $gmt ): string {
		if ( '' === $gmt ) {
			return '';
		}

		return str_replace( ' ', 'T', $gmt ) . 'Z';
	}

	/**
	 * A user's display name, or an empty string when there is none to show.
	 *
	 * @param int $user_id User ID.
	 * @return string The name.
	 */
	private static function display_name( int $user_id ): string {
		$user = get_userdata( $user_id );

		return $user ? $user->display_name : '';
	}
}
