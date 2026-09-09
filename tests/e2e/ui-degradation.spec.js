/**
 * What the editor does when this plugin's chrome cannot attach.
 *
 * Most of this plugin's editor surfaces are registered slots and fail by not
 * being there. Two are not: the primary button's label, and the published
 * post's read-only title -- each a selector aimed at markup core owns, and
 * core keeps moving. Their failure mode is a control that lies rather than
 * one that is simply absent. This file drills both on purpose, and the bundle
 * missing outright besides, because these are the ones nobody notices in a
 * green suite: the flows still work, and the assertions that matter are about
 * what an editor is shown while they do.
 *
 * Nothing here is a hook the server can reach. The bundle is blocked by refusing
 * to deliver it, which is the real failure; a selector is broken from a script
 * that runs in the page before the bundle, which is something only a browser
 * driver can do.
 */

const { test, expect } = require( '@playwright/test' );
const {
	backstopEvents,
	clearBackstopEvents,
	cleanUp,
	createPublishedPost,
	createStagedCopyFor,
	getPostField,
	openEditor,
	postExists,
	publishButton,
	showDocumentPanel,
	stagedCopyIdFor,
	titleField,
} = require( './helpers' );

const PUBLISHED_TEXT = 'The Summer collection launches in June.';

/**
 * The disclosed notice the canary posts.
 */
const DEGRADED = 'Some of this screen may show WordPress defaults';

/**
 * Makes the publish control's selector match nothing, for this page load only.
 *
 * Test-only and unreachable from the server: no filter, option, context key, or
 * query parameter sets it, and the bundle only ever reads it. The only way in is
 * to run script in the admin page before the bundle, which is what
 * `addInitScript()` does -- and anything able to do that in production already
 * owns the editor. Setting it weakens no guarantee either: that is the whole
 * point of the drill below.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Promise<void>}
 */
function breakPublishSelector( page ) {
	return page.addInitScript( () => {
		window.swpubTestBreakPublishSelector = true;
	} );
}

/**
 * Makes the title's selector match nothing, for this page load only.
 *
 * Same shape and same guarantee as `breakPublishSelector()` above: unreachable
 * from the server, and the save lock behind the read-only title is unaffected
 * either way -- this only drills the courtesy in front of it.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Promise<void>}
 */
function breakTitleSelector( page ) {
	return page.addInitScript( () => {
		window.swpubTestBreakTitleSelector = true;
	} );
}

/**
 * Subscribes to the canary hook the way a site's telemetry would.
 *
 * Installed before the page's own scripts, and waits for `wp.hooks` rather than
 * assuming it, because the subscriber has to be listening before the editor has
 * finished deciding whether anything is wrong.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Promise<void>}
 */
function watchCanary( page ) {
	return page.addInitScript( () => {
		window.swpubCanaryHeard = [];

		const install = () => {
			if ( window.wp && window.wp.hooks && window.wp.hooks.addAction ) {
				window.wp.hooks.addAction(
					'swpub.canary',
					'swpub/e2e',
					( id ) => window.swpubCanaryHeard.push( id )
				);

				return;
			}

			window.setTimeout( install, 10 );
		};

		install();
	} );
}

test.describe( 'When the editor chrome cannot attach', () => {
	let liveId;
	let stagedCopyId;

	test.beforeEach( () => {
		clearBackstopEvents();
		liveId = createPublishedPost(
			'Meridian Active, Summer collection',
			PUBLISHED_TEXT
		);
		stagedCopyId = createStagedCopyFor( liveId );
	} );

	/*
	 * Degraded is not the same as forked. Nothing below takes a path that
	 * stages, so the backstop alarm has to stay as quiet here as it does on the
	 * happy path -- if it fires, a drill quietly turned into a save.
	 */
	test.afterEach( () => {
		const heard = backstopEvents();

		cleanUp( liveId );

		expect( heard, 'The save was carried by the backstop.' ).toHaveLength(
			0
		);
	} );

	test( 'with the bundle gone, core keeps its own Status row and trash control', async ( {
		page,
	} ) => {
		// Not a hook of ours: the bundle is simply never delivered. The
		// stylesheet still is, which is the point -- it is enqueued
		// independently, so an unscoped hide would take core's controls away on
		// exactly this page load.
		await page.route(
			'**/save-without-publish/build/index.js*',
			( route ) => route.abort()
		);

		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		expect(
			await page.evaluate(
				() =>
					!! document.querySelector(
						'link[href*="save-without-publish/build/index.css"]'
					)
			),
			'The stylesheet did not load, so this proves nothing.'
		).toBe( true );

		// Nothing of ours rendered, and both superseded controls are still
		// there, visible and working.
		await expect(
			page.locator( '.swpub-status-row, .swpub-discard-row' )
		).toHaveCount( 0 );
		await expect(
			page.locator( '.editor-post-panel__row:has(.editor-post-status)' )
		).toBeVisible();
		await expect( page.locator( '.editor-post-trash' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Move to trash' } )
		).toBeVisible();
	} );

	test( 'a rotted publish selector discloses itself and still publishes', async ( {
		page,
	} ) => {
		await breakPublishSelector( page );
		await openEditor( page, stagedCopyId );

		// Said where the person about to press the button is reading, because a
		// console error is invisible to them.
		await expect(
			page
				.locator( '.components-notice__content' )
				.filter( { hasText: DEGRADED } )
		).toBeVisible( { timeout: 20_000 } );

		// The label fell back to core's own word, which is the failure being
		// drilled rather than an incidental detail.
		await expect( publishButton( page ).first() ).not.toHaveText(
			'Publish changes'
		);

		// Core's button, core's handler, all the way to the server.
		await publishButton( page ).first().click();

		const panelButton = page.locator(
			'.editor-post-publish-panel .editor-post-publish-button__button'
		);

		await panelButton
			.first()
			.waitFor( { timeout: 10_000 } )
			.catch( () => {
				// Core shows the pre-publish panel by preference. Where it is
				// switched off the first click already sent the save.
			} );

		if ( await panelButton.count() ) {
			await panelButton.first().click();
		}

		/*
		 * The end of the chain: the server refused to publish a staged copy,
		 * the middleware recognised the refusal by its code rather than by any
		 * piece of markup, and the merge this plugin owns ran anyway. One round
		 * trip slower than the takeover, and the same outcome.
		 */
		await page.waitForURL( new RegExp( `post=${ liveId }` ), {
			timeout: 20_000,
		} );

		// The change went to the published post, and the copy is gone. What the
		// refusal prevented is the other outcome: core publishing the staged
		// copy itself, as a second public post.
		expect( postExists( stagedCopyId ) ).toBe( false );
		expect( stagedCopyIdFor( liveId ) ).toBe( 0 );
		expect( getPostField( liveId, 'post_status' ) ).toBe( 'publish' );
		expect( getPostField( liveId, 'post_content' ) ).toContain(
			PUBLISHED_TEXT
		);
	} );

	test( 'a rotted title selector discloses itself, and the save stays locked', async ( {
		page,
	} ) => {
		await watchCanary( page );
		await breakTitleSelector( page );

		// The published post, not the staged copy: this drills the courtesy
		// standing in front of the boundary a save onto the published post
		// meets either way.
		await openEditor( page, liveId );

		await expect(
			page
				.locator( '.components-notice__content' )
				.filter( { hasText: DEGRADED } )
		).toBeVisible( { timeout: 20_000 } );

		await expect
			.poll( () => page.evaluate( () => window.swpubCanaryHeard || [] ), {
				timeout: 20_000,
			} )
			.toContain( 'title-lock' );

		// The courtesy failed -- the title is editable, which is the failure
		// being drilled -- but the boundary behind it did not: a title edit
		// that reaches the store anyway still disables saving, the same way
		// it would if the title had never been reachable at all.
		const title = titleField( page );

		await expect( title ).toHaveAttribute( 'contenteditable', 'true' );

		const seen = [];
		page.on( 'request', ( request ) => {
			seen.push(
				`${ request.method() } ${ decodeURIComponent( request.url() ) }`
			);
		} );

		await title.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ', autumn' );

		await expect( publishButton( page ) ).toBeDisabled();

		await page.keyboard.press( 'ControlOrMeta+s' );
		await page.waitForTimeout( 1000 );

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
	} );

	test( 'the canary is something a site can subscribe to', async ( {
		page,
	} ) => {
		await watchCanary( page );
		await breakPublishSelector( page );

		await openEditor( page, stagedCopyId );

		// The plugin ships no logging of its own (KTD38): the hook is how a site
		// learns that its editors are looking at core's words.
		await expect
			.poll( () => page.evaluate( () => window.swpubCanaryHeard || [] ), {
				timeout: 20_000,
			} )
			.toContain( 'publish-button' );
	} );
} );
