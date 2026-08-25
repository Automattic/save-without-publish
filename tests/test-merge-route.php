<?php
/**
 * Merge route tests for U7.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Status;
use WP_Post;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proves the merge is reachable by the editor and by nobody else.
 */
class Test_Merge_Route extends WP_UnitTestCase {

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
	 * Stages a published post with a staged edit.
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

		wp_update_post(
			array(
				'ID'           => $this->staged_copy_id,
				'post_content' => 'Launches on October 3.',
			)
		);
	}

	/**
	 * Sends a merge request.
	 *
	 * @param int         $staged_copy_id Staged copy to merge.
	 * @param string|null $confirm   Drift confirmation.
	 * @return \WP_REST_Response The response.
	 */
	private function request( int $staged_copy_id, ?string $confirm = null ) {
		$request = new WP_REST_Request( 'POST', '/swpub/v1/merge/' . $staged_copy_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		if ( null !== $confirm ) {
			$request->set_param( 'confirm', $confirm );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The route is registered.
	 */
	public function test_the_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/swpub/v1/merge/(?P<id>[\d]+)', $routes );
	}

	/**
	 * A merge over REST lands the staged content and removes the staged copy.
	 */
	public function test_a_merge_applies_and_returns_where_to_go_next(): void {
		$response = $this->request( $this->staged_copy_id );

		$this->assertFalse( $response->is_error(), 'The merge was refused.' );

		$data = $response->get_data();

		$this->assertSame( $this->live_id, $data['liveId'] );
		$this->assertStringContainsString( 'post=' . $this->live_id, $data['editUrl'] );

		$this->assertSame( 'Launches on October 3.', get_post( $this->live_id )->post_content );
		$this->assertNull( get_post( $this->staged_copy_id ) );
	}

	/**
	 * A user who cannot edit the live post is refused.
	 */
	public function test_an_unauthorized_user_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->request( $this->staged_copy_id );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'swpub_forbidden', $response->as_error()->get_error_code() );
		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
	}

	/**
	 * A logged-out request is refused.
	 */
	public function test_a_logged_out_request_is_refused(): void {
		wp_set_current_user( 0 );

		$response = $this->request( $this->staged_copy_id );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'Launches in June.', get_post( $this->live_id )->post_content );
		$this->assertSame( Status::NAME, get_post( $this->staged_copy_id )->post_status );
	}

	/**
	 * Pointing the route at an ordinary post does nothing to it.
	 */
	public function test_the_route_refuses_a_post_that_is_not_a_staged_copy(): void {
		$response = $this->request( $this->live_id );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'swpub_not_staged', $response->as_error()->get_error_code() );
		$this->assertSame( 'publish', get_post( $this->live_id )->post_status );
	}

	/**
	 * Drift refuses the merge and hands back what the editor needs to confirm.
	 */
	public function test_drift_refuses_and_returns_the_confirmation_state(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Core overwrites post_modified_gmt on every update, so it cannot be set through the API.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_modified_gmt' => '2026-08-14 09:00:00' ),
			array( 'ID' => $this->live_id )
		);
		clean_post_cache( $this->live_id );

		$response = $this->request( $this->staged_copy_id );
		$error    = $response->as_error();

		$this->assertSame( 'swpub_drift', $error->get_error_code() );

		$data = $error->get_error_data();
		$this->assertSame( '2026-08-14 09:00:00', $data['live_modified'] );

		// The editor sends that exact value back to confirm.
		$confirmed = $this->request( $this->staged_copy_id, $data['live_modified'] );

		$this->assertFalse( $confirmed->is_error() );
		$this->assertSame( 'Launches on October 3.', get_post( $this->live_id )->post_content );
	}

	/**
	 * The kill switch closes the route.
	 */
	public function test_the_kill_switch_closes_the_route(): void {
		add_filter( 'swpub_is_enabled', '__return_false' );

		$response = $this->request( $this->staged_copy_id );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'swpub_disabled', $response->as_error()->get_error_code() );
		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
	}
}
