/**
 * The Summary panel's Status row, on a staged copy.
 *
 * Core resolves that row's value from a fixed list -- draft, future, pending,
 * private, publish, trash -- and a staged copy is none of them, so core's own
 * row renders blank: an empty control announced as "Change status: " to a screen
 * reader, or an empty read-only value for an editor who cannot publish. The row
 * is not wrong, it is silent, and the one document property this plugin exists
 * to change is the one the panel declines to name.
 *
 * This used to be fixed by rewriting core's row in place, with a MutationObserver
 * to survive its re-renders. It is a fill now (R53, KTD37): the row is ours, it
 * is rendered through core's own extensibility slot, and core's row is hidden by
 * a stylesheet rule scoped to this fill's presence. The scoping is the whole
 * safety property -- the stylesheet loads whether or not the script does, so an
 * unscoped hide would delete core's Status row outright on exactly the page load
 * where this fill never rendered.
 *
 * The status is also the link to what is staged, the way every other value in
 * this panel is the control for the thing it names: "Revisions" is a count you
 * click, "Slug" is the slug you click. One word, one control, and nothing in the
 * row that core would not have put there.
 *
 * Including the icon and the place. Core prints an icon before every status it
 * names and prints Status first in the panel, so a row that skipped either would
 * be a row an editor has to look for. The icon is `staged-icon.js`; the place is
 * one `order` declaration in `panel-rows.scss`, because core renders every fill
 * after its own rows.
 */

import { Button } from '@wordpress/components';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';

import { context } from './context';
import { reviewStaged, useReviewUrl } from './routes';
import { staged as stagedIcon } from './staged-icon';

/**
 * Renders the row.
 *
 * @return {React.ReactNode} The row, or nothing anywhere but a staged copy.
 */
export function StagedStatusRow() {
	const ctx = context();

	// Before the bail, because a hook cannot be called conditionally, and
	// because this is the row that goes stale: it is the one on the copy being
	// saved.
	const reviewUrl = useReviewUrl( ctx );

	if ( ! ctx.isStaged ) {
		return null;
	}

	const label = __( 'Staged', 'save-without-publish' );
	const [ review ] = reviewStaged( ctx, reviewUrl );

	return (
		<PluginPostStatusInfo className="swpub-status-row">
			<div className="editor-post-panel__row-label">
				{ __( 'Status', 'save-without-publish' ) }
			</div>
			<div className="editor-post-panel__row-control">
				{ review ? (
					<Button
						className="swpub-status-row__button"
						href={ review.url }
						icon={ stagedIcon }
						onClick={ review.onClick }
						text={ label }
						/*
						 * The visible word is the state; the accessible name adds
						 * where the link goes, and keeps the visible word inside
						 * it so speech input can still say "Staged" and hit this
						 * control.
						 */
						aria-label={ sprintf(
							/* translators: %s: the post's status, always "Staged" here. */
							__(
								'%s: review staged changes',
								'save-without-publish'
							),
							label
						) }
						variant="tertiary"
						size="compact"
					/>
				) : (
					// Nothing comparable staged yet, so the state is stated and
					// not offered: a link to a comparison that does not exist
					// yet is worse than the word on its own.
					<span className="swpub-status-row__value">{ label }</span>
				) }
			</div>
		</PluginPostStatusInfo>
	);
}
