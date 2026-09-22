<?php
/**
 * WP-CLI commands for finding and repairing staged state.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_CLI;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The commands an on-call engineer needs when the admin is not enough.
 *
 * Two situations this exists for. A merge that failed past its attempt budget
 * is stranded, and nothing in the admin can clear it: that is deliberate, since
 * automatic revival would defeat the cap and put both posts back in the loop
 * the cap exists to break. And a staged copy whose live post was deleted cannot
 * appear in the posts list at all, because there is no row left to label.
 *
 * This is not a settings surface. There is nothing to configure here; the kill
 * switch is a filter, and everything else is state to inspect or repair.
 */
final class CLI {

	/**
	 * Staged copies fetched per query.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Hard stop on paging.
	 */
	private const MAX_PAGES = 100;

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command(
			'swpub list',
			array( __CLASS__, 'command_list' ),
			array(
				'shortdesc' => 'Lists every staged copy, its published post, and any merge in flight.',
			)
		);

		WP_CLI::add_command(
			'swpub repair',
			array( __CLASS__, 'command_repair' ),
			array(
				'shortdesc' => 'Resumes or reports an interrupted merge.',
			)
		);

		WP_CLI::add_command(
			'swpub schedule',
			array( __CLASS__, 'command_schedule' ),
			array(
				'shortdesc' => 'Schedules a staged copy to publish at a set time (VIPPROD-1247).',
			)
		);

		WP_CLI::add_command(
			'swpub unschedule',
			array( __CLASS__, 'command_unschedule' ),
			array(
				'shortdesc' => "Cancels a staged copy's scheduled publish (VIPPROD-1247).",
			)
		);

		WP_CLI::add_command(
			'swpub publish',
			array( __CLASS__, 'command_publish' ),
			array(
				'shortdesc' => 'Publishes a staged copy immediately, from the command line (VIPPROD-1247).',
			)
		);

		WP_CLI::add_command(
			'swpub run-due',
			array( __CLASS__, 'command_run_due' ),
			array(
				'shortdesc' => 'Fires every scheduled publish that is due now (VIPPROD-1247).',
			)
		);
	}

	/**
	 * Every staged copy on the site, with the state it is in.
	 *
	 * Deliberately does not consult the kill switch. When staging is disabled
	 * during an incident, finding the staged copies that already exist is exactly
	 * what an engineer needs to do.
	 *
	 * @return array<int, array<string, mixed>> One row per staged copy.
	 */
	public static function inventory(): array {
		$rows = array();

		foreach ( self::staged_copies() as $staged_copy ) {
			$live      = Staged_Copy_Repository::find_live_for_staged_copy( $staged_copy->ID );
			$stranding = Transitions::stranding( $staged_copy->ID );
			$marker    = $live instanceof WP_Post ? Merge_Marker::get( $live->ID ) : null;
			$sched     = Scheduled_Publish::scheduled( $staged_copy->ID );

			$rows[] = array(
				'staged_copy_id'  => $staged_copy->ID,
				'live_id'    => $live instanceof WP_Post ? $live->ID : (int) get_post_meta( $staged_copy->ID, Staged_Copy_Repository::LIVE_META, true ),
				'live_title' => self::live_title( $live, $stranding ),
				'state'      => self::state( $staged_copy->ID, $live, $stranding, $marker ),
				'phase'      => $marker ? (string) $marker['phase'] : '',
				'attempts'   => $marker ? (int) $marker['attempts'] : 0,
				'staged_by'  => self::author_name( (int) $staged_copy->post_author ),
				'staged_at'  => $staged_copy->post_modified_gmt,
				'scheduled_for' => $sched ? $sched['at_gmt'] : '',
			);
		}

		return $rows;
	}

	/**
	 * Repairs, or explains, one post's staged state.
	 *
	 * Accepts either post of a pair, because whoever is holding an incident
	 * usually has one ID and does not yet know which end of it they have.
	 *
	 * @param int  $post_id Either post of a staged pair.
	 * @param bool $force   Whether to revive a stranded merge.
	 * @return array<string, mixed> What was done, and to what.
	 */
	public static function repair_post( int $post_id, bool $force = false ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return array(
				'post_id' => $post_id,
				'action'  => 'none',
				'reason'  => 'no_post',
				'revived' => false,
			);
		}

		$live    = Status::is_staged( $post ) ? Staged_Copy_Repository::find_live_for_staged_copy( $post->ID ) : $post;
		$live_id = $live instanceof WP_Post ? $live->ID : 0;

		$revived = false;

		if ( $force && $live_id > 0 ) {
			$revived = Merge_Marker::revive( $live_id );
		}

		$decision = Merge_Resume::run( $post->ID );

		return array(
			'post_id' => $post->ID,
			'action'  => (string) $decision['action'],
			'reason'  => (string) $decision['reason'],
			'revived' => $revived,
		);
	}

	/**
	 * `wp swpub list`
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function command_list( $args, $assoc_args ): void {
		$rows = self::inventory();

		if ( empty( $rows ) ) {
			WP_CLI::success( 'No staged copies exist.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			array( 'staged_copy_id', 'live_id', 'live_title', 'state', 'phase', 'attempts', 'staged_by', 'staged_at', 'scheduled_for' )
		);
	}

	/**
	 * `wp swpub repair <post-id>`
	 *
	 * ## OPTIONS
	 *
	 * [<post-id>]
	 * : Either post of a staged pair. Omit with --all.
	 *
	 * [--all]
	 * : Repair every staged pair on the site.
	 *
	 * [--force]
	 * : Revive a merge stranded past its attempt budget. Use once the underlying cause is addressed: the budget exists to stop a failing merge from making both posts unopenable.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function command_repair( $args, $assoc_args ): void {
		$force = isset( $assoc_args['force'] );
		$all   = isset( $assoc_args['all'] );

		if ( ! $all && empty( $args[0] ) ) {
			WP_CLI::error( 'Pass a post ID, or --all.' );
			return;
		}

		$targets = $all
			? wp_list_pluck( self::inventory(), 'staged_copy_id' )
			: array( (int) $args[0] );

		foreach ( $targets as $target ) {
			$result = self::repair_post( (int) $target, $force );

			WP_CLI::log(
				sprintf(
					'%d: %s (%s)%s',
					$result['post_id'],
					$result['action'],
					$result['reason'],
					$result['revived'] ? ' [revived]' : ''
				)
			);
		}

		WP_CLI::success( sprintf( 'Checked %d staged %s.', count( $targets ), count( $targets ) === 1 ? 'copy' : 'copies' ) );
	}

	/**
	 * `wp swpub schedule <copy> --at=<datetime>`
	 *
	 * Runs as WP-CLI's own `--user` global, the same flag every WP-CLI
	 * command already reads: `wp swpub schedule 123 --at="+2 minutes"
	 * --user=admin`. That person becomes the acting user when the schedule
	 * fires (VIPPROD-1247), exactly as scheduling from the editor would make
	 * the person clicking the acting user.
	 *
	 * ## OPTIONS
	 *
	 * <copy-id>
	 * : The staged copy to schedule.
	 *
	 * --at=<datetime>
	 * : When to publish, e.g. 2026-10-03T09:00:00. ISO 8601 only -- the same
	 * parser the posts controller's own `date` parameter uses, which is
	 * stricter than PHP's strtotime() and does not accept a relative phrase
	 * such as "+2 minutes". A time with no offset is treated as site-local.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function command_schedule( $args, $assoc_args ): void {
		$copy_id = (int) ( $args[0] ?? 0 );
		$at      = (string) ( $assoc_args['at'] ?? '' );

		if ( $copy_id <= 0 ) {
			WP_CLI::error( 'Pass a staged copy ID.' );
			return;
		}

		if ( '' === $at ) {
			WP_CLI::error( 'Pass --at=<datetime>.' );
			return;
		}

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			WP_CLI::error( 'Pass --user: a scheduled publish runs as the person who scheduled it.' );
			return;
		}

		$result = Scheduled_Publish::schedule( $copy_id, $at, $user_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Copy %d scheduled to publish at %s GMT (%s local).',
				$copy_id,
				$result['at_gmt'],
				$result['at_local']
			)
		);
	}

	/**
	 * `wp swpub unschedule <copy>`
	 *
	 * ## OPTIONS
	 *
	 * <copy-id>
	 * : The staged copy to unschedule.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function command_unschedule( $args, $assoc_args ): void {
		$copy_id = (int) ( $args[0] ?? 0 );

		if ( $copy_id <= 0 ) {
			WP_CLI::error( 'Pass a staged copy ID.' );
			return;
		}

		Scheduled_Publish::unschedule( $copy_id );

		WP_CLI::success( sprintf( 'Copy %d unscheduled.', $copy_id ) );
	}

	/**
	 * `wp swpub publish <copy>`
	 *
	 * The merge, from the command line: applies immediately, as WP-CLI's own
	 * `--user` global, with every refusal the editor's own click meets --
	 * drift included, which `--confirm` answers the same way the editor's
	 * confirmation does.
	 *
	 * ## OPTIONS
	 *
	 * <copy-id>
	 * : The staged copy to publish.
	 *
	 * [--confirm=<token>]
	 * : The published state token to confirm past drift with, from a prior refusal's data.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function command_publish( $args, $assoc_args ): void {
		$copy_id = (int) ( $args[0] ?? 0 );
		$confirm = isset( $assoc_args['confirm'] ) ? (string) $assoc_args['confirm'] : null;

		if ( $copy_id <= 0 ) {
			WP_CLI::error( 'Pass a staged copy ID.' );
			return;
		}

		if ( get_current_user_id() <= 0 ) {
			WP_CLI::error( 'Pass --user: a publish runs as the person publishing it.' );
			return;
		}

		$result = Merge::apply( $copy_id, $confirm );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Published. Live post %d, snapshot revision %d, %d revision(s) adopted.',
				$result['live_id'],
				$result['snapshot_id'],
				count( $result['revisions'] )
			)
		);
	}

	/**
	 * `wp swpub run-due`
	 *
	 * Fires every schedule whose time has come, the way a system cron line
	 * would on a site running `DISABLE_WP_CRON` (VIPPROD-1247). Also the
	 * right thing to run by hand after an incident, to catch anything
	 * WP-Cron itself dropped.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function command_run_due( $args, $assoc_args ): void {
		$outcomes = Scheduled_Publish::run_due();

		if ( empty( $outcomes ) ) {
			WP_CLI::success( 'No schedules are due.' );
			return;
		}

		$counts = array(
			'ran'     => 0,
			'refused' => 0,
			'skipped' => 0,
		);

		foreach ( $outcomes as $copy_id => $outcome ) {
			WP_CLI::log(
				sprintf(
					'%d: %s%s',
					$copy_id,
					$outcome['outcome'],
					'' !== $outcome['reason'] ? ' (' . $outcome['reason'] . ')' : ''
				)
			);

			++$counts[ $outcome['outcome'] ];
		}

		WP_CLI::success(
			sprintf(
				'%d due: %d ran, %d refused, %d skipped.',
				count( $outcomes ),
				$counts['ran'],
				$counts['refused'],
				$counts['skipped']
			)
		);
	}

	/**
	 * Every staged post, paged.
	 *
	 * Public so `Scheduled_Publish::run_due()` (VIPPROD-1247) can iterate the
	 * same set `inventory()` does rather than running a second query with its
	 * own idea of "every staged copy" to keep in step with this one.
	 *
	 * @return WP_Post[] The staged copies.
	 */
	public static function staged_copies(): array {
		$staged_copies = array();

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = new WP_Query(
				array(
					'post_type'              => 'any',
					'post_status'            => Status::NAME,
					'posts_per_page'         => self::PAGE_SIZE,
					'offset'                 => $page * self::PAGE_SIZE,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'ignore_sticky_posts'    => true,
				)
			);

			if ( empty( $query->posts ) ) {
				break;
			}

			$staged_copies = array_merge( $staged_copies, $query->posts );

			if ( count( $query->posts ) < self::PAGE_SIZE ) {
				break;
			}
		}

		return $staged_copies;
	}

	/**
	 * A one-word summary of what is wrong, if anything.
	 *
	 * @param int                       $staged_copy_id The staged copy.
	 * @param WP_Post|null              $live      The live post.
	 * @param array<string, mixed>|null $stranding The stranding record.
	 * @param array<string, mixed>|null $marker    The merge marker.
	 * @return string The state.
	 */
	private static function state( int $staged_copy_id, ?WP_Post $live, ?array $stranding, ?array $marker ): string {
		if ( $marker && Merge_Marker::is_stranded( $marker ) ) {
			return 'merge-stranded';
		}

		if ( $marker ) {
			return 'merging';
		}

		if ( ! $live instanceof WP_Post ) {
			// No live post and no stranding record means the pointer broke
			// without any transition we saw -- the one state nothing else can
			// explain, so it is named rather than lumped in with the rest.
			return $stranding ? 'live-' . $stranding['reason'] : 'orphaned';
		}

		if ( 'publish' !== $live->post_status ) {
			return 'live-unpublished';
		}

		if ( null !== Scheduled_Publish::refusal( $staged_copy_id ) ) {
			return 'schedule-refused';
		}

		return Drift::has_drifted( $staged_copy_id ) ? 'drifted' : 'healthy';
	}

	/**
	 * The published post's title, falling back to what was recorded at deletion.
	 *
	 * @param WP_Post|null              $live      The live post.
	 * @param array<string, mixed>|null $stranding The stranding record.
	 * @return string The title.
	 */
	private static function live_title( ?WP_Post $live, ?array $stranding ): string {
		if ( $live instanceof WP_Post ) {
			return $live->post_title;
		}

		return $stranding ? (string) $stranding['live_title'] . ' (deleted)' : '(unknown)';
	}

	/**
	 * A user's display name.
	 *
	 * @param int $user_id User ID.
	 * @return string The name, or the ID when the user is gone.
	 */
	private static function author_name( int $user_id ): string {
		$user = get_userdata( $user_id );

		return $user ? $user->display_name : '#' . $user_id;
	}
}
