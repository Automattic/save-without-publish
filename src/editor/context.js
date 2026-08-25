/**
 * The staging context the server attached to the page.
 *
 * Every editor module reads the same object, so it is read through one function
 * rather than each module inventing its own inert default.
 */

/**
 * Reads the context.
 *
 * @return {Object} The context, or an inert default.
 */
export function context() {
	return window.swpubEditor || { isStaged: false, stagedCopyId: 0 };
}
