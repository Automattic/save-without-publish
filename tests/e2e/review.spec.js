/**
 * The route from a staged copy to the review surface.
 *
 * The whole review story is core's own revisions view, so the only thing this
 * plugin controls is which post it opens and which revision it opens on.
 * Getting that wrong is invisible in the database and looks like a working
 * link: the screen opens, it just shows the wrong change.
 */

const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const {
	backstopEvents,
	chooseCategory,
	clearBackstopEvents,
	createCategory,
	createPublishedPost,
	createStagedCopyFor,
	dirtyTitle,
	getPostField,
	postExists,
	stagedCopyIdFor,
	cleanUp,
	openEditor,
	appendAndSave,
	canvasOf,
	publishButton,
	revisionsOf,
	reviewControl,
	reviewSurface,
	showDocumentPanel,
	titleField,
	wp,
} = require( './helpers' );

const PUBLISHED_TEXT = 'The Summer collection launches in June.';
const CATEGORY = 'Collections';

/**
 * Stages typed-but-unsaved text through the editor's own entry point.
 *
 * The suite runs as an administrator, whose plain save publishes, so staging
 * is the deliberate act: type, then Stage changes. This is also what seeds
 * the baseline revision the review link starts from.
 *
 * @param {Object} page   Playwright page.
 * @param {string} text   Text to append before staging.
 * @param {number} liveId The published post being staged, so the wait can
 *                        tell the arrival apart from the URL it started on.
 */
async function stageThroughEditor( page, text, liveId ) {
	const paragraph = canvasOf( page )
		.locator( 'p[data-type="core/paragraph"]' )
		.first();
	await paragraph.click();
	await page.keyboard.press( 'End' );
	await page.keyboard.type( text );

	await showDocumentPanel( page );
	await page.getByRole( 'button', { name: 'Stage changes' } ).click();
	await page.waitForURL(
		( url ) => url.searchParams.get( 'post' ) !== String( liveId ),
		{ timeout: 20_000 }
	);
}

test.describe( 'Reviewing a staged change', () => {
	let liveId;

	test.beforeEach( () => {
		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			PUBLISHED_TEXT
		);
	} );

	test.afterEach( () => {
		cleanUp( liveId );
	} );

	test( 'the review link opens the right revisions view on the newest staged save', async ( {
		page,
	} ) => {
		// Staged through the editor rather than arranged directly, because the
		// entry point is what writes the baseline revision this link starts
		// from.
		await openEditor( page, liveId );
		await stageThroughEditor( page, ' It is going to be good.', liveId );

		const stagedCopyId = stagedCopyIdFor( liveId );

		// A third revision, so "newest" and "second newest" are different
		// answers and the link cannot pass by accident.
		wp( [
			'post',
			'update',
			String( stagedCopyId ),
			'--post_content=<!-- wp:paragraph --><p>A second staged pass.</p><!-- /wp:paragraph -->',
		] );

		await openEditor( page, stagedCopyId );

		const revisions = revisionsOf( stagedCopyId );
		expect( revisions.length ).toBeGreaterThan( 2 );

		const href = await reviewControl( page ).getAttribute( 'href' );
		const newest = revisions[ revisions.length - 1 ];
		const baseline = revisions[ 0 ];

		if ( 'classic' === ( await reviewSurface( page ) ) ) {
			// The classic surface names both ends by id; the baseline is the
			// `from`, and the newest staged save is the `to`.
			expect( href ).toContain( `from=${ baseline }` );
			expect( href ).toContain( `to=${ newest }` );
		} else {
			// The staged copy's own editor, on its newest save. That screen diffs
			// a revision against the one before it, so the newest is the one that
			// shows the most recent staged change, and its timeline is the way
			// back through the rest.
			expect( href ).toContain( `post=${ stagedCopyId }` );
			expect( href ).toContain( 'action=edit' );
			expect( href ).toContain( `revision=${ newest }` );

			// The baseline is where the classic screen used to start. Opening
			// there now would show the fork itself rather than anything anyone
			// staged.
			expect( href ).not.toContain( `revision=${ baseline }` );
		}
	} );

	test( 'the published post announces the staged copy before a keystroke', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );
		await stageThroughEditor( page, ' It is going to be good.', liveId );

		const stagedCopyId = stagedCopyIdFor( liveId );

		// Back to the published post, the way a second editor arrives at it.
		await openEditor( page, liveId );

		// A notice, not a sidebar panel: the panel this replaced sat collapsed
		// below "Move to trash", so the only warning that a second copy existed
		// was one an editor had to go looking for.
		const notice = page
			.locator( '.components-notice' )
			.filter( { hasText: 'This post has staged changes' } );

		await expect(
			notice.locator( '.components-notice__content' )
		).toBeVisible();
		await expect(
			notice.getByRole( 'link', { name: 'Edit staged changes' } )
		).toHaveAttribute( 'href', new RegExp( `post=${ stagedCopyId }` ) );
		// Reviewing is the Summary panel's row here too, so both copies offer
		// the same destination under the same name.
		await expect( reviewControl( page ) ).toBeVisible();
	} );

	test( 'both copies name the same review destination the same way', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );
		await stageThroughEditor( page, ' It is going to be good.', liveId );

		const stagedCopyId = stagedCopyIdFor( liveId );

		const fromStaged = await reviewControl( page ).getAttribute( 'href' );
		const surface = await reviewSurface( page );

		await openEditor( page, liveId );

		const fromPublished =
			await reviewControl( page ).getAttribute( 'href' );

		// One label, one destination. A reviewer sent by an editor standing on
		// the other copy must arrive at the same comparison.
		expect( fromPublished ).toBe( fromStaged );

		const revisions = revisionsOf( stagedCopyId );
		const newest = revisions[ revisions.length - 1 ];

		if ( 'classic' === surface ) {
			expect( fromStaged ).toContain( `from=${ revisions[ 0 ] }` );
			expect( fromStaged ).toContain( `to=${ newest }` );
		} else {
			expect( fromStaged ).toContain( `post=${ stagedCopyId }` );
			expect( fromStaged ).toContain( `revision=${ newest }` );
		}
	} );

	test( 'a title-only change is invisible on the in-editor view, so it offers Compare as text too', async ( {
		page,
	} ) => {
		const stagedCopyId = createStagedCopyFor( liveId );

		wp( [
			'post',
			'update',
			String( stagedCopyId ),
			'--post_title=Meridian Active, Autumn collection',
		] );

		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		// The in-editor review link still exists and is still offered; it
		// only cannot show this particular change (VIPPROD-753, F2).
		await expect( reviewControl( page ) ).toBeVisible();

		const notice = page
			.locator( '.components-notice' )
			.filter( { hasText: 'You are staging edits' } );
		// A button, not a link: core's own Notice component drops an
		// action's click handler whenever the action also carries a URL
		// (WordPress 6.8), so this reads without one (`toReadInNotice()`).
		const compareAsText = notice.getByRole( 'button', {
			name: 'Compare as text',
		} );

		await expect( compareAsText ).toBeVisible();

		const opened = page.waitForEvent( 'popup' );
		await compareAsText.click();
		const tab = await opened;

		await expect( tab.locator( '.revisions-diff' ) ).toBeVisible( {
			timeout: 20_000,
		} );
		await expect(
			tab.locator( '.diff-deletedline' ).filter( { hasText: 'Summer' } )
		).toHaveCount( 1 );
		await expect(
			tab.locator( '.diff-addedline' ).filter( { hasText: 'Autumn' } )
		).toHaveCount( 1 );

		// Restoring has nothing left to mean here either (D4), and the
		// screen still knows this is a staged copy's own history.
		await expect( tab.locator( '.restore-revision' ) ).toHaveCount( 0 );
		await expect( tab.locator( 'body.swpub-staged' ) ).toHaveCount( 1 );

		await tab.close();

		// The published post's own warning notice offers the same route,
		// alongside the way over to the copy it already offered.
		await openEditor( page, liveId );

		const existingNotice = page
			.locator( '.components-notice' )
			.filter( { hasText: 'locked here until those changes' } );

		await expect(
			existingNotice.getByRole( 'link', { name: 'Edit staged changes' } )
		).toBeVisible();
		await expect(
			existingNotice.getByRole( 'button', { name: 'Compare as text' } )
		).toBeVisible();
	} );
} );

/**
 * The Summary panel, once the surgeries became registered slots.
 *
 * Every row below used to be written into core's own markup by a
 * MutationObserver, and the trash button used to be a capture-phase click
 * intercept. Both are fills now (R53), and what these assert is the part a
 * unit test cannot see: that the fill renders where core puts fills, that the
 * control it supersedes is gone rather than sitting beside it, and that the
 * words are the ones the vocabulary table says they are.
 */
test.describe( 'The staged copy speaks through registered slots', () => {
	let liveId;
	let stagedCopyId;

	test.beforeEach( () => {
		clearBackstopEvents();
		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			PUBLISHED_TEXT
		);
		stagedCopyId = createStagedCopyFor( liveId );

		// A second pass, so there are two revisions to compare and the Status
		// row has somewhere to send a reviewer.
		wp( [
			'post',
			'update',
			String( stagedCopyId ),
			'--post_content=<!-- wp:paragraph --><p>A second staged pass.</p><!-- /wp:paragraph -->',
		] );
	} );

	// Nothing here saves through the editor, so the backstop has nothing to
	// carry; hearing it would mean something else in the flow forked.
	test.afterEach( () => {
		const heard = backstopEvents();

		cleanUp( liveId );

		expect( heard, 'The save was carried by the backstop.' ).toHaveLength(
			0
		);
	} );

	test( 'the review link follows the newest staged save', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		const link = page.locator( '.swpub-status-row a' ).first();

		await link.waitFor( { timeout: 20_000 } );

		const before = await link.getAttribute( 'href' );

		await appendAndSave( page, ' One more staged word.' );

		// Clicking into a block switches the sidebar to its Block tab.
		await showDocumentPanel( page );
		await link.waitFor( { timeout: 20_000 } );

		// The server builds this URL once, at page load, naming the revision
		// that was newest then. Every save writes another one, so a link that
		// did not move would open on the change minus the edit just made: the
		// stalest possible answer to "what is staged".
		await expect( link ).not.toHaveAttribute( 'href', before, {
			timeout: 20_000,
		} );

		const revisions = revisionsOf( stagedCopyId );
		const href = await link.getAttribute( 'href' );

		if ( 'classic' === ( await reviewSurface( page ) ) ) {
			expect( href ).toContain(
				`to=${ revisions[ revisions.length - 1 ] }`
			);
		} else {
			expect( href ).toContain(
				`revision=${ revisions[ revisions.length - 1 ] }`
			);
		}
	} );

	test( 'the revisions view is stripped to the change, and only there', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		// This whole test is about the in-editor view; below WordPress 7.0
		// review lands on the classic screen instead (VIPPROD-753), which
		// "the classic surface diffs the change, and offers no Restore"
		// below covers.
		test.skip(
			( await reviewSurface( page ) ) === 'classic',
			'The classic surface has its own test below.'
		);

		const href = await page
			.locator( '.swpub-status-row a' )
			.first()
			.getAttribute( 'href' );

		await page.goto( href );

		await expect( page.locator( '.editor-revisions-header' ) ).toBeVisible(
			{ timeout: 20_000 }
		);

		// The diff is real, not just a screen: core marks the changed block
		// in the margin and, inside it, marks the words themselves --
		// `<del>` for what the published post said, `<ins>` for what is
		// staged. Matched on a word from each rather than the block's whole
		// text, which core's word-level diff interleaves old and new too
		// closely to match as one phrase.
		await expect(
			page.locator( '.revision-diff-marker.is-modified' )
		).toHaveCount( 1 );

		const paragraph = canvasOf( page )
			.locator( 'p[data-type="core/paragraph"]' )
			.first();

		await expect( paragraph ).toHaveClass( /is-revision-modified/ );
		await expect(
			paragraph.locator( '.revision-diff-removed' ).filter( {
				hasText: 'Summer',
			} )
		).toHaveCount( 1 );
		await expect(
			paragraph.locator( '.revision-diff-added' ).filter( {
				hasText: 'staged',
			} )
		).toHaveCount( 1 );

		// A staged copy's history is two points, the content as published and
		// the change as staged, so a scrubber between them promises more than
		// it does. The sidebar lists the same two under a heading that names
		// neither, and its toggle goes with it rather than opening nothing.
		await expect(
			page.locator( '.editor-revisions-header__slider' )
		).toBeHidden();
		await expect(
			page.locator( '.interface-interface-skeleton__sidebar' )
		).toBeHidden();
		// By name rather than by the stylesheet's selector: the header's
		// Options menu is a second expanding button in the same group in newer
		// editors, and it is the one control here that must stay.
		await expect(
			page.getByRole( 'button', { name: 'Settings', exact: true } )
		).toBeHidden();
		await expect(
			page.getByRole( 'button', { name: 'Options', exact: true } )
		).toBeVisible();

		// None of which may reach the copy's ordinary editor, where the Summary
		// panel is how this plugin says almost everything it says.
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		await expect(
			page.locator( '.interface-interface-skeleton__sidebar' )
		).toBeVisible();
		await expect( page.locator( '.swpub-status-row' ) ).toHaveCount( 1 );

		// And the published post is not a staged copy, so it is never marked.
		await openEditor( page, liveId );

		await expect( page.locator( 'body.swpub-staged' ) ).toHaveCount( 0 );
	} );

	test( 'the revisions view offers publishing, not restoring', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		// The click takeover this asserts (`publish-changes.js`) is specific
		// to the in-editor view; the classic surface (below WordPress 7.0,
		// VIPPROD-753) offers no publish-from-review action to take over --
		// only Restore to withhold, which the sibling test below covers.
		test.skip(
			( await reviewSurface( page ) ) === 'classic',
			'The classic surface has no publish action to take over.'
		);

		const href = await page
			.locator( '.swpub-status-row a' )
			.first()
			.getAttribute( 'href' );

		await page.goto( href );

		const primary = page
			.locator( '.editor-revisions-header .components-button.is-primary' )
			.first();

		// Restoring has nothing left to mean here. The timeline holds two
		// points, the content as published and the change as staged, so one end
		// is a no-op and the other is a discard that leaves the emptied copy
		// standing. Publishing is the act this screen is read before.
		await expect( primary ).toHaveText( 'Publish changes', {
			timeout: 20_000,
		} );

		// Core's own Exit is left alone: it already lands back on the copy.
		await expect(
			page.getByRole( 'button', { name: 'Exit', exact: true } )
		).toBeVisible();

		await primary.click();

		await page.waitForURL( new RegExp( `post=${ liveId }` ), {
			timeout: 20_000,
		} );

		expect( postExists( stagedCopyId ) ).toBe( false );
		expect( getPostField( liveId, 'post_content' ) ).toContain(
			'A second staged pass.'
		);
	} );

	test( 'the classic surface diffs the change, and offers no Restore', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		// The in-editor tests above cover this same ground on the surface
		// WordPress 7.0+ opens by default; below that version review lands
		// here instead (VIPPROD-753).
		test.skip(
			( await reviewSurface( page ) ) !== 'classic',
			'The in-editor surface has its own tests above.'
		);

		const href = await page
			.locator( '.swpub-status-row a' )
			.first()
			.getAttribute( 'href' );

		await page.goto( href );

		await expect( page.locator( '.revisions-diff' ) ).toBeVisible( {
			timeout: 20_000,
		} );

		// Word-level, the same way the in-editor surface marks its diff:
		// `<del>` for what the published post said, `<ins>` for what is
		// staged, each inside its own added/deleted table cell.
		await expect(
			page.locator( '.diff-deletedline' ).filter( { hasText: 'Summer' } )
		).toHaveCount( 1 );
		await expect(
			page.locator( '.diff-addedline' ).filter( { hasText: 'staged' } )
		).toHaveCount( 1 );

		// Restoring has nothing left to mean on a staged copy (D4): core's own
		// button is withheld here rather than left pointing at a discard.
		await expect( page.locator( '.restore-revision' ) ).toHaveCount( 0 );

		// The body class this plugin marks the staged copy's own screens
		// with reaches even this admin-side page.
		await expect( page.locator( 'body.swpub-staged' ) ).toHaveCount( 1 );
	} );

	test( 'the notice links the phrase naming what readers see', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );

		const notice = page
			.locator( '.components-notice' )
			.filter( { hasText: 'You are staging edits' } );

		// Inline, inside the sentence, which a block editor notice can only do
		// through a core option core calls unstable. This is the assertion that
		// fails on the day it stops being honoured, and the failure it catches
		// is a sentence with visible tags in it.
		const published = notice.getByRole( 'link', {
			name: 'the published post as it is now',
		} );

		await expect( published ).toBeVisible( { timeout: 20_000 } );

		// The front end, which is what the phrase names, and a new tab, because
		// reading it must not cost the staged edits in this one.
		expect( await published.getAttribute( 'href' ) ).not.toContain(
			'wp-admin'
		);
		await expect( published ).toHaveAttribute( 'target', '_blank' );
	} );

	test( 'a content-only change offers no Compare as text', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );

		// This fixture's only staged change is content, which the in-editor
		// view already shows; a second link to the same comparison would be
		// noise, not help.
		const notice = page
			.locator( '.components-notice' )
			.filter( { hasText: 'You are staging edits' } );

		await expect(
			notice.locator( '.components-notice__content' )
		).toBeVisible();
		await expect(
			notice.getByRole( 'button', { name: 'Compare as text' } )
		).toHaveCount( 0 );
	} );

	test( 'the Status row is ours, reads Staged, and is the way to review', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		const row = page.locator( '.swpub-status-row' );

		await expect( row ).toHaveCount( 1 );
		await expect(
			row.locator( '.editor-post-panel__row-label' )
		).toHaveText( 'Status' );

		// The value is the state and the control at once, the way "Revisions"
		// is a count you click.
		const review = row.getByRole( 'link', {
			name: 'Review staged changes',
		} );

		await expect( review ).toHaveText( 'Staged' );

		// Core's revisions view, which R23 makes the only review surface --
		// the in-editor one on WordPress 7.0+, the classic one below it
		// (VIPPROD-753). Which revision it opens on is pinned by the tests
		// above; here the point is that the row's value is a route to one of
		// the two at all.
		const reviewHref = await review.getAttribute( 'href' );

		if ( 'classic' === ( await reviewSurface( page ) ) ) {
			expect( reviewHref ).toMatch( /revision\.php\?from=\d+&to=\d+/ );
		} else {
			expect( reviewHref ).toMatch(
				/post\.php\?post=\d+&action=edit&revision=\d+/
			);
		}

		// Core prints an icon before every status it names, so a row that named
		// one without an icon would be the only bare row in the panel.
		await expect( review.locator( 'svg' ) ).toHaveCount( 1 );

		// And core prints Status first. This row replaces core's, so it sits
		// where core's sat rather than after every other row, which is where
		// the slot renders fills. Compared by position because the row is put
		// there by `order`, which leaves the DOM alone.
		const status = await row.boundingBox();
		const publish = await page
			.locator( '.editor-post-panel__row' )
			.filter( { hasText: 'Publish' } )
			.first()
			.boundingBox();

		expect( status.y ).toBeLessThan( publish.y );

		// Core's own Status row is superseded, not duplicated. Hidden rather
		// than removed, because core is what re-renders it.
		await expect(
			page.locator( '.editor-post-panel__row:has(.editor-post-status)' )
		).toBeHidden();
		await expect( reviewControl( page ) ).toHaveCount( 1 );
	} );

	test( 'the header names the staged actions in the words the table fixes', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );

		await expect( publishButton( page ) ).toHaveText( 'Publish changes' );

		const paragraph = canvasOf( page )
			.locator( 'p[data-type="core/paragraph"]' )
			.first();
		await paragraph.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' Unsaved words.' );

		/*
		 * Verbatim, both of them. These two labels come from a translation
		 * filter that matches core's own English string exactly, so a core
		 * rewording stops the rename with no error anywhere -- and the button an
		 * editor reaches would go back to promising a trash that can be undone.
		 * This is where that has to fail, not in an editor's hands.
		 */
		await expect(
			page.getByRole( 'button', { name: 'Save changes', exact: true } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Save draft', exact: true } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: 'Move to trash', exact: true } )
		).toHaveCount( 0 );
	} );

	test( 'discarding is our own control, and it discards', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		// Core's trash button is still rendered, and hidden by a rule scoped to
		// this control's presence, so it can never be hidden without it.
		await expect( page.locator( '.editor-post-trash' ) ).toBeHidden();

		const discard = page.getByRole( 'link', {
			name: 'Discard staged changes',
		} );

		await expect( discard ).toHaveCount( 1 );
		await discard.click();

		// The same nonced confirmation the posts list and the classic editor
		// lead to, which says what core's trash dialog does not.
		await expect(
			page.getByText( 'Discard staged changes?' )
		).toBeVisible();
		await page.getByRole( 'link', { name: 'Discard permanently' } ).click();

		expect( postExists( stagedCopyId ) ).toBe( false );
		expect( stagedCopyIdFor( liveId ) ).toBe( 0 );
		expect( getPostField( liveId, 'post_status' ) ).toBe( 'publish' );
		expect( getPostField( liveId, 'post_content' ) ).toContain(
			PUBLISHED_TEXT
		);
	} );
} );

/**
 * The person staging exists for, standing on the published post.
 *
 * Core offers them "Submit for Review", which is a state this plugin does not
 * have and a word its vocabulary rules out -- and the click stages instead. The
 * button has to say so before it is pressed (R52).
 */
test.describe( 'The published post, for someone whose save stages', () => {
	let liveId;

	test.use( {
		storageState: path.join(
			process.cwd(),
			'artifacts/storage-states/revisor.json'
		),
	} );

	test.beforeEach( () => {
		clearBackstopEvents();
		createCategory( CATEGORY );
		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			PUBLISHED_TEXT
		);
	} );

	test.afterEach( () => {
		const heard = backstopEvents();

		cleanUp( liveId );

		expect( heard, 'The save was carried by the backstop.' ).toHaveLength(
			0
		);
	} );

	test( 'the primary button says it will stage', async ( { page } ) => {
		await openEditor( page, liveId );

		await expect( publishButton( page ) ).toHaveText( 'Stage changes' );
		expect( stagedCopyIdFor( liveId ) ).toBe( 0 );
	} );

	test( 'the primary button stops saying it will stage once a copy exists', async ( {
		page,
	} ) => {
		// Core's own label here, with no publish capability, is "Submit for
		// Review" -- a word this plugin's vocabulary rules out, and no more
		// honest than "Stage changes": the copy owns the title, content, and
		// excerpt now, and a write to any of them is refused (VIPPROD-1171).
		createStagedCopyFor( liveId );

		await openEditor( page, liveId );

		await expect( publishButton( page ) ).toHaveText( 'Save' );

		// Nothing dirty yet, so core's own disabled state -- nothing to save --
		// already applies.
		await expect( publishButton( page ) ).toBeDisabled();

		// The title is read-only here: typing into it changes nothing, and
		// the editor never counts the post as dirty for it (VIPPROD-1121).
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

		const seen = [];
		page.on( 'request', ( request ) => {
			seen.push(
				`${ request.method() } ${ decodeURIComponent( request.url() ) }`
			);
		} );

		// The lock behind the read-only field: a title edit that reaches the
		// store anyway still disables saving, the same way it did before this
		// screen made the field read-only.
		await dirtyTitle( page, 'Meridian Active, autumn' );
		await expect( publishButton( page ) ).toBeDisabled();

		await page.keyboard.press( 'ControlOrMeta+s' );

		// Long enough for a request that was going to happen to have happened.
		// Asserting "nothing was sent" the instant after the keypress would
		// pass before anything could have been.
		await page.waitForTimeout( 1000 );

		expect( seen ).toHaveLength( 0 );
		await expect(
			page
				.locator( '.components-notice__content' )
				.filter( { hasText: 'Updating failed' } )
		).toHaveCount( 0 );
		expect( getPostField( liveId, 'post_title' ) ).not.toContain(
			'autumn'
		);
	} );

	test( 'a category still saves from the published post once a copy exists', async ( {
		page,
	} ) => {
		// R42 with a copy already standing: a category touches none of the
		// three fields the copy owns, so it still applies to the published
		// post exactly as core, whoever is saving it.
		createStagedCopyFor( liveId );

		await openEditor( page, liveId );
		await chooseCategory( page, CATEGORY );

		await expect( publishButton( page ) ).toBeEnabled();
		await expect( publishButton( page ) ).toHaveText( 'Save' );

		const saved = page.waitForResponse(
			( response ) =>
				/^(?:PUT|POST)$/.test( response.request().method() ) &&
				/\/wp\/v2\/posts\/\d+(?![\d/])/.test(
					decodeURIComponent( response.url() )
				)
		);
		await publishButton( page ).click();
		expect( ( await saved ).ok() ).toBe( true );

		// The category reached the published post, the post is still
		// published, and the copy is still standing.
		await expect
			.poll( () =>
				wp( [
					'post',
					'term',
					'list',
					String( liveId ),
					'category',
					'--field=name',
				] )
			)
			.toContain( CATEGORY );
		expect( getPostField( liveId, 'post_status' ) ).toBe( 'publish' );
		expect( stagedCopyIdFor( liveId ) ).toBeGreaterThan( 0 );
	} );

	test( 'the title is read-only on the published post once a copy exists, and nowhere else', async ( {
		page,
	} ) => {
		// The control: editable before a copy exists, the same as any other
		// published post.
		await openEditor( page, liveId );

		const title = titleField( page );

		await expect( title ).toHaveAttribute( 'contenteditable', 'true' );
		await expect( title ).not.toHaveAttribute( 'aria-readonly', 'true' );

		const stagedCopyId = createStagedCopyFor( liveId );

		await openEditor( page, liveId );

		await expect( title ).toHaveAttribute( 'contenteditable', 'false' );
		await expect( title ).toHaveAttribute( 'aria-readonly', 'true' );

		// The canary stays quiet on a screen that is actually working: waited
		// past its 4-second grace, not just past the notice's own timeout.
		await page.waitForTimeout( 5000 );
		await expect(
			page.locator( '.components-notice__content' ).filter( {
				hasText: 'Some of this screen may show WordPress defaults',
			} )
		).toHaveCount( 0 );

		// The copy is where the title is actually edited.
		await openEditor( page, stagedCopyId );

		await expect( titleField( page ) ).toHaveAttribute(
			'contenteditable',
			'true'
		);
	} );

	test( 'the excerpt field leaves the published post once a copy exists', async ( {
		page,
	} ) => {
		// The control: present before a copy exists.
		await openEditor( page, liveId );
		await showDocumentPanel( page );

		await expect(
			page.locator( '.editor-post-excerpt__dropdown' )
		).toBeVisible();

		const stagedCopyId = createStagedCopyFor( liveId );

		await openEditor( page, liveId );
		await showDocumentPanel( page );

		await expect(
			page.locator( '.editor-post-excerpt__dropdown' )
		).toHaveCount( 0 );

		// The copy is where the excerpt is actually edited.
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		await expect(
			page.locator( '.editor-post-excerpt__dropdown' )
		).toBeVisible();
	} );

	test( 'a staged copy they cannot publish says so exactly once', async ( {
		page,
	} ) => {
		const stagedCopyId = createStagedCopyFor( liveId );

		wp( [
			'post',
			'update',
			String( stagedCopyId ),
			'--post_content=<!-- wp:paragraph --><p>A second staged pass.</p><!-- /wp:paragraph -->',
		] );

		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		/*
		 * Core renders its Status row read-only for an editor who cannot
		 * publish, which is why this plugin used to add a row of its own here.
		 * The Status fill covers both readers now, so the second row would be
		 * the same fact said twice.
		 */
		await expect( page.locator( '.swpub-status-row' ) ).toHaveCount( 1 );
		await expect( page.locator( '.swpub-staged-row' ) ).toHaveCount( 0 );
		await expect( reviewControl( page ) ).toHaveCount( 1 );

		// Said once on the screen, not just once in the markup. The regexp is
		// case-sensitive on purpose: "Discard staged changes" is a different
		// row saying a different thing.
		await expect(
			page
				.locator(
					'.editor-post-summary .editor-post-panel__row:visible, .editor-post-summary .components-panel__row:visible'
				)
				.filter( { hasText: /Staged/ } )
		).toHaveCount( 1 );
	} );
} );
