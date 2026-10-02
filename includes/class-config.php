<?php
/**
 * The runtime config VIP defines for this integration.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads `VIP_SAVE_WITHOUT_PUBLISH_CONFIG`, the one constant the platform sets.
 *
 * Every read of the constant goes through here, and nothing here is required:
 * an undefined constant, a value that is not an array, and a setting that is
 * missing or unrecognised all mean "use the plugin's own default". The plugin
 * behaves exactly as it does without the Integration Center until someone
 * sets a value, and a bad value can never stop it loading.
 *
 * The one setting is `scheduled_publish_overrides_drift`. It does not decide a
 * scheduled publish itself: it is the starting answer to the
 * `swpub_scheduled_publish_overrides_drift` filter, so a site's own filter
 * still has the last word.
 */
final class Config {

	/**
	 * The constant VIP defines before the plugin loads: a plain array.
	 */
	public const CONSTANT_NAME = 'VIP_SAVE_WITHOUT_PUBLISH_CONFIG';

	/**
	 * Keys the plugin cannot work without. None: every setting is optional.
	 */
	public const REQUIRED_FIELDS = array();

	/**
	 * Keys whose values must never be rendered or logged. None: nothing here is a secret.
	 */
	public const SENSITIVE_FIELDS = array();

	/**
	 * The key holding the drift policy for a scheduled publish.
	 */
	public const DRIFT_POLICY_KEY = 'scheduled_publish_overrides_drift';

	/**
	 * What a scheduled publish does when the published post changed first.
	 *
	 * `never` stops and keeps the staged copy for a person to review, which is
	 * what the plugin has always done. `non_content` publishes past a change to
	 * a field the merge never writes (a term, the featured image, the slug) and
	 * still stops for a change to the words. `always` publishes the staged
	 * words over any change.
	 */
	public const DRIFT_POLICIES = array( 'never', 'non_content', 'always' );

	/**
	 * The policy used when none is set, or the one set is not recognised.
	 */
	public const DEFAULT_DRIFT_POLICY = 'never';

	/**
	 * The settings, empty unless the constant held an array.
	 *
	 * @var array<mixed>
	 */
	private array $config;

	/**
	 * Whether the constant held an array at all.
	 *
	 * @var bool
	 */
	private bool $available;

	/**
	 * Use `current()` or `from_raw()`.
	 *
	 * @param array<mixed> $config    The settings.
	 * @param bool         $available Whether the constant held an array.
	 */
	private function __construct( array $config, bool $available ) {
		$this->config    = $config;
		$this->available = $available;
	}

	/**
	 * Reads the constant as VIP defined it, or an unavailable config if it is not defined.
	 *
	 * @return self
	 */
	public static function current(): self {
		if ( ! defined( self::CONSTANT_NAME ) ) {
			return self::from_raw( null );
		}

		return self::from_raw( constant( self::CONSTANT_NAME ) );
	}

	/**
	 * Builds a config from a raw value, however malformed.
	 *
	 * @param mixed $raw What the constant held, or null when it was not defined.
	 * @return self
	 */
	public static function from_raw( mixed $raw ): self {
		if ( is_array( $raw ) ) {
			return new self( $raw, true );
		}

		return new self( array(), false );
	}

	/**
	 * Whether the platform defined a config array at all.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * The required keys that are not usable. Always empty: nothing is required.
	 *
	 * @return array<string>
	 */
	public function missing_fields(): array {
		return array();
	}

	/**
	 * Whether every required key is usable. True whether or not a config exists.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return array() === $this->missing_fields();
	}

	/**
	 * The drift policy in force: a recognised setting, or `never`.
	 *
	 * @return string One of `DRIFT_POLICIES`.
	 */
	public function drift_policy(): string {
		$value = $this->config[ self::DRIFT_POLICY_KEY ] ?? null;

		if ( is_string( $value ) && in_array( $value, self::DRIFT_POLICIES, true ) ) {
			return $value;
		}

		return self::DEFAULT_DRIFT_POLICY;
	}

	/**
	 * Whether the policy lets a scheduled publish proceed over this kind of drift.
	 *
	 * @param string $kind What `Drift::kind()` reported: `content`, `other`, or `unknown`.
	 * @return bool False for any kind the policy does not cover, including one
	 *              this method has never heard of.
	 */
	public function drift_override_default( string $kind ): bool {
		return match ( $this->drift_policy() ) {
			'always'      => in_array( $kind, array( 'content', 'other', 'unknown' ), true ),
			'non_content' => 'other' === $kind,
			default       => false,
		};
	}
}
