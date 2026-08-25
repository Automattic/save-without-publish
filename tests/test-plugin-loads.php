<?php
/**
 * Scaffold tests for U1.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use WP_UnitTestCase;

use function SaveWithoutPublish\is_enabled;

/**
 * Proves the plugin loads and its kill switch behaves.
 */
class Test_Plugin_Loads extends WP_UnitTestCase {

	/**
	 * The plugin file loads and defines its namespaced version constant.
	 */
	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'SaveWithoutPublish\\VERSION' ) );
		$this->assertSame( '0.1.0', \SaveWithoutPublish\VERSION );
	}

	/**
	 * Bootstrap runs on init without raising a notice.
	 */
	public function test_bootstrap_is_wired_on_init(): void {
		$this->assertNotFalse( has_action( 'init', 'SaveWithoutPublish\\bootstrap' ) );
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
		add_filter( 'swpub_is_enabled', '__return_false' );

		$this->assertFalse( is_enabled() );

		remove_filter( 'swpub_is_enabled', '__return_false' );
	}

	/**
	 * The autoloader ignores classes outside this plugin's namespace.
	 */
	public function test_autoloader_ignores_foreign_namespaces(): void {
		$this->assertFalse( class_exists( 'SomeOther\\Vendor\\Status', true ) );
	}
}
