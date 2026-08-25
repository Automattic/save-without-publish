/**
 * The editor plugin registration.
 *
 * One registration renders every staged-state surface: the notices that carry the
 * routes between the two copies, the Summary panel's staged row, and the publish
 * takeover.
 */

import { registerPlugin } from '@wordpress/plugins';

import { DiscardStaged } from './discard';
import { ExistingStagedCopyNotice } from './existing-staged-copy';
import { PublishChanges } from './publish-changes';
import { PublishedRow } from './published-row';
import { StageChanges } from './stage-changes';
import { StagedNotices } from './staged-notices';
import { StagedRow } from './staged-row';
import { StagedStatusRow } from './status-row';

/**
 * Registers the editor plugin.
 *
 * The Summary rows are listed in the order they should read: the document's
 * status first, then what is staged against it, then where the published post
 * is, and the destructive control last.
 *
 * @return {void}
 */
export function registerEditorPlugin() {
	registerPlugin( 'save-without-publish', {
		render: () => (
			<>
				<StagedNotices />
				<StagedStatusRow />
				<StageChanges />
				<StagedRow />
				<PublishedRow />
				<DiscardStaged />
				<PublishChanges />
				<ExistingStagedCopyNotice />
			</>
		),
	} );
}
