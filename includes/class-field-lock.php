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
use WP_REST_Response;

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
 *
 * ## `meta` is the one exception, and it is not in the list above (VIPPROD-755)
 *
 * Every other locked field refuses the whole write, deliberately (the
 * doc-block above). `meta` used to be enforced the same way -- any non-empty
 * `meta` array refused the entire request, title and content included, since
 * the refusal happens in `rest_pre_insert_*`, before `wp_update_post()` runs
 * at all. That was too blunt for what `meta` actually is: not one field with
 * one owner, but a bag of them, most written by something with nothing to do
 * with staging -- an SEO plugin's own panel, a site's own custom field, core's
 * own footnotes. A staged copy's every ordinary save carries whatever meta is
 * dirty alongside the words, and refusing the whole thing for a key nobody
 * asked to stage cost the words too.
 *
 * So `meta` is handled once the lock has passed, by `strip_unstageable_meta()`:
 * a request's `meta` array is narrowed to the staged set (`STAGED_META_KEYS`,
 * empty until VIPPROD-755 T4 gives it real members) and whatever was removed is
 * named on `swpub_meta_keys_dropped` and the `X-SWPub-Meta-Dropped` response
 * header, rather than costing the rest of the save. A write that is refused
 * for some other locked field is refused whole, meta included, and announces
 * nothing about its meta -- there was no save for the key to be missing from.
 */
final class Field_Lock {

	/**
	 * Request parameters that may not change a staged copy.
	 *
	 * Each maps to the post field or concept it would alter. `status` is the
	 * dangerous one — it is what the editor's Publish button sends, and honouring
	 * it would publish staged content directly.
	 *
	 * `categories` and `tags` are not here: they are two entries of
	 * `taxonomy_params()`, the same enumeration that decides what
	 * `Staged_Copy_Repository::copy_locked_fields()` copies at fork, so a
	 * taxonomy this list once had no opinion about -- a site's own, or a
	 * plugin's -- is locked the same way core's two are, whatever its own
	 * `rest_base` happens to be (VIPPROD-755). `meta` is not here either;
	 * see the class doc-block.
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
	);

	/**
	 * Meta keys a staged copy may keep from a request's `meta` array.
	 *
	 * Empty for now (VIPPROD-755 Phase 0): no meta key is part of the staged
	 * field set, so every key arriving in `meta` is dropped, named, and
	 * warned about rather than being written or costing the rest of the
	 * save. A real allowlist -- core's revisioned meta by default, a site's
	 * own editorial keys by filter -- is VIPPROD-755 T4's job. Widening this
	 * constant before T4 lands would widen the staged field set without the
	 * merge, the drift check, or the review surface knowing anything changed,
	 * which is exactly what this phase of the ticket was told not to do.
	 *
	 * @var string[]
	 */
	private const STAGED_META_KEYS = array();

	/**
	 * Dropped meta keys this request stripped, keyed by staged copy ID.
	 *
	 * Request-scoped, on the same reasoning `Write_Guard`'s own request-scoped
	 * arrays give (KTD29/KTD30): the question the response header answers is
	 * "did this request drop something", not "does this post have dropped
	 * meta on file anywhere", which nothing persists.
	 *
	 * @var array<int, string[]>
	 */
	private static array $dropped_meta = array();

	/**
	 * Registers hooks.
	 *
	 * Attached from both hooks for the same reason as the fork filter: missing
	 * the attachment would be silent, and here it would mean a staged copy could be
	 * published directly.
	 *
	 * `rest_request_after_callbacks` is registered directly, not deferred: it
	 * is a generic filter tag, not one scoped to a post type the REST server
	 * has to have finished registering, so there is no ordering hazard the
	 * other two hooks exist to cover. It is `Write_Guard`'s own choice for
	 * the same reason it is this one's: `rest_post_dispatch` only fires
	 * inside `WP_REST_Server::serve_request()`, which a real HTTP request
	 * takes and `rest_get_server()->dispatch()` -- what this plugin's own
	 * test suite, and any other code calling the REST API in-process, calls
	 * instead -- does not. A header that only appears over real HTTP is a
	 * header this suite cannot pin.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'attach_filters' ), PHP_INT_MAX );
		add_action( 'rest_api_init', array( __CLASS__, 'attach_filters' ) );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'add_dropped_meta_header' ), 10, 3 );
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

		/*
		 * After the lock, not before. A write carrying a locked field and meta
		 * together is refused whole, like any mixed write (readme, "A write
		 * carrying both kinds is refused whole rather than half-applied"), and
		 * nothing about it is announced: the event and the header below both
		 * say "the save went through without this key", which is only true of
		 * a save that went through.
		 */
		self::strip_unstageable_meta( $staged_copy, $request );

		return $prepared_post;
	}

	/**
	 * The first locked parameter the request would actually change, or null.
	 *
	 * A parameter matching the staged copy's current value is a no-op, not an attempt:
	 * the editor resends unchanged attributes routinely, and refusing those would
	 * make ordinary saves fail.
	 *
	 * The static list is checked first, then every taxonomy of the copy's own
	 * post type (VIPPROD-755) -- `categories` and `tags` among them, since
	 * `taxonomy_params()` returns core's two taxonomies by their REST params
	 * like any other. One loop rather than two lists kept in step is the
	 * point: a taxonomy the fork copies for fidelity is a taxonomy this
	 * refuses to let the copy change, whatever registered it.
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

		foreach ( self::taxonomy_params( $staged_copy->post_type ) as $param => $taxonomy ) {
			if ( ! isset( $request[ $param ] ) ) {
				continue;
			}

			if ( ! self::terms_would_change( $staged_copy, $taxonomy, $request[ $param ] ) ) {
				continue;
			}

			return $param;
		}

		return null;
	}

	/**
	 * Every REST-exposed taxonomy of a post type, keyed by the REST param
	 * name each is exposed under (VIPPROD-755).
	 *
	 * Reads `get_object_taxonomies()` the same way
	 * `Staged_Copy_Repository::copy_locked_fields()` does to decide what the
	 * fork copies for fidelity, so the copy's write guard and the fork's own
	 * idea of "every taxonomy of this type" cannot drift apart: whatever the
	 * fork copied, this locks. A taxonomy not registered for REST has no
	 * param here and none to lock, because core never gave it a request
	 * parameter to lock either.
	 *
	 * @param string $post_type Post type name.
	 * @return array<string, string> REST param name => taxonomy name.
	 */
	private static function taxonomy_params( string $post_type ): array {
		$params = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->show_in_rest ) ) {
				continue;
			}

			$param = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;

			$params[ $param ] = $taxonomy->name;
		}

		return $params;
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

			default:
				// Everything else is locked outright; presence is an attempt.
				return true;
		}
	}

	/**
	 * Whether a submitted term set differs from a taxonomy's current one on
	 * the staged copy.
	 *
	 * @param WP_Post $staged_copy The staged copy.
	 * @param string  $taxonomy    Taxonomy name (not its REST param).
	 * @param mixed   $value       Submitted term IDs.
	 * @return bool True when the term set would change.
	 */
	private static function terms_would_change( WP_Post $staged_copy, string $taxonomy, $value ): bool {
		if ( ! is_array( $value ) ) {
			return true;
		}

		$current = wp_get_object_terms( $staged_copy->ID, $taxonomy, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $current ) ) {
			return true;
		}

		$submitted = array_map( 'intval', $value );
		$current   = array_map( 'intval', $current );

		sort( $submitted );
		sort( $current );

		return $submitted !== $current;
	}

	/**
	 * Narrows a request's `meta` array to the staged set, rather than
	 * refusing the whole write for carrying a key outside it (VIPPROD-755).
	 *
	 * Runs after `attempted_locked_change()` has passed, and only then: `meta`
	 * is no longer in `LOCKED_PARAMS`, so this is the whole of its
	 * enforcement, and a write refused for another field never gets here.
	 * Mutates `$request` in place -- the same
	 * object the REST controller reads `meta` from after `wp_update_post()`
	 * runs -- so the controller's own write proceeds with the narrowed array
	 * and nothing here has to duplicate `WP_REST_Meta_Fields::update_value()`.
	 *
	 * A key matching the staged copy's current value is not "dropped": it was
	 * never going to be written differently, so naming it in a warning would
	 * describe a change that was not attempted. That mirrors `would_change()`
	 * -- an unchanged locked field is not an attempt either -- and keeps this
	 * from warning about a value the editor's own save-without-changes
	 * resends every time.
	 *
	 * @param WP_Post         $staged_copy The staged copy being written to.
	 * @param WP_REST_Request $request     The request, mutated in place.
	 * @return void
	 */
	private static function strip_unstageable_meta( WP_Post $staged_copy, WP_REST_Request $request ): void {
		$meta = $request->get_param( 'meta' );

		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return;
		}

		$dropped = array();

		foreach ( $meta as $key => $value ) {
			if ( in_array( $key, self::STAGED_META_KEYS, true ) ) {
				continue;
			}

			$current = get_post_meta( $staged_copy->ID, (string) $key, true );

			// Only two scalars compare. A stored array, or a submitted one,
			// is a change -- and casting an array to string is a notice.
			if ( is_scalar( $value ) && is_scalar( $current ) && (string) $value === (string) $current ) {
				continue;
			}

			$dropped[] = (string) $key;
		}

		if ( empty( $dropped ) ) {
			return;
		}

		$request->set_param(
			'meta',
			array_intersect_key( $meta, array_flip( self::STAGED_META_KEYS ) )
		);

		self::$dropped_meta[ $staged_copy->ID ] = $dropped;

		Events::meta_keys_dropped( $staged_copy->ID, $dropped );
	}

	/**
	 * Names, on the response, whatever meta this request dropped.
	 *
	 * `rest_request_after_callbacks` rather than something scoped to the
	 * posts controller, because a dropped key is decided in
	 * `rest_pre_insert_*`, long before the response is assembled, and this is
	 * the first later hook that still has both the response object and the
	 * original request -- and, unlike `rest_post_dispatch`, one that fires on
	 * `rest_get_server()->dispatch()` as well as a real HTTP request (see the
	 * doc-block on `init()`). Cheapest check first (`self::$dropped_meta` is
	 * empty on every request that dropped nothing, which is nearly all of
	 * them), since this fires on every REST response site-wide, not only a
	 * staged copy's.
	 *
	 * @param mixed           $response The response, or an error, so far.
	 * @param array           $handler  The matched route handler.
	 * @param WP_REST_Request $request  The original request.
	 * @return mixed The response, with the header added when this request dropped meta.
	 */
	public static function add_dropped_meta_header( $response, $handler, $request ) {
		if ( empty( self::$dropped_meta ) || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$staged_copy_id = (int) $request['id'];

		if ( $staged_copy_id <= 0 || ! isset( self::$dropped_meta[ $staged_copy_id ] ) ) {
			return $response;
		}

		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'X-SWPub-Meta-Dropped', implode( ',', self::$dropped_meta[ $staged_copy_id ] ) );
		}

		unset( self::$dropped_meta[ $staged_copy_id ] );

		return $response;
	}
}
