<?php
/**
 * Runtime config tests (VIPPROD-1352).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use SaveWithoutPublish\Config;
use WP_UnitTestCase;

/**
 * Proves a missing, partial or malformed config can never change behaviour or fatal.
 */
class Test_Config extends WP_UnitTestCase {

	/**
	 * Loads a fixture.
	 *
	 * @param string $name File name under tests/fixtures.
	 * @return mixed
	 */
	private function fixture( string $name ): mixed {
		return require __DIR__ . '/fixtures/' . $name;
	}

	/**
	 * Every fixture maps to the policy its README says.
	 *
	 * @dataProvider provide_fixtures
	 *
	 * @param string $file     Fixture file.
	 * @param string $expected Policy in force.
	 */
	public function test_fixtures_resolve_to_their_policy( string $file, string $expected ): void {
		$config = Config::from_raw( $this->fixture( $file ) );

		$this->assertTrue( $config->is_available() );
		$this->assertSame( $expected, $config->drift_policy() );
	}

	/**
	 * Fixture to policy.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function provide_fixtures(): array {
		return array(
			'valid'      => array( 'config-valid.php', 'always' ),
			'minimal'    => array( 'config-minimal.php', 'never' ),
			'incomplete' => array( 'config-incomplete.php', 'never' ),
			'invalid'    => array( 'config-invalid.php', 'never' ),
		);
	}

	/**
	 * A constant that is not defined is unavailable, ready, and says never.
	 */
	public function test_undefined_constant_behaves_as_never(): void {
		$this->assertFalse( defined( Config::CONSTANT_NAME ) );

		$config = Config::current();

		$this->assertFalse( $config->is_available() );
		$this->assertTrue( $config->is_ready() );
		$this->assertSame( array(), $config->missing_fields() );
		$this->assertSame( 'never', $config->drift_policy() );
	}

	/**
	 * Whatever the platform defines, reading it never throws and never overrides drift.
	 *
	 * @dataProvider provide_garbage
	 *
	 * @param mixed $raw A malformed value.
	 */
	public function test_malformed_values_fall_back_to_never( mixed $raw ): void {
		$config = Config::from_raw( $raw );

		$this->assertTrue( $config->is_ready() );
		$this->assertSame( 'never', $config->drift_policy() );

		foreach ( array( 'content', 'other', 'unknown', '' ) as $kind ) {
			$this->assertFalse( $config->drift_override_default( $kind ) );
		}
	}

	/**
	 * Values that are not a usable config.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function provide_garbage(): array {
		return array(
			'null'            => array( null ),
			'string'          => array( 'always' ),
			'int'             => array( 1 ),
			'true'            => array( true ),
			'empty array'     => array( array() ),
			'int setting'     => array( array( Config::DRIFT_POLICY_KEY => 1 ) ),
			'bool setting'    => array( array( Config::DRIFT_POLICY_KEY => true ) ),
			'array setting'   => array( array( Config::DRIFT_POLICY_KEY => array( 'always' ) ) ),
			'null setting'    => array( array( Config::DRIFT_POLICY_KEY => null ) ),
			'wrong case'      => array( array( Config::DRIFT_POLICY_KEY => 'ALWAYS' ) ),
			'padded'          => array( array( Config::DRIFT_POLICY_KEY => ' always ' ) ),
			'unknown setting' => array( array( 'something_else' => 'always' ) ),
		);
	}

	/**
	 * Each policy against each kind of drift.
	 *
	 * @dataProvider provide_policy_matrix
	 *
	 * @param string $policy   The setting.
	 * @param string $kind     What `Drift::kind()` reported.
	 * @param bool   $expected Whether a scheduled publish proceeds.
	 */
	public function test_policy_by_kind( string $policy, string $kind, bool $expected ): void {
		$config = Config::from_raw( array( Config::DRIFT_POLICY_KEY => $policy ) );

		$this->assertSame( $expected, $config->drift_override_default( $kind ) );
	}

	/**
	 * Policy x kind.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function provide_policy_matrix(): array {
		return array(
			'never content'        => array( 'never', 'content', false ),
			'never other'          => array( 'never', 'other', false ),
			'never unknown'        => array( 'never', 'unknown', false ),
			'non_content content'  => array( 'non_content', 'content', false ),
			'non_content other'    => array( 'non_content', 'other', true ),
			'non_content unknown'  => array( 'non_content', 'unknown', false ),
			'always content'       => array( 'always', 'content', true ),
			'always other'         => array( 'always', 'other', true ),
			'always unknown'       => array( 'always', 'unknown', true ),
			'always no drift'      => array( 'always', '', false ),
			'always unheard-of'    => array( 'always', 'surprise', false ),
		);
	}

	/**
	 * The declared policies are exactly the ones the matrix above covers.
	 */
	public function test_policy_list_is_closed(): void {
		$this->assertSame( array( 'never', 'non_content', 'always' ), Config::DRIFT_POLICIES );
		$this->assertContains( Config::DEFAULT_DRIFT_POLICY, Config::DRIFT_POLICIES );
	}
}
