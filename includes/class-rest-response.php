<?php
/**
 * What a REST response says about a write the data layer staged.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `swpub` response field, for the clients that are not the editor (R48).
 *
 * A token client's write to a post with a staged copy already succeeds and
 * already returns 200: the guard contains the write, the live row comes back
 * unchanged, and the controller has an ordinary successful update to report. What
 * it does not have is any way to say what happened. The client sent new content,
 * was told the write succeeded, and was handed its own values back replaced by
 * the published ones -- correct in every byte and unreadable as anything but a
 * bug. This field is the sentence that was missing.
 *
 * It sits in its own file rather than in `Write_Guard` on the split KD14 draws:
 * the capability decides whether a write stages, and the transport decides only
 * how that outcome is presented. `Admin_Surfaces` is the same seam for Quick Edit
 * and the classic editor. Keeping the presentation out of the guard is what stops
 * the data layer growing a route's schema, and it is why the guard's file knows
 * nothing about REST beyond the request it already had to remember.
 *
 * Registered at file load, like `Fork` and `Stage_Route`, because its only hook is
 * `rest_api_init` and that hook fires when the REST server is built, which can be
 * before `init`. The cost of missing it is smaller here than there -- a missing
 * field rather than a silent publish -- but the fix is the same one line.
 */
final class Rest_Response {

	/**
	 * The response field's name.
	 *
	 * Prefixed with nothing further: `swpub` is the prefix, and this is the one
	 * key the plugin adds to another controller's response shape.
	 */
	public const FIELD = 'swpub';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_field' ) );
	}

	/**
	 * Adds `swpub` to the staged post types' schemas.
	 *
	 * On `rest_api_init` rather than the plugin's own early `init`, for the reason
	 * `Fork::attach_filters()` gives: the registration is per post type and most
	 * plugins register theirs at `init` priority 10 or later, so attaching early
	 * would silently miss them.
	 *
	 * Registered whether or not staging is enabled. The kill switch stops writes
	 * from staging, which makes the field answer null; it is not a reason for a
	 * documented part of the route's schema to appear and disappear underneath a
	 * client that reads OPTIONS once.
	 *
	 * @return void
	 */
	public static function register_field(): void {
		register_rest_field(
			staged_post_types(),
			self::FIELD,
			array(
				'get_callback' => array( __CLASS__, 'account' ),
				'schema'       => self::schema(),
			)
		);
	}

	/**
	 * What this request staged for this post, or null (KTD32).
	 *
	 * Reads the guard's request-scoped engagement record, never the pointer meta,
	 * and the distinction is the whole design. The meta answers "does this post
	 * have a staged copy", which is true of every read of that post by anybody
	 * forever; the field answers "did this request stage something", which is true
	 * of exactly one response. Backing it with meta would have every GET tell every
	 * client that it had just staged a change it never sent.
	 *
	 * Null rather than an absent key, and null rather than `staged: false`: the
	 * field is present on every response so a client can read it without knowing
	 * the plugin is installed, and a null says "nothing here" without inviting the
	 * reading that something was considered and declined.
	 *
	 * @param mixed           $prepared     The response data prepared so far.
	 * @param string          $field_name   The field being populated.
	 * @param WP_REST_Request $request      The request.
	 * @param string          $object_type  The object type being prepared.
	 * @return array{staged: bool, staged_copy_id: int, edit_url: string}|null The account, or null.
	 */
	public static function account( $prepared, $field_name, $request, $object_type ): ?array {
		$live_id = is_array( $prepared ) && isset( $prepared['id'] ) ? (int) $prepared['id'] : 0;

		if ( $live_id <= 0 ) {
			return null;
		}

		$staged_copy_id = Write_Guard::staged_copy_engaged( $live_id );

		if ( $staged_copy_id <= 0 ) {
			return null;
		}

		return array(
			'staged'         => true,
			'staged_copy_id' => $staged_copy_id,
			'edit_url'       => (string) get_edit_post_link( $staged_copy_id, 'raw' ),
		);
	}

	/**
	 * The field's schema.
	 *
	 * Written out rather than left off so the field appears in the route's OPTIONS
	 * output. An undocumented extra key in a response is something a client
	 * discovers by accident and cannot rely on; this is a contract, and R48 says
	 * so in those words.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private static function schema(): array {
		return array(
			'description' => __( 'What this request staged for this post, or null when it staged nothing. A write whose staged fields were contained reports success here rather than as an error.', 'save-without-publish' ),
			'type'        => array( 'object', 'null' ),
			'context'     => array( 'view', 'edit' ),
			'readonly'    => true,
			'properties'  => array(
				'staged'         => array(
					'description' => __( 'Whether this request staged its change instead of applying it to the published post.', 'save-without-publish' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'staged_copy_id' => array(
					'description' => __( 'ID of the staged copy the change was written to.', 'save-without-publish' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'edit_url'       => array(
					'description' => __( 'Edit screen for the staged copy. Empty when the acting user cannot edit it.', 'save-without-publish' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);
	}
}
