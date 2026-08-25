/**
 * The merge request, and the sentence describing what it does.
 *
 * Kept apart from the UI that calls it: the same request is issued from the
 * confirmation step and again from the overwrite step, with different arguments
 * and different consequences.
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Publishes the staged changes.
 *
 * @param {number}      stagedCopyId Staged copy's post ID.
 * @param {string|null} confirm      The live post's modified time being overwritten,
 *                                   when confirming a drifted merge.
 * @return {Promise<Object>} The merge result.
 */
export function mergeStagedChanges( stagedCopyId, confirm ) {
	return apiFetch( {
		path: `/swpub/v1/merge/${ stagedCopyId }`,
		method: 'POST',
		data: confirm ? { confirm } : {},
	} );
}
