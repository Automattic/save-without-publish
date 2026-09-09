/**
 * What a published post does when someone has already staged a change to it.
 *
 * Four things: it says so, it stops the canvas from being typed into (R55),
 * it stops the title the same way, and it disables saving while a locked
 * field is dirty anyway (VIPPROD-1171, VIPPROD-1121).
 *
 * The save lock exists because the other three do not cover every way a
 * locked field can end up dirty. A save carrying one still reaches the write
 * path, which refuses it (`swpub_live_locked`), so without the lock the
 * primary button would sit there promising a save that cannot succeed.
 * Locking it with core's own `lockPostSaving` -- the same mechanism
 * `PostPublishButton` already reads to grey itself out -- means the button
 * simply cannot be clicked while that is true, rather than being clicked and
 * refused. It is scoped to the three fields, not to the whole screen: a
 * category or a featured image saved from here still applies (R42), so
 * locking every time anything is dirty would refuse a save this screen is
 * supposed to make.
 *
 * A notice rather than a sidebar panel, and deliberately: the panel this
 * replaced sat collapsed below "Move to trash", under Categories and Tags, so
 * the only warning that a second copy of this post existed was one an editor
 * had to go looking for. This is document-level state -- three of the fields on
 * screen will not save -- and it has to be legible before the first keystroke,
 * not after the save.
 *
 * A warning rather than an info, for the same reason. Info is the colour core
 * uses for something worth knowing; this is a screen where the title, content,
 * and excerpt do not do what typing into them usually does. An editor who reads
 * past it loses work, which is the line between the two.
 *
 * The sentence used to say the next save was "added to those changes", which was
 * never true. The save was composed against the published words, so it replaced
 * the staged ones. Nothing is added to a staged copy from here; the copy is
 * edited on the copy.
 */

import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { store as editorStore } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import { verify } from './canary';
import { context } from './context';
import { STAGED_FIELDS } from './edits';
import { editStaged } from './routes';

/**
 * The save lock's key.
 *
 * Namespaced for the same reason `stage.js`'s autosave lock is: core keys
 * locks by string and every plugin's locks share one register.
 */
const SAVE_LOCK = 'swpub/live-locked';

/**
 * Says who staged the change, when that is known.
 *
 * @param {Object} ctx The staging context.
 * @return {string} The sentence.
 */
function who( ctx ) {
	return ctx.stagedBy
		? sprintf(
				/* translators: %s: display name of the editor who staged the change. */
				__(
					'This post has staged changes by %s, waiting to be published.',
					'save-without-publish'
				),
				ctx.stagedBy
		  )
		: __(
				'This post has staged changes waiting to be published.',
				'save-without-publish'
		  );
}

/**
 * Makes the canvas read-only while a staged copy holds this post's next change.
 *
 * `setBlockEditingMode` is core's own stable switch for this, the one the site
 * editor uses on template parts, so there is no selector here to rot. It is set
 * on the root client ID, which covers every block in the tree including ones
 * added later.
 *
 * It is unset on the way out rather than left standing. The editor keeps its
 * block-editor store across a client-side navigation to another post, and a
 * disabled root that outlived its reason would be an unexplained read-only
 * canvas on a post with nothing staged against it at all.
 *
 * The title is covered separately, in `useLockedTitle()` below: it is not a
 * block, so this switch does not reach it. The client lock has always been
 * the courtesy and `Write_Guard` the boundary.
 *
 * @param {Object} ctx The staging context.
 * @return {void}
 */
function useLockedCanvas( ctx ) {
	const { setBlockEditingMode, unsetBlockEditingMode } =
		useDispatch( blockEditorStore );

	const locked = ! ctx.isStaged && !! ctx.stagedCopyId;

	useEffect( () => {
		if ( ! locked || ! setBlockEditingMode ) {
			return undefined;
		}

		setBlockEditingMode( '', 'disabled' );

		return () => unsetBlockEditingMode( '' );
	}, [ locked, setBlockEditingMode, unsetBlockEditingMode ] );
}

/**
 * Disables saving while a write from here would be refused (VIPPROD-1171).
 *
 * Reads the edit set directly rather than through `edits.js`'s `dirtyFields()`:
 * that function answers null off a screen it cannot read, which the request
 * middleware treats as "assume the worst" because refusing a save it cannot
 * see is the safe failure. A lock has the opposite safe failure -- disabling a
 * button it cannot judge would freeze saving on a state that was never
 * reached -- so this asks `useSelect` directly and defaults to unlocked.
 *
 * Content is included alongside title and excerpt on purpose, even though the
 * canvas above is already read-only: this runs on every render, including the
 * one where the canvas lock has not taken yet, and a save slipping through
 * that gap is exactly what the write path's own field lock exists to catch on
 * the wire. Catching it here first means the button never offers the click.
 *
 * @param {Object} ctx The staging context.
 * @return {void}
 */
function useLockedSave( ctx ) {
	const { lockPostSaving, unlockPostSaving } = useDispatch( editorStore );

	const lockedFieldDirty = useSelect(
		( select ) => {
			if ( ctx.isStaged || ! ctx.stagedCopyId || ! ctx.liveId ) {
				return false;
			}

			const post = select( editorStore ).getCurrentPost();

			if ( ! post || post.id !== ctx.liveId ) {
				return false;
			}

			const edits =
				select( 'core' ).getEntityRecordNonTransientEdits(
					'postType',
					post.type,
					post.id
				) || {};

			return STAGED_FIELDS.some(
				( field ) => undefined !== edits[ field ]
			);
		},
		[ ctx ]
	);

	useEffect( () => {
		if ( ! lockedFieldDirty ) {
			return undefined;
		}

		lockPostSaving( SAVE_LOCK );

		return () => unlockPostSaving( SAVE_LOCK );
	}, [ lockedFieldDirty, lockPostSaving, unlockPostSaving ] );
}

/**
 * The title's selector, or a broken one under test.
 *
 * Test-only, and deliberately unreachable from the server -- the same shape as
 * `publishButtonSelector()` in `publish-changes.js`, for the same drill.
 *
 * @return {string} The selector to use.
 */
function titleSelector() {
	return true === window.swpubTestBreakTitleSelector
		? '.swpub-title-not-found'
		: '.editor-post-title__input';
}

/**
 * Every document the title could be rendered in.
 *
 * Ordinarily one: the canvas iframe's own document. Some configurations --
 * classic themes, meta boxes present -- run the editor un-iframed, and then
 * the title is in the page's own document instead. Same-origin either way, so
 * the frame's document is readable; the try/catch is for the moment before it
 * has one.
 *
 * @return {Document[]} Every document worth searching.
 */
function editorDocuments() {
	const docs = [ document ];

	document
		.querySelectorAll( 'iframe[name="editor-canvas"]' )
		.forEach( ( frame ) => {
			try {
				if ( frame.contentDocument ) {
					docs.push( frame.contentDocument );
				}
			} catch {
				// Not same-origin, or not ready yet. Either way, nothing here
				// to search.
			}
		} );

	return docs;
}

/**
 * Makes the title read-only while a staged copy holds this post's next
 * change (R55, VIPPROD-1121).
 *
 * `PostTitle` has no public read-only prop on any version this plugin
 * supports. WP 7.0 added a private one -- the `h1` core renders is not
 * `contentEditable` while a content-only section is being edited -- but it is
 * unlocked (`unlock()` from `@wordpress/private-apis`) and this plugin does
 * not reach for private APIs. So this is a selector, the one other surface
 * that can rot (`relabel.js`'s primary-button label is the other), and it
 * carries the same disclosure: `canary.js`'s `verify()`, under the id
 * `title-lock`.
 *
 * `contentEditable = 'false'` rather than `inert`, which is how core disables
 * a locked block: `inert` removes an element from the accessibility tree, and
 * the title is the one thing on this screen a screen-reader user most needs
 * read. A non-editable `h1` with no `tabindex` is not focusable and takes no
 * `input`, `paste`, or `keydown` -- read-only in fact, not just in name.
 *
 * React does not fight this. `PostTitle` renders a constant `contentEditable:
 * true`, and React only rewrites a DOM property when the value it is asked to
 * render actually changes, so an unrelated re-render leaves the override
 * standing -- the same reason `relabelPublish()`'s overwritten button text
 * survives core's own re-renders. The one thing that does change the prop's
 * value is the private, content-only-section state above, which is why this
 * is watched rather than applied once: a `MutationObserver` on the
 * `contenteditable` attribute puts the lock back the instant core's own
 * write clears it.
 *
 * The canvas iframe, and the title inside it, do not exist yet when this
 * effect first runs, so it waits for them the way `relabelPublish()` waits
 * for `.editor-header`: an uncapped `requestAnimationFrame` loop, which never
 * spins forever in practice because a post type without title support bails
 * out below before the loop ever starts.
 *
 * @param {Object} ctx The staging context.
 * @return {void}
 */
function useLockedTitle( ctx ) {
	const locked = useSelect(
		( select ) => {
			if ( ctx.isStaged || ! ctx.stagedCopyId || ! ctx.liveId ) {
				return false;
			}

			const postType = select( editorStore ).getCurrentPostType();
			const postTypeObject =
				postType && select( 'core' ).getPostType( postType );

			// A type that never renders a title has nothing here to lock, and
			// nothing for the canary to judge. Undefined (not yet fetched)
			// falls through to locked, which is the safe default while this
			// cannot yet be answered.
			if ( postTypeObject && false === postTypeObject.supports?.title ) {
				return false;
			}

			return true;
		},
		[ ctx ]
	);

	useEffect( () => {
		if ( ! locked ) {
			return undefined;
		}

		const touched = new Set();
		const observers = [];
		let frame = null;
		let unsubscribe = null;

		function lock( el ) {
			if ( 'false' !== el.contentEditable ) {
				el.contentEditable = 'false';
			}

			el.setAttribute( 'aria-readonly', 'true' );
			el.style.cursor = 'default';
			touched.add( el );
		}

		function apply() {
			editorDocuments().forEach( ( doc ) => {
				doc.querySelectorAll( titleSelector() ).forEach( lock );
			} );
		}

		function watch() {
			const docs = editorDocuments();
			const found = docs.some( ( doc ) =>
				doc.querySelector( titleSelector() )
			);

			if ( ! found ) {
				frame = window.requestAnimationFrame( watch );

				return;
			}

			docs.forEach( ( doc ) => {
				const observer = new window.MutationObserver( apply );

				observer.observe( doc.body, {
					childList: true,
					subtree: true,
					attributes: true,
					attributeFilter: [ 'contenteditable' ],
				} );

				observers.push( observer );
			} );

			const data = window.wp && window.wp.data;

			if ( data && data.subscribe ) {
				unsubscribe = data.subscribe( apply );
			}

			apply();
		}

		watch();

		verify( 'title-lock', () => {
			const docs = editorDocuments();
			const mounted = docs.some( ( doc ) =>
				doc.querySelector( '.block-editor-block-list__layout' )
			);

			if ( ! mounted ) {
				// Nothing has mounted yet, so there is nothing to be wrong
				// about.
				return null;
			}

			const titles = docs.flatMap( ( doc ) =>
				Array.from( doc.querySelectorAll( titleSelector() ) )
			);

			if ( ! titles.length ) {
				return false;
			}

			return titles.every( ( el ) => 'false' === el.contentEditable );
		} );

		return () => {
			if ( null !== frame ) {
				window.cancelAnimationFrame( frame );
			}

			observers.forEach( ( observer ) => observer.disconnect() );

			if ( unsubscribe ) {
				unsubscribe();
			}

			touched.forEach( ( el ) => {
				el.contentEditable = 'true';
				el.removeAttribute( 'aria-readonly' );
				el.style.cursor = '';
			} );
		};
	}, [ locked ] );
}

/**
 * Posts the notice, locks the canvas and the title, and disables saving
 * where it would be refused anyway.
 *
 * @return {null} Renders nothing.
 */
export function ExistingStagedCopyNotice() {
	const ctx = context();
	const { createNotice } = useDispatch( noticesStore );

	useLockedCanvas( ctx );
	useLockedTitle( ctx );
	useLockedSave( ctx );

	useEffect( () => {
		if ( ctx.isStaged || ! ctx.stagedCopyId ) {
			return;
		}

		createNotice(
			'warning',
			`${ who( ctx ) } ${ __(
				'The title, content, and excerpt are locked here until those changes are published or discarded. Categories, tags, and the featured image still save normally.',
				'save-without-publish'
			) }`,
			{
				id: 'swpub-existing',

				// Not dismissible: it describes which fields on this screen will
				// not save, which stays true for as long as the staged copy
				// exists. A notice you can dismiss is one an editor can be
				// looking straight past at the moment it matters.
				isDismissible: false,
				// Reviewing is the Summary's "Staged / Review changes" row, on
				// this screen as much as on the staged copy. What only this
				// screen can offer is the way over to that copy.
				actions: editStaged( ctx ),
			}
		);
	}, [ ctx, createNotice ] );

	return null;
}
