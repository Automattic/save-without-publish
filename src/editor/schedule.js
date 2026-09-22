/**
 * The scheduling request.
 *
 * Kept apart from the row that issues it, the way the staging and merge
 * requests are: the request is about the copy, and the row is about the
 * editor.
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Schedules a staged copy to publish at a set time.
 *
 * The value is sent exactly as the date picker produced it: a timezoneless
 * string, already in the site's own timezone because the picker itself is
 * (`@wordpress/date` resolves every value it shows and returns against the
 * site's configured timezone, not the browser's). The route reads a value
 * with no offset the same way -- as site-local -- so nothing here needs to
 * add one.
 *
 * @param {number} copyId Staged copy post ID.
 * @param {string} at     When to publish, as `@wordpress/components`'
 *                        `DateTimePicker` returns it.
 * @return {Promise<Object>} `{ scheduledFor, scheduledForLocal, scheduledBy }`.
 */
export async function scheduleAt( copyId, at ) {
	return apiFetch( {
		path: `/swpub/v1/schedule/${ copyId }`,
		method: 'POST',
		data: { at },
	} );
}

/**
 * Cancels a staged copy's schedule.
 *
 * @param {number} copyId Staged copy post ID.
 * @return {Promise<Object>} `{ scheduled: false }`.
 */
export async function cancelSchedule( copyId ) {
	return apiFetch( {
		path: `/swpub/v1/schedule/${ copyId }`,
		method: 'DELETE',
	} );
}
