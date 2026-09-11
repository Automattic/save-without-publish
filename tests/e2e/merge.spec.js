/**
 * Publishing a staged change, and refusing to when the post has moved.
 *
 * The merge is the only thing here that writes to a published post or deletes
 * anything, so these tests assert what must be *unchanged* as much as what
 * changed. A merge that carried the staged copy's status or slug across would
 * unpublish the article and break every inbound link, and would look like a
 * success from the editor.
 */

const { test, expect } = require( '@playwright/test' );
const {
	createPublishedPost,
	getPostField,
	postExists,
	createStagedCopyFor,
	forceModifiedGmt,
	forcePublishedContent,
	stagedCopyIdFor,
	cleanUp,
	openEditor,
	appendAndSave,
	publishButton,
	publishStagedChanges,
	reviewControl,
	reviewSurface,
	publishedEditControl,
	canvasOf,
	revisionsOf,
	wp,
} = require( './helpers' );

const PUBLISHED_TEXT = 'The Summer collection launches in June.';
const STAGED_TEXT = 'Now launching October 3.';

/**
 * Stages a copy holding different content from the published post.
 *
 * @param {number} liveId Published post ID.
 * @return {number} The staged copy's ID.
 */
function stageWithContent( liveId ) {
	const stagedCopyId = createStagedCopyFor( liveId );

	wp( [
		'post',
		'update',
		String( stagedCopyId ),
		`--post_content=<!-- wp:paragraph --><p>${ STAGED_TEXT }</p><!-- /wp:paragraph -->`,
	] );

	return stagedCopyId;
}

test.describe( 'Publishing a staged change', () => {
	let liveId;
	let stagedCopyId;
	let originalSlug;

	test.beforeEach( () => {
		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			PUBLISHED_TEXT
		);
		stagedCopyId = stageWithContent( liveId );
		originalSlug = getPostField( liveId, 'post_name' );
	} );

	test.afterEach( () => {
		cleanUp( liveId );
	} );

	test( 'publishing applies the change and keeps everything else intact', async ( {
		page,
	} ) => {
		const publishDate = getPostField( liveId, 'post_date_gmt' );

		await openEditor( page, stagedCopyId );

		await publishStagedChanges( page );

		// The editor lands back on the published post, since the staged copy
		// no longer exists.
		await page.waitForURL( new RegExp( `post=${ liveId }` ), {
			timeout: 20_000,
		} );

		// Said afterwards, where the merge landed, rather than asked for
		// beforehand as a dialog naming the same facts every time.
		//
		// Read first, and in one pass: a snackbar dismisses itself after ten
		// seconds, and the WP-CLI checks below each shell out through wp-env,
		// which on a shared runner adds up to longer than that.
		const snackbar = page
			.locator( '.components-snackbar' )
			.filter( { hasText: 'Your staged changes are published' } );

		await expect( snackbar ).toBeVisible( { timeout: 20_000 } );

		// And it offers what core offers after any save: the post type's own
		// view label, the permalink, and a new tab -- opened by hand
		// (`toRead()`) rather than through the snackbar's own
		// `openInNewTab`, which core's snackbar silently ignores below
		// WordPress 7.0 (VIPPROD-753, F6), so this is asserted by the tab
		// it actually opens rather than by a `target` attribute the
		// component may or may not have set.
		const view = snackbar.getByRole( 'link' ).first();
		const href = await view.getAttribute( 'href' );

		expect( href ).not.toContain( 'wp-admin' );

		const opened = page.waitForEvent( 'popup' );
		await view.click();
		const tab = await opened;
		await tab.waitForLoadState();

		expect( tab.url() ).toBe( href );
		await tab.close();

		expect( getPostField( liveId, 'post_content' ) ).toContain(
			STAGED_TEXT
		);
		expect( getPostField( liveId, 'post_status' ) ).toBe( 'publish' );
		expect( getPostField( liveId, 'post_name' ) ).toBe( originalSlug );
		expect( getPostField( liveId, 'post_date_gmt' ) ).toBe( publishDate );

		expect( postExists( stagedCopyId ) ).toBe( false );
		expect( stagedCopyIdFor( liveId ) ).toBe( 0 );
	} );

	test( 'the pre-merge content is restorable from core revisions', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await publishStagedChanges( page );
		await page.waitForURL( new RegExp( `post=${ liveId }` ), {
			timeout: 20_000,
		} );

		// Core's own revision screen, unmodified, is the review and undo
		// surface. If the published content is not in there, a merge is not
		// undoable and the product's central promise is broken.
		const revisions = revisionsOf( liveId );

		expect( revisions.length ).toBeGreaterThan( 1 );

		const contents = revisions.map( ( id ) =>
			getPostField( id, 'post_content' )
		);

		expect(
			contents.some( ( text ) => text.includes( PUBLISHED_TEXT ) )
		).toBe( true );
		expect(
			contents.some( ( text ) => text.includes( STAGED_TEXT ) )
		).toBe( true );

		// The newest revision, in the order core presents them, must match the
		// live content. If it did not, an editor restoring "the latest" from
		// the revision screen would silently revert the merge.
		const newest = revisions[ revisions.length - 1 ];
		expect( getPostField( newest, 'post_content' ) ).toBe(
			getPostField( liveId, 'post_content' )
		);
		expect( getPostField( newest, 'post_content' ) ).toContain(
			STAGED_TEXT
		);
	} );

	test( 'the staged copy carries its routes in the Summary', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );

		// Standing facts about the document sit in the Summary panel beside
		// slug and author, not in the notice: a notice announces a state once
		// and is scrolled past.
		await expect( reviewControl( page ) ).toBeVisible();
		await expect( publishedEditControl( page ) ).toBeVisible();

		// And it leads to the published post's own editor. The front end is
		// named in the notice, inside the sentence that says what readers see.
		expect(
			await publishedEditControl( page ).getAttribute( 'href' )
		).toMatch( new RegExp( `post=${ liveId }.*action=edit` ) );

		// Reviewing rides in the Status row, which reads "Staged" here. A row of
		// our own beside it would repeat the word and read as a second fact, so
		// there is exactly one of these on the screen.
		await expect( reviewControl( page ) ).toHaveCount( 1 );
		await expect(
			page
				.locator( '.swpub-status-row' )
				.getByRole( 'link', { name: 'Review staged changes' } )
		).toBeVisible();

		// Reading must not cost the staged edits sitting in the editor, so the
		// review control leaves this tab where it is.
		const surface = await reviewSurface( page );
		const opened = page.waitForEvent( 'popup' );
		await reviewControl( page ).click();

		const reviewTab = await opened;
		expect( reviewTab.url() ).toMatch(
			'classic' === surface
				? /revision\.php\?from=\d+&to=\d+/
				: /post\.php\?.*revision=\d+/
		);
		await reviewTab.close();

		await expect( page ).toHaveURL(
			new RegExp( `post=${ stagedCopyId }` )
		);
	} );

	test( 'the header controls name the staged action, not a draft', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );

		// Core's own words here are "Publish" and "Save draft", and both are
		// wrong on a staged copy: it publishes a change, and it is not a draft
		// of an unpublished post.
		await expect( publishButton( page ) ).toHaveText( 'Publish changes' );

		// A staged copy cannot be trashed: anything reaching the trash is
		// unlinked and force-deleted, so the control is named for what it does
		// and leads to a confirmation that says the change cannot be restored.
		// Asserted before typing, because clicking into a block moves the
		// sidebar to its Block tab and takes the Summary with it.
		await expect(
			page.getByRole( 'link', { name: 'Discard staged changes' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Move to trash' } )
		).toHaveCount( 0 );

		const paragraph = canvasOf( page )
			.locator( 'p[data-type="core/paragraph"]' )
			.first();
		await paragraph.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' Unsaved words.' );

		await expect(
			page.getByRole( 'button', { name: 'Save changes' } )
		).toBeVisible();
	} );

	test( 'publishing saves what is still in the editor first', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await appendAndSave( page, '' );

		// Type without saving.
		const paragraph = canvasOf( page )
			.locator( 'p[data-type="core/paragraph"]' )
			.first();
		await paragraph.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' Unsaved words.' );

		await publishButton( page ).click();

		await page.waitForURL( new RegExp( `post=${ liveId }` ), {
			timeout: 30_000,
		} );

		// A merge reads the staged copy from the database, so the words on
		// screen have to reach it first. They are saved rather than asked for:
		// the click already said to publish what the editor is showing.
		expect( getPostField( liveId, 'post_content' ) ).toContain(
			'Unsaved words.'
		);
		expect( postExists( stagedCopyId ) ).toBe( false );
	} );
} );

test.describe( 'Publishing when the post has changed underneath', () => {
	let liveId;
	let stagedCopyId;

	test.beforeEach( () => {
		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			PUBLISHED_TEXT
		);
		stagedCopyId = stageWithContent( liveId );

		/*
		 * Somebody else edits the published post after staging began. Put into
		 * the row rather than made through the API: the write guard now contains
		 * every write to a post that has a staged copy, on every transport, so an
		 * ordinary update here would land in the copy and overwrite the staged
		 * words this file is about. Drift is the state, and this is what reaching
		 * it looks like once containment holds everywhere.
		 */
		forcePublishedContent(
			liveId,
			`<!-- wp:paragraph --><p>${ PUBLISHED_TEXT } Typo fixed.</p><!-- /wp:paragraph -->`
		);
		forceModifiedGmt( liveId, '2026-08-14 09:00:00' );
	} );

	test.afterEach( () => {
		cleanUp( liveId );
	} );

	test( 'the change is refused and the editor is offered the difference', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );

		await publishStagedChanges( page );

		const dialog = page.getByRole( 'dialog' );

		await expect(
			dialog.locator( '.components-notice__content' ).filter( {
				hasText:
					'The published post changed while these edits were staged',
			} )
		).toBeVisible();
		await expect(
			dialog.getByRole( 'link', { name: 'Review the change' } )
		).toBeVisible();

		// Nothing was written.
		expect( getPostField( liveId, 'post_content' ) ).toContain(
			'Typo fixed.'
		);
		expect( getPostField( liveId, 'post_content' ) ).not.toContain(
			STAGED_TEXT
		);
		expect( postExists( stagedCopyId ) ).toBe( true );
	} );

	test( 'confirming overwrites the change and completes the merge', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );

		await publishStagedChanges( page );
		await page
			.getByRole( 'button', { name: 'Overwrite and publish' } )
			.click();

		await page.waitForURL( new RegExp( `post=${ liveId }` ), {
			timeout: 20_000,
		} );

		expect( getPostField( liveId, 'post_content' ) ).toContain(
			STAGED_TEXT
		);
		expect( getPostField( liveId, 'post_content' ) ).not.toContain(
			'Typo fixed.'
		);
		expect( postExists( stagedCopyId ) ).toBe( false );
	} );

	test( 'the review link opens in a new tab so staged work survives', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await publishStagedChanges( page );

		const link = page
			.getByRole( 'dialog' )
			.getByRole( 'link', { name: 'Review the change' } );

		// Navigating in place would discard unsaved staged edits and lose the
		// timestamp the confirmation has to name.
		await expect( link ).toHaveAttribute( 'target', '_blank' );

		const href = await link.getAttribute( 'href' );

		if ( 'classic' === ( await reviewSurface( page ) ) ) {
			expect( href ).toContain( 'revision.php' );
		} else {
			expect( href ).toMatch(
				new RegExp( `post=${ liveId }&action=edit` )
			);
		}
		expect( href ).toMatch( /revision=\d+/ );
		expect( href ).not.toMatch( /revision=0?$/ );
	} );
} );
