/**
 * Renaming core's header buttons, so the primary button says what it will do.
 *
 * Three surfaces, three wrong words, all on the primary button. On a staged
 * copy core says "Publish", which publishes the change rather than this post,
 * and "Save draft", which a staged copy is not: it is an unpublished change to
 * a published post. On a published post with nothing staged against it,
 * someone whose save stages is offered "Submit for Review" -- a state this
 * plugin does not have and a word its vocabulary rules out -- when what the
 * click actually does is stage the change. And on a published post that
 * already has a staged copy, the same "Submit for Review" or "Stage changes"
 * promises the one thing that save cannot do: the copy owns the title,
 * content, and excerpt now, and a write to any of them is refused
 * (`swpub_live_locked`). That third case reads "Save" instead, honestly: a
 * write that changes none of those three fields -- a category, a featured
 * image -- still applies to the published post exactly as core, so "Save" is
 * never a lie there, and where it would be the fields are locked (KTD-42) so
 * the click has nothing left to refuse.
 *
 * "Move to trash" is renamed for a fourth reason, and it is the reason the
 * rename survives even though a control of our own now sits in front of it: a
 * staged copy cannot be trashed, `Transitions` force-deletes anything that
 * reaches the trash, and core's wording promises a way back that does not
 * exist. If our own control ever fails to render, the one an editor reaches
 * instead must not lie about what it destroys.
 *
 * They are renamed by two different means on purpose. "Save draft", "Move to
 * trash", and the save notice's own two strings each occur in core's editor
 * bundle once, so a translation filter reaches exactly them. "Publish" occurs
 * four times,
 * including the Summary panel's own publish-date row, so a translation filter
 * would rename that row too; the button is renamed where it is rendered
 * instead -- which is a selector aimed at core's markup, and so the one surface
 * here that can rot. `canary.js` is what makes that rot audible.
 */

import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

import { verify } from './canary';
import { context } from './context';
import { publishButtonSelector, publishLabel } from './publish-changes';

/**
 * Core's trash control in the document Summary.
 */
const TRASH_BUTTON = '.editor-post-trash';

/**
 * What core's trash control has to read once the rename has taken.
 *
 * @return {string} The label.
 */
const discardLabel = () =>
	__( 'Discard staged changes', 'save-without-publish' );

/**
 * Core's strings renamed through translation.
 *
 * Each occurs in core's editor bundle exactly once, and as the control or the
 * notice itself, so the filter reaches exactly them and nothing near them.
 *
 * The last two are the save snackbar, which core builds in
 * `getNotificationArgumentsForSaveSuccess()` from the post's status alone: a
 * status in neither publish nor draft falls to the draft branch, so a staged
 * copy is announced as "Draft saved." and offered "View Preview". A staged copy
 * is not a draft, and the vocabulary has one word for what just happened to it.
 * The link is renamed with it because what it opens is the staged change, not a
 * draft of a new post.
 */
const RENAMED = {
	'Save draft': () => __( 'Save changes', 'save-without-publish' ),
	'Move to trash': discardLabel,
	'Draft saved.': () => __( 'Changes staged.', 'save-without-publish' ),
	'View Preview': () => __( 'Preview changes', 'save-without-publish' ),
};

/**
 * Renames the save and trash controls through core's own translation filter.
 *
 * @return {void}
 */
function relabelDocumentControls() {
	addFilter(
		'i18n.gettext_default',
		'save-without-publish/renamed-controls',
		( translation, text ) => {
			if ( ! Object.hasOwn( RENAMED, text ) ) {
				return translation;
			}

			return RENAMED[ text ]();
		}
	);
}

/**
 * Keeps the primary button's label correct as the editor re-renders it.
 *
 * Watches the header rather than the document: the canvas mutates on every
 * keystroke, and the header does not. `label` is asked fresh on every check,
 * not read once, because on a published post with nothing staged yet the
 * answer can change without the header itself mutating at all (KTD-42): typing
 * into a category checkbox does not touch anything this observer watches, but
 * it does change what the next save carries.
 *
 * @param {Function} label Answers the text the button has to read, or null to
 *                         leave core's own label alone.
 * @return {void}
 */
function relabelPublish( label ) {
	function apply() {
		const text = label();

		if ( null === text ) {
			return;
		}

		document
			.querySelectorAll( publishButtonSelector() )
			.forEach( ( button ) => {
				if ( text !== button.textContent ) {
					button.textContent = text;
				}
			} );
	}

	function watch() {
		const header = document.querySelector( '.editor-header' );

		if ( ! header ) {
			// The editor mounts after this script runs, so wait for it rather
			// than relabelling nothing and giving up.
			window.requestAnimationFrame( watch );

			return;
		}

		new window.MutationObserver( apply ).observe( header, {
			childList: true,
			subtree: true,
			characterData: true,
		} );

		apply();
	}

	watch();

	/*
	 * The label is cosmetic; being wrong about it quietly is not. A button
	 * reading "Publish" on a staged copy still stages, because the server
	 * refuses it and the middleware recovers -- but the person about to click it
	 * deserves to know before they do.
	 */
	verify( 'publish-button', () => {
		if ( ! document.querySelector( '.editor-header' ) ) {
			// Nothing has mounted yet, so there is nothing to be wrong about.
			return null;
		}

		const buttons = document.querySelectorAll( publishButtonSelector() );

		if ( ! buttons.length ) {
			return false;
		}

		const text = label();

		if ( null === text ) {
			// This render has nothing to say, so there is nothing to verify.
			return true;
		}

		return Array.from( buttons ).every(
			( button ) => text === button.textContent
		);
	} );
}

/**
 * Watches the one renamed control whose absence would be silent.
 *
 * The translation filter matches core's string exactly, so a core rewording
 * makes it stop matching with no error anywhere. That leaves an editor holding a
 * button that says it moves this to the trash, next to a plugin that will delete
 * it outright.
 *
 * @return {void}
 */
function verifyTrashLabel() {
	verify( 'trash-label', () => {
		const control = document.querySelector( TRASH_BUTTON );

		if ( ! control ) {
			// Core renders this only inside the Summary panel, which an editor
			// may never open. Absent is not wrong.
			return null;
		}

		return discardLabel() === control.textContent.trim();
	} );
}

/**
 * What the primary button on a published post has to read.
 *
 * Three answers, matching the write path exactly rather than a rule of its own
 * (KTD-42, VIPPROD-1171): once a staged copy exists, every write to the title,
 * content, or excerpt is refused, whoever makes it, so those fields read "Save"
 * -- true, because a write that touches none of them still applies -- and are
 * locked at the keystroke (`existing-staged-copy.js`) so the click never meets
 * the refusal. With nothing staged yet, someone who cannot publish directly
 * still sees "Stage changes": correct for the field a fresh save carries, which
 * is the case this label exists for.
 *
 * `null` means core's own label is already right and nothing here should touch
 * it -- true for a direct publisher with no copy in the way, where core's
 * "Save" (or "Update") is the same word this plugin would have chosen.
 *
 * @param {Object} ctx The staging context.
 * @return {string|null} The label to force, or null to leave core alone.
 */
function primaryLabel( ctx ) {
	if ( ctx.stagedCopyId ) {
		return __( 'Save', 'save-without-publish' );
	}

	if ( ctx.canPublishDirectly ) {
		return null;
	}

	return __( 'Stage changes', 'save-without-publish' );
}

/**
 * Renames whichever controls this surface has.
 *
 * A stranded copy has nothing to publish to, so its publish button keeps core's
 * own label and core's own refusal.
 *
 * @return {void}
 */
export function registerRelabel() {
	const ctx = context();

	if ( ctx.isStaged ) {
		relabelDocumentControls();
		verifyTrashLabel();

		if ( ! ctx.stranded ) {
			relabelPublish( () => publishLabel() );
		}

		return;
	}

	if ( ! ctx.liveId ) {
		return;
	}

	relabelPublish( () => primaryLabel( ctx ) );
}
