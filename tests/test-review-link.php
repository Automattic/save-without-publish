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
	 *
	 * Forced onto the editor surface: this is what that surface names, on
	 * whichever WordPress version the suite happens to run against
	 * (VIPPROD-753's own default is version-dependent below WordPress 7.0).
	 */
	public function test_the_review_link_opens_the_editor_revisions_view(): void {
		add_filter( 'swpub_review_surface', fn () => 'editor' );

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

	/**
	 * The default surface follows the running WordPress; the filter
	 * overrides it; anything else falls back to the newer, more common
	 * answer rather than silently landing on a screen nobody asked for.
	 */
	public function test_the_review_surface_follows_the_version_and_the_filter(): void {
		$expected = version_compare( get_bloginfo( 'version' ), '7.0', '>=' ) ? 'editor' : 'classic';

		$this->assertSame( $expected, Review_Link::surface() );

		add_filter( 'swpub_review_surface', fn () => 'classic' );
		$this->assertSame( 'classic', Review_Link::surface() );

		add_filter( 'swpub_review_surface', fn () => 'editor', 20 );
		$this->assertSame( 'editor', Review_Link::surface() );

		add_filter( 'swpub_review_surface', fn () => 'nonsense', 30 );
		$this->assertSame( 'editor', Review_Link::surface(), 'An unrecognised value must fall back to the newer, more common surface rather than nothing.' );
	}

	/**
	 * Forced onto the classic surface, the link names both ends directly.
	 */
	public function test_the_classic_surface_links_the_two_ends(): void {
		add_filter( 'swpub_review_surface', fn () => 'classic' );

		$all = $this->revisions_of( $this->staged_copy_id );
		$url = Review_Link::for_staged_copy( $this->staged_copy_id );

		$this->assertStringContainsString( 'revision.php', $url );
		$this->assertStringContainsString( 'from=' . $all[0], $url );
		$this->assertStringContainsString( 'to=' . end( $all ), $url );
	}

	/**
	 * Core's classic screen offers no Restore for a staged copy, and keeps
	 * offering it, unchanged, for a published post.
	 *
	 * The published post half uses a post of its own rather than
	 * `$this->live_id`: that one already has a staged copy from `set_up()`,
	 * and the write guard neutralizes a direct content write to a post in
	 * that state (R55), leaving nothing here for a revision to record.
	 */
	public function test_restore_is_withheld_on_a_staged_copy_and_kept_on_a_published_post(): void {
		$staged_copy_revisions = wp_get_post_revisions( $this->staged_copy_id, array( 'order' => 'ASC' ) );
		$staged_copy_revision  = end( $staged_copy_revisions );

		$staged_data = apply_filters(
			'wp_prepare_revision_for_js',
			array( 'restoreUrl' => 'https://example.test/restore-me' ),
			$staged_copy_revision,
			get_post( $this->staged_copy_id )
		);

		$this->assertFalse( $staged_data['restoreUrl'] );

		$other_live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'As published.',
			)
		);

		// Plainly called, `wp_update_post()` is an unidentified write, which
		// this plugin forks into a staged copy rather than writing the post
		// directly (KTD30b) -- correctly, and not what this test is about.
		// The filter is the direct-publish grant's own escape hatch for
		// exactly this: answering per write rather than fighting a role's
		// capabilities.
		add_filter( 'swpub_can_publish_directly', '__return_true' );

		wp_update_post(
			array(
				'ID'           => $other_live_id,
				'post_content' => 'A correction.',
			)
		);

		remove_filter( 'swpub_can_publish_directly', '__return_true' );

		$live_revisions = wp_get_post_revisions( $other_live_id, array( 'order' => 'ASC' ) );
		$live_revision  = end( $live_revisions );

		$this->assertNotFalse( $live_revision, 'Precondition: the edit above has to have left a revision.' );

		$live_data = apply_filters(
			'wp_prepare_revision_for_js',
			array( 'restoreUrl' => 'https://example.test/restore-me' ),
			$live_revision,
			get_post( $other_live_id )
		);

		$this->assertSame( 'https://example.test/restore-me', $live_data['restoreUrl'] );
	}

	/**
	 * A staged copy of its own, with a baseline seeded, ready for a change.
	 *
	 * @return int Staged copy post ID.
	 */
	private function fresh_staged_copy(): int {
		$live = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'As published.',
				'post_excerpt' => 'The Summer collection.',
			)
		);

		$staged_copy = Staged_Copy_Repository::create( get_post( $live ) );

		Baseline_Revision::seed( $staged_copy->ID );

		return $staged_copy->ID;
	}

	/**
	 * Only the fields that actually differ are named -- not the copy's
	 * whole shape, and not a field untouched since the fork.
	 */
	public function test_changed_fields_names_what_differs(): void {
		$staged_copy_id = $this->fresh_staged_copy();

		$this->assertSame( array(), Review_Link::changed_fields( $staged_copy_id ), 'A baseline alone is not yet a change.' );

		wp_update_post(
			array(
				'ID'         => $staged_copy_id,
				'post_title' => 'Meridian Active, Autumn collection',
			)
		);

		$this->assertSame( array( 'title' ), Review_Link::changed_fields( $staged_copy_id ) );

		wp_update_post(
			array(
				'ID'           => $staged_copy_id,
				'post_excerpt' => 'The Autumn collection.',
			)
		);

		$this->assertSame( array( 'title', 'excerpt' ), Review_Link::changed_fields( $staged_copy_id ) );

		wp_update_post(
			array(
				'ID'           => $staged_copy_id,
				'post_content' => 'As staged.',
			)
		);

		$this->assertSame( array( 'title', 'content', 'excerpt' ), Review_Link::changed_fields( $staged_copy_id ) );
	}

	/**
	 * A content-only change names content alone -- title and excerpt are
	 * not swept in just because something changed.
	 */
	public function test_changed_fields_names_content_alone_when_only_content_changed(): void {
		$this->assertSame( array( 'content' ), Review_Link::changed_fields( $this->staged_copy_id ) );
	}

	/**
	 * The classic screen is always where "Compare as text" goes, on either
	 * surface, since it is the one core screen that diffs the title and
	 * the excerpt as well as the content.
	 */
	public function test_compare_as_text_url_names_the_two_ends_on_the_classic_screen(): void {
		// Forced to the in-editor surface: `compare_as_text_url()` must stay
		// on the classic screen regardless, which this proves by pinning
		// the surface `for_staged_copy()` would otherwise use for review
		// itself.
		add_filter( 'swpub_review_surface', fn () => 'editor' );

		$all = $this->revisions_of( $this->staged_copy_id );
		$url = Review_Link::compare_as_text_url( $this->staged_copy_id );

		$this->assertStringContainsString( 'revision.php', $url );
		$this->assertStringContainsString( 'from=' . $all[0], $url );
		$this->assertStringContainsString( 'to=' . end( $all ), $url );
	}

	/**
	 * A copy with only its baseline has nothing to compare as text either.
	 */
	public function test_compare_as_text_url_needs_two_ends(): void {
		$staged_copy_id = $this->fresh_staged_copy();

		$this->assertSame( '', Review_Link::compare_as_text_url( $staged_copy_id ) );
	}
}
