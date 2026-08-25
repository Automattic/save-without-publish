<?php
/**
 * The REST route that stages a change deliberately.
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
 * Stages a change without publishing anything first (R37, R38).
 *
 * It began as the way someone who can publish asks for staging, and it is now
 * the block editor's primary save protocol for staging outright (R49): the
 * editor recognises a save that is going to stage -- whoever is making it, and
 * whether or not the post already has a staged copy -- and sends it here rather
 * than to the post endpoint. `Fork`'s 409 stays underneath as the backstop for a
 * save whose JavaScript did not run.
 *
 * The contract, for a client that is not the editor:
 *
 *     POST /swpub/v1/stage/{id}    { title?, content?, excerpt? }
 *     200                          { stagedCopyId, editUrl }
 *
 * All three fields are optional, so a request carrying none of them stages the
 * post as it stands. Anything outside them is refused rather than dropped, with
 * `swpub_unstageable_field` and HTTP 400 naming the fields, because an editor
 * whose category change vanished silently would reasonably call that data loss.
 *
 * A route rather than a flag carried on the save: a flag that fails to arrive --
 * because the script did not load, or the request was replayed, or the editor
 * was mid-render -- publishes to readers. The failure mode of a missing route
 * call is that nothing happens at all.
 *
 * The published post is never written here. The editor sends what it is holding,
 * the route puts it on the staged copy, and the editor moves there.
 */
final class Stage_Route {

	/**
	 * The fields a staged copy can carry (R29).
	 */
	private const STAGED_FIELDS = array( 'title', 'content', 'excerpt' );

	/**
	 * Fields that are refused rather than dropped.
	 *
	 * Silently ignoring one would let an editor believe a slug or a term change
	 * had been staged and discover on publish that it never was. Core versions
	 * none of these, so neither the review screen nor the pre-publish snapshot
	 * could honour them.
	 */
	private const REFUSED_FIELDS = array(
		'status',
		'slug',
		'author',
		'date',
		'date_gmt',
		'password',
		'featured_media',
		'categories',
		'tags',
		'meta',
		'template',
		'parent',
		'menu_order',
		'comment_status',
		'ping_status',
		'sticky',
		'format',
	);

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the staging route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			Merge_Route::NAMESPACE_V1,
			'/stage/(?P<id>[\d]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'can_stage' ),
				'args'                => array(
					'id'      => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'title'   => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'The title the editor is holding, unsaved.', 'save-without-publish' ),
					),
					'content' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'The content the editor is holding, unsaved.', 'save-without-publish' ),
					),
					'excerpt' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'The excerpt the editor is holding, unsaved.', 'save-without-publish' ),
					),
				),
			)
		);
	}

	/**
	 * Whether the current user may stage a change to this post.
	 *
	 * The published post's own edit capability, and nothing more. Someone who
	 * cannot publish stages on save anyway, so requiring the direct-publish
	 * capability here would refuse a request the product already grants.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error True when allowed.
	 */
	public static function can_stage( WP_REST_Request $request ) {
		if ( ! is_enabled() ) {
			return new WP_Error(
				'swpub_disabled',
				__( 'Staging is currently disabled.', 'save-without-publish' ),
				array( 'status' => 403 )
			);
		}

		$live = get_post( (int) $request['id'] );

		if ( ! $live instanceof WP_Post || 'publish' !== $live->post_status ) {
			return new WP_Error(
				'swpub_not_published',
				__( 'There is no published post here to stage a change to.', 'save-without-publish' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $live->ID ) ) {
			return new WP_Error(
				'swpub_forbidden',
				__( 'You are not allowed to edit this post.', 'save-without-publish' ),
				array( 'status' => 403 )
			);
		}

		/*
		 * The shared preconditions, after the capability check so a request
		 * that may not edit the post learns nothing about its staging state.
		 * The type gate matters most here: a type outside `staged_post_types()`
		 * never gets the field-lock seam, so a copy staged for it could be
		 * flipped to publish over REST as a public duplicate.
		 */
		$establishable = Staged_Copy_Repository::can_establish( $live );

		if ( is_wp_error( $establishable ) ) {
			return $establishable;
		}

		/*
		 * Staging is for a post that has nothing staged yet (R55). This route is
		 * the deliberate first save, and asking it to stage a second time over an
		 * existing copy is the destructive case wearing the polite door's clothes:
		 * the request was composed in an editor showing the published words, so
		 * writing it into the copy reverts every staged change it does not carry.
		 *
		 * Last of the checks, after the capability, so a request that may not edit
		 * this post is told nothing about whether a copy exists.
		 */
		$staged_copy = Staged_Copy_Repository::find_for_live( $live->ID );

		if ( $staged_copy instanceof WP_Post ) {
			Events::write_blocked( $staged_copy->ID, $live->ID, Write_Guard::channel() );

			return Staged_Copy_Repository::live_locked_error( $live, $staged_copy );
		}

		return true;
	}

	/**
	 * Stages the change.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error Where the staged copy is, or why staging refused.
	 */
	public static function handle( WP_REST_Request $request ) {
		$live = get_post( (int) $request['id'] );

		if ( ! $live instanceof WP_Post ) {
			return new WP_Error(
				'swpub_not_published',
				__( 'There is no published post here to stage a change to.', 'save-without-publish' ),
				array( 'status' => 404 )
			);
		}

		$refused = self::refused_fields( $request );

		if ( ! empty( $refused ) ) {
			return new WP_Error(
				'swpub_unstageable_field',
				sprintf(
					/* translators: %s: comma-separated list of field names. */
					__( 'Only the title, content, and excerpt can be staged. Change these on the published post instead: %s.', 'save-without-publish' ),
					implode( ', ', $refused )
				),
				array( 'status' => 400 )
			);
		}

		// The revision, merge-marker, type, and no-existing-copy preconditions ran
		// in `can_stage()`, which dispatch guarantees ran before this. `establish()`
		// therefore creates rather than finds, and this route never writes into a
		// copy it did not just make.
		$staged_copy = Staged_Copy_Repository::establish( $live );

		if ( is_wp_error( $staged_copy ) ) {
			return $staged_copy;
		}

		if ( ! Staged_Copy_Repository::pointers_agree( $live->ID, $staged_copy->ID ) ) {
			return new WP_Error(
				'swpub_staging_failed',
				__( 'The change was not staged. The published post is unchanged, and your edits are still here.', 'save-without-publish' ),
				array( 'status' => 500 )
			);
		}

		if ( ! self::write_staged_content( $staged_copy->ID, $request ) ) {
			return new WP_Error(
				'swpub_staging_failed',
				__( 'The change was not staged. The published post is unchanged, and your edits are still here.', 'save-without-publish' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'stagedCopyId' => $staged_copy->ID,
				'editUrl'      => (string) get_edit_post_link( $staged_copy->ID, 'raw' ),
			)
		);
	}

	/**
	 * The unstageable fields this request carries, if any.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return string[] Field names, empty when the request is stageable.
	 */
	private static function refused_fields( WP_REST_Request $request ): array {
		$sent = array_keys( $request->get_params() );

		return array_values( array_intersect( self::REFUSED_FIELDS, $sent ) );
	}

	/**
	 * Writes the editor's unsaved fields onto the staged copy.
	 *
	 * @param int             $staged_copy_id Staged copy post ID.
	 * @param WP_REST_Request $request        The request carrying the fields.
	 * @return bool True when the write succeeded, or there was nothing to write.
	 */
	private static function write_staged_content( int $staged_copy_id, WP_REST_Request $request ): bool {
		$update = array( 'ID' => $staged_copy_id );
		$map    = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		);

		foreach ( self::STAGED_FIELDS as $field ) {
			$value = $request->get_param( $field );

			if ( is_string( $value ) ) {
				$update[ $map[ $field ] ] = $value;
			}
		}

		// Staging with nothing unsaved is the ordinary case from the posts list.
		// The copy already holds the published content.
		if ( count( $update ) === 1 ) {
			return true;
		}

		return ! is_wp_error( wp_update_post( wp_slash( $update ), true ) );
	}
}
