<?php
/**
 * Server-side enforcement of the staged field set.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Error;
use WP_Post;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses any change to a staged copy outside title, content, and excerpt (R29).
 *
 * The client lock is a courtesy; this is the boundary. A crafted request, an
 * older editor build, or the Publish button reaching a staged copy all arrive here,
 * and the answer has to be the same for each.
 *
 * Refusing rather than silently stripping is deliberate: AE12 says the change is
 * refused, and an editor whose category edit vanished without comment would
 * reasonably call that data loss.
 *
 * ## The `swpub_field_locked` error code is public contract
 *
 * Every refusal from this class answers with the error code `swpub_field_locked`,
 * HTTP 403, and a `field` data key naming the first locked parameter the request
 * would have changed. That triple is API, not an incident identifier, and it is
 * documented here the way `swpub_staged` is (KTD35):
 *
 * - The code is what the editor's own request middleware keys on to recognise a
 *   click that reached core's publish control on a staged copy and turn it back
 *   into the staging flow, so renaming it turns a recoverable round-trip into a
 *   dead end for anyone running an older or newer bundle than the server.
 * - `data.field` is the parameter name as REST spells it (`status`, `categories`,
 *   `featured_media`), so a client can name the field it has to put back.
 *
 * It will not be renamed. Integrations may match on it.
 */
final class Field_Lock {

	/**
	 * Request parameters that may not change a staged copy.
	 *
	 * Each maps to the post field or concept it would alter. `status` is the
	 * dangerous one — it is what the editor's Publish button sends, and honouring
	 * it would publish staged content directly.
	 */
	private const LOCKED_PARAMS = array(
		'status',
		'slug',
		'date',
		'date_gmt',
		'author',
		'password',
		'parent',
		'menu_order',
		'featured_media',
		'sticky',
		'template',
		'format',
		'comment_status',
		'ping_status',
		'categories',
		'tags',
		'meta',
	);

	/**
	 * Registers hooks.
	 *
	 * Attached from both hooks for the same reason as the fork filter: missing
	 * the attachment would be silent, and here it would mean a staged copy could be
	 * published directly.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'attach_filters' ), PHP_INT_MAX );
		add_action( 'rest_api_init', array( __CLASS__, 'attach_filters' ) );
	}

	/**
	 * Attaches the per-post-type filters.
	 *
	 * @return void
	 */
	public static function attach_filters(): void {
		foreach ( staged_post_types() as $post_type ) {
			add_filter( "rest_pre_insert_{$post_type}", array( __CLASS__, 'refuse_locked_changes' ), 20, 2 );
		}
	}

	/**
	 * Refuses a write that would change a locked field on a staged copy.
	 *
	 * The refusal shape is contract: code `swpub_field_locked`, status 403, and a
	 * `field` key naming the parameter. See the class doc-block.
	 *
	 * @param object          $prepared_post The post about to be written.
	 * @param WP_REST_Request $request       The request.
	 * @return object|WP_Error The post, or an error naming the refused field.
	 */
	public static function refuse_locked_changes( $prepared_post, $request ) {
		if ( ! is_object( $prepared_post ) || empty( $prepared_post->ID ) ) {
			return $prepared_post;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $prepared_post;
		}

		$staged_copy = get_post( (int) $prepared_post->ID );

		if ( ! $staged_copy instanceof WP_Post || ! Status::is_staged( $staged_copy ) ) {
			return $prepared_post;
		}

		$attempted = self::attempted_locked_change( $staged_copy, $request );

		if ( null !== $attempted ) {
			return new WP_Error(
				'swpub_field_locked',
				sprintf(
					/* translators: %s: the name of the field the editor tried to change. */
					__( 'Only the title, content, and excerpt can be staged. Change %s on the published post instead.', 'save-without-publish' ),
					$attempted
				),
				array(
					'status' => 403,
					'field'  => $attempted,
				)
			);
		}

		return $prepared_post;
	}

	/**
	 * The first locked parameter the request would actually change, or null.
	 *
	 * A parameter matching the staged copy's current value is a no-op, not an attempt:
	 * the editor resends unchanged attributes routinely, and refusing those would
	 * make ordinary saves fail.
	 *
	 * @param WP_Post         $staged_copy  The staged copy being written to.
	 * @param WP_REST_Request $request The request.
	 * @return string|null The parameter name, or null when nothing locked changes.
	 */
	private static function attempted_locked_change( WP_Post $staged_copy, WP_REST_Request $request ): ?string {
		foreach ( self::LOCKED_PARAMS as $param ) {
			if ( ! isset( $request[ $param ] ) ) {
				continue;
			}

			if ( ! self::would_change( $staged_copy, $param, $request[ $param ] ) ) {
				continue;
			}

			return $param;
		}

		return null;
	}

	/**
	 * Whether a submitted value differs from the staged copy's current state.
	 *
	 * @param WP_Post $staged_copy The staged copy.
	 * @param string  $param  Parameter name.
	 * @param mixed   $value  Submitted value.
	 * @return bool True when the value would change something.
	 */
	private static function would_change( WP_Post $staged_copy, string $param, $value ): bool {
		switch ( $param ) {
			case 'status':
				return Status::NAME !== (string) $value;

			case 'slug':
				return (string) $value !== $staged_copy->post_name;

			case 'author':
				return (int) $value !== (int) $staged_copy->post_author;

			case 'parent':
				return (int) $value !== (int) $staged_copy->post_parent;

			case 'menu_order':
				return (int) $value !== (int) $staged_copy->menu_order;

			case 'comment_status':
				return (string) $value !== $staged_copy->comment_status;

			case 'ping_status':
				return (string) $value !== $staged_copy->ping_status;

			case 'featured_media':
				return (int) $value !== (int) get_post_thumbnail_id( $staged_copy->ID );

			case 'meta':
				return is_array( $value ) && array() !== $value;

			case 'categories':
			case 'tags':
				return self::terms_would_change( $staged_copy, $param, $value );

			default:
				// Everything else is locked outright; presence is an attempt.
				return true;
		}
	}

	/**
	 * Whether a submitted term set differs from the staged copy's current one.
	 *
	 * @param WP_Post $staged_copy The staged copy.
	 * @param string  $param  `categories` or `tags`.
	 * @param mixed   $value  Submitted term IDs.
	 * @return bool True when the term set would change.
	 */
	private static function terms_would_change( WP_Post $staged_copy, string $param, $value ): bool {
		if ( ! is_array( $value ) ) {
			return true;
		}

		$taxonomy = 'categories' === $param ? 'category' : 'post_tag';
		$current  = wp_get_object_terms( $staged_copy->ID, $taxonomy, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $current ) ) {
			return true;
		}

		$submitted = array_map( 'intval', $value );
		$current   = array_map( 'intval', $current );

		sort( $submitted );
		sort( $current );

		return $submitted !== $current;
	}
}
