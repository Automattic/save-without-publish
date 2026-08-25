/**
 * The Staged status icon.
 *
 * Core draws all five of its post statuses as one 24px ring with a different
 * mark inside it: a check for Published, a half-filled disc for Draft, a clock
 * hand for Scheduled, a slash for Private. The ring is the family, and the mark
 * is which member of it you are looking at -- so a status that borrowed one of
 * those five marks would not read as a sixth state, it would read as that one.
 *
 * This is core's ring, to the path, with a mark of its own: an arrow pointing at
 * the act the state is waiting for. Drawn as a single path with `evenodd` the
 * way core's are, so the mark is filled by the same rule that hollows the ring.
 */

import { Path, SVG } from '@wordpress/primitives';

export const staged = (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path
			fillRule="evenodd"
			clipRule="evenodd"
			d="M12 18.5a6.5 6.5 0 1 1 0-13 6.5 6.5 0 0 1 0 13ZM4 12a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm8-4.5 3.5 4h-2.25v4h-2.5v-4H8.5l3.5-4Z"
		/>
	</SVG>
);
