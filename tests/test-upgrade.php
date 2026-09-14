<?php
/**
 * Stored-state upgrade tests.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Baseline_Revision;
use SaveWithoutPublish\Drift;
use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Upgrade;
use WP_UnitTestCase;

/**
 * Proves a site that staged work before the rename keeps it.
 *
 * The pointer key changed with the vocabulary. A site upgrading without this
 * would keep its staged copies as rows nobody points at: the published post
 * would look clean, and the next edit would stage a second copy beside the
 * first.
 */
class Test_Upgrade extends WP_UnitTestCase {

	/**
	 * The pointer key as it was written before the rename.
	 */
	private const LEGACY_POINTER = '_swpub_shadow_id';

	/**
	 * A pointer written under the old key is found under the new one.
	 */
	public function test_a_legacy_pointer_is_renamed(): void {
		$live_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$staged_copy_id = self::factory()->post->create();

		update_post_meta( $live_id, self::LEGACY_POINTER, $staged_copy_id );
		update_post_meta( $staged_copy_id, Staged_Copy_Repository::LIVE_META, $live_id );

		$this->assertSame( 1, Upgrade::rename_pointers() );

		$this->assertSame(
			(string) $staged_copy_id,
			get_post_meta( $live_id, Staged_Copy_Repository::STAGED_COPY_META, true )
		);
		$this->assertSame( '', get_post_meta( $live_id, self::LEGACY_POINTER, true ) );
	}

	/**
	 * The repository finds a pair that was staged before the rename.
	 *
	 * Renaming the row is only half the job: what matters is that the pointer
	 * resolves again, in both directions.
	 */
	public function test_a_renamed_pair_resolves_again(): void {
		$live_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$staged  = Staged_Copy_Repository::create( get_post( $live_id ) );

		// Put the pointer back under the old key, as an un-upgraded site has it.
		delete_post_meta( $live_id, Staged_Copy_Repository::STAGED_COPY_META );
		update_post_meta( $live_id, self::LEGACY_POINTER, $staged->ID );
		clean_post_cache( $live_id );

		$this->assertNull( Staged_Copy_Repository::find_for_live( $live_id ) );

		Upgrade::rename_pointers();

		$found = Staged_Copy_Repository::find_for_live( $live_id );

		$this->assertInstanceOf( \WP_Post::class, $found );
		$this->assertSame( $staged->ID, $found->ID );
	}

	/**
	 * A site with nothing staged is left alone.
	 */
	public function test_nothing_to_rename_changes_nothing(): void {
		$this->assertSame( 0, Upgrade::rename_pointers() );
	}

	/**
	 * A copy staged before the fork fingerprint existed (VIPPROD-752) is
	 * backfilled from its own baseline revision -- the content as
	 * published at the moment staging began, which is what the fingerprint
	 * has always meant, and the only trace of that moment still available
	 * by the time an upgrade runs.
	 */
	public function test_backfill_fingerprints_reads_the_baseline_revision(): void {
		$live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'As published.',
			)
		);

		$staged_copy = Staged_Copy_Repository::create( get_post( $live_id ) );
		Baseline_Revision::seed( $staged_copy->ID );

		// As a copy staged before this existed: the meta is simply absent.
		delete_post_meta( $staged_copy->ID, Staged_Copy_Repository::FORK_FINGERPRINT_META );
		clean_post_cache( $staged_copy->ID );

		$this->assertSame( 1, Upgrade::backfill_fingerprints() );

		$revisions = wp_get_post_revisions( $staged_copy->ID, array( 'order' => 'ASC' ) );
		$baseline  = reset( $revisions );

		$this->assertNotFalse( $baseline, 'Precondition: the seeded baseline has to exist to read from.' );
		$this->assertSame(
			Drift::fingerprint( $baseline ),
			get_post_meta( $staged_copy->ID, Staged_Copy_Repository::FORK_FINGERPRINT_META, true )
		);
	}

	/**
	 * A copy with no baseline revision to read is left without a
	 * fingerprint rather than backfilled from a guess. `Drift::kind()`
	 * reads that exactly as it reads a missing baseline today, so this
	 * copy is no worse off than before the fingerprint existed.
	 */
	public function test_backfill_leaves_a_copy_without_a_baseline_alone(): void {
		$live_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// create() alone, deliberately: no baseline revision is seeded.
		$staged_copy = Staged_Copy_Repository::create( get_post( $live_id ) );

		delete_post_meta( $staged_copy->ID, Staged_Copy_Repository::FORK_FINGERPRINT_META );
		clean_post_cache( $staged_copy->ID );

		$this->assertSame( 0, Upgrade::backfill_fingerprints() );
		$this->assertSame(
			'',
			get_post_meta( $staged_copy->ID, Staged_Copy_Repository::FORK_FINGERPRINT_META, true )
		);
	}
}
