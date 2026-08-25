/**
 * Publishing staged changes from the editor's own Publish button.
 *
 * Core's button means "save this post", which on a staged copy is refused
 * server-side, so its click is taken over here and turned into a merge: "put
 * these changes on the published post". Two publish-looking buttons is the
 * confusion this replaces, so the takeover is deliberate rather than an extra
 * control added beside it.
 *
 * The click publishes. It does not ask first, and that is deliberate: the editor
 * clicked a button that says what it does, on a screen whose Status reads
 * Staged, and every merge writes the published post's previous content as a
 * revision before it writes the new one, so the act is undoable from core's own
 * revision screen. A dialog that says the same sentence every time is a click to
 * get through, not a decision.
 *
 * There is one dialog, and it is for the case where something is actually wrong:
 * the published post changed while these edits were staged. That one has to be a
 * modal rather than a notice, because it offers a link that opens in a new tab
 * (navigating away from a staged copy discards unsaved edits) and because the
 * confirmation it collects is bound to the exact published state it named.
 *
 * Drift is found by attempting the merge, not by asking beforehand: the server
 * refuses with `swpub_drift` and this catches it. So publishing straight from
 * the click gives up no protection at all.
 *
 * The click takeover is cosmetic, not load-bearing (KTD36). There is no public
 * header slot to render into, and a portal into core's header markup would
 * trade this selector for a worse one and give up core's own busy, disabled and
 * shortcut wiring. So the selector stays, and the guarantee moves off it: if it
 * ever matches nothing, the canary discloses that, the click reaches core, the
 * server refuses it, and the middleware turns that refusal back into this modal
 * through `swpub.openPublishChanges`. One round trip slower, and never wrong.
 */

import {
	Button,
	ExternalLink,
	Flex,
	Modal,
	Notice,
} from '@wordpress/components';
import { select, useDispatch, useSelect } from '@wordpress/data';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { addAction, removeAction } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';

import { context } from './context';
import { mergeStagedChanges } from './merge';

/**
 * The published post's edit screen, flagged as somewhere a merge just landed.
 *
 * The same shape `fork-navigation.js` uses for the other arrival, because the
 * two are the same idea: a redirect the editor did not ask for, and a sentence
 * waiting on the other side explaining where they are.
 *
 * @param {string} editUrl The published post's edit URL.
 * @return {string} The URL to navigate to.
 */
function arrivalUrl( editUrl ) {
	const separator = editUrl.indexOf( '?' ) === -1 ? '?' : '&';

	return editUrl + separator + 'swpub_published=1';
}

/**
 * The primary control in the editor header, on both headers it has.
 *
 * The direct publish button and the pre-publish panel toggle share the first
 * class, so one selector covers whichever core is rendering. The second is the
 * revisions view's own header, where core's primary reads "Restore".
 *
 * Restore is taken over rather than left alone, because on a staged copy it has
 * nothing left to mean. The timeline there holds two points, the content as
 * published and the change as staged, so restoring the newer is a no-op and
 * restoring the older is a discard that leaves the emptied copy standing --
 * which "Discard staged changes" already does properly, by deleting it. The one
 * act worth offering from a screen showing what is staged is publishing it.
 *
 * It is the one shared constant every piece of the takeover reads -- the label
 * surgery, the click intercept, and the canary that watches both -- so selector
 * rot is one edit and one alarm rather than three of each.
 */
export const PUBLISH_BUTTON =
	'.editor-post-publish-button__button, .editor-revisions-header__restore-button';

/**
 * A selector that deliberately matches nothing.
 */
const NO_PUBLISH_BUTTON = '.swpub-publish-button-not-found';

/**
 * The action the middleware fires when the server refused a click that got past
 * the takeover.
 */
export const REPAIR_ACTION = 'swpub.openPublishChanges';

/**
 * Namespace for this module's own hook registrations.
 */
const NAMESPACE = 'save-without-publish/publish-changes';

/**
 * The publish control's selector, or a broken one under test.
 *
 * Test-only, and deliberately unreachable from the server: no filter, option,
 * context key, or query parameter sets it. The only way in is to run script in
 * the admin page before this bundle, which is what Playwright's
 * `addInitScript()` does to exercise the degraded path on demand -- and anything
 * able to do that in production already owns the editor.
 *
 * Breaking it costs no guarantee either, which is the point of the drill: the
 * canary discloses the failure, core's own button handles the click, the server
 * refuses to publish a staged copy, and the refusal reopens this modal.
 *
 * @return {string} The selector to use.
 */
export function publishButtonSelector() {
	return true === window.swpubTestBreakPublishSelector
		? NO_PUBLISH_BUTTON
		: PUBLISH_BUTTON;
}

/**
 * The label core's publish button carries while editing a staged copy.
 *
 * @return {string} The label.
 */
export function publishLabel() {
	return __( 'Publish changes', 'save-without-publish' );
}

/**
 * The click takeover, and the one dialog it can end in.
 *
 * @return {Object|null} The drift modal, on the one path that opens it.
 */
export function PublishChanges() {
	const ctx = context();
	const [ busy, setBusy ] = useState( false );
	const [ drift, setDrift ] = useState( null );
	const [ repairing, setRepairing ] = useState( false );
	const { createErrorNotice, createWarningNotice } =
		useDispatch( noticesStore );

	// A merge reads the staged copy from the database, so anything still sitting
	// in the editor would be silently left behind. Saving first is the whole
	// contract, not a nicety. Read as two facts rather than one, because the
	// repair below has to wait out the second and only judge on the first.
	const { savePost } = useDispatch( editorStore );

	// Read for the repair below, which has to wait one out. What the click path
	// needs is the answer at the moment of the click, which it reads itself.
	const saving = useSelect(
		( registry ) => registry( editorStore ).isSavingPost(),
		[]
	);

	const active = ctx.isStaged && ! ctx.stranded;

	/**
	 * Publishes the staged changes, and handles the one refusal that is not an
	 * error: the published post changed while these edits were staged.
	 *
	 * @param {string|null} confirm The published state being overwritten, when
	 *                              this is the second attempt.
	 */
	const merge = useCallback(
		async ( confirm ) => {
			setBusy( true );

			try {
				const result = await mergeStagedChanges(
					ctx.stagedCopyId,
					confirm
				);

				// The staged copy no longer exists, so staying here would 404.
				window.location.assign( arrivalUrl( result.editUrl ) );
			} catch ( error ) {
				if ( error && error.code === 'swpub_drift' ) {
					setDrift( error.data );
				} else {
					setDrift( null );
					createErrorNotice(
						( error && error.message ) ||
							__(
								'Could not publish these changes.',
								'save-without-publish'
							),
						{ type: 'snackbar' }
					);
				}

				setBusy( false );
			}
		},
		[ ctx.stagedCopyId, createErrorNotice ]
	);

	/**
	 * Saves whatever is still in the editor, then publishes it.
	 *
	 * Shared by the two ways in -- the intercepted click, and the repair after
	 * a click the intercept missed -- so both answer the same way.
	 *
	 * A merge reads the staged copy from the database, so anything still in the
	 * editor has to reach it first. This used to say that and stop, which left
	 * the editor holding a refusal and a second button to press: the click said
	 * "publish what I am looking at", and the answer was a note about how to
	 * make that possible. Saving is what that note asked for, so it is done.
	 *
	 * The save is still the condition, though, not a formality. If it fails --
	 * a locked field the copy cannot carry, a network that dropped -- the post
	 * is still dirty afterwards, and nothing is published, because publishing
	 * would then send the older words the database is holding rather than the
	 * ones on screen.
	 *
	 * Read fresh rather than from a render, because a click and a keystroke can
	 * arrive between the two.
	 */
	const request = useCallback( async () => {
		if ( select( editorStore ).isEditedPostDirty() ) {
			await savePost();

			if ( select( editorStore ).isEditedPostDirty() ) {
				createWarningNotice(
					__(
						'Your changes could not be saved, so nothing was published. The published post is unchanged.',
						'save-without-publish'
					),
					{ id: 'swpub-unsaved', type: 'snackbar' }
				);

				return;
			}
		}

		setDrift( null );
		merge( null );
	}, [ savePost, createWarningNotice, merge ] );

	useEffect( () => {
		if ( ! active ) {
			return;
		}

		function intercept( event ) {
			const target = event.target;

			if (
				! target ||
				typeof target.closest !== 'function' ||
				! target.closest( publishButtonSelector() )
			) {
				return;
			}

			// Capture phase, so core's own handler never sees the click and no
			// attempt is made to publish the staged copy itself.
			event.preventDefault();
			event.stopPropagation();

			request();
		}

		document.addEventListener( 'click', intercept, true );

		return () => document.removeEventListener( 'click', intercept, true );
	}, [ active, request ] );

	useEffect( () => {
		if ( ! active ) {
			return;
		}

		/*
		 * The other end of the repair chain. The middleware cannot render this
		 * modal, and it must not know how to find it, so it says what happened
		 * and this listens. Keyed on the server's error code rather than on any
		 * piece of core's markup, which is what makes the recovery survive the
		 * failure it exists for.
		 *
		 * It records the request rather than answering it. The refusal arrives
		 * while the save that caused it is still unwinding, so a component that
		 * answered on the spot would read "still saving", refuse itself, and
		 * turn the recovery into a snackbar telling the editor to save work they
		 * have already saved.
		 */
		addAction( REPAIR_ACTION, NAMESPACE, () => setRepairing( true ) );

		return () => removeAction( REPAIR_ACTION, NAMESPACE );
	}, [ active ] );

	useEffect( () => {
		// Once the failed save has finished unwinding, the same question the
		// intercepted click asks: is there unsaved work a merge would leave
		// behind, and if not, publish.
		if ( ! repairing || saving ) {
			return;
		}

		setRepairing( false );
		request();
	}, [ repairing, saving, request ] );

	if ( ! drift ) {
		return null;
	}

	function close() {
		setDrift( null );
	}

	return (
		<Modal
			title={ __( 'The published post changed', 'save-without-publish' ) }
			onRequestClose={ close }
		>
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'The published post changed while these edits were staged. Review that change before continuing: publishing now will overwrite it.',
					'save-without-publish'
				) }
			</Notice>

			{ !! drift.history_url && (
				<p>
					<ExternalLink href={ drift.history_url }>
						{ __( 'Review the change', 'save-without-publish' ) }
					</ExternalLink>
				</p>
			) }

			<Flex justify="flex-end">
				<Button variant="tertiary" onClick={ close }>
					{ __( 'Cancel', 'save-without-publish' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					isBusy={ busy }
					disabled={ busy }
					/*
					 * The exact state the editor was shown is sent back
					 * verbatim. The server compares it for equality against
					 * the live post read at that moment, so a confirmation
					 * only ever applies to the change actually reviewed.
					 */
					onClick={ () => merge( drift.live_modified ) }
				>
					{ __( 'Overwrite and publish', 'save-without-publish' ) }
				</Button>
			</Flex>
		</Modal>
	);
}
