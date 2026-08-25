<?php
/**
 * Media reparenting tests for U8 (R20).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Media;
use SaveWithoutPublish\Merge_Marker;
use SaveWithoutPublish\Staged_Copy_Repository;
use WP_Post;
use WP_UnitTestCase;

/**
 * Proves no attachment is orphaned or deleted by a merge, discard, or resume.
 */
class Test_Media extends WP_UnitTestCase {

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
	 * Uploads an attachment against a post.
	 *
	 * @param int $parent_id Parent post ID.
	 * @return int Attachment ID.
	 */
	private function attach_to( int $parent_id ): int {
		return self::factory()->attachment->create_object(
			array(
				'file'           => 'meridian-fall.jpg',
				'post_parent'    => $parent_id,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Meridian Fall lookbook',
			)
		);
	}

	/**
	 * Covers AE7. An attachment uploaded while staging lands on the live post.
	 */
	public function test_a_staged_attachment_reparents_on_merge(): void {
		$attachment_id = $this->attach_to( $this->staged_copy_id );

		$recorded = Media::attachments_for( $this->staged_copy_id );
		$this->assertSame( array( $attachment_id ), $recorded );

		$this->assertSame( 1, Media::reparent( $this->live_id, $this->staged_copy_id, $recorded ) );
		$this->assertSame( $this->live_id, (int) get_post( $attachment_id )->post_parent );
	}

	/**
	 * Covers AE7. A discarded staged copy leaves its uploads in the library.
	 *
	 * An editor who abandons an edit should not lose the photo they uploaded
	 * for it. Core points a deleted post's attachments up one level rather than
	 * deleting them, so the file survives; this proves we do not undo that.
	 *
	 * The cache is cleaned by hand because core performs that reparenting as a
	 * raw `$wpdb->update` and never cleans the affected attachments' caches, so
	 * `get_post()` keeps returning the old parent for the rest of the request.
	 */
	public function test_a_discarded_staged_copy_leaves_its_uploads_in_the_library(): void {
		$attachment_id = $this->attach_to( $this->staged_copy_id );

		wp_delete_post( $this->staged_copy_id, true );
		clean_post_cache( $attachment_id );

		$attachment = get_post( $attachment_id );

		$this->assertInstanceOf( WP_Post::class, $attachment, 'Discarding a staged copy deleted its upload.' );
		$this->assertSame( 'attachment', $attachment->post_type );
		$this->assertNotSame( $this->staged_copy_id, (int) $attachment->post_parent );
	}

	/**
	 * An attachment belonging to another post is never reparented.
	 *
	 * The ID list comes from the merge marker, which is never trusted, so a
	 * stale or tampered entry must not be able to steal someone else's media.
	 */
	public function test_an_attachment_owned_by_another_post_is_not_reparented(): void {
		$other_post    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$other_media   = $this->attach_to( $other_post );
		$staged_media  = $this->attach_to( $this->staged_copy_id );

		$moved = Media::reparent(
			$this->live_id,
			$this->staged_copy_id,
			array( $staged_media, $other_media )
		);

		$this->assertSame( 1, $moved );
		$this->assertSame( $this->live_id, (int) get_post( $staged_media )->post_parent );
		$this->assertSame( $other_post, (int) get_post( $other_media )->post_parent );
	}

	/**
	 * An unattached attachment is not swept up.
	 */
	public function test_an_unattached_attachment_is_not_reparented(): void {
		$loose = $this->attach_to( 0 );

		$this->assertSame( 0, Media::reparent( $this->live_id, $this->staged_copy_id, array( $loose ) ) );
		$this->assertSame( 0, (int) get_post( $loose )->post_parent );
	}

	/**
	 * A non-attachment ID in the list is ignored rather than reparented.
	 */
	public function test_a_non_attachment_id_is_ignored(): void {
		$bystander = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( 0, Media::reparent( $this->live_id, $this->staged_copy_id, array( $bystander ) ) );
		$this->assertSame( 'publish', get_post( $bystander )->post_status );
		$this->assertSame( 0, (int) get_post( $bystander )->post_parent );
	}

	/**
	 * Aborting mid-set and resuming lands every attachment on the live post.
	 *
	 * This is the interrupted-reparenting row of the recovery table: the marker
	 * holds the full set, so the resume finishes the half that did not move
	 * without needing to query a post that may already be gone.
	 */
	public function test_an_interrupted_reparenting_finishes_on_resume(): void {
		$first  = $this->attach_to( $this->staged_copy_id );
		$second = $this->attach_to( $this->staged_copy_id );
		$third  = $this->attach_to( $this->staged_copy_id );

		Merge_Marker::start( $this->live_id, $this->staged_copy_id );
		Merge_Marker::record_attachments( $this->live_id, Media::attachments_for( $this->staged_copy_id ) );

		// The merge dies after moving one of the three.
		Media::reparent( $this->live_id, $this->staged_copy_id, array( $first ) );

		$this->assertSame( $this->live_id, (int) get_post( $first )->post_parent );
		$this->assertSame( $this->staged_copy_id, (int) get_post( $second )->post_parent );

		// Resume works from the recorded set, not from a fresh query.
		$recorded = Merge_Marker::get( $this->live_id )['attachments'];
		$this->assertSame( array( $first, $second, $third ), $recorded );

		$moved = Media::reparent( $this->live_id, $this->staged_copy_id, $recorded );

		$this->assertSame( 2, $moved, 'Resume re-moved an attachment that had already moved.' );

		foreach ( array( $first, $second, $third ) as $attachment_id ) {
			$this->assertSame( $this->live_id, (int) get_post( $attachment_id )->post_parent );
		}
	}

	/**
	 * Reparenting twice moves nothing the second time.
	 */
	public function test_reparenting_is_idempotent(): void {
		$attachment_id = $this->attach_to( $this->staged_copy_id );
		$recorded      = array( $attachment_id );

		$this->assertSame( 1, Media::reparent( $this->live_id, $this->staged_copy_id, $recorded ) );
		$this->assertSame( 0, Media::reparent( $this->live_id, $this->staged_copy_id, $recorded ) );
		$this->assertSame( $this->live_id, (int) get_post( $attachment_id )->post_parent );
	}

	/**
	 * Reparenting does not eat backslashes out of an attachment's text.
	 *
	 * `wp_update_post()` merges the existing row unslashed before re-inserting
	 * it, and the insert strips one level of slashes from the whole array. So
	 * passing two numeric fields still rewrites the text ones, and a caption
	 * containing a Windows path or a regex loses a character on every merge
	 * that moves the image.
	 */
	public function test_reparenting_preserves_backslashes_in_attachment_text(): void {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'meridian-fall.jpg',
				'post_parent'    => $this->staged_copy_id,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Meridian \\\\ Fall',
				'post_excerpt'   => 'Shot on C:\\\\photos\\\\meridian, matched by \\\\d+ files.',
				'post_content'   => 'Escaped like \\\\n and \\\\t, deliberately.',
			)
		);

		/*
		 * Compared against what was actually stored, not against what was
		 * passed in: `wp_insert_post()` strips one level of slashes on the way
		 * in, so the fixture's own creation already consumed a level. What is
		 * under test is whether reparenting consumes another.
		 */
		$before = get_post( $attachment_id );

		$this->assertStringContainsString( '\\', $before->post_excerpt, 'The fixture stored no backslashes to lose.' );

		Media::reparent( $this->live_id, $this->staged_copy_id, array( $attachment_id ) );

		$moved = get_post( $attachment_id );

		$this->assertSame( $this->live_id, (int) $moved->post_parent );
		$this->assertSame( $before->post_excerpt, $moved->post_excerpt );
		$this->assertSame( $before->post_content, $moved->post_content );
		$this->assertSame( $before->post_title, $moved->post_title );
	}

	/**
	 * A staged copy with no attachments changes nothing.
	 */
	public function test_a_staged_copy_with_no_attachments_changes_nothing(): void {
		$untouched = $this->attach_to( $this->live_id );

		$this->assertSame( array(), Media::attachments_for( $this->staged_copy_id ) );
		$this->assertSame( 0, Media::reparent( $this->live_id, $this->staged_copy_id, array() ) );
		$this->assertSame( $this->live_id, (int) get_post( $untouched )->post_parent );
	}

	/**
	 * The lookup pages rather than fetching an unbounded set.
	 *
	 * The page size is 100, so this proves the paging loop continues past one
	 * page instead of silently truncating the set at the query limit.
	 */
	public function test_the_lookup_pages_past_its_query_limit(): void {
		$expected = array();

		for ( $i = 0; $i < 105; $i++ ) {
			$expected[] = $this->attach_to( $this->staged_copy_id );
		}

		$found = Media::attachments_for( $this->staged_copy_id );

		$this->assertCount( 105, $found, 'The lookup truncated at its page size.' );
		$this->assertSame( $expected, $found );
	}
}
