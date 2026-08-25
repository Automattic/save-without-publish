<?php
/**
 * Stored-state upgrade tests.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

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
}
