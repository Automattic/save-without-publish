/**
 * What a published post does when someone has already staged a change to it.
 *
 * Two things: it says so, and it stops the three fields the staged copy owns
 * from being typed into (R55).
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
import { useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import { context } from './context';
import { editStaged } from './routes';

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
 * Posts the notice and locks the canvas.
 *
 * @return {null} Renders nothing.
 */
export function ExistingStagedCopyNotice() {
	const ctx = context();
	const { createNotice } = useDispatch( noticesStore );

	useLockedCanvas( ctx );

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
