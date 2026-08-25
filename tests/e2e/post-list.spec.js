/**
 * Finding staged changes in the posts list, and discarding them.
 *
 * Discard is one of the two places staged work can be destroyed, and the whole
 * path is links and redirects, which is precisely what a unit test cannot
 * follow. An earlier build's post-discard redirect looped back into its own
 * handler and reported a failure for a discard that had already succeeded, with
 * the PHP suite entirely green.
 */

const { test, expect } = require( '@playwright/test' );
const {
	createPublishedPost,
	getPostField,
	postExists,
	createStagedCopyFor,
	stagedCopyIdFor,
	openEditor,
	cleanUp,
	wp,
} = require( './helpers' );

const PUBLISHED_TEXT = 'The Summer collection launches in June.';

test.describe( 'The posts list', () => {
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

	/**
	 * The list row for the staged post.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 * @return {Object} A locator for the row.
	 */
	function row( page ) {
		return page.locator( `#post-${ liveId }` );
	}

	test( 'a post with staged changes is labelled, and the staged copy is not listed', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/edit.php' );

		await expect( row( page ) ).toContainText( 'Staged changes' );

		// The staged copy must never appear as a post of its own.
		await expect( page.locator( `#post-${ stagedCopyId }` ) ).toHaveCount(
			0
		);
	} );

	test( 'a post without staged changes is not labelled', async ( {
		page,
	} ) => {
		const plainId = createPublishedPost(
			'Nothing staged here',
			'As published.'
		);

		await page.goto( '/wp-admin/edit.php' );

		await expect( page.locator( `#post-${ plainId }` ) ).not.toContainText(
			'Staged changes'
		);

		cleanUp( plainId );
	} );

	test( 'a post whose published version is gone reads differently', async ( {
		page,
	} ) => {
		wp( [ 'post', 'update', String( liveId ), '--post_status=draft' ] );

		await page.goto(
			'/wp-admin/edit.php?post_status=draft&post_type=post'
		);

		await expect( row( page ) ).toContainText(
			'Staged changes, Cannot be published'
		);
	} );

	test( 'discarding asks first, and abandoning it keeps the staged changes', async ( {
		page,
	} ) => {
		// Discarding is offered where the staged copy is open, not on the list:
		// core's row already carries four actions of its own. It is the
		// plugin's own control, standing in front of core's trash button,
		// because a staged copy cannot be trashed.
		await openEditor( page, stagedCopyId );
		await page
			.getByRole( 'link', { name: 'Discard staged changes' } )
			.click();

		// The confirmation names the post and says the change cannot be undone.
		await expect(
			page.getByText( 'Discard staged changes?' )
		).toBeVisible();
		await expect( page.getByText( 'cannot be restored' ) ).toBeVisible();
		await expect(
			page.getByText( 'Meridian Active, Summer collection' )
		).toBeVisible();

		// Backing out changes nothing.
		await page.getByRole( 'link', { name: 'Keep editing' } ).click();

		expect( postExists( stagedCopyId ) ).toBe( true );
		expect( stagedCopyIdFor( liveId ) ).toBe( stagedCopyId );
	} );

	test( 'confirming a discard removes the staged copy and returns to the list', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await page
			.getByRole( 'link', { name: 'Discard staged changes' } )
			.click();
		await page.getByRole( 'link', { name: 'Discard permanently' } ).click();

		// Back on the list, with the outcome stated. An earlier build redirected
		// into its own handler here and reported a failure for a discard that
		// had already succeeded.
		await expect( page ).toHaveURL( /edit\.php/ );
		await expect(
			page.getByText( 'The staged changes were discarded' )
		).toBeVisible();
		await expect( page.getByText( 'You are not allowed' ) ).toHaveCount(
			0
		);

		// Force-deleted, not trashed: there is no trash to recover it from.
		expect( postExists( stagedCopyId ) ).toBe( false );
		expect( stagedCopyIdFor( liveId ) ).toBe( 0 );

		// The published post is untouched, and no longer labelled.
		expect( getPostField( liveId, 'post_status' ) ).toBe( 'publish' );
		expect( getPostField( liveId, 'post_content' ) ).toContain(
			PUBLISHED_TEXT
		);
		await expect( row( page ) ).not.toContainText( 'Staged changes' );
	} );

	test( 'a discard link without a valid nonce is refused', async ( {
		page,
	} ) => {
		await page.goto(
			`/wp-admin/admin-post.php?action=swpub_discard&swpub_post=${ liveId }&_wpnonce=not-a-real-nonce&swpub_confirmed=1`
		);

		// Row actions are ordinary links, so an irreversible force-delete must
		// not be one crafted URL away.
		await expect(
			page.getByText( /link you followed has expired|Are you sure/i )
		).toBeVisible();

		expect( postExists( stagedCopyId ) ).toBe( true );
	} );
} );
