/**
 * Editor entry point.
 *
 * `wp-scripts` discovers `src/index.js` as the build entry by convention, so
 * per-feature modules live under `src/editor/` and are wired up from here.
 *
 * The relabelling registers a translation filter, so it runs before anything
 * core renders rather than from inside a component.
 *
 * The stylesheet is imported here because `wp-scripts` collects styles from the
 * entry's module graph into one `build/style-index.css`.
 */

import './editor/panel-rows.scss';
import './editor/revisions-screen.scss';

import { registerForkNavigation } from './editor/fork-navigation';
import { registerEditorPlugin } from './editor/plugin';
import { registerRelabel } from './editor/relabel';

registerForkNavigation();
registerRelabel();
registerEditorPlugin();
