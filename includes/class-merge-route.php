<?php
/**
 * The REST route that applies a merge.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the merge to the editor, and to nothing else.
 *
 * A route of our own rather than an action on the posts controller, because a
 * merge is not an update to the staged copy: it writes to a different post, deletes
 * the one being addressed, and has to refuse for reasons core's controller has
 * no vocabulary for.
 */
final class Merge_Route {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE_V1 = 'swpub/v1';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the merge route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/merge/(?P<id>[\d]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'can_merge' ),
				'args'                => array(
					'id'      => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'confirm' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'The published state the editor was shown, when confirming past a change.', 'save-without-publish' ),
					),
				),
			)
		);
	}

	/**
	 * Whether the current user may merge this staged copy.
	 *
	 * Authorization resolves against the live post, not the staged copy (KTD13), so
	 * someone who can no longer edit the published post cannot publish into it.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error True when allowed.
	 */
	public static function can_merge( WP_REST_Request $request ) {
		if ( ! is_enabled() ) {
			return new WP_Error(
				'swpub_disabled',
				__( 'Staging is currently disabled.', 'save-without-publish' ),
				array( 'status' => 403 )
			);
		}

		$staged_copy_id = (int) $request['id'];
		$staged_copy    = get_post( $staged_copy_id );

		if ( ! $staged_copy instanceof WP_Post || ! Status::is_staged( $staged_copy ) ) {
			return new WP_Error(
				'swpub_not_staged',
				__( 'There is nothing staged to publish.', 'save-without-publish' ),
				array( 'status' => 404 )
			);
		}

		if ( ! Capabilities::current_user_can_manage( $staged_copy_id ) ) {
			return new WP_Error(
				'swpub_forbidden',
				__( 'You are not allowed to publish these changes.', 'save-without-publish' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Applies the merge.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error What the merge did, or why it refused.
	 */
	public static function handle( WP_REST_Request $request ) {
		$confirm = $request->get_param( 'confirm' );

		$result = Merge::apply( (int) $request['id'], is_string( $confirm ) ? $confirm : null );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'liveId'    => $result['live_id'],
				'editUrl'   => (string) get_edit_post_link( $result['live_id'], 'raw' ),
				'viewUrl'   => (string) get_permalink( $result['live_id'] ),
				'revisions' => count( $result['revisions'] ),
			)
		);
	}
}
