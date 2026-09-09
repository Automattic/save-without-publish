/**
 * How the block editor saves a change that is going to be staged.
 *
 * There are two layers here, and the order matters.
 *
 * The first is the protocol (R49, KTD34). Before a save leaves the editor, this
 * middleware asks whether it is going to stage, and if it is, sends it to the
 * staging route instead of the post endpoint. That route answers 200 with the
 * staged copy's ID, so a staged save is a success to everything that watches
 * requests -- the browser's network panel, a proxy, an error tracker, a log.
 *
 * The second is the backstop. The server still redirects a save that reaches the
 * post endpoint anyway, and still answers `swpub_staged` with HTTP 409, because
 * the protocol above is JavaScript and JavaScript that did not load must not be
 * the difference between staging and publishing. That path is now instrumented
 * rather than routine: the server fires `swpub_staged_via_backstop`, and the end
 * -to-end suite fails if it is ever heard on a path a working editor takes.
 *
 * Either way the write succeeded, so either way the editor arrives on the staged
 * copy and is told so. Navigation is the reconciliation: handing the entity
 * store a record whose ID it never asked for leaves the post dirty after a
 * successful write, which arms the unload guard and cancels the move
 * (`includes/class-fork.php` carries the same note on the server side).
 */

import apiFetch from '@wordpress/api-fetch';
import { doAction } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';

import { context } from './context';
import { STAGED_FIELDS, dirtyFields } from './edits';
import { clearPendingEdits } from './pending-edits';
import { REPAIR_ACTION } from './publish-changes';
import { editStaged } from './routes';
import { stageChanges } from './stage';

const STAGED_CODE = 'swpub_staged';

/**
 * The server's refusal when a write would change something a staged copy cannot
 * hold. Documented contract, not an incident identifier -- see
 * `includes/class-field-lock.php`.
 */
const LOCKED_CODE = 'swpub_field_locked';

/**
 * Fields a staging save drops rather than refuses.
 *
 * Only `status`, and only because the editor writes it itself. Core's primary
 * button adds a status to every save it makes -- for someone who cannot publish
 * this post that status is `pending`, which nobody asked for and which arrives
 * on a save whose subject is the words. The server has always dropped it on this
 * path: neither the fork nor the staging route will carry a status onto a staged
 * copy. Refusing it here would mean the primary button refuses every save by the
 * one person staging exists for.
 *
 * It is dropped from a save that stages and from one that does not, because the
 * reason is the same either way and only one of them was ever covered. A save
 * carrying nothing but a category goes to the published post, as it should --
 * and it was carrying that `pending` with it, so ticking a category and pressing
 * the primary button unpublished the post. Nobody asked for that either, and it
 * changes what the published post serves more completely than publishing the
 * words would have.
 */
const DROPPED_FIELDS = [ 'status' ];

/**
 * Where the backstop leaves word that the save it just failed actually worked.
 *
 * Per tab and read once, so it cannot outlive the navigation that set it.
 */
const ARRIVAL_KEY = 'swpub-staged-arrival';

/**
 * Names for the fields a save may not carry alongside a staged change.
 *
 * The refusal has to say what to undo, and an editor does not know a REST
 * parameter by name. Anything unmapped falls back to the parameter itself, which
 * is worse but still true.
 *
 * @return {Object} Parameter name to label.
 */
const fieldLabels = () => ( {
	author: __( 'The author', 'save-without-publish' ),
	categories: __( 'Categories', 'save-without-publish' ),
	comment_status: __( 'Comments', 'save-without-publish' ),
	date: __( 'The publish date', 'save-without-publish' ),
	date_gmt: __( 'The publish date', 'save-without-publish' ),
	featured_media: __( 'The featured image', 'save-without-publish' ),
	format: __( 'The post format', 'save-without-publish' ),
	menu_order: __( 'The order', 'save-without-publish' ),
	meta: __( 'Custom fields', 'save-without-publish' ),
	parent: __( 'The parent', 'save-without-publish' ),
	password: __( 'The password', 'save-without-publish' ),
	ping_status: __( 'Pingbacks', 'save-without-publish' ),
	slug: __( 'The slug', 'save-without-publish' ),
	status: __( 'The status', 'save-without-publish' ),
	sticky: __( 'Sticky', 'save-without-publish' ),
	tags: __( 'Tags', 'save-without-publish' ),
	template: __( 'The template', 'save-without-publish' ),
} );

/**
 * Whether the save swap is switched off for this page load.
 *
 * Test-only, and deliberately not reachable from the server: there is no filter,
 * option, context key, or query parameter behind it. The only way to set it is
 * to run script in the admin page before this bundle, which is what Playwright's
 * `addInitScript()` does to exercise the backstop on demand. Anything able to do
 * that in production already owns the editor.
 *
 * Switching it off never weakens the guarantee either -- the save falls through
 * to the server's fork, which stages it and fails closed if it cannot.
 *
 * @return {boolean} True when the swap is disabled.
 */
function swapDisabled() {
	return true === window.swpubTestDisableSaveSwap;
}

/**
 * The data registry, or null on a screen that has none.
 *
 * @return {Object|null} The registry.
 */
function registry() {
	return ( window.wp && window.wp.data ) || null;
}

/**
 * The post ID a save is aimed at, or 0 when the request is not a post save.
 *
 * Matched narrowly on purpose. The pattern ends at the ID, which is what
 * excludes `/wp/v2/posts/12/autosaves` -- an autosave is core's own draft of
 * unsaved work, it never publishes, and staging it would fork on every keystroke
 * interval. Meta-box form posts never reach here at all; they are not
 * `apiFetch` requests, and the server's write guard is what covers them.
 *
 * @param {Object} options The request options.
 * @return {number} The post ID, or 0.
 */
function savedPostId( options ) {
	if ( ! options || 'string' !== typeof options.path ) {
		return 0;
	}

	const method = String( options.method || 'GET' ).toUpperCase();

	if ( 'PUT' !== method && 'POST' !== method ) {
		return 0;
	}

	const matched = /^\/wp\/v2\/[^/?]+\/(\d+)(?:\?|$)/.exec( options.path );

	return matched ? Number( matched[ 1 ] ) : 0;
}

/**
 * Whether this save is one the staging route should carry (KTD34).
 *
 * The staged-field half is load-bearing: without it, a save that only moved the
 * post into a category would be sent to the staging route, which cannot hold a
 * category, and the editor would land on a staged copy while the category change
 * went nowhere.
 *
 * A post that already has a copy is not staged again, it is refused
 * (`locksStagedFields()` below). The route agrees and says so, but the point of
 * asking here is to refuse before anything is sent, so the editor keeps the text
 * rather than getting it back through an error.
 *
 * @param {Object}   ctx    The staging context.
 * @param {number}   id     The post being saved.
 * @param {string[]} fields The fields the save carries.
 * @return {boolean} True when the save should go to the staging route.
 */
function shouldStage( ctx, id, fields ) {
	if ( ctx.isStaged || ! ctx.liveId || ctx.liveId !== id ) {
		return false;
	}

	if ( ctx.stagedCopyId || ctx.canPublishDirectly ) {
		return false;
	}

	return fields.some( ( field ) => STAGED_FIELDS.includes( field ) );
}

/**
 * Whether this save would write fields the staged copy already owns (R55).
 *
 * The client half of `swpub_live_locked`. The canvas and the title are
 * read-only while a copy exists, and the excerpt field is gone from the
 * screen (`existing-staged-copy.js`), but those are courtesies, not the
 * boundary: this is what actually catches an edit that reaches the store some
 * other way -- the load before those locks have applied yet, a title-lock
 * selector gone stale, a plugin writing the entity record directly.
 *
 * Scoped to the staged fields, not to the post. A category, a tag, or a featured
 * image saved from here is a change the copy cannot hold and the published post
 * is the only place to make it, so those saves go through untouched.
 *
 * @param {Object}   ctx    The staging context.
 * @param {number}   id     The post being saved.
 * @param {string[]} fields The fields the save carries.
 * @return {boolean} True when the save must be refused.
 */
function locksStagedFields( ctx, id, fields ) {
	if ( ctx.isStaged || ! ctx.stagedCopyId || ctx.liveId !== id ) {
		return false;
	}

	return fields.some( ( field ) => STAGED_FIELDS.includes( field ) );
}

/**
 * Removes core's generic save-failed notice once it lands.
 *
 * Core's `getNotificationArgumentsForSaveFail()` posts "Updating failed." for
 * any save it did not make itself, unconditionally, and appends the thrown
 * error's message to it when there is one -- an empty message never stopped
 * it. This plugin's own notice has already said what happened and what to do,
 * so core's is removed the moment it appears rather than left to bury it.
 *
 * Watched rather than removed on a timer, because core dispatches its notice
 * after this promise has already rejected, on its own schedule.
 *
 * @return {void}
 */
function dismissCoreSaveFailure() {
	const data = registry();
	const notices = data && data.dispatch( 'core/notices' );

	if ( ! notices ) {
		return;
	}

	const stopAt = Date.now() + 5000;
	const unsubscribe = data.subscribe( () => {
		const posted = data.select( 'core/notices' ).getNotices();

		if ( posted.some( ( notice ) => 'editor-save' === notice.id ) ) {
			notices.removeNotice( 'editor-save' );
			unsubscribe();
		} else if ( Date.now() > stopAt ) {
			unsubscribe();
		}
	} );
}

/**
 * Refuses a save that would write over the staged copy's own fields.
 *
 * Its own sentence rather than the server's, for the same reason `refuse()` has
 * one: the server's names the remedy for a machine, and this reader is a person
 * already looking at the screen the remedy starts from. The way out is the
 * staged copy, and the notice carries the link to it.
 *
 * Nothing is sent, so the editor keeps every edit and stays dirty. Core still
 * posts its own generic failure notice for the rejection this produces --
 * `dismissCoreSaveFailure()` is what keeps that from burying the sentence
 * above under "Updating failed."
 *
 * @param {Object} ctx The staging context.
 * @return {Error} The refusal.
 */
function refuseLocked( ctx ) {
	const data = registry();

	if ( data && data.dispatch( 'core/notices' ) ) {
		data.dispatch( 'core/notices' ).createErrorNotice(
			__(
				'Not saved. This post already has staged changes, and they are the only place its title, content, and excerpt can be edited.',
				'save-without-publish'
			),
			{
				id: 'swpub-live-locked',
				isDismissible: true,
				actions: editStaged( ctx ),
			}
		);
	}

	dismissCoreSaveFailure();

	const error = new Error();
	error.code = 'swpub_live_locked';

	return error;
}

/**
 * The staged copy's edit screen, flagged as somewhere the editor was moved to.
 *
 * @param {string} editUrl The staged copy's edit URL.
 * @return {string} The URL to navigate to.
 */
function arrivalUrl( editUrl ) {
	const separator = editUrl.indexOf( '?' ) === -1 ? '?' : '&';

	return editUrl + separator + 'swpub_forked=1';
}

/**
 * Refuses a save carrying changes a staged copy cannot hold (R51).
 *
 * The client twin of the staging route's own refusal, and it has to be a
 * different sentence: the route tells the reader to change the field on the
 * published post, which is exactly where this reader already is. What they need
 * is the way to get their text staged from here.
 *
 * Nothing is sent, so the editor keeps every edit and stays dirty. Core still
 * posts its own generic failure notice for the rejection this produces --
 * `dismissCoreSaveFailure()` is what keeps that from burying the sentence
 * above under "Updating failed."
 *
 * @param {string[]} refused The field names that cannot be staged.
 * @return {Error} The refusal.
 */
function refuse( refused ) {
	const data = registry();
	const labels = fieldLabels();
	const named = refused.map( ( field ) => labels[ field ] || field );

	if ( data && data.dispatch( 'core/notices' ) ) {
		data.dispatch( 'core/notices' ).createErrorNotice(
			sprintf(
				/* translators: %s: the names of the fields that were changed, as a list. */
				__(
					'%s cannot be staged. Undo that change to save, or change it on the published post after your text change is staged.',
					'save-without-publish'
				),
				named.join( ', ' )
			),
			{ id: 'swpub-unstageable-edit', isDismissible: true }
		);
	}

	dismissCoreSaveFailure();

	const error = new Error();
	error.code = 'swpub_unstageable_edit';

	return error;
}

/**
 * Sends the save to the staging route and follows it to the staged copy.
 *
 * Answers the caller with the published post's own ID and nothing else, because
 * that is the truth of what happened to it: the record it asked to save is
 * unchanged. Claiming otherwise, or answering with the staged copy's record,
 * would leave the store holding a post it never asked for.
 *
 * @param {number}   id     The published post's ID.
 * @param {Object}   body   The save's body.
 * @param {string[]} fields The fields the save carries.
 * @return {Promise<Object>} The unchanged live record.
 */
async function stageInstead( id, body, fields ) {
	const staged = {};
	const dropped = {};

	fields.forEach( ( field ) => {
		if ( STAGED_FIELDS.includes( field ) ) {
			staged[ field ] = body[ field ];
			return;
		}

		if ( DROPPED_FIELDS.includes( field ) ) {
			dropped[ field ] = body[ field ];
		}
	} );

	const result = await stageChanges( id, staged );

	/*
	 * On the next tick, after this save has settled. The editor counts an
	 * in-flight save as unsaved work whatever its edits say, which arms the
	 * unload guard and cancels the navigation; letting it settle first and then
	 * letting go of the delivered edits is what disarms it.
	 *
	 * The dropped fields are let go of alongside the delivered ones. They were
	 * not delivered anywhere, but they are not being kept either, and an edit the
	 * editor guards on behalf of a value nothing will ever write is an unload
	 * prompt with no work behind it.
	 */
	setTimeout( () => {
		clearPendingEdits( { ...staged, ...dropped } );
		window.location.assign( arrivalUrl( result.editUrl ) );
	}, 0 );

	return { id };
}

/**
 * Leaves word that a save the editor reported as failed had in fact succeeded.
 *
 * The backstop answers a successful write with an error, so the editor posts a
 * failure notice for it. Correcting that in place is a race against a navigation
 * already scheduled, so the correction travels with the editor instead and is
 * said once it arrives.
 *
 * @return {void}
 */
function announceOnArrival() {
	try {
		window.sessionStorage.setItem( ARRIVAL_KEY, '1' );
	} catch {
		// Private browsing, or storage that is full. The arrival notice on the
		// staged copy still explains the move; this only adds that the save
		// itself worked.
	}
}

/**
 * Says so, on the staged copy, when the backstop carried the last save.
 *
 * @return {void}
 */
function announceArrival() {
	let pending = null;

	try {
		pending = window.sessionStorage.getItem( ARRIVAL_KEY );
		window.sessionStorage.removeItem( ARRIVAL_KEY );
	} catch {
		return;
	}

	if ( ! pending ) {
		return;
	}

	const data = registry();

	if ( ! data || ! data.dispatch( 'core/notices' ) ) {
		return;
	}

	data.dispatch( 'core/notices' ).createSuccessNotice(
		__(
			'Your change was staged. The published post is unchanged.',
			'save-without-publish'
		),
		{ id: 'swpub-staged-arrival', type: 'snackbar' }
	);
}

/**
 * The same save, without the status core put on it uninvited.
 *
 * Only on the post being staged against, and only for a status the editor did
 * not choose: core's primary button dispatches `editPost( { status } )` before
 * every save it makes, and for someone who cannot publish this post that status
 * is `pending`. On a save the staging route carries, `DROPPED_FIELDS` already
 * takes it off. On a save that goes to the published post -- a category on its
 * own, a slug -- nothing did, and it unpublished the post.
 *
 * Keyed on core's publish capability rather than on whether this save stages,
 * and the difference is the whole point: since the bypass became a capability
 * of this plugin's own, an editor can hold core's `publish_post` and still
 * stage every save. Core writes the status they asked for, so dropping it here
 * would silently discard a deliberate switch to draft or private on a post they
 * are entitled to make it on.
 *
 * Dropping rather than refusing, for the reason `DROPPED_FIELDS` gives: a
 * refusal here would be the primary button refusing an ordinary save.
 *
 * @param {Object} ctx     The staging context.
 * @param {number} id      The post being saved.
 * @param {Object} options The request options.
 * @return {Object} The options to send.
 */
function withoutInjectedStatus( ctx, id, options ) {
	if ( ctx.isStaged || ctx.canPublish || ! ctx.liveId || ctx.liveId !== id ) {
		return options;
	}

	if ( ! options.data || undefined === options.data.status ) {
		return options;
	}

	const data = { ...options.data };

	delete data.status;

	return { ...options, data };
}

/**
 * The status a save carried, if it carried one.
 *
 * @param {Object} options The request options.
 * @return {string|undefined} The status, or undefined.
 */
function sentStatus( options ) {
	return options && options.data ? options.data.status : undefined;
}

/**
 * Turns a refused publish click back into the publishing confirmation.
 *
 * Core's primary button dispatches `editPost( { status } )` before it saves, so
 * a refused publish leaves a status edit pending that nothing will ever write.
 * Letting go of it is not tidying: the confirmation refuses to open while the
 * editor believes it is holding unsaved work, and this edit would hold it there
 * for good. Only that one value is let go of, so a word typed before the click
 * is still guarded.
 *
 * Deferred a tick, for the same reason every other reconciliation here is: the
 * editor still counts this save as in flight until the rejection has finished
 * unwinding, and the confirmation reads that as unsaved work.
 *
 * @param {Object} options The request options.
 * @return {void}
 */
function repairPublishAttempt( options ) {
	setTimeout( () => {
		clearPendingEdits( { status: sentStatus( options ) } );
		doAction( REPAIR_ACTION );
	}, 0 );
}

/**
 * Registers the middleware.
 *
 * @return {void}
 */
export function registerForkNavigation() {
	announceArrival();

	apiFetch.use( async ( options, next ) => {
		const ctx = context();
		const id = savedPostId( options );

		if ( id && ! swapDisabled() ) {
			const fields = dirtyFields( id );

			if ( fields && locksStagedFields( ctx, id, fields ) ) {
				throw refuseLocked( ctx );
			}

			if ( fields && shouldStage( ctx, id, fields ) ) {
				const refused = fields.filter(
					( field ) =>
						! STAGED_FIELDS.includes( field ) &&
						! DROPPED_FIELDS.includes( field )
				);

				if ( refused.length ) {
					throw refuse( refused );
				}

				return stageInstead( id, options.data, fields );
			}

			options = withoutInjectedStatus( ctx, id, options );
		}

		try {
			return await next( options );
		} catch ( error ) {
			if (
				error &&
				error.code === LOCKED_CODE &&
				sentStatus( options )
			) {
				/*
				 * A click reached core's publish control on a staged copy. The
				 * server refused it, which is the guarantee working; this is the
				 * recovery, and it is deliberately selector-free -- it keys on
				 * the error code rather than on any piece of core's markup, so
				 * it survives the label surgery failing, which is the failure it
				 * exists for.
				 *
				 * Only a save that carried a status is a publish attempt. The
				 * same refusal answers a category or a slug changed on a staged
				 * copy, and opening the publishing confirmation for one of those
				 * would answer a question nobody asked.
				 */
				repairPublishAttempt( options );

				throw error;
			}

			if ( ! error || error.code !== STAGED_CODE ) {
				throw error;
			}

			const editUrl = error.data && error.data.edit_url;

			if ( ! editUrl ) {
				throw error;
			}

			announceOnArrival();

			// Same next-tick reasoning as the staged path above.
			setTimeout( () => {
				clearPendingEdits();
				window.location.assign( arrivalUrl( editUrl ) );
			}, 0 );

			throw error;
		}
	} );
}
