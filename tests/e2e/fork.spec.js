/**
 * The fork: saving a published post stages the change instead of publishing it.
 *
 * This is the product's whole promise, and it is the one thing that cannot be
 * proven without a browser. The editor has to notice a save went to a different
 * post than it asked for, and reconcile without losing the edit or wedging on
 * an unsaved-changes prompt. PHPUnit sees the correct database rows either way.
 */

const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const {
	createPublishedPost,
	getPostField,
	stagedCopyIdFor,
	cleanUp,
	openEditor,
	appendAndSave,
	canvasOf,
	reviewControl,
	showDocumentPanel,
	backstopEvents,
	clearBackstopEvents,
	createCategory,
	chooseCategory,
	publishButton,
} = require( './helpers' );

const PUBLISHED_TEXT = 'The Summer collection launches in June.';
const STAGED_TEXT = ' It is going to be good.';
const CATEGORY = 'Collections';

/**
 * Every REST call the browser made, as "METHOD /path", decoded.
 *
 * The site runs on plain permalinks, so REST calls are query strings rather than
 * paths -- `index.php?rest_route=%2Fwp%2Fv2%2Fposts%2F12`. Decoding first is what
 * lets one matcher read both shapes.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {string[]} A list that fills as the page makes requests.
 */
function recordRequests( page ) {
	const seen = [];

	page.on( 'request', ( request ) => {
		seen.push(
			`${ request.method() } ${ decodeURIComponent( request.url() ) }`
		);
	} );

	return seen;
}

/**
 * Saves that went to the post endpoint itself, autosaves excluded.
 *
 * The lookahead is what excludes `/wp/v2/posts/12/autosaves`: an autosave is
 * core's own draft of unsaved work and never publishes, so it is not the write
 * this asserts the absence of.
 *
 * @param {string[]} seen Recorded requests.
 * @return {string[]} The matching requests.
 */
function postEndpointWrites( seen ) {
	return seen.filter( ( request ) =>
		/^(?:PUT|POST) .*\/wp\/v2\/posts\/\d+(?![\d/])/.test( request )
	);
}

/**
 * Calls to the staging route.
 *
 * @param {string[]} seen Recorded requests.
 * @return {string[]} The matching requests.
 */
function stageRouteCalls( seen ) {
	return seen.filter( ( request ) =>
		/^POST .*\/swpub\/v1\/stage\/\d+/.test( request )
	);
}

/**
 * Whether the editor still believes it is holding unsaved work.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Promise<boolean>} True when the post is dirty.
 */
function isDirty( page ) {
	return page.evaluate( () =>
		window.wp.data.select( 'core/editor' ).isEditedPostDirty()
	);
}

/*
 * The whole file runs as the revisor: an editor who cannot publish, and so the
 * person the redirected save exists for. An administrator's save publishes
 * (stage.spec.js proves that side), so running these as admin would make every
 * assertion below pass for the wrong reason or fail for no interesting one.
 */
test.use( {
	storageState: path.join(
		process.cwd(),
		'artifacts/storage-states/revisor.json'
	),
} );

test.describe( 'Forking a published post', () => {
	let liveId;

	test.beforeEach( () => {
		clearBackstopEvents();
		createCategory( CATEGORY );
		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			PUBLISHED_TEXT
		);
	} );

	/*
	 * The one assertion in this file that is about silence. Every flow below is
	 * one a working editor takes, and the editor's own protocol is the staging
	 * route -- so the fork underneath it must never have been what carried the
	 * save. Hearing the alarm here means the protocol regressed and the backstop
	 * quietly covered for it, which is exactly the failure that would otherwise
	 * ship green.
	 */
	test.afterEach( () => {
		const heard = backstopEvents();

		cleanUp( liveId );

		expect( heard, 'The save was carried by the backstop.' ).toHaveLength(
			0
		);
	} );

	test( 'the first save stages the change and leaves the published post alone', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );
		await appendAndSave( page, STAGED_TEXT );

		// The editor is moved to the staged copy, which is a different post.
		await page.waitForURL( /post=(?!(?:$|\D))/, { timeout: 20_000 } );
		await expect( page ).not.toHaveURL( new RegExp( `post=${ liveId }&` ) );

		const stagedCopyId = stagedCopyIdFor( liveId );
		expect( stagedCopyId ).toBeGreaterThan( 0 );
		await expect( page ).toHaveURL(
			new RegExp( `post=${ stagedCopyId }` )
		);

		// The published post did not receive the edit.
		expect( getPostField( liveId, 'post_content' ) ).toContain(
			PUBLISHED_TEXT
		);
		expect( getPostField( liveId, 'post_content' ) ).not.toContain(
			STAGED_TEXT.trim()
		);
		expect( getPostField( liveId, 'post_status' ) ).toBe( 'publish' );

		// The staged copy did.
		expect( getPostField( stagedCopyId, 'post_content' ) ).toContain(
			STAGED_TEXT.trim()
		);
		expect( getPostField( stagedCopyId, 'post_status' ) ).toBe(
			'swpub_staged'
		);
	} );

	test( 'the editor explains the move rather than silently relocating', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );
		await appendAndSave( page, STAGED_TEXT );

		// Scoped to the notice itself. The same text also lands in the
		// accessibility live region, which is correct but is not what this
		// asserts.
		const staged = page
			.locator( '.components-notice' )
			.filter( { hasText: 'Your edit was staged' } );

		await expect(
			staged.locator( '.components-notice__content' )
		).toBeVisible( {
			timeout: 20_000,
		} );

		// Reviewing is the Summary panel's own row, not a second copy of the
		// same route inside the notice.
		await showDocumentPanel( page );
		const review = reviewControl( page );

		await expect( review ).toBeVisible();

		// The editor's revisions view on the staged copy, not the classic
		// compare screen: review.spec.js is where the revision it names is
		// pinned to the newest staged save.
		expect( await review.getAttribute( 'href' ) ).toMatch(
			/post\.php\?post=\d+&action=edit&revision=\d+/
		);
	} );

	test( 'the staged edit survives the move to the staged copy', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );
		await appendAndSave( page, STAGED_TEXT );

		const stagedCopyId = stagedCopyIdFor( liveId );
		await page.waitForURL( new RegExp( `post=${ stagedCopyId }` ), {
			timeout: 20_000,
		} );

		// The point of the redirect is that the editor arrives holding the edit
		// it just made, not the pre-edit content.
		await expect(
			canvasOf( page ).locator( 'p[data-type="core/paragraph"]' ).first()
		).toContainText( STAGED_TEXT.trim() );
	} );

	test( 'the editor does not wedge on an unsaved-changes prompt', async ( {
		page,
	} ) => {
		let dialogAppeared = false;
		page.on( 'dialog', async ( dialog ) => {
			dialogAppeared = true;
			await dialog.dismiss();
		} );

		await openEditor( page, liveId );
		await appendAndSave( page, STAGED_TEXT );

		const stagedCopyId = stagedCopyIdFor( liveId );
		await page.waitForURL( new RegExp( `post=${ stagedCopyId }` ), {
			timeout: 20_000,
		} );

		// Navigating away must not be blocked by a leftover dirty state: an
		// earlier build left the post dirty after a successful save, which
		// armed beforeunload and trapped the editor.
		await page.goto( '/wp-admin/edit.php' );
		await expect( page.locator( '.wp-list-table' ) ).toBeVisible();

		expect( dialogAppeared ).toBe( false );
	} );

	test( 'the save is sent to the staging route, not to the published post', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );

		const seen = recordRequests( page );

		await appendAndSave( page, STAGED_TEXT );

		const stagedCopyId = stagedCopyIdFor( liveId );
		await page.waitForURL( new RegExp( `post=${ stagedCopyId }` ), {
			timeout: 20_000,
		} );

		// The protocol, stated as the network sees it: one deliberate staging
		// call, answered 200, and nothing sent to the published post at all.
		expect( stageRouteCalls( seen ) ).toHaveLength( 1 );
		expect( postEndpointWrites( seen ) ).toHaveLength( 0 );
	} );

	test( 'a category change on its own saves to the published post', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );

		const seen = recordRequests( page );

		await chooseCategory( page, CATEGORY );

		// A term is not a staged field, so this save is an ordinary one and
		// belongs on the published post. Staging it would strand the change:
		// a staged copy cannot hold a category, and nothing would carry it back.
		const saved = page.waitForResponse(
			( response ) =>
				/^(?:PUT|POST)$/.test( response.request().method() ) &&
				/\/wp\/v2\/posts\/\d+(?![\d/])/.test(
					decodeURIComponent( response.url() )
				)
		);
		await page.keyboard.press( 'ControlOrMeta+s' );
		await saved;

		await expect
			.poll( () => getPostField( liveId, 'post_status' ) )
			.toBe( 'publish' );

		expect( stageRouteCalls( seen ) ).toHaveLength( 0 );
		expect( postEndpointWrites( seen ) ).toHaveLength( 1 );
		expect( stagedCopyIdFor( liveId ) ).toBe( 0 );
		await expect( page ).toHaveURL( new RegExp( `post=${ liveId }` ) );
	} );

	test( 'a category change from the primary button leaves the post published', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );
		await chooseCategory( page, CATEGORY );

		// A category is not one of the fields a staged copy can hold, so the
		// label already says what this save actually does (VIPPROD-1171):
		// "Save", not "Stage changes".
		await expect( publishButton( page ) ).toHaveText( 'Save' );

		/*
		 * The primary button, not the keyboard shortcut, because they do not
		 * send the same thing. Core's button dispatches editPost( { status } )
		 * before it saves, and for someone who cannot publish this post that
		 * status is `pending` -- so this save carried an unpublish nobody asked
		 * for, on the most ordinary edit there is.
		 */
		const button = await publishButton( page );

		await button.click();

		await expect
			.poll( () => getPostField( liveId, 'post_status' ) )
			.toBe( 'publish' );

		expect( stagedCopyIdFor( liveId ) ).toBe( 0 );
	} );

	test( 'a save mixing text with a category is refused before it is sent', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );

		const seen = recordRequests( page );

		const paragraph = canvasOf( page )
			.locator( 'p[data-type="core/paragraph"]' )
			.first();
		await paragraph.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( STAGED_TEXT );

		await chooseCategory( page, CATEGORY );

		// Content is dirty alongside the category, so the label still reads
		// "Stage changes" -- it does not read the mix as safely unstageable
		// just because one of the two fields is.
		await expect( publishButton( page ) ).toHaveText( 'Stage changes' );

		await page.keyboard.press( 'ControlOrMeta+s' );

		// The refusal names the field and says what to do about it. It has to
		// be a different sentence from the server's, which tells the reader to
		// change the field on the published post -- where this reader already is.
		const refusal = page
			.locator( '.components-notice__content' )
			.filter( { hasText: 'Categories cannot be staged' } );

		await expect( refusal ).toBeVisible();
		await expect( refusal ).toContainText( 'Undo that change to save' );

		// Nothing was sent, on either route.
		expect( stageRouteCalls( seen ) ).toHaveLength( 0 );
		expect( postEndpointWrites( seen ) ).toHaveLength( 0 );
		expect( stagedCopyIdFor( liveId ) ).toBe( 0 );

		// And the words are still here, still counted as unsaved, so nothing
		// was lost by refusing.
		expect( await isDirty( page ) ).toBe( true );
		await expect( paragraph ).toContainText( STAGED_TEXT.trim() );
	} );

	test( 'a second edit continues on the staged copy, not a second copy', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );
		await appendAndSave( page, STAGED_TEXT );

		const stagedCopyId = stagedCopyIdFor( liveId );
		await page.waitForURL( new RegExp( `post=${ stagedCopyId }` ), {
			timeout: 20_000,
		} );

		// Back on the published post, the copy owns the content now: the canvas
		// is read-only, and the notice says so before the first keystroke
		// rather than after a save that would have replaced the staged words.
		await openEditor( page, liveId );

		const locked = page
			.locator( '.components-notice' )
			.filter( { hasText: 'locked here until those changes' } );

		await expect(
			locked.locator( '.components-notice__content' )
		).toBeVisible();
		await expect(
			canvasOf( page ).locator( 'p[data-type="core/paragraph"]' ).first()
		).toHaveClass( /is-editing-disabled/ );

		// The notice carries the way over to the copy, where the second pass
		// is made.
		await locked
			.getByRole( 'link', { name: 'Edit staged changes' } )
			.click();
		await page.waitForURL( new RegExp( `post=${ stagedCopyId }` ), {
			timeout: 20_000,
		} );
		await canvasOf( page )
			.locator( 'p[data-type="core/paragraph"]' )
			.first()
			.waitFor();

		await appendAndSave( page, ' Second pass.' );

		await expect
			.poll( () => getPostField( stagedCopyId, 'post_content' ), {
				timeout: 20_000,
			} )
			.toContain( 'Second pass.' );

		// Still one staged copy, not a second one, and the published post saw
		// neither pass.
		expect( stagedCopyIdFor( liveId ) ).toBe( stagedCopyId );
		expect( getPostField( liveId, 'post_content' ) ).not.toContain(
			STAGED_TEXT.trim()
		);
		expect( getPostField( liveId, 'post_content' ) ).not.toContain(
			'Second pass.'
		);
	} );
} );

/*
 * The floor beneath the protocol. Neither flow here is one a working editor
 * takes, so both expect the alarm rather than its silence.
 */
test.describe( 'When the editor protocol does not run', () => {
	let liveId;

	test.beforeEach( () => {
		clearBackstopEvents();
		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			PUBLISHED_TEXT
		);
	} );

	test.afterEach( () => {
		cleanUp( liveId );
	} );

	test( 'the save still stages, and arrives saying so', async ( {
		page,
	} ) => {
		/*
		 * Test-only, and reachable only from here: nothing on the server can
		 * set this, and the bundle reads it without ever writing it. Disabling
		 * the swap costs no guarantee -- the save falls through to the fork,
		 * which is the point of the drill.
		 */
		await page.addInitScript( () => {
			window.swpubTestDisableSaveSwap = true;
		} );

		await openEditor( page, liveId );

		const seen = recordRequests( page );

		await appendAndSave( page, STAGED_TEXT );

		const stagedCopyId = stagedCopyIdFor( liveId );
		await page.waitForURL( new RegExp( `post=${ stagedCopyId }` ), {
			timeout: 20_000,
		} );

		// The save went to the post endpoint and was redirected there.
		expect( stageRouteCalls( seen ) ).toHaveLength( 0 );
		expect( postEndpointWrites( seen ) ).not.toHaveLength( 0 );

		// The server wrote the change, so the arrival says so. An editor whose
		// save worked must never be told it failed.
		await expect(
			page
				.locator( '.components-snackbar__content' )
				.filter( { hasText: 'Your change was staged' } )
		).toBeVisible();

		expect( getPostField( liveId, 'post_content' ) ).not.toContain(
			STAGED_TEXT.trim()
		);
		expect( getPostField( stagedCopyId, 'post_content' ) ).toContain(
			STAGED_TEXT.trim()
		);

		const heard = backstopEvents();
		expect( heard ).toHaveLength( 1 );
		expect( heard[ 0 ].live_id ).toBe( liveId );
		expect( heard[ 0 ].staged_copy_id ).toBe( stagedCopyId );
	} );

	test( 'covers AE26: with the bundle gone, the click still stages', async ( {
		page,
	} ) => {
		// Not a hook of ours: the bundle is simply never delivered, which is the
		// failure AE26 describes. Everything below is what the server does on
		// its own.
		await page.route(
			'**/save-without-publish/build/index.js*',
			( route ) => route.abort()
		);

		await openEditor( page, liveId );
		await appendAndSave( page, STAGED_TEXT );

		await expect
			.poll( () => stagedCopyIdFor( liveId ), { timeout: 20_000 } )
			.toBeGreaterThan( 0 );

		const stagedCopyId = stagedCopyIdFor( liveId );

		// The published post is untouched and still published, whatever the
		// button said.
		expect( getPostField( liveId, 'post_content' ) ).toContain(
			PUBLISHED_TEXT
		);
		expect( getPostField( liveId, 'post_content' ) ).not.toContain(
			STAGED_TEXT.trim()
		);
		expect( getPostField( liveId, 'post_status' ) ).toBe( 'publish' );
		expect( getPostField( stagedCopyId, 'post_content' ) ).toContain(
			STAGED_TEXT.trim()
		);

		expect( backstopEvents() ).toHaveLength( 1 );
	} );
} );
