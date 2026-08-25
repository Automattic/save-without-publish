<?php
/**
 * Merge marker and resume tests for U13 (R16, R33).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Merge_Marker;
use SaveWithoutPublish\Merge_Resume;
use SaveWithoutPublish\Staged_Copy_Repository;
use SaveWithoutPublish\Status;
use WP_UnitTestCase;

/**
 * Proves no recovery path loses staged work, and none loops forever.
 */
class Test_Merge_Resume extends WP_UnitTestCase {

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

		/*
		 * These tests cover routing: which state the recovery table lands on and
		 * how the attempt budget is spent. The real merge handler is unhooked so
		 * a resume dispatch does not actually complete a merge, clear the marker,
		 * and take the state under test away mid-assertion. Merging itself is
		 * covered in Test_Merge.
		 */
		remove_action( 'swpub_resume_merge', array( \SaveWithoutPublish\Merge::class, 'resume' ), 10 );

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
	 * Ages the marker past its cooldown so a resume is due.
	 *
	 * @param int $live_id Live post ID.
	 * @return void
	 */
	private function age_marker( int $live_id ): void {
		$marker                 = Merge_Marker::get( $live_id );
		$marker['attempted_at'] = time() - ( Merge_Marker::COOLDOWN + 1 );

		update_post_meta( $live_id, Merge_Marker::META, $marker );
	}

	/**
	 * Opens a marker at a given phase, past its cooldown.
	 *
	 * @param string $phase Phase to stop at.
	 * @return void
	 */
	private function interrupt_at( string $phase ): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );
		Merge_Marker::advance( $this->live_id, $phase );
		$this->age_marker( $this->live_id );
	}

	/**
	 * A staged pair with no merge in flight is healthy from either side.
	 */
	public function test_a_healthy_pair_needs_no_recovery(): void {
		foreach ( array( $this->live_id, $this->staged_copy_id ) as $entry_point ) {
			$decision = Merge_Resume::decide( $entry_point );

			$this->assertSame( Merge_Resume::ACTION_NONE, $decision['action'] );
			$this->assertSame( 'healthy', $decision['reason'] );
		}
	}

	/**
	 * Covers AE11. Every phase resumes, and resumes at the phase it stopped in.
	 *
	 * @dataProvider phases
	 *
	 * @param string $phase The phase the merge was interrupted in.
	 */
	public function test_each_phase_resumes_where_it_stopped( string $phase ): void {
		$this->interrupt_at( $phase );

		$resumed = array();

		add_action(
			'swpub_resume_merge',
			static function ( $live_id, $staged_copy_id, $resumed_phase ) use ( &$resumed ): void {
				$resumed[] = array( $live_id, $staged_copy_id, $resumed_phase );
			},
			10,
			3
		);

		$decision = Merge_Resume::run( $this->live_id );

		$this->assertSame( Merge_Resume::ACTION_RESUME, $decision['action'] );
		$this->assertSame( $phase, $decision['phase'] );
		$this->assertSame( array( array( $this->live_id, $this->staged_copy_id, $phase ) ), $resumed );
	}

	/**
	 * Every phase a merge can be interrupted in.
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public function phases(): array {
		$cases = array();

		foreach ( Merge_Marker::PHASES as $phase ) {
			$cases[ $phase ] = array( $phase );
		}

		return $cases;
	}

	/**
	 * A resume can be entered from the staged copy's side too.
	 */
	public function test_a_resume_can_be_entered_from_the_staged_copy(): void {
		$this->interrupt_at( Merge_Marker::PHASE_ADOPTING );

		$decision = Merge_Resume::decide( $this->staged_copy_id );

		$this->assertSame( Merge_Resume::ACTION_RESUME, $decision['action'] );
		$this->assertSame( $this->live_id, $decision['live_id'] );
	}

	/**
	 * A repeatedly failing merge stops re-attempting and surfaces as stranded.
	 *
	 * Without the cap, every load of either post re-runs the failing merge and
	 * both posts become unopenable.
	 */
	public function test_a_repeatedly_failing_resume_strands(): void {
		$this->interrupt_at( Merge_Marker::PHASE_WRITING );

		$attempts = 0;

		add_action(
			'swpub_resume_merge',
			static function () use ( &$attempts ): void {
				++$attempts;
			}
		);

		for ( $i = 0; $i < Merge_Marker::ATTEMPT_CAP + 3; $i++ ) {
			$this->age_marker( $this->live_id );
			Merge_Resume::run( $this->live_id );
		}

		$this->assertSame( Merge_Marker::ATTEMPT_CAP, $attempts, 'The attempt budget was not enforced.' );

		$decision = Merge_Resume::run( $this->live_id );
		$this->assertSame( Merge_Resume::ACTION_STRANDED, $decision['action'] );

		$this->assertTrue( Merge_Marker::is_stranded( Merge_Marker::get( $this->live_id ) ) );

		// The staged work is still there. Stranded is a state to repair, not to clean up.
		$this->assertInstanceOf( \WP_Post::class, get_post( $this->staged_copy_id ) );
	}

	/**
	 * A resume inside the cooldown window does not re-attempt.
	 */
	public function test_a_resume_inside_the_cooldown_does_not_re_attempt(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );

		$attempts = 0;

		add_action(
			'swpub_resume_merge',
			static function () use ( &$attempts ): void {
				++$attempts;
			}
		);

		$decision = Merge_Resume::run( $this->live_id );

		$this->assertSame( Merge_Resume::ACTION_WAIT, $decision['action'] );
		$this->assertSame( 0, $attempts );
		$this->assertSame( 0, Merge_Marker::get( $this->live_id )['attempts'] );
	}

	/**
	 * A staged copy with no marker is never deleted by any recovery path.
	 */
	public function test_a_staged_copy_with_no_marker_is_never_deleted(): void {
		$decision = Merge_Resume::run( $this->staged_copy_id );

		$this->assertSame( Merge_Resume::ACTION_NONE, $decision['action'] );
		$this->assertInstanceOf( \WP_Post::class, get_post( $this->staged_copy_id ) );
		$this->assertSame( Status::NAME, get_post( $this->staged_copy_id )->post_status );
	}

	/**
	 * A marker naming a post that is not a valid staged copy is surfaced, not acted on.
	 *
	 * The marker drives force-deletion and reparenting, so acting on a bad one
	 * would destroy an unrelated post.
	 */
	public function test_a_marker_naming_an_invalid_staged_copy_is_surfaced(): void {
		$bystander = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'An unrelated published post',
			)
		);

		Merge_Marker::start( $this->live_id, $bystander );
		$this->age_marker( $this->live_id );

		$fired = 0;
		add_action(
			'swpub_resume_merge',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$decision = Merge_Resume::run( $this->live_id );

		$this->assertSame( Merge_Resume::ACTION_SURFACE, $decision['action'] );
		$this->assertSame( 'invalid_marker', $decision['reason'] );
		$this->assertSame( 0, $fired );
		$this->assertInstanceOf( \WP_Post::class, get_post( $bystander ) );
		$this->assertSame( 'publish', get_post( $bystander )->post_status );
	}

	/**
	 * A marker whose live post is no longer published strands rather than merges.
	 */
	public function test_an_unpublished_live_post_strands_rather_than_merging(): void {
		$this->interrupt_at( Merge_Marker::PHASE_WRITING );

		wp_update_post(
			array(
				'ID'          => $this->live_id,
				'post_status' => 'draft',
			)
		);

		$fired = 0;
		add_action(
			'swpub_resume_merge',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$decision = Merge_Resume::run( $this->live_id );

		$this->assertSame( Merge_Resume::ACTION_STRANDED, $decision['action'] );
		$this->assertSame( 'live_not_published', $decision['reason'] );
		$this->assertSame( 0, $fired );

		// The staged copy is never promoted to fill the gap.
		$this->assertSame( Status::NAME, get_post( $this->staged_copy_id )->post_status );
	}

	/**
	 * A staged post with no reverse pointer is surfaced, never silently deleted.
	 */
	public function test_an_orphaned_staged_copy_is_surfaced_not_deleted(): void {
		delete_post_meta( $this->staged_copy_id, Staged_Copy_Repository::LIVE_META );

		$decision = Merge_Resume::run( $this->staged_copy_id );

		$this->assertSame( Merge_Resume::ACTION_SURFACE, $decision['action'] );
		$this->assertSame( 'orphaned_staged_copy', $decision['reason'] );
		$this->assertInstanceOf( \WP_Post::class, get_post( $this->staged_copy_id ) );
	}

	/**
	 * A forward pointer with no staged copy is cleared, and the next save succeeds.
	 *
	 * Left in place the pointer fails every save closed, which makes the
	 * published post uneditable -- fail-safe turning into fail-stuck.
	 */
	public function test_a_stale_forward_pointer_is_cleared(): void {
		wp_delete_post( $this->staged_copy_id, true );
		update_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, $this->staged_copy_id );

		$decision = Merge_Resume::run( $this->live_id );

		$this->assertSame( Merge_Resume::ACTION_CLEAR_POINTER, $decision['action'] );
		$this->assertSame( '', get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}

	/**
	 * Completion fires the merge-completed event exactly once.
	 *
	 * A resume that re-enters a finished merge finds no marker, so it can
	 * neither re-fire the event nor re-run the merge behind it (R11).
	 */
	public function test_completion_fires_the_event_exactly_once(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );

		$fired = 0;
		add_action(
			'swpub_merge_completed',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->assertTrue( Merge_Marker::complete( $this->live_id ) );
		$this->assertFalse( Merge_Marker::complete( $this->live_id ) );
		$this->assertFalse( Merge_Marker::complete( $this->live_id ) );

		$this->assertSame( 1, $fired );
		$this->assertNull( Merge_Marker::get( $this->live_id ) );
	}

	/**
	 * A completed merge leaves nothing for recovery to re-enter.
	 */
	public function test_a_completed_merge_is_not_resumed(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );
		Merge_Marker::complete( $this->live_id );

		$fired = 0;
		add_action(
			'swpub_resume_merge',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		Merge_Resume::run( $this->live_id );

		$this->assertSame( 0, $fired );
	}

	/**
	 * Opening the edit screen for a post you cannot edit recovers nothing.
	 *
	 * `load-post.php` fires from admin.php, which post.php includes at its very
	 * top, well before post.php runs its own `edit_post` check. So this entry
	 * point sees an attacker-supplied ID for a post the user may have no rights
	 * to. Ungated, opening that URL could clear a pointer, drive an interrupted
	 * merge to completion in someone else's article, or burn the attempt budget
	 * until a recoverable merge is stranded.
	 */
	public function test_an_unauthorized_visitor_cannot_drive_recovery(): void {
		$this->interrupt_at( Merge_Marker::PHASE_WRITING );

		$fired = 0;
		add_action(
			'swpub_resume_merge',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach ( array( $this->live_id, $this->staged_copy_id ) as $target ) {
			$_GET['post'] = $target;
			Merge_Resume::on_edit_screen();
		}

		unset( $_GET['post'] );

		$this->assertSame( 0, $fired, 'An unauthorized visitor resumed a merge.' );
		$this->assertSame( 0, Merge_Marker::get( $this->live_id )['attempts'], 'An unauthorized visitor spent the attempt budget.' );
		$this->assertSame( Merge_Marker::PHASE_WRITING, Merge_Marker::get( $this->live_id )['phase'] );
	}

	/**
	 * An unauthorized visitor cannot clear a pointer either.
	 */
	public function test_an_unauthorized_visitor_cannot_clear_a_pointer(): void {
		wp_delete_post( $this->staged_copy_id, true );
		update_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, $this->staged_copy_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_GET['post'] = $this->live_id;
		Merge_Resume::on_edit_screen();
		unset( $_GET['post'] );

		$this->assertSame(
			(string) $this->staged_copy_id,
			(string) get_post_meta( $this->live_id, Staged_Copy_Repository::STAGED_COPY_META, true )
		);
	}

	/**
	 * Someone who can edit the post does drive recovery.
	 *
	 * The gate above is worthless if it also blocks the people it exists for.
	 */
	public function test_an_authorized_editor_still_drives_recovery(): void {
		$this->interrupt_at( Merge_Marker::PHASE_WRITING );

		$fired = 0;
		add_action(
			'swpub_resume_merge',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$_GET['post'] = $this->live_id;
		Merge_Resume::on_edit_screen();
		unset( $_GET['post'] );

		$this->assertSame( 1, $fired );
	}

	/**
	 * The marker carries the attachment set across an interruption.
	 */
	public function test_the_marker_carries_the_attachment_set(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );
		Merge_Marker::record_attachments( $this->live_id, array( 11, 22, 33 ) );

		$this->assertSame( array( 11, 22, 33 ), Merge_Marker::get( $this->live_id )['attachments'] );
	}

	/**
	 * The marker is never exposed over REST.
	 */
	public function test_the_marker_is_protected(): void {
		Merge_Marker::start( $this->live_id, $this->staged_copy_id );

		$this->assertTrue( is_protected_meta( Merge_Marker::META, 'post' ) );

		$request  = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->live_id );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayNotHasKey( Merge_Marker::META, (array) ( $data['meta'] ?? array() ) );
	}
}
