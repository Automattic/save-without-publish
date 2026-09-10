/**
 * What the editor says while a staged copy is open.
 *
 * These are core's own editor notices rather than a panel of our own: the state
 * they describe applies to the whole document, and a sidebar panel is closed,
 * scrolled past, or hidden behind the Block tab exactly when it matters.
 */

import { useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { escapeAttribute, escapeHTML } from '@wordpress/escape-html';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import { verify } from './canary';
import { context } from './context';
import { compareAsText, reviewPublishedHistory } from './routes';

/**
 * The sentence a staged copy opens with, as a format string.
 *
 * Held apart from the two places that use it because they use it differently:
 * one fills it with words, the other with a link, and the canary reads the part
 * in front of the placeholder to find the notice it is watching.
 *
 * @return {string} The format string, with one `%s`.
 */
const stagedFormat = () =>
	/* translators: %s: the words "the published post as it is now". */
	__(
		'You are staging edits to a published post. Until you publish them, readers still see %s.',
		'save-without-publish'
	);

/**
 * The phrase the sentence ends on.
 *
 * @return {string} The words.
 */
const publishedPhrase = () =>
	__( 'the published post as it is now', 'save-without-publish' );

/**
 * Says what state this staged copy is in, and why.
 *
 * The placeholder is the same phrase in both editors, and a link in both, but by
 * different means: the classic editor's notice is markup all the way down, and a
 * block editor notice carries markup only through `__unstableHTML`. That option
 * is unstable by name and by core's own docblock, so this file asks for it in
 * one place (`linkedSentence()`), escapes everything it interpolates, and
 * watches the result (`verifyNoticeLink()`). One translation serves both.
 *
 * A stranded copy names what happened to the post it staged. "This cannot be
 * published" without a reason reads as a bug; "the post it updates was deleted"
 * is something an editor can act on. Nothing is offered in that case, because
 * the post the sentence names is the one that is gone.
 *
 * @param {Object} ctx The staging context.
 * @return {string} The sentence.
 */
export function statusSentence( ctx ) {
	if ( ! ctx.stranded ) {
		return ctx.justForked
			? sprintf(
					/* translators: %s: the words "the published post". */
					__(
						'Your edit was staged instead of updating %s. It keeps serving what it serves now until you publish these changes.',
						'save-without-publish'
					),
					__( 'the published post', 'save-without-publish' )
			  )
			: sprintf( stagedFormat(), publishedPhrase() );
	}

	if ( ctx.strandReason === 'deleted' ) {
		return ctx.strandedTitle
			? sprintf(
					/* translators: %s: title of the deleted published post. */
					__(
						'The published post these changes were staged against ("%s") was deleted. The changes are kept here, but there is nothing to publish them to.',
						'save-without-publish'
					),
					ctx.strandedTitle
			  )
			: __(
					'The published post these changes were staged against was deleted. The changes are kept here, but there is nothing to publish them to.',
					'save-without-publish'
			  );
	}

	return __(
		'The post these changes were staged against is no longer published. They are kept here, and can be published once it is published again.',
		'save-without-publish'
	);
}

/**
 * The same sentence with the phrase linked to the published post's front end.
 *
 * A block editor notice can only carry markup through `__unstableHTML`, which
 * core documents as unstable and reserves the right to remove. This uses it
 * anyway, for one link, and watches it: `verifyNoticeLink()` below discloses the
 * day it stops being honoured, because the failure would otherwise be a sentence
 * with visible tags in it.
 *
 * Everything interpolated is escaped, and nothing but this path ever asks for
 * raw HTML: the stranded sentences interpolate a post title, which is exactly
 * the string that must never reach `RawHTML`.
 *
 * @param {Object} ctx The staging context.
 * @return {string|null} The markup, or null when there is nothing to link to.
 */
function linkedSentence( ctx ) {
	if ( ctx.stranded || ctx.justForked || ! ctx.liveView ) {
		return null;
	}

	const link = sprintf(
		'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
		escapeAttribute( ctx.liveView ),
		escapeHTML( publishedPhrase() )
	);

	return sprintf( escapeHTML( stagedFormat() ), link );
}

/**
 * Watches the one notice that asks core for markup.
 *
 * The notice carries no identifier into the DOM, so it is found by the words in
 * front of the link, which are the same words either way. Present with an anchor
 * is right; present without one means the markup was printed as text.
 *
 * @return {void}
 */
function verifyNoticeLink() {
	const opening = stagedFormat().split( '%s' )[ 0 ].trim();

	verify( 'staged-notice-link', () => {
		const notice = Array.from(
			document.querySelectorAll( '.components-notice__content' )
		).find( ( node ) => node.textContent.includes( opening ) );

		if ( ! notice ) {
			return null;
		}

		return !! notice.querySelector( 'a' );
	} );
}

/**
 * Posts the staged-state notices.
 *
 * Notices are posted by ID, so a re-render replaces rather than stacks them.
 *
 * @return {null} Renders nothing.
 */
export function StagedNotices() {
	const ctx = context();
	const { createNotice } = useDispatch( noticesStore );

	useEffect( () => {
		if ( ! ctx.isStaged ) {
			return;
		}

		const markup = linkedSentence( ctx );

		createNotice(
			ctx.stranded ? 'warning' : 'info',
			markup ?? statusSentence( ctx ),
			{
				id: 'swpub-staged',

				// Only where this notice built the markup itself, above.
				__unstableHTML: !! markup,

				// Not dismissible: staged work is a published post carrying a
				// change nobody has decided on yet, and it stays undecided until
				// someone publishes or discards it. A notice that can be closed
				// is one that stops saying so on the second page load.
				isDismissible: false,

				// Offered here, not on the Status row (one word, one control):
				// a title or excerpt change is invisible on the in-editor
				// revisions view, which diffs blocks (VIPPROD-753, F2).
				actions: compareAsText( ctx ),
			}
		);

		if ( markup ) {
			verifyNoticeLink();
		}
	}, [ ctx, createNotice ] );

	// What publishing did, said once, on the screen it lands on. This used to be
	// a modal asking permission before the merge, which said the same sentence
	// every time about an act the editor had just asked for and can undo from
	// core's revision screen. A report is what it always was.
	useEffect( () => {
		if ( ! ctx.justPublished ) {
			return;
		}

		createNotice(
			'success',
			__(
				'Your staged changes are published. The post kept its URL, date, and comments.',
				'save-without-publish'
			),
			{
				id: 'swpub-published-arrival',
				type: 'snackbar',

				/*
				 * Shaped like core's own after any save: the post type's own
				 * "View Post" label, the permalink, and a new tab -- which is
				 * what turns it into an external link with the arrow core puts
				 * on one. The words are core's so the control reads the same
				 * here as it does everywhere else this editor offers it.
				 */
				actions: ctx.liveView
					? [
							{
								label:
									ctx.viewLabel ||
									__( 'View Post', 'save-without-publish' ),
								url: ctx.liveView,
								openInNewTab: true,
							},
					  ]
					: [],
			}
		);
	}, [ ctx.justPublished, ctx.liveView, ctx.viewLabel, createNotice ] );

	useEffect( () => {
		if ( ! ctx.isStaged || ! ctx.drifted || ctx.stranded ) {
			return;
		}

		/*
		 * This warns; it does not gate. The refusal that actually protects the
		 * live post is server-side, re-checked immediately before the merge
		 * writes (KTD15), because anything the browser decides can be skipped by
		 * not using the browser.
		 */
		createNotice(
			'warning',
			__(
				'Someone changed the published post after these edits were staged. Publishing these changes will overwrite that change.',
				'save-without-publish'
			),
			{
				id: 'swpub-drift',
				isDismissible: false,
				actions: reviewPublishedHistory( ctx ),
			}
		);
	}, [ ctx, createNotice ] );

	return null;
}
