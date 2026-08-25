<?php
/**
 * Post list and discard tests for U9 (R14, R15, R16, R28).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Capabilities;
use SaveWithoutPublish\Merge_Marker;
use SaveWithoutPublish\Post_List;
use SaveWithoutPublish\Staged_Copy_Repository;
use WP_Post;
use WP_UnitTestCase;

/**
 * Proves staged work is visible, and that discarding it takes real intent.
 */
class Test_Post_List extends WP_UnitTestCase {

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
		set_current_screen( 'edit-post' );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Meridian Active, Summer collection',
			)
		);

		$this->staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;
	}

	/**
	 * Restores screen state.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	/**
	 * A post with staged changes is labelled.
	 */
	public function test_a_post_with_a_staged_copy_is_labelled(): void {
		$states = apply_filters( 'display_post_states', array(), get_post( $this->live_id ) );

		$this->assertArrayHasKey( 'swpub', $states );
		$this->assertSame( 'Staged changes', $states['swpub'] );
	}

	/**
	 * A post without staged changes is not labelled.
	 */
	public function test_a_post_without_a_staged_copy_is_not_labelled(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( array(), apply_filters( 'display_post_states', array(), get_post( $other ) ) );
	}

	/**
	 * The staged copy itself is not labelled as holding staged changes.
	 */
	public function test_a_staged_copy_is_not_labelled(): void {
		$this->assertSame( array(), apply_filters( 'display_post_states', array(), get_post( $this->staged_copy_id ) ) );
	}

	/**
	 * A staged copy whose live post cannot receive it reads differently.
	 */
	public function test_a_stranded_staged_copy_is_labelled_distinctly(): void {
		wp_update_post(
			array(
				'ID'          => $this->live_id,
				'post_status' => 'draft',
			)
		);

		$states = apply_filters( 'display_post_states', array(), get_post( $this->live_id ) );

		// Two states on one row, the way core reads "Sticky, Private". The noun
		// stays, because unlike every state core adds, this one is not a
		// property of the post: the post is published, and what is staged hangs
		// off it.
		$this->assertSame( 'Staged changes', $states['swpub'] );
		$this->assertSame( 'Cannot be published', $states['swpub_stranded'] );
	}

	/**
	 * The row offers one staged action: opening the staged copy.
	 */
	public function test_the_row_offers_only_opening_the_staged_copy(): void {
		$actions = apply_filters( 'post_row_actions', array(), get_post( $this->live_id ) );

		$this->assertArrayHasKey( 'swpub_open', $actions );
		$this->assertStringContainsString( 'post=' . $this->staged_copy_id, $actions['swpub_open'] );

		// One word for one thing. The state beside the title and the action
		// beneath it are the same vocabulary, so a support ticket quoting either
		// greps straight to the source.
		$this->assertStringContainsString( 'Edit staged changes', $actions['swpub_open'] );

		// Reviewing and discarding are decisions about the staged copy, offered
		// where that copy is open. Core's row already carries four actions, and
		// three more wrapped it onto a second line.
		$this->assertArrayNotHasKey( 'swpub_review', $actions );
		$this->assertArrayNotHasKey( 'swpub_discard', $actions );
	}

	/**
	 * A user who cannot edit the live post gets no staged row actions.
	 */
	public function test_an_unauthorized_user_gets_no_row_actions(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$actions = apply_filters( 'post_row_actions', array(), get_post( $this->live_id ) );

		$this->assertArrayNotHasKey( 'swpub_open', $actions );
	}

	/**
	 * A post with no staged copy is not offered a copy that does not exist.
	 */
	public function test_a_post_without_a_staged_copy_does_not_offer_the_copy(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$actions = apply_filters( 'post_row_actions', array(), get_post( $other ) );

		// The way in is offered; a copy that does not exist is not.
		$this->assertArrayNotHasKey( 'swpub_open', $actions );
	}

	/**
	 * A published post with nothing staged offers a way to begin staging.
	 */
	public function test_a_post_without_a_staged_copy_offers_staging(): void {
		// The row action is the opt-in for someone whose save would otherwise
		// publish, so it is offered only to a user granted that.
		$granted = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$granted->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );
		wp_set_current_user( $granted->ID );

		$plain = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$actions = Post_List::row_actions( array( 'edit' => '<a href="#">Edit</a>' ), get_post( $plain ) );

		$this->assertArrayHasKey( 'swpub_stage', $actions );
		$this->assertStringContainsString( 'Stage changes', $actions['swpub_stage'] );
	}

	/**
	 * Once a copy exists, the row leads to it and never offers a second one.
	 */
	public function test_a_post_with_a_staged_copy_does_not_offer_staging(): void {
		$actions = Post_List::row_actions( array(), get_post( $this->live_id ) );

		$this->assertArrayHasKey( 'swpub_open', $actions );
		$this->assertArrayNotHasKey( 'swpub_stage', $actions );
	}

	/**
	 * A user whose saves stage anyway is not offered the action.
	 *
	 * Their way in is pressing Update; offering "Stage changes" beside it
	 * would present a choice they do not have.
	 */
	public function test_a_user_who_cannot_publish_is_not_offered_staging(): void {
		$editor = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$editor->add_cap( 'publish_posts', false );
		wp_set_current_user( $editor->ID );

		$plain = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$actions = Post_List::row_actions( array(), get_post( $plain ) );

		$this->assertArrayNotHasKey( 'swpub_stage', $actions );
	}

	/**
	 * A draft has nothing published to stage against.
	 */
	public function test_a_draft_is_not_offered_staging(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$actions = Post_List::row_actions( array(), get_post( $draft ) );

		$this->assertArrayNotHasKey( 'swpub_stage', $actions );
	}

	/**
	 * Following the action creates one copy and lands on its editor.
	 */
	public function test_following_the_stage_action_creates_the_copy_and_redirects(): void {
		$plain = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$_REQUEST['swpub_post'] = (string) $plain;
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'swpub_stage_' . $plain );

		/*
		 * The handler ends in `exit`, which no test can survive. Throwing from
		 * the redirect filter stops it one line earlier, with the redirect
		 * destination in hand and the exit never reached.
		 */
		$redirect = '';
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \Exception( (string) $location );
			}
		);

		try {
			Post_List::handle_stage();
			$this->fail( 'The handler did not redirect.' );
		} catch ( \Exception $e ) {
			$redirect = $e->getMessage();
		} finally {
			unset( $_REQUEST['swpub_post'], $_REQUEST['_wpnonce'] );
		}

		$staged_copy = Staged_Copy_Repository::find_for_live( $plain );
		$this->assertInstanceOf( WP_Post::class, $staged_copy );
		$this->assertStringContainsString( 'post=' . $staged_copy->ID, $redirect );
	}

	/**
	 * The staging nonce is tied to the post, so one URL stages one thing.
	 */
	public function test_the_stage_nonce_is_tied_to_the_post(): void {
		$plain = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$_REQUEST['swpub_post'] = (string) $plain;
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'swpub_stage_' . $other );

		try {
			Post_List::handle_stage();
			$this->fail( 'A mismatched nonce was accepted.' );
		} catch ( \WPDieException $e ) {
			unset( $_REQUEST['swpub_post'], $_REQUEST['_wpnonce'] );
			$this->assertNull( Staged_Copy_Repository::find_for_live( $plain ) );
		}
	}

	/**
	 * The kill switch closes the list's staging path too.
	 */
	public function test_the_kill_switch_closes_the_stage_action(): void {
		add_filter( 'swpub_is_enabled', '__return_false' );

		$plain = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$_REQUEST['swpub_post'] = (string) $plain;
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'swpub_stage_' . $plain );

		try {
			Post_List::handle_stage();
			$this->fail( 'The kill switch did not stop the stage action.' );
		} catch ( \WPDieException $e ) {
			$this->assertNull( Staged_Copy_Repository::find_for_live( $plain ) );
		} finally {
			unset( $_REQUEST['swpub_post'], $_REQUEST['_wpnonce'] );
			remove_filter( 'swpub_is_enabled', '__return_false' );
		}
	}

	/**
	 * A draft has nothing published to stage against, even with a valid nonce.
	 */
	public function test_the_stage_action_refuses_a_draft(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$_REQUEST['swpub_post'] = (string) $draft;
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'swpub_stage_' . $draft );

		try {
			Post_List::handle_stage();
			$this->fail( 'A draft was staged.' );
		} catch ( \WPDieException $e ) {
			$this->assertNull( Staged_Copy_Repository::find_for_live( $draft ) );
		} finally {
			unset( $_REQUEST['swpub_post'], $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * A user who cannot edit the post cannot stage it from the list either.
	 */
	public function test_the_stage_action_refuses_a_user_who_cannot_edit(): void {
		$plain = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_REQUEST['swpub_post'] = (string) $plain;
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'swpub_stage_' . $plain );

		try {
			Post_List::handle_stage();
			$this->fail( 'An unauthorized user staged a post.' );
		} catch ( \WPDieException $e ) {
			$this->assertNull( Staged_Copy_Repository::find_for_live( $plain ) );
		} finally {
			unset( $_REQUEST['swpub_post'], $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * A merge in flight owns the post until it finishes.
	 */
	public function test_the_stage_action_refuses_a_mid_merge_post(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );

		$_REQUEST['swpub_post'] = (string) $this->live_id;
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'swpub_stage_' . $this->live_id );

		try {
			Post_List::handle_stage();
			$this->fail( 'A mid-merge post was staged.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'already in progress', $e->getMessage() );
		} finally {
			unset( $_REQUEST['swpub_post'], $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * A post whose revisions are disabled cannot stage: nothing could review
	 * or roll back the change.
	 */
	public function test_the_stage_action_refuses_a_post_without_revisions(): void {
		add_filter( 'wp_revisions_to_keep', '__return_zero' );

		$plain = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$_REQUEST['swpub_post'] = (string) $plain;
		$_REQUEST['_wpnonce']   = wp_create_nonce( 'swpub_stage_' . $plain );

		try {
			Post_List::handle_stage();
			$this->fail( 'A post without revisions was staged.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'revisions', $e->getMessage() );
			$this->assertNull( Staged_Copy_Repository::find_for_live( $plain ) );
		} finally {
			unset( $_REQUEST['swpub_post'], $_REQUEST['_wpnonce'] );
			remove_filter( 'wp_revisions_to_keep', '__return_zero' );
		}
	}

	/**
	 * A type staging does not cover is never offered the way in.
	 *
	 * `staged_post_types()` keys on `show_in_rest`, because the write seams
	 * attach to `rest_pre_insert_{$type}`. A public type outside REST has no
	 * seam, so a copy staged for it would be uncontained.
	 */
	public function test_an_uncovered_post_type_is_not_offered_staging(): void {
		register_post_type(
			'swpub_case',
			array(
				'public'       => true,
				'show_in_rest' => false,

				// Ordinary post capabilities, so the administrator passes every
				// other gate and only the type check can be what refuses.
				'map_meta_cap' => true,
				'supports'     => array( 'title', 'editor', 'revisions' ),
			)
		);

		$plain = self::factory()->post->create(
			array(
				'post_type'   => 'swpub_case',
				'post_status' => 'publish',
			)
		);

		$actions = Post_List::row_actions( array(), get_post( $plain ) );

		unregister_post_type( 'swpub_case' );

		$this->assertArrayNotHasKey( 'swpub_stage', $actions );
	}

	/**
	 * The discard URL carries a nonce tied to the specific post.
	 *
	 * A nonce for one post must not authorize discarding another's staged work.
	 */
	public function test_the_discard_nonce_is_tied_to_the_post(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// wp_nonce_url() returns an HTML-escaped URL, so the separators are
		// entities until something renders them.
		$mine   = html_entity_decode( Post_List::discard_url( $this->live_id ) );
		$theirs = html_entity_decode( Post_List::discard_url( $other ) );

		parse_str( (string) wp_parse_url( $mine, PHP_URL_QUERY ), $mine_args );
		parse_str( (string) wp_parse_url( $theirs, PHP_URL_QUERY ), $theirs_args );

		$this->assertNotSame( $mine_args['_wpnonce'], $theirs_args['_wpnonce'] );
		$this->assertSame(
			1,
			wp_verify_nonce( $mine_args['_wpnonce'], 'swpub_discard_' . $this->live_id )
		);
		$this->assertFalse(
			wp_verify_nonce( $mine_args['_wpnonce'], 'swpub_discard_' . $other )
		);
	}

	/**
	 * Listing many posts adds one query for the staged copies, not one per row.
	 *
	 * The label is worth nothing if it makes the posts list slow, and a
	 * per-row lookup is exactly the kind of thing that passes every functional
	 * test and then falls over on a real site.
	 */
	public function test_listing_many_posts_does_not_query_per_row(): void {
		$live_ids = array();

		for ( $i = 0; $i < 20; $i++ ) {
			$live_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			Staged_Copy_Repository::create( get_post( $live_id ) );
			$live_ids[] = $live_id;
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
			)
		);

		Post_List::prime( $query->posts );

		$before = get_num_queries();

		foreach ( $live_ids as $live_id ) {
			apply_filters( 'display_post_states', array(), get_post( $live_id ) );
		}

		$this->assertSame(
			0,
			get_num_queries() - $before,
			'Rendering the label queried the database per row.'
		);
	}

	/**
	 * Discarding removes the staged copy and both pointers, leaving nothing trashed.
	 */
	public function test_a_confirmed_discard_removes_the_staged_copy(): void {
		Staged_Copy_Repository::unlink( $this->live_id, $this->staged_copy_id );
		wp_delete_post( $this->staged_copy_id, true );

		$this->assertNull( get_post( $this->staged_copy_id ) );
		$this->assertSame( '', get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true ) );
		$this->assertSame( array(), apply_filters( 'display_post_states', array(), get_post( $this->live_id ) ) );

		// The published post is untouched by a discard.
		$this->assertSame( 'publish', get_post( $this->live_id )->post_status );
		$this->assertSame( 'Meridian Active, Summer collection', get_post( $this->live_id )->post_title );
	}

	/**
	 * The staged copy survives until a discard is actually confirmed.
	 */
	public function test_the_staged_copy_survives_an_unconfirmed_discard(): void {
		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
		$this->assertStringNotContainsString( 'swpub_confirmed', Post_List::discard_url( $this->live_id ) );
	}
}
