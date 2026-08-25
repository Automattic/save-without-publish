/**
 * The Summary panel's own row for staged state, on the published post.
 *
 * The notices carry the same route, but a notice is an interruption: it is read
 * once and then scrolled past. Whether a post has a staged change is a standing
 * property of the document, like its author, its slug, or how many revisions it
 * has, so it belongs in the list where an editor already looks those up.
 *
 * `PluginPostStatusInfo` is core's slot for exactly that, and its fills render
 * as the last rows of the Summary panel's own list -- below Format, above "Move
 * to trash" -- so the row is rendered with core's row markup rather than markup
 * of our own. Reusing `.editor-post-panel__row` means the label column lines up
 * with Status, Publish, Slug, Author and Revisions instead of nearly lining up.
 *
 * On the published post only. A staged copy says the same thing in its own
 * Status row (`status-row.js`), which is a fill too now and so is there for
 * everyone -- including the editor who cannot publish, who used to get this row
 * instead because core would not render a Status row for them. Keeping both
 * would show that editor two Summary rows saying one thing.
 */

import { Button } from '@wordpress/components';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

import { context } from './context';
import { reviewStaged } from './routes';

/**
 * Renders the row.
 *
 * @return {React.ReactNode} The row, or nothing when there is nothing to review.
 */
export function StagedRow() {
	const ctx = context();

	if ( ctx.isStaged ) {
		return null;
	}

	const [ review ] = reviewStaged( ctx );

	// Nothing staged, or nothing comparable staged yet. A row reading "Staged
	// changes" with no destination is worse than no row: it names a state and
	// then refuses to explain it.
	if ( ! review || ! ctx.stagedCopyId ) {
		return null;
	}

	return (
		<PluginPostStatusInfo className="swpub-staged-row">
			<div className="editor-post-panel__row-label">
				{ __( 'Staged changes', 'save-without-publish' ) }
			</div>
			<div className="editor-post-panel__row-control">
				<Button
					className="swpub-staged-row__button"
					href={ review.url }
					onClick={ review.onClick }
					/*
					 * The shape core's own Summary rows use: a noun label and a
					 * terse value, the way "Revisions" is followed by a count
					 * and nothing else. The row label already supplies the noun,
					 * so the control does not repeat it. The accessible name
					 * keeps the full sentence, so the control is announced by
					 * the same name it has everywhere else it is offered.
					 */
					text={ __( 'Review', 'save-without-publish' ) }
					aria-label={ review.label }
					variant="tertiary"
					size="compact"
				/>
			</div>
		</PluginPostStatusInfo>
	);
}
