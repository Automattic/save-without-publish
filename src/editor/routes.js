/**
 * The routes between a published post and its staged copy.
 *
 * One definition of each destination, used by every notice that offers it, so
 * the same screen is never called two different things depending on which copy
 * an editor happens to be standing on.
 *
 * Reading opens a new tab, going somewhere to work navigates in place. A
 * reviewer following a link out of a staged copy would otherwise discard unsaved
 * edits in order to go and look at what those edits are.
 *
 * The new tab is opened by hand rather than declared: core's notice actions pass
 * `href` and `onClick` to a Button and nothing else, so there is no `target` to
 * set. The `url` is still given, which keeps these real links -- middle-click and
 * "open in new tab" behave, and the status bar shows where they go.
 */

import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * The review URL, kept current with the copy being edited.
 *
 * The server builds this URL once, when the page renders, and it names the
 * newest revision as of that moment. Every save after it writes another one, so
 * on a copy someone is working in, a link built at page load points at the save
 * before the one they just made -- the change they are looking at, minus their
 * last edit.
 *
 * So the revision is re-read from the editor rather than trusted from the page.
 * `getCurrentPostLastRevisionId()` reads the post's own `predecessor-version`
 * link, which the save response replaces, so it is right again the moment a save
 * settles.
 *
 * A copy holding only its baseline arrives with no URL to refresh at all
 * (VIPPROD-753): one revision is nothing to review yet. Whether that has
 * changed is answered against the baseline's own id, sent for exactly this
 * -- `getCurrentPostLastRevisionId()` cannot say it on its own, since it
 * names whichever revision is newest, baseline included, so it already
 * answers "yes" to "is there a last revision" before there is a second end
 * to compare it against.
 *
 * Once a second end does exist, the two surfaces are rebuilt differently,
 * because they are not read the same way. The in-editor view takes one
 * revision and diffs it against whichever came before, so its URL is
 * `compareUrl` (or, the first time, the copy's own edit screen -- where this
 * hook is read from) with the freshest id swapped in. The classic screen
 * (WordPress below 7.0, `ctx.reviewSurface`) takes both ends by name, so it
 * is rebuilt from them directly: the baseline, which never moves, and the
 * freshest id. `classicRevisionBase` needs neither id to be shippable --
 * `revision.php` takes `from` and `to` as query arguments -- so it costs
 * nothing sent even where there was nothing yet to review.
 *
 * Only on the copy being edited. On the published post the review points at the
 * staged copy, which is not the post this editor is holding, so there is no
 * fresher answer here than the one the page arrived with.
 *
 * @param {Object} ctx The staging context.
 * @return {string} The URL, or an empty string when there is nothing to review.
 */
export function useReviewUrl( ctx ) {
	const lastRevisionId = useSelect(
		( select ) => select( editorStore ).getCurrentPostLastRevisionId(),
		[]
	);

	if ( ! ctx.isStaged || ! lastRevisionId ) {
		return ctx.compareUrl || '';
	}

	if (
		! ctx.baselineRevisionId ||
		lastRevisionId === ctx.baselineRevisionId
	) {
		return ctx.compareUrl || '';
	}

	if ( 'classic' === ctx.reviewSurface ) {
		return addQueryArgs( ctx.classicRevisionBase, {
			from: ctx.baselineRevisionId,
			to: lastRevisionId,
		} );
	}

	return addQueryArgs( ctx.compareUrl || window.location.href, {
		revision: lastRevisionId,
	} );
}

/**
 * A destination that is read rather than worked in.
 *
 * @param {string} label The link text.
 * @param {string} url   Where it goes.
 * @return {Object} A notice action.
 */
function toRead( label, url ) {
	return {
		label,
		url,
		onClick: ( event ) => {
			event.preventDefault();
			window.open( url, '_blank', 'noopener,noreferrer' );
		},
	};
}

/**
 * A destination the editor moves to.
 *
 * @param {string} label The link text.
 * @param {string} url   Where it goes.
 * @return {Object} A notice action.
 */
function toOpen( label, url ) {
	return { label, url };
}

/**
 * Core's revisions view, opened on what is staged.
 *
 * R23 forbids a diff tool of our own, so this is the review surface everywhere
 * it is offered.
 *
 * @param {Object} ctx   The staging context.
 * @param {string} [url] The URL to use instead of the one the page arrived
 *                       with, from `useReviewUrl()`.
 * @return {Array<Object>} Nothing, or one action.
 */
export function reviewStaged( ctx, url ) {
	const target = url || ctx.compareUrl;

	if ( ! target ) {
		return [];
	}

	return [
		toRead( __( 'Review staged changes', 'save-without-publish' ), target ),
	];
}

/**
 * Core's classic compare screen, offered only when it would show something
 * the in-editor view cannot.
 *
 * That view diffs blocks; a title or excerpt is neither, so a change to
 * either reads there as nothing changed at all (VIPPROD-753, F2). This is
 * the way around that -- still core's own screen (R23), not offered
 * everywhere a review link is, because a second review link on a copy
 * whose only change is content would be a second name for the same
 * destination `reviewStaged()` already offers.
 *
 * @param {Object} ctx The staging context.
 * @return {Array<Object>} Nothing, or one action.
 */
export function compareAsText( ctx ) {
	const changed = Array.isArray( ctx.changedFields ) ? ctx.changedFields : [];
	const wanted = changed.includes( 'title' ) || changed.includes( 'excerpt' );

	if ( ! wanted || ! ctx.compareTextUrl ) {
		return [];
	}

	return [
		toRead(
			__( 'Compare as text', 'save-without-publish' ),
			ctx.compareTextUrl
		),
	];
}

/**
 * The staged copy, in the editor.
 *
 * @param {Object} ctx The staging context.
 * @return {Array<Object>} Nothing, or one action.
 */
export function editStaged( ctx ) {
	if ( ! ctx.stagedCopyEdit ) {
		return [];
	}

	return [
		toOpen(
			__( 'Edit staged changes', 'save-without-publish' ),
			ctx.stagedCopyEdit
		),
	];
}

/**
 * The published post's own history, for the drift case.
 *
 * @param {Object} ctx The staging context.
 * @return {Array<Object>} Nothing, or one action.
 */
export function reviewPublishedHistory( ctx ) {
	if ( ! ctx.historyUrl ) {
		return [];
	}

	return [
		toRead(
			__(
				'Review what changed on the published post',
				'save-without-publish'
			),
			ctx.historyUrl
		),
	];
}
