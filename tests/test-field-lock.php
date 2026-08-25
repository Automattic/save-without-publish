<?php
/**
 * Field-lock tests for U5 (R29, R30).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

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
