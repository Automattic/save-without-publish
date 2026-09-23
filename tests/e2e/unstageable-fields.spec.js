/**
 * The controls for fields that cannot be staged, taken off the screen
 * (VIPPROD-1228).
 *
 * `test-field-lock.php` and `test-admin-surfaces.php` already prove the
 * server refuses these fields and removes the classic editor's boxes for
 * them. What only a browser can prove is what this ticket is actually about:
 * that the block editor's Summary panel does not go on offering a control
 * for a field the very next save would refuse, and that an editor who
 * changes one anyway has a way back that does not require remembering what
 * the value was.
 */

const { test, expect } = require( '@playwright/test' );
const {
	cleanUp,
	createPublishedPost,
	createStagedCopyFor,
	getPostField,
	installTestTaxonomy,
	notice,
	openEditor,
	removeTestTaxonomy,
	showDocumentPanel,
	stagedCopyIdFor,
	canvasOf,
} = require( './helpers' );

const PUBLISHED_TEXT = 'The Summer collection launches in June.';

/**
 * Every control this ticket takes off a staged copy's screen, and how to
 * find it -- a class where core's own markup carries one, an `aria-label`
 * prefix where it does not (`panel-rows.scss`'s own doc-block explains why
 * those two are weaker).
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Object<string, Object>} Name to locator.
 */
function unstageableControls( page ) {
	return {
		'Publish (date)': page.locator(
			'.editor-post-schedule__panel-dropdown'
		),
		Slug: page.locator( '.editor-post-url__panel-dropdown' ),
		Author: page.locator( '[aria-label^="Change author:"]' ),
		Template: page.locator(
			'.editor-post-summary .components-dropdown-menu__toggle'
		),
		Format: page.locator( '[aria-label^="Change format:"]' ),
		Discussion: page.locator( '.editor-post-discussion__panel-dropdown' ),
		'Featured image': page.locator( '.editor-post-featured-image' ),
		Categories: page
			.locator( '.components-panel__body-title' )
			.filter( { hasText: 'Categories' } ),
		Tags: page
			.locator( '.components-panel__body-title' )
			.filter( { hasText: 'Tags' } ),
	};
}

/**
 * The rows this plugin's own surfaces render, which must stay exactly as
 * present on a staged copy as the controls above are absent.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Object<string, Object>} Name to locator.
 */
function ownRows( page ) {
	return {
		Status: page.locator( '.swpub-status-row' ),
		'Publish at': page.locator( '.swpub-schedule-row' ),
		'Published post': page.locator( '.swpub-published-row' ),
		Discard: page.locator( '.swpub-discard-row' ),
	};
}

test.describe( 'Fields that cannot be staged', () => {
	let liveId;
	let stagedCopyId;

	test.beforeEach( () => {
		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			PUBLISHED_TEXT
		);
		stagedCopyId = createStagedCopyFor( liveId );
	} );

	test.afterEach( () => {
		cleanUp( liveId );
	} );

	test( "every unstageable control is hidden, and this plugin's own rows are not", async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		for ( const [ name, locator ] of Object.entries(
			unstageableControls( page )
		) ) {
			await expect( locator, `${ name } should be hidden` ).toBeHidden();
		}

		for ( const [ name, locator ] of Object.entries( ownRows( page ) ) ) {
			await expect(
				locator,
				`${ name } should be visible`
			).toBeVisible();
		}
	} );

	test( 'the Published post row says where those fields are changed', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		await expect( page.locator( '.swpub-published-row__help' ) ).toHaveText(
			'Slug, publish date, author, categories, tags, featured image, and discussion are set on the published post.'
		);
	} );

	test( 'a custom REST taxonomy is hidden on the copy too', async ( {
		page,
	} ) => {
		installTestTaxonomy();

		try {
			await openEditor( page, stagedCopyId );
			await showDocumentPanel( page );

			await expect(
				page
					.locator( '.components-panel__body-title' )
					.filter( { hasText: 'Topics' } )
			).toBeHidden();
		} finally {
			removeTestTaxonomy();
		}
	} );

	test( 'every control is present on the published post that has a copy, and on an ordinary post', async ( {
		page,
	} ) => {
		const other = createPublishedPost(
			'An ordinary post',
			'Nothing staged.'
		);

		for ( const id of [ liveId, other ] ) {
			await openEditor( page, id );
			await showDocumentPanel( page );

			for ( const [ name, locator ] of Object.entries(
				unstageableControls( page )
			) ) {
				await expect(
					locator,
					`${ name } should be visible on post ${ id }`
				).toBeVisible();
			}
		}

		cleanUp( other );
	} );

	test( 'hiding these controls on a staged copy writes no lasting preference', async ( {
		page,
	} ) => {
		const other = createPublishedPost(
			'An ordinary post',
			'Nothing staged.'
		);

		// Visit the staged copy first, where Categories, Tags, and Featured
		// image are all removed with `removeEditorPanel()`.
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );
		await expect(
			page
				.locator( '.components-panel__body-title' )
				.filter( { hasText: 'Categories' } )
		).toBeHidden();

		// A fresh navigation to an unrelated post. If `removeEditorPanel()`
		// had been `toggleEditorPanelEnabled()` instead -- a written user
		// preference rather than an in-memory flag -- Categories would stay
		// hidden here too.
		await openEditor( page, other );
		await showDocumentPanel( page );

		await expect(
			page
				.locator( '.components-panel__body-title' )
				.filter( { hasText: 'Categories' } )
		).toBeVisible();
		await expect(
			page
				.locator( '.components-panel__body-title' )
				.filter( { hasText: 'Tags' } )
		).toBeVisible();
		await expect(
			page.locator( '.editor-post-featured-image' )
		).toBeVisible();

		cleanUp( other );
	} );

	test( 'the rows being hidden does not stop staging from working', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );

		const paragraph = canvasOf( page )
			.locator( 'p[data-type="core/paragraph"]' )
			.first();

		await paragraph.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' It only gets better from here.' );
		await page.keyboard.press( 'ControlOrMeta+s' );

		await expect( page.locator( '.components-snackbar' ) ).toBeVisible();

		await page.reload();

		await expect(
			canvasOf( page ).getByText( 'It only gets better from here.' )
		).toBeVisible();
	} );
} );

test.describe( 'Undo those changes', () => {
	// Deliberately the default administrator, not the revisor: core's own
	// slug editor only renders for someone who holds `publish_posts`
	// (`isPermalinkEditable()` reads the REST response's `wp:action-publish`
	// link), which the revisor is explicitly denied. This plugin's own
	// stage-or-publish decision is a separate capability nobody is granted
	// by default (`Capabilities::map_publish_directly()`), so the plain
	// administrator -- unlike `stage.spec.js`'s "publisher", who calls
	// `grantDirectPublish()` -- already stages, and can still see the
	// control this refusal is about.
	let liveId;
	let originalSlug;

	test.beforeEach( () => {
		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			PUBLISHED_TEXT
		);
		originalSlug = getPostField( liveId, 'post_name' );
	} );

	test.afterEach( () => {
		cleanUp( liveId );
	} );

	test( 'undoes the refused field and lets the text change stage', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );
		await showDocumentPanel( page );

		// The slug, changed through the store rather than core's slug field:
		// that field only renders where permalinks are pretty
		// (`isPermalinkEditable()`), and CI's site runs on plain ones. What
		// the refusal and the undo key on is the edit set, not which control
		// produced it -- the same reasoning as `dirtyTitle()`.
		await page.evaluate( () => {
			window.wp.data
				.dispatch( 'core/editor' )
				.editPost( { slug: 'a-slug-nobody-will-remember' } );
		} );

		// And the words, alongside it -- the change that should stage.
		const paragraph = canvasOf( page )
			.locator( 'p[data-type="core/paragraph"]' )
			.first();

		await paragraph.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' It is going to be good.' );
		await page.keyboard.press( 'ControlOrMeta+s' );

		await expect(
			notice( page, 'The slug cannot be staged' )
		).toBeVisible();

		/*
		 * Core's own `Notice` renders `.components-notice__actions` as a
		 * sibling of `.components-notice__content`, not a descendant of it
		 * -- `notice()` is scoped to the content div on purpose (its own
		 * doc-block: the screen-reader live region would otherwise be a
		 * second match), so the action button is reached from the notice
		 * as a whole instead.
		 */
		await page
			.locator( '.components-notice', {
				has: notice( page, 'The slug cannot be staged' ),
			} )
			.getByRole( 'button', { name: 'Undo those changes' } )
			.click();

		await expect( notice( page, 'The slug cannot be staged' ) ).toHaveCount(
			0
		);

		await showDocumentPanel( page );

		await expect(
			page.locator( '.editor-post-url__panel-toggle' )
		).toHaveText( originalSlug );

		await page.keyboard.press( 'ControlOrMeta+s' );

		// The published post's first stage forks to a new copy and navigates
		// there (`fork-navigation.js`'s `stageInstead()`) -- not the same-page
		// snackbar a save onto an existing copy shows.
		await page.waitForURL( /swpub_forked=1/ );

		expect(
			stagedCopyIdFor( liveId ),
			'The text change should have staged once the slug was undone.'
		).toBeGreaterThan( 0 );

		expect(
			getPostField( liveId, 'post_name' ),
			"The published post's own slug must be untouched."
		).toBe( originalSlug );
	} );
} );
