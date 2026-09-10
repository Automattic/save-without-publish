/**
 * The deliberate path: staging is asked for by someone whose save publishes.
 *
 * The forked path (fork.spec.js) proves what happens to an editor who cannot
 * publish. This file is the other half of the entry rule, running as the
 * administrator the suite already has: Update publishes like core, and staging
 * happens exactly when it is requested -- from the editor with unsaved words in
 * hand, or from the posts list.
 */

const { test, expect } = require( '@playwright/test' );
const {
	createPublishedPost,
	getPostField,
	stagedCopyIdFor,
	createStagedCopyFor,
	cleanUp,
	openEditor,
	appendAndSave,
	canvasOf,
	dirtyTitle,
	publishButton,
	reviewControl,
	showDocumentPanel,
	titleField,
	backstopEvents,
	clearBackstopEvents,
	grantDirectPublish,
	revokeDirectPublish,
} = require( './helpers' );

const PUBLISHED_TEXT = 'The Summer collection launches in June.';
const STAGED_TEXT = ' It is going to be good.';

test.describe( 'A publisher and a published post', () => {
	let liveId;

	test.beforeEach( () => {
		clearBackstopEvents();

		// The block's name is the setup: a publisher is someone a site granted
		// the direct-publish capability, because no role ships holding it.
		grantDirectPublish();

		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			PUBLISHED_TEXT
		);
	} );

	// Every flow here is one a working editor takes, so the fork underneath the
	// protocol must never have been what carried it.
	test.afterEach( () => {
		const heard = backstopEvents();

		revokeDirectPublish();
		cleanUp( liveId );

		expect( heard, 'The save was carried by the backstop.' ).toHaveLength(
			0
		);
	} );

	test( 'a plain update publishes, as core does', async ( { page } ) => {
		await openEditor( page, liveId );
		await appendAndSave( page, STAGED_TEXT );

		// The save completes on the same post: no navigation, no staged copy.
		// Core disables the save button once nothing is left to save.
		await expect(
			page.getByRole( 'button', { name: 'Save', exact: true } )
		).toBeDisabled();
		await expect( page ).toHaveURL( new RegExp( `post=${ liveId }` ) );

		expect( stagedCopyIdFor( liveId ) ).toBe( 0 );
		expect( getPostField( liveId, 'post_content' ) ).toContain(
			STAGED_TEXT.trim()
		);
	} );

	test( 'the primary button keeps reading Save after a copy exists', async ( {
		page,
	} ) => {
		// The case the label used to get *less* accurate for (VIPPROD-1171): a
		// publisher's button correctly reads "Save" before a copy exists, and
		// used to flip to "Stage changes" -- the one thing a save from here
		// cannot do -- once one did.
		await openEditor( page, liveId );
		await expect( publishButton( page ) ).toHaveText( 'Save' );

		createStagedCopyFor( liveId );
		await openEditor( page, liveId );

		await expect( publishButton( page ) ).toHaveText( 'Save' );
	} );

	test( 'staging from the editor carries unsaved words to the copy', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );

		// Type and do not save. The words exist nowhere but the editor.
		const paragraph = canvasOf( page )
			.locator( 'p[data-type="core/paragraph"]' )
			.first();
		await paragraph.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( STAGED_TEXT );

		await showDocumentPanel( page );
		await page.getByRole( 'button', { name: 'Stage changes' } ).click();

		// The editor lands on the staged copy without an unsaved-changes
		// prompt, and the copy holds the words that were never saved.
		await page.waitForURL( /post\.php\?post=\d+/ );

		const stagedCopyId = stagedCopyIdFor( liveId );
		expect( stagedCopyId ).toBeGreaterThan( 0 );
		await expect( page ).toHaveURL(
			new RegExp( `post=${ stagedCopyId }` )
		);
		expect( getPostField( stagedCopyId, 'post_content' ) ).toContain(
			STAGED_TEXT.trim()
		);

		// The published post never saw them.
		expect( getPostField( liveId, 'post_content' ) ).not.toContain(
			STAGED_TEXT.trim()
		);
	} );

	test( 'a save onto a post with a staged copy is refused, not published', async ( {
		page,
	} ) => {
		// Someone who can publish, saving a post that already has a copy. The
		// copy owns the title, content, and excerpt now: the canvas and the
		// title are read-only, the save lock behind them still holds if a
		// title edit reaches the store some other way (VIPPROD-1171,
		// VIPPROD-1121). Nothing reaches either post on either route, and the
		// fork underneath never carried it.
		const stagedCopyId = createStagedCopyFor( liveId );

		await openEditor( page, liveId );

		// A save from here is refused whatever it carries, so the label reads
		// "Save" -- not "Stage changes", which is exactly the thing a click
		// cannot do.
		await expect( publishButton( page ) ).toHaveText( 'Save' );

		await expect(
			canvasOf( page ).locator( 'p[data-type="core/paragraph"]' ).first()
		).toHaveClass( /is-editing-disabled/ );

		// The warning is on screen before the first keystroke, and it is what
		// carries the way to the copy those three fields actually live on --
		// there is no second, per-attempt notice to carry it once the button
		// is disabled instead of refused.
		const warning = page.locator( '.components-notice' ).filter( {
			hasText: 'staged changes waiting to be published',
		} );

		await expect(
			warning.getByRole( 'link', { name: 'Edit staged changes' } )
		).toBeVisible();

		const seen = [];
		page.on( 'request', ( request ) => {
			seen.push(
				`${ request.method() } ${ decodeURIComponent( request.url() ) }`
			);
		} );

		// The title is read-only here: typing into it changes nothing, and
		// the editor never counts the post as dirty for it.
		const title = titleField( page );

		await expect( title ).toHaveAttribute( 'contenteditable', 'false' );
		await expect( title ).toHaveAttribute( 'aria-readonly', 'true' );

		await title.click( { force: true } );
		await page.keyboard.type( ', autumn' );

		await expect( title ).not.toContainText( 'autumn' );
		expect(
			await page.evaluate( () =>
				window.wp.data.select( 'core/editor' ).isEditedPostDirty()
			)
		).toBe( false );

		// The lock behind the read-only field: a title edit that reaches the
		// store anyway -- a plugin, a restored autosave -- still disables
		// saving.
		await dirtyTitle( page, 'Meridian Active, autumn' );
		await expect( publishButton( page ) ).toBeDisabled();

		// The keyboard shortcut checks the same lock and does nothing either.
		await page.keyboard.press( 'ControlOrMeta+s' );

		// Long enough for a request that was going to happen to have happened;
		// the assertions below are about its absence.
		await page.waitForTimeout( 1000 );

		// Nothing failed, because nothing was attempted: core's own generic
		// failure notice never appears.
		await expect(
			page
				.locator( '.components-notice__content' )
				.filter( { hasText: 'Updating failed' } )
		).toHaveCount( 0 );

		expect(
			seen.filter( ( request ) =>
				/^POST .*\/swpub\/v1\/stage\/\d+/.test( request )
			)
		).toHaveLength( 0 );
		expect(
			seen.filter( ( request ) =>
				/^(?:PUT|POST) .*\/wp\/v2\/posts\/\d+(?![\d/])/.test( request )
			)
		).toHaveLength( 0 );

		expect( getPostField( liveId, 'post_title' ) ).not.toContain(
			'autumn'
		);
		expect( getPostField( stagedCopyId, 'post_title' ) ).not.toContain(
			'autumn'
		);
		expect( stagedCopyIdFor( liveId ) ).toBe( stagedCopyId );
	} );

	test( 'autosaving is locked while a staging request is in flight', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );

		const paragraph = canvasOf( page )
			.locator( 'p[data-type="core/paragraph"]' )
			.first();
		await paragraph.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( STAGED_TEXT );

		const autosaves = [];
		page.on( 'request', ( request ) => {
			autosaves.push( decodeURIComponent( request.url() ) );
		} );

		// Hold the staging request open so the in-flight window is something a
		// test can stand inside. An autosave landing here would write the same
		// words to the published post through an endpoint staging does not
		// contain, and race the staging write for them.
		let release;
		const held = new Promise( ( resolve ) => {
			release = resolve;
		} );

		await page.route(
			( url ) =>
				decodeURIComponent( url.toString() ).includes(
					'/swpub/v1/stage/'
				),
			async ( route ) => {
				await held;
				await route.continue();
			}
		);

		await showDocumentPanel( page );
		await page.getByRole( 'button', { name: 'Stage changes' } ).click();

		await expect
			.poll( () =>
				page.evaluate( () =>
					window.wp.data
						.select( 'core/editor' )
						.isPostAutosavingLocked()
				)
			)
			.toBe( true );

		// Nothing was autosaved to the published post while the request was
		// held open, which is what the lock is for.
		expect(
			autosaves.filter( ( request ) =>
				request.includes( `/wp/v2/posts/${ liveId }/autosaves` )
			)
		).toHaveLength( 0 );

		release();

		await expect
			.poll( () => stagedCopyIdFor( liveId ), { timeout: 20_000 } )
			.toBeGreaterThan( 0 );

		await page.waitForURL(
			new RegExp( `post=${ stagedCopyIdFor( liveId ) }` ),
			{ timeout: 20_000 }
		);
	} );

	test( 'staging from the posts list lands on a copy of the right post', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/edit.php' );

		const row = page.locator( `#post-${ liveId }` );
		await row.hover();
		await row.getByRole( 'link', { name: 'Stage changes' } ).click();

		const stagedCopyId = stagedCopyIdFor( liveId );
		expect( stagedCopyId ).toBeGreaterThan( 0 );
		await expect( page ).toHaveURL(
			new RegExp( `post=${ stagedCopyId }` )
		);

		// Nothing was typed, so the copy reads as the published post does.
		expect( getPostField( stagedCopyId, 'post_content' ) ).toContain(
			'launches in June'
		);
		expect( getPostField( liveId, 'post_content' ) ).toContain(
			'launches in June'
		);

		// One revision -- the baseline this staging wrote, and nothing staged
		// on top of it yet -- is not a change, so there is nothing to review.
		// The word is stated on its own rather than offered as a link to a
		// screen that would have nothing on the other side of the comparison.
		await showDocumentPanel( page );
		await expect( page.locator( '.swpub-status-row__value' ) ).toHaveText(
			'Staged'
		);
		await expect( reviewControl( page ) ).toHaveCount( 0 );

		// A second end, and the link appears. Clicking into the block to
		// type switched the sidebar to its Block tab, so the Post tab that
		// carries the Status row has to be brought back.
		await appendAndSave( page, STAGED_TEXT );
		await showDocumentPanel( page );
		await expect( reviewControl( page ) ).toBeVisible( {
			timeout: 20_000,
		} );
	} );

	test( 'once a copy exists, the row offers the copy and not a second staging', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/edit.php' );
		const row = page.locator( `#post-${ liveId }` );
		await row.hover();
		await row.getByRole( 'link', { name: 'Stage changes' } ).click();
		await page.waitForURL( /post\.php\?post=\d+/ );

		await page.goto( '/wp-admin/edit.php' );
		const again = page.locator( `#post-${ liveId }` );
		await again.hover();

		await expect(
			again.getByRole( 'link', { name: 'Edit staged changes' } )
		).toBeVisible();
		await expect(
			again.getByRole( 'link', { name: 'Stage changes' } )
		).toHaveCount( 0 );
	} );
} );
