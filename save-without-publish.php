<?php
/**
 * Plugin Name:       Save without Publish
 * Plugin URI:        https://github.com/Automattic/save-without-publish
 * Description:       Edit a published post as a private staged copy. The live post keeps serving until you publish the change.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Automattic
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       save-without-publish
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';

const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/autoload.php';

/*
 * Loaded eagerly rather than through the autoloader. It declares the public
 * `is_staged()` predicate, and an integration guarding with `function_exists()`
 * must get a truthful answer whether or not anything has touched the class yet.
 */
require_once __DIR__ . '/includes/class-events.php';

/**
 * Whether staging is enabled for this request.
 *
 * The kill switch from R32. Filterable so an operator can disable staging during
 * an incident without deactivating the plugin, which would unregister the staged
 * post status and stop containing existing staged copies.
 *
 * @return bool True when published posts should stage instead of publishing.
 */
function is_enabled(): bool {
	/**
	 * Filters whether Save without Publish stages edits to published posts.
	 *
	 * Returning false makes saves behave exactly as core does. Existing staged copies
	 * stay registered and contained; they simply cannot be created or merged.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $enabled Whether staging is active. Default true.
	 */
	return (bool) apply_filters( 'swpub_is_enabled', true );
}

/**
 * The post types this plugin attaches its write seams to.
 *
 * Keyed on `show_in_rest`, not `public`. The seam is `rest_pre_insert_{$type}`,
 * so `show_in_rest` is exactly what decides whether the seam exists at all --
 * and a type that reaches the block editor without it is the worst outcome
 * available here: the save publishes straight to the live post, silently, which
 * is the accident this plugin exists to prevent. A private-but-REST-exposed
 * custom post type is an ordinary thing to register, and it is editable in the
 * block editor like any other.
 *
 * Both the fork and the field lock read this, so the two can never disagree
 * about which types are covered.
 *
 * @return string[] Post type names.
 */
function staged_post_types(): array {
	$types = get_post_types( array( 'show_in_rest' => true ), 'names' );

	/**
	 * Filters the post types eligible for staging.
	 *
	 * Removing a type makes its saves publish immediately, as core does.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $types Post type names.
	 */
	return (array) apply_filters( 'swpub_post_types', array_values( $types ) );
}

/**
 * Boots the plugin.
 *
 * Wired on `init` at priority 0 so the staged post status is registered before
 * anything consults the status list. Request-time hooks that must see every
 * registered post type attach later, on `rest_api_init`.
 *
 * @return void
 */
function bootstrap(): void {
	Status::init();
	Capabilities::init();
	Admin_Surfaces::init();
	Editor_Assets::init();
	Merge_Resume::init();
	Merge::init();
	Post_List::init();
	Review_Link::init();
	Transitions::init();
	CLI::init();
	Upgrade::init();
	Events::init();
}

add_action( 'init', __NAMESPACE__ . '\\bootstrap', 0 );

/*
 * The fork listener registers at load rather than on `init`. It only adds a
 * `rest_api_init` action, and that hook can fire before `init` -- registering it
 * later means it never fires and every save publishes to the live post silently.
 *
 * The write guard registers here for the same reason and needs it more: another
 * plugin can call `wp_insert_post()` before `init` fires, and a containment
 * filter that was not attached yet does not fail loudly -- the write just
 * publishes. It only adds filters, so nothing it needs is registered later.
 *
 * `Rest_Response` joins them on the same one-line reasoning rather than on the
 * same stakes: missing its `rest_api_init` registration costs a client the field
 * that explains its own response, not a silent publish.
 */
Write_Guard::init();
Fork::init();
Field_Lock::init();
Merge_Route::init();
Stage_Route::init();
Rest_Response::init();
