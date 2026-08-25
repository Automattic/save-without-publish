<?php
/**
 * The machine-client REST response for U4 (R48, R50).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use ReflectionProperty;
use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Write_Guard;
use WP_Post;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A staged write over token REST reports success, and says where the change went.
 *
 * The guarantee under test is not "the write was contained" -- `tests/test-write-guard.php`
 * owns that -- but what the client is told about it. A write that succeeded must
 * not arrive as an error, the body must be the published post's true state rather
 * than the values the client sent, and the `swpub` field must name the staged copy
 * for exactly the request that staged something and no other.
 */
class Test_Rest_Response extends WP_UnitTestCase {

	/**
	 * The live post's stored content, carrying a quote and a backslash.
	 */
	private const LIVE_CONTENT = 'Launches in June. Nadia said "hold" \\ until then.';

	/**
	 * The content an incoming write carries.
	 */
	private const INCOMING_CONTENT = 'Launches in July. Nadia said "ship it" \\ now.';

	/**
	 * The live post's stored title.
	 */
	private const LIVE_TITLE = 'Meridian Active, Summer collection';

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * An editor whose writes stage, standing in for a token client's user.
	 *
	 * @var int
	 */
	private int $editor_id;

	/**
	 * Engagements captured during a test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $engagements = array();

	/**
	 * Publishes a post and subscribes to the engagement event.
	 */
	public function set_up(): void {
		parent::set_up();

		$editor = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$editor->add_cap( 'publish_posts', false );
		$this->editor_id = $editor->ID;

		wp_set_current_user( $this->editor_id );

		$this->live_id = self::factory()->post->create(
			array(
				'post_author'  => $this->editor_id,
				'post_status'  => 'publish',
				'post_title'   => self::LIVE_TITLE,
				'post_content' => wp_slash( self::LIVE_CONTENT ),
				'post_excerpt' => 'A short season.',
			)
		);

		$this->engagements = array();

		add_action(
			'swpub_write_staged',
			function ( $staged_copy_id, $live_id, $channel, $user_id ): void {
				$this->engagements[] = array(
					'staged_copy_id' => (int) $staged_copy_id,
					'live_id'        => (int) $live_id,
					'channel'        => (string) $channel,
					'user_id'        => (int) $user_id,
				);
			},
			10,
			4
		);
	}

	/**
	 * Establishes a staged copy for the live post.
	 *
	 * @return WP_Post The staged copy.
	 */
	private function stage(): WP_Post {
		$staged_copy = Staged_Copy_Repository::establish( get_post( $this->live_id ) );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );

		$this->engagements = array();

		return $staged_copy;
	}

	/**
	 * Dispatches a REST write, optionally cookie-authenticated.
	 *
	 * Without the nonce this is what an application password or OAuth client looks
	 * like to the plugin: a REST write with no editor behind it.
	 *
	 * @param array $body       Fields to send.
	 * @param bool  $with_nonce Whether to send a valid `wp_rest` nonce.
	 * @return \WP_REST_Response The response.
	 */
	private function rest_write( array $body, bool $with_nonce = false ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->live_id );

		if ( $with_nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}

		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Reads the post back over REST, in the context a token client would.
	 *
	 * @return \WP_REST_Response The response.
	 */
	private function rest_read() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->live_id );

		$request->set_param( 'context', 'edit' );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Ends the request, the way the end of a PHP process would.
	 *
	 * The engagement record is a private static scoped to one request, and a test
	 * process runs every request of a test in the same one. Reflection clears
	 * exactly what a new process clears and nothing else, so the read that follows
	 * is genuinely a second request rather than a continuation of the first. A
	 * production method for clearing it would be an API nothing in the plugin
	 * needs, and a test that skipped the reset would pass whatever the field read.
	 *
	 * @return void
	 */
	private function end_request(): void {
		$engaged = new ReflectionProperty( Write_Guard::class, 'engaged' );

		$engaged->setValue( null, array() );

		$this->assertSame( 0, Write_Guard::staged_copy_engaged( $this->live_id ) );
	}

	/**
	 * A stored field, read past the cache.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name.
	 * @return string The stored value.
	 */
	private function stored( int $post_id, string $field ): string {
		clean_post_cache( $post_id );

		return (string) get_post_field( $field, $post_id );
	}

	/**
	 * Covers AE24, response half. A staged write is a success, and names the copy.
	 *
	 * The 200 and the live-state body are already true once the guard diverts the
	 * write, because the divert leaves the controller with an ordinary successful
	 * update of an unchanged row. What is asserted here as well is the part that
	 * makes it legible: without `swpub`, a client is told its write succeeded and
	 * handed back its own values unchanged, with nothing anywhere saying why.
	 *
	 * On a first write with the seam closed, which is the one shape a token client
	 * can still stage in. R55 refuses a token write onto a post that already has a
	 * copy, so the case this used to assert is now a 409 and is covered in
	 * `Test_Write_Guard`. Closing the seam is what a site does when it wants
	 * programmatic writes staged rather than published, and it is the only way a
	 * token client reaches the divert at all.
	 */
	public function test_a_token_write_returns_the_live_state_and_names_the_staged_copy(): void {
		add_filter( 'swpub_enforce_programmatic_first_save', '__return_true' );

		$response = $this->rest_write( array( 'content' => self::INCOMING_CONTENT ) );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( self::LIVE_CONTENT, $data['content']['raw'] );
		$this->assertSame( self::LIVE_TITLE, $data['title']['raw'] );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );

		$this->assertIsArray( $data['swpub'] );
		$this->assertTrue( $data['swpub']['staged'] );
		$this->assertSame( $staged_copy->ID, $data['swpub']['staged_copy_id'] );
		$this->assertSame(
			(string) get_edit_post_link( $staged_copy->ID, 'raw' ),
			$data['swpub']['edit_url']
		);
		$this->assertNotSame( '', $data['swpub']['edit_url'] );

		$this->assertCount( 1, $this->engagements );
		$this->assertSame( $staged_copy->ID, $this->engagements[0]['staged_copy_id'] );
		$this->assertSame( $this->live_id, $this->engagements[0]['live_id'] );
		$this->assertSame( 'rest-token', $this->engagements[0]['channel'] );
		$this->assertSame( $this->editor_id, $this->engagements[0]['user_id'] );
	}

	/**
	 * A later read claims nothing, which is what proves the field is not a meta read.
	 *
	 * The post still has a staged copy here, and the meta saying so is still on it.
	 * The field answers "did this request stage something", so the only honest
	 * answer to a plain read is null -- and a field backed by meta would answer
	 * true to every GET any client ever makes on this post.
	 */
	public function test_a_later_read_does_not_claim_to_have_staged_anything(): void {
		$staged_copy = $this->stage();

		$this->rest_write( array( 'content' => self::INCOMING_CONTENT ) );

		$this->end_request();

		$response = $this->rest_read();

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'swpub', $data );
		$this->assertNull( $data['swpub'] );
		$this->assertInstanceOf( WP_Post::class, Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame(
			$staged_copy->ID,
			(int) get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true )
		);
	}

	/**
	 * A write that stages nothing says so, and lands live exactly as core (R42).
	 */
	public function test_a_write_touching_no_staged_field_reports_nothing_staged(): void {
		$this->stage();

		$response = $this->rest_write( array( 'slug' => 'summer-collection' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'swpub', $data );
		$this->assertNull( $data['swpub'] );
		$this->assertSame( 'summer-collection', $data['slug'] );
		$this->assertSame( 'summer-collection', $this->stored( $this->live_id, 'post_name' ) );
		$this->assertSame( array(), $this->engagements );
	}

	/**
	 * The field is in the route's schema, which is what makes it a contract.
	 *
	 * An extra key nobody documented is something a client finds by accident and
	 * cannot rely on. R48 promises a documented field, so the OPTIONS output is
	 * where that promise is either kept or not.
	 */
	public function test_the_field_is_documented_in_the_route_schema(): void {
		/*
		 * Core answers OPTIONS from `rest_pre_dispatch`, outside route mapping,
		 * and the test suite does not attach that filter -- it swaps in
		 * `Spy_REST_Server` and leaves the handler `default-filters.php` would
		 * have added off, so an OPTIONS dispatch here 404s whatever the plugin
		 * registered. Attaching core's own handler is what makes this assert the
		 * response a real client gets rather than a shape read back out of the
		 * route table.
		 */
		add_filter( 'rest_pre_dispatch', 'rest_handle_options_request', 10, 3 );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'OPTIONS', '/wp/v2/posts' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'swpub', $data['schema']['properties'] );

		$field = $data['schema']['properties']['swpub'];

		$this->assertTrue( $field['readonly'] );
		$this->assertSame( array( 'object', 'null' ), $field['type'] );
		$this->assertSame(
			array( 'staged', 'staged_copy_id', 'edit_url' ),
			array_keys( $field['properties'] )
		);
	}

	/**
	 * The editor's cookie save keeps the 409 fork it has today.
	 *
	 * The regression guard for U5's backstop: the block editor moves onto the
	 * staging route there, and the 409 stays underneath it as the fail-closed
	 * path. Nothing in this unit may touch it, so its shape is pinned here.
	 */
	public function test_a_cookie_authenticated_save_still_forks_with_409(): void {
		$response = $this->rest_write( array( 'content' => self::INCOMING_CONTENT ), true );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 409, $response->get_status() );

		$error = $response->as_error();

		$this->assertSame( 'swpub_staged', $error->get_error_code() );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );

		$data = $error->get_error_data();

		$this->assertSame( $staged_copy->ID, $data['staged_copy_id'] );
		$this->assertSame( self::LIVE_CONTENT, $this->stored( $this->live_id, 'post_content' ) );
		$this->assertSame( self::INCOMING_CONTENT, $this->stored( $staged_copy->ID, 'post_content' ) );
	}
}
