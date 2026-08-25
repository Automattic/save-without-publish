<?php
/**
 * The plugin's public event surface.
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
 * Every action this plugin fires, in one place.
 *
 * Gathered here rather than scattered across the classes that trigger them,
 * because these are a contract with other people's code. A hook someone else
 * depends on should be findable, documented, and changed deliberately, not
 * discovered by grepping for `do_action`.
 *
 * The plugin implements no notification and no audit log of its own. It reports
 * what happened and names who did it; what to do with that belongs to the site.
 * Every action names the acting user for that reason (R27): a merge is the
 * highest-consequence thing here, and an event that cannot be attributed is
 * not much use to an audit trail.
 */
final class Events {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 10, 3 );
	}

	/**
	 * Turns a save of a staged copy into the right event.
	 *
	 * Hooked to `save_post` rather than announced from the fork, so a save made
	 * directly against an existing staged copy is reported too. Core distinguishes
	 * the first write from later ones for us.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post.
	 * @param bool    $update  Whether this was an update rather than a creation.
	 * @return void
	 */
	public static function on_save( $post_id, $post, $update ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $post instanceof WP_Post || ! Status::is_staged( $post ) ) {
			return;
		}

		$live_id = (int) get_post_meta( (int) $post_id, Staged_Copy_Repository::LIVE_META, true );

		if ( $update ) {
			self::staged_edited( (int) $post_id, $live_id );
			return;
		}

		self::staged_created( (int) $post_id, $live_id );
	}

	/**
	 * Announces a new staged copy.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @param int $live_id   Published post ID it stages.
	 * @return void
	 */
	public static function staged_created( int $staged_copy_id, int $live_id ): void {
		/**
		 * Fires when an edit to a published post is first staged.
		 *
		 * The published post is unchanged at this point, and stays unchanged
		 * until a merge.
		 *
		 * @since 0.1.0
		 *
		 * @param int $staged_copy_id Staged copy post ID.
		 * @param int $live_id   Published post ID it stages.
		 * @param int $user_id   User who staged the edit.
		 */
		do_action( 'swpub_staged_created', $staged_copy_id, $live_id, get_current_user_id() );
	}

	/**
	 * Announces a save against an existing staged copy.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @param int $live_id   Published post ID it stages.
	 * @return void
	 */
	public static function staged_edited( int $staged_copy_id, int $live_id ): void {
		/**
		 * Fires on every save to a staged copy after the first.
		 *
		 * Useful for telling the original author that someone is revising their
		 * article before it goes live, which is the case this plugin exists to
		 * make visible.
		 *
		 * @since 0.1.0
		 *
		 * @param int $staged_copy_id Staged copy post ID.
		 * @param int $live_id   Published post ID it stages.
		 * @param int $user_id   User who saved the edit.
		 */
		do_action( 'swpub_staged_edited', $staged_copy_id, $live_id, get_current_user_id() );
	}

	/**
	 * Announces that a write was contained into a staged copy.
	 *
	 * Fires on every engagement of the write guard, whatever transport the write
	 * arrived on, and adds to `swpub_staged_created` / `swpub_staged_edited`
	 * rather than replacing them -- the guard's divert is a save of the staged
	 * copy, so those fire too. It carries what they cannot: which transport the
	 * write came from, which is the difference between an editor staging a change
	 * and an integration finding its writes quietly contained.
	 *
	 * The argument order deliberately matches `swpub_staged_created`. A handler
	 * copied between the two would otherwise read the wrong post.
	 *
	 * @param int    $staged_copy_id Staged copy post ID.
	 * @param int    $live_id        Published post ID it stages.
	 * @param string $channel        Transport the write arrived on.
	 * @return void
	 */
	public static function write_staged( int $staged_copy_id, int $live_id, string $channel ): void {
		/**
		 * Fires when a write to a published post is contained into its staged copy.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $staged_copy_id Staged copy post ID.
		 * @param int    $live_id        Published post ID it stages.
		 * @param string $channel        Transport the write arrived on: rest-cookie,
		 *                               rest-token, xmlrpc, classic, cli, cron,
		 *                               quickedit, or internal. Best-effort.
		 * @param int    $user_id        User who made the write. 0 when there is none.
		 */
		do_action( 'swpub_write_staged', $staged_copy_id, $live_id, $channel, get_current_user_id() );
	}

	/**
	 * Announces that a save reached the backstop instead of the staging route.
	 *
	 * The block editor's own protocol is the staging route: it recognises a save
	 * that will stage and sends it to `POST /swpub/v1/stage/{id}` deliberately.
	 * The redirect-on-save fork underneath it stays as the floor, because a
	 * protocol that depends on a script having loaded is not a guarantee. But it
	 * is a floor, not a path: reaching it means the editor's own protocol did not
	 * run -- the bundle failed, an older build is cached, or the save arrived from
	 * something that is not the editor at all.
	 *
	 * So this is an alarm rather than a report, and the end-to-end suite asserts
	 * it stays silent on every path a working editor takes. It fires only where
	 * the staging route was available to the saver: someone who may publish
	 * directly, saving a post that already has a staged copy, is contained by the
	 * fork by design, and that is not a fallback.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @param int $live_id        Published post ID it stages.
	 * @return void
	 */
	public static function staged_via_backstop( int $staged_copy_id, int $live_id ): void {
		/**
		 * Fires when a save was staged by the fork rather than the staging route.
		 *
		 * The write succeeded and the staged copy holds the change; what this
		 * reports is that the editor's primary protocol did not run for a save it
		 * should have handled. On a healthy site it never fires.
		 *
		 * @since 0.1.0
		 *
		 * @param int $staged_copy_id Staged copy post ID.
		 * @param int $live_id        Published post ID it stages.
		 * @param int $user_id        User whose save was staged.
		 */
		do_action( 'swpub_staged_via_backstop', $staged_copy_id, $live_id, get_current_user_id() );
	}

	/**
	 * Announces that a write published around staging, on the seam that allows it.
	 *
	 * The first write to a post nobody has staged yet, made by a user who cannot
	 * publish, arriving on a programmatic transport: that write publishes, because
	 * closing the seam by default would divert the writes of every sync plugin,
	 * headless publisher, and WP-CLI script running as a low-capability user.
	 *
	 * This is the price of leaving it open. A bypass whose use leaves no record is
	 * not a seam, it is a hole, so every use of it says so here -- with the channel
	 * it arrived on and the user it ran as. `swpub_enforce_programmatic_first_save`
	 * closes the seam for a site that would rather stage those writes.
	 *
	 * @param int    $live_id Published post ID that was written to.
	 * @param string $channel Transport the write arrived on.
	 * @return void
	 */
	public static function published_via_carveout( int $live_id, string $channel ): void {
		/**
		 * Fires when a write publishes on the programmatic first-save seam.
		 *
		 * Subscribe to it to see the seam being used on your site:
		 *
		 *     add_action(
		 *         'swpub_published_via_carveout',
		 *         function ( $live_id, $channel, $user_id ) {
		 *             error_log( "swpub: post {$live_id} published via {$channel} by user {$user_id}" );
		 *         },
		 *         10,
		 *         3
		 *     );
		 *
		 * @since 0.1.0
		 *
		 * @param int    $live_id Published post ID that was written to.
		 * @param string $channel Transport the write arrived on: rest-token, xmlrpc,
		 *                        or cli. Best-effort.
		 * @param int    $user_id User who made the write.
		 */
		do_action( 'swpub_published_via_carveout', $live_id, $channel, get_current_user_id() );
	}

	/**
	 * Announces that a write was refused rather than staged.
	 *
	 * The counterpart to `write_staged()` on a surface that cannot show what
	 * staging did. Quick Edit re-renders its row from the published post, which
	 * still holds the old title, so a staged Quick Edit would look to the editor
	 * exactly like an edit that vanished. Refusing says so instead, and this is
	 * where a site can count how often it happens.
	 *
	 * Nothing was written on either side: the published post is unchanged and no
	 * staged copy was created.
	 *
	 * @param int    $live_id Published post ID the write targeted.
	 * @param string $channel Transport the write arrived on.
	 * @return void
	 */
	public static function stage_refused( int $live_id, string $channel ): void {
		/**
		 * Fires when a write that would have staged was refused instead.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $live_id Published post ID the write targeted.
		 * @param string $channel Transport the write arrived on. Currently only
		 *                        `quickedit`.
		 * @param int    $user_id User whose write was refused.
		 */
		do_action( 'swpub_stage_refused', $live_id, $channel, get_current_user_id() );
	}

	/**
	 * Announces that a write was refused because a staged copy already holds this post.
	 *
	 * Nothing was written on either side. The published post is unchanged and so
	 * is the staged copy, which is the point: the write was assembled against the
	 * published words and would have reverted every staged change it did not know
	 * about.
	 *
	 * This is the event an integration watches to find out that this plugin is
	 * standing between it and a post it expects to own. It fires on every
	 * transport, including one with no authenticated user behind it, so a site
	 * subscribing to it sees the whole picture rather than the admin's share of it.
	 *
	 * @param int    $staged_copy_id Staged copy holding the post's next change.
	 * @param int    $live_id        Published post ID the write targeted.
	 * @param string $channel        Transport the write arrived on.
	 * @return void
	 */
	public static function write_blocked( int $staged_copy_id, int $live_id, string $channel ): void {
		/**
		 * Fires when a write to a post with a staged copy was refused.
		 *
		 * Subscribe to it to find the integrations a staged copy is blocking:
		 *
		 *     add_action(
		 *         'swpub_write_blocked',
		 *         function ( $staged_copy_id, $live_id, $channel, $user_id ) {
		 *             error_log( "swpub: write to {$live_id} via {$channel} refused; copy {$staged_copy_id}" );
		 *         },
		 *         10,
		 *         4
		 *     );
		 *
		 * @since 0.1.0
		 *
		 * @param int    $staged_copy_id Staged copy holding the post's next change.
		 * @param int    $live_id        Published post ID the write targeted.
		 * @param string $channel        Transport the write arrived on. Best-effort.
		 * @param int    $user_id        User whose write was refused. 0 when there is none.
		 */
		do_action( 'swpub_write_blocked', $staged_copy_id, $live_id, $channel, get_current_user_id() );
	}

	/**
	 * Announces that a write could not be staged, so nothing was written at all.
	 *
	 * The counterpart to the fail-closed abort: the live post is untouched and so
	 * is the staged copy, and the caller was told the post was empty, which it was
	 * not. This is where the real reason is.
	 *
	 * @param int    $live_id Published post ID the write targeted.
	 * @param string $reason  Error code from the failed staged-copy write.
	 * @return void
	 */
	public static function staging_write_failed( int $live_id, string $reason ): void {
		/**
		 * Fires when a write was contained but the staged copy could not be written.
		 *
		 * The whole update was aborted, so the published post is unchanged. The
		 * caller receives core's `empty_content` failure, whose message is
		 * misleading by construction; this action carries the true reason.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $live_id Published post ID the write targeted.
		 * @param string $reason  Error code from the failed staged-copy write.
		 * @param int    $user_id User who made the write. 0 when there is none.
		 */
		do_action( 'swpub_staging_write_failed', $live_id, $reason, get_current_user_id() );
	}

	/**
	 * Announces that someone published over a change they were shown.
	 *
	 * @param int    $staged_copy_id Staged copy post ID.
	 * @param int    $live_id   Published post ID.
	 * @param string $confirmed The published state they confirmed against.
	 * @return void
	 */
	public static function drift_overridden( int $staged_copy_id, int $live_id, string $confirmed ): void {
		/**
		 * Fires when a merge proceeds over a change made after staging began.
		 *
		 * This is the one moment the plugin knowingly overwrites somebody
		 * else's edit. It fires only when there was drift and the editor
		 * confirmed past it, never on an ordinary merge, so a listener can
		 * treat it as the exception it is.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $staged_copy_id Staged copy post ID.
		 * @param int    $live_id   Published post ID being overwritten.
		 * @param int    $user_id   User who confirmed.
		 * @param string $confirmed GMT timestamp of the published state they were shown.
		 */
		do_action( 'swpub_drift_overridden', $staged_copy_id, $live_id, get_current_user_id(), $confirmed );
	}

	/**
	 * Whether a post is a staged copy (R35).
	 *
	 * The one-line opt-out for anything hooked to core's save and transition
	 * hooks. A staged copy is a real post row, so `save_post`, `wp_after_insert_post`
	 * and `transition_post_status` all fire for staged writes. Code that should
	 * only react to published content -- search indexing, social posting, cache
	 * warming, newsletters -- can exclude them with this:
	 *
	 *     if ( function_exists( 'SaveWithoutPublish\is_staged' ) && SaveWithoutPublish\is_staged( $post ) ) {
	 *         return;
	 *     }
	 *
	 * Guarded with `function_exists` so the integration survives the plugin
	 * being deactivated.
	 *
	 * @param int|WP_Post|null $post Post to test.
	 * @return bool True when the post is a staged copy.
	 */
	public static function is_staged( $post ): bool {
		return Status::is_staged( $post );
	}
}

/**
 * Whether a post is a staged copy.
 *
 * The documented public predicate from R35, as a plain function so a one-line
 * `function_exists()` guard works without knowing the class layout.
 *
 * @since 0.1.0
 *
 * @param int|WP_Post|null $post Post to test.
 * @return bool True when the post is a staged copy.
 */
function is_staged( $post ): bool {
	return Events::is_staged( $post );
}
