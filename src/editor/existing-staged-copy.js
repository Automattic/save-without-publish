/**
 * What a published post does when someone has already staged a change to it.
 *
 * Three things: it says so, it stops the three fields the staged copy owns
 * from being typed into (R55), and it disables saving while one of them is
 * dirty anyway (VIPPROD-1171).
 *
 * That third one exists because the first two do not cover the whole screen.
 * The canvas lock below has no equivalent for the title -- core offers no
 * read-only path for it -- and the excerpt has neither. A save carrying either
 * still reaches the write path, which refuses it (`swpub_live_locked`), so
 * without this the primary button would sit there promising a save that
 * cannot succeed. Locking it with core's own `lockPostSaving` -- the same
 * mechanism `PostPublishButton` already reads to grey itself out -- means the
 * button simply cannot be clicked while that is true, rather than being
 * clicked and refused. It is scoped to the three fields, not to the whole
 * screen: a category or a featured image saved from here still applies (R42),
 * so locking every time anything is dirty would refuse a save this screen is
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
 * The title and excerpt are not covered, because core offers nothing equivalent
 * for them -- `PostTitle` has no read-only path -- and a second DOM hack to
 * reach them would cost more than it buys. They are refused at the save instead,
 * in `fork-navigation.js`, which is where the notice below sends the reader
 * anyway. The client lock has always been the courtesy and `Write_Guard` the
 * boundary.
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
 * Posts the notice, locks the canvas, and disables saving where it would be
 * refused anyway.
 *
 * @return {null} Renders nothing.
 */
export function ExistingStagedCopyNotice() {
	const ctx = context();
	const { createNotice } = useDispatch( noticesStore );

	useLockedCanvas( ctx );
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
