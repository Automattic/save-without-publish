<?php
/**
 * Incident CLI tests for U14 (R34).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\CLI;
use SaveWithoutPublish\Merge_Marker;
use SaveWithoutPublish\Merge_Resume;
use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Transitions;
use WP_Post;
use WP_UnitTestCase;

/**
 * Proves an engineer can find every staged copy and clear a stranded merge.
 *
 * The command wrappers are thin; what matters is the state they read and the
 * repair they run, so those are exercised directly rather than through WP-CLI.
 */
class Test_CLI extends WP_UnitTestCase {

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

		wp_update_post(
			array(
				'ID'           => $this->staged_copy_id,
				'post_content' => 'Launches on October 3.',
			)
		);
	}

	/**
	 * The row for a given staged copy.
	 *
	 * @param int $staged_copy_id Staged copy post ID.
	 * @return array<string, mixed>|null The row.
	 */
	private function row_for( int $staged_copy_id ): ?array {
		foreach ( CLI::inventory() as $row ) {
			if ( $row['staged_copy_id'] === $staged_copy_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The listing reports a healthy pair accurately.
	 */
	public function test_the_listing_reports_a_healthy_pair(): void {
		$row = $this->row_for( $this->staged_copy_id );

		$this->assertNotNull( $row, 'The staged copy was not listed.' );
		$this->assertSame( $this->live_id, $row['live_id'] );
		$this->assertSame( 'Meridian Active, Summer collection', $row['live_title'] );
		$this->assertSame( 'healthy', $row['state'] );
		$this->assertSame( '', $row['phase'] );
	}

	/**
	 * A staged copy whose live post was unpublished is reported as such.
	 */
	public function test_the_listing_reports_an_unpublished_live_post(): void {
		wp_update_post(
			array(
				'ID'          => $this->live_id,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 'live-unpublished', $this->row_for( $this->staged_copy_id )['state'] );
	}

	/**
	 * A staged copy whose live post was deleted is still listed, and still named.
	 *
	 * This is the case the admin cannot show at all: there is no row left to
	 * label, so without the CLI the staged copy is invisible.
	 */
	public function test_the_listing_reports_a_deleted_live_post(): void {
		wp_delete_post( $this->live_id, true );

		$row = $this->row_for( $this->staged_copy_id );

		$this->assertNotNull( $row, 'A staged copy with no live post vanished from the listing.' );
		$this->assertSame( 'live-deleted', $row['state'] );
		$this->assertStringContainsString( 'Meridian Active, Summer collection', $row['live_title'] );
		$this->assertStringContainsString( 'deleted', $row['live_title'] );
	}

	/**
	 * A merge in flight is reported with its phase and attempt count.
	 */
	public function test_the_listing_reports_a_merge_in_flight(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );
		Merge_Marker::advance( $this->live_id, Merge_Marker::PHASE_WRITING );
		Merge_Marker::record_attempt( $this->live_id );

		$row = $this->row_for( $this->staged_copy_id );

		$this->assertSame( 'merging', $row['state'] );
		$this->assertSame( Merge_Marker::PHASE_WRITING, $row['phase'] );
		$this->assertSame( 1, $row['attempts'] );
	}

	/**
	 * A stranded merge is reported distinctly from one still in flight.
	 */
	public function test_the_listing_reports_a_stranded_merge(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );
		Merge_Marker::advance( $this->live_id, Merge_Marker::PHASE_ADOPTING );
		Merge_Marker::strand( $this->live_id );

		$this->assertSame( 'merge-stranded', $this->row_for( $this->staged_copy_id )['state'] );
	}

	/**
	 * The kill switch does not hide existing staged copies.
	 *
	 * Staging is disabled during an incident, which is exactly when finding the
	 * staged copies that already exist matters most.
	 */
	public function test_the_listing_works_with_staging_disabled(): void {
		add_filter( 'swpub_is_enabled', '__return_false' );

		$row = $this->row_for( $this->staged_copy_id );

		$this->assertNotNull( $row, 'Disabling staging hid existing staged copies.' );
		$this->assertSame( $this->live_id, $row['live_id'] );
		$this->assertSame( 'Launches on October 3.', get_post( $this->staged_copy_id )->post_content );
	}

	/**
	 * Stranding preserves the phase the merge died in.
	 *
	 * Without this the repair has nothing to re-enter: the stranded flag would
	 * have overwritten the only record of where it stopped.
	 */
	public function test_stranding_preserves_the_phase_it_died_in(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );
		Merge_Marker::advance( $this->live_id, Merge_Marker::PHASE_REPARENTING );
		Merge_Marker::strand( $this->live_id );

		$this->assertTrue( Merge_Marker::revive( $this->live_id ) );
		$this->assertSame( Merge_Marker::PHASE_REPARENTING, Merge_Marker::get( $this->live_id )['phase'] );
		$this->assertSame( 0, Merge_Marker::get( $this->live_id )['attempts'] );
	}

	/**
	 * Repair resolves a stranded merge, and is safe to run twice.
	 */
	public function test_repair_resolves_a_stranded_merge_and_is_safe_twice(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );
		Merge_Marker::advance( $this->live_id, Merge_Marker::PHASE_WRITING );

		// Spend the budget the way repeated failures would.
		for ( $i = 0; $i < Merge_Marker::ATTEMPT_CAP; $i++ ) {
			Merge_Marker::record_attempt( $this->live_id );
		}
		Merge_Marker::strand( $this->live_id );

		// Without --force the stranded state is reported, not cleared.
		$reported = CLI::repair_post( $this->live_id );
		$this->assertSame( Merge_Resume::ACTION_STRANDED, $reported['action'] );
		$this->assertFalse( $reported['revived'] );

		$first = CLI::repair_post( $this->live_id, true );

		$this->assertTrue( $first['revived'] );
		$this->assertSame( 'Launches on October 3.', get_post( $this->live_id )->post_content );
		$this->assertNull( get_post( $this->staged_copy_id ), 'The merge did not complete.' );

		// Running it again finds nothing left to do rather than redoing it.
		$second = CLI::repair_post( $this->live_id, true );

		$this->assertFalse( $second['revived'] );
		$this->assertSame( Merge_Resume::ACTION_NONE, $second['action'] );
		$this->assertSame( 'Launches on October 3.', get_post( $this->live_id )->post_content );
	}

	/**
	 * Repair accepts either post of a pair.
	 */
	public function test_repair_accepts_the_staged_copy_id_too(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );
		Merge_Marker::advance( $this->live_id, Merge_Marker::PHASE_WRITING );
		Merge_Marker::strand( $this->live_id );

		$result = CLI::repair_post( $this->staged_copy_id, true );

		$this->assertTrue( $result['revived'] );
		$this->assertNull( get_post( $this->staged_copy_id ) );
	}

	/**
	 * Repair refuses a marker that fails validation, and destroys nothing.
	 */
	public function test_repair_refuses_a_marker_that_fails_validation(): void {
		$bystander = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'An unrelated published post',
			)
		);

		Merge_Marker::start( $this->live_id, $bystander );
		Merge_Marker::strand( $this->live_id );

		$result = CLI::repair_post( $this->live_id, true );

		$this->assertSame( Merge_Resume::ACTION_SURFACE, $result['action'] );
		$this->assertSame( 'invalid_marker', $result['reason'] );
		$this->assertInstanceOf( WP_Post::class, get_post( $bystander ) );
		$this->assertSame( 'publish', get_post( $bystander )->post_status );
		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
	}

	/**
	 * Repairing a post that does not exist reports it rather than failing.
	 */
	public function test_repair_reports_a_missing_post(): void {
		$result = CLI::repair_post( 999999, true );

		$this->assertSame( 'none', $result['action'] );
		$this->assertSame( 'no_post', $result['reason'] );
	}

	/**
	 * A healthy pair is left alone by repair.
	 */
	public function test_repair_leaves_a_healthy_pair_alone(): void {
		$result = CLI::repair_post( $this->live_id, true );

		$this->assertSame( Merge_Resume::ACTION_NONE, $result['action'] );
		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
		$this->assertSame( 'Launches in June.', get_post( $this->live_id )->post_content );
	}

	/**
	 * The listing covers several staged copies at once.
	 */
	public function test_the_listing_covers_every_staged_copy(): void {
		$expected = array( $this->staged_copy_id );

		for ( $i = 0; $i < 5; $i++ ) {
			$live       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			$expected[] = Staged_Copy_Repository::create( get_post( $live ) )->ID;
		}

		$listed = wp_list_pluck( CLI::inventory(), 'staged_copy_id' );

		sort( $expected );
		sort( $listed );

		$this->assertSame( $expected, $listed );
	}

	/**
	 * An orphaned staged copy is listed and named as such.
	 */
	public function test_an_orphaned_staged_copy_is_listed(): void {
		delete_post_meta( $this->staged_copy_id, Staged_Copy_Repository::LIVE_META );

		$row = $this->row_for( $this->staged_copy_id );

		$this->assertSame( 'orphaned', $row['state'] );
		$this->assertSame( '(unknown)', $row['live_title'] );
		$this->assertNull( Transitions::stranding( $this->staged_copy_id ) );
	}
}
