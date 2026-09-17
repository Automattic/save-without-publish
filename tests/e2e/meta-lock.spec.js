/**
 * A locked `meta` key is stripped and warned about, not refused wholesale
 * (VIPPROD-755), through the real editor rather than a crafted REST request.
 *
 * Installs a trivial sidebar plugin -- the shape a real SEO plugin's own
 * panel takes: a `PluginSidebar` bound to one `meta` key through
 * `useEntityProp`, so typing into it dirties `meta` in the entity's edit
 * set exactly the way a real one would. Then it types into the sidebar and
 * the canvas of a staged copy in the same session and saves once, the way an
 * editor actually would: one save, both fields dirty.
 *
 * Before VIPPROD-755's fix to `class-field-lock.php`, run against `main` @
 * cc7c417: the save below failed with a 403 (`swpub_field_locked` naming
 * `meta`), core's "Updating failed" notice appeared on screen, the editor
 * still reported the post dirty, and the canvas edit was lost along with the
 * sidebar field -- one locked meta key cost the whole save. The test does
 * not touch the editor's own JavaScript -- the fix is server-side only, so
 * the editor still believes every save here succeeded cleanly; what the fix
 * changes is whether the words actually landed. `test-field-lock.php`'s
 * `Test_Field_Lock_Meta` pins the same fix at the REST layer directly.
 */

const { test, expect } = require( '@playwright/test' );
const {
	wp,
	createPublishedPost,
	createStagedCopyFor,
	openEditor,
	canvasOf,
	cleanUp,
} = require( './helpers' );

const SEO_META_KEY = 'swpub_test_seo_description';

const SIDEBAR_PHP = `<?php
add_action( 'init', function () {
	register_post_meta(
		'post',
		'${ SEO_META_KEY }',
		array(
			'show_in_rest' => true,
			'single'       => true,
			'type'         => 'string',
		)
	);
} );

add_action( 'enqueue_block_editor_assets', function () {
	wp_enqueue_script(
		'swpub-test-sidebar',
		content_url( 'mu-plugins/swpub-test-sidebar.js' ),
		array( 'wp-plugins', 'wp-editor', 'wp-components', 'wp-element', 'wp-core-data', 'wp-data' ),
		'1.0.0',
		true
	);
} );
`;

// Deliberately not the shape of anything in src/editor -- this is a stand-in
// for a plugin this project does not own, written the plain way a small
// site-specific sidebar is: no build step, one useEntityProp binding, one
// field. Exactly the shape a real SEO plugin's description field takes.
const SIDEBAR_JS = `( function ( wp ) {
	var registerPlugin = wp.plugins.registerPlugin;
	var PluginSidebar = wp.editor.PluginSidebar;
	var TextControl = wp.components.TextControl;
	var useEntityProp = wp.coreData.useEntityProp;
	var useSelect = wp.data.useSelect;
	var el = wp.element.createElement;

	function SidebarPanel() {
		var postType = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );
		var entityProp = useEntityProp( 'postType', postType, 'meta' );
		var meta = entityProp[ 0 ] || {};
		var setMeta = entityProp[ 1 ];

		return el(
			PluginSidebar,
			{ name: 'swpub-test-seo', title: 'Test SEO', icon: 'admin-generic' },
			el( TextControl, {
				label: 'Description',
				value: meta.${ SEO_META_KEY } || '',
				onChange: function ( value ) {
					var next = Object.assign( {}, meta );
					next.${ SEO_META_KEY } = value;
					setMeta( next );
				},
			} )
		);
	}

	registerPlugin( 'swpub-test-sidebar', { render: SidebarPanel } );
} )( window.wp );
`;

/**
 * Installs the sidebar plugin as a must-use plugin, so it is present for
 * every editor screen load without touching this project's own plugin.
 *
 * @return {void}
 */
function installSeoSidebarFixture() {
	const php = Buffer.from( SIDEBAR_PHP, 'utf8' ).toString( 'base64' );
	const js = Buffer.from( SIDEBAR_JS, 'utf8' ).toString( 'base64' );

	wp( [
		'eval',
		`wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/swpub-test-sidebar.php', base64_decode( '${ php }' ) ); file_put_contents( WPMU_PLUGIN_DIR . '/swpub-test-sidebar.js', base64_decode( '${ js }' ) );`,
	] );
}

/**
 * Removes the fixture, so it does not ride into any other spec file.
 *
 * @return {void}
 */
function removeSeoSidebarFixture() {
	wp( [
		'eval',
		"foreach ( array( WPMU_PLUGIN_DIR . '/swpub-test-sidebar.php', WPMU_PLUGIN_DIR . '/swpub-test-sidebar.js' ) as $file ) { if ( file_exists( $file ) ) { unlink( $file ); } }",
	] );
}

/**
 * Opens the staged copy, types into the sidebar field and the canvas, and
 * saves once.
 *
 * @param {import('@playwright/test').Page} page         The page.
 * @param {number}                          stagedCopyId Staged copy ID.
 * @param {string}                          canvasText   Text appended to the first paragraph.
 * @param {string}                          sidebarText  Text typed into the SEO field.
 * @return {Promise<import('@playwright/test').Response>} The save response.
 */
async function editBothFieldsAndSave(
	page,
	stagedCopyId,
	canvasText,
	sidebarText
) {
	await openEditor( page, stagedCopyId );

	await page.getByRole( 'button', { name: 'Test SEO' } ).click();
	await page
		.getByRole( 'textbox', { name: 'Description' } )
		.fill( sidebarText );

	const paragraph = canvasOf( page )
		.locator( 'p[data-type="core/paragraph"]' )
		.first();
	await paragraph.click();
	await page.keyboard.press( 'End' );
	await page.keyboard.type( canvasText );

	const saved = page.waitForResponse(
		( response ) =>
			/^(?:PUT|POST)$/.test( response.request().method() ) &&
			new RegExp( `/wp/v2/posts/${ stagedCopyId }(?![\\d/])` ).test(
				decodeURIComponent( response.url() )
			)
	);
	await page.keyboard.press( 'ControlOrMeta+s' );

	return saved;
}

test.describe( 'A sidebar plugin writing meta alongside a staged copy save', () => {
	let liveId;
	let stagedCopyId;

	test.beforeAll( () => {
		installSeoSidebarFixture();
	} );

	test.afterAll( () => {
		removeSeoSidebarFixture();
	} );

	test.beforeEach( () => {
		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			'Launches in June.'
		);
		stagedCopyId = createStagedCopyFor( liveId );
	} );

	test.afterEach( () => {
		cleanUp( liveId );
	} );

	/**
	 * Regression pin for VIPPROD-755 (fixed in `class-field-lock.php`): the
	 * save succeeds, the canvas edit persists, and the sidebar's meta key
	 * was silently dropped -- server-side only, so the editor itself still
	 * reports the save as clean. That the SEO field did not actually
	 * persist is visible in `X-SWPub-Meta-Dropped` and
	 * `swpub_meta_keys_dropped`, not in this screen; VIPPROD-755 T4 is what
	 * gives the editor its own awareness of a staged-meta set.
	 *
	 * Before the fix, run against `main` @ cc7c417: this test failed with a
	 * 403 (`swpub_field_locked` naming `meta`), and the canvas edit above
	 * was lost along with the sidebar field, with core's "Updating failed"
	 * notice on screen and the editor still reporting the post dirty.
	 */
	test( 'the save succeeds and the canvas edit persists; the sidebar field is silently dropped', async ( {
		page,
	} ) => {
		const response = await editBothFieldsAndSave(
			page,
			stagedCopyId,
			' Now with a description.',
			'Meridian Active launches its fall collection.'
		);

		expect( response.status() ).toBe( 200 );
		expect( response.headers()[ 'x-swpub-meta-dropped' ] ).toBe(
			SEO_META_KEY
		);

		await expect(
			page
				.locator( '.components-notice__content' )
				.filter( { hasText: 'Updating failed' } )
		).toHaveCount( 0 );

		const content = wp( [
			'post',
			'get',
			String( stagedCopyId ),
			'--field=content',
		] );
		expect( content ).toContain( 'Now with a description' );

		// `wp post meta get` errors on a key that was never written, which is
		// exactly the state the fix leaves it in; `wp eval` reads the same way
		// `get_post_meta()` answers everywhere else in this suite, empty
		// string included.
		const meta = wp( [
			'eval',
			`echo get_post_meta( ${ stagedCopyId }, '${ SEO_META_KEY }', true );`,
		] );
		expect( meta ).toBe( '' );
	} );
} );
