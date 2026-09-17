<?php
/**
 * Field-lock tests for U5 (R29, R30), and for the taxonomy enumeration and
 * meta handling VIPPROD-755 added to it.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Merge;
use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Status;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proves nothing outside title, content, and excerpt can be staged.
 */
class Test_Field_Lock extends WP_UnitTestCase {

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Its staged copy.
	 *
	 * @var int
	 */
	private int $staged_copy_id;

	/**
	 * Stages a published post.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Launches in June.',
			)
		);

		$this->staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;
	}

	/**
	 * Sends an update to the staged copy.
	 *
	 * @param array $body Parameters to send.
	 * @return \WP_REST_Response The response.
	 */
	private function update_staged_copy( array $body ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->staged_copy_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The staged fields are writable, so ordinary editing still works.
	 */
	public function test_staged_fields_are_writable(): void {
		$response = $this->update_staged_copy(
			array(
				'title'   => 'Meridian Active, Fall collection',
				'content' => 'Launches October 3.',
				'excerpt' => 'Fall.',
			)
		);

		$this->assertFalse( $response->is_error(), 'A staged-field edit was refused.' );

		$staged_copy = get_post( $this->staged_copy_id );
		$this->assertSame( 'Meridian Active, Fall collection', $staged_copy->post_title );
		$this->assertStringContainsString( 'October 3', $staged_copy->post_content );
	}

	/**
	 * Covers AE12. Publishing a staged copy directly is refused.
	 *
	 * This is the Publish button in the editor: without this the staged content
	 * goes live in one click, bypassing the merge entirely.
	 */
	public function test_publishing_a_staged_copy_is_refused(): void {
		$response = $this->update_staged_copy( array( 'status' => 'publish' ) );

		$this->assertSame( 'swpub_field_locked', $response->as_error()->get_error_code() );
		$this->assertSame( Status::NAME, get_post( $this->staged_copy_id )->post_status );
	}

	/**
	 * Covers AE12. Changing the slug is refused, so KTD8's determinism holds.
	 */
	public function test_changing_the_staged_copy_slug_is_refused(): void {
		$response = $this->update_staged_copy( array( 'slug' => 'something-else' ) );

		$this->assertSame( 'swpub_field_locked', $response->as_error()->get_error_code() );
		$this->assertSame( Staged_Copy_Repository::staged_copy_slug( $this->live_id ), get_post( $this->staged_copy_id )->post_name );
	}

	/**
	 * Covers AE12. Changing terms is refused.
	 */
	public function test_changing_terms_is_refused(): void {
		$category = self::factory()->category->create( array( 'name' => 'Collections' ) );

		$response = $this->update_staged_copy( array( 'categories' => array( $category ) ) );

		$this->assertSame( 'swpub_field_locked', $response->as_error()->get_error_code() );
		$this->assertNotContains(
			$category,
			wp_get_object_terms( $this->staged_copy_id, 'category', array( 'fields' => 'ids' ) )
		);
	}

	/**
	 * Covers AE12. Changing the featured image is refused.
	 */
	public function test_changing_featured_media_is_refused(): void {
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$response = $this->update_staged_copy( array( 'featured_media' => $attachment ) );

		$this->assertSame( 'swpub_field_locked', $response->as_error()->get_error_code() );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $this->staged_copy_id ) );
	}

	/**
	 * Resending an unchanged locked value is a no-op, not an attempt.
	 *
	 * The editor resends unchanged attributes routinely; refusing those would
	 * make ordinary saves fail.
	 */
	public function test_unchanged_locked_values_are_not_refused(): void {
		$staged_copy = get_post( $this->staged_copy_id );

		$response = $this->update_staged_copy(
			array(
				'title'  => 'Still editable',
				'slug'   => $staged_copy->post_name,
				'status' => Status::NAME,
			)
		);

		$this->assertFalse( $response->is_error(), 'A no-op locked value was refused.' );
		$this->assertSame( 'Still editable', get_post( $this->staged_copy_id )->post_title );
	}

	/**
	 * The lock does not leak onto ordinary posts.
	 */
	public function test_ordinary_posts_are_unaffected(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $other );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'status', 'publish' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( 'publish', get_post( $other )->post_status );
	}

	/**
	 * The refusal names the field, so the editor can explain it.
	 */
	public function test_refusal_names_the_field(): void {
		$response = $this->update_staged_copy( array( 'status' => 'publish' ) );
		$data     = $response->as_error()->get_error_data();

		$this->assertSame( 'status', $data['field'] );
		$this->assertSame( 403, $data['status'] );
	}

	/**
	 * The refusal shape is contract, not an incident identifier (KTD35).
	 *
	 * The editor's request middleware keys on this code to recognise a click that
	 * reached core's publish control on a staged copy and turn it back into the
	 * staging flow. Renaming it, or moving the field name out of the error data,
	 * turns a recoverable round-trip into a dead end for every bundle that is not
	 * the one this server shipped with.
	 */
	public function test_the_locked_field_error_code_is_stable(): void {
		$response = $this->update_staged_copy( array( 'status' => 'publish' ) );

		$this->assertTrue( $response->is_error() );

		$error = $response->as_error();
		$data  = $error->get_error_data();

		$this->assertSame( 'swpub_field_locked', $error->get_error_code() );
		$this->assertSame( 403, $data['status'] );
		$this->assertSame( 'status', $data['field'] );
		$this->assertSame( Status::NAME, get_post_status( $this->staged_copy_id ), 'The staged copy was published anyway.' );
	}
}

/**
 * VIPPROD-755: a taxonomy the fork copies for fidelity is locked on the copy
 * exactly like `categories` and `tags`, whatever its own REST param is.
 *
 * Before this, `LOCKED_PARAMS` hardcoded `categories` and `tags` and nothing
 * else. A taxonomy registered under its own REST param name -- a site's own
 * classification taxonomy, or a plugin's -- was invisible to it: a staged
 * copy accepted a term change for it, and the merge then discarded it
 * silently, since the merge never writes terms at all. A separate class
 * rather than more methods on `Test_Field_Lock`, because registering and
 * unregistering a taxonomy around every one of that class's tests would cost
 * more than it proves.
 */
class Test_Field_Lock_Taxonomy_Enumeration extends WP_UnitTestCase {

	/**
	 * A taxonomy not named `category` or `post_tag`, registered for REST
	 * under its own name -- the shape of a site's own classification
	 * taxonomy, or a plugin's.
	 */
	private const TAXONOMY = 'swpub_collection';

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Its staged copy.
	 *
	 * @var int
	 */
	private int $staged_copy_id;

	/**
	 * Registers the taxonomy and stages a published post.
	 */
	public function set_up(): void {
		parent::set_up();

		register_taxonomy(
			self::TAXONOMY,
			'post',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => false,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Launches in June.',
			)
		);

		$this->staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;
	}

	/**
	 * Unregisters the taxonomy so it does not leak into later tests.
	 *
	 * This suite is not run with `WP_RUN_CORE_TESTS`, so core's own
	 * per-test taxonomy reset does not run between methods (see
	 * `WP_UnitTestCase_Base::set_up()`), and a taxonomy registered here
	 * would otherwise persist for the rest of the process.
	 */
	public function tear_down(): void {
		unregister_taxonomy( self::TAXONOMY );

		parent::tear_down();
	}

	/**
	 * Sends a REST update carrying a term for the custom taxonomy.
	 *
	 * @param int $term_id Term to set.
	 * @return \WP_REST_Response The response.
	 */
	private function set_collection_term( int $term_id ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->staged_copy_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( self::TAXONOMY, array( $term_id ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The invariant: a custom taxonomy is locked on a staged copy exactly
	 * like `categories` and `tags` are.
	 */
	public function test_a_custom_taxonomy_is_refused_on_a_staged_copy(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Autumn drop',
			)
		);

		$response = $this->set_collection_term( $term_id );

		$this->assertTrue(
			$response->is_error(),
			'A term change on an unlisted taxonomy was accepted by a staged copy; it should be refused like categories and tags.'
		);
		$this->assertSame( 'swpub_field_locked', $response->as_error()->get_error_code() );
		$this->assertNotContains(
			$term_id,
			wp_get_object_terms( $this->staged_copy_id, self::TAXONOMY, array( 'fields' => 'ids' ) )
		);
	}

	/**
	 * The consequence the invariant above prevents: before the fix, a term
	 * change the write guard let through onto the copy was then silently
	 * discarded at merge, because the merge never writes terms. With the
	 * write refused up front, there is no longer anything for a merge to
	 * lose -- the live post's own custom-taxonomy terms, copied at fork,
	 * pass through a merge untouched, exactly as `categories` already does
	 * (`test_a_merge_changes_nothing_but_the_staged_fields` in `test-merge.php`).
	 */
	public function test_a_refused_custom_taxonomy_write_leaves_nothing_for_a_merge_to_lose(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Winter drop',
			)
		);

		$response = $this->set_collection_term( $term_id );

		$this->assertTrue( $response->is_error(), 'The write should be refused; see test_a_custom_taxonomy_is_refused_on_a_staged_copy.' );
		$this->assertNotContains(
			$term_id,
			wp_get_object_terms( $this->staged_copy_id, self::TAXONOMY, array( 'fields' => 'ids' ) ),
			'The refused term must not be on the copy.'
		);

		$result = Merge::apply( $this->staged_copy_id );

		$this->assertIsArray( $result, 'The merge itself should succeed.' );
		$this->assertNotContains(
			$term_id,
			wp_get_object_terms( $this->live_id, self::TAXONOMY, array( 'fields' => 'ids' ) ),
			'A term the write guard refused must not appear on the live post after merge either.'
		);
	}
}

/**
 * VIPPROD-755: a locked `meta` key is stripped and warned about, not
 * refused wholesale.
 *
 * Before this, any non-empty `meta` array refused the entire REST write to a
 * staged copy -- title, content, and excerpt included -- because the
 * refusal happens in `rest_pre_insert_*`, before `wp_update_post()` runs at
 * all. A footnotes edit, or an unrelated editorial key an SEO plugin's own
 * panel writes, cost a real content edit sent in the same request.
 */
class Test_Field_Lock_Meta extends WP_UnitTestCase {

	/**
	 * A meta key with the shape of an SEO plugin's editorial field: its own
	 * name, registered for REST, no relation to anything this plugin knows.
	 */
	private const SEO_META_KEY = 'swpub_test_seo_description';

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Its staged copy.
	 *
	 * @var int
	 */
	private int $staged_copy_id;

	/**
	 * Registers a stand-in editorial meta key and stages a published post.
	 */
	public function set_up(): void {
		parent::set_up();

		register_post_meta(
			'post',
			self::SEO_META_KEY,
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Launches in June.',
			)
		);

		$this->staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;
	}

	/**
	 * Unregisters the stand-in key, for the same reason
	 * `Test_Field_Lock_Taxonomy_Enumeration` unregisters its taxonomy: this
	 * suite does not reset registrations between tests.
	 */
	public function tear_down(): void {
		unregister_post_meta( 'post', self::SEO_META_KEY );

		parent::tear_down();
	}

	/**
	 * Sends a REST update to the staged copy.
	 *
	 * @param array $body Parameters to send.
	 * @return \WP_REST_Response The response.
	 */
	private function update_staged_copy( array $body ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->staged_copy_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A write refused for another locked field is refused whole, meta
	 * included, and announces nothing about its meta.
	 *
	 * The strip runs only once the lock has passed. Otherwise a save carrying
	 * `status: publish` and a meta key would fire `swpub_meta_keys_dropped`
	 * and queue the header -- both of which say "the save went through
	 * without this key" -- and then be refused.
	 */
	public function test_a_write_refused_for_another_field_announces_no_dropped_meta(): void {
		$fired = 0;
		add_action(
			'swpub_meta_keys_dropped',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$response = $this->update_staged_copy(
			array(
				'status'  => 'publish',
				'content' => 'Launches October 3.',
				'meta'    => array( self::SEO_META_KEY => 'A description.' ),
			)
		);

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'swpub_field_locked', $response->as_error()->get_error_code() );
		$this->assertSame( 'status', $response->as_error()->get_error_data()['field'] );

		$this->assertSame( 0, $fired, 'A refused write must not announce dropped meta.' );
		$this->assertArrayNotHasKey( 'X-SWPub-Meta-Dropped', $response->get_headers() );

		$staged_copy = get_post( $this->staged_copy_id );
		$this->assertSame( Status::NAME, $staged_copy->post_status );
		$this->assertStringNotContainsString( 'October 3', $staged_copy->post_content, 'Refused whole: the content edit must not land either.' );
		$this->assertSame( '', get_post_meta( $this->staged_copy_id, self::SEO_META_KEY, true ) );
	}

	/**
	 * A stored array value does not trip the unchanged-value comparison.
	 *
	 * Core stores a serialized array as one meta row; reading it back
	 * through `get_post_meta( ..., true )` gives an array, and casting that
	 * to string is a PHP notice -- which this suite converts to an
	 * exception, and which VIP logs in production.
	 */
	public function test_a_stored_array_value_is_dropped_without_a_notice(): void {
		update_post_meta( $this->staged_copy_id, self::SEO_META_KEY, array( 'a', 'b' ) );

		$response = $this->update_staged_copy(
			array(
				'content' => 'Launches October 3.',
				'meta'    => array( self::SEO_META_KEY => 'A string now.' ),
			)
		);

		$this->assertFalse( $response->is_error() );
		$this->assertSame( self::SEO_META_KEY, $response->get_headers()['X-SWPub-Meta-Dropped'] );
		$this->assertSame( array( 'a', 'b' ), get_post_meta( $this->staged_copy_id, self::SEO_META_KEY, true ), 'The stored value must be untouched.' );
	}

	/**
	 * Core meta (`footnotes`, registered by core itself for any post type
	 * supporting the editor, custom fields, and revisions --
	 * `register_block_core_footnotes_post_meta()`): the content edit
	 * persists; the footnotes key -- outside the (currently empty)
	 * staged-meta set -- is stripped rather than written; a warning names
	 * it, both as the `swpub_meta_keys_dropped` action and as a response
	 * header a REST client can read without subscribing to anything.
	 */
	public function test_footnotes_meta_is_dropped_and_content_still_saves(): void {
		$dropped = array();
		add_action(
			'swpub_meta_keys_dropped',
			static function ( $staged_copy_id, $keys ) use ( &$dropped ) {
				$dropped[] = array( $staged_copy_id, $keys );
			},
			10,
			2
		);

		$response = $this->update_staged_copy(
			array(
				'content' => 'Launches in June, with a footnote reference.',
				'meta'    => array(
					'footnotes' => wp_json_encode(
						array(
							array(
								'id'      => 'fn1',
								'content' => 'The exact date has not been announced.',
							),
						)
					),
				),
			)
		);

		$this->assertFalse( $response->is_error(), 'A dropped meta key should not cost the rest of the save.' );
		$this->assertStringContainsString(
			'footnote reference',
			get_post( $this->staged_copy_id )->post_content,
			'The content edit sent alongside the dropped meta key must persist.'
		);
		$this->assertSame(
			'',
			get_post_meta( $this->staged_copy_id, 'footnotes', true ),
			'The staged-meta set is empty until VIPPROD-755 T4; footnotes must not have been written.'
		);

		$this->assertCount( 1, $dropped, 'The dropped-keys warning did not fire exactly once.' );
		$this->assertSame( $this->staged_copy_id, $dropped[0][0] );
		$this->assertSame( array( 'footnotes' ), $dropped[0][1] );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-SWPub-Meta-Dropped', $headers, 'No response header named the dropped key.' );
		$this->assertSame( 'footnotes', $headers['X-SWPub-Meta-Dropped'] );
	}

	/**
	 * Editorial plugin meta: the same shape as the footnotes case, for a key
	 * with no relationship to core at all -- the shape an SEO plugin's own
	 * field takes.
	 */
	public function test_an_editorial_meta_key_is_dropped_and_content_still_saves(): void {
		$dropped = array();
		add_action(
			'swpub_meta_keys_dropped',
			static function ( $staged_copy_id, $keys ) use ( &$dropped ) {
				$dropped[] = array( $staged_copy_id, $keys );
			},
			10,
			2
		);

		$response = $this->update_staged_copy(
			array(
				'content' => 'Launches October 3, newly written for the rewrite.',
				'meta'    => array(
					self::SEO_META_KEY => 'Meridian Active launches its fall collection October 3.',
				),
			)
		);

		$this->assertFalse( $response->is_error(), 'A dropped meta key should not cost the rest of the save.' );
		$this->assertStringContainsString(
			'newly written for the rewrite',
			get_post( $this->staged_copy_id )->post_content
		);
		$this->assertSame( '', get_post_meta( $this->staged_copy_id, self::SEO_META_KEY, true ) );

		$this->assertCount( 1, $dropped );
		$this->assertSame( array( self::SEO_META_KEY ), $dropped[0][1] );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-SWPub-Meta-Dropped', $headers );
		$this->assertSame( self::SEO_META_KEY, $headers['X-SWPub-Meta-Dropped'] );
	}
}
