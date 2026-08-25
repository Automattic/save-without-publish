<?php
/**
 * Containment of writes to a published post, and the policy for the first one.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

use WP_Error;
use WP_Post;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Once a staged copy exists, nothing writes this post's staged fields (R41, R55).
 *
 * Two rules, and the second one used to be softer than it is now. A first write
 * by someone who cannot publish forks a staged copy and lands in it. Every write
 * after that, to either the published post or through any transport, is refused
 * until the copy is published or discarded.
 *
 * The guarantee moved here from the REST seam because a seam is a transport, and
 * a transport-shaped guarantee is only true on that transport: Quick Edit, the
 * classic editor, XML-RPC, and a plain `wp_update_post()` all published straight
 * to the live post while the plugin's headline said otherwise. Every one of them
 * ends in `wp_insert_post()`, so that is where the rule now lives.
 *
 * The pair of hooks is not a choice of taste (KTD28). `wp_insert_post_data` can
 * reshape the row but cannot stop it -- core unslashes whatever it returns with
 * no `is_wp_error()` check -- and `wp_insert_post_empty_content` is the only
 * abort inside `wp_insert_post()` that fires on every transport. So the divert
 * happens in the empty-content hook, which can also fail the whole update
 * closed, and the data hook only neutralizes what is left.
 *
 * Slashing is explicit in both directions, because the two hooks sit on opposite
 * sides of core's unslash. Values arriving in `$postarr` and `$data` are slashed;
 * the live row read back through `get_post()` is not. So incoming values are
 * unslashed before they are compared, restored values are re-slashed, and the
 * write to the staged copy is slashed. `includes/class-media.php` carries the
 * same warning for the same reason.
 *
 * The guard runs on every post write site-wide, so the predicate is ordered
 * cheapest first and reaches its one primary-key meta read only after six
 * cheaper questions have already said yes. It adds no queries.
 *
 * Once that read comes back empty there is nothing to protect, and the guard
 * turns from containment to policy: `decide_first_write()` decides whether a
 * first write publishes, publishes with an audit event, is refused outright, or
 * establishes the copy that every later write is contained into. The capability
 * decides that, not the transport -- the transport names the one seam left open
 * on purpose, and the one surface that cannot show what staging did.
 *
 * Deciding and doing are separate: `classify()` answers what would happen with
 * nothing written, and `divert()` is the only thing that acts on the answer. The
 * split exists so `would_divert()` can hand the same answer to a surface that
 * has to refuse a write before it is ever attempted (KTD33), rather than that
 * surface growing a second copy of this predicate.
 *
 * ## Why the second rule refuses rather than diverts
 *
 * It used to divert: a write to a post with a staged copy had its staged fields
 * redirected into the copy and was answered 200. The live post was never
 * touched, so the headline guarantee held, and the write was never lost. It was
 * still wrong, and R55 is the correction.
 *
 * The value being diverted is assembled against the live row. Somebody editing
 * the published post is reading published words, so what their editor sends is
 * the published body with one change in it -- and that body has never seen the
 * staged edits. Writing it into the copy reverts all of them. Diffing per field
 * bounds this to the fields that differ and no further, which saves an untouched
 * title but does nothing for the case that matters: `content` is a single field,
 * so one edited paragraph replaces the entire staged body.
 *
 * Making the divert safe would mean merging two bodies of text, which is a diff
 * tool, which the plan rules out. So the copy is the only place its own fields
 * are written from.
 *
 * ## What this costs an integration, and why there is no filter for it
 *
 * The refusal has no valve, deliberately, and it costs more than the divert did.
 * A sync client, headless publisher, or scheduled importer writing to a post
 * that happens to have a staged copy now gets an error where it used to get 200,
 * and its content does not reach readers until somebody clears the copy. That is
 * louder than before and it is meant to be: the previous behaviour spent
 * somebody's staged work to keep the integration quiet.
 *
 * No filter exempts a caller from this. A filter that did would be the defect
 * this class exists to close, re-entered through a named door. The remedies are
 * to publish or discard the copy, which returns the post to its first-save
 * state, or to stop staging site-wide with `swpub_is_enabled`. The readme says
 * so where a site evaluating the plugin will read it, rather than where a site
 * running it will discover it.
 */
final class Write_Guard {

	/**
	 * Channels whose first write may publish on the carve-out seam (KTD30b).
	 *
	 * Enumerated, never inferred from what is missing. `internal`, `classic`, and
	 * `quickedit` are absent because a human is behind them; `cron` is absent
	 * because a write with no user has already passed through before this list is
	 * read, and a cron write that somehow carries one is safer staged.
	 *
	 * `quickedit` never reaches this list in practice: it is refused a few lines
	 * earlier, because its row cannot show what staging did to it (KD15). Being
	 * absent here as well is the second lock -- removing the refusal must not
	 * quietly turn Quick Edit into a publishing seam.
	 *
	 * @var string[]
	 */
	private const CARVE_OUT_CHANNELS = array( 'rest-token', 'xmlrpc', 'cli' );

	/**
	 * The write lands on the live post, as core would write it.
	 */
	private const PASS = 'pass';

	/**
	 * The write's staged fields divert into an existing staged copy.
	 */
	private const CONTAIN = 'contain';

	/**
	 * There is no copy yet, so one is created and the write diverts into it.
	 */
	private const ESTABLISH = 'establish';

	/**
	 * A staged copy already holds this post's next change, so the write is refused.
	 */
	private const BLOCK = 'block';

	/**
	 * The write publishes on the named programmatic seam, and says so.
	 */
	private const CARVE_OUT = 'carve-out';

	/**
	 * The write is refused outright, because its surface cannot show what staging did.
	 */
	private const REFUSE = 'refuse';

	/**
	 * The post ID whose live write is sanctioned for this call, or 0.
	 *
	 * A post ID rather than a boolean (KTD29). A merge's `wp_update_post()` fires
	 * core's whole `save_post` chain, and a third-party listener writing to some
	 * other post from inside that chain would ride a boolean sanction straight
	 * past the containment on a post it has nothing to do with.
	 *
	 * Request-scoped, never persisted: a merge that crashes mid-write would leave
	 * a persisted marker standing as an open bypass on that post.
	 *
	 * @var int
	 */
	private static int $sanctioned_id = 0;

	/**
	 * Live posts whose staged fields this request diverted, keyed by post ID.
	 *
	 * Holds the live post as it was before the write, which is what the data hook
	 * restores. Armed by the empty-content hook and consumed by the very next
	 * `wp_insert_post_data` for the same ID.
	 *
	 * @var array<int, WP_Post>
	 */
	private static array $neutralized = array();

	/**
	 * Staged copies this request diverted into, keyed by the live post ID.
	 *
	 * Request-scoped and never persisted, because the question it answers is
	 * "did this request stage something", not "does this post have a copy". The
	 * meta answers the second and would answer the first wrongly: a classic save
	 * that changed only a category on a post that already has a copy must keep
	 * core's own redirect, and meta cannot tell the two apart.
	 *
	 * @var array<int, int>
	 */
	private static array $engaged = array();

	/**
	 * Live posts whose write this request refused, keyed by post ID.
	 *
	 * The value is the staged copy that refused it, so a surface answering after
	 * the fact can name the way out without a second meta read.
	 *
	 * Request-scoped for the same reason `$engaged` is: the question is "did this
	 * request get refused", which the meta cannot answer. A classic save that
	 * changed only a category on a post with a copy was not refused, and belongs
	 * back where core would have put it.
	 *
	 * @var array<int, int>
	 */
	private static array $blocked = array();

	/**
	 * Whether the guard is currently writing to a staged copy.
	 *
	 * Reentrancy is already clean by construction (KTD31): `establish()` inserts a
	 * staged-status row and the field write updates one, while the guard only
	 * matches updates to `publish` rows. This is the second layer, so a change to
	 * either side cannot quietly turn the divert into a loop.
	 *
	 * @var bool
	 */
	private static bool $writing_staged_copy = false;

	/**
	 * The REST request currently running its route callback, if any.
	 *
	 * Recorded so channel detection can read the request's own `X-WP-Nonce`
	 * header rather than guessing from superglobals, and so a REST write is
	 * recognised as one wherever `wp_insert_post()` is reached from.
	 *
	 * @var WP_REST_Request|null
	 */
	private static ?WP_REST_Request $rest_request = null;

	/**
	 * Registers hooks.
	 *
	 * Called at plugin load rather than on `init`, for the same reason `Fork`,
	 * `Field_Lock`, `Merge_Route`, and `Stage_Route` are: another plugin can call
	 * `wp_insert_post()` before `init` fires, and a containment filter that was
	 * not attached yet does not fail loudly -- the write simply publishes. This
	 * only adds filters, so there is nothing here that needs post types or the
	 * staged status to be registered first.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'divert' ), 10, 2 );
		add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'disarm_on_abort' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'neutralize' ), 10, 2 );

		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'remember_rest_request' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'forget_rest_request' ), 10, 3 );
	}

	/**
	 * Runs the plugin's own write to a live post past the containment (KTD29).
	 *
	 * The merge is the one writer allowed to land staged content on the live
	 * post; without this its `wp_update_post()` would be diverted straight back
	 * into the staged copy it is trying to publish.
	 *
	 * The previous value is restored rather than cleared, and it is restored in a
	 * `finally`, so neither an exception nor an early return can leave a sanction
	 * armed across the iterations of `wp swpub repair --all` or a cron-driven
	 * `Merge_Resume`.
	 *
	 * @param int      $post_id  The live post whose write is sanctioned.
	 * @param callable $callback The write to run.
	 * @return mixed Whatever the callback returned.
	 */
	public static function sanction( int $post_id, callable $callback ) {
		$previous = self::$sanctioned_id;

		self::$sanctioned_id = $post_id;

		try {
			return $callback();
		} finally {
			self::$sanctioned_id = $previous;
		}
	}

	/**
	 * Diverts a contained write into the staged copy, or fails it closed (R41, R47).
	 *
	 * Returns `false` on a successful divert, deliberately overriding whatever
	 * core thought about emptiness: the live update should carry on and land as a
	 * no-op, because `neutralize()` has been armed to put the live post's own
	 * values back. Returns `true` only when the staged copy could not be written,
	 * which aborts the whole update before any row is touched.
	 *
	 * The `empty_content` error identity that abort produces is wrong and known to
	 * be wrong. Core's message says the title, content, and excerpt are empty,
	 * when what actually happened is that the staged-copy write failed. It is
	 * accepted because this hook is the only universal abort core offers and the
	 * path is a rare already-broken one, and because a false failure is survivable
	 * where a silent publish is not. `swpub_staging_write_failed` carries the real
	 * reason; letting this hook pass a `WP_Error` through is the upstream fix.
	 *
	 * @param bool  $maybe_empty Whether core considers the post empty.
	 * @param array $postarr     Slashed, sanitized post data.
	 * @return bool Whether to abort the write.
	 */
	public static function divert( $maybe_empty, $postarr ): bool {
		$maybe_empty = (bool) $maybe_empty;

		if ( ! is_array( $postarr ) ) {
			return $maybe_empty;
		}

		$verdict = self::classify( $postarr );

		if ( self::BLOCK === $verdict['decision'] ) {
			self::$blocked[ $verdict['live']->ID ] = $verdict['staged_copy']->ID;

			Events::write_blocked(
				$verdict['staged_copy']->ID,
				$verdict['live']->ID,
				$verdict['channel']
			);

			/*
			 * Aborts with core's `empty_content` identity, which is wrong and
			 * known to be wrong -- this hook is the only universal abort inside
			 * `wp_insert_post()` and it does not carry a `WP_Error` through. Every
			 * surface that can say something better says it before the write
			 * reaches here: `Fork` answers REST with `swpub_live_locked` and a
			 * 409, and `Admin_Surfaces` answers the classic editor and Quick Edit
			 * on their own screens. What is left underneath is a direct
			 * `wp_update_post()` from plugin code or WP-CLI, where a wrong error
			 * identity is survivable and a silent overwrite of somebody's staged
			 * work is not. `swpub_write_blocked` above carries the real reason.
			 */
			return true;
		}

		if ( self::ESTABLISH === $verdict['decision'] ) {
			$staged_copy = Staged_Copy_Repository::establish( $verdict['live'] );

			if ( is_wp_error( $staged_copy ) ) {
				Events::staging_write_failed( $verdict['live']->ID, $staged_copy->get_error_code() );

				return true;
			}

			return self::contain( $verdict['live'], $staged_copy, $verdict['changed'] );
		}

		if ( self::CARVE_OUT === $verdict['decision'] ) {
			Events::published_via_carveout( $verdict['live']->ID, $verdict['channel'] );

			return $maybe_empty;
		}

		if ( self::REFUSE === $verdict['decision'] ) {
			/*
			 * The fail-closed backstop from KTD33, and the one place the data
			 * layer is allowed to reach for `wp_die()`. The decision is only ever
			 * REFUSE on the `quickedit` channel, which is core's inline-save ajax
			 * request and nothing else -- so the ajax scoping `wp_die()` needs is
			 * a property of the decision, not a hope about the caller. Dying in
			 * the general data layer stays vetoed: it would kill CLI loops, cron,
			 * and importers mid-batch.
			 *
			 * The abort below is not reached while `wp_die()` does what it says.
			 * It is here because a backstop that fails open when its own mechanism
			 * is filtered away is not a backstop -- `wp_die()` is filterable by
			 * design, and the fallthrough from this branch publishes.
			 */
			Admin_Surfaces::refuse_quick_edit( $verdict['live']->ID );

			return true;
		}

		return $maybe_empty;
	}

	/**
	 * What the guard would do with this write, without doing any of it.
	 *
	 * Split out of `divert()` so the Quick Edit handler can ask the question one
	 * hook earlier and answer it on its own surface (KTD33). The two must not
	 * drift: the handler refuses exactly the writes this would divert, so a
	 * predicate of its own would eventually refuse a write that publishes, or
	 * publish one that vanishes into a copy. There is one predicate, and this is
	 * it.
	 *
	 * Ordered cheapest first (KTD30), reaching its one primary-key meta read only
	 * after six cheaper questions have already said yes.
	 *
	 * @param array $postarr Slashed, sanitized post data.
	 * @return array{decision: string, live: ?WP_Post, staged_copy: ?WP_Post, changed: array<string, string>, channel: string} The verdict.
	 */
	private static function classify( array $postarr ): array {
		$verdict = array(
			'decision'    => self::PASS,
			'live'        => null,
			'staged_copy' => null,
			'changed'     => array(),
			'channel'     => '',
		);

		if ( self::$writing_staged_copy || ! is_enabled() ) {
			return $verdict;
		}

		// An update, not a creation. A create has no live post to contain.
		$live_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

		if ( $live_id <= 0 ) {
			return $verdict;
		}

		/*
		 * Autosave writes its own revision row and never touches the live post,
		 * and diverting one would stage text the author has not decided to keep.
		 * Core defines the constant and offers no `wp_doing_autosave()`.
		 */
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $verdict;
		}

		$live = get_post( $live_id );

		if ( ! $live instanceof WP_Post ) {
			return $verdict;
		}

		/*
		 * Revisions, not `staged_post_types()`. The two predicates answer
		 * different questions and the difference is deliberate: `staged_post_types()`
		 * is keyed on `show_in_rest` because that decides whether the
		 * `rest_pre_insert_*` seam exists at all, while containment has to hold
		 * for anything that already has a staged copy, whatever seam made it.
		 * The narrower gate lives in `Staged_Copy_Repository::can_establish()`,
		 * which only the no-copy branch consults.
		 *
		 * This also keeps revisions and attachments out by construction: neither
		 * post type supports revisions, and attachments are filtered through
		 * `wp_insert_attachment_data` rather than `wp_insert_post_data` anyway.
		 */
		if ( ! post_type_supports( $live->post_type, 'revisions' ) || ! wp_revisions_enabled( $live ) ) {
			return $verdict;
		}

		/*
		 * Both ends must be published. A status transition therefore bails here,
		 * which is what exempts trash, untrash, and scheduling by construction
		 * rather than by a list of special cases that could fall out of date.
		 */
		$target_status = isset( $postarr['post_status'] ) ? (string) $postarr['post_status'] : '';

		if ( 'publish' !== $target_status || 'publish' !== $live->post_status ) {
			return $verdict;
		}

		/*
		 * Only the staged fields whose value actually differs (R41, R42). Core
		 * merges the live row into `$postarr` before this hook sees it, so every
		 * write carries all three fields whether or not the writer touched them --
		 * diverting all three would overwrite staged work nobody asked to change,
		 * and a write that changes none of them must pass through as core.
		 */
		$changed = self::changed_staged_fields( $postarr, $live );

		if ( empty( $changed ) ) {
			return $verdict;
		}

		if ( $live_id === self::$sanctioned_id ) {
			return $verdict;
		}

		$verdict['live']    = $live;
		$verdict['changed'] = $changed;

		$staged_copy = Staged_Copy_Repository::find_for_live( $live_id );

		if ( $staged_copy instanceof WP_Post ) {
			/*
			 * Tier 1. No capability check and no user check: R41 is true as
			 * written, including for a write with no authenticated user.
			 *
			 * The answer is a refusal rather than a divert, and the difference is
			 * the whole of R55. Diverting looked like the safer half of the same
			 * guarantee -- the live post is untouched either way -- but the value
			 * it diverted was assembled against the live row, not the staged one.
			 * An editor on the published post is looking at published words, so
			 * their save carries published words plus one change, and writing
			 * that into the copy reverts every staged change it did not know
			 * about. Per-field diffing bounded the damage to the fields that
			 * actually differ and no further: `content` is one field, so one
			 * edited paragraph took the whole staged body with it.
			 *
			 * There is no version of the divert that is safe without merging two
			 * bodies of text, which is a diff tool this plugin does not have and
			 * does not want. So the copy is the only place its own fields may be
			 * written from, and the published post says no until the copy is
			 * published or discarded.
			 */
			$verdict['staged_copy'] = $staged_copy;
			$verdict['channel']     = self::channel();
			$verdict['decision']    = self::BLOCK;

			return $verdict;
		}

		// No staged copy yet, so nothing to contain: this is a first write, and
		// what happens to it is a policy question rather than a containment one.
		return self::decide_first_write( $verdict );
	}

	/**
	 * Whether this write would keep its staged fields off the published post.
	 *
	 * The narrow internal KTD33 needs, and the only thing outside the guard that
	 * may ask: it runs the same predicate `divert()` runs, one hook earlier, with
	 * nothing written and no event fired. A surface that cannot represent staging
	 * uses it to refuse before the write is attempted.
	 *
	 * @param array $postarr Slashed, sanitized post data, as `wp_insert_post()` would see it.
	 * @return bool True when the write would divert, be established into a copy, or be refused.
	 */
	public static function would_divert( array $postarr ): bool {
		return in_array(
			self::classify( $postarr )['decision'],
			array( self::CONTAIN, self::ESTABLISH, self::REFUSE, self::BLOCK ),
			true
		);
	}

	/**
	 * The staged copy this request diverted a write into, if it diverted one.
	 *
	 * @param int $live_id Published post ID.
	 * @return int The staged copy ID, or 0 when this request staged nothing for it.
	 */
	public static function staged_copy_engaged( int $live_id ): int {
		return isset( self::$engaged[ $live_id ] ) ? (int) self::$engaged[ $live_id ] : 0;
	}

	/**
	 * The staged copy that refused this request's write, if one did.
	 *
	 * For a surface that only learns the write failed after the fact. The classic
	 * editor is the one that needs it: `edit_post()` ignores what
	 * `wp_update_post()` returned, so without this it redirects saying "Post
	 * updated" over a save that wrote nothing.
	 *
	 * @param int $live_id Published post ID.
	 * @return int The staged copy ID, or 0 when this request refused nothing for it.
	 */
	public static function write_blocked_for( int $live_id ): int {
		return isset( self::$blocked[ $live_id ] ) ? (int) self::$blocked[ $live_id ] : 0;
	}

	/**
	 * Decides what a first write to an unstaged post does (R43, R45, R46, KTD30b).
	 *
	 * Four outcomes, mutually exclusive, at most one event between them:
	 *
	 * 1. The capability says yes, so the write publishes and nothing is announced.
	 *    An ordinary save by someone who may publish has never fired an event and
	 *    does not start now.
	 * 2. The write arrived from Quick Edit, whose row cannot show what staging did
	 *    to it, so it is refused (KD15, KTD33).
	 * 3. No capability, but the write positively identifies as programmatic and
	 *    the seam is open: it publishes and says so.
	 * 4. Anything else stages.
	 *
	 * A fourth answer sits before all three and announces nothing: a post staging
	 * could not cover at all publishes as core, because there is no copy to divert
	 * into and no gate anyone got around.
	 *
	 * The authenticated-user check lives here rather than up in the predicate, and
	 * the position is load-bearing: containment above must hold for a write with
	 * no user behind it. Here there is no such write to contain, no human waiting
	 * on a staged copy, and no user whose capability could be read -- so it
	 * publishes as core does.
	 *
	 * Channels are identified positively, and anything unrecognized stages. A
	 * default that published would let a front-end editor or a custom admin screen
	 * publish for a user without the capability, which is the exact defect this
	 * guard exists to close, surviving as the default branch. The valve for an
	 * integration that must keep publishing is a capability grant through
	 * `swpub_can_publish_directly`, not a transport nobody enumerated.
	 *
	 * @param array{decision: string, live: ?WP_Post, staged_copy: ?WP_Post, changed: array<string, string>, channel: string} $verdict The verdict so far, carrying the live post.
	 * @return array{decision: string, live: ?WP_Post, staged_copy: ?WP_Post, changed: array<string, string>, channel: string} The completed verdict.
	 */
	private static function decide_first_write( array $verdict ): array {
		$live = $verdict['live'];

		if ( ! $live instanceof WP_Post || get_current_user_id() <= 0 ) {
			return $verdict;
		}

		if ( Capabilities::current_user_can_publish_directly( $live->ID ) ) {
			return $verdict;
		}

		/*
		 * The preconditions the deliberate entry points already share, because
		 * `establish()` enforces none of its own -- its callers do. Without this
		 * the guard would create copies for types `Field_Lock` and `Fork` never
		 * attach to, where a copy nobody locks can be flipped to publish as a
		 * public duplicate, and copies during a merge that the merge then adopts
		 * and deletes. A refusal falls back to publishing as core: staging is not
		 * available here, so there is nothing to divert into.
		 *
		 * It is asked before the seam, not after, and that order is what keeps the
		 * audit trail honest. A write that publishes because staging was never
		 * available for this post did not publish because the seam is open, and
		 * closing the seam would not change what it did -- so announcing it as a
		 * carve-out would point a site at a remedy that cannot work.
		 *
		 * Tier 1 containment above deliberately keeps the wider predicate. The two
		 * answer different questions: what may be created, and what must be
		 * contained once it exists.
		 */
		if ( is_wp_error( Staged_Copy_Repository::can_establish( $live ) ) ) {
			return $verdict;
		}

		$channel            = self::channel();
		$verdict['channel'] = $channel;

		/*
		 * Ahead of the carve-out, deliberately. Quick Edit is a human surface, so
		 * it is not on the carve-out list and would otherwise stage here -- and a
		 * staged Quick Edit re-renders the row from the published post, which
		 * still holds the old title. The editor watches their change disappear
		 * with nothing to click. This is the backstop for a priority-0 handler
		 * that never ran, and `Admin_Surfaces` owns what the refusal says.
		 */
		if ( 'quickedit' === $channel ) {
			$verdict['decision'] = self::REFUSE;

			return $verdict;
		}

		if ( in_array( $channel, self::CARVE_OUT_CHANNELS, true ) && ! self::seam_closed( $live->ID ) ) {
			$verdict['decision'] = self::CARVE_OUT;

			return $verdict;
		}

		$verdict['decision'] = self::ESTABLISH;

		return $verdict;
	}

	/**
	 * Whether this site has closed the programmatic first-save seam (KD16).
	 *
	 * Open by default, and that is a decision rather than an oversight. Closing it
	 * by default would divert the writes of every programmatic client already
	 * running as a low-capability user -- sync plugins, headless publishers,
	 * WP-CLI scripts -- into staged copies nobody asked for and nobody is watching.
	 * And it would stop only a determined evader while the merge seam stays open,
	 * so the safety it buys is smaller than it looks.
	 *
	 * What it costs instead is disclosed: the seam is named, every use of it fires
	 * `swpub_published_via_carveout`, and this filter closes it for a site that
	 * wants those writes staged at the data layer.
	 *
	 * Containment is copy-scoped, so the seam reopens: discarding or merging a
	 * staged copy returns the post to its first-save state, where the carve-out
	 * applies again. Closing the seam is the remedy for a site that needs it shut.
	 *
	 * @param int $live_id Published post ID being written to.
	 * @return bool True when the write should stage rather than publish.
	 */
	private static function seam_closed( int $live_id ): bool {
		/**
		 * Filters whether a programmatic first save stages instead of publishing.
		 *
		 * Return true to close the seam site-wide:
		 *
		 *     add_filter( 'swpub_enforce_programmatic_first_save', '__return_true' );
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enforce Whether to stage the write. Default false.
		 * @param int  $live_id Published post ID being written to.
		 */
		return (bool) apply_filters( 'swpub_enforce_programmatic_first_save', false, $live_id );
	}

	/**
	 * Puts the live post's own values back into the write it already diverted.
	 *
	 * Runs only for a post the empty-content hook armed, and only once per
	 * arming, so an unrelated write later in the same request cannot pick up a
	 * stale restore.
	 *
	 * The `post_modified` restore is not cosmetic. Core recomputes both modified
	 * columns on every update, and `Drift::has_drifted()` compares
	 * `post_modified_gmt` byte for byte against a baseline frozen at fork -- so
	 * without this, every contained write would leave the live post permanently
	 * reading as drifted and the author's next merge would be refused for a
	 * change that never happened. It is restored only when the write carries no
	 * change to any other field, because a write that also edits a non-staged
	 * field really did modify the live post and should say so.
	 *
	 * @param array $data    Slashed, processed row data about to be written.
	 * @param array $postarr Slashed, sanitized post data.
	 * @return array The row data to write.
	 */
	public static function neutralize( $data, $postarr ): array {
		$data = (array) $data;

		if ( ! is_array( $postarr ) ) {
			return $data;
		}

		$live_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

		if ( $live_id <= 0 || ! isset( self::$neutralized[ $live_id ] ) ) {
			return $data;
		}

		$live = self::$neutralized[ $live_id ];

		unset( self::$neutralized[ $live_id ] );

		foreach ( Staged_Copy_Repository::STAGED_FIELDS as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				// Re-slashed: core unslashes this array the moment the filter returns.
				$data[ $field ] = wp_slash( (string) $live->$field );
			}
		}

		if ( self::changes_nothing_else( $data, $live ) ) {
			$data['post_modified']     = $live->post_modified;
			$data['post_modified_gmt'] = $live->post_modified_gmt;
		}

		return $data;
	}

	/**
	 * Drops an arming that its own write is never going to reach.
	 *
	 * `apply_filters()` runs every callback, so this sees the value core will
	 * actually act on. If something else on this hook aborts the update after the
	 * divert has already run, `neutralize()` never fires and the arming would sit
	 * there waiting for the next write to the same post in the same request --
	 * which it would then quietly restore to a stale snapshot. The divert itself
	 * stands: the staged copy holds the change and the event said so.
	 *
	 * @param bool  $maybe_empty Whether the write is about to be aborted.
	 * @param array $postarr     Slashed, sanitized post data.
	 * @return bool The decision, untouched.
	 */
	public static function disarm_on_abort( $maybe_empty, $postarr ): bool {
		$maybe_empty = (bool) $maybe_empty;

		if ( ! $maybe_empty || ! is_array( $postarr ) ) {
			return $maybe_empty;
		}

		$live_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

		if ( $live_id > 0 ) {
			unset( self::$neutralized[ $live_id ] );
		}

		return $maybe_empty;
	}

	/**
	 * Records the REST request whose callback is about to run.
	 *
	 * @param mixed           $response Response so far.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed The response, untouched.
	 */
	public static function remember_rest_request( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request ) {
			self::$rest_request = $request;
		}

		return $response;
	}

	/**
	 * Forgets the REST request once its callback has finished.
	 *
	 * @param mixed           $response Response so far.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed The response, untouched.
	 */
	public static function forget_rest_request( $response, $handler, $request ) {
		self::$rest_request = null;

		return $response;
	}

	/**
	 * Diverts the changed fields into the staged copy and arms the neutralizer.
	 *
	 * @param WP_Post               $live        The live post.
	 * @param WP_Post               $staged_copy Its staged copy.
	 * @param array<string, string> $changed     Unslashed staged fields that differ.
	 * @return bool True to abort the update, false to let it proceed as a no-op.
	 */
	private static function contain( WP_Post $live, WP_Post $staged_copy, array $changed ): bool {
		$written = self::write_to_staged_copy( $staged_copy->ID, $changed );

		if ( is_wp_error( $written ) ) {
			Events::staging_write_failed( $live->ID, $written->get_error_code() );

			return true;
		}

		self::$neutralized[ $live->ID ] = $live;
		self::$engaged[ $live->ID ]     = $staged_copy->ID;

		Events::write_staged( $staged_copy->ID, $live->ID, self::channel() );

		return false;
	}

	/**
	 * Writes the changed staged fields onto the staged copy.
	 *
	 * @param int                   $staged_copy_id Staged copy post ID.
	 * @param array<string, string> $changed        Unslashed staged fields that differ.
	 * @return int|WP_Error The staged copy ID, or the reason the write failed.
	 */
	private static function write_to_staged_copy( int $staged_copy_id, array $changed ) {
		$update = array_merge( array( 'ID' => $staged_copy_id ), $changed );

		self::$writing_staged_copy = true;

		try {
			// Slashed, because `wp_update_post()` merges the stored row and the
			// insert then strips one level of slashes off the whole array.
			$result = wp_update_post( wp_slash( $update ), true );
		} finally {
			self::$writing_staged_copy = false;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( (int) $result <= 0 ) {
			return new WP_Error(
				'swpub_staged_copy_write_failed',
				__( 'The change could not be written to the staged copy.', 'save-without-publish' )
			);
		}

		return (int) $result;
	}

	/**
	 * The staged fields whose incoming value differs from the live row.
	 *
	 * @param array   $postarr Slashed, sanitized post data.
	 * @param WP_Post $live    The live post.
	 * @return array<string, string> Unslashed values, keyed by field.
	 */
	private static function changed_staged_fields( array $postarr, WP_Post $live ): array {
		$changed = array();

		foreach ( Staged_Copy_Repository::STAGED_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $postarr ) || ! is_scalar( $postarr[ $field ] ) ) {
				continue;
			}

			// Unslashed before comparing: `$postarr` is slashed at this hook and
			// the live row read back from `get_post()` is not.
			$incoming = (string) wp_unslash( (string) $postarr[ $field ] );

			if ( $incoming !== (string) $live->$field ) {
				$changed[ $field ] = $incoming;
			}
		}

		return $changed;
	}

	/**
	 * Whether the write, once neutralized, changes nothing on the live row.
	 *
	 * Compared field by field against the stored row rather than against a list
	 * of known columns, so a column core adds later is covered without this
	 * having to hear about it.
	 *
	 * @param array   $data Slashed row data, staged fields already restored.
	 * @param WP_Post $live The live post as it was before the write.
	 * @return bool True when only the modified timestamps would change.
	 */
	private static function changes_nothing_else( array $data, WP_Post $live ): bool {
		foreach ( $data as $field => $value ) {
			if ( 'post_modified' === $field || 'post_modified_gmt' === $field ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				return false;
			}

			$stored = isset( $live->$field ) ? (string) $live->$field : '';

			if ( (string) wp_unslash( (string) $value ) !== $stored ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The transport this write arrived on (KTD38).
	 *
	 * Best-effort by design, and worth saying so plainly: core exposes no request
	 * origin at this seam, so every arm below is an inference from a constant, a
	 * request header, or an admin page name. It labels an event for a site's own
	 * audit trail and decides nothing about containment, which is why an inference
	 * is good enough here and would not be anywhere else.
	 *
	 * `rest-token` covers application passwords, OAuth, and anything else without
	 * a `wp_rest` nonce; those are not reliably distinguishable from each other at
	 * this seam and the distinction would not change what a listener does.
	 *
	 * @return string One of rest-cookie, rest-token, xmlrpc, classic, cli, cron,
	 *                quickedit, internal.
	 */
	public static function channel(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		if ( self::is_quick_edit() ) {
			return 'quickedit';
		}

		if ( self::$rest_request instanceof WP_REST_Request ) {
			return self::rest_channel( self::$rest_request );
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest-token';
		}

		if ( isset( $GLOBALS['pagenow'] ) && 'post.php' === $GLOBALS['pagenow'] ) {
			return 'classic';
		}

		return 'internal';
	}

	/**
	 * Whether a REST write is the editor's cookie save or a token client's.
	 *
	 * A valid `wp_rest` nonce is the only signal core offers, and it is the same
	 * one `Fork` reads. Token clients do not send it.
	 *
	 * @param WP_REST_Request $request The in-flight request.
	 * @return string Either rest-cookie or rest-token.
	 */
	private static function rest_channel( WP_REST_Request $request ): string {
		if ( ! is_user_logged_in() ) {
			return 'rest-token';
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return 'rest-token';
		}

		return false !== wp_verify_nonce( $nonce, 'wp_rest' ) ? 'rest-cookie' : 'rest-token';
	}

	/**
	 * Whether this is core's inline-save ajax request.
	 *
	 * @return bool True when the write came from Quick Edit.
	 */
	private static function is_quick_edit(): bool {
		// `wp_doing_ajax()` rather than the constant it wraps: it is core's own
		// reading of the same thing, `wp_die()` dispatches on it, and it is
		// filterable -- which is the only way a test can simulate an ajax request
		// without a define that outlives the rest of the suite.
		if ( ! wp_doing_ajax() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reads the action name to label an event; core's own inline-save handler owns the nonce check, and nothing here acts on request data.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		return 'inline-save' === $action;
	}
}
