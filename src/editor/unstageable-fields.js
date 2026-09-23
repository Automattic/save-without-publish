/**
 * Takes the controls for fields that cannot be staged off a staged copy's
 * screen (VIPPROD-1228).
 *
 * Ten controls sit in the Summary panel of an ordinary published post --
 * Publish, Slug, Author, Template, Discussion, Format, Featured image,
 * Categories, Tags, Page Attributes -- and every one of them is refused on a
 * staged copy (`Field_Lock`). Left on screen they look live, several show a
 * value nobody staged (the copy's own internal slug, comments deliberately
 * closed at fork), and the one action available for any of them -- change it
 * on the published post -- is not offered anywhere near the control that
 * invites the edit. This takes them off the screen instead, the same way
 * `existing-staged-copy.js` already takes the excerpt off the *published*
 * post's screen while a copy exists.
 *
 * Two mechanisms, because core gates some of these by a panel name and not
 * others:
 *
 * `removeEditorPanel()` covers Featured image, Discussion, Page Attributes,
 * and every taxonomy panel -- public, stable, per page load, and (unlike
 * `toggleEditorPanelEnabled()`) an in-memory action rather than a written
 * preference, so hiding Categories here can never hide it on the next
 * ordinary post this editor opens (`REMOVE_PANEL` in
 * `@wordpress/editor`'s reducer, not `core/preferences`). The taxonomy list
 * comes from the server's own enumeration (`ctx.lockedTaxonomies`,
 * `Field_Lock::locked_taxonomies()`), so a site's or plugin's own taxonomy is
 * hidden the same way core's two are.
 *
 * A stylesheet rule (`panel-rows.scss`) covers Publish, Slug, Template,
 * Author, and Format, which core does not gate by panel name at all. It is
 * scoped to this plugin's own Status row the same way the existing rule that
 * hides core's Status row is (R53): if the script never ran, nothing is
 * hidden. Two of the five rows -- Author, Format -- carry no distinguishing
 * class in the rendered DOM (checked against the live markup, not guessed
 * from source), so the stylesheet keys on the toggle button's own
 * `aria-label` for those two, which is translated text and the weaker of the
 * two selector kinds already in this codebase. The canary below watches all
 * three of the rows this matters most for.
 */

import { useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { store as editorStore } from '@wordpress/editor';

import { verify } from './canary';
import { context } from './context';

/**
 * Panels core gates by name, that this row's context does not otherwise
 * cover.
 */
const REMOVED_PANELS = [
	'featured-image',
	'discussion-panel',
	'page-attributes',
];

/**
 * The panel names for every taxonomy the server says is locked.
 *
 * @param {Object} ctx The staging context.
 * @return {string[]} Panel names.
 */
function taxonomyPanels( ctx ) {
	return ( ctx.lockedTaxonomies || [] ).map(
		( taxonomy ) => `taxonomy-panel-${ taxonomy }`
	);
}

/**
 * Watches the rows the stylesheet's weaker selectors hide, and the slug this
 * ticket is about, and discloses it if any of them settle visible.
 *
 * Failure is graceful either way: a row that stays on screen is still
 * refused at save, and the refusal now offers to undo the change
 * (`fork-navigation.js`'s `refuse()`).
 *
 * @return {void}
 */
function watchHiddenRows() {
	const selectors = [
		'.editor-post-url__panel-dropdown',
		'[aria-label^="Change author:"]',
		'[aria-label^="Change format:"]',
	];

	verify( 'unstageable-rows-hidden', () => {
		const found = selectors
			.map( ( selector ) => document.querySelector( selector ) )
			.filter( Boolean );

		if ( ! found.length ) {
			return null;
		}

		return found.every(
			( el ) =>
				! (
					el.offsetWidth ||
					el.offsetHeight ||
					el.getClientRects().length
				)
		);
	} );
}

/**
 * Renders nothing; removes panels and watches the rest.
 *
 * Active on a stranded copy too: those fields are no more stageable there
 * than on a healthy one, and the notice that explains why the copy is
 * stranded already says nothing here can be published to.
 *
 * @return {null} Renders nothing.
 */
export function UnstageableFields() {
	const ctx = context();
	const { removeEditorPanel } = useDispatch( editorStore );

	const locked = ctx.isStaged;

	useEffect( () => {
		if ( ! locked || ! removeEditorPanel ) {
			return;
		}

		[ ...REMOVED_PANELS, ...taxonomyPanels( ctx ) ].forEach( ( panel ) =>
			removeEditorPanel( panel )
		);

		watchHiddenRows();
		// `ctx` rather than `ctx.lockedTaxonomies`: the context object is a
		// stable reference for the whole page load (`window.swpubEditor`),
		// so this still runs exactly once.
	}, [ locked, removeEditorPanel, ctx ] );

	return null;
}
