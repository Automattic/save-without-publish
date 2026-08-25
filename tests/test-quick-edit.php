<?php
/**
 * Quick Edit refusal and Classic Editor arrival tests for U3 (R44, R50).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish\Tests;

use Exception;
use SaveWithoutPublish\Capabilities;
use SaveWithoutPublish\Admin_Surfaces;
use SaveWithoutPublish\Post_List;
use SaveWithoutPublish\Review_Link;
use SaveWithoutPublish\Staged_Copy_Repository;
use WP_Post;
use WP_UnitTestCase;

/**
 * What a refusal looks like from a test's point of view.
 *
 * `wp_die()` is the mechanism KTD33 chose because core's `inline-edit-post.js`
 * renders a non-row response as its own inline error notice. A test cannot let
 * the process die, so it swaps in a handler that throws this instead and reads
 * the message off it.
 */
class Quick_Edit_Died extends Exception {}

/**
 * Quick Edit refuses rather than staging, and refuses only when it must.
 *
 * KD15: Quick Edit's surface cannot represent staging. The row it re-renders is
 * built from the published post, so a staged save would redisplay the old title
 * with no path to the copy and the edit would appear to have vanished. The
 * refusal is therefore the product behaviour, not a limitation being papered
 * over -- and it is keyed on "this write would divert", never on the capability,
 * because a capability-holder editing a post that already has a staged copy hits
 * the same vanishing row.
 */
class Test_Quick_Edit extends WP_UnitTestCase {

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * A user whose saves publish.
	 *
	 * @var int
	 */
	private int $publisher_id;

	/**
	 * A user whose saves stage.
	 *
	 * @var int
	 */
	private int $editor_id;

	/**
	 * Events fired during a test, oldest first.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $captured = array();

	/**
	 * Publishes a post and subscribes to the events this unit can fire.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->publisher_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// No role holds the direct-publish capability, so a user who publishes
		// rather than stages is one a site granted it to.
		get_user_by( 'id', $this->publisher_id )->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );

		$editor = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$editor->add_cap( 'publish_posts', false );
		$this->editor_id = $editor->ID;

		wp_set_current_user( $this->publisher_id );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Launches in June.',
				'post_excerpt' => 'A short season.',
			)
		);

		$this->captured = array();

		add_action(
			'swpub_stage_refused',
			function ( $live_id, $channel, $user_id ): void {
				$this->captured[] = array(
					'event'   => 'swpub_stage_refused',
					'live_id' => (int) $live_id,
					'channel' => (string) $channel,
					'user_id' => (int) $user_id,
				);
			},
			10,
			3
		);

		add_action(
			'swpub_write_blocked',
			function ( $staged_copy_id, $live_id, $channel, $user_id ): void {
				$this->captured[] = array(
					'event'          => 'swpub_write_blocked',
					'staged_copy_id' => (int) $staged_copy_id,
					'live_id'        => (int) $live_id,
					'channel'        => (string) $channel,
					'user_id'        => (int) $user_id,
				);
			},
			10,
			4
		);

		add_action(
			'swpub_write_staged',
			function ( $staged_copy_id, $live_id, $channel, $user_id ): void {
				$this->captured[] = array(
					'event'          => 'swpub_write_staged',
					'staged_copy_id' => (int) $staged_copy_id,
					'live_id'        => (int) $live_id,
					'channel'        => (string) $channel,
					'user_id'        => (int) $user_id,
				);
			},
			10,
			4
		);

		add_action(
			'swpub_published_via_carveout',
			function ( $live_id, $channel, $user_id ): void {
				$this->captured[] = array(
					'event'   => 'swpub_published_via_carveout',
					'live_id' => (int) $live_id,
					'channel' => (string) $channel,
					'user_id' => (int) $user_id,
				);
			},
			10,
			3
		);
	}

	/**
	 * Makes this request look like core's inline-save ajax request.
	 *
	 * `wp_doing_ajax()` rather than the `DOING_AJAX` constant, deliberately: a
	 * define lasts the rest of the process and would make every later test in the
	 * suite an ajax request. `tests/test-write-guard.php` carries the same warning
	 * about `DOING_AUTOSAVE`, which core offers no filter for. This one does.
	 *
	 * @return void
	 */
	private function doing_ajax(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( self::class, 'die_handler' ) );
	}

	/**
	 * A `wp_die` handler that a test can catch.
	 *
	 * @return callable The handler.
	 */
	public static function die_handler(): callable {
		return static function ( $message = '' ): void {
			throw new Quick_Edit_Died( is_string( $message ) ? $message : '' );
		};
	}

	/**
	 * Seeds the request core's Quick Edit would send.
	 *
	 * Only `post_title` and `_status` come from the form: core's
	 * `wp_ajax_inline_save()` reads content and excerpt back off the stored row
	 * before it calls `edit_post()`, so the title is the one staged field Quick
	 * Edit can change at all.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return void
	 */
	private function seed_inline_save( array $overrides = array() ): void {
		$_POST = array_merge(
			array(
				'action'       => 'inline-save',
				'post_ID'      => (string) $this->live_id,
				'post_type'    => 'post',
				'post_title'   => 'Meridian Active, Autumn collection',
				'_status'      => 'publish',
				'_inline_edit' => wp_create_nonce( 'inlineeditnonce' ),
			),
			$overrides
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Seeding the request, not reading one. The nonce it seeds is on the line above.
		$_REQUEST = $_POST;
	}

	/**
	 * Runs the priority-0 handler, returning the refusal message if it refused.
	 *
	 * @return string|null The message, or null when the write was let through.
	 */
	private function run_inline_save(): ?string {
		try {
			do_action( 'wp_ajax_inline-save' );
		} catch ( Quick_Edit_Died $died ) {
			return $died->getMessage();
		}

		return null;
	}

	/**
	 * Establishes a staged copy for the fixture.
	 *
	 * @return WP_Post The staged copy.
	 */
	private function stage(): WP_Post {
		$current = get_current_user_id();

		wp_set_current_user( $this->publisher_id );

		$staged_copy = Staged_Copy_Repository::establish( get_post( $this->live_id ) );

		wp_set_current_user( $current );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );

		return $staged_copy;
	}

	/**
	 * The stored value of a field, cache bypassed.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name.
	 * @return string The stored value.
	 */
	private function stored( int $post_id, string $field ): string {
		clean_post_cache( $post_id );

		return (string) get_post_field( $field, $post_id );
	}

	/**
	 * AE23: a staging user's Quick Edit is refused, and nothing is written.
	 */
	public function test_a_staging_users_quick_edit_is_refused(): void {
		wp_set_current_user( $this->editor_id );

		$this->doing_ajax();
		$this->seed_inline_save();

		$message = $this->run_inline_save();

		$this->assertNotNull( $message, 'Quick Edit should have refused the write.' );
		$this->assertStringContainsString( 'This change was not saved.', $message );
		$this->assertStringContainsString( 'staged', $message );
		$this->assertStringContainsString( 'post=' . $this->live_id, $message );
		$this->assertStringContainsString( 'the editor</a>', $message );

		$this->assertSame( 'Meridian Active, Summer collection', $this->stored( $this->live_id, 'post_title' ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );

		$this->assertSame(
			array(
				array(
					'event'   => 'swpub_stage_refused',
					'live_id' => $this->live_id,
					'channel' => 'quickedit',
					'user_id' => $this->editor_id,
				),
			),
			$this->captured
		);
	}

	/**
	 * The refusal never says "pending", "unpublished changes", or "for review".
	 *
	 * The Vocabulary table is a gate on the string, not a preference: this plan
	 * ships no approval step, and two of those three words name a core state this
	 * one is not.
	 */
	public function test_the_refusal_uses_the_products_vocabulary(): void {
		wp_set_current_user( $this->editor_id );

		$this->doing_ajax();
		$this->seed_inline_save();

		$message = (string) $this->run_inline_save();

		foreach ( array( 'pending', 'unpublished changes', 'for review', 'shadow' ) as $forbidden ) {
			$this->assertStringNotContainsStringIgnoringCase( $forbidden, $message );
		}
	}

	/**
	 * A publisher's Quick Edit on an unstaged post is core's, untouched.
	 */
	public function test_a_publishers_quick_edit_passes_through(): void {
		wp_set_current_user( $this->publisher_id );

		$this->doing_ajax();
		$this->seed_inline_save();

		$this->assertNull( $this->run_inline_save() );
		$this->assertSame( array(), $this->captured );

		// What core's handler would do next, and it publishes.
		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_title' => 'Meridian Active, Autumn collection' ) ) );

		$this->assertSame( 'Meridian Active, Autumn collection', $this->stored( $this->live_id, 'post_title' ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
	}

	/**
	 * A publisher's Quick Edit on a post that already has a staged copy is refused.
	 *
	 * The capability is not the question, and neither is the surface: R55 refuses
	 * this write on every transport. Quick Edit refuses it a hook earlier so the
	 * row can say something, which is what KD15 asks for.
	 *
	 * The event is `swpub_write_blocked`, not `swpub_stage_refused`. The two mean
	 * different things and a site counting them needs them apart: one is a write
	 * that would have staged and had nowhere to show it, the other is a write to a
	 * post whose staged copy already owns the field.
	 */
	public function test_a_publishers_quick_edit_is_refused_once_a_copy_exists(): void {
		$staged_copy = $this->stage();

		wp_set_current_user( $this->publisher_id );

		$this->doing_ajax();
		$this->seed_inline_save();

		$message = $this->run_inline_save();

		$this->assertNotNull( $message, 'A refused write should be refused whoever makes it.' );
		$this->assertStringContainsString( 'This change was not saved.', $message );
		$this->assertStringContainsString( 'staged changes waiting to be published', $message );

		$this->assertSame( 'Meridian Active, Summer collection', $this->stored( $this->live_id, 'post_title' ) );
		$this->assertSame( 'Meridian Active, Summer collection', $this->stored( $staged_copy->ID, 'post_title' ) );

		$this->assertCount( 1, $this->captured );
		$this->assertSame( 'swpub_write_blocked', $this->captured[0]['event'] );
		$this->assertSame( $staged_copy->ID, $this->captured[0]['staged_copy_id'] );
		$this->assertSame( 'quickedit', $this->captured[0]['channel'] );
		$this->assertSame( $this->publisher_id, $this->captured[0]['user_id'] );
	}

	/**
	 * A forged `post_ID` for a post the user cannot edit returns silently.
	 *
	 * A refusal is an answer. Refusing here would tell any logged-in user whether
	 * some other post has staged changes, and would let them fill an audit trail
	 * with events for posts they cannot touch.
	 */
	public function test_a_forged_post_id_learns_nothing(): void {
		$this->stage();

		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( $author_id );

		$this->doing_ajax();
		$this->seed_inline_save();

		$this->assertNull( $this->run_inline_save() );
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * A request whose nonce does not verify returns silently too.
	 */
	public function test_an_unverified_request_learns_nothing(): void {
		wp_set_current_user( $this->editor_id );

		$this->doing_ajax();
		$this->seed_inline_save( array( '_inline_edit' => 'not-the-nonce' ) );

		$this->assertNull( $this->run_inline_save() );
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * A Quick Edit that changes no staged field passes through (R42).
	 *
	 * Categories, comment status, and the slug are core's business. A refusal here
	 * would break Quick Edit for everything it is mostly used for.
	 */
	public function test_a_quick_edit_of_only_non_staged_fields_passes_through(): void {
		wp_set_current_user( $this->editor_id );

		$category_id = self::factory()->category->create( array( 'name' => 'Collections' ) );

		$this->doing_ajax();
		$this->seed_inline_save(
			array(
				'post_title'    => 'Meridian Active, Summer collection',
				'post_category' => array( (string) $category_id ),
			)
		);

		$this->assertNull( $this->run_inline_save() );
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * Without the priority-0 handler the guard refuses instead of publishing.
	 *
	 * The fail-closed backstop from KTD33. If another plugin dies on this hook
	 * first, or the registration order slips, the write reaches `wp_insert_post()`
	 * on the `quickedit` channel -- where it must refuse rather than fall through
	 * to the programmatic carve-out.
	 */
	public function test_the_guard_refuses_a_quick_edit_the_handler_missed(): void {
		remove_action( 'wp_ajax_inline-save', array( Admin_Surfaces::class, 'intercept_quick_edit' ), 0 );

		wp_set_current_user( $this->editor_id );

		$this->doing_ajax();
		$this->seed_inline_save();

		$refused = null;

		try {
			wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_title' => 'Meridian Active, Autumn collection' ) ) );
		} catch ( Quick_Edit_Died $died ) {
			$refused = $died->getMessage();
		}

		$this->assertNotNull( $refused, 'The guard should refuse a Quick Edit that reached it.' );
		$this->assertStringContainsString( 'This change was not saved.', $refused );

		$this->assertSame( 'Meridian Active, Summer collection', $this->stored( $this->live_id, 'post_title' ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );

		$this->assertCount( 1, $this->captured );
		$this->assertSame( 'swpub_stage_refused', $this->captured[0]['event'] );
		$this->assertSame( 'quickedit', $this->captured[0]['channel'] );
	}

	/**
	 * Bulk edit needs no handler, because it carries no staged field (R42).
	 */
	public function test_bulk_edit_is_untouched(): void {
		require_once ABSPATH . 'wp-admin/includes/post.php';

		wp_set_current_user( $this->editor_id );

		$updated = bulk_edit_posts(
			array(
				'post'           => array( $this->live_id ),
				'post_type'      => 'post',
				'_status'        => '-1',
				'comment_status' => 'closed',
				'ping_status'    => '-1',
				'post_author'    => '-1',
				'post_parent'    => '',
				'sticky'         => '-1',
			)
		);

		$this->assertContains( $this->live_id, (array) ( $updated['updated'] ?? array() ) );

		$this->assertSame( 'Meridian Active, Summer collection', $this->stored( $this->live_id, 'post_title' ) );
		$this->assertSame( 'closed', $this->stored( $this->live_id, 'comment_status' ) );
		$this->assertNull( Staged_Copy_Repository::find_for_live( $this->live_id ) );
		$this->assertSame( array(), $this->captured );
	}
}

/**
 * Where a classic-editor save lands, and what it says when it gets there.
 *
 * `edit_post()` returns the post ID whatever `wp_update_post()` returned, so the
 * guard's containment is invisible to `post.php`: the row is untouched and the
 * classic editor still reports success. These two surfaces are what say
 * otherwise, and they have to say it themselves.
 */
class Test_Classic_Arrival extends WP_UnitTestCase {

	/**
	 * The published post.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * A user whose saves stage.
	 *
	 * @var int
	 */
	private int $editor_id;

	/**
	 * Publishes a post and loads the admin functions these surfaces run beside.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$editor = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$editor->add_cap( 'publish_posts', false );
		$this->editor_id = $editor->ID;

		// No role holds the direct-publish capability, so the user whose classic
		// save publishes is one a site granted it to.
		$publisher = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$publisher->add_cap( Capabilities::PUBLISH_DIRECTLY_POSTS );

		wp_set_current_user( $publisher->ID );

		$this->live_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Meridian Active, Summer collection',
				'post_content' => 'Launches in June.',
			)
		);
	}

	/**
	 * Restores the page the guard reads to name the classic channel.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['pagenow'] );

		parent::tear_down();
	}

	/**
	 * Saves as the classic editor does, returning where post.php would send them.
	 *
	 * @return string The redirect location.
	 */
	private function classic_save(): string {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The admin page name is the only signal core offers for the classic channel, so the test has to stand where that save stands. `tear_down()` puts it back.
		$GLOBALS['pagenow'] = 'post.php';

		wp_set_current_user( $this->editor_id );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => 'Launches in July.' ) ) );

		return (string) apply_filters(
			'redirect_post_location',
			add_query_arg( 'message', 1, (string) get_edit_post_link( $this->live_id, 'raw' ) ),
			$this->live_id
		);
	}

	/**
	 * Stands the request on an edit screen, in one editor or the other.
	 *
	 * `WP_Screen` reports the post screen as a block editor by default, and each
	 * editor corrects it on the way in: `wp-admin/edit-form-advanced.php` sets it
	 * false and `wp-admin/edit-form-blocks.php` sets it true, both before
	 * `admin-header.php` fires `admin_notices`. This is that, and nothing else.
	 *
	 * @param bool $block_editor Which editor is rendering.
	 * @return void
	 */
	private function on_edit_screen( bool $block_editor ): void {
		set_current_screen( 'post' );
		get_current_screen()->is_block_editor( $block_editor );
	}

	/**
	 * A staged classic save redirects to the staged copy, flagged as an arrival.
	 */
	public function test_a_staged_classic_save_lands_on_the_staged_copy(): void {
		$location = $this->classic_save();

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$this->assertInstanceOf( WP_Post::class, $staged_copy );
		$this->assertStringContainsString( 'post=' . $staged_copy->ID, $location );
		$this->assertStringContainsString( 'swpub_forked=1', $location );

		clean_post_cache( $this->live_id );

		$this->assertSame( 'Launches in June.', (string) get_post_field( 'post_content', $this->live_id ) );
	}

	/**
	 * A save that stages nothing keeps core's own redirect.
	 */
	public function test_a_publishing_classic_save_keeps_cores_redirect(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As above; `tear_down()` puts it back.
		$GLOBALS['pagenow'] = 'post.php';

		$default = add_query_arg( 'message', 1, (string) get_edit_post_link( $this->live_id, 'raw' ) );

		wp_update_post( wp_slash( array( 'ID' => $this->live_id, 'post_content' => 'Launches in July.' ) ) );

		$this->assertSame(
			$default,
			(string) apply_filters( 'redirect_post_location', $default, $this->live_id )
		);
	}

	/**
	 * The arrival notice on the classic edit screen carries the routes U6 never gives it.
	 */
	public function test_the_arrival_notice_renders_with_its_routes(): void {
		$this->classic_save();

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$_GET['post']         = (string) $staged_copy->ID;
		$_GET['swpub_forked'] = '1';

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- `post.php` sets this global before `admin_notices` fires, and the notice reads the post being edited from it. Unset again below.
		$GLOBALS['post'] = $staged_copy;

		$this->on_edit_screen( false );

		ob_start();
		Admin_Surfaces::arrival_notice();
		$notice = (string) ob_get_clean();

		unset( $GLOBALS['post'] );

		$this->assertStringContainsString( 'Your edit was staged', $notice );
		/*
		 * Compared with entities decoded on both sides. The separator in these
		 * URLs is written `&`, `&#038;`, or `&amp;` depending on which of
		 * `wp_nonce_url()`, `esc_url()`, and `wp_kses_post()` touched it last,
		 * and none of those differences is what this test is about.
		 */
		$decoded = html_entity_decode( $notice, ENT_QUOTES );

		$this->assertStringContainsString(
			html_entity_decode( Review_Link::for_staged_copy( $staged_copy->ID ), ENT_QUOTES ),
			$decoded
		);
		$this->assertStringContainsString( 'Review staged changes', $notice );
		$this->assertStringContainsString(
			html_entity_decode( Post_List::discard_url( $this->live_id ), ENT_QUOTES ),
			$decoded
		);
		$this->assertStringContainsString( 'Discard staged changes', $notice );
		$this->assertStringContainsString( 'post=' . $this->live_id, $notice );
		$this->assertStringContainsString( 'block editor', $notice );
	}

	/**
	 * The block editor gets no server-rendered notice; it has its own.
	 */
	public function test_the_block_editor_screen_renders_no_notice(): void {
		$this->classic_save();

		$staged_copy = Staged_Copy_Repository::find_for_live( $this->live_id );

		$_GET['post']         = (string) $staged_copy->ID;
		$_GET['swpub_forked'] = '1';

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- `post.php` sets this global before `admin_notices` fires, and the notice reads the post being edited from it. Unset again below.
		$GLOBALS['post'] = $staged_copy;

		$this->on_edit_screen( true );

		ob_start();
		Admin_Surfaces::arrival_notice();
		$notice = (string) ob_get_clean();

		unset( $GLOBALS['post'] );

		$this->assertSame( '', $notice );
	}

	/**
	 * A published post's classic screen gets no staged-copy notice.
	 */
	public function test_the_published_posts_screen_renders_no_notice(): void {
		$_GET['post']    = (string) $this->live_id;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As above; unset again below.
		$GLOBALS['post'] = get_post( $this->live_id );

		$this->on_edit_screen( false );

		ob_start();
		Admin_Surfaces::arrival_notice();
		$notice = (string) ob_get_clean();

		unset( $GLOBALS['post'] );

		$this->assertSame( '', $notice );
	}
}
