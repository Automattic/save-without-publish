/**
 * The way into staging for someone whose save would otherwise publish.
 *
 * Staging is theirs to ask for. A save that quietly went somewhere else is what
 * happens to an editor who cannot publish, and the plugin owes them that; doing
 * it to someone who can publish means a typo fix cannot be published, and the
 * editor spends the arrival explaining a move nobody requested.
 *
 * The control sits in the Summary panel's row list, where the staged-changes row
 * sits once there is something staged. One place answers both questions: is
 * anything staged, and how do I stage something.
 */

import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginPostStatusInfo, store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import { context } from './context';
import { clearPendingEdits } from './pending-edits';
import { stageChanges } from './stage';

/**
 * Renders the row.
 *
 * @return {React.ReactNode} The row, or nothing where staging is not a choice.
 */
export function StageChanges() {
	const [ staging, setStaging ] = useState( false );
	const ctx = context();
	const { createErrorNotice } = useDispatch( noticesStore );

	// What the editor is holding right now, saved or not. `getEditedPostContent`
	// serializes the blocks the same way a save would, so the staged copy gets
	// block markup rather than a rendering of it.
	const edited = useSelect(
		( select ) => ( {
			title: select( editorStore ).getEditedPostAttribute( 'title' ),
			content: select( editorStore ).getEditedPostContent(),
			excerpt: select( editorStore ).getEditedPostAttribute( 'excerpt' ),
		} ),
		[]
	);

	// Only on a published post, only where the save would publish, and only
	// while nothing is staged yet. Once a copy exists every save joins it, so
	// there is nothing left to ask for.
	if (
		ctx.isStaged ||
		ctx.stagedCopyId ||
		! ctx.liveId ||
		! ctx.canPublishDirectly
	) {
		return null;
	}

	async function stage() {
		// The disabled prop below blocks clicks once the re-render lands, but
		// state updates are async: a same-tick double click can arrive before
		// it does, so the guard has to live here too.
		if ( staging ) {
			return;
		}

		setStaging( true );

		try {
			const staged = await stageChanges( ctx.liveId, edited );

			/*
			 * The words are on the staged copy now, but the editor still counts
			 * them as unsaved: they never went through its own save. Letting go
			 * of them is what disarms the unload guard, which would otherwise
			 * cancel the navigation or ask the editor to confirm abandoning work
			 * that is already safe.
			 *
			 * Only what was actually delivered gets let go of. Anything typed
			 * between the click and the response never reached the route, so
			 * passing the sent values makes the clear selective: a value that
			 * changed mid-request stays a pending edit, the unload guard stays
			 * armed for it, and the navigation below asks before discarding it
			 * instead of losing it silently. When nothing changed mid-request,
			 * everything clears and the navigation is silent.
			 */
			clearPendingEdits( edited );
			window.location.assign( staged.editUrl );
		} catch ( error ) {
			setStaging( false );

			// The editor stays where it is, with everything it was holding, so
			// a refusal costs the words nothing.
			createErrorNotice(
				error && error.message
					? error.message
					: __(
							'The change was not staged. The published post is unchanged, and your edits are still here.',
							'save-without-publish'
					  ),
				{ id: 'swpub-stage-failed', type: 'snackbar' }
			);
		}
	}

	return (
		<PluginPostStatusInfo className="swpub-stage-row">
			<div className="editor-post-panel__row-label">
				{ __( 'Staged changes', 'save-without-publish' ) }
			</div>
			<div className="editor-post-panel__row-control">
				<Button
					className="swpub-stage-row__button"
					onClick={ stage }
					isBusy={ staging }
					disabled={ staging }
					text={
						staging
							? __( 'Staging…', 'save-without-publish' )
							: __( 'Stage changes', 'save-without-publish' )
					}
					variant="tertiary"
					size="compact"
				/>
			</div>
		</PluginPostStatusInfo>
	);
}
