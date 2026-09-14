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
 * but no fingerprint, which `Drift::kind()` reads as `unknown` -- the same
 * answer a missing baseline gets, and by design: an unbackfilled copy behaves
 * exactly as drift detection did before this fingerprint existed. The backfill
 * only upgrades what it safely can.
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
	 * A copy's own oldest non-autosave revision is the baseline `establish()`
	 * seeds at fork -- the content as published, at the moment staging began --
	 * so it stands in for the live post's state at that same moment, which is
	 * long gone by the time this runs. A copy with no such revision (baseline
	 * seeding failed, or every revision was later pruned by
	 * `wp_revisions_to_keep`) is left without a fingerprint: `Drift::kind()`
	 * reads that as `unknown`, the same answer a missing baseline already
	 * gets, so an unbackfilled copy is no worse off than before this existed.
	 *
	 * A direct query on posts and postmeta, so it never traverses `Write_Guard`
	 * and touches no post row. Reading the revision, which is not this
	 * plugin's row to own, and writing only meta on the staged copy, is not
	 * a write this containment is about.
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
			$baseline       = self::oldest_non_autosave_revision( $staged_copy_id );

			if ( ! $baseline instanceof WP_Post ) {
				continue;
			}

			add_post_meta(
				$staged_copy_id,
				Staged_Copy_Repository::FORK_FINGERPRINT_META,
				Drift::fingerprint( $baseline ),
				true
			);

			clean_post_cache( $staged_copy_id );

			++$backfilled;
		}

		return $backfilled;
	}

	/**
	 * A staged copy's oldest revision that is not an autosave.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return WP_Post|null The revision, or null when the copy has none.
	 */
	private static function oldest_non_autosave_revision( int $staged_copy_id ): ?WP_Post {
		foreach ( wp_get_post_revisions( $staged_copy_id, array( 'order' => 'ASC' ) ) as $revision ) {
			if ( ! wp_is_post_autosave( $revision ) ) {
				return $revision;
			}
		}

		return null;
	}
}
