<?php
/**
 * Deliberate staging route tests for U3 (R37, R38).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Merge_Marker;
use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Status;
use WP_Post;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proves an editor can stage a change without publishing anything first.
 */
class Test_Stage_Route extends WP_UnitTestCase {

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * An editor who can publish, and therefore has to ask to stage.
	 *
	 * @var int
	 */
	private int $editor_id;

	/**
	 * Someone who cannot edit the published post at all.
	 *
	 * @var int
	 */
	private int $outsider_id;

	/**
	 * Creates a published post and signs in the editor.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->editor_id   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->outsider_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->editor_id );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Launches in June.',
			)
		);
	}

	/**
	 * Asks the route to stage a change.
	 *
	 * @param int   $live_id The published post.
	 * @param array $body    Fields to send.
	 * @return \WP_REST_Response The response.
	 */
	private function stage( int $live_id, array $body = array() ) {
		$request = new WP_REST_Request( 'POST', '/swpub/v1/stage/' . $live_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Covers AE19. Unsaved work reaches the staged copy and the published post is untouched.
	 */
	public function test_staging_carries_unsaved_content_and_leaves_the_published_post_alone(): void {
		$before = get_post( $this->live_id );

		$response = $this->stage(
			$this->live_id,
			array(
				'title'   => 'Meridian Active, Autumn collection',
				'content' => 'Launches in September.',
				'excerpt' => 'Now in September.',
			)
		);

		$this->assertFalse( $response->is_error(), 'Staging was refused.' );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );
		$this->assertInstanceOf( WP_Post::class, $staged_copy );
		$this->assertSame( 'Meridian Active, Autumn collection', $staged_copy->post_title );
		$this->assertStringContainsString( 'Launches in September.', $staged_copy->post_content );
		$this->assertSame( 'Now in September.', $staged_copy->post_excerpt );

		$after = get_post( $this->live_id );
		$this->assertSame( $before->post_title, $after->post_title );
		$this->assertSame( $before->post_content, $after->post_content );
		$this->assertSame( $before->post_modified_gmt, $after->post_modified_gmt );

		$data = $response->get_data();
		$this->assertSame( $staged_copy->ID, $data['stagedCopyId'] );
		$this->assertStringContainsString( (string) $staged_copy->ID, $data['editUrl'] );
	}

	/**
	 * The baseline records the published content, so the review screen has something to compare.
	 */
	public function test_the_staged_copy_carries_a_baseline_holding_the_published_content(): void {
		$this->stage( $this->live_id, array( 'content' => 'Launches in September.' ) );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );
		$revisions   = array_values( wp_get_post_revisions( $staged_copy->ID, array( 'order' => 'ASC' ) ) );

		$this->assertNotEmpty( $revisions );
		$this->assertStringContainsString( 'Launches in June.', $revisions[0]->post_content );
	}

	/**
	 * Covers AE22. A second request is refused, and creates no second copy.
	 *
	 * This route is the deliberate first save. Asking it to stage again over an
	 * existing copy is the destructive case at the polite door: the request was
	 * composed in an editor showing the published words, so honouring it would
	 * replace the first pass rather than add to it (R55).
	 */
	public function test_staging_twice_is_refused(): void {
		$first = $this->stage( $this->live_id, array( 'content' => 'First pass.' ) );
		$this->assertFalse( $first->is_error() );

		$copy_id = $first->get_data()['stagedCopyId'];

		$second = $this->stage( $this->live_id, array( 'content' => 'Second pass.' ) );

		$this->assertTrue( $second->is_error() );
		$this->assertSame( 'swpub_live_locked', $second->as_error()->get_error_code() );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( $copy_id, $second->as_error()->get_error_data()['staged_copy_id'] );

		$staged_copies = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => Status::NAME,
				'fields'      => 'ids',
				'numberposts' => 10,
			)
		);
		$this->assertCount( 1, $staged_copies );
		$this->assertStringContainsString( 'First pass.', get_post_field( 'post_content', $copy_id ) );
	}

	/**
	 * Staging with nothing to write still produces a copy of the published post.
	 */
	public function test_staging_without_content_copies_the_published_post(): void {
		$response = $this->stage( $this->live_id );

		$this->assertFalse( $response->is_error() );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );
		$this->assertStringContainsString( 'Launches in June.', $staged_copy->post_content );
	}

	/**
	 * Someone who cannot edit the published post cannot stage against it.
	 */
	public function test_a_user_who_cannot_edit_the_published_post_is_refused(): void {
		wp_set_current_user( $this->outsider_id );

		$response = $this->stage( $this->live_id, array( 'content' => 'Not mine to stage.' ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 403, $response->get_status() );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}

	/**
	 * An anonymous request is refused.
	 */
	public function test_an_anonymous_request_is_refused(): void {
		wp_set_current_user( 0 );

		$response = $this->stage( $this->live_id, array( 'content' => 'Not mine to stage.' ) );

		$this->assertTrue( $response->is_error() );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}

	/**
	 * A post that is not published has nothing to stage against.
	 */
	public function test_a_draft_is_refused(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$response = $this->stage( $draft, array( 'content' => 'Nothing to stage against.' ) );

		$this->assertTrue( $response->is_error() );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $draft ) );
	}

	/**
	 * A post that does not exist is refused.
	 */
	public function test_a_missing_post_is_refused(): void {
		$response = $this->stage( 999999, array( 'content' => 'Nothing there.' ) );

		$this->assertTrue( $response->is_error() );
	}

	/**
	 * A merge in flight owns the post until it finishes.
	 */
	public function test_a_post_carrying_a_merge_marker_is_refused(): void {
		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );
		Merge_Marker::start( $this->live_id, $staged_copy->ID );

		$response = $this->stage( $this->live_id, array( 'content' => 'Mid-merge.' ) );

		$this->assertTrue( $response->is_error() );
		$this->assertStringNotContainsString( 'Mid-merge.', get_post_field( 'post_content', $staged_copy->ID ) );
	}

	/**
	 * A field the merge could not carry is refused rather than dropped.
	 *
	 * Silently ignoring it would let an editor believe a slug or a term change
	 * had been staged, and discover on publish that it never was.
	 */
	public function test_an_unstageable_field_is_refused(): void {
		$response = $this->stage(
			$this->live_id,
			array(
				'content' => 'Launches in September.',
				'slug'    => 'a-new-slug',
			)
		);

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'swpub_unstageable_field', $response->as_error()->get_error_code() );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}

	/**
	 * The route's copy and the fork's copy are the same thing.
	 */
	public function test_the_route_and_the_fork_produce_the_same_copy(): void {
		$this->stage( $this->live_id, array( 'content' => 'Staged by the route.' ) );
		$from_route = Staged_Copy_Repository::find_for_live( $this->live_id );

		$other_live = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Launches in June.',
			)
		);

		$editor = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$editor->add_cap( 'publish_posts', false );
		wp_set_current_user( $editor->ID );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $other_live );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'content', 'Staged by a save.' );
		rest_get_server()->dispatch( $request );

		$from_fork = Staged_Copy_Repository::find_for_live( $other_live );
		$this->assertInstanceOf( WP_Post::class, $from_fork );

		$this->assertSame( $from_fork->post_status, $from_route->post_status );
		$this->assertSame( $from_fork->post_type, $from_route->post_type );
		$this->assertSame( $from_fork->comment_status, $from_route->comment_status );
		$this->assertTrue( Staged_Copy_Repository::pointers_agree( $this->live_id, $from_route->ID ) );
		$this->assertSame(
			get_post_meta( $from_fork->ID, Staged_Copy_Repository::FORK_BASELINE_META, true ) !== '',
			get_post_meta( $from_route->ID, Staged_Copy_Repository::FORK_BASELINE_META, true ) !== ''
		);
		$this->assertCount(
			count( wp_get_post_revisions( $from_fork->ID ) ),
			wp_get_post_revisions( $from_route->ID )
		);
	}

	/**
	 * A failed baseline leaves nothing behind, and the published post untouched.
	 */
	public function test_a_failed_baseline_leaves_no_staged_copy(): void {
		add_filter( 'wp_save_post_revision_check_for_changes', '__return_true' );
		add_filter(
			'wp_revisions_to_keep',
			static function ( $keep, $post ) {
				return Status::NAME === $post->post_status ? 0 : $keep;
			},
			10,
			2
		);

		$response = $this->stage( $this->live_id, array( 'content' => 'Baseline will fail.' ) );

		$this->assertTrue( $response->is_error() );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame( '', get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true ) );
		$this->assertStringContainsString( 'Launches in June.', get_post_field( 'post_content', $this->live_id ) );
	}

	/**
	 * The kill switch closes the route.
	 */
	public function test_the_kill_switch_closes_the_route(): void {
		add_filter( 'swpub_is_enabled', '__return_false' );

		$response = $this->stage( $this->live_id, array( 'content' => 'Disabled.' ) );

		remove_filter( 'swpub_is_enabled', '__return_false' );

		$this->assertTrue( $response->is_error() );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}
}
