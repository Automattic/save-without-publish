<?php
/**
 * Live-post transition tests for U10 (R17, R21, R22, R31).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Drift;
use SaveWithoutPublish\Merge;
use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Status;
use SaveWithoutPublish\Transitions;
use WP_Post;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proves a staged copy is never deleted and never promoted, whatever happens above it.
 */
class Test_Transitions extends WP_UnitTestCase {

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
	 * Stages a published post with staged content.
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

		wp_update_post(
			array(
				'ID'           => $this->staged_copy_id,
				'post_content' => 'Launches on October 3.',
			)
		);
	}

	/**
	 * Moves the live post to a status.
	 *
	 * @param string $status The status.
	 * @return void
	 */
	private function move_live_to( string $status ): void {
		wp_update_post(
			array(
				'ID'          => $this->live_id,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Covers AE8. Unpublishing keeps the staged copy and marks it stranded.
	 *
	 * @dataProvider unpublished_states
	 *
	 * @param string $status Status the live post moves to.
	 */
	public function test_unpublishing_strands_but_keeps_the_staged_copy( string $status ): void {
		$this->move_live_to( $status );

		$staged_copy = get_post( $this->staged_copy_id );

		$this->assertInstanceOf( WP_Post::class, $staged_copy, 'The staged copy was deleted.' );
		$this->assertSame( Status::NAME, $staged_copy->post_status, 'The staged copy was promoted.' );
		$this->assertSame( 'Launches on October 3.', $staged_copy->post_content, 'Staged content was lost.' );

		$stranding = Transitions::stranding( $this->staged_copy_id );

		$this->assertSame( Transitions::REASON_UNPUBLISHED, $stranding['reason'] );
		$this->assertSame( $this->live_id, $stranding['live_id'] );
	}

	/**
	 * Statuses that strand a staged copy.
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public function unpublished_states(): array {
		return array(
			'draft'   => array( 'draft' ),
			'pending' => array( 'pending' ),
			'private' => array( 'private' ),
			'trash'   => array( 'trash' ),
		);
	}

	/**
	 * Covers AE8. A stranded staged copy refuses to merge.
	 */
	public function test_a_stranded_staged_copy_refuses_to_merge(): void {
		$this->move_live_to( 'draft' );

		$result = Merge::apply( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_not_published', $result->get_error_code() );
		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
	}

	/**
	 * Covers AE16. Deleting the live post keeps the staged copy and promotes nothing.
	 */
	public function test_deleting_the_live_post_keeps_the_staged_copy(): void {
		$published_before = count( get_posts( array( 'post_status' => 'publish', 'fields' => 'ids' ) ) );

		wp_delete_post( $this->live_id, true );

		$staged_copy = get_post( $this->staged_copy_id );

		$this->assertInstanceOf( WP_Post::class, $staged_copy, 'The staged copy was deleted with its live post.' );
		$this->assertSame( Status::NAME, $staged_copy->post_status, 'The staged copy was promoted to fill the gap.' );
		$this->assertSame( 'Launches on October 3.', $staged_copy->post_content );

		$published_after = count( get_posts( array( 'post_status' => 'publish', 'fields' => 'ids' ) ) );

		$this->assertSame( $published_before - 1, $published_after, 'A published post appeared from nowhere.' );
	}

	/**
	 * Covers AE16. A staged copy whose live post is gone says what it staged.
	 *
	 * Nothing can resolve the title afterwards, so a staged copy nobody can
	 * identify would be nearly as useless as a deleted one.
	 */
	public function test_a_deleted_live_post_is_recorded_by_title(): void {
		wp_delete_post( $this->live_id, true );

		$stranding = Transitions::stranding( $this->staged_copy_id );

		$this->assertSame( Transitions::REASON_DELETED, $stranding['reason'] );
		$this->assertSame( 'Meridian Active, Summer collection', $stranding['live_title'] );
		$this->assertSame( $this->live_id, $stranding['live_id'] );
	}

	/**
	 * Stranding fires an action, so a site can notice.
	 */
	public function test_stranding_fires_an_action(): void {
		$fired = array();

		add_action(
			'swpub_staged_stranded',
			static function ( $staged_copy_id, $reason ) use ( &$fired ): void {
				$fired[] = array( $staged_copy_id, $reason );
			},
			10,
			2
		);

		$this->move_live_to( 'draft' );

		$this->assertSame( array( array( $this->staged_copy_id, Transitions::REASON_UNPUBLISHED ) ), $fired );
	}

	/**
	 * Re-publishing clears the stranded mark and restores ordinary behaviour.
	 */
	public function test_republishing_clears_the_stranded_mark(): void {
		$recovered = 0;
		add_action(
			'swpub_staged_recovered',
			static function () use ( &$recovered ): void {
				++$recovered;
			}
		);

		$this->move_live_to( 'draft' );
		$this->assertNotNull( Transitions::stranding( $this->staged_copy_id ) );

		$this->move_live_to( 'publish' );

		$this->assertNull( Transitions::stranding( $this->staged_copy_id ) );
		$this->assertSame( 1, $recovered );
	}

	/**
	 * A staged copy that survived an unpublish can be merged once the post is back.
	 */
	public function test_a_recovered_staged_copy_can_still_be_merged(): void {
		$this->move_live_to( 'draft' );
		$this->move_live_to( 'publish' );

		/*
		 * Confirming against the current state covers both outcomes. On a real
		 * site the round trip moves post_modified_gmt and drift is reported; in
		 * a test every write lands in the same second, so it may not. An
		 * override with no drift is an ordinary merge, not a bypass, so this
		 * asserts the recovery either way rather than depending on clock
		 * granularity.
		 */
		$shown  = get_post( $this->live_id )->post_modified_gmt;
		$result = Merge::apply( $this->staged_copy_id, $shown );

		$this->assertIsArray( $result, 'A recovered staged copy could not be merged.' );
		$this->assertSame( 'Launches on October 3.', get_post( $this->live_id )->post_content );
	}

	/**
	 * Covers AE6. An ungated write leaves the staged copy intact and reports drift.
	 */
	public function test_an_ungated_write_leaves_the_staged_copy_and_reports_drift(): void {
		global $wpdb;

		wp_update_post(
			array(
				'ID'           => $this->live_id,
				'post_content' => 'Launches in June. Typo fixed.',
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Core overwrites post_modified_gmt on every update, so it cannot be set through the API.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_modified_gmt' => '2026-08-14 09:00:00' ),
			array( 'ID' => $this->live_id )
		);
		clean_post_cache( $this->live_id );

		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
		$this->assertNull( Transitions::stranding( $this->staged_copy_id ), 'An ordinary edit stranded the staged copy.' );
		$this->assertTrue( Drift::has_drifted( $this->staged_copy_id ) );
	}

	/**
	 * Saving a staged copy that no longer exists fails, and never hits the live post.
	 *
	 * The editor can still be open on a staged copy after it was merged or
	 * discarded. That save must not fall through to the published post.
	 */
	public function test_a_save_against_a_deleted_staged_copy_does_not_reach_the_live_post(): void {
		Merge::apply( $this->staged_copy_id );

		$this->assertNull( get_post( $this->staged_copy_id ) );

		$before = get_post( $this->live_id )->post_content;

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->staged_copy_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'content', 'Content from a stale editor tab.' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertTrue( $response->is_error(), 'A save against a deleted staged copy succeeded.' );
		$this->assertSame( $before, get_post( $this->live_id )->post_content, 'A stale save reached the live post.' );
	}

	/**
	 * Deleting an unrelated post does not disturb any staged copy.
	 */
	public function test_deleting_an_unrelated_post_changes_nothing(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_delete_post( $other, true );

		$this->assertNull( Transitions::stranding( $this->staged_copy_id ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
	}

	/**
	 * Trashing a staged copy discards it rather than leaving it in the trash.
	 *
	 * The editor still offers core's "Move to trash" on a staged copy. Left alone it
	 * produces exactly what R14 rules out: unreviewed content sitting in the
	 * trash, and a live post pointing at something that is no longer a valid
	 * staged copy.
	 */
	public function test_trashing_a_staged_copy_discards_it(): void {
		wp_trash_post( $this->staged_copy_id );

		// Pointers go immediately; the row survives until the request ends so
		// core can finish reading it.
		$this->assertSame( '', get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true ) );

		Transitions::flush_discards();

		$this->assertNull( get_post( $this->staged_copy_id ), 'The staged copy was left in the trash.' );

		$live = get_post( $this->live_id );
		$this->assertSame( 'publish', $live->post_status );
		$this->assertSame( 'Launches in June.', $live->post_content );
	}

	/**
	 * Trashing a staged copy hands back the post, not a bare true.
	 *
	 * Callers treat a short-circuit as a successful trash and then build a
	 * response from the post. Core's REST controller re-reads it by ID, which
	 * now returns null, and prepares a response from nothing -- and Gutenberg's
	 * "Move to trash" on a staged copy is exactly that path.
	 */
	public function test_a_trashed_staged_copy_stays_readable_until_the_request_ends(): void {
		$result = wp_trash_post( $this->staged_copy_id );

		// Core re-reads the post after trashing it and builds its response from
		// that read, so deleting during the request leaves callers preparing a
		// response from nothing.
		$this->assertInstanceOf( WP_Post::class, $result );
		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );

		Transitions::flush_discards();

		$this->assertNull( get_post( $this->staged_copy_id ) );
	}

	/**
	 * Deleting a staged copy over REST returns a usable response.
	 */
	public function test_deleting_a_staged_copy_over_rest_returns_a_usable_response(): void {
		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $this->staged_copy_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data, 'The response body was built from a deleted post.' );
		$this->assertFalse( $response->is_error() );

		Transitions::flush_discards();

		$this->assertNull( get_post( $this->staged_copy_id ) );
	}

	/**
	 * Trashing an ordinary post still trashes it.
	 */
	public function test_trashing_an_ordinary_post_is_unaffected(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_trash_post( $other );

		$this->assertSame( 'trash', get_post( $other )->post_status );
	}

	/**
	 * Deleting the staged copy itself does not strand anything or touch the live post.
	 */
	public function test_deleting_the_staged_copy_leaves_the_live_post_alone(): void {
		wp_delete_post( $this->staged_copy_id, true );

		$live = get_post( $this->live_id );

		$this->assertInstanceOf( WP_Post::class, $live );
		$this->assertSame( 'publish', $live->post_status );
		$this->assertSame( 'Launches in June.', $live->post_content );
	}
}
