<?php
/**
 * Fork tests for U4 (R1, R2, R4, R5, R6, R19, R21).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Capabilities;
use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Status;
use WP_Post;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proves the first cookie-authenticated save redirects onto a staged copy, and that
 * everything else either passes through or fails closed.
 */
class Test_Fork extends WP_UnitTestCase {

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * An editor who cannot publish, so their saves stage.
	 *
	 * @var int
	 */
	private int $editor_id;

	/**
	 * An editor who can publish, so their saves do not stage.
	 *
	 * @var int
	 */
	private int $publisher_id;

	/**
	 * Every firing of a recorded action, in order, as argument lists.
	 *
	 * @var array<string, array<int, array<int, mixed>>>
	 */
	private array $fired = array();

	/**
	 * Creates a published post and signs in the editor whose saves stage.
	 *
	 * Every test below the entry rule is about what staging does once it
	 * applies, so the signed-in user is the one it applies to. Core ships no
	 * role that can edit a published post but not publish it, so the deny is
	 * explicit: `remove_cap()` would leave the role's own grant standing.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// No role holds the direct-publish capability, so a user who publishes
		// rather than stages is one a site granted it to.
		get_user_by( 'id', $this->publisher_id )->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );

		$editor = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$editor->add_cap( 'publish_posts', false );
		$this->editor_id = $editor->ID;

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
	 * Dispatches a post update, optionally cookie-authenticated.
	 *
	 * @param int   $post_id   Post to update.
	 * @param array $body      Fields to send.
	 * @param bool  $with_nonce Whether to send a valid wp_rest nonce.
	 * @return \WP_REST_Response The response.
	 */
	private function update_post( int $post_id, array $body, bool $with_nonce = true ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );

		if ( $with_nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}

		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Covers AE17. A user who may publish writes to the published post.
	 */
	public function test_publisher_save_publishes_and_stages_nothing(): void {
		wp_set_current_user( $this->publisher_id );

		$response = $this->update_post( $this->live_id, array( 'content' => 'Launches in July.' ) );

		$this->assertFalse( $response->is_error() );
		$this->assertStringContainsString( 'Launches in July.', get_post_field( 'post_content', $this->live_id ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}

	/**
	 * Covers AE20. An existing staged copy claims a publisher's save.
	 *
	 * One post never carries two pending versions moving apart, whoever saves.
	 */
	public function test_publisher_save_onto_an_existing_staged_copy_is_refused(): void {
		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );

		wp_set_current_user( $this->publisher_id );

		$before      = get_post_field( 'post_content', $this->live_id );
		$copy_before = get_post_field( 'post_content', $staged_copy->ID );

		$response = $this->update_post( $this->live_id, array( 'content' => 'Launches in July.' ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'swpub_live_locked', $response->as_error()->get_error_code() );
		$this->assertSame( $before, get_post_field( 'post_content', $this->live_id ) );
		$this->assertSame( $copy_before, get_post_field( 'post_content', $staged_copy->ID ), 'The staged copy was overwritten from the published post.' );
	}

	/**
	 * Covers AE18. A user who cannot publish still stages on save.
	 */
	public function test_user_without_direct_publish_still_stages(): void {
		$before = get_post_field( 'post_content', $this->live_id );

		$response = $this->update_post( $this->live_id, array( 'content' => 'Launches in July.' ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( $before, get_post_field( 'post_content', $this->live_id ) );
		$this->assertInstanceOf( WP_Post::class, Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}

	/**
	 * Covers AE21. The filter restores staging for everyone.
	 */
	public function test_filter_forcing_staging_stages_a_publisher_save(): void {
		wp_set_current_user( $this->publisher_id );
		add_filter( 'swpub_can_publish_directly', '__return_false' );

		$response = $this->update_post( $this->live_id, array( 'content' => 'Launches in July.' ) );

		remove_filter( 'swpub_can_publish_directly', '__return_false' );

		$this->assertTrue( $response->is_error() );
		$this->assertInstanceOf( WP_Post::class, Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}

	/**
	 * The kill switch is consulted before the entry rule is.
	 *
	 * A disabled plugin stages nothing, whatever the capability says.
	 */
	public function test_kill_switch_short_circuits_before_the_gate(): void {
		wp_set_current_user( $this->publisher_id );
		add_filter( 'swpub_can_publish_directly', '__return_false' );
		add_filter( 'swpub_is_enabled', '__return_false' );

		$response = $this->update_post( $this->live_id, array( 'content' => 'Launches in July.' ) );

		remove_filter( 'swpub_is_enabled', '__return_false' );
		remove_filter( 'swpub_can_publish_directly', '__return_false' );

		$this->assertFalse( $response->is_error() );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}

	/**
	 * With revisions off site-wide, saves publish and stage nothing.
	 *
	 * `post_type_supports( 'revisions' )` does not cover WP_POST_REVISIONS or
	 * the `wp_revisions_to_keep` filter. Staging without revisions is not
	 * degraded but impossible: every staged save is a revision, the review
	 * surface is the revision screen, and the pre-merge snapshot is a revision.
	 */
	public function test_no_staging_when_revisions_are_disabled_site_wide(): void {
		add_filter( 'wp_revisions_to_keep', '__return_zero' );

		$response = $this->update_post( $this->live_id, array( 'content' => 'Edited with revisions off.' ) );

		$this->assertFalse( $response->is_error(), 'The save failed instead of publishing.' );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ), 'A staged copy was created with no baseline.' );
		$this->assertStringContainsString( 'revisions off', get_post( $this->live_id )->post_content );
	}

	/**
	 * A failed baseline leaves no half-built staged copy behind.
	 *
	 * Leaving one is worse than the error it reports: this save fails, but the
	 * next finds the abandoned staged copy, succeeds against it, and hands the
	 * editor a staged copy whose review surface is empty.
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

		$response = $this->update_post( $this->live_id, array( 'content' => 'Baseline will fail.' ) );

		$this->assertTrue( $response->is_error(), 'A failed baseline was reported as success.' );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame( '', get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true ) );

		// The published post is untouched, which is the fail-closed guarantee.
		$this->assertStringContainsString( 'Launches in June.', get_post( $this->live_id )->post_content );
	}

	/**
	 * A private post type exposed to REST is staged like any other.
	 *
	 * `show_in_rest` is what decides whether the write seam exists at all, so a
	 * type reaching the block editor without the fork attached would publish
	 * straight to the live post, silently.
	 */
	public function test_a_private_rest_post_type_is_staged(): void {
		register_post_type(
			'swpub_internal',
			array(
				'public'       => false,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'revisions' ),
			)
		);

		/*
		 * Rebuild the REST server. It was constructed before this type existed,
		 * so its route table has no entry for it -- and rebuilding also re-fires
		 * `rest_api_init`, which is where the fork and the lock attach.
		 */
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$live_id = self::factory()->post->create(
			array(
				'post_type'    => 'swpub_internal',
				'post_status'  => 'publish',
				'post_content' => 'Internal, published.',
			)
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/swpub_internal/' . $live_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'content', 'Internal, edited.' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 'swpub_staged', $response->as_error()->get_error_code() );
		$this->assertStringContainsString( 'Internal, published.', get_post( $live_id )->post_content );

		$staged_copy = Staged_Copy_Repository::find_for_live( $live_id );
		$this->assertInstanceOf( \WP_Post::class, $staged_copy );
		$this->assertSame( 'swpub_internal', $staged_copy->post_type );

		unregister_post_type( 'swpub_internal' );
	}

	/**
	 * Covers AE1. Opening without saving creates nothing.
	 */
	public function test_no_save_creates_no_staged_copy(): void {
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame( '', get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true ) );
	}

	/**
	 * A first save creates exactly one staged copy and leaves the live post untouched.
	 */
	public function test_first_save_forks_and_leaves_live_untouched(): void {
		$before = get_post( $this->live_id );

		$response = $this->update_post( $this->live_id, array( 'content' => 'Launches October 3.' ) );

		$this->assertSame( 'swpub_staged', $response->as_error()->get_error_code() );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );
		$this->assertInstanceOf( WP_Post::class, $staged_copy );

		$after = get_post( $this->live_id );
		$this->assertSame( $before->post_content, $after->post_content, 'The live post content changed.' );
		$this->assertSame( $before->post_modified_gmt, $after->post_modified_gmt );
		$this->assertSame( 'publish', $after->post_status );
	}

	/**
	 * The staged edit lands on the staged copy, and the response describes it.
	 */
	public function test_staged_content_lands_on_the_staged_copy(): void {
		$response = $this->update_post( $this->live_id, array( 'content' => 'Launches October 3.' ) );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertStringContainsString( 'October 3', $staged_copy->post_content );
		$this->assertSame( Status::NAME, $staged_copy->post_status );

		// The signal names where the content went, so the client can follow it.
		$data = $response->as_error()->get_error_data();
		$this->assertSame( $staged_copy->ID, $data['staged_copy_id'] );
		$this->assertNotEmpty( $data['edit_url'] );
	}

	/**
	 * The staged copy's first revision holds the live content, not the staged edit.
	 */
	public function test_baseline_revision_holds_the_as_published_content(): void {
		$this->update_post( $this->live_id, array( 'content' => 'Launches October 3.' ) );

		$staged_copy    = Staged_Copy_Repository::find_for_live( $this->live_id );
		$revisions = array_values( wp_get_post_revisions( $staged_copy->ID, array( 'order' => 'ASC' ) ) );

		$this->assertNotEmpty( $revisions, 'No baseline revision was recorded.' );
		$this->assertStringContainsString( 'June', $revisions[0]->post_content );
		$this->assertStringNotContainsString( 'October', $revisions[0]->post_content );
	}

	/**
	 * A second save from the published post is refused, and creates no second copy.
	 *
	 * It used to be folded into the first copy, which is the bug R55 closes: the
	 * second request was composed against the published words, so it replaced the
	 * first staged edit rather than adding to it. The copy keeps what it holds and
	 * the caller is told to go there.
	 */
	public function test_a_second_save_from_the_published_post_is_refused(): void {
		$this->update_post( $this->live_id, array( 'content' => 'First staged edit.' ) );
		$first = Staged_Copy_Repository::find_for_live( $this->live_id );

		$response = $this->update_post( $this->live_id, array( 'content' => 'Second staged edit.' ) );
		$second   = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertSame( 'swpub_live_locked', $response->as_error()->get_error_code() );
		$this->assertSame( $first->ID, $second->ID, 'A second staged copy was created.' );
		$this->assertStringContainsString( 'First staged edit', get_post( $second->ID )->post_content );
	}

	/**
	 * Covers AE14. A request without the REST nonce publishes as core would.
	 */
	public function test_request_without_rest_nonce_is_not_staged(): void {
		$response = $this->update_post(
			$this->live_id,
			array( 'content' => 'Published directly.' ),
			false
		);

		$this->assertFalse( $response->is_error() );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertStringContainsString( 'Published directly', get_post( $this->live_id )->post_content );
	}

	/**
	 * Covers AE12's fork half. A request-supplied slug does not reach the staged copy.
	 */
	public function test_forking_save_cannot_set_the_staged_copy_slug(): void {
		$this->update_post(
			$this->live_id,
			array(
				'content' => 'Staged.',
				'slug'    => 'attacker-chosen-slug',
			)
		);

		$live   = get_post( $this->live_id );
		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertNotSame( 'attacker-chosen-slug', $staged_copy->post_name );
		$this->assertNotSame( 'attacker-chosen-slug', $live->post_name );
		$this->assertSame( Staged_Copy_Repository::staged_copy_slug( $this->live_id ), $staged_copy->post_name );
	}

	/**
	 * A request-supplied status does not escape the staged status.
	 */
	public function test_forking_save_cannot_set_the_staged_copy_status(): void {
		$this->update_post(
			$this->live_id,
			array(
				'content' => 'Staged.',
				'status'  => 'publish',
			)
		);

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertSame( Status::NAME, $staged_copy->post_status );
	}

	/**
	 * A draft post is not staged; staging is for published content only.
	 */
	public function test_draft_post_is_not_staged(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->update_post( $draft, array( 'content' => 'Ordinary draft edit.' ) );

		$this->assertNull( Staged_Copy_Repository::find_for_live( $draft ) );
		$this->assertStringContainsString( 'Ordinary draft edit', get_post( $draft )->post_content );
	}

	/**
	 * The R32 kill switch turns staging off without deactivating the plugin.
	 */
	public function test_kill_switch_disables_forking(): void {
		add_filter( 'swpub_is_enabled', '__return_false' );

		$this->update_post( $this->live_id, array( 'content' => 'Published directly.' ) );

		remove_filter( 'swpub_is_enabled', '__return_false' );

		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertStringContainsString( 'Published directly', get_post( $this->live_id )->post_content );
	}

	/**
	 * Covers AE15. A pre-fork autosave does not survive to offer staged text back.
	 */
	public function test_pre_fork_autosave_is_removed(): void {
		wp_create_post_autosave(
			array(
				'post_ID'      => $this->live_id,
				'post_type'    => 'post',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Half-typed unreviewed text.',
				'post_author'  => $this->editor_id,
			)
		);

		$this->assertInstanceOf(
			WP_Post::class,
			wp_get_post_autosave( $this->live_id, $this->editor_id ),
			'Precondition: the autosave must exist before the fork.'
		);

		$this->update_post( $this->live_id, array( 'content' => 'Staged edit.' ) );

		$this->assertFalse( wp_get_post_autosave( $this->live_id, $this->editor_id ) );
	}

	/**
	 * Covers AE9. When staging cannot be established the live post is untouched.
	 */
	public function test_fork_failure_leaves_the_live_post_byte_identical(): void {
		$before = get_post( $this->live_id );

		// Force staged copy creation to fail at the insert.
		$fail = static function ( $maybe_empty, $postarr ) {
			return Status::NAME === ( $postarr['post_status'] ?? '' ) ? true : $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );

		$response = $this->update_post( $this->live_id, array( 'content' => 'Should not land.' ) );

		remove_filter( 'wp_insert_post_empty_content', $fail, 10 );

		$after = get_post( $this->live_id );

		$this->assertTrue( $response->is_error(), 'A failed fork must not report success.' );
		$this->assertSame( $before->post_content, $after->post_content );
		$this->assertSame( $before->post_modified_gmt, $after->post_modified_gmt );
	}

	/**
	 * A public custom post type registered late in init still forks (KTD23).
	 */
	public function test_late_registered_public_post_type_forks(): void {
		register_post_type(
			'swpub_late',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'revisions' ),
			)
		);

		/*
		 * A real request builds the REST server after every type has registered,
		 * so both its routes and our filters see the late type. The test process
		 * reuses one server, so drop it and let the next call rebuild.
		 */
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$late_id = self::factory()->post->create(
			array(
				'post_type'    => 'swpub_late',
				'post_status'  => 'publish',
				'post_content' => 'Original.',
			)
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/swpub_late/' . $late_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'content', 'Staged.' );
		rest_get_server()->dispatch( $request );

		$staged_copy = Staged_Copy_Repository::find_for_live( $late_id );

		unregister_post_type( 'swpub_late' );

		$this->assertInstanceOf( WP_Post::class, $staged_copy, 'A late-registered public type did not fork.' );
		$this->assertStringContainsString( 'Original.', get_post( $late_id )->post_content );
	}

	/**
	 * Starts recording an action so a test can assert on it, or on its silence.
	 *
	 * @param string $hook Action name.
	 * @return void
	 */
	private function record_action( string $hook ): void {
		$this->fired[ $hook ] = array();

		add_action(
			$hook,
			function ( $first, $second, $third ) use ( $hook ): void {
				$this->fired[ $hook ][] = array( $first, $second, $third );
			},
			10,
			3
		);
	}

	/**
	 * The backstop fires when the fork handles a save the staging route could
	 * have taken (R49, KTD38).
	 *
	 * The editor asks `Stage_Route` for a staging save. Arriving here instead
	 * means its protocol did not run, which is worth an alarm rather than a
	 * shrug.
	 */
	public function test_backstop_event_fires_for_a_staging_user(): void {
		$this->record_action( 'swpub_staged_via_backstop' );

		$response = $this->update_post( $this->live_id, array( 'content' => 'Launches in July.' ) );

		$this->assertSame( 'swpub_staged', $response->as_error()->get_error_code() );

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertCount( 1, $this->fired['swpub_staged_via_backstop'], 'The backstop did not report itself.' );
		$this->assertSame(
			array( $staged_copy->ID, $this->live_id, $this->editor_id ),
			$this->fired['swpub_staged_via_backstop'][0],
			'The backstop reported the wrong post or the wrong user.'
		);
	}

	/**
	 * A save onto an existing staged copy is a refusal, not a backstop (KTD38).
	 *
	 * The alarm means one thing now -- the editor's protocol did not run and a
	 * first save fell through to the fork -- and it has to stay silent on a path
	 * that never reaches the fork at all, or the end-to-end suite's silence
	 * assertion stops meaning anything.
	 */
	public function test_backstop_event_is_silent_when_the_write_is_refused(): void {
		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );

		wp_set_current_user( $this->publisher_id );

		$this->record_action( 'swpub_staged_via_backstop' );

		$copy_before = get_post_field( 'post_content', $staged_copy->ID );
		$response    = $this->update_post( $this->live_id, array( 'content' => 'Launches in July.' ) );

		$this->assertSame( 'swpub_live_locked', $response->as_error()->get_error_code() );
		$this->assertSame( $copy_before, get_post_field( 'post_content', $staged_copy->ID ) );
		$this->assertSame( array(), $this->fired['swpub_staged_via_backstop'], 'A refusal reported itself as a fallback.' );
	}

	/**
	 * Covers R42. A save changing nothing stageable publishes as core does.
	 *
	 * The editor sends a term change to the same endpoint an edit goes to.
	 * Forking it would create a copy holding no change, move the editor onto it,
	 * and lose the term change entirely -- this filter aborts the controller
	 * before it writes terms.
	 */
	public function test_a_save_changing_no_staged_field_passes_through(): void {
		$category = self::factory()->category->create( array( 'name' => 'Collections' ) );

		$this->record_action( 'swpub_staged_via_backstop' );

		$response = $this->update_post( $this->live_id, array( 'categories' => array( $category ) ) );

		$this->assertFalse( $response->is_error(), 'A term-only save was refused instead of applied.' );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ), 'A term-only save created a staged copy.' );
		$this->assertContains( $category, wp_get_post_categories( $this->live_id ) );
		$this->assertSame( array(), $this->fired['swpub_staged_via_backstop'] );
	}

	/**
	 * A save resending the published post's own text is not a staging save.
	 */
	public function test_a_save_resending_unchanged_text_passes_through(): void {
		$live = get_post( $this->live_id );

		$response = $this->update_post(
			$this->live_id,
			array(
				'title'   => $live->post_title,
				'content' => $live->post_content,
			)
		);

		$this->assertFalse( $response->is_error() );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}
}
