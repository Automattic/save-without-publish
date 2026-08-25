/**
 * Logs in once per identity and saves each session for the other specs.
 *
 * Logging in per test would triple the suite's runtime and add a failure mode
 * that has nothing to do with what is being tested.
 *
 * Two identities, because the entry rule splits on one capability. The
 * administrator can publish directly, so their saves publish and staging is a
 * deliberate act. The revisor -- an editor denied `publish_posts`, the user
 * PublishPress ships a role for and core does not -- stages on every save.
 */

const { test: setup } = require( '@playwright/test' );
const path = require( 'path' );
const fs = require( 'fs' );
const { execFileSync } = require( 'child_process' );
const { installEventRecorder } = require( './helpers' );

const ADMIN_FILE = path.join(
	process.cwd(),
	'artifacts/storage-states/admin.json'
);

const REVISOR_FILE = path.join(
	process.cwd(),
	'artifacts/storage-states/revisor.json'
);

/**
 * Logs a user in and saves the session.
 *
 * @param {Object} page     Playwright page.
 * @param {Object} context  Browser context.
 * @param {string} login    Username.
 * @param {string} password Password.
 * @param {string} file     Where the storage state goes.
 */
async function saveSession( page, context, login, password, file ) {
	await page.goto( '/wp-login.php' );

	await page.locator( '#user_login' ).fill( login );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();

	await page.waitForURL( /wp-admin/ );

	fs.mkdirSync( path.dirname( file ), { recursive: true } );
	await context.storageState( { path: file } );
}

setup( 'authenticate as administrator', async ( { page, context } ) => {
	// The backstop alarm is a PHP action, and the assertion the suite cares about
	// is that it stays quiet across whole browser flows. Something has to be
	// listening inside the site while those flows run.
	installEventRecorder();

	await saveSession( page, context, 'admin', 'password', ADMIN_FILE );
} );

setup( 'create and authenticate the revisor', async ( { page, context } ) => {
	// Idempotent: user create fails quietly when the user exists, and the
	// capability deny is keyed, so re-running setup converges on the same user.
	const env = { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] };

	try {
		execFileSync(
			'npx',
			[
				'wp-env',
				'run',
				'cli',
				'wp',
				'user',
				'create',
				'revisor',
				'revisor@example.test',
				'--role=editor',
				'--user_pass=password',
			],
			env
		);
	} catch {
		// Already exists.
	}

	/*
	 * An explicit deny, not `remove-cap`: `publish_posts` comes from the editor
	 * role, and removing it from the user's own set leaves the role's grant
	 * standing. `add_cap( 'publish_posts', false )` overrides the role.
	 */
	execFileSync(
		'npx',
		[
			'wp-env',
			'run',
			'cli',
			'wp',
			'eval',
			"get_user_by( 'login', 'revisor' )->add_cap( 'publish_posts', false );",
		],
		env
	);

	/*
	 * Dismiss the editor's welcome guide before the first editor load. The
	 * modal steals the suite's first click, and openEditor()'s dismissal races
	 * an editor that is still mounting on a first-ever visit.
	 */
	execFileSync(
		'npx',
		[
			'wp-env',
			'run',
			'cli',
			'wp',
			'eval',
			"$u = get_user_by( 'login', 'revisor' ); update_user_meta( $u->ID, $GLOBALS['wpdb']->get_blog_prefix() . 'persisted_preferences', array( 'core/edit-post' => array( 'welcomeGuide' => false ), '_modified' => gmdate( 'c' ) ) );",
		],
		env
	);

	await saveSession( page, context, 'revisor', 'password', REVISOR_FILE );
} );
