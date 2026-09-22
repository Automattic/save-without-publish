<?php
/**
 * The REST route that schedules and cancels a publish.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes scheduling a staged copy's publish to the editor, and to nothing else.
 *
 * A route of its own for the same reason `Merge_Route` is one: scheduling is
 * not an update to the staged copy's own fields, and it has to refuse for
 * reasons core's posts controller has no vocabulary for.
 */
final class Schedule_Route {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the schedule route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			Merge_Route::NAMESPACE_V1,
			'/schedule/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_schedule' ),
					'permission_callback' => array( Merge_Route::class, 'can_manage_staged' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'at' => array(
							'type'        => 'string',
							'required'    => true,
							'description' => __( 'When to publish these changes. ISO 8601; a time with no offset is treated as site-local, exactly as the date parameter is.', 'save-without-publish' ),
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'handle_unschedule' ),
					'permission_callback' => array( Merge_Route::class, 'can_manage_staged' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Schedules the publish.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error The schedule, or why it was refused.
	 */
	public static function handle_schedule( WP_REST_Request $request ) {
		$result = Scheduled_Publish::schedule(
			(int) $request['id'],
			(string) $request->get_param( 'at' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'scheduledFor'      => $result['at_gmt'],
				'scheduledForLocal' => $result['at_local'],
				'scheduledBy'       => $result['by'],
			)
		);
	}

	/**
	 * Cancels the schedule.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response Always succeeds; cancelling an unscheduled copy is not an error.
	 */
	public static function handle_unschedule( WP_REST_Request $request ) {
		Scheduled_Publish::unschedule( (int) $request['id'] );

		return new WP_REST_Response( array( 'scheduled' => false ) );
	}
}
