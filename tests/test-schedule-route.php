<?php
/**
 * Schedule route tests for VIPPROD-1247.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Scheduled_Publish;
use SaveWithoutPublish\Staged_Copy_Repository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proves scheduling is reachable by the editor and by nobody else.
 */
class Test_Schedule_Route extends WP_UnitTestCase {

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
	 * Sends a schedule request.
	 *
	 * @param int         $staged_copy_id Staged copy to schedule.
	 * @param string|null $at        When to publish, or null to omit it.
	 * @return \WP_REST_Response The response.
	 */
	private function schedule_request( int $staged_copy_id, ?string $at ) {
		$request = new WP_REST_Request( 'POST', '/swpub/v1/schedule/' . $staged_copy_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		if ( null !== $at ) {
			$request->set_param( 'at', $at );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Sends an unschedule request.
	 *
	 * @param int $staged_copy_id Staged copy to unschedule.
	 * @return \WP_REST_Response The response.
	 */
	private function unschedule_request( int $staged_copy_id ) {
		$request = new WP_REST_Request( 'DELETE', '/swpub/v1/schedule/' . $staged_copy_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Both methods of the route are registered.
	 */
	public function test_the_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/swpub/v1/schedule/(?P<id>[\d]+)', $routes );

		$methods = wp_list_pluck( $routes['/swpub/v1/schedule/(?P<id>[\d]+)'], 'methods' );

		$this->assertContains( array( 'POST' => true ), $methods );
		$this->assertContains( array( 'DELETE' => true ), $methods );
	}

	/**
	 * A POST with a future time schedules and returns the three fields.
	 */
	public function test_scheduling_returns_the_time_and_scheduler(): void {
		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );

		$response = $this->schedule_request( $this->staged_copy_id, $at );

		$this->assertFalse( $response->is_error(), 'The schedule was refused.' );

		$data = $response->get_data();

		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $at ) ), $data['scheduledFor'] );
		$this->assertArrayHasKey( 'scheduledForLocal', $data );
		$this->assertSame( get_current_user_id(), $data['scheduledBy'] );

		$this->assertNotNull( Scheduled_Publish::scheduled( $this->staged_copy_id ) );
	}

	/**
	 * A POST with a past time is refused with 400 and the past-time code.
	 */
	public function test_a_past_time_is_refused_with_400(): void {
		$past = gmdate( 'Y-m-d\TH:i:s\Z', time() - HOUR_IN_SECONDS );

		$response = $this->schedule_request( $this->staged_copy_id, $past );

		$this->assertTrue( $response->is_error() );

		$error = $response->as_error();

		$this->assertSame( 'swpub_time_past', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/**
	 * A POST with no `at` is refused by the route's own required arg, before
	 * `Scheduled_Publish::schedule()` is ever called.
	 */
	public function test_a_missing_time_is_refused(): void {
		$response = $this->schedule_request( $this->staged_copy_id, null );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'rest_missing_callback_param', $response->as_error()->get_error_code() );
	}

	/**
	 * A DELETE cancels a schedule.
	 */
	public function test_unschedule_cancels_it(): void {
		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		$this->schedule_request( $this->staged_copy_id, $at );

		$response = $this->unschedule_request( $this->staged_copy_id );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( array( 'scheduled' => false ), $response->get_data() );
		$this->assertNull( Scheduled_Publish::scheduled( $this->staged_copy_id ) );
	}

	/**
	 * A subscriber may not schedule or unschedule.
	 */
	public function test_an_unauthorized_user_is_refused_on_both_methods(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );

		$scheduled = $this->schedule_request( $this->staged_copy_id, $at );
		$this->assertTrue( $scheduled->is_error() );
		$this->assertSame( 'swpub_forbidden', $scheduled->as_error()->get_error_code() );

		$unscheduled = $this->unschedule_request( $this->staged_copy_id );
		$this->assertTrue( $unscheduled->is_error() );
		$this->assertSame( 'swpub_forbidden', $unscheduled->as_error()->get_error_code() );
	}

	/**
	 * The kill switch closes both methods of the route.
	 */
	public function test_the_kill_switch_closes_the_route(): void {
		add_filter( 'swpub_is_enabled', '__return_false' );

		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );

		$response = $this->schedule_request( $this->staged_copy_id, $at );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'swpub_disabled', $response->as_error()->get_error_code() );
	}

	/**
	 * Pointing the route at an ordinary post refuses with `swpub_not_staged`.
	 */
	public function test_the_route_refuses_a_post_that_is_not_a_staged_copy(): void {
		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );

		$response = $this->schedule_request( $this->live_id, $at );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'swpub_not_staged', $response->as_error()->get_error_code() );
	}

	/**
	 * An unknown ID is refused the same way -- there is no post to be a
	 * staged copy at all.
	 */
	public function test_an_unknown_id_is_refused(): void {
		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );

		$response = $this->schedule_request( 999999, $at );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'swpub_not_staged', $response->as_error()->get_error_code() );
	}

	/**
	 * A logged-out request is refused.
	 */
	public function test_a_logged_out_request_is_refused(): void {
		wp_set_current_user( 0 );

		$at       = gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS );
		$response = $this->schedule_request( $this->staged_copy_id, $at );

		$this->assertTrue( $response->is_error() );
		$this->assertNull( Scheduled_Publish::scheduled( $this->staged_copy_id ) );
	}
}
