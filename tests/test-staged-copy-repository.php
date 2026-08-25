<?php
/**
 * Staged copy repository tests for U3 (R3, R24, R29).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Status;
use WP_Post;
use WP_UnitTestCase;

/**
 * Proves staged copies are found without queries and pointers are never trusted.
 */
class Test_Staged_Copy_Repository extends WP_UnitTestCase {

	/**
	 * A published post to stage.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Creates the live post.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Launches in June.',
				'post_excerpt' => 'Summer.',
			)
		);
	}

	/**
	 * A fork creates one staged copy carrying the live post's content.
	 */
	public function test_create_makes_one_staged_copy_with_live_content(): void {
		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );
		$this->assertSame( Status::NAME, $staged_copy->post_status );
		$this->assertSame( 'Meridian Active, Summer collection', $staged_copy->post_title );
		$this->assertSame( 'Launches in June.', $staged_copy->post_content );
		$this->assertSame( 'Summer.', $staged_copy->post_excerpt );
	}

	/**
	 * The live post is untouched by a fork (R1).
	 */
	public function test_create_does_not_modify_the_live_post(): void {
		$before = get_post( $this->live_id );

		Staged_Copy_Repository::create( $before );

		$after = get_post( $this->live_id );

		$this->assertSame( $before->post_content, $after->post_content );
		$this->assertSame( $before->post_modified_gmt, $after->post_modified_gmt );
		$this->assertSame( 'publish', $after->post_status );
	}

	/**
	 * Covers AE2. A second create returns the existing staged copy.
	 */
	public function test_create_returns_the_existing_staged_copy(): void {
		$first  = Staged_Copy_Repository::create( get_post( $this->live_id ) );
		$second = Staged_Copy_Repository::create( get_post( $this->live_id ) );

		$this->assertSame( $first->ID, $second->ID );
	}

	/**
	 * Both pointers are written and agree.
	 */
	public function test_pointers_are_written_in_both_directions(): void {
		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );

		$this->assertSame(
			$staged_copy->ID,
			(int) get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true )
		);
		$this->assertSame(
			$this->live_id,
			(int) get_post_meta( $staged_copy->ID, Staged_Copy_Repository::LIVE_META, true )
		);
		$this->assertTrue( Staged_Copy_Repository::pointers_agree( $this->live_id, $staged_copy->ID ) );
	}

	/**
	 * The reverse pointer exists from the moment the row does.
	 */
	public function test_reverse_pointer_is_written_with_the_insert(): void {
		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );

		$this->assertNotEmpty( get_post_meta( $staged_copy->ID, Staged_Copy_Repository::LIVE_META, true ) );
	}

	/**
	 * The fork baseline records the live post's modified time (I8).
	 */
	public function test_fork_baseline_records_live_modified_time(): void {
		$live   = get_post( $this->live_id );
		$staged_copy = Staged_Copy_Repository::create( $live );

		$this->assertSame(
			$live->post_modified_gmt,
			get_post_meta( $staged_copy->ID, Staged_Copy_Repository::FORK_BASELINE_META, true )
		);
	}

	/**
	 * The staged copy slug is deterministic and never equals the live slug (KTD8).
	 */
	public function test_staged_copy_slug_differs_from_the_live_slug(): void {
		$live   = get_post( $this->live_id );
		$staged_copy = Staged_Copy_Repository::create( $live );

		$this->assertNotSame( $live->post_name, $staged_copy->post_name );
		$this->assertStringContainsString( (string) $this->live_id, $staged_copy->post_name );
	}

	/**
	 * Locked fields are copied so the staged copy reads as a faithful copy (KTD18).
	 */
	public function test_locked_fields_are_copied_at_fork(): void {
		$category_id = self::factory()->category->create( array( 'name' => 'Collections' ) );
		wp_set_object_terms( $this->live_id, array( $category_id ), 'category' );

		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );

		$this->assertContains(
			$category_id,
			wp_get_object_terms( $staged_copy->ID, 'category', array( 'fields' => 'ids' ) )
		);
	}

	/**
	 * A pointer to a post that is not staged is rejected and cleared.
	 */
	public function test_pointer_to_a_non_staged_post_is_rejected_and_cleared(): void {
		$impostor = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, $impostor );

		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame( '', get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true ) );
	}

	/**
	 * A pointer whose target does not point back is rejected.
	 *
	 * This is the copied-pointer case: a duplication plugin cloning the forward
	 * pointer onto another post must not resolve to the original's staged copy.
	 */
	public function test_pointer_without_agreeing_reverse_is_rejected(): void {
		$staged_copy    = Staged_Copy_Repository::create( get_post( $this->live_id ) );
		$other_live = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		update_post_meta( $other_live, Staged_Copy_Repository::STAGED_COPY_META, $staged_copy->ID );

		$this->assertNull( Staged_Copy_Repository::find_for_live( $other_live ) );
		$this->assertInstanceOf( WP_Post::class, Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}

	/**
	 * A pointer of the wrong post type is rejected.
	 */
	public function test_pointer_of_mismatched_post_type_is_rejected(): void {
		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );

		wp_update_post(
			array(
				'ID'        => $staged_copy->ID,
				'post_type' => 'page',
			)
		);

		$this->assertFalse( Staged_Copy_Repository::pointers_agree( $this->live_id, $staged_copy->ID ) );
	}

	/**
	 * A pointer to a deleted post resolves to nothing and is cleared, so the
	 * fail-closed rule cannot lock an editor out of the post permanently.
	 */
	public function test_stale_pointer_to_deleted_post_is_cleared(): void {
		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );
		wp_delete_post( $staged_copy->ID, true );

		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame( '', get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true ) );
	}

	/**
	 * The reverse lookup resolves and validates the same way.
	 */
	public function test_reverse_lookup_resolves_the_live_post(): void {
		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );

		$live = Staged_Copy_Repository::find_live_for_staged_copy( $staged_copy->ID );

		$this->assertInstanceOf( WP_Post::class, $live );
		$this->assertSame( $this->live_id, $live->ID );
	}

	/**
	 * Unlink removes both pointers.
	 */
	public function test_unlink_clears_both_pointers(): void {
		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );

		Staged_Copy_Repository::unlink( $this->live_id, $staged_copy->ID );

		$this->assertSame( '', get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true ) );
		$this->assertSame( '', get_post_meta( $staged_copy->ID, Staged_Copy_Repository::LIVE_META, true ) );
	}

	/**
	 * Pointer meta is protected, so it is never exposed over REST (KTD14).
	 */
	public function test_pointer_meta_is_protected(): void {
		$this->assertTrue( is_protected_meta( Staged_Copy_Repository::STAGED_COPY_META, 'post' ) );
		$this->assertTrue( is_protected_meta( Staged_Copy_Repository::LIVE_META, 'post' ) );
		$this->assertTrue( is_protected_meta( Staged_Copy_Repository::FORK_BASELINE_META, 'post' ) );
	}

	/**
	 * Nonsense input resolves to nothing rather than fataling.
	 */
	public function test_lookups_tolerate_missing_and_invalid_ids(): void {
		$this->assertNull( Staged_Copy_Repository::find_for_live( 0 ) );
		$this->assertNull( Staged_Copy_Repository::find_live_for_staged_copy( 0 ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertFalse( Staged_Copy_Repository::pointers_agree( $this->live_id, $this->live_id ) );
	}

	/**
	 * A backslash in the published post survives the fork.
	 *
	 * `wp_insert_post()` expects slashed data and strips one level from whatever
	 * it is given, but a post read back through `get_post()` is unslashed. Handing
	 * it straight over loses a backslash on every fork -- the same trap
	 * `includes/class-media.php` guards against one file away.
	 */
	public function test_backslashes_survive_the_fork(): void {
		$content = 'A path C:\\Users\\ and a quote "here" and an apostrophe it\'s.';
		$title   = 'Title with a backslash \\ and a "quote"';

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $this->live_id,
					'post_title'   => $title,
					'post_content' => $content,
					'post_excerpt' => $content,
				)
			)
		);

		$live        = get_post( $this->live_id );
		$staged_copy = Staged_Copy_Repository::create( $live );

		$this->assertSame( $live->post_title, $staged_copy->post_title );
		$this->assertSame( $live->post_content, $staged_copy->post_content );
		$this->assertSame( $live->post_excerpt, $staged_copy->post_excerpt );
	}
}
