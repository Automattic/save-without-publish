/**
 * What "dirty" means to the pieces that key off it.
 *
 * Three surfaces ask the same question from different places -- the request
 * middleware, to decide whether a save should stage; the primary button's
 * label, to say so before the click; and the save lock, to disable it -- so the
 * fields a staged copy can hold, and which of them a save on a published post
 * currently carries, live once here rather than three times drifting apart.
 */

/**
 * The fields a staged copy can hold (R29), as the REST post resource names them.
 */
export const STAGED_FIELDS = [ 'title', 'content', 'excerpt' ];

/**
 * The data registry, or null on a screen that has none.
 *
 * @return {Object|null} The registry.
 */
function registry() {
	return ( window.wp && window.wp.data ) || null;
}

/**
 * The fields this save actually changes, or null when that cannot be answered.
 *
 * Read from the editor's own edit set rather than from the outgoing body, which
 * looks like the same question and is not: `savePost` appends the serialized
 * content to every payload it builds, changed or not, so a save that only ticked
 * a category still carries the post's whole content. Treating that as a text
 * change would send a term change to a route that cannot hold one.
 *
 * The edit set is safe to read here because core reconciles it first: `savePost`
 * writes the content back through `editEntityRecord`, which drops any edit equal
 * to the value already stored. So by the time this is asked, `content` is in
 * the set only if it really differs.
 *
 * Answering null rather than an empty list is deliberate. An empty list is "this
 * save changes nothing stageable"; null is "there is no editor here to ask", and
 * the two must not take the same branch -- the second belongs to the server.
 *
 * @param {number} id The post being asked about.
 * @return {string[]|null} Field names, or null.
 */
export function dirtyFields( id ) {
	const data = registry();
	const editor = data && data.select( 'core/editor' );
	const records = data && data.select( 'core' );
	const post = editor && editor.getCurrentPost && editor.getCurrentPost();

	if ( ! records || ! post || post.id !== id || 'publish' !== post.status ) {
		return null;
	}

	const edits =
		records.getEntityRecordNonTransientEdits( 'postType', post.type, id ) ||
		{};

	return Object.keys( edits ).filter(
		( key ) => 'id' !== key && undefined !== edits[ key ]
	);
}
