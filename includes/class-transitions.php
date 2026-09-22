<?php
/**
 * What happens to a staged copy when its live post changes underneath it.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a staged copy alive through anything that happens to its live post.
 *
 * The rule is one-directional and has no exceptions: nothing here deletes a
 * staged copy, and nothing here promotes one. Both are tempting. When an article is
 * unpublished, "clean up the orphaned staged copy" destroys work somebody may
 * be mid-way through. When an article is deleted, "promote the staged copy so the
 * content survives" publishes text that was never reviewed, at a URL nobody
 * chose, which is the exact accident this plugin exists to prevent.
 *
 * So the staged copy is kept and marked, and a human decides. It is discarded
 * deliberately through the posts list, or the article comes back and staging
 * resumes where it left off.
 */
final class Transitions {

	/**
	 * Meta on the staged copy recording why it cannot currently be published.
	 *
	 * Absent means the pair is healthy. Underscore-prefixed, so it is protected
	 * and never exposed over REST.
	 */
	public const STRANDED_META = '_swpub_stranded';

	/**
	 * The live post left published status but still exists.
	 */
	public const REASON_UNPUBLISHED = 'unpublished';

	/**
	 * The live post was permanently deleted.
	 */
	public const REASON_DELETED = 'deleted';

	/**
	 * Staged copies trashed during this request, awaiting removal.
	 *
	 * @var int[]
	 */
	private static array $discarding = array();

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'on_status_change' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'discard_trashed_staged_copy' ) );
	}

	/**
	 * Marks or clears stranding as the live post moves in and out of published.
	 *
	 * @param string  $new_status The new status.
	 * @param string  $old_status The old status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public static function on_status_change( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof WP_Post || $new_status === $old_status ) {
			return;
		}

		// The post that just left the staged status is the staged copy itself,
		// so this is the other direction entirely and answers before the rest.
		if ( Status::NAME === (string) $old_status ) {
			self::restore_staged_status( (string) $new_status, $post );

			return;
		}

		if ( Status::is_staged( $post ) ) {
			return;
		}

		$staged_copy = Staged_Copy_Repository::find_for_live( $post->ID );

		if ( ! $staged_copy instanceof WP_Post ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			// The article is back. Drift takes over from here: the baseline was
			// never touched, so whatever changed while it was away is reported
			// as drift and the editor is asked before anything overwrites it.
			self::clear( $staged_copy->ID );
			return;
		}

		if ( 'publish' === $old_status ) {
			self::strand( $staged_copy->ID, self::REASON_UNPUBLISHED, $post );
		}
	}

	/**
	 * Keeps the staged copy when the live post is permanently deleted.
	 *
	 * The title is recorded because after this request nothing can resolve it,
	 * and a staged copy nobody can identify is nearly as bad as a deleted one.
	 *
	 * @param int     $post_id The post being deleted.
	 * @param WP_Post $post    The post.
	 * @return void
	 */
	public static function on_delete( $post_id, $post = null ): void {
		$post = $post instanceof WP_Post ? $post : get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post || Status::is_staged( $post ) ) {
			return;
		}

		$staged_copy = Staged_Copy_Repository::find_for_live( $post->ID );

		if ( ! $staged_copy instanceof WP_Post ) {
			return;
		}

		self::strand( $staged_copy->ID, self::REASON_DELETED, $post );

		/*
		 * The forward pointer dies with the post it lives on. The reverse
		 * pointer is deliberately kept: it is the only remaining record of what
		 * this staged copy belonged to, and U14's repair needs it. It resolves
		 * to nothing, which every caller already handles.
		 */
	}

	/**
	 * Turns trashing a staged copy into discarding it.
	 *
	 * The editor still shows core's "Move to trash" button on a staged copy, and it
	 * is a reasonable thing for someone to press when abandoning an edit. Left
	 * alone it produces the one outcome R14 rules out: a staged copy sitting in
	 * the trash holding unreviewed content, still discoverable, while the live
	 * post keeps a pointer to something that is no longer a valid staged copy.
	 *
	 * So it becomes the same operation the posts list offers -- pointers
	 * cleared, then force-deleted. The intent behind the click is honoured
	 * exactly; only the mechanism differs.
	 *
	 * The trash itself is allowed to happen and then undone at the end of the
	 * request, rather than short-circuited. Short-circuiting cannot work here:
	 * core's REST controller re-reads the post by ID immediately after trashing
	 * it and builds the response from that, so a post deleted during the
	 * request leaves the controller preparing a response from null no matter
	 * what the short-circuit returned. Deferring keeps the post readable for
	 * exactly as long as core needs it, and gone before anyone can find it.
	 *
	 * The honest limit: if the process dies before shutdown, the staged copy is
	 * left in the trash with its pointers already cleared. That surfaces as an
	 * orphan in `wp swpub list` and is repairable, which is the right way round
	 * -- the alternative loses the editor's work instead.
	 *
	 * @param int $post_id The post that was trashed.
	 * @return void
	 */
	public static function discard_trashed_staged_copy( $post_id ): void {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'trash' !== $post->post_status ) {
			return;
		}

		// The status has already changed, so identity comes from the link.
		$live_id = (int) get_post_meta( $post_id, Staged_Copy_Repository::LIVE_META, true );

		if ( $live_id <= 0 ) {
			return;
		}

		Staged_Copy_Repository::unlink( $live_id, $post_id );

		self::$discarding[] = $post_id;

		add_action( 'shutdown', array( __CLASS__, 'flush_discards' ), 0 );
	}

	/**
	 * Removes the staged copies that were trashed during this request.
	 *
	 * A named method rather than a closure so the deferred half can be run on
	 * its own, which is the only way to test the two halves separately.
	 *
	 * @return void
	 */
	public static function flush_discards(): void {
		$pending           = self::$discarding;
		self::$discarding = array();

		foreach ( $pending as $post_id ) {
			if ( get_post( $post_id ) instanceof WP_Post ) {
				wp_delete_post( $post_id, true );
			}
		}
	}

	/**
	 * Puts a staged copy back when something took it out of the staged status
	 * without going through `wp_insert_post()` (VIPPROD-1246).
	 *
	 * `Write_Guard` refuses this at `wp_insert_post_empty_content`, which covers
	 * every write that routes through `wp_insert_post()` -- the block editor,
	 * the classic editor, REST, XML-RPC, WP-CLI, a plugin's `wp_update_post()`.
	 * `wp_publish_post()` is the one that does not: it writes `post_status` to
	 * the row with a query of its own and never reaches that hook. That is the
	 * function cron's `check_and_publish_future_post()` calls, and it is public
	 * API a plugin may call directly, so without this a staged copy becomes an
	 * ordinary published post at its own `swpub-staged-<id>` slug, while the
	 * post it stages sits untouched and both pointers still point at it.
	 *
	 * So this is repair rather than refusal, and the difference is honest: the
	 * row has already been written by the time a status transition is
	 * announced, so the status is put back rather than stopped. Refusal remains
	 * the primary mechanism and every write that can be refused still is. What
	 * is left here is the narrow window where core changed a column behind the
	 * guard's back.
	 *
	 * The repair is a direct query for the same reason the damage was one:
	 * `wp_update_post()` from inside a status transition would re-enter the
	 * whole save chain, announce a second transition, and write a revision
	 * recording a state that existed for a fraction of one request. One column
	 * was changed, so one column is changed back.
	 *
	 * The passed object is corrected as well, because it is the same instance
	 * `wp_publish_post()` goes on to hand to `save_post`, `wp_insert_post`, and
	 * `wp_after_insert_post`. Left alone it would tell every listener in the
	 * rest of that request that the copy is published, after this has already
	 * put it back.
	 *
	 * Trash is the one status allowed through, as everywhere else:
	 * `discard_trashed_staged_copy()` above turns it into a discard, which is a
	 * deliberate act rather than an escape.
	 *
	 * Not gated on `is_enabled()`, matching the stranding path above: turning
	 * staging off stops new staging, and the readme's promise is that existing
	 * copies stay contained while it is off.
	 *
	 * @param string  $new_status The status something just gave the copy.
	 * @param WP_Post $post       The staged copy, as core is carrying it.
	 * @return void
	 */
	private static function restore_staged_status( string $new_status, WP_Post $post ): void {
		global $wpdb;

		if ( 'trash' === $new_status ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Puts one column back the way core's own direct query changed it; wp_update_post() here would re-enter the save chain from inside its own transition. The cache is dropped immediately below.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_status' => Status::NAME ),
			array( 'ID' => $post->ID )
		);

		clean_post_cache( $post->ID );

		$post->post_status = Status::NAME;

		Events::staged_status_reverted(
			$post->ID,
			(int) get_post_meta( $post->ID, Staged_Copy_Repository::LIVE_META, true ),
			$new_status
		);
	}

	/**
	 * Records that a staged copy cannot currently be published.
	 *
	 * @param int     $staged_copy_id Staged copy post ID.
	 * @param string  $reason    One of the REASON_ constants.
	 * @param WP_Post $live      The live post, while it can still be read.
	 * @return void
	 */
	private static function strand( int $staged_copy_id, string $reason, WP_Post $live ): void {
		update_post_meta(
			$staged_copy_id,
			self::STRANDED_META,
			array(
				'reason'     => $reason,
				'live_id'    => $live->ID,
				'live_title' => $live->post_title,
				'since'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		/**
		 * Fires when staged changes can no longer be published.
		 *
		 * The staged copy still exists and still holds its content; what is
		 * gone is the published post it was going to update. Nothing is deleted
		 * in response to this, by design.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $staged_copy_id Staged copy post ID.
		 * @param string $reason    Either 'unpublished' or 'deleted'.
		 * @param int    $live_id   The live post it staged.
		 */
		do_action( 'swpub_staged_stranded', $staged_copy_id, $reason, $live->ID );
	}

	/**
	 * Clears the stranded mark.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return void
	 */
	private static function clear( int $staged_copy_id ): void {
		// Checked by existence rather than value: the record is an array, and a
		// string cast of one is both a warning and always true.
		if ( ! metadata_exists( 'post', $staged_copy_id, self::STRANDED_META ) ) {
			return;
		}

		delete_post_meta( $staged_copy_id, self::STRANDED_META );

		/**
		 * Fires when staged changes become publishable again.
		 *
		 * @since 0.1.0
		 *
		 * @param int $staged_copy_id Staged copy post ID.
		 */
		do_action( 'swpub_staged_recovered', $staged_copy_id );
	}

	/**
	 * Why a staged copy cannot be published, if it cannot.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return array<string, mixed>|null The stranding record, or null when healthy.
	 */
	public static function stranding( int $staged_copy_id ): ?array {
		$record = get_post_meta( $staged_copy_id, self::STRANDED_META, true );

		return is_array( $record ) && ! empty( $record['reason'] ) ? $record : null;
	}
}
