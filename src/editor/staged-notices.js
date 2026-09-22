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
import { compareAsText, reviewPublishedHistory, toRead } from './routes';

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
 * Says when this staged copy is due to publish itself, if it is (VIPPROD-1247).
 *
 * Not offered on a stranded copy: `Scheduled_Publish::schedule()` refuses to
 * schedule one in the first place, so `ctx.scheduledFor` is only ever set here
 * through the narrow window between a schedule being stranded and the next
 * cron tick catching it -- and the stranded sentences already say the
 * published post is gone or unpublished, which a promise to publish "on"
 * would contradict rather than add to.
 *
 * @param {Object} ctx The staging context.
 * @return {string} The sentence, or '' when there is nothing to add.
 */
function scheduledSentence( ctx ) {
	if ( ctx.stranded || ! ctx.scheduledFor ) {
		return '';
	}

	return sprintf(
		/* translators: %s: the date and time these changes are due to publish. */
		__(
			'These changes are scheduled to publish on %s.',
			'save-without-publish'
		),
		ctx.scheduledForLabel
	);
}

/**
 * The sentence explaining why a scheduled publish did not happen
 * (VIPPROD-1247).
 *
 * Every reason ends the same way in substance -- nothing published, nothing
 * lost -- because that is true whichever one fired. What differs is *why*,
 * and only the reasons an editor can actually meet in practice get their own
 * words; anything else falls through to a sentence that is still accurate,
 * if less specific.
 *
 * @param {Object} ctx The staging context.
 * @return {string} The sentence.
 */
function scheduleRefusedSentence( ctx ) {
	const when = ctx.scheduleRefused.scheduledForLabel;

	switch ( ctx.scheduleRefused.reason ) {
		case 'swpub_drift':
			return sprintf(
				/* translators: %s: the date and time these changes were due to publish. */
				__(
					'These changes were due to publish on %s. The published post changed first, so they were not published and nothing was overwritten.',
					'save-without-publish'
				),
				when
			);

		case 'actor':
			return sprintf(
				/* translators: %s: the date and time these changes were due to publish. */
				__(
					'These changes were due to publish on %s, but the person who scheduled them can no longer publish this post.',
					'save-without-publish'
				),
				when
			);

		case 'stranded':
		case 'not_published':
		case 'no_live_post':
			return sprintf(
				/* translators: %s: the date and time these changes were due to publish. */
				__(
					'These changes were due to publish on %s. The published post is no longer available to publish to.',
					'save-without-publish'
				),
				when
			);

		case 'disabled':
			return sprintf(
				/* translators: %s: the date and time these changes were due to publish. */
				__(
					'These changes were due to publish on %s. Staging was switched off at the time, so they were not published.',
					'save-without-publish'
				),
				when
			);

		case 'merge_stranded':
			return sprintf(
				/* translators: %s: the date and time these changes were due to publish. */
				__(
					'These changes were due to publish on %s, but a publish that did not finish is still in the way.',
					'save-without-publish'
				),
				when
			);

		default:
			return sprintf(
				/* translators: %s: the date and time these changes were due to publish. */
				__(
					'These changes were due to publish on %s and were not published.',
					'save-without-publish'
				),
				when
			);
	}
}

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
		const base = markup ?? statusSentence( ctx );
		const scheduled = scheduledSentence( ctx );

		/*
		 * Appended to the same notice rather than posted as a second one: it
		 * is the same fact about the same state, and two info notices
		 * stacked is how a screen stops being read. Escaped the same way
		 * everything else `linkedSentence()` interpolates is escaped, since
		 * the whole string is rendered as raw HTML whenever `markup` is set.
		 */
		const content = scheduled
			? `${ base } ${ markup ? escapeHTML( scheduled ) : scheduled }`
			: base;

		createNotice( ctx.stranded ? 'warning' : 'info', content, {
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
		} );

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
				 * "View Post" label, the permalink, and a new tab. Opened by
				 * hand (`toRead()`) rather than through the snackbar's own
				 * `openInNewTab`, which core's snackbar silently ignores
				 * below WordPress 7.0 (VIPPROD-753, F6) -- this way the tab
				 * opens the same way on every version this plugin supports.
				 */
				actions: ctx.liveView
					? [
							toRead(
								ctx.viewLabel ||
									__( 'View Post', 'save-without-publish' ),
								ctx.liveView
							),
					  ]
					: [],
			}
		);
	}, [ ctx.justPublished, ctx.liveView, ctx.viewLabel, createNotice ] );

	useEffect( () => {
		/*
		 * Suppressed when a scheduled run was refused for this exact drift:
		 * `swpub-schedule-refused` below says the more specific version of
		 * the same fact, with the time attached, and two notices about one
		 * change on the published post is not a second warning, it is the
		 * first one said twice.
		 */
		if (
			! ctx.isStaged ||
			! ctx.drifted ||
			ctx.stranded ||
			'swpub_drift' === ctx.scheduleRefused?.reason
		) {
			return;
		}

		/*
		 * This warns; it does not gate. The refusal that actually protects the
		 * live post is server-side, re-checked immediately before the merge
		 * writes (KTD15), because anything the browser decides can be skipped by
		 * not using the browser.
		 *
		 * The wording splits on `driftKind` (VIPPROD-752) the same way the
		 * merge's own refusal does: a change to title, content, or excerpt is
		 * something publishing could overwrite, and anything else provably
		 * is not, since the merge never writes it. `reviewPublishedHistory()`
		 * already renders nothing when the server withheld the history link
		 * for that same reason, so the action needs no branch of its own.
		 */
		createNotice(
			'warning',
			'other' === ctx.driftKind
				? __(
						'The published post was updated after these edits were staged. Its title, content, and excerpt are unchanged.',
						'save-without-publish'
				  )
				: __(
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

	useEffect( () => {
		if ( ! ctx.isStaged || ! ctx.scheduleRefused ) {
			return;
		}

		/*
		 * The drift case carries the review action the drift notice would
		 * have offered, since that notice is the one suppressed above --
		 * an editor who reads this and wants to see what changed still
		 * needs the way there. Every other reason has nothing to review:
		 * the published post did not move, something about the pair itself
		 * did.
		 */
		createNotice( 'warning', scheduleRefusedSentence( ctx ), {
			id: 'swpub-schedule-refused',
			isDismissible: false,
			actions:
				'swpub_drift' === ctx.scheduleRefused.reason
					? reviewPublishedHistory( ctx )
					: [],
		} );
	}, [ ctx, createNotice ] );

	return null;
}
