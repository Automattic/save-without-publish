<?php
/**
 * Stored state carried across a rename.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings stored pointers up to the current vocabulary.
 *
 * The pointer meta key was `_swpub_shadow_id` before the plugin settled on one
 * word for the second copy. Nothing reads the old key any more, so a site that
 * upgraded without this would keep its staged copies as rows nobody points at:
 * the published post would look clean, the copy would be invisible in the posts
 * list, and the next edit would stage a second one beside it.
 *
 * Runs in the admin only. The rename has to happen before anyone can act on a
 * staged copy, and every way of acting on one goes through the admin or WP-CLI,
 * which loads the admin's `init` too.
 */
final class Upgrade {

	/**
	 * Option holding the stored-state version.
	 */
	private const OPTION = 'swpub_schema';

	/**
	 * Current stored-state version.
	 *
	 * 1 is everything before the rename, including installs that predate this
	 * option and therefore read as 0.
	 */
	private const VERSION = 2;

	/**
	 * The pointer key as it was written before the rename.
	 */
	private const LEGACY_POINTER = '_swpub_shadow_id';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
	}

	/**
	 * Runs any upgrade this site has not had yet.
	 *
	 * @return void
	 */
	public static function maybe_run(): void {
		if ( (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		self::rename_pointers();

		update_option( self::OPTION, self::VERSION, true );
	}

	/**
	 * Renames the live post's pointer meta.
	 *
	 * The rows are read before they are written so their post caches can be
	 * cleared afterwards. Without that, a request that already loaded a live
	 * post keeps serving its old meta and the pointer looks missing for the rest
	 * of the request -- which is exactly when this runs.
	 *
	 * A direct query on the meta table, so it never traverses `Write_Guard`, and
	 * that must stay true. This renames a meta key and touches no post row at
	 * all -- least of all a staged field -- so there is nothing here for the
	 * containment to contain. It also runs before the pointers it is renaming can
	 * be read, which is precisely when the guard could not find a staged copy
	 * even if it looked.
	 *
	 * @return int How many pointers were renamed.
	 */
	public static function rename_pointers(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-time key rename across the meta table, which no API expresses.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::LEGACY_POINTER
			)
		);

		if ( empty( $post_ids ) ) {
			return 0;
		}

		$renamed = $wpdb->update(
			$wpdb->postmeta,
			array( 'meta_key' => Staged_Copy_Repository::STAGED_COPY_META ),
			array( 'meta_key' => self::LEGACY_POINTER )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $post_ids as $post_id ) {
			clean_post_cache( (int) $post_id );
		}

		return is_int( $renamed ) ? $renamed : 0;
	}
}
