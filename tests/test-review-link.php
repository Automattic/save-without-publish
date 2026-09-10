<?php
/**
 * Review surface tests (R23).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Baseline_Revision;
use SaveWithoutPublish\Review_Link;
use SaveWithoutPublish\Staged_Copy_Repository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proves the review lands on core's revisions view, spanning the whole change.
 *
 * The editor's revisions view has no `from` and `to`. It diffs a revision
 * against whichever revision precedes it in the list core hands it, so the span
 * is made in the list rather than in the URL, and these are the assertions that
 * say the list is the right two entries.
 */
class Test_Review_Link extends WP_UnitTestCase {

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
	 * Stages a change and saves onto it more than once.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'As published: the collection lands in June.',
			)
		);

		$staged_copy = Staged_Copy_Repository::create( get_post( $this->live_id ) );

		$this->staged_copy_id = $staged_copy->ID;

		// The baseline holds the content as published. The editor's entry points
		// seed it; a test that arranges the copy directly has to say so.
		Baseline_Revision::seed( $this->staged_copy_id );

		foreach ( array( 'First staged pass.', 'Second staged pass.', 'The final wording.' ) as $content ) {
			wp_update_post(
				array(
					'ID'           => $this->staged_copy_id,
					'post_content' => $content,
				)
			);
		}
	}

	/**
	 * The revision IDs of a post, oldest first.
	 *
	 * @param int $post_id Post ID.
	 * @return int[] Revision IDs.
	 */
	private function revisions_of( int $post_id ): array {
		return array_values(
			array_map(
				static function ( $revision ) {
					return (int) $revision->ID;
				},
				wp_get_post_revisions( $post_id, array( 'order' => 'ASC' ) )
			)
		);
	}

	/**
	 * The revision IDs the REST collection answers with, oldest first.
	 *
	 * Asked over REST rather than of the filter directly, because what matters
	 * is what the editor is handed, and the editor asks this way.
	 *
	 * @param int $post_id Post ID.
	 * @return int[] Revision IDs.
	 */
	private function rest_revisions_of( int $post_id ): array {
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id . '/revisions' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'The revisions collection refused.' );

		$ids = array_map(
			static function ( $revision ) {
				return (int) $revision['id'];
			},
			$response->get_data()
		);

		sort( $ids );

		return $ids;
	}

	/**
	 * The review opens the editor's own revisions view on the newest save.
	 */
	public function test_the_review_link_opens_the_editor_revisions_view(): void {
		$revisions = $this->revisions_of( $this->staged_copy_id );
		$newest    = (int) end( $revisions );

		$url = Review_Link::for_staged_copy( $this->staged_copy_id );

		$this->assertStringContainsString( 'post.php', $url );
		$this->assertStringContainsString( 'post=' . $this->staged_copy_id, $url );
		$this->assertStringContainsString( 'action=edit', $url );
		$this->assertStringContainsString( 'revision=' . $newest, $url );
	}

	/**
	 * A staged copy is handed the two ends of its change and nothing between.
	 *
	 * This is the span. With these two the view's "revision before this one" is
	 * the content as published, so the whole staged change reads in one pass
	 * rather than as the last save alone.
	 */
	public function test_a_staged_copys_revisions_span_the_change(): void {
		$all = $this->revisions_of( $this->staged_copy_id );

		$this->assertGreaterThan( 2, count( $all ), 'Precondition: the copy needs saves between its ends.' );

		$this->assertSame(
			array( (int) $all[0], (int) end( $all ) ),
			$this->rest_revisions_of( $this->staged_copy_id )
		);
	}

	/**
	 * Nothing is deleted, and every other way of asking still sees them all.
	 */
	public function test_the_intermediate_revisions_are_kept(): void {
		$this->assertGreaterThan(
			2,
			count( wp_get_post_revisions( $this->staged_copy_id ) ),
			'The saves between the ends were removed rather than left out of one view.'
		);
	}

	/**
	 * A published post's own history is its own history.
	 */
	public function test_a_published_posts_revisions_are_untouched(): void {
		foreach ( array( 'A correction.', 'Another correction.', 'A third.' ) as $content ) {
			wp_update_post(
				array(
					'ID'           => $this->live_id,
					'post_content' => $content,
				)
			);
		}

		$this->assertSame(
			$this->revisions_of( $this->live_id ),
			$this->rest_revisions_of( $this->live_id )
		);
	}

	/**
	 * A copy with nothing staged on top of its baseline is left alone.
	 */
	public function test_a_copy_without_a_second_end_is_left_alone(): void {
		$live = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'As published.',
			)
		);

		$staged_copy = Staged_Copy_Repository::create( get_post( $live ) );

		Baseline_Revision::seed( $staged_copy->ID );

		$this->assertSame(
			$this->revisions_of( $staged_copy->ID ),
			$this->rest_revisions_of( $staged_copy->ID )
		);
	}

	/**
	 * A copy holding only its baseline is offered no review link.
	 *
	 * One revision is not a change to review: a link to the newest of the
	 * two would open on a screen that had nothing on the other side of the
	 * comparison to show.
	 */
	public function test_no_review_link_without_two_ends(): void {
		$live = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'As published.',
			)
		);

		$staged_copy = Staged_Copy_Repository::create( get_post( $live ) );

		Baseline_Revision::seed( $staged_copy->ID );

		$this->assertSame( '', Review_Link::for_staged_copy( $staged_copy->ID ) );
	}

	/**
	 * The link returns once a second end exists.
	 */
	public function test_the_review_link_appears_after_the_first_staged_save(): void {
		$live = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'As published.',
			)
		);

		$staged_copy = Staged_Copy_Repository::create( get_post( $live ) );

		Baseline_Revision::seed( $staged_copy->ID );

		$this->assertSame( '', Review_Link::for_staged_copy( $staged_copy->ID ) );

		wp_update_post(
			array(
				'ID'           => $staged_copy->ID,
				'post_content' => 'Staged wording.',
			)
		);

		$this->assertNotSame( '', Review_Link::for_staged_copy( $staged_copy->ID ) );
	}

	/**
	 * A staged copy that never had a baseline seeded is left with no link
	 * either -- the same "fewer than two ends" answer, from the other side.
	 */
	public function test_no_review_link_without_any_revision_at_all(): void {
		$live = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'As published.',
			)
		);

		$staged_copy = Staged_Copy_Repository::create( get_post( $live ) );

		$this->assertSame( '', Review_Link::for_staged_copy( $staged_copy->ID ) );
	}
}
