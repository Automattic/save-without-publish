/**
 * The staging request.
 *
 * Kept apart from the control that issues it, the way the merge request is: the
 * request is about the published post, and the control is about the editor.
 *
 * Both ways into staging come through here -- the Summary panel's button, and
 * the save the request middleware recognises and redirects (KTD34) -- so the
 * autosave lock belongs here too rather than at either call site.
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * The fields a staged copy can hold, in the shape the route names them.
 */
const STAGED_FIELDS = [ 'title', 'content', 'excerpt' ];

/**
 * The autosave lock's key.
 *
 * Namespaced because core keys locks by string and every plugin's locks share
 * one register; releasing another plugin's lock is a one-word typo away.
 */
const LOCK = 'swpub/staging';

/**
 * Takes or releases the autosave lock (KTD39).
 *
 * An autosave fired mid-request writes to the published post through the
 * autosave endpoint, which staging deliberately does not contain, and it races
 * the staging write for the same words. `lockPostSaving` is deliberately not
 * used alongside it: it would also disable "Save changes" on a staged copy, and
 * a save that arrives during the request is handled correctly by the fork
 * underneath anyway.
 *
 * Reaches through `window.wp.data` rather than importing the editor store, so a
 * screen that never registered it -- there is no editor to autosave there --
 * costs nothing instead of throwing.
 *
 * @param {boolean} locked Whether autosaving should be locked.
 * @return {void}
 */
function lockAutosaving( locked ) {
	const data = window.wp && window.wp.data;
	const editor = data && data.dispatch( 'core/editor' );

	if ( ! editor || ! editor.lockPostAutosaving ) {
		return;
	}

	if ( locked ) {
		editor.lockPostAutosaving( LOCK );
		return;
	}

	editor.unlockPostAutosaving( LOCK );
}

/**
 * Stages what the editor is holding, without publishing it.
 *
 * Only the fields actually passed are sent. The route treats a field it did not
 * receive as untouched, so omitting one leaves the staged copy's own value
 * standing -- which is what a save carrying only new content means, and is the
 * difference between staging a content change and blanking a staged title.
 *
 * @param {number} liveId The published post's ID.
 * @param {Object} fields The title, content, and excerpt to stage. Any of them
 *                        may be absent.
 * @return {Promise<Object>} The staged copy's ID and edit URL.
 */
export async function stageChanges( liveId, fields ) {
	const data = {};

	STAGED_FIELDS.forEach( ( field ) => {
		if ( 'string' === typeof fields[ field ] ) {
			data[ field ] = fields[ field ];
		}
	} );

	lockAutosaving( true );

	try {
		return await apiFetch( {
			path: `/swpub/v1/stage/${ liveId }`,
			method: 'POST',
			data,
		} );
	} finally {
		lockAutosaving( false );
	}
}
