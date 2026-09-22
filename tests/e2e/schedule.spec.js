/**
 * The staged copy's own control for publishing at a set time (VIPPROD-1248).
 *
 * Everything the control calls already exists and is proven server-side
 * (VIPPROD-1247): the route, the merge, every refusal. What this file proves
 * is the one thing a unit suite cannot: that the picker an editor actually
 * touches sends the time they actually chose, and that what the row and the
 * notices say afterwards is what the server actually holds -- not just what
 * the row happens to render.
 */

const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const {
	cleanUp,
	createPublishedPost,
	createStagedCopyFor,
	notice,
	openEditor,
	runDueSchedules,
	scheduleFor,
	scheduledFor,
	showDocumentPanel,
	wp,
} = require( './helpers' );

const PUBLISHED_TEXT = 'The Summer collection launches in June.';

/**
 * The row's toggle button, wherever it renders.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Object} A locator for the button.
 */
function scheduleToggle( page ) {
	return page.locator( '.swpub-schedule-row__button' );
}

/**
 * The open dropdown's own content, scoped so a field label inside it -- Year,
 * Month, Day -- cannot be confused with anything else on the page.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Object} A locator for the popover.
 */
function schedulePopover( page ) {
	return page.locator( '.swpub-schedule-row__popover' );
}

/**
 * Opens the row's dropdown.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Promise<void>}
 */
async function openSchedule( page ) {
	await showDocumentPanel( page );
	await scheduleToggle( page ).click();
	await schedulePopover( page ).waitFor();
}

/**
 * Sets every field of the open picker to an explicit date and time.
 *
 * Every field, not just the ones that differ from today, because the picker
 * opens showing today: a test that only sets the day risks landing in the
 * past the moment it runs late in the month, or in a different year than it
 * meant to on 31 December.
 *
 * @param {import('@playwright/test').Page} page          The page.
 * @param {Object}                          when          The date and time.
 * @param {number}                          when.year
 * @param {string}                          when.month    Two-digit month, e.g. '03'.
 * @param {number}                          when.day
 * @param {number}                          when.hour12   1-12.
 * @param {number}                          when.minute
 * @param {'AM'|'PM'}                       when.meridiem
 * @return {Promise<void>}
 */
async function fillPicker(
	page,
	{ year, month, day, hour12, minute, meridiem }
) {
	const popover = schedulePopover( page );

	await popover.getByLabel( 'Year' ).fill( String( year ) );
	await popover.getByLabel( 'Year' ).press( 'Tab' );
	await popover.getByLabel( 'Month', { exact: true } ).selectOption( month );
	await popover.getByLabel( 'Day' ).fill( String( day ) );
	await popover.getByLabel( 'Day' ).press( 'Tab' );
	await popover.getByLabel( 'Hours' ).fill( String( hour12 ) );
	await popover.getByLabel( 'Hours' ).press( 'Tab' );
	await popover.getByLabel( 'Minutes' ).fill( String( minute ) );
	await popover.getByLabel( 'Minutes' ).press( 'Tab' );
	await popover.getByRole( 'radio', { name: meridiem } ).click();
}

// The whole run as the revisor: scheduling needs no capability the staged
// copy does not already require, so there is nothing the administrator's
// direct-publish bypass would change about it.
test.use( {
	storageState: path.join(
		process.cwd(),
		'artifacts/storage-states/revisor.json'
	),
} );

test.describe( 'Publishing a staged copy at a set time', () => {
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

	test( 'the row is absent on an ordinary published post', async ( {
		page,
	} ) => {
		const other = createPublishedPost(
			'An ordinary post',
			'Nothing staged.'
		);

		await openEditor( page, other );
		await showDocumentPanel( page );

		await expect( scheduleToggle( page ) ).toHaveCount( 0 );

		cleanUp( other );
	} );

	test( 'the row is absent on the published post that has a copy', async ( {
		page,
	} ) => {
		await openEditor( page, liveId );
		await showDocumentPanel( page );

		await expect( scheduleToggle( page ) ).toHaveCount( 0 );
	} );

	test( 'unscheduled, the row reads Immediately', async ( { page } ) => {
		await openEditor( page, stagedCopyId );
		await showDocumentPanel( page );

		await expect( scheduleToggle( page ) ).toHaveText( 'Immediately' );
	} );

	test( 'scheduling through the picker updates the row and the stored schedule', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await openSchedule( page );

		const popover = schedulePopover( page );
		const scheduleButton = popover.getByRole( 'button', {
			name: 'Schedule',
			exact: true,
		} );

		await expect( scheduleButton ).toBeEnabled();

		await fillPicker( page, {
			year: 2027,
			month: '03',
			day: 15,
			hour12: 10,
			minute: 30,
			meridiem: 'AM',
		} );

		await scheduleButton.click();
		await expect( schedulePopover( page ) ).toHaveCount( 0 );

		// The rendered label, not just that scheduling succeeded.
		await expect( scheduleToggle( page ) ).toHaveText(
			'March 15, 2027 10:30 am'
		);

		// The stored value, not just what the row renders -- a row that shows
		// a time it never actually sent is exactly the bug this surface can
		// have, and the site's timezone here is UTC, so the picker's 10:30 AM
		// and the stored GMT time are the same clock.
		expect( scheduledFor( stagedCopyId ) ).toBe( '2027-03-15 10:30:00' );
	} );

	test( 'the staged-state notice names the scheduled time', async ( {
		page,
	} ) => {
		scheduleFor( stagedCopyId, '2027-03-15T10:30:00Z' );

		await openEditor( page, stagedCopyId );

		await expect(
			notice(
				page,
				'These changes are scheduled to publish on March 15, 2027 10:30 am.'
			)
		).toBeVisible();
	} );

	test( 'clearing removes the schedule from the row and the server', async ( {
		page,
	} ) => {
		scheduleFor( stagedCopyId, '2027-03-15T10:30:00Z' );

		await openEditor( page, stagedCopyId );
		await openSchedule( page );

		await schedulePopover( page )
			.getByRole( 'button', { name: 'Clear', exact: true } )
			.click();

		await expect( scheduleToggle( page ) ).toHaveText( 'Immediately' );
		expect( scheduledFor( stagedCopyId ) ).toBe( '' );
	} );

	test( 'a past time is refused, and the row keeps its previous value', async ( {
		page,
	} ) => {
		await openEditor( page, stagedCopyId );
		await openSchedule( page );

		await fillPicker( page, {
			year: 2020,
			month: '01',
			day: 1,
			hour12: 9,
			minute: 0,
			meridiem: 'AM',
		} );

		await schedulePopover( page )
			.getByRole( 'button', { name: 'Schedule', exact: true } )
			.click();

		// The server's own refusal, not a client-side guess at the same rule.
		await expect(
			page.locator( '.components-snackbar' ).filter( {
				hasText: 'already passed',
			} )
		).toBeVisible();

		await expect( scheduleToggle( page ) ).toHaveText( 'Immediately' );
		expect( scheduledFor( stagedCopyId ) ).toBe( '' );
	} );

	test( "the published post's notice names the time, and the locked-fields sentence is unchanged", async ( {
		page,
	} ) => {
		scheduleFor( stagedCopyId, '2027-03-15T10:30:00Z' );

		await openEditor( page, liveId );

		await expect(
			notice(
				page,
				'Those changes are scheduled to publish on March 15, 2027 10:30 am.'
			)
		).toBeVisible();

		// The sentence two other suites locate this notice by (VIPPROD-1230),
		// still present and still exact.
		await expect(
			notice( page, 'locked here until those changes' )
		).toBeVisible();
	} );

	test( 'the posts list shows the scheduled state', async ( { page } ) => {
		scheduleFor( stagedCopyId, '2027-03-15T10:30:00Z' );

		await page.goto( '/wp-admin/edit.php' );

		const row = page.locator( `#post-${ liveId }` );

		await expect( row ).toContainText( 'Staged changes' );
		await expect( row ).toContainText( 'Scheduled' );
	} );

	test( 'a refused run shows on the copy, on the published post, and in the posts list', async ( {
		page,
	} ) => {
		// Scheduled to fire almost immediately, then the published post
		// changes before it does -- the same shape the server's own tests
		// use, arranged here through the CLI so this test is about what the
		// editor shows afterwards, not about reproducing drift by hand.
		const soon = new Date( Date.now() + 5_000 ).toISOString();
		scheduleFor( stagedCopyId, soon );

		wp( [
			'post',
			'update',
			String( liveId ),
			'--post_category=1',
			'--tags_input=drift',
		] );

		await new Promise( ( resolve ) => setTimeout( resolve, 7_000 ) );
		runDueSchedules();

		expect( scheduledFor( stagedCopyId ) ).toBe( '' );

		await openEditor( page, stagedCopyId );

		await expect(
			notice( page, 'The published post changed first' )
		).toBeVisible();

		// The ordinary drift notice would say a near-duplicate of the same
		// fact; it must not also be showing.
		await expect(
			notice(
				page,
				'Someone changed the published post after these edits were staged'
			)
		).toHaveCount( 0 );

		await page.goto( '/wp-admin/edit.php' );

		const row = page.locator( `#post-${ liveId }` );

		await expect( row ).toContainText( 'Staged changes' );
		await expect( row ).toContainText( 'Schedule stopped' );
	} );
} );
