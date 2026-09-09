/**
 * Saying so, out loud, when a piece of core's chrome stopped answering to us.
 *
 * Most of this plugin's editor surfaces are registered slots and cannot
 * silently stop working. Two are not: the primary button's label
 * (`relabel.js`) and the published post's read-only title (`existing-staged-
 * copy.js`), both selectors aimed at markup core owns, and core keeps moving.
 * When one stops matching, the surface it named falls back to core's own
 * behaviour while the underlying save or field lock still holds: correct, and
 * no longer honest about what it is about to do.
 *
 * A console error would be invisible to the person looking at the screen, so
 * this says it where they are looking, and fires a hook so a site can count
 * it. Nothing here changes behaviour: the flow underneath is already covered
 * server-side, and the disclosure is the whole point.
 */

import { doAction } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

/**
 * The hook a site subscribes to in order to count these.
 */
const CANARY_ACTION = 'swpub.canary';

/**
 * The notice's ID, so a second failure replaces the first rather than stacking.
 */
const NOTICE_ID = 'swpub-degraded';

/**
 * How long a check may keep answering "wrong" before it is believed.
 *
 * The editor mounts in pieces and re-renders while it settles, so a check that
 * fired on the first wrong answer would report every slow page load.
 */
const GRACE = 4000;

/**
 * How long to keep asking a check that has never answered at all.
 *
 * A control that is only rendered once a panel is opened may never appear, and
 * a poll with no end would outlive the page it was watching.
 */
const LIMIT = 60000;

/**
 * How often a check is asked.
 */
const POLL = 250;

/**
 * What has already been reported, so a re-check cannot say it twice.
 */
const reported = new Set();

/**
 * Discloses one degraded surface, once.
 *
 * @param {string} id Which surface, as a stable identifier.
 * @return {void}
 */
function reportDegraded( id ) {
	if ( reported.has( id ) ) {
		return;
	}

	reported.add( id );

	// The hook first: a site's own listener must hear about this even on a
	// screen where the notices store is not registered.
	doAction( CANARY_ACTION, id );

	const data = window.wp && window.wp.data;
	const notices = data && data.dispatch( 'core/notices' );

	if ( ! notices ) {
		return;
	}

	notices.createWarningNotice(
		__(
			'Some of this screen may show WordPress defaults; your changes still stage safely.',
			'save-without-publish'
		),
		{
			id: NOTICE_ID,
			// Not dismissible, for the same reason the staged notice is not: the
			// degraded state lasts as long as the page does, and a notice that
			// can be closed stops saying so before the click it exists for.
			isDismissible: false,
		}
	);
}

/**
 * Watches one surface and discloses it if it settles wrong.
 *
 * The check answers three ways, and the third is what keeps this quiet:
 *
 * - `true`  -- attached, correct, stop asking.
 * - `false` -- the surface is there and wrong; report if it stays wrong.
 * - `null`  -- there is nothing to judge yet (the editor has not mounted, the
 *              sidebar has never been opened). Never reported, only waited on.
 *
 * @param {string}   id    Which surface, as a stable identifier.
 * @param {Function} check Answers `true`, `false`, or `null`.
 * @return {void}
 */
export function verify( id, check ) {
	const stopAt = Date.now() + LIMIT;
	let wrongSince = null;

	function tick() {
		const state = check();

		if ( true === state ) {
			return;
		}

		if ( false === state ) {
			wrongSince = null === wrongSince ? Date.now() : wrongSince;

			if ( Date.now() - wrongSince >= GRACE ) {
				reportDegraded( id );

				return;
			}
		} else {
			wrongSince = null;
		}

		if ( Date.now() < stopAt ) {
			window.setTimeout( tick, POLL );
		}
	}

	tick();
}
