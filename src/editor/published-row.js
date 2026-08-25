/**
 * The Summary panel's own row for the post this copy stages a change to.
 *
 * The route used to sit in the staged notice, next to "Review staged changes".
 * A notice is where a state gets announced, not where a document's standing
 * facts are looked up, and "where does this staged copy point" is a standing
 * fact: it is true for as long as the copy exists, like its author or its slug.
 *
 * `PluginPostStatusInfo` puts it in the list where an editor already reads those
 * facts, using core's own row markup so the label column lines up with Status,
 * Publish, Slug, Author and Revisions. It sits directly under Status, because
 * the two rows answer one question between them: what this post is, and what it
 * is a change to.
 *
 * The destination is the published post's own editor. The notice above already
 * links the front end, inside the sentence that names what readers are seeing,
 * so the row is the other errand: the screen where the staged copy is announced,
 * where the way back to it is, and where the fields staging cannot carry are
 * changed. In a new tab, because the trip must not cost the staged edits sitting
 * unsaved in this one.
 *
 * No capability check of its own. Editing a staged copy maps to editing the post
 * it stages (`Capabilities::map_staged_copy_caps`), so anyone who can open this
 * screen can open that one, and `liveEdit` is empty only where the plugin could
 * not name the published post at all.
 */

import { Button } from '@wordpress/components';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

import { context } from './context';

/**
 * Renders the row.
 *
 * @return {React.ReactNode} The row, or nothing when there is no published post
 *                           to point at.
 */
export function PublishedRow() {
	const ctx = context();

	// Only on a staged copy, and only while the post it stages against is still
	// published. A stranded copy has nothing on the other end, and the notice
	// already explains why.
	if ( ! ctx.isStaged || ! ctx.liveEdit || ctx.stranded ) {
		return null;
	}

	return (
		<PluginPostStatusInfo className="swpub-published-row">
			<div className="editor-post-panel__row-label">
				{ __( 'Published post', 'save-without-publish' ) }
			</div>
			<div className="editor-post-panel__row-control">
				<Button
					href={ ctx.liveEdit }
					target="_blank"
					rel="noopener noreferrer"
					/*
					 * The same control core's own rows use, so the value text
					 * lines up with Slug, Author and Template: core styles
					 * `.editor-post-panel__row-control .components-button`, and
					 * a bare link sits 12px to their left.
					 */
					text={ __( 'Edit', 'save-without-publish' ) }
					aria-label={ __(
						'Edit the published post',
						'save-without-publish'
					) }
					variant="tertiary"
					size="compact"
				/>
			</div>
		</PluginPostStatusInfo>
	);
}
