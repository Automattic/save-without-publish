<?php
/**
 * Merge tests for U7 (R8, R9, R10, R11, R30, R31).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Capabilities;
use SaveWithoutPublish\Merge;
use SaveWithoutPublish\Merge_Marker;
use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Status;
use WP_Post;
use WP_UnitTestCase;

/**
 * Proves the merge lands staged content without destroying anything.
 */
class Test_Merge extends WP_UnitTestCase {

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
	 * The published content before any staging.
	 */
	private const PUBLISHED_CONTENT = 'The Summer collection launches in June.';

	/**
	 * The staged replacement.
	 */
	private const STAGED_CONTENT = 'The Fall collection launches on October 3.';

	/**
	 * Stages a published post with a staged edit already saved.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => self::PUBLISHED_CONTENT,
				'post_excerpt' => 'Summer.',
			)
		);

		$this->staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;

		$this->stage( 'Meridian Active, Fall collection', self::STAGED_CONTENT );
	}

	/**
	 * Writes staged content to the staged copy, leaving a revision behind.
	 *
	 * @param string $title   Staged title.
	 * @param string $content Staged content.
	 * @return void
	 */
	private function stage( string $title, string $content ): void {
		wp_update_post(
			array(
				'ID'           => $this->staged_copy_id,
				'post_title'   => $title,
				'post_content' => $content,
			)
		);

		wp_save_post_revision( $this->staged_copy_id );
	}

	/**
	 * Revisions of a post, oldest first.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post[] Revisions.
	 */
	private function revisions_of( int $post_id ): array {
		return array_values( wp_get_post_revisions( $post_id, array( 'order' => 'ASC' ) ) );
	}

	/**
	 * Covers AE4. Staged content lands, history is adopted, staged copy is gone.
	 */
	public function test_a_merge_lands_content_history_and_removes_the_staged_copy(): void {
		$staged_revisions = $this->revisions_of( $this->staged_copy_id );
		$this->assertNotEmpty( $staged_revisions, 'The fixture staged no revisions to adopt.' );

		$result = Merge::apply( $this->staged_copy_id );

		$this->assertIsArray( $result, 'The merge failed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );

		$live = get_post( $this->live_id );
		$this->assertSame( self::STAGED_CONTENT, $live->post_content );
		$this->assertSame( 'Meridian Active, Fall collection', $live->post_title );

		$this->assertNull( get_post( $this->staged_copy_id ), 'The staged copy survived the merge.' );

		// Every staged revision now belongs to the live post.
		$live_revision_ids = wp_list_pluck( $this->revisions_of( $this->live_id ), 'ID' );

		foreach ( $staged_revisions as $revision ) {
			$this->assertContains( $revision->ID, $live_revision_ids, 'A staged revision was lost.' );
		}
	}

	/**
	 * Covers AE4. The pre-merge published content is restorable.
	 *
	 * This is the promise the whole product rests on: a merge is undoable.
	 */
	public function test_the_pre_merge_content_is_restorable(): void {
		$result = Merge::apply( $this->staged_copy_id );

		$snapshot_id = $result['snapshot_id'];
		$this->assertGreaterThan( 0, $snapshot_id, 'No snapshot was recorded.' );

		$snapshot = get_post( $snapshot_id );
		$this->assertSame( self::PUBLISHED_CONTENT, $snapshot->post_content );

		// Restoring a revision writes to the published post, which stages like
		// any other write unless the restorer holds the bypass.
		$granted = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$granted->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );
		wp_set_current_user( $granted->ID );

		wp_restore_post_revision( $snapshot_id );

		$this->assertSame( self::PUBLISHED_CONTENT, get_post( $this->live_id )->post_content );
	}

	/**
	 * The newest revision matches the merged content (KTD6).
	 *
	 * If core's revision were suppressed on the merge write, the newest
	 * revision would be the pre-merge snapshot while the post held merged
	 * content, and anything treating the newest revision as current would
	 * silently revert the article.
	 */
	public function test_the_newest_revision_matches_the_merged_content(): void {
		Merge::apply( $this->staged_copy_id );

		$revisions = $this->revisions_of( $this->live_id );
		$newest    = end( $revisions );

		$this->assertSame( self::STAGED_CONTENT, $newest->post_content );

		// Restoring the newest revision changes nothing.
		wp_restore_post_revision( $newest->ID );
		$this->assertSame( self::STAGED_CONTENT, get_post( $this->live_id )->post_content );
	}

	/**
	 * Covers AE12's second half. Everything except the staged fields is intact.
	 *
	 * A merge that carried the staged copy's status or slug across would unpublish
	 * the article and break every inbound link.
	 */
	public function test_a_merge_changes_nothing_but_the_staged_fields(): void {
		$category = self::factory()->category->create( array( 'name' => 'Collections' ) );
		wp_set_post_categories( $this->live_id, array( $category ) );

		$before = get_post( $this->live_id );

		Merge::apply( $this->staged_copy_id );

		$after = get_post( $this->live_id );

		$this->assertSame( $before->ID, $after->ID );
		$this->assertSame( $before->post_name, $after->post_name );
		$this->assertSame( 'publish', $after->post_status );
		$this->assertSame( $before->post_date_gmt, $after->post_date_gmt );
		$this->assertSame( $before->post_type, $after->post_type );
		$this->assertSame( $before->post_author, $after->post_author );
		$this->assertSame( $before->post_parent, $after->post_parent );
		$this->assertContains( $category, wp_get_post_categories( $this->live_id ) );
	}

	/**
	 * Enforces I9. No post outside a staged copy ever carries the staged status.
	 */
	public function test_no_post_is_left_carrying_the_staged_status(): void {
		Merge::apply( $this->staged_copy_id );

		$staged = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => Status::NAME,
				'posts_per_page'   => 10,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$this->assertSame( array(), $staged );
		$this->assertSame( 'publish', get_post( $this->live_id )->post_status );
	}

	/**
	 * An adopted revision's slug names the live post.
	 *
	 * Core reads a revision's owner from the slug as well as the parent, so a
	 * parent-only rewrite leaves revisions core can misattribute.
	 */
	public function test_adopted_revisions_are_renamed_to_the_live_post(): void {
		$staged = $this->revisions_of( $this->staged_copy_id );

		Merge::apply( $this->staged_copy_id );

		foreach ( $staged as $revision ) {
			$adopted = get_post( $revision->ID );

			$this->assertSame( $this->live_id, (int) $adopted->post_parent );
			$this->assertSame( $this->live_id . '-revision-v1', $adopted->post_name );
		}
	}

	/**
	 * Revisions belonging to another post are never adopted.
	 */
	public function test_revisions_of_another_post_are_not_adopted(): void {
		$other = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Unrelated.',
			)
		);
		wp_update_post(
			array(
				'ID'           => $other,
				'post_content' => 'Unrelated, edited.',
			)
		);

		$other_revisions = wp_list_pluck( $this->revisions_of( $other ), 'ID' );
		$this->assertNotEmpty( $other_revisions );

		Merge::apply( $this->staged_copy_id );

		foreach ( $other_revisions as $revision_id ) {
			$this->assertSame( $other, (int) get_post( $revision_id )->post_parent );
		}
	}

	/**
	 * A staged copy with no revisions merges anyway and adopts nothing.
	 */
	public function test_a_staged_copy_with_no_revisions_adopts_nothing(): void {
		foreach ( $this->revisions_of( $this->staged_copy_id ) as $revision ) {
			wp_delete_post_revision( $revision->ID );
		}

		$result = Merge::apply( $this->staged_copy_id );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['revisions'] );
		$this->assertSame( self::STAGED_CONTENT, get_post( $this->live_id )->post_content );
	}

	/**
	 * Autosaves are not adopted, and stale ones are dropped.
	 *
	 * Adopting an autosave would make core offer the editor a restore nobody
	 * asked for, pointing at content that was never reviewed.
	 */
	public function test_autosaves_are_not_adopted(): void {
		$user_id = get_current_user_id();

		wp_create_post_autosave(
			array(
				'post_ID'      => $this->staged_copy_id,
				'post_content' => 'An unreviewed autosave.',
				'post_type'    => 'post',
				'post_author'  => $user_id,
			)
		);

		Merge::apply( $this->staged_copy_id );

		$this->assertFalse( (bool) wp_get_post_autosave( $this->live_id, $user_id ), 'An autosave reached the live post.' );

		foreach ( $this->revisions_of( $this->live_id ) as $revision ) {
			$this->assertStringNotContainsString( 'autosave', $revision->post_name );
			$this->assertNotSame( 'An unreviewed autosave.', $revision->post_content );
		}
	}

	/**
	 * A vetoing integrator leaves both posts untouched and starts no merge.
	 */
	public function test_a_veto_leaves_both_posts_untouched(): void {
		add_filter(
			'swpub_pre_merge',
			static function () {
				return new \WP_Error( 'nope', 'Blocked by policy.' );
			}
		);

		$result = Merge::apply( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'nope', $result->get_error_code() );

		$this->assertSame( self::PUBLISHED_CONTENT, get_post( $this->live_id )->post_content );
		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
		$this->assertNull( Merge_Marker::get( $this->live_id ), 'A vetoed merge left a marker behind.' );
	}

	/**
	 * Covers AE13. Two merges of one post produce one applied merge.
	 *
	 * Re-entered from inside the veto filter, which is the last moment before
	 * the first merge starts writing.
	 *
	 * @group concurrency
	 */
	public function test_two_simultaneous_merges_apply_once(): void {
		$completed = 0;
		add_action(
			'swpub_merge_completed',
			static function () use ( &$completed ): void {
				++$completed;
			}
		);

		$second = null;
		add_filter(
			'swpub_pre_merge',
			function ( $allowed ) use ( &$second ) {
				if ( null === $second ) {
					$second = Merge::apply( $this->staged_copy_id );
				}

				return $allowed;
			}
		);

		$first = Merge::apply( $this->staged_copy_id );

		$this->assertIsArray( $first, 'The first merge did not apply.' );
		$this->assertWPError( $second, 'The second merge was not refused.' );
		$this->assertSame( 'swpub_merge_in_progress', $second->get_error_code() );
		$this->assertSame( 1, $completed, 'The completion event fired more than once.' );
		$this->assertNull( get_post( $this->staged_copy_id ) );
	}

	/**
	 * A merge is refused when the live post is no longer published.
	 *
	 * @dataProvider unpublished_states
	 *
	 * @param string $status Status to put the live post into.
	 */
	public function test_a_merge_is_refused_when_the_live_post_is_not_published( string $status ): void {
		wp_update_post(
			array(
				'ID'          => $this->live_id,
				'post_status' => $status,
			)
		);

		$result = Merge::apply( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_not_published', $result->get_error_code() );
		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
	}

	/**
	 * Statuses a live post can be in that block a merge.
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public function unpublished_states(): array {
		return array(
			'draft'   => array( 'draft' ),
			'pending' => array( 'pending' ),
			'private' => array( 'private' ),
		);
	}

	/**
	 * A merge is refused when the live post has been deleted.
	 */
	public function test_a_merge_is_refused_when_the_live_post_is_gone(): void {
		wp_delete_post( $this->live_id, true );

		$result = Merge::apply( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_no_live_post', $result->get_error_code() );
		$this->assertInstanceOf( WP_Post::class, get_post( $this->staged_copy_id ) );
	}

	/**
	 * A user who cannot edit the live post cannot merge into it.
	 */
	public function test_an_unauthorized_user_cannot_merge(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = Merge::apply( $this->staged_copy_id );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_forbidden', $result->get_error_code() );
		$this->assertSame( self::PUBLISHED_CONTENT, get_post( $this->live_id )->post_content );
	}

	/**
	 * A merge is refused when the live post drifted, and applies with a match.
	 */
	public function test_drift_refuses_the_merge_until_confirmed(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Core overwrites post_modified_gmt on every update, so it cannot be set through the API.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_modified_gmt' => '2026-08-14 09:00:00' ),
			array( 'ID' => $this->live_id )
		);
		clean_post_cache( $this->live_id );

		$refused = Merge::apply( $this->staged_copy_id );

		$this->assertWPError( $refused );
		$this->assertSame( 'swpub_drift', $refused->get_error_code() );
		$this->assertSame( self::PUBLISHED_CONTENT, get_post( $this->live_id )->post_content );
		$this->assertNull( Merge_Marker::get( $this->live_id ), 'A refused merge left a marker behind.' );

		$applied = Merge::apply( $this->staged_copy_id, '2026-08-14 09:00:00' );

		$this->assertIsArray( $applied );
		$this->assertSame( self::STAGED_CONTENT, get_post( $this->live_id )->post_content );
	}

	/**
	 * The completion event carries what a listener cannot read back afterwards.
	 */
	public function test_the_completion_event_carries_the_merge_facts(): void {
		$staged_by = get_current_user_id();
		$staged    = wp_list_pluck( $this->revisions_of( $this->staged_copy_id ), 'ID' );

		$payload = null;
		add_action(
			'swpub_merge_completed',
			static function ( $live_id, $data ) use ( &$payload ): void {
				$payload = $data;
			},
			10,
			2
		);

		Merge::apply( $this->staged_copy_id );

		$this->assertIsArray( $payload, 'The completion event did not fire.' );
		$this->assertSame( $this->staged_copy_id, $payload['staged_copy_id'] );
		$this->assertGreaterThan( 0, $payload['snapshot_id'] );
		$this->assertSame( $staged_by, $payload['staged_by'] );
		$this->assertSame( $staged_by, $payload['merged_by'] );
		$this->assertFalse( $payload['drifted'] );
		$this->assertSame( '', $payload['override'] );
		$this->assertSame( $staged, $payload['revisions'] );
		$this->assertNotEmpty( $payload['forked_at'] );
	}

	/**
	 * Kills the merge the first time a hook fires.
	 *
	 * A thrown exception is the closest a test can get to the failure this
	 * guards against: a timeout or fatal partway through, leaving whatever had
	 * already been written on disk.
	 *
	 * @param string $hook Hook to die on.
	 * @return callable The listener, so it can be removed before resuming.
	 */
	private function abort_at( string $hook ): callable {
		$killer = static function (): void {
			throw new \RuntimeException( 'interrupted' );
		};

		add_action( $hook, $killer, 1 );

		return $killer;
	}

	/**
	 * Runs a merge that is expected to die, then clears the interruption.
	 *
	 * @param string $hook Hook to die on.
	 * @return void
	 */
	private function merge_and_die( string $hook, ?string $override = null ): void {
		global $wp_current_filter;

		$killer = $this->abort_at( $hook );
		$stack  = $wp_current_filter;

		try {
			Merge::apply( $this->staged_copy_id, $override );
			$this->fail( 'The merge was expected to be interrupted at ' . $hook . '.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'interrupted', $e->getMessage() );
		} finally {
			remove_action( $hook, $killer, 1 );

			/*
			 * Restore the hook stack by hand. An exception thrown from inside
			 * `do_action()` never lets WP_Hook pop its entry, so the aborted hook
			 * stays on `$wp_current_filter` for the rest of the process and
			 * `doing_action()` keeps reporting true for it. Core branches on that:
			 * `wp_save_post_revision()` returns early while `post_updated` appears
			 * to be running. Leaving it corrupted makes the resume behave in a way
			 * a real interruption never would, since a genuine fatal or timeout
			 * ends the request and the resume arrives on a clean one.
			 */
			$wp_current_filter = $stack; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Repairing state this test deliberately corrupted.
		}
	}

	/**
	 * Resumes whatever the recovery table says is outstanding.
	 *
	 * @return array<string, mixed> The resume decision.
	 */
	private function resume(): array {
		$marker = Merge_Marker::get( $this->live_id );
		$this->assertNotNull( $marker, 'The interrupted merge left no marker to resume from.' );

		$marker['attempted_at'] = time() - ( Merge_Marker::COOLDOWN + 1 );
		update_post_meta( $this->live_id, Merge_Marker::META, $marker );

		return \SaveWithoutPublish\Merge_Resume::run( $this->live_id );
	}

	/**
	 * Asserts a merge finished correctly and left nothing behind.
	 *
	 * @return void
	 */
	private function assert_merge_completed(): void {
		$this->assertSame( self::STAGED_CONTENT, get_post( $this->live_id )->post_content );
		$this->assertSame( 'publish', get_post( $this->live_id )->post_status );
		$this->assertNull( get_post( $this->staged_copy_id ), 'The staged copy survived.' );
		$this->assertNull( Merge_Marker::get( $this->live_id ), 'The marker was not cleared.' );
		$this->assertSame( '', get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true ) );

		$revisions = $this->revisions_of( $this->live_id );
		$newest    = end( $revisions );

		$this->assertSame(
			self::STAGED_CONTENT,
			$newest->post_content,
			'The newest revision does not match the merged content, so a restore would revert the article.'
		);
	}

	/**
	 * An interruption just after the snapshot resumes to a complete merge.
	 */
	public function test_an_interruption_after_the_snapshot_resumes(): void {
		$this->merge_and_die( '_wp_put_post_revision' );

		$this->assertSame( self::PUBLISHED_CONTENT, get_post( $this->live_id )->post_content );

		$decision = $this->resume();

		$this->assertSame( \SaveWithoutPublish\Merge_Resume::ACTION_RESUME, $decision['action'] );
		$this->assert_merge_completed();
	}

	/**
	 * An interruption during reparenting resumes without moving media twice.
	 */
	public function test_an_interruption_during_reparenting_resumes(): void {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'meridian-fall.jpg',
				'post_parent'    => $this->staged_copy_id,
				'post_mime_type' => 'image/jpeg',
			)
		);

		$this->merge_and_die( 'attachment_updated' );

		$this->resume();

		$this->assert_merge_completed();
		$this->assertSame( $this->live_id, (int) get_post( $attachment_id )->post_parent );
	}

	/**
	 * An interruption between the content write and core's revision resumes.
	 *
	 * The narrow window that would otherwise leave the post holding merged
	 * content while its newest revision is the pre-merge snapshot, so a restore
	 * of "the latest" would silently revert the article.
	 */
	public function test_an_interruption_between_the_write_and_its_revision_resumes(): void {
		$this->merge_and_die( 'post_updated' );

		// The row landed; the revision for it did not.
		$this->assertSame( self::STAGED_CONTENT, get_post( $this->live_id )->post_content );

		$this->resume();

		$this->assert_merge_completed();
	}

	/**
	 * An interruption just before the staged copy is deleted resumes.
	 */
	public function test_an_interruption_before_deletion_resumes(): void {
		$completed = 0;
		add_action(
			'swpub_merge_completed',
			static function () use ( &$completed ): void {
				++$completed;
			}
		);

		$this->merge_and_die( 'before_delete_post' );

		$this->resume();

		$this->assert_merge_completed();
		$this->assertSame( 1, $completed, 'The completion event did not fire exactly once.' );
	}

	/**
	 * A resumed merge does not adopt the same revision twice.
	 */
	public function test_a_resumed_merge_does_not_double_adopt(): void {
		$staged = wp_list_pluck( $this->revisions_of( $this->staged_copy_id ), 'ID' );

		$this->merge_and_die( 'post_updated' );

		$this->resume();

		$live_revisions = wp_list_pluck( $this->revisions_of( $this->live_id ), 'ID' );

		$this->assertSame(
			count( array_unique( $live_revisions ) ),
			count( $live_revisions ),
			'A revision was adopted more than once.'
		);

		foreach ( $staged as $revision_id ) {
			$this->assertContains( $revision_id, $live_revisions );
		}
	}

	/**
	 * Moves the live post so it no longer matches the fork baseline.
	 *
	 * @param string $when GMT timestamp to stamp.
	 * @return string The new timestamp.
	 */
	private function drift_live( string $when = '2026-08-14 09:00:00' ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Core overwrites post_modified_gmt on every update, so it cannot be set through the API.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_modified_gmt' => $when ),
			array( 'ID' => $this->live_id )
		);
		clean_post_cache( $this->live_id );

		return $when;
	}

	/**
	 * A confirmed merge interrupted before the write still finishes on resume.
	 *
	 * The confirmation lives in the marker. Resuming without it re-runs the
	 * drift gate against a baseline that still differs, so the merge refuses on
	 * every attempt until it strands -- and reviving it cannot help, because
	 * the next attempt meets the same gate. The editor confirmed once; they
	 * should not have to be present for the retry.
	 */
	public function test_a_confirmed_merge_resumes_after_an_interruption(): void {
		$shown = $this->drift_live();

		// Interrupted at the snapshot, so the content write has not happened
		// yet and the resumed merge must pass the drift gate on its own.
		$this->merge_and_die( '_wp_put_post_revision', $shown );

		$this->assertSame( self::PUBLISHED_CONTENT, get_post( $this->live_id )->post_content );

		$marker = Merge_Marker::get( $this->live_id );
		$this->assertSame( $shown, $marker['override'], 'The confirmation was not recorded.' );

		$this->resume();

		$this->assert_merge_completed();
	}

	/**
	 * A live edit arriving mid-merge does not leave staged revisions behind.
	 *
	 * Adoption re-parents the staged revisions onto the live post before the
	 * content write. Gating only at the write meant a third-party edit in
	 * between left the live post carrying staged revisions whose newest entry
	 * held content that was never published, and then refused, so nothing
	 * cleaned them up.
	 */
	public function test_drift_arriving_mid_merge_moves_nothing_onto_the_live_post(): void {
		$staged = wp_list_pluck( $this->revisions_of( $this->staged_copy_id ), 'ID' );
		$this->assertNotEmpty( $staged );

		Merge_Marker::start( $this->live_id, $this->staged_copy_id );
		Merge_Marker::advance( $this->live_id, Merge_Marker::PHASE_ADOPTING );

		$this->drift_live();

		$result = Merge::resume( $this->live_id, $this->staged_copy_id, Merge_Marker::PHASE_ADOPTING );

		$this->assertWPError( $result );
		$this->assertSame( 'swpub_drift', $result->get_error_code() );

		// The staged history is still the staged copy's, not the live post's.
		foreach ( $staged as $revision_id ) {
			$this->assertSame( $this->staged_copy_id, (int) get_post( $revision_id )->post_parent );
		}

		$this->assertSame( self::PUBLISHED_CONTENT, get_post( $this->live_id )->post_content );
	}

	/**
	 * The revisions to adopt are recorded before they are moved.
	 *
	 * Once re-parented they are no longer the staged copy's, so a merge interrupted
	 * between moving them and recording what it moved could never work the set
	 * out again, and the completion event would under-report the history a
	 * listener cannot reconstruct after the staged copy is gone.
	 */
	public function test_the_revision_set_is_recorded_before_adoption(): void {
		$staged = wp_list_pluck( $this->revisions_of( $this->staged_copy_id ), 'ID' );
		$this->assertNotEmpty( $staged );

		$live_id = $this->live_id;
		$seen    = null;

		// Captured at the moment the first staged revision is re-filed: if the
		// marker does not already name it, an interruption here would lose it.
		add_action(
			'clean_post_cache',
			static function ( $post_id ) use ( $staged, $live_id, &$seen ): void {
				if ( null === $seen && in_array( (int) $post_id, $staged, true ) ) {
					$marker = Merge_Marker::get( $live_id );
					$seen   = (array) ( $marker['revisions'] ?? array() );
				}
			}
		);

		Merge::apply( $this->staged_copy_id );

		$this->assertIsArray( $seen, 'No staged revision was re-filed.' );

		foreach ( $staged as $revision_id ) {
			$this->assertContains( $revision_id, $seen, 'A staged revision was moved before being recorded.' );
		}
	}

	/**
	 * Merging twice does not apply twice.
	 */
	public function test_a_second_merge_finds_nothing_to_do(): void {
		$this->assertIsArray( Merge::apply( $this->staged_copy_id ) );

		$second = Merge::apply( $this->staged_copy_id );

		$this->assertWPError( $second );
		$this->assertSame( 'swpub_not_staged', $second->get_error_code() );
	}
}
