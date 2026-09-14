<?php
/**
 * Stored state carried across a rename.
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
 * Brings stored state up to date across a version bump.
 *
 * The pointer meta key was `_swpub_shadow_id` before the plugin settled on one
 * word for the second copy. Nothing reads the old key any more, so a site that
 * upgraded without this would keep its staged copies as rows nobody points at:
 * the published post would look clean, the copy would be invisible in the posts
 * list, and the next edit would stage a second one beside it.
 *
 * A staged copy created before VIPPROD-752 carries a fork baseline timestamp
 * but no fingerprint, which `Drift::kind()` reads as `unknown` once the live
 * post moves -- the same answer a missing baseline gets, and by design: an
 * unbackfilled copy behaves exactly as drift detection did before this
 * fingerprint existed. The backfill only upgrades what it can prove.
 *
 * Runs in the admin only. Both steps have to happen before anyone can act on a
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
	 * option and therefore read as 0. 3 adds the fork fingerprint (VIPPROD-752).
	 */
	private const VERSION = 3;

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
		self::backfill_fingerprints();

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

	/**
	 * Backfills the fork fingerprint for staged copies that predate it (VIPPROD-752).
	 *
	 * The fingerprint means "the live post's title, content, and excerpt at
	 * the moment staging began". The only place those exact bytes still exist
	 * by the time an upgrade runs is the live row itself -- and only while
	 * nothing has touched it since the fork, which the recorded baseline
	 * timestamp still matching `post_modified_gmt` is the plugin's own
	 * definition of. So that is the one case backfilled, from the live row.
	 *
	 * Not from the copy's baseline revision, which looks like the same
	 * content and is not: the copy was written through `wp_insert_post()`,
	 * whose sanitization can alter bytes for a user without
	 * `unfiltered_html` (every non-super-admin on multisite). A fingerprint
	 * of those bytes would read as content drift on every such copy with
	 * nothing having changed at all.
	 *
	 * A copy whose live post has already moved is left alone. It reads as
	 * `unknown` drift from here on, which is exactly what it read as before
	 * this fingerprint existed: refused, confirmable, no kind.
	 *
	 * A direct query on posts and postmeta, so it never traverses `Write_Guard`
	 * and touches no post row: it reads the live row and writes only meta on
	 * the staged copy.
	 *
	 * @return int How many copies were backfilled.
	 */
	public static function backfill_fingerprints(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-time backfill across posts and postmeta; the "posts missing this meta key" query has no non-direct expression VIP Search's WP_Query offload can serve.
		$staged_copy_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_status = %s AND m.post_id IS NULL",
				Staged_Copy_Repository::FORK_FINGERPRINT_META,
				Status::NAME
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$backfilled = 0;

		foreach ( $staged_copy_ids as $staged_copy_id ) {
			$staged_copy_id = (int) $staged_copy_id;
			$live           = Staged_Copy_Repository::find_live_for_staged_copy( $staged_copy_id );

			if ( ! $live instanceof WP_Post ) {
				continue;
			}

			$baseline = Drift::baseline( $staged_copy_id );

			if ( '' === $baseline || $baseline !== $live->post_modified_gmt ) {
				continue;
			}

			add_post_meta(
				$staged_copy_id,
				Staged_Copy_Repository::FORK_FINGERPRINT_META,
				Drift::fingerprint( $live ),
				true
			);

			clean_post_cache( $staged_copy_id );

			++$backfilled;
		}

		return $backfilled;
	}
}
