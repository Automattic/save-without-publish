<?php
/**
 * Scaffold tests for U1.
 *
 * @package StagedEdits
 */

declare( strict_types = 1 );

namespace StagedEdits\Tests;

use WP_UnitTestCase;

use function StagedEdits\is_enabled;

/**
 * Proves the plugin loads and its kill switch behaves.
 */
class Test_Plugin_Loads extends WP_UnitTestCase {

	/**
	 * The plugin file loads and defines its namespaced version constant.
	 *
	 * Which version is the manifest test's job (header, constant, package.json
	 * and manifest agree); pinning it here too made a fifth place to bump.
	 */
	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'StagedEdits\\VERSION' ) );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', \StagedEdits\VERSION );
	}

	/**
	 * Bootstrap runs on init without raising a notice.
	 */
	public function test_bootstrap_is_wired_on_init(): void {
		$this->assertNotFalse( has_action( 'init', 'StagedEdits\\bootstrap' ) );
	}

	/**
	 * Staging is on by default (R5, KD1 -- no opt-in).
	 */
	public function test_staging_is_enabled_by_default(): void {
		$this->assertTrue( is_enabled() );
	}

	/**
	 * The kill switch disables staging without deactivating the plugin (R32).
	 */
	public function test_kill_switch_filter_disables_staging(): void {
		add_filter( 'staged_edits_is_enabled', '__return_false' );

		$this->assertFalse( is_enabled() );

		remove_filter( 'staged_edits_is_enabled', '__return_false' );
	}

	/**
	 * The autoloader ignores classes outside this plugin's namespace.
	 */
	public function test_autoloader_ignores_foreign_namespaces(): void {
		$this->assertFalse( class_exists( 'SomeOther\\Vendor\\Status', true ) );
	}
}
