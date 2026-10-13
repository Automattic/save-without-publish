<?php
/**
 * Stored state carried across a rename.
 *
 * @package StagedEdits
 */

declare( strict_types = 1 );

namespace StagedEdits;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings stored state up to date across a version bump.
 *
 * The plugin was called Save without Publish, and everything it stored carried
 * the prefix `swpub`: the staged post status, every meta key, the scheduled
 * publish's cron hook, the direct-publish capabilities a site granted, and this
 * class's own option. All of it is renamed to `staged_edits` here, because none
 * of the code reads the old names any more. A site that upgraded without this
 * would keep its staged copies as rows in a status nobody registers, pointed at
 * by meta nobody reads.
 *
 * Before that, the pointer meta key was `_swpub_shadow_id`, until the plugin
 * settled on one word for the second copy. A site that old is carried forward
 * by the same step: the key is renamed straight to its current name.
 *
 * A staged copy created before VIPPROD-752 carries a fork baseline timestamp
 * but no fingerprint, which `Drift::kind()` reads as `unknown` once the live
 * post moves -- the same answer a missing baseline gets, and by design: an
 * unbackfilled copy behaves exactly as drift detection did before this
 * fingerprint existed. The backfill only upgrades what it can prove.
 *
 * Runs on `init`, on every kind of request, and not in the admin only as it
 * once did. A scheduled publish is the reason: WP-Cron fires an event under
 * the hook it was scheduled with, and an event left under the old hook would
 * find nothing listening, be consumed, and take the schedule with it -- on a
 * cron request, which never loads the admin. The cost on every other request
 * is one autoloaded option read.
 */
final class Upgrade {

	/**
	 * Option holding the stored-state version.
	 */
	private const OPTION = 'staged_edits_schema';

	/**
	 * The same option as it was named before the plugin was.
	 */
	private const LEGACY_OPTION = 'swpub_schema';

	/**
	 * Current stored-state version.
	 *
	 * 1 is everything before the pointer rename, including installs that predate
	 * this option and therefore read as 0. 3 adds the fork fingerprint
	 * (VIPPROD-752). 4 is the plugin's own rename, `swpub` to `staged_edits`.
	 */
	private const VERSION = 4;

	/**
	 * The staged post status as it was stored before the rename.
	 */
	private const LEGACY_STATUS = 'swpub_staged';

	/**
	 * The scheduled publish's cron hook as it was before the rename.
	 */
	private const LEGACY_HOOK = 'swpub_publish_scheduled';

	/**
	 * What every direct-publish capability began with before the rename.
	 */
	private const LEGACY_CAPABILITY_PREFIX = 'swpub_publish_directly';

	/**
	 * Every meta key the plugin has stored under an old name, and its name now.
	 *
	 * `_swpub_shadow_id` is the oldest: the pointer before it was called a
	 * staged copy. It and its successor both land on the current pointer key.
	 */
	private const LEGACY_META = array(
		'_swpub_shadow_id'            => Staged_Copy_Repository::STAGED_COPY_META,
		'_swpub_staged_copy_id'       => Staged_Copy_Repository::STAGED_COPY_META,
		'_swpub_live_id'              => Staged_Copy_Repository::LIVE_META,
		'_swpub_forked_modified_gmt'  => Staged_Copy_Repository::FORK_BASELINE_META,
		'_swpub_forked_fingerprint'   => Staged_Copy_Repository::FORK_FINGERPRINT_META,
		'_swpub_merge'                => Merge_Marker::META,
		'_swpub_publish_at'           => Scheduled_Publish::AT_META,
		'_swpub_publish_scheduled_by' => Scheduled_Publish::BY_META,
		'_swpub_schedule_refused'     => Scheduled_Publish::REFUSED_META,
		'_swpub_stranded'             => Transitions::STRANDED_META,
	);

	/**
	 * Upgrades stored state before anything reads it.
	 *
	 * Called from `bootstrap()`, which is `init` at priority 0, after the
	 * status is registered and before anything reads a staged copy.
	 *
	 * @return void
	 */
	public static function init(): void {
		self::maybe_run();
	}

	/**
	 * Runs any upgrade this site has not had yet.
	 *
	 * A site upgrading from before the rename has its version under the
	 * option's old name, so it reads as 0 here and runs every step. That is
	 * fine: every step is safe to repeat, which is also what lets two requests
	 * arriving together on the first load after a deploy both run them.
	 *
	 * @return void
	 */
	public static function maybe_run(): void {
		if ( (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		self::rename_meta();
		self::rename_status();
		self::rename_schedules();
		self::rename_capabilities();
		self::backfill_fingerprints();

		update_option( self::OPTION, self::VERSION, true );
		delete_option( self::LEGACY_OPTION );
	}

	/**
	 * Renames every meta key stored under an old name.
	 *
	 * The rows are read before they are written so their post caches can be
	 * cleared afterwards. Without that, a request that already loaded a live
	 * post keeps serving its old meta and the pointer looks missing for the rest
	 * of the request -- which is exactly when this runs.
	 *
	 * A direct query on the meta table, so it never traverses `Write_Guard`, and
	 * that must stay true. This renames meta keys and touches no post row at
	 * all -- least of all a staged field -- so there is nothing here for the
	 * containment to contain. It also runs before the pointers it is renaming can
	 * be read, which is precisely when the guard could not find a staged copy
	 * even if it looked.
	 *
	 * @return int How many meta rows were renamed.
	 */
	public static function rename_meta(): int {
		global $wpdb;

		$renamed  = 0;
		$post_ids = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A one-time key rename across the meta table, which no API expresses.
		foreach ( self::LEGACY_META as $legacy => $current ) {
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$legacy
				)
			);

			if ( empty( $found ) ) {
				continue;
			}

			$rows = $wpdb->update(
				$wpdb->postmeta,
				array( 'meta_key' => $current ),
				array( 'meta_key' => $legacy )
			);

			$renamed += is_int( $rows ) ? $rows : 0;
			$post_ids = array_merge( $post_ids, $found );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $post_id ) {
			clean_post_cache( $post_id );
		}

		return $renamed;
	}

	/**
	 * Moves every staged copy onto the status registered now.
	 *
	 * A direct query, for the same reason `rename_meta()` is one and with the
	 * same consequence: it does not traverse `Write_Guard` and must not. The
	 * row's status goes from one name for "staged" to the other, which is not a
	 * transition, so nothing that listens for one should hear it.
	 *
	 * @return int How many staged copies were moved.
	 */
	public static function rename_status(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-time status rename, which no API expresses without firing a transition.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s",
				self::LEGACY_STATUS
			)
		);

		if ( empty( $post_ids ) ) {
			return 0;
		}

		$moved = $wpdb->update(
			$wpdb->posts,
			array( 'post_status' => Status::NAME ),
			array( 'post_status' => self::LEGACY_STATUS )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $post_ids as $post_id ) {
			clean_post_cache( (int) $post_id );
		}

		return is_int( $moved ) ? $moved : 0;
	}

	/**
	 * Moves every scheduled publish onto the hook that is listened to now.
	 *
	 * Each event keeps its own time and arguments. The old hook is cleared
	 * whether or not anything was moved, so an event nothing can answer is never
	 * left for WP-Cron to fire.
	 *
	 * @return int How many events were moved.
	 */
	public static function rename_schedules(): int {
		$moved = 0;

		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( empty( $hooks[ self::LEGACY_HOOK ] ) ) {
				continue;
			}

			foreach ( $hooks[ self::LEGACY_HOOK ] as $event ) {
				$args = (array) ( $event['args'] ?? array() );

				if ( false === wp_next_scheduled( Scheduled_Publish::HOOK, $args ) ) {
					wp_schedule_single_event( (int) $timestamp, Scheduled_Publish::HOOK, $args );
				}

				++$moved;
			}
		}

		if ( $moved > 0 ) {
			wp_unschedule_hook( self::LEGACY_HOOK );
		}

		return $moved;
	}

	/**
	 * Carries a site's direct-publish grants over to the capabilities' new names.
	 *
	 * These are the one thing here a site stored itself, by granting
	 * `Capabilities::PUBLISH_DIRECTLY_POSTS` or a sibling to a role or a user.
	 * Losing a grant fails safe -- the save stages instead of publishing -- but
	 * it would still be a rule the site made and the upgrade forgot. A denial
	 * (`add_cap( $cap, false )`) is carried over as a denial.
	 *
	 * @return int How many grants were renamed.
	 */
	public static function rename_capabilities(): int {
		global $wpdb;

		$renamed = 0;

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( $role->capabilities as $capability => $granted ) {
				if ( ! str_starts_with( (string) $capability, self::LEGACY_CAPABILITY_PREFIX ) ) {
					continue;
				}

				$role->add_cap( self::current_capability( (string) $capability ), (bool) $granted );
				$role->remove_cap( (string) $capability );

				++$renamed;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-time search of stored capabilities for a name; WP_User_Query cannot ask for a capability a user is denied.
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				$wpdb->get_blog_prefix() . 'capabilities',
				'%' . $wpdb->esc_like( self::LEGACY_CAPABILITY_PREFIX ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( (int) $user_id );

			if ( false === $user ) {
				continue;
			}

			foreach ( $user->caps as $capability => $granted ) {
				if ( ! str_starts_with( (string) $capability, self::LEGACY_CAPABILITY_PREFIX ) ) {
					continue;
				}

				$user->add_cap( self::current_capability( (string) $capability ), (bool) $granted );
				$user->remove_cap( (string) $capability );

				++$renamed;
			}
		}

		return $renamed;
	}

	/**
	 * A direct-publish capability's name now, from its name before the rename.
	 *
	 * @param string $legacy A capability beginning with the old prefix.
	 * @return string
	 */
	private static function current_capability( string $legacy ): string {
		return Capabilities::PUBLISH_DIRECTLY . substr( $legacy, strlen( self::LEGACY_CAPABILITY_PREFIX ) );
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
