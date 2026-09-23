<?php
/**
 * Classic editor meta box removal tests for VIPPROD-1228.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Staged_Copy_Repository;
use WP_UnitTestCase;

/**
 * Proves the classic editor takes the same controls off a staged copy's
 * screen that `unstageable-fields.js` takes off the block editor's.
 */
class Test_Admin_Surfaces extends WP_UnitTestCase {

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
	 * Stages a published post, and registers the meta boxes core would have
	 * by the time `add_meta_boxes` fires.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->live_id        = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->staged_copy_id = Staged_Copy_Repository::create( get_post( $this->live_id ) )->ID;

		$this->register_default_meta_boxes();
	}

	/**
	 * Clears the meta box registry so nothing here leaks into another file's
	 * tests -- unlike post and option data, `$wp_meta_boxes` is a bare PHP
	 * global, not something a database transaction rolls back.
	 */
	public function tear_down(): void {
		global $wp_meta_boxes;

		unset( $wp_meta_boxes['post'] );

		parent::tear_down();
	}

	/**
	 * Registers the same meta boxes, at the same ids and contexts, core
	 * registers for a post before `add_meta_boxes` fires. What this file
	 * proves is that this plugin's own removal runs after them and finds
	 * them -- core registering them by these names is core's own behaviour,
	 * not this plugin's to test.
	 *
	 * @return void
	 */
	private function register_default_meta_boxes(): void {
		foreach ( array( 'slugdiv', 'authordiv', 'commentstatusdiv', 'commentsdiv' ) as $id ) {
			add_meta_box( $id, $id, '__return_null', 'post', 'normal' );
		}

		foreach ( array( 'postimagediv', 'pageparentdiv', 'formatdiv' ) as $id ) {
			add_meta_box( $id, $id, '__return_null', 'post', 'side' );
		}

		// The two taxonomy boxes core itself registers for a post: category
		// hierarchical (`<tax>div`), tags flat (`tagsdiv-<tax>`).
		add_meta_box( 'categorydiv', 'categorydiv', '__return_null', 'post', 'side' );
		add_meta_box( 'tagsdiv-post_tag', 'tagsdiv-post_tag', '__return_null', 'post', 'side' );
	}

	/**
	 * Every box this ticket names, and the context it registers in.
	 *
	 * @return array<string, string> Meta box id to context.
	 */
	private function removable_boxes(): array {
		return array(
			'slugdiv'          => 'normal',
			'authordiv'        => 'normal',
			'commentstatusdiv' => 'normal',
			'commentsdiv'      => 'normal',
			'postimagediv'     => 'side',
			'pageparentdiv'    => 'side',
			'formatdiv'        => 'side',
			'categorydiv'      => 'side',
			'tagsdiv-post_tag' => 'side',
		);
	}

	/**
	 * Whether a meta box is currently registered, at any priority.
	 *
	 * Core's own `remove_meta_box()` does not unset the entry; it writes
	 * `false` over it at every priority, which is what the render loop in
	 * `wp-admin/includes/template.php` checks for. `isset()` is therefore
	 * the wrong test here -- a removed box is still "set", to `false` --
	 * and `!empty()` is what actually answers "would this still render".
	 *
	 * @param string $id      Meta box id.
	 * @param string $context Meta box context.
	 * @return bool True when it is still registered.
	 */
	private function box_exists( string $id, string $context ): bool {
		global $wp_meta_boxes;

		if ( empty( $wp_meta_boxes['post'][ $context ] ) ) {
			return false;
		}

		foreach ( $wp_meta_boxes['post'][ $context ] as $boxes ) {
			if ( ! empty( $boxes[ $id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every one of these boxes is gone on a staged copy's own screen.
	 */
	public function test_unstageable_meta_boxes_are_removed_on_a_staged_copy(): void {
		do_action( 'add_meta_boxes', 'post', get_post( $this->staged_copy_id ) );

		foreach ( $this->removable_boxes() as $id => $context ) {
			$this->assertFalse( $this->box_exists( $id, $context ), "$id should have been removed on the staged copy." );
		}
	}

	/**
	 * The published post this copy stages a change to keeps every one of
	 * them: these are exactly the fields that still save straight to it.
	 */
	public function test_unstageable_meta_boxes_stay_on_the_published_post(): void {
		do_action( 'add_meta_boxes', 'post', get_post( $this->live_id ) );

		foreach ( $this->removable_boxes() as $id => $context ) {
			$this->assertTrue( $this->box_exists( $id, $context ), "$id should not have been removed on the published post." );
		}
	}

	/**
	 * An ordinary post with no staged copy at all keeps every one of them
	 * too.
	 */
	public function test_unstageable_meta_boxes_stay_on_an_ordinary_post(): void {
		$ordinary = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		do_action( 'add_meta_boxes', 'post', get_post( $ordinary ) );

		foreach ( $this->removable_boxes() as $id => $context ) {
			$this->assertTrue( $this->box_exists( $id, $context ) );
		}
	}
}
