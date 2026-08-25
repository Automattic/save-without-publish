/**
 * Discarding a staged copy, from the Summary panel.
 *
 * "Move to trash" is the right instinct and the wrong words: a staged copy
 * cannot be trashed. `Transitions::discard_trashed_staged_copy()` already
 * unlinks and force-deletes anything that reaches the trash, because a staged
 * copy sitting there holds unreviewed content and is still discoverable (R14).
 *
 * This used to be a capture-phase click intercept on core's trash button. It is
 * a fill now (R53, KTD37): the control is ours, it is verb-first and destructive
 * the way core's own is, and it links the same nonced confirmation the posts
 * list and the classic editor link. That screen names the post and says the
 * change cannot be restored, which core's "move to the trash" dialog does not.
 *
 * Core's own trash button is hidden by a stylesheet rule scoped to this fill's
 * presence, never unconditionally: the stylesheet is enqueued whether or not the
 * script runs, so an unscoped hide would take core's control away on exactly the
 * page load where this one never rendered. When that happens the button comes
 * back, renamed by the translation filter in `relabel.js` -- ugly, honest, and
 * it still says what it will actually do.
 */

import { Button } from '@wordpress/components';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

import { context } from './context';

/**
 * Renders the control.
 *
 * @return {React.ReactNode} The control, or nothing where discarding is not
 *                           this reader's to do.
 */
export function DiscardStaged() {
	const ctx = context();

	if ( ! ctx.isStaged || ! ctx.discardUrl ) {
		return null;
	}

	return (
		<PluginPostStatusInfo className="swpub-discard-row">
			<Button
				className="swpub-discard-row__button"
				href={ ctx.discardUrl }
				isDestructive
				variant="secondary"
				__next40pxDefaultSize
				text={ __( 'Discard staged changes', 'save-without-publish' ) }
			/>
		</PluginPostStatusInfo>
	);
}
