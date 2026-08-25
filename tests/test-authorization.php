<?php
/**
 * Authorization tests for U12 (R28).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Capabilities;
use SaveWithoutPublish\Staged_Copy_Repository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proves a staged copy is never easier to reach than the post it stages.
 */
class Test_Authorization extends WP_UnitTestCase {

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
	 * An editor who can edit anyone's published posts.
	 *
	 * @var int
	 */
	private int $editor_id;

	/**
	 * A contributor who cannot edit published posts.
	 *
	 * @var int
	 */
	private int $contributor_id;

	/**
	 * Stages a published post authored by the contributor.
	 *
	 * Authorship matters: core's `read_post` mapping has an author branch that
	 * grants access before any edit check, and the staged copy inherits the live
	 * post's author at fork. Making the contributor the author is what exercises
	 * that branch.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->editor_id      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->contributor_id = self::factory()->user->create( array( 'role' => 'contributor' ) );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $this->contributor_id,
			)
		);

		$staged_copy          = Staged_Copy_Repository::create( get_post( $this->live_id ) );
		$this->staged_copy_id = $staged_copy->ID;
	}

	/**
	 * The staged copy inherits the live post's author, so the branch under test is live.
	 */
	public function test_staged_copy_inherits_the_live_author(): void {
		$this->assertSame(
			$this->contributor_id,
			(int) get_post_field( 'post_author', $this->staged_copy_id )
		);
	}

	/**
	 * Covers AE10. The staged copy's own author cannot read or edit it when they
	 * cannot edit the live post.
	 */
	public function test_staged_copy_author_who_cannot_edit_live_cannot_reach_the_staged_copy(): void {
		wp_set_current_user( $this->contributor_id );

		$this->assertFalse( current_user_can( 'edit_post', $this->live_id ), 'Precondition: the contributor must not be able to edit the published post.' );

		$this->assertFalse( current_user_can( 'read_post', $this->staged_copy_id ) );
		$this->assertFalse( current_user_can( 'edit_post', $this->staged_copy_id ) );
		$this->assertFalse( current_user_can( 'delete_post', $this->staged_copy_id ) );
		$this->assertFalse( Capabilities::current_user_can_manage( $this->staged_copy_id ) );
	}

	/**
	 * Covers AE10. That user cannot reach the staged copy over REST either.
	 */
	public function test_staged_copy_author_cannot_read_the_staged_copy_over_rest(): void {
		wp_set_current_user( $this->contributor_id );

		$item = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->staged_copy_id )
		);
		$this->assertTrue( $item->is_error() );

		$revisions = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->staged_copy_id . '/revisions' )
		);
		$this->assertTrue( $revisions->is_error() );
	}

	/**
	 * Someone who can edit the live post can act on its staged copy.
	 */
	public function test_editor_can_reach_the_staged_copy(): void {
		wp_set_current_user( $this->editor_id );

		$this->assertTrue( current_user_can( 'edit_post', $this->live_id ), 'Precondition: the editor must be able to edit the published post.' );

		$this->assertTrue( current_user_can( 'read_post', $this->staged_copy_id ) );
		$this->assertTrue( current_user_can( 'edit_post', $this->staged_copy_id ) );
		$this->assertTrue( Capabilities::current_user_can_manage( $this->staged_copy_id ) );
	}

	/**
	 * An anonymous visitor can do nothing with a staged copy.
	 */
	public function test_anonymous_user_cannot_reach_the_staged_copy(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( current_user_can( 'read_post', $this->staged_copy_id ) );
		$this->assertFalse( current_user_can( 'edit_post', $this->staged_copy_id ) );
		$this->assertFalse( Capabilities::current_user_can_manage( $this->staged_copy_id ) );
	}

	/**
	 * The mapping does not leak onto ordinary posts.
	 */
	public function test_mapping_leaves_non_staged_copy_posts_alone(): void {
		$other = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $this->contributor_id,
			)
		);

		wp_set_current_user( $this->contributor_id );

		$this->assertTrue( current_user_can( 'read_post', $other ) );
	}

	/**
	 * A stranded staged copy stays actionable by a sufficiently privileged user.
	 *
	 * Denying outright would make it permanent, which contradicts R17's promise
	 * that a stranded staged copy is surfaced for an editor to clear.
	 */
	public function test_stranded_staged_copy_remains_actionable_by_an_editor(): void {
		wp_delete_post( $this->live_id, true );

		wp_set_current_user( $this->editor_id );
		$this->assertTrue( current_user_can( 'edit_post', $this->staged_copy_id ) );

		wp_set_current_user( $this->contributor_id );
		$this->assertFalse( current_user_can( 'edit_post', $this->staged_copy_id ) );
	}

	/**
	 * No role ships holding the direct-publish capability.
	 *
	 * The default the whole entry rule rests on: install the plugin and every
	 * save to a published post stages, an administrator's included.
	 */
	public function test_no_role_holds_the_capability(): void {
		foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

			$this->assertFalse(
				Capabilities::current_user_can_publish_directly( $this->live_id ),
				sprintf( 'The %s role must not publish directly without a grant.', $role )
			);
		}
	}

	/**
	 * A granted user publishes directly.
	 */
	public function test_a_granted_user_can_publish_directly(): void {
		$granted = get_user_by( 'id', $this->editor_id );
		$granted->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );

		wp_set_current_user( $granted->ID );

		$this->assertTrue( current_user_can( Capabilities::PUBLISH_DIRECTLY, $this->live_id ) );
		$this->assertTrue( Capabilities::current_user_can_publish_directly( $this->live_id ) );
	}

	/**
	 * `publish_posts` no longer decides, in either direction.
	 *
	 * Holding it does not grant the bypass, and being denied it does not take
	 * the bypass away. The two capabilities answer different questions: whether
	 * this user may put content in front of readers at all, and whether their
	 * ordinary save may go there with no staged copy in between.
	 */
	public function test_publish_posts_does_not_decide(): void {
		// An explicit deny, not `remove_cap()`: the capability comes from the
		// role, and removing it from the user's own set leaves the role's grant
		// standing. This is the shape a site building a revisor role would use.
		$revisor = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$revisor->add_cap( 'publish_posts', false );
		$revisor->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );

		wp_set_current_user( $revisor->ID );

		$this->assertTrue( current_user_can( 'edit_post', $this->live_id ), 'Precondition: the user must still be able to edit the published post.' );
		$this->assertFalse( current_user_can( 'publish_posts' ), 'Precondition: the user must not hold the post type publish capability.' );
		$this->assertTrue( Capabilities::current_user_can_publish_directly( $this->live_id ) );

		wp_set_current_user( $this->editor_id );

		$this->assertTrue( current_user_can( 'publish_posts' ), 'Precondition: a stock editor holds the post type publish capability.' );
		$this->assertFalse( Capabilities::current_user_can_publish_directly( $this->live_id ) );
	}

	/**
	 * Covers AE21. The documented filter forces staging for everyone.
	 */
	public function test_filter_can_force_staging_for_everyone(): void {
		$granted = get_user_by( 'id', $this->editor_id );
		$granted->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );

		wp_set_current_user( $granted->ID );
		$this->assertTrue( Capabilities::current_user_can_publish_directly( $this->live_id ) );

		add_filter( 'swpub_can_publish_directly', '__return_false' );
		$this->assertFalse( Capabilities::current_user_can_publish_directly( $this->live_id ) );
		remove_filter( 'swpub_can_publish_directly', '__return_false' );

		$this->assertTrue( Capabilities::current_user_can_publish_directly( $this->live_id ) );
	}

	/**
	 * The filter grants as well as denies (R46).
	 *
	 * This is how a site keeps an integration publishing: answer for its own
	 * account on the posts it writes, rather than granting the capability to a
	 * role every human shares.
	 */
	public function test_filter_can_grant_the_bypass_to_a_user_without_the_capability(): void {
		$revisor = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'editor' ) ) );

		wp_set_current_user( $revisor->ID );

		$this->assertFalse( Capabilities::current_user_can_publish_directly( $this->live_id ) );

		add_filter( 'swpub_can_publish_directly', '__return_true' );

		$this->assertTrue( Capabilities::current_user_can_publish_directly( $this->live_id ) );

		remove_filter( 'swpub_can_publish_directly', '__return_true' );

		$this->assertFalse( Capabilities::current_user_can_publish_directly( $this->live_id ) );
	}

	/**
	 * The default answer, null, is the plugin's own capability.
	 *
	 * A filter that declines to decide leaves the rule where the grants put it:
	 * the granted user publishes, everyone else stages.
	 */
	public function test_filter_returning_null_leaves_the_capability_alone(): void {
		$granted = get_user_by( 'id', $this->editor_id );
		$granted->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );

		add_filter( 'swpub_can_publish_directly', '__return_null' );

		wp_set_current_user( $granted->ID );
		$this->assertTrue( Capabilities::current_user_can_publish_directly( $this->live_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( Capabilities::current_user_can_publish_directly( $this->live_id ) );
	}

	/**
	 * A grant never reaches a post the plugin cannot name.
	 */
	public function test_filter_cannot_grant_the_bypass_on_a_missing_post(): void {
		wp_set_current_user( $this->editor_id );

		add_filter( 'swpub_can_publish_directly', '__return_true' );

		$this->assertFalse( Capabilities::current_user_can_publish_directly( 999999 ) );
	}

	/**
	 * The capability is resolved per post type, not per site.
	 *
	 * A grant on posts is not a grant on every type a site registers: the
	 * capability name is built from the type's own publish capability, so the
	 * locked type below needs `swpub_publish_directly_swpub_lockeds` and does
	 * not get it from the grant on posts.
	 */
	public function test_direct_publish_is_resolved_per_post_type(): void {
		register_post_type(
			'swpub_locked',
			array(
				'public'          => true,
				'capability_type' => 'swpub_locked',
				'map_meta_cap'    => true,
			)
		);

		$locked = self::factory()->post->create(
			array(
				'post_type'   => 'swpub_locked',
				'post_status' => 'publish',
			)
		);

		$granted = get_user_by( 'id', $this->editor_id );
		$granted->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );

		wp_set_current_user( $granted->ID );

		$this->assertTrue( Capabilities::current_user_can_publish_directly( $this->live_id ) );
		$this->assertFalse( Capabilities::current_user_can_publish_directly( $locked ) );

		unregister_post_type( 'swpub_locked' );
	}

	/**
	 * A check against a post that does not exist denies rather than allows.
	 */
	public function test_direct_publish_denies_a_missing_post(): void {
		wp_set_current_user( $this->editor_id );

		$this->assertFalse( Capabilities::current_user_can_publish_directly( 0 ) );
		$this->assertFalse( Capabilities::current_user_can_publish_directly( 999999 ) );
	}

	/**
	 * An anonymous visitor never holds it.
	 */
	public function test_anonymous_user_cannot_publish_directly(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( Capabilities::current_user_can_publish_directly( $this->live_id ) );
	}

	/**
	 * Covers AE28. A grant is entry, never exemption: the copy still contains the write.
	 *
	 * The sync client's user was handed direct publishing on purpose, so its first
	 * write to an unstaged post publishes. This post is already staged, and R41
	 * admits no valve at all -- not the capability, not the grant that produced it.
	 */
	public function test_a_granted_user_is_still_contained_by_an_existing_copy(): void {
		$revisor = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$revisor->add_cap( 'publish_posts', false );

		wp_set_current_user( $revisor->ID );

		add_filter( 'swpub_can_publish_directly', '__return_true' );

		$this->assertTrue(
			Capabilities::current_user_can_publish_directly( $this->live_id ),
			'Precondition: the filter must actually grant the bypass.'
		);

		$published = (string) get_post_field( 'post_content', $this->live_id );
		$staged    = (string) get_post_field( 'post_content', $this->staged_copy_id );

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $this->live_id,
					'post_content' => 'Rewritten by the sync client.',
				)
			)
		);

		clean_post_cache( $this->live_id );
		clean_post_cache( $this->staged_copy_id );

		$this->assertSame( $published, (string) get_post_field( 'post_content', $this->live_id ) );
		$this->assertSame(
			$staged,
			(string) get_post_field( 'post_content', $this->staged_copy_id ),
			'A granted user overwrote the staged copy from the published post.'
		);
	}

	/**
	 * The manage helper refuses anything that is not a staged copy.
	 */
	public function test_manage_helper_refuses_non_staged_copies(): void {
		wp_set_current_user( $this->editor_id );

		$this->assertFalse( Capabilities::current_user_can_manage( $this->live_id ) );
		$this->assertFalse( Capabilities::current_user_can_manage( 0 ) );
	}
}
