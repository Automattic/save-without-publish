<?php
/**
 * Recovery for an interrupted merge.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides what an interrupted merge needs, and never guesses.
 *
 * Every branch here answers the same question: is it safe to act, or should
 * this only be surfaced to a human? The bias is fixed and one-directional --
 * no path in this class deletes staged work. Deletion belongs to the merge,
 * after the work has provably reached the live post, and to U14's CLI, which
 * a person runs deliberately.
 *
 * Resume decides *where* to re-enter. It does not decide whether a step needs
 * doing: each merge step is idempotent (KTD19), so re-entering a step that
 * already completed is a no-op rather than a double-apply. That is what makes
 * an interrupted merge safe to re-run at all.
 */
final class Merge_Resume {

	/**
	 * Nothing to do; the pair is healthy.
	 */
	public const ACTION_NONE = 'none';

	/**
	 * A forward pointer naming no valid staged copy; clear it and let saves through.
	 */
	public const ACTION_CLEAR_POINTER = 'clear_pointer';

	/**
	 * Re-enter the merge at the recorded phase.
	 */
	public const ACTION_RESUME = 'resume';

	/**
	 * A resume is due but the cooldown has not elapsed.
	 */
	public const ACTION_WAIT = 'wait';

	/**
	 * Past the attempt budget, or the live post is no longer publishable-to.
	 * Repairable only through the CLI.
	 */
	public const ACTION_STRANDED = 'stranded';

	/**
	 * Something a human must look at. Never acted on automatically.
	 */
	public const ACTION_SURFACE = 'surface';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'load-post.php', array( __CLASS__, 'on_edit_screen' ) );
	}

	/**
	 * Runs recovery when an editor opens either post.
	 *
	 * The edit screen is the entry point because it is the one moment we know a
	 * human is waiting on this post and can be shown the result. A cron entry
	 * point would repair posts nobody was asking for, and R24 rules out the
	 * query that would find them.
	 *
	 * @return void
	 */
	public static function on_edit_screen(): void {
		if ( ! is_enabled() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which post is being opened; authorization is checked immediately below and the routing performs no request-driven write.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		if ( $post_id <= 0 ) {
			return;
		}

		/*
		 * Authorize before doing anything at all.
		 *
		 * `load-post.php` fires from admin.php, which post.php includes at its
		 * very top -- well before post.php performs its own `edit_post` check.
		 * So this runs on an attacker-supplied ID for a post the current user
		 * may have no rights to. Without this gate, opening that URL could
		 * clear a pointer, drive an interrupted merge to completion in someone
		 * else's article, or burn the attempt budget until a recoverable merge
		 * is stranded.
		 */
		if ( ! self::may_recover( $post_id ) ) {
			return;
		}

		self::run( $post_id );
	}

	/**
	 * Whether the current user may drive recovery for a post.
	 *
	 * Resolved against the live post, as everywhere else (KTD13): a staged copy
	 * is only ever as private as the article it stages.
	 *
	 * @param int $post_id Either post of a staged pair.
	 * @return bool True when recovery may run.
	 */
	private static function may_recover( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( Status::is_staged( $post ) ) {
			return Capabilities::current_user_can_manage( $post->ID );
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Decides and then applies whatever is safe to apply.
	 *
	 * Not an authorization boundary. Callers reached from a request must gate
	 * themselves first, as `on_edit_screen()` does; the WP-CLI repair command
	 * calls this deliberately without a user, which is the whole point of
	 * having an out-of-band repair path.
	 *
	 * @param int $post_id Either post of a staged pair.
	 * @return array<string, mixed> The decision that was acted on.
	 */
	public static function run( int $post_id ): array {
		$decision = self::decide( $post_id );

		switch ( $decision['action'] ) {
			case self::ACTION_CLEAR_POINTER:
				delete_post_meta( $decision['live_id'], Staged_Copy_Repository::STAGED_COPY_META );
				break;

			case self::ACTION_STRANDED:
				if ( ! empty( $decision['strand'] ) ) {
					Merge_Marker::strand( $decision['live_id'] );
				}
				break;

			case self::ACTION_RESUME:
				Merge_Marker::record_attempt( $decision['live_id'] );

				/**
				 * Fires when an interrupted merge should be re-entered.
				 *
				 * The merge unit hooks this and re-runs from the named phase.
				 * The attempt has already been counted against the budget, so a
				 * handler that fatals still moves the count forward.
				 *
				 * @since 0.1.0
				 *
				 * @param int    $live_id   Published post ID.
				 * @param int    $staged_copy_id Staged copy post ID, already revalidated.
				 * @param string $phase     Phase to re-enter.
				 */
				do_action( 'swpub_resume_merge', $decision['live_id'], $decision['staged_copy_id'], $decision['phase'] );
				break;
		}

		return $decision;
	}

	/**
	 * Works out what state a staged pair is in.
	 *
	 * Accepts either post, because an editor can arrive at either one and the
	 * damage is the same from both sides.
	 *
	 * @param int $post_id Either post of a staged pair.
	 * @return array<string, mixed> An action, plus whatever the caller needs to act on it.
	 */
	public static function decide( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return self::decision( self::ACTION_NONE, 'no_post' );
		}

		if ( Status::is_staged( $post ) ) {
			$live = Staged_Copy_Repository::find_live_for_staged_copy( $post->ID );

			if ( ! $live instanceof WP_Post ) {
				/*
				 * A staged post whose reverse pointer is missing or disagrees.
				 * Most likely a fork that died between the insert and the
				 * forward pointer. It holds a copy of published content and
				 * possibly an editor's staged work, and nothing here can tell
				 * those apart, so it is surfaced and left alone.
				 */
				return self::decision( self::ACTION_SURFACE, 'orphaned_staged_copy', array( 'staged_copy_id' => $post->ID ) );
			}

			return self::decide_for_live( $live->ID );
		}

		return self::decide_for_live( $post->ID );
	}

	/**
	 * The recovery table, keyed on the live post.
	 *
	 * @param int $live_id Live post ID.
	 * @return array<string, mixed> The decision.
	 */
	private static function decide_for_live( int $live_id ): array {
		$marker = Merge_Marker::get( $live_id );

		if ( null === $marker ) {
			return self::decide_without_marker( $live_id );
		}

		$context = array( 'live_id' => $live_id );

		if ( Merge_Marker::is_stranded( $marker ) ) {
			return self::decision( self::ACTION_STRANDED, 'already_stranded', $context );
		}

		/*
		 * The final phase clears both pointers before deleting the staged copy, so a
		 * merge interrupted in that gap legitimately has none left. Refusing it
		 * on that basis would strand a merge that is one step from done.
		 */
		$finishing = Merge_Marker::PHASE_FINISHING === ( $marker['phase'] ?? '' );

		$staged_copy = Merge_Marker::validated_staged_copy( $live_id, $marker, $finishing );

		if ( ! $staged_copy instanceof WP_Post ) {
			/*
			 * The marker names something that is not this post's staged copy. It
			 * drives force-deletion and reparenting, so acting on it could
			 * destroy an unrelated post. Surfaced, never acted on (KTD14).
			 */
			return self::decision( self::ACTION_SURFACE, 'invalid_marker', $context );
		}

		$context['staged_copy_id'] = $staged_copy->ID;

		if ( 'publish' !== get_post_status( $live_id ) ) {
			// Nothing to merge into. Never promote the staged copy to fill the gap.
			return self::decision( self::ACTION_STRANDED, 'live_not_published', $context + array( 'strand' => true ) );
		}

		if ( Merge_Marker::is_exhausted( $marker ) ) {
			return self::decision( self::ACTION_STRANDED, 'attempts_exhausted', $context + array( 'strand' => true ) );
		}

		if ( ! Merge_Marker::cooldown_elapsed( $marker ) ) {
			return self::decision( self::ACTION_WAIT, 'cooling_down', $context );
		}

		return self::decision(
			self::ACTION_RESUME,
			'resume_phase',
			$context + array( 'phase' => (string) $marker['phase'] )
		);
	}

	/**
	 * The no-merge-in-flight rows of the recovery table.
	 *
	 * @param int $live_id Live post ID.
	 * @return array<string, mixed> The decision.
	 */
	private static function decide_without_marker( int $live_id ): array {
		$pointer = (int) get_post_meta( $live_id, Staged_Copy_Repository::STAGED_COPY_META, true );

		if ( $pointer <= 0 ) {
			return self::decision( self::ACTION_NONE, 'no_staged_copy', array( 'live_id' => $live_id ) );
		}

		if ( ! Staged_Copy_Repository::pointers_agree( $live_id, $pointer ) ) {
			/*
			 * A pointer to a post that is gone or is not this post's staged copy.
			 * Left in place it fails every save closed, so the post becomes
			 * uneditable. Clearing it costs nothing: no staged copy is destroyed,
			 * because there is no valid staged copy to destroy.
			 */
			return self::decision( self::ACTION_CLEAR_POINTER, 'stale_pointer', array( 'live_id' => $live_id ) );
		}

		return self::decision(
			self::ACTION_NONE,
			'healthy',
			array(
				'live_id'   => $live_id,
				'staged_copy_id' => $pointer,
			)
		);
	}

	/**
	 * Builds a decision.
	 *
	 * @param string               $action One of the ACTION_ constants.
	 * @param string               $reason Machine-readable reason, for logs and tests.
	 * @param array<string, mixed> $extra  Additional context.
	 * @return array<string, mixed> The decision.
	 */
	private static function decision( string $action, string $reason, array $extra = array() ): array {
		return array_merge(
			array(
				'action'    => $action,
				'reason'    => $reason,
				'live_id'   => 0,
				'staged_copy_id' => 0,
				'phase'     => '',
			),
			$extra
		);
	}
}
