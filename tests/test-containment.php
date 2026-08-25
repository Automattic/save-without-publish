<?php
/**
 * Containment tests for U2 (R18).
 *
 * These assert core's behaviour, not the plugin's. The containment guarantee is
 * that registering the status as internal makes WordPress itself exclude staged copies
 * everywhere, so a mocked assertion that a filter ran would prove nothing.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Status;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proves staged content is unreachable from every public surface.
 */
class Test_Containment extends WP_UnitTestCase {

	/**
	 * A post in the staged status, standing in for a staged copy.
	 *
	 * @var int
	 */
	private int $staged_id;

	/**
	 * An ordinary published post, as the control.
	 *
	 * @var int
	 */
	private int $published_id;

	/**
	 * Creates one published post and one staged post, logged out.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->published_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Published headline',
				'post_content' => 'Body text readers may see.',
			)
		);

		$this->staged_id = self::factory()->post->create(
			array(
				'post_status'  => Status::NAME,
				'post_title'   => 'Embargoed headline',
				'post_content' => 'Unreviewed staged body text.',
			)
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Every flag is set as stated, since containment depends on all of them.
	 */
	public function test_status_is_registered_with_containment_flags(): void {
		$status = get_post_status_object( Status::NAME );

		$this->assertNotNull( $status, 'The staged status is not registered.' );
		$this->assertTrue( $status->internal );
		$this->assertFalse( $status->public );
		$this->assertTrue( $status->protected );
		$this->assertFalse( $status->private );
		$this->assertFalse( $status->publicly_queryable );
		$this->assertTrue( $status->exclude_from_search );
		$this->assertFalse( $status->show_in_admin_all_list );
		$this->assertFalse( $status->show_in_admin_status_list );
	}

	/**
	 * Core's own viewability gate refuses a staged post.
	 */
	public function test_staged_post_is_not_publicly_viewable(): void {
		$this->assertFalse( is_post_publicly_viewable( $this->staged_id ) );
		$this->assertTrue( is_post_publicly_viewable( $this->published_id ) );
	}

	/**
	 * Covers AE5. A logged-out visitor hitting the permalink gets nothing.
	 */
	public function test_logged_out_permalink_serves_no_content(): void {
		$this->go_to( get_permalink( $this->staged_id ) );

		$this->assertTrue( is_404(), 'A staged permalink served something to a logged-out visitor.' );
		$this->assertEmpty( $GLOBALS['wp_query']->posts );
	}

	/**
	 * The editor who wrote the staged copy can preview it at its own permalink.
	 *
	 * `protected` is what buys this, and a preview that 404s for the person who
	 * just saved is the failure the flag exists to stop. `is_preview` is asserted
	 * with it because a 200 that rendered something else would pass on the count
	 * alone.
	 */
	public function test_editor_can_preview_the_staged_permalink(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->go_to( get_permalink( $this->staged_id ) );

		$this->assertFalse( is_404(), 'The staged permalink 404d for a user who can edit it.' );
		$this->assertTrue( $GLOBALS['wp_query']->is_preview );
		$this->assertSame( $this->staged_id, (int) $GLOBALS['wp_query']->posts[0]->ID );
	}

	/**
	 * A reader with no edit rights gets the same nothing a logged-out visitor does.
	 *
	 * The preview branch reads a capability, so this is the assertion that says
	 * `protected` widened the door rather than removed it.
	 */
	public function test_subscriber_cannot_preview_the_staged_permalink(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->go_to( get_permalink( $this->staged_id ) );

		$this->assertTrue( is_404(), 'A subscriber reached a staged permalink.' );
		$this->assertEmpty( $GLOBALS['wp_query']->posts );
	}

	/**
	 * Covers AE5. The staged post is absent from the front-page loop.
	 */
	public function test_staged_post_is_absent_from_the_front_page_loop(): void {
		$this->go_to( home_url( '/' ) );

		$ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );

		$this->assertContains( $this->published_id, $ids, 'The control post is missing; the loop assertion proves nothing.' );
		$this->assertNotContains( $this->staged_id, $ids );
	}

	/**
	 * Covers AE5. Site search does not surface staged content.
	 */
	public function test_staged_post_is_absent_from_site_search(): void {
		$this->go_to( home_url( '/?s=Embargoed' ) );

		$ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );

		$this->assertNotContains( $this->staged_id, $ids );
	}

	/**
	 * Covers AE5. The feed does not carry staged content.
	 */
	public function test_staged_post_is_absent_from_the_feed(): void {
		$this->go_to( home_url( '/?feed=rss2' ) );

		$ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );

		$this->assertNotContains( $this->staged_id, $ids );
	}

	/**
	 * Covers AE5. The sitemap lists published posts only.
	 */
	public function test_staged_post_is_absent_from_the_sitemap(): void {
		$provider = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
		$entries  = $provider->get_url_list( 1, 'post' );
		$locs     = wp_list_pluck( $entries, 'loc' );

		$this->assertNotContains( get_permalink( $this->staged_id ), $locs );
	}

	/**
	 * Covers AE5. oEmbed refuses to describe a staged post.
	 */
	public function test_oembed_refuses_a_staged_post(): void {
		$this->assertFalse( get_oembed_response_data( get_post( $this->staged_id ), 600 ) );
	}

	/**
	 * Covers AE5. An anonymous REST read of the item is refused.
	 */
	public function test_anonymous_rest_item_request_is_refused(): void {
		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->staged_id )
		);

		$this->assertTrue( $response->is_error() );
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	/**
	 * Covers AE5. The staged status is not an accepted REST collection filter.
	 *
	 * Core builds the `status` enum from statuses registered as non-internal, so
	 * an internal status cannot be requested by any client at all.
	 */
	public function test_rest_collection_cannot_filter_to_the_staged_status(): void {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'status', Status::NAME );

		$response = rest_get_server()->dispatch( $request );

		$this->assertTrue( $response->is_error() );
	}

	/**
	 * The default REST collection omits staged posts for anonymous callers.
	 */
	public function test_anonymous_rest_collection_omits_staged_posts(): void {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertContains( $this->published_id, $ids, 'The control post is missing; the collection assertion proves nothing.' );
		$this->assertNotContains( $this->staged_id, $ids );
	}

	/**
	 * A low-privilege logged-in user cannot enumerate staged content.
	 *
	 * A Contributor holds `edit_posts`, which is the only capability guarding
	 * the collection route, so this closes the bulk-enumeration path that the
	 * anonymous tests above do not reach.
	 */
	public function test_contributor_cannot_enumerate_staged_posts(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertNotContains( $this->staged_id, $ids );
	}

	/**
	 * A low-privilege logged-in user cannot read a named staged post.
	 */
	public function test_contributor_cannot_read_a_staged_post(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->staged_id )
		);

		$this->assertTrue( $response->is_error() );
	}

	/**
	 * The public staged copy predicate reports status honestly.
	 */
	public function test_is_staged_predicate(): void {
		$this->assertTrue( Status::is_staged( $this->staged_id ) );
		$this->assertFalse( Status::is_staged( $this->published_id ) );
		$this->assertFalse( Status::is_staged( 0 ) );
	}

	/**
	 * Search-index containment cannot be proven in this environment.
	 *
	 * VIP Search is a platform plugin that wp-env does not provide, and the plan
	 * forbids substituting a mocked assertion that a filter ran. Recorded as a
	 * skip so the gap stays visible rather than silently dropped.
	 */
	public function test_staged_post_is_never_search_indexed(): void {
		$this->markTestSkipped(
			'VIP Search is absent from wp-env; verify on a VIP environment that a staged post is never queued for or present in the search index.'
		);
	}
}
