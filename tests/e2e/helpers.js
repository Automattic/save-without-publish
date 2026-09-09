/**
 * Shared helpers for the end-to-end suite.
 */

const { execFileSync } = require( 'child_process' );

/**
 * Runs a WP-CLI command inside the wp-env container.
 *
 * Site state is set up through WP-CLI rather than through the browser, so a
 * test that is about publishing a staged change does not fail because of
 * something unrelated in the post creation UI.
 *
 * @param {string[]} args WP-CLI arguments.
 * @return {string} Trimmed stdout.
 */
function wp( args ) {
	const output = execFileSync(
		'npx',
		[ 'wp-env', 'run', 'cli', 'wp', ...args ],
		{
			encoding: 'utf8',
			maxBuffer: 10 * 1024 * 1024,
			// Capture stderr rather than letting it through: "post does not
			// exist" is an expected answer for several helpers, and printing it
			// makes a passing run look like a failing one.
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		}
	);

	// wp-env prefixes its own progress lines; the command's own output is last.
	return output
		.split( '\n' )
		.filter(
			( line ) => ! line.startsWith( 'ℹ' ) && ! line.startsWith( '✔' )
		)
		.join( '\n' )
		.trim();
}

/**
 * Creates a published post whose body is a real paragraph block.
 *
 * Block markup matters: content written as plain text becomes a Classic block,
 * which cannot be typed into the way these tests type into it.
 *
 * @param {string} title   Post title.
 * @param {string} content Paragraph text.
 * @return {number} The new post ID.
 */
function createPublishedPost( title, content ) {
	const markup = `<!-- wp:paragraph --><p>${ content }</p><!-- /wp:paragraph -->`;

	return Number(
		wp( [
			'post',
			'create',
			`--post_title=${ title }`,
			`--post_content=${ markup }`,
			'--post_status=publish',
			'--porcelain',
		] )
	);
}

/**
 * Reads one field from a post.
 *
 * @param {number} id    Post ID.
 * @param {string} field Field name.
 * @return {string} The value, or an empty string when the post is gone.
 */
function getPostField( id, field ) {
	try {
		return wp( [ 'post', 'get', String( id ), `--field=${ field }` ] );
	} catch {
		// The post is gone, which is a valid answer here.
		return '';
	}
}

/**
 * Whether a post still exists.
 *
 * @param {number} id Post ID.
 * @return {boolean} True when it exists.
 */
function postExists( id ) {
	return getPostField( id, 'ID' ) === String( id );
}

/**
 * The staged copy staged against a published post, if any.
 *
 * @param {number} liveId Published post ID.
 * @return {number} Staged copy ID, or 0.
 */
function stagedCopyIdFor( liveId ) {
	try {
		return (
			Number(
				wp( [
					'post',
					'meta',
					'get',
					String( liveId ),
					'_swpub_staged_copy_id',
				] )
			) || 0
		);
	} catch {
		// No pointer, or no post to read it from.
		return 0;
	}
}

/**
 * Stages a staged copy directly, for tests that are not about the fork itself.
 *
 * @param {number} liveId Published post ID.
 * @return {number} The staged copy ID.
 */
function createStagedCopyFor( liveId ) {
	return Number(
		wp( [
			'eval',
			`echo \\SaveWithoutPublish\\Staged_Copy_Repository::create( get_post( ${ liveId } ) )->ID;`,
		] )
	);
}

/**
 * Moves a published post's GMT modified time, producing drift.
 *
 * Written to the row directly because core overwrites this field on every
 * update, so it cannot be set through the API.
 *
 * @param {number} liveId Published post ID.
 * @param {string} when   GMT timestamp.
 * @return {void}
 */
function forceModifiedGmt( liveId, when ) {
	wp( [
		'eval',
		`global $wpdb; $wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '${ when }' ), array( 'ID' => ${ liveId } ) ); clean_post_cache( ${ liveId } );`,
	] );
}

/**
 * Rewrites a published post's content behind the plugin's back.
 *
 * Written to the row directly, for the same reason `forceModifiedGmt` is. Once a
 * staged copy exists the write guard contains every write to the published post
 * on every transport, including WP-CLI's -- so `wp post update` no longer
 * produces drift, it produces a staged copy holding somebody else's words. The
 * only way left to stand a test up in the state drift describes is to put the
 * row there, which is what a database-level edit or a restore from backup would
 * do in the wild.
 *
 * @param {number} liveId  Published post ID.
 * @param {string} content New content.
 * @return {void}
 */
function forcePublishedContent( liveId, content ) {
	wp( [
		'eval',
		// The revision is part of the fixture, not a flourish: an edit that
		// reached the published post leaves one behind, and the drift notice's
		// "Review the change" link is a link to it.
		`global $wpdb; $wpdb->update( $wpdb->posts, array( 'post_content' => '${ content }' ), array( 'ID' => ${ liveId } ) ); clean_post_cache( ${ liveId } ); wp_save_post_revision( ${ liveId } );`,
	] );
}

/**
 * Deletes a post and any staged copy it has, ignoring what is already gone.
 *
 * @param {number} id Post ID.
 * @return {void}
 */
function cleanUp( id ) {
	const stagedCopyId = stagedCopyIdFor( id );

	for ( const target of [ stagedCopyId, id ] ) {
		if ( target > 0 ) {
			try {
				wp( [ 'post', 'delete', String( target ), '--force' ] );
			} catch {
				// Already gone, which is the state we wanted.
			}
		}
	}
}

/**
 * The block editor's content canvas.
 *
 * The canvas is an iframe in current WordPress, so locators aimed at the page
 * never find the blocks. Falls back to the page for the un-iframed case.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Object} A locator root for the editor content.
 */
function canvasOf( page ) {
	return page.frameLocator( 'iframe[name="editor-canvas"]' );
}

/**
 * The title field in the canvas.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Object} A locator for the title.
 */
function titleField( page ) {
	return canvasOf( page ).getByRole( 'textbox', { name: 'Add title' } );
}

/**
 * Makes the title dirty through the store, the way a plugin or a restored
 * autosave would, rather than by typing -- which a read-only title no longer
 * allows. What the save lock keys on is the edit set, not which control
 * produced it, so this is the direct way to exercise it.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string}                          text The new title.
 * @return {Promise<void>}
 */
function dirtyTitle( page, text ) {
	return page.evaluate( ( value ) => {
		window.wp.data.dispatch( 'core/editor' ).editPost( { title: value } );
	}, text );
}

/**
 * Opens a post in the block editor and waits for it to be usable.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {number}                          id   Post ID.
 * @return {Promise<void>}
 */
async function openEditor( page, id ) {
	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );

	// The welcome modal steals the first click if it is showing. Scoped to the
	// dialog: core's notice dismiss button is also called "Close", and an
	// unscoped match closes the notice under test instead.
	const modalClose = page.getByRole( 'dialog' ).getByRole( 'button', {
		name: 'Close',
		exact: true,
	} );
	if ( await modalClose.isVisible().catch( () => false ) ) {
		await modalClose.click();
	}

	await canvasOf( page )
		.locator( 'p[data-type="core/paragraph"]' )
		.first()
		.waitFor();
}

/**
 * Types at the end of the first paragraph and saves.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string}                          text Text to append.
 * @return {Promise<void>}
 */
async function appendAndSave( page, text ) {
	const paragraph = canvasOf( page )
		.locator( 'p[data-type="core/paragraph"]' )
		.first();

	await paragraph.click();
	await page.keyboard.press( 'End' );
	await page.keyboard.type( text );

	await page.keyboard.press( 'ControlOrMeta+s' );
}

/**
 * A notice in the editor, matched by its text.
 *
 * Scoped to the notice element deliberately. WordPress also mirrors notice text
 * into an accessibility live region, so an unscoped text match finds two nodes
 * and fails strict mode -- which looks like a missing notice and is the
 * opposite.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string}                          text Text the notice contains.
 * @return {Object} A locator for the notice.
 */
function notice( page, text ) {
	return page
		.locator( '.components-notice__content' )
		.filter( { hasText: text } );
}

/**
 * The Summary panel's review control.
 *
 * Located by its accessible name rather than its visible text: on a staged copy
 * this is the Status row's own value, which reads "Staged" and is announced as
 * "Staged: review staged changes".
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Object} A locator for the control.
 */
function reviewControl( page ) {
	return page.getByRole( 'link', { name: 'Review staged changes' } );
}

/**
 * Makes the signed-in administrator someone whose saves publish directly.
 *
 * No role holds `swpub_publish_directly_posts`, so on a default install every
 * save to a published post stages. A test about the other path has to say so,
 * the same way a site does.
 *
 * @return {void}
 */
function grantDirectPublish() {
	wp( [ 'user', 'add-cap', 'admin', 'swpub_publish_directly_posts' ] );
}

/**
 * Puts the administrator back on the default, so the next spec starts there.
 *
 * @return {void}
 */
function revokeDirectPublish() {
	wp( [ 'user', 'remove-cap', 'admin', 'swpub_publish_directly_posts' ] );
}

/**
 * The Summary panel's link to the published post's own editor.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Object} A locator for the control.
 */
function publishedEditControl( page ) {
	return page.getByRole( 'link', { name: 'Edit the published post' } );
}

/**
 * Core's publish control in the editor header.
 *
 * Located by class rather than by name: once the confirmation modal is open,
 * two buttons read "Publish changes", and the header one is not the one the
 * modal step means.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Object} A locator for the button.
 */
function publishButton( page ) {
	return page.locator( '.editor-post-publish-button__button' );
}

/**
 * Publishes staged changes the way an editor does: the header button, and that
 * is the whole gesture. Nothing is confirmed on the ordinary path.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Promise<void>}
 */
async function publishStagedChanges( page ) {
	await publishButton( page ).click();
}

/**
 * Brings the document settings sidebar back to the front.
 *
 * Clicking into a block switches the sidebar to its Block tab, which hides
 * every document panel including this plugin's.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Promise<void>}
 */
async function showDocumentPanel( page ) {
	// A fresh profile starts with the sidebar closed, and a closed sidebar
	// renders none of the Summary rows these tests assert on.
	const settings = page.getByRole( 'button', { name: 'Settings' } );

	if (
		( await settings
			.getAttribute( 'aria-pressed' )
			.catch( () => null ) ) === 'false'
	) {
		await settings.click();
	}

	const postTab = page.getByRole( 'tab', { name: 'Post' } );

	if ( await postTab.isVisible().catch( () => false ) ) {
		await postTab.click();
	}
}

/**
 * Every revision of a post, in the order core itself presents them.
 *
 * Core's own function is used rather than a sort of our own, because revision
 * ID order is not chronological here and assuming it is asserts against the
 * wrong end of the history. A pre-merge snapshot is written after the staged
 * revisions are adopted, so it has a higher ID -- but it carries the published
 * post's own modified time, which is older. Core orders by date, so the
 * snapshot correctly sits earlier in the timeline despite the later ID.
 *
 * @param {number} postId Post ID.
 * @return {number[]} Revision IDs, oldest first, in core's ordering.
 */
function revisionsOf( postId ) {
	const ids = wp( [
		'eval',
		'--user=1',
		`foreach ( wp_get_post_revisions( ${ postId }, array( 'order' => 'ASC' ) ) as $revision ) { echo $revision->ID . "\\n"; }`,
	] );

	return ids.split( '\n' ).filter( Boolean ).map( Number );
}

/**
 * The site-side listener that makes the backstop alarm observable.
 *
 * `swpub_staged_via_backstop` is a PHP action, and the assertion that matters is
 * that it stays silent across a whole browser flow -- which no request-scoped
 * check can see. So the flow's own site records every firing into an option, and
 * the specs read it back through WP-CLI.
 *
 * Written as a must-use plugin because it has to be listening during the browser's
 * requests, not during the CLI call that installs it. It only ever appends to one
 * option, and only for this plugin's own action, so it changes nothing a spec
 * asserts on.
 */
const RECORDER = `<?php
add_action(
	'swpub_staged_via_backstop',
	function ( $staged_copy_id, $live_id, $user_id ) {
		$seen   = get_option( 'swpub_e2e_backstop', array() );
		$seen[] = array(
			'staged_copy_id' => (int) $staged_copy_id,
			'live_id'        => (int) $live_id,
			'user_id'        => (int) $user_id,
		);
		update_option( 'swpub_e2e_backstop', $seen, false );
	},
	10,
	3
);
`;

/**
 * Installs the recorder. Idempotent, so setup can run it every time.
 *
 * @return {void}
 */
function installEventRecorder() {
	const encoded = Buffer.from( RECORDER, 'utf8' ).toString( 'base64' );

	wp( [
		'eval',
		`wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/swpub-e2e-events.php', base64_decode( '${ encoded }' ) );`,
	] );
}

/**
 * Every backstop firing recorded since the log was last cleared.
 *
 * @return {Array<Object>} The firings, oldest first.
 */
function backstopEvents() {
	const raw = wp( [
		'eval',
		"echo wp_json_encode( array_values( (array) get_option( 'swpub_e2e_backstop', array() ) ) );",
	] );

	try {
		return JSON.parse( raw ) || [];
	} catch {
		// An empty or unparsable option is the same answer as no firings.
		return [];
	}
}

/**
 * Forgets every recorded firing, so a spec reads only its own.
 *
 * @return {void}
 */
function clearBackstopEvents() {
	wp( [ 'eval', "delete_option( 'swpub_e2e_backstop' );" ] );
}

/**
 * Creates a category, or returns the existing one of that name.
 *
 * @param {string} name Category name.
 * @return {number} The term ID.
 */
function createCategory( name ) {
	return Number(
		wp( [
			'eval',
			`$term = term_exists( '${ name }', 'category' ); if ( ! $term ) { $term = wp_insert_term( '${ name }', 'category' ); } echo (int) $term['term_id'];`,
		] )
	);
}

/**
 * Ticks a category in the Summary sidebar.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string}                          name Category name.
 * @return {Promise<void>}
 */
async function chooseCategory( page, name ) {
	await showDocumentPanel( page );

	const panel = page.getByRole( 'button', {
		name: 'Categories',
		exact: true,
	} );

	if ( ( await panel.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await panel.click();
	}

	await page.getByRole( 'checkbox', { name, exact: true } ).check();
}

module.exports = {
	wp,
	notice,
	reviewControl,
	grantDirectPublish,
	revokeDirectPublish,
	publishedEditControl,
	publishButton,
	publishStagedChanges,
	showDocumentPanel,
	revisionsOf,
	createPublishedPost,
	getPostField,
	postExists,
	stagedCopyIdFor,
	createStagedCopyFor,
	forceModifiedGmt,
	forcePublishedContent,
	cleanUp,
	canvasOf,
	titleField,
	dirtyTitle,
	openEditor,
	appendAndSave,
	installEventRecorder,
	backstopEvents,
	clearBackstopEvents,
	createCategory,
	chooseCategory,
};
