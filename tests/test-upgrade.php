<?php
/**
 * Stored-state upgrade tests.
 *
 * @package StagedEdits
 */

declare( strict_types = 1 );

namespace StagedEdits\Tests;

use StagedEdits\Baseline_Revision;
use StagedEdits\Capabilities;
use StagedEdits\Drift;
use StagedEdits\Scheduled_Publish;
use StagedEdits\Staged_Copy_Repository;
use StagedEdits\Status;
use StagedEdits\Upgrade;
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

		$this->assertSame( 1, Upgrade::rename_meta() );

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

		Upgrade::rename_meta();

		$found = Staged_Copy_Repository::find_for_live( $live_id );

		$this->assertInstanceOf( \WP_Post::class, $found );
		$this->assertSame( $staged->ID, $found->ID );
	}

	/**
	 * A site with nothing staged is left alone.
	 */
	public function test_nothing_to_rename_changes_nothing(): void {
		$this->assertSame( 0, Upgrade::rename_meta() );
	}

	/**
	 * A pair staged under the plugin's old name is found under its new one.
	 *
	 * The whole of what a staged copy is, as an un-upgraded site holds it: the
	 * old status on the row and the old keys on both sides.
	 */
	public function test_a_pair_staged_before_the_plugin_was_renamed_resolves_again(): void {
		global $wpdb;

		$live_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$staged  = Staged_Copy_Repository::create( get_post( $live_id ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Putting stored state back the way an un-upgraded site has it.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'swpub_staged' ), array( 'ID' => $staged->ID ) );
		$wpdb->update( $wpdb->postmeta, array( 'meta_key' => '_swpub_staged_copy_id' ), array( 'meta_key' => Staged_Copy_Repository::STAGED_COPY_META ) );
		$wpdb->update( $wpdb->postmeta, array( 'meta_key' => '_swpub_live_id' ), array( 'meta_key' => Staged_Copy_Repository::LIVE_META ) );
		$wpdb->update( $wpdb->postmeta, array( 'meta_key' => '_swpub_forked_modified_gmt' ), array( 'meta_key' => Staged_Copy_Repository::FORK_BASELINE_META ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		clean_post_cache( $live_id );
		clean_post_cache( $staged->ID );

		$this->assertNull( Staged_Copy_Repository::find_for_live( $live_id ) );
		$this->assertFalse( Status::is_staged( $staged->ID ) );

		delete_option( 'staged_edits_schema' );
		update_option( 'swpub_schema', 3 );

		Upgrade::maybe_run();

		$found = Staged_Copy_Repository::find_for_live( $live_id );

		$this->assertInstanceOf( \WP_Post::class, $found );
		$this->assertSame( $staged->ID, $found->ID );
		$this->assertTrue( Status::is_staged( $staged->ID ) );
		$this->assertSame( $live_id, Staged_Copy_Repository::find_live_for_staged_copy( $staged->ID )->ID );
		$this->assertNotSame( '', Drift::baseline( $staged->ID ) );

		$this->assertSame( 4, (int) get_option( 'staged_edits_schema' ) );
		$this->assertFalse( get_option( 'swpub_schema' ), 'The option under its old name should be gone.' );
	}

	/**
	 * A publish scheduled before the rename still fires, at the time it was set for.
	 *
	 * WP-Cron fires an event under the hook it was scheduled with. Left under
	 * the old one it would find nothing listening and be consumed.
	 */
	public function test_a_scheduled_publish_moves_to_the_hook_that_is_listened_to(): void {
		$timestamp = time() + HOUR_IN_SECONDS;

		wp_schedule_single_event( $timestamp, 'swpub_publish_scheduled', array( 123 ) );

		$this->assertSame( 1, Upgrade::rename_schedules() );

		$this->assertSame( $timestamp, wp_next_scheduled( Scheduled_Publish::HOOK, array( 123 ) ) );
		$this->assertFalse( wp_next_scheduled( 'swpub_publish_scheduled', array( 123 ) ) );

		$this->assertSame( 0, Upgrade::rename_schedules(), 'A second run has nothing left to move.' );
		$this->assertSame( $timestamp, wp_next_scheduled( Scheduled_Publish::HOOK, array( 123 ) ) );
	}

	/**
	 * A grant a site made under the old capability name still holds.
	 */
	public function test_a_direct_publish_grant_on_a_role_is_carried_over(): void {
		$role = get_role( 'editor' );
		$role->add_cap( 'swpub_publish_directly_posts' );

		$this->assertSame( 1, Upgrade::rename_capabilities() );

		$role = get_role( 'editor' );

		$this->assertTrue( $role->has_cap( Capabilities::PUBLISH_DIRECTLY_POSTS ) );
		$this->assertArrayNotHasKey( 'swpub_publish_directly_posts', $role->capabilities );

		$role->remove_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );
	}

	/**
	 * A grant or a denial made on one user is carried over as what it was.
	 */
	public function test_a_direct_publish_grant_on_a_user_is_carried_over(): void {
		$granted = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$denied  = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );

		$granted->add_cap( 'swpub_publish_directly_pages' );
		$denied->add_cap( 'swpub_publish_directly_posts', false );

		$this->assertSame( 2, Upgrade::rename_capabilities() );

		$granted = get_userdata( $granted->ID );
		$denied  = get_userdata( $denied->ID );

		$this->assertSame( true, $granted->caps['staged_edits_publish_directly_pages'] ?? null );
		$this->assertArrayNotHasKey( 'swpub_publish_directly_pages', $granted->caps );
		$this->assertSame( false, $denied->caps[ Capabilities::PUBLISH_DIRECTLY_POSTS ] ?? null );
		$this->assertArrayNotHasKey( 'swpub_publish_directly_posts', $denied->caps );
	}

	/**
	 * A copy staged before the fork fingerprint existed (VIPPROD-752) is
	 * backfilled from the live row while nothing has touched it since the
	 * fork -- the one place the bytes the fingerprint means still exist,
	 * and the one case the recorded timestamp can vouch for.
	 *
	 * From the live row, not the copy's baseline revision: the copy went
	 * through `wp_insert_post()` sanitization at fork, which can alter
	 * bytes for a user without `unfiltered_html`. The live row did not.
	 */
	public function test_backfill_fingerprints_the_live_row_while_it_is_unmoved(): void {
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

		$this->assertSame(
			Drift::fingerprint( get_post( $live_id ) ),
			get_post_meta( $staged_copy->ID, Staged_Copy_Repository::FORK_FINGERPRINT_META, true )
		);
		$this->assertFalse( Drift::has_drifted( $staged_copy->ID ), 'A backfilled, untouched copy must not read as drifted.' );
	}

	/**
	 * A copy whose live post already moved is left alone: nothing left can
	 * say what its content was at fork, so it stays `unknown` drift, which
	 * is exactly what it was before the fingerprint existed.
	 */
	public function test_backfill_leaves_a_copy_alone_once_the_live_post_moved(): void {
		global $wpdb;

		$live_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$staged_copy = Staged_Copy_Repository::create( get_post( $live_id ) );

		delete_post_meta( $staged_copy->ID, Staged_Copy_Repository::FORK_FINGERPRINT_META );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Core overwrites post_modified_gmt on every update, so it cannot be set through the API.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_modified_gmt' => '2026-08-14 09:00:00' ),
			array( 'ID' => $live_id )
		);
		clean_post_cache( $live_id );
		clean_post_cache( $staged_copy->ID );

		$this->assertSame( 0, Upgrade::backfill_fingerprints() );
		$this->assertSame(
			'',
			get_post_meta( $staged_copy->ID, Staged_Copy_Repository::FORK_FINGERPRINT_META, true )
		);
		$this->assertSame( 'unknown', Drift::kind( $staged_copy->ID ) );
	}

	/**
	 * A copy whose live post is gone has nothing to backfill from.
	 */
	public function test_backfill_leaves_a_copy_without_a_live_post_alone(): void {
		$live_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$staged_copy = Staged_Copy_Repository::create( get_post( $live_id ) );

		delete_post_meta( $staged_copy->ID, Staged_Copy_Repository::FORK_FINGERPRINT_META );
		wp_delete_post( $live_id, true );
		clean_post_cache( $staged_copy->ID );

		$this->assertSame( 0, Upgrade::backfill_fingerprints() );
		$this->assertSame(
			'',
			get_post_meta( $staged_copy->ID, Staged_Copy_Repository::FORK_FINGERPRINT_META, true )
		);
	}
}
