<?php
/**
 * Handoff manifest consistency tests (VIPPROD-1352).
 *
 * The manifest is what VIP registers the integration from, so a value that
 * drifts from the plugin is a wrong promise to the platform. The line parsing
 * is deliberately plain: the manifest is flat enough that a YAML parser would
 * be a dependency for four lookups.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Config;
use WP_UnitTestCase;

/**
 * Proves vip-manifest.yaml says what the plugin does.
 */
class Test_Manifest extends WP_UnitTestCase {

	/**
	 * The plugin root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__ );
	}

	/**
	 * A file's contents.
	 *
	 * @param string $file Path relative to the plugin root.
	 * @return string
	 */
	private function read( string $file ): string {
		$contents = file_get_contents( $this->root() . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local file in the plugin, not a remote request.

		$this->assertIsString( $contents, $file . ' should be readable.' );

		return (string) $contents;
	}

	/**
	 * One scalar from the manifest, unquoted.
	 *
	 * @param string $key A key that appears once in the manifest.
	 * @return string
	 */
	private function scalar( string $key ): string {
		$this->assertSame(
			1,
			preg_match( '/^\s*' . preg_quote( $key, '/' ) . ':\s*(.+?)\s*$/m', $this->read( 'vip-manifest.yaml' ), $match ),
			'vip-manifest.yaml should declare ' . $key . '.'
		);

		return trim( $match[1], '\'"' );
	}

	/**
	 * Every release version agrees: header, constant, package.json, manifest.
	 */
	public function test_the_version_is_the_same_everywhere(): void {
		$this->assertSame(
			1,
			preg_match( '/^ \* Version:\s*(\S+)/m', $this->read( 'save-without-publish.php' ), $header ),
			'The plugin header should carry a Version.'
		);

		$package = json_decode( $this->read( 'package.json' ), true );

		$this->assertIsArray( $package );
		$this->assertSame( $header[1], \SaveWithoutPublish\VERSION, 'Header and VERSION constant.' );
		$this->assertSame( $header[1], $package['version'], 'Header and package.json.' );
		$this->assertSame( $header[1], $this->scalar( 'plugin_version' ), 'Header and manifest release.plugin_version.' );
	}

	/**
	 * The manifest names the constant and the setting the plugin reads.
	 */
	public function test_runtime_config_matches_the_config_class(): void {
		$manifest = $this->read( 'vip-manifest.yaml' );

		$this->assertSame( Config::CONSTANT_NAME, $this->scalar( 'constant_name' ) );

		preg_match_all( '/^\s*-\s*key:\s*(\S+)\s*$/m', $manifest, $keys );

		$this->assertSame( array( Config::DRIFT_POLICY_KEY ), $keys[1], 'The manifest should declare exactly the settings Config reads.' );
		$this->assertSame( Config::DEFAULT_DRIFT_POLICY, $this->scalar( 'default' ) );

		$this->assertSame(
			1,
			preg_match( '/^\s*values:\s*\[(.+)\]\s*$/m', $manifest, $values ),
			'The enum field should list its values.'
		);
		$this->assertSame( Config::DRIFT_POLICIES, array_map( 'trim', explode( ',', $values[1] ) ) );

		$this->assertSame( array(), Config::REQUIRED_FIELDS, 'The manifest marks the field optional, so nothing may be required.' );
	}

	/**
	 * The manifest points at the plugin that exists.
	 */
	public function test_runtime_identity_matches_the_plugin(): void {
		$entry = $this->read( 'save-without-publish.php' );

		$this->assertSame( 1, preg_match( '/^namespace\s+([^;]+);/m', $entry, $namespace ) );
		$this->assertSame( $namespace[1], $this->scalar( 'php_namespace' ) );

		$this->assertSame( 'save-without-publish.php', $this->scalar( 'entry_file' ) );
		$this->assertFileExists( $this->root() . '/' . $this->scalar( 'entry_file' ) );
		$this->assertSame( 1, preg_match( '/^ \* Text Domain:\s*(\S+)/m', $entry, $domain ) );
		$this->assertSame( $domain[1], $this->scalar( 'folder' ), 'The folder VIP installs into is the plugin slug.' );
		$this->assertSame( $domain[1], $this->scalar( 'slug' ) );
		$this->assertSame( 1, preg_match( '/^ \* Plugin Name:\s*(.+?)\s*$/m', $entry, $name ) );
		$this->assertSame( $name[1], $this->scalar( 'display_name' ) );
	}

	/**
	 * The manifest has no telemetry section because the plugin records none.
	 *
	 * Rule 9 of the checker is what notices if that stops being true, and it
	 * reads every PHP file, this one included, so this test does not name the
	 * telemetry API itself.
	 */
	public function test_the_manifest_declares_no_telemetry(): void {
		$this->assertStringNotContainsString( "\ntelemetry:", $this->read( 'vip-manifest.yaml' ) );
	}
}
