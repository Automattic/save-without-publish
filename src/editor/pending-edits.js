/**
 * Letting go of edits the editor has already delivered somewhere else.
 *
 * Both ways into staging end the same way: the words are safely on the staged
 * copy, and the editor still believes they are unsaved. Navigating with that
 * belief armed shows a "Leave site?" dialog, and in some contexts cancels the
 * navigation outright.
 */

/**
 * Clears the editor's pending edits so it stops believing work is unsaved.
 *
 * A redirected save looks like a failure to the editor, so it keeps its edits.
 * A deliberate staging never went through the editor's save at all, so it never
 * had a reason to drop them. Either way the words are on the staged copy and the
 * editor has to stop guarding them.
 *
 * Core's entity reducer drops any edit whose value is `undefined`, so passing
 * every edited key as `undefined` empties the edit set. That is the documented
 * `editEntityRecord` action, not an internal: nothing here reaches for an
 * `__unstable` or `__experimental` API.
 *
 * The rule for what to clear: only what was actually delivered. Without
 * `delivered` (the after-a-real-save path), the editor's edits are exactly what
 * was saved, so everything clears. With `delivered` (the deliberate-staging
 * path), a key clears only while its current value still equals the value that
 * was sent; a key that changed mid-request, or was never sent at all, stays
 * edited so the editor's own unload guard protects it instead of the words
 * being silently dropped. The comparison has to read through the editor's
 * selectors: the sent content was `getEditedPostContent()`'s serialized string,
 * while the raw entity edit for `content` may be a function over blocks, so the
 * raw edit is never compared against the sent string directly.
 *
 * @param {Object} [delivered] The values already delivered elsewhere, keyed the
 *                             way they were sent (title, content, excerpt).
 *                             Omit to clear every pending edit.
 * @return {boolean} True when the editor no longer reports unsaved changes.
 */
export function clearPendingEdits( delivered ) {
	const data = window.wp && window.wp.data;

	if ( ! data ) {
		return false;
	}

	const editor = data.select( 'core/editor' );
	const coreSelect = data.select( 'core' );
	const coreDispatch = data.dispatch( 'core' );

	if ( ! editor || ! coreSelect || ! coreDispatch ) {
		return false;
	}

	const post = editor.getCurrentPost && editor.getCurrentPost();

	if ( ! post || ! post.id || ! post.type ) {
		return false;
	}

	const edits =
		coreSelect.getEntityRecordEdits( 'postType', post.type, post.id ) || {};

	const cleared = {};
	Object.keys( edits ).forEach( ( key ) => {
		if ( delivered ) {
			if ( ! ( key in delivered ) ) {
				return;
			}

			// Like against like: the sent content came from
			// getEditedPostContent(), so that selector is what the current
			// value is read through here too.
			const current =
				'content' === key
					? editor.getEditedPostContent()
					: editor.getEditedPostAttribute( key );

			if ( current !== delivered[ key ] ) {
				return;
			}
		}

		cleared[ key ] = undefined;
	} );

	if ( Object.keys( cleared ).length ) {
		coreDispatch.editEntityRecord(
			'postType',
			post.type,
			post.id,
			cleared,
			{
				undoIgnore: true,
			}
		);
	}

	return ! editor.isEditedPostDirty();
}
