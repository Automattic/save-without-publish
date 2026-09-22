# Save without Publish

Updating a published story shouldn't mean gambling with what readers see.

Save without Publish lets editors revise and review published content without it going live as soon as they hit save. A change to a published post is staged as a private staged copy, and stays staged until someone publishes it deliberately. The published post keeps serving, unchanged, until someone deliberately publishes the change.

What it delivers is that nothing publishes without a deliberate act. It is not an approval workflow: there is no reviewer role, no queue, and no sign-off. Anyone who can edit a staged copy can publish it, and that is on purpose.

Review happens in WordPress's own revisions view, inside the editor, where the change is marked on the blocks that carry it — on WordPress 7.0 and later. Earlier versions review on core's classic compare screen instead, since the in-editor view does not exist yet. Both are core's screens, unmodified: there is no custom diff tool, no editorial dashboard, and no new admin screens.

## Vocabulary

These are the words the interface and the code use, one per thing.

| Term | Means | Where it is used |
| --- | --- | --- |
| **published post** | The post readers see. Keeps its ID, URL, date, author, comments, and terms throughout. | Everywhere. Never "live post" in an editor-facing string. |
| **staged** | The state: a change that exists, is complete, and is waiting for a deliberate act to go live. | The post status, short labels, and sentences alike. |
| **staged copy** | The private post row holding the change. | Code and docs: `Staged_Copy_Repository`, `$staged_copy`, `_swpub_staged_copy_id`. |
| **publishing** | Applying the staged copy's content to the published post, then deleting the copy. | Everywhere. Never "merging" in an editor-facing string. |
| **drift** | The published post changed after the copy was staged, so publishing would overwrite someone else's edit. | Code and docs only. Editors are told what happened, not the word. |
| **stage changes** | The deliberate act: starting a staged copy when your save would otherwise publish. | The editor's Summary panel and the posts list row action, as a verb. |
| **publish directly** | Writing to a published post without staging the change. The capability deciding it is granted to no role. | `swpub_publish_directly_posts` on a role, `swpub_publish_directly` resolved per post, `swpub_can_publish_directly` to filter it. |

## Requirements

- WordPress 6.8+ (review is core's in-editor revisions view on 7.0 and later, and core's classic compare screen below that)
- PHP 8.2+
- The block editor

## Who stages, and who publishes

Two rules, in this order.

### Once a staged copy exists, the staged copy owns those three fields

A write that would change the title, content, or excerpt of a published post that has a staged copy is **refused**. On any transport: the block editor, the classic editor, Quick Edit, WP-CLI, XML-RPC, an application password or OAuth client, or a plugin calling `wp_update_post()`. Whatever the writer's capability, and whether or not anyone is logged in. Nothing is written on either side, and the published post keeps serving.

Nothing exempts this. Not a role, not a capability grant, not a filter. The plugin's own publishing write is the single exception, and it is scoped to the one post it is publishing.

The refusal is `swpub_live_locked`, HTTP 409, over REST and the staging route. 409 rather than 403 because nothing is wrong with the caller: the post is in a state that has to be resolved, and the same request succeeds once it is. Its error data carries `live_id`, `staged_copy_id`, and `edit_url`.

Three things follow that are easy to miss:

- **It is scoped to three fields, not to the post.** A write that touches no staged field (terms, meta, slug, a featured image, a sticky toggle) applies to the published post exactly as core, with no staging, no refusal, and no events. That is deliberate, and load-bearing: those fields cannot live on a staged copy at all, so the published post is the only place to change them. A write carrying both kinds is refused whole rather than half-applied.
- **The published post is never edited from a distance.** This rule used to divert instead of refuse: the write's staged fields were redirected into the copy and answered 200. It was withdrawn because the value being diverted is composed against the *published* row. Someone editing the published post is reading published words, so their save carries the published body with one change in it, and that body has never seen the staged edits. Writing it into the copy reverted all of them. Since `post_content` is a single field, one edited paragraph replaced the whole staged body. Making the divert safe would mean merging two bodies of text, which is a diff tool this plugin does not have.
- **A refusal changes nothing, so it cannot register as drift.** The published post's `post_modified` is untouched, so a refused write never makes the next publish ask about a change that did not happen.
- **The staged copy cannot be left in any other status, by any transport.** Trash discards it, through the same posts-list action as always; nothing else publishes it except the merge. A write that tries is refused whole and announced on `swpub_write_blocked`, the same as a write to the published post. One path is repaired rather than refused: `wp_publish_post()` writes the status column directly and never reaches the hook the refusal lives on, so a copy it publishes is put back and `swpub_staged_status_reverted` says so. That is the function cron runs for a scheduled post, and it is public API any plugin may call.

### Before there is a copy, the first save decides

The question is answered per post: does this user hold `swpub_publish_directly_posts`?

**No role holds it.** The plugin ships the capability granted to nobody, so on a fresh install every save to a published post stages, whoever makes it. An administrator gets the same staged copy a contributor does. Granting it is how a site names its exceptions:

```php
get_role( 'editor' )->add_cap( 'swpub_publish_directly_posts' );
```

Each post type has its own, built from the type's own publish capability, so a grant on posts is not silently a grant on pages: `publish_pages` gives `swpub_publish_directly_pages`, and a type declaring `capability_type => 'brief'` gives `swpub_publish_directly_briefs`.

It is the plugin's own capability rather than the post type's `publish_posts`, which decided this until now, because the two answer different questions. `publish_posts` asks whether this user may put content in front of readers at all, and every editor and author holds it. This asks whether their ordinary save may go straight to readers with no staged copy in between. A site that installs this plugin has already said the answer is usually no, and a typo fix that does not need staging is a smaller exception than a role.

- **A user holding the capability** publishes, exactly as without the plugin. Staging is theirs to ask for: **Stage changes** in the editor's Summary panel carries whatever they have typed, unsaved included, onto a staged copy without touching the published post, and the same action on the posts list starts a copy from the list.
- **Everyone else stages**, in the block editor and the classic editor alike. Their save writes to a private staged copy instead of the published post, they land on that copy and are told why, and every later save continues it. This needs no setup and no interface: in the block editor their primary button says **Stage changes** and does exactly that, and in the classic editor the save arrives on the staged copy with a notice saying where it went. Once a copy already exists, the button reads **Save** instead, for everyone: the title, content, and excerpt are refused whoever tries to save them, so "Stage changes" would promise the one thing that click cannot do, and saving is disabled outright while any of those three is dirty. The title cannot be typed into on that screen, and the excerpt field is not shown there at all. Everything else on that screen -- the slug, publish date, author, categories, tags, and featured image -- cannot be staged, so it still saves from there, straight to the published post, exactly as the warning notice on that screen says.
- **Quick Edit refuses.** Its row is rebuilt from the published post, so a staged Quick Edit would redisplay the old title with no path to the copy: an edit that looks like it vanished. Instead nothing is written on either side, and the row shows a message pointing to the editor. The refusal is keyed on whether the save would stage, not on the capability, so a user holding it is refused too when the post already has a staged copy.
- **A write with nobody logged in** publishes, as core does. Cron, an importer, or code running as user 0 has no capability to read and no person waiting on a staged copy. Once the post has a copy, the first rule governs these writes like every other.
- **A programmatic write by a user without the capability** publishes, and says so. That is the seam below, and with no role holding the capability it is the path every programmatic write now takes.
- **Anything else stages**, including a transport the plugin could not identify. A front-end editor, a custom admin screen, or a plain `wp_update_post()` by a logged-in user stages rather than publishes, because a default that published would be the hole this rule exists to close.

A site that wants staging even for the users it granted, belt and braces, filters the bypass off:

```php
add_filter( 'swpub_can_publish_directly', '__return_false' );
```

### The programmatic first-save seam

A first write to a post that has no staged copy, made by a user without the direct-publish capability, arriving over REST with a token (application password, OAuth), XML-RPC, or WP-CLI, publishes as core does. Since no role holds that capability, this covers every programmatic write, an administrator's included.

It is open by default deliberately. Closing it by default would divert the writes of every sync plugin, headless publisher, and scheduled script already running as a low-capability user into staged copies nobody asked for and nobody is watching.

The price is disclosed rather than hidden. Every use of the seam fires `swpub_published_via_carveout` naming the channel and the user, and one filter closes it site-wide:

```php
add_filter( 'swpub_enforce_programmatic_first_save', '__return_true' );
```

**The seam reopens.** Containment is scoped to the copy, so publishing or discarding a staged copy returns that post to its first-save state, where the carve-out applies again. A post is not permanently closed by having been staged once. Closing the seam with the filter is the remedy for a site that needs it shut.

The plugin ships no log of its own, so on a default install the seam's use leaves no trail unless something is listening. The [Actions](#actions) section carries a subscriber to paste in.

### What this means for an integration

An integration that writes the title, content, or excerpt of a published post which happens to have a staged copy has that write refused. Its content does not reach readers until someone clears the copy.

There is no filter for this, by design: the first rule above has no exemptions. Over REST the refusal is `swpub_live_locked` with HTTP 409, which a client can match on. From WP-CLI, XML-RPC, or an in-process `wp_update_post()` it is core's own `empty_content` failure, whose message names the wrong problem — the universal abort inside `wp_insert_post()` carries no error of its own. Subscribe to [`swpub_write_blocked`](#actions) for the real reason on every transport.

This is louder than what it replaced, and deliberately so. It used to be an ordinary 200 with the write folded into the staged copy, which kept integrations quiet by spending somebody's staged work: the write had been composed against the published content, so it reverted every staged change it did not carry.

Writes that touch none of the three staged fields — terms, meta, slug, featured image — are unaffected and never were.

The remedies are:

- Publish or discard the staged copy. The post returns to its first-save state.
- Turn the plugin off with `swpub_is_enabled`, which stops staging site-wide while leaving existing copies contained.

That is the whole list, and it is worth being clear about what is not on it. Granting the integration's account the direct-publish bypass with `swpub_can_publish_directly` does not help here. It decides first saves, and a granted account writing to a post that already has a copy is refused like any other. There is no setting that lets a caller write past a staged copy.

A site that runs integrations against published content should decide about this before adopting the plugin, not after.

## How it works

1. A change is staged: automatically on save for anyone without the direct-publish capability, which by default is everyone, or by choosing **Stage changes** for someone who holds it.
2. The staged copy is a private post holding the change. The published post stays editable for everything the copy cannot hold — terms, featured image, slug, meta — and says so: opening it shows a notice naming whose staged changes are waiting, locks the canvas and the title, takes the excerpt field off the screen, states that all three are edited on the copy, and names the fields — slug, publish date, and author among them — that cannot be staged and so go live at once when saved from that screen.
3. They keep editing. Every save is an ordinary WordPress revision on the staged copy, with its own author and timestamp.
4. Anyone with permission reviews the change in the editor's own revisions view, which shows the staged words against the words as published, marked on the blocks that carry them. It is core's screen with the parts that do not apply to a staged copy taken out: there is no timeline to scrub, because a staged copy has two states rather than a history, and no sidebar, because it listed those same two states. That view diffs blocks, so a change to only the title or the excerpt does not show there; when either changed, **Compare as text** is offered alongside it, opening core's classic compare screen instead — the one core surface that diffs those fields too. Below WordPress 7.0, where the in-editor view does not exist yet, that classic screen is the review itself, reached the same way review always is.
5. Publishing applies the staged content to the published post, adopts the staged revisions into its history, and removes the staged copy.

The published post keeps its ID, URL, publish date, author, comments, terms, and featured image throughout. Only the title, content, and excerpt change.

## What can be staged

Title, content, and excerpt.

Everything else, including status, slug, publish date, author, terms, and featured image, is copied to the staged copy for fidelity but locked. Changing any of them means editing the published post directly, which takes effect immediately — and stays available while a staged copy exists, since the published post is the only place those changes can be made. The published post's own editor notice names this while a staged copy exists, so the risk is visible rather than assumed.

This is a deliberate limit rather than an unfinished one. The review surface is core's revision screen, and core revisions only store those three fields. Staging a term change would mean staging something the review cannot show and the pre-publish snapshot cannot roll back.

A scheduled publish time (below) is not staged either, for the same reason the publish date itself is not: it lives beside the copy, in its own meta, never on a field the merge or the review surface has any idea what to do with.

## Publishing a staged change

While a staged copy is open, the editor's own header buttons are renamed and re-aimed: **Publish** becomes **Publish changes** and publishes the change to the live post, and **Save draft** becomes **Save changes**, since a staged copy is not a draft of an unpublished post. Publishing does not ask first. The button says what it does, the Status row beside it reads **Staged**, and every merge writes the published post's previous content as a revision before writing the new one, so the act is undoable from core's own revision screen. What publishing did is said afterwards, on the published post it lands on.

Publishing saves first when there is anything unsaved in the editor, because the merge reads the staged copy from the database and words still on screen would otherwise be left behind. If that save fails, nothing is published and the published post is unchanged.

### When the published post has changed

If someone changed the published post's title, content, or excerpt after staging began, publishing is refused. The editor is shown what changed, on the live post's revision screen, opened in a new tab so their staged edits survive the trip.

If something else changed instead -- a category, the featured image, the slug -- publishing is still refused, but says so plainly: that update will not be overwritten, because publishing never touches those fields. There is no revision screen to send anyone to in that case, since there is nothing to diff.

Either way, they can confirm and overwrite. The confirmation names the exact published state they were shown -- its content as well as its timestamp -- as one token: if the post changes again in between, or if the words in it change without the timestamp moving at all, the confirmation no longer matches and the merge is refused again.

Every merge writes the published post's previous content as a revision first, so any publish can be rolled back from the core revision screen.

Not everything that can change the published post is caught. A term, meta, or featured-image change made through its own API -- `wp_set_post_terms()`, `update_post_meta()`, `set_post_thumbnail()` -- rather than a post save leaves the row untouched, so there is nothing here to see it move. That is not a gap in what a merge could overwrite: it never writes those fields either.

### Publishing at a set time

A schedule is a deferred **Publish changes**, by a named person, and nothing more: at the set time the same merge runs, as that person, refused for every reason it refuses a click. There is no second publishing path here to keep in step with the first.

Whoever may publish the copy may schedule it -- the same authority `Capabilities::current_user_can_manage()` resolves everywhere else, checked against the published post rather than the copy (KTD13). The scheduled time lives in its own meta on the copy, never on its `post_date`: that field already means the moment staging began, which `Merge_Marker` records as `forked_at`, and it stays locked for exactly that reason -- scheduling a publish does not change what "staged" already means.

On the staged copy, the Summary panel carries a **Publish at** row beside **Staged changes**. Unscheduled, it reads **Immediately** -- the true answer to what the next **Publish changes** does, not a placeholder for a control nobody has used yet. Opening it offers the same date and time picker core's own Publish row uses, and **Schedule**; once a time is set the row shows it, and the same control offers **Change** or **Clear**. The staged copy's own notice names the scheduled time too, and if a scheduled run was refused, a second notice says when it was due and why -- the published post changed first, the person who scheduled it can no longer publish, the pair is stranded, or staging was off at the time -- without repeating the ordinary drift warning, since a schedule refused for drift already says the more specific version of the same fact. The published post's own notice names the time as well, alongside who staged the change and what is locked here. The posts list reads **Staged changes, Scheduled**, or, after a refusal, **Staged changes, Schedule stopped**.

Over REST, scheduling and cancelling are their own route:

```
POST   /swpub/v1/schedule/{id}   { at }
200                              { scheduledFor, scheduledForLocal, scheduledBy }

DELETE /swpub/v1/schedule/{id}
200                              { scheduled: false }
```

`at` is ISO 8601; a value with no offset is treated as site-local, exactly as the posts controller's own `date` parameter is. Cancelling a copy that was not scheduled is not an error.

**If the published post changed in between**, the default is the same one an unattended run always gets: abort, keep the copy, mark it, and say so -- there is nobody to ask, so nothing is confirmed on a schedule's behalf. The copy is not deleted and not retried; it stays exactly as staged, waiting for a person to review it, reschedule it, or publish it by hand. A site that would rather the staged words win regardless answers `swpub_scheduled_publish_overrides_drift`, which can discriminate by what actually changed -- publishing past a category or a featured image while still asking about a change to the words themselves:

```php
add_filter(
    'swpub_scheduled_publish_overrides_drift',
    function ( $override, $copy_id, $live_id, $kind ) {
        return 'other' === $kind;
    },
    10,
    4
);
```

Returning `true` proceeds exactly as an editor's own confirmation would: the merge takes its snapshot and fires `swpub_drift_overridden`, named below.

**A late run still publishes.** WP-Cron makes no promise about exact timing, and a schedule that missed its moment by a few minutes on a quiet site is not a schedule that should be abandoned. `swpub_scheduled_publish_ran` reports how late a run was; a site that wants a hard cutoff instead reads the scheduled time off the copy's meta inside its own `swpub_pre_merge` veto, which already gates every merge, scheduled or not.

**A refusal is not retried.** Once a schedule cannot be kept -- the published post changed and nothing overrode it, the scheduler lost the capability, the pair is stranded, staging is switched off at the moment it comes due -- it is cleared rather than left to fire again on the next cron tick. The reason is recorded on the copy and on `swpub_scheduled_publish_refused`, and the copy reads `schedule-refused` in `wp swpub list`. Nothing is republished by turning staging back on: the copy is exactly as it was staged, and someone reschedules it.

**A run that cannot happen *yet* is deferred, not lost.** A merge already in flight on the same post, or an event that somehow fires ahead of its own time, puts the run off by a few minutes rather than refusing it -- the schedule stands and the time the editor asked for is untouched. This matters more than it looks: cron spends a single event *before* running the hook it fires, so a run that simply declined to act would leave the copy reading as scheduled with nothing left to fire it, and the publish would never happen and never say why. The one in-flight case that is refused rather than deferred is a merge stranded past its attempt budget, because only a person with `wp swpub repair` can clear that, and deferring against it would wait forever.

**If `WP_CLI` is running**, `wp swpub schedule <copy> --at=<datetime>` schedules as WP-CLI's own `--user` global, the same flag every command already reads. `wp swpub unschedule <copy>` cancels. `wp swpub publish <copy> [--confirm=<token>]` is the merge itself, from the command line, for a script that wants to publish without waiting on cron. `wp swpub run-due` fires every schedule whose time has come -- what a system cron line calls on a site running `DISABLE_WP_CRON`, and the right thing to run by hand after an incident to catch anything WP-Cron dropped. `wp swpub list` gains a `scheduled_for` column, and reports `schedule-refused` in its state column for a copy whose last scheduled run did not keep.

## Discarding a staged change

The posts list shows **Staged changes** beside any post that has one, with a single row action, **Edit staged changes**. Reviewing and discarding are decisions about the staged copy, so they are offered where that copy is open: its notice carries **Review staged changes**, **View the published post**, and **Discard staged changes**.

Discarding is permanent. Staged copies are deleted rather than trashed, so there is no trash to recover them from, and the confirmation says so. The published post is never affected by a discard.

## REST

A staged save is a success, and every client is told so in the shape it understands. Which shape depends on how the request authenticated.

### Machine clients

A REST write authenticated with anything but a cookie -- an application password, OAuth, any token client -- returns **200**. The body is the published post's true state, which is to say the change is not in it. What says where the change went is the `swpub` field, added to the schema of every post type staging covers:

```json
"swpub": {
    "staged": true,
    "staged_copy_id": 456,
    "edit_url": "https://example.com/wp-admin/post.php?post=456&action=edit"
}
```

`swpub` is `null` on every response that staged nothing, including a GET of a post that already has a staged copy. It reports what this request did, not what the post holds. `edit_url` is empty when the acting user cannot edit the staged copy.

A write to a post that already has a staged copy never gets this far. It is refused first, on every REST client, with `swpub_live_locked` and HTTP 409.

The `swpub_staged` 409 below is a different thing and never reached a token client: it is nonce-gated, so only cookie-authenticated editor saves ever met it.

### The block editor

The editor's own protocol is a deliberate route. A save that is going to stage is sent to it instead of the post endpoint:

```
POST /swpub/v1/stage/{id}     { title?, content?, excerpt? }
200                           { stagedCopyId, editUrl }
```

Anything the route cannot stage is refused rather than dropped: a request carrying a status, slug, term, or featured image change answers `swpub_unstageable_field` with HTTP 400, naming the fields.

The route stages a first change only. Asked to stage onto a post that already has a copy, it answers `swpub_live_locked` with HTTP 409 and does not write, because the request was composed in an editor showing the published words and honouring it would replace what the copy holds rather than add to it.

Underneath that sits the backstop. A cookie-authenticated save that reaches `/wp/v2/{type}/{id}` and would stage is redirected onto the staged copy anyway and answered:

```
409  swpub_staged  { staged_copy_id, edit_url }
```

The write succeeded; the staged copy holds the change. The 409 exists because the protocol above is JavaScript, and JavaScript that failed to load must not be the difference between staging and publishing. It fires `swpub_staged_via_backstop` when it engages for a save that could have taken the staging route, which is an alarm rather than a report: on a healthy site nothing hears it.

A write that would change a locked field on a staged copy is refused with `swpub_field_locked`, HTTP 403, and a `field` key naming the parameter. That code is contract, not an incident identifier: the editor keys its own recovery on it, and integrations may match on it.

`meta` is the one locked field that does not refuse the write. A staged copy's ordinary save often carries `meta` it never asked to stage -- an SEO plugin's own panel, a custom field, core's own footnotes -- and refusing the whole request for it used to cost the title, content, and excerpt sent alongside it. Instead, any key outside the staged-meta set is silently dropped from the request rather than written, the save proceeds with everything else it carried, and the drop is named twice: on the `X-SWPub-Meta-Dropped` response header, a comma-separated list of the keys, and on `swpub_meta_keys_dropped` below. The staged-meta set is empty today -- no meta key is staged yet -- so every key currently arrives dropped; a client relying on a meta write to a staged copy should not assume it landed without checking one of those two. That includes core's own footnotes: a footnote added on a staged copy keeps its marker in the content and loses its text, which lives in meta, until the staged-meta set has members. A write refused for some other locked field is refused whole, meta included, and announces nothing about its meta.

`swpub_live_locked` is contract in the same way, and will not be renamed:

```
409  swpub_live_locked  { live_id, staged_copy_id, edit_url }
```

`edit_url` is the remedy for a person, so a client rendering the refusal can send its reader somewhere without knowing this plugin's routes. `staged_copy_id` is the remedy for a machine, which can read, publish, or discard the copy through the plugin's own routes.

## Actions and filters

### Actions

```php
do_action( 'swpub_staged_created',       int $staged_copy_id, int $live_id, int $user_id );
do_action( 'swpub_staged_edited',        int $staged_copy_id, int $live_id, int $user_id );
do_action( 'swpub_write_staged',         int $staged_copy_id, int $live_id, string $channel, int $user_id );
do_action( 'swpub_staged_via_backstop',  int $staged_copy_id, int $live_id, int $user_id );
do_action( 'swpub_stage_refused',        int $live_id,        string $channel, int $user_id );
do_action( 'swpub_write_blocked',        int $staged_copy_id, int $live_id, string $channel, int $user_id );
do_action( 'swpub_staged_status_reverted', int $staged_copy_id, int $live_id, string $attempted_status, int $user_id );
do_action( 'swpub_scheduled_publish_ran',     int $live_id, int $staged_copy_id, string $scheduled_for, int $late_by, int $user_id );
do_action( 'swpub_scheduled_publish_refused', int $staged_copy_id, int $live_id, string $reason, ?WP_Error $error, int $user_id );
do_action( 'swpub_published_via_carveout', int $live_id,      string $channel, int $user_id );
do_action( 'swpub_staging_write_failed', int $live_id,        string $reason,  int $user_id );
do_action( 'swpub_drift_overridden',     int $staged_copy_id, int $live_id, int $user_id, string $confirmed, string $drift_kind );
do_action( 'swpub_merge_completed',      int $live_id,        array $payload );
do_action( 'swpub_staged_stranded',      int $staged_copy_id, string $reason, int $live_id );
do_action( 'swpub_staged_recovered',     int $staged_copy_id );
do_action( 'swpub_meta_keys_dropped',    int $staged_copy_id, array $keys, int $user_id );
```

`swpub_write_staged` fires every time a write is diverted into a staged copy, whatever transport it came from. That is the first save; a later one is refused rather than diverted, and fires `swpub_write_blocked`. It adds to `swpub_staged_created` and `swpub_staged_edited` rather than replacing them: a diverted write is a save of the staged copy, so those fire too. Its argument order matches theirs deliberately, so a handler copied between them reads the same posts. What it carries that they cannot is the channel, which is the difference between an editor staging a change and an integration finding its first write staged.

`$channel` is one of `rest-cookie`, `rest-token`, `xmlrpc`, `classic`, `cli`, `cron`, `quickedit`, or `internal`. It is best-effort: core exposes no request origin at this seam, so each is inferred from a constant, a request header, or the admin page in play. `rest-token` covers application passwords, OAuth, and anything else arriving without a `wp_rest` nonce, which are not reliably distinguishable from each other here. The channel labels an event; it decides nothing.

`swpub_staged_via_backstop` fires when a save was staged by the 409 backstop rather than the staging route, and only where that route was available to the saver. It means the editor's own protocol did not run. On a healthy site it never fires.

`swpub_stage_refused` fires when a write that would have staged was refused instead, currently only on the `quickedit` channel. Nothing was written on either side.

`swpub_write_blocked` fires on two refusals, both with nothing written on either side. A write to a published post that already has a staged copy is the first, and the published post is unchanged and so is the copy, which is the point. A write giving the staged copy itself any status but staged or trash is the second: left alone, that write would take the copy out of containment entirely, publishing it as an ordinary post of its own while the post it stages sits untouched. `$live_id` is `0` on this second trigger only when the published post it staged is already gone. This is the event to watch to find out which of your integrations a staged copy is standing in front of, and it fires on every transport, including one with no authenticated user behind it:

```php
add_action(
	'swpub_write_blocked',
	function ( $staged_copy_id, $live_id, $channel, $user_id ) {
		error_log( "swpub: write to {$live_id} via {$channel} refused; copy {$staged_copy_id}" );
	},
	10,
	4
);
```

`swpub_staged_status_reverted` fires when a staged copy's status was put back after something changed it outside `wp_insert_post()`. It is the twin of `swpub_write_blocked` for the one path a refusal cannot reach: `wp_publish_post()` writes the status column with a query of its own, so the copy is repaired rather than stopped, and by the time this fires it is staged again and the published post was never involved. Cron calls that function for a scheduled post, so `$user_id` is `0` on that route. Subscribe to it to find whatever is reaching for staged copies:

```php
add_action(
	'swpub_staged_status_reverted',
	function ( $staged_copy_id, $live_id, $attempted_status, $user_id ) {
		error_log( "swpub: copy {$staged_copy_id} was given {$attempted_status}; put back" );
	},
	10,
	4
);
```

`swpub_meta_keys_dropped` fires when a staged copy's own save carried a meta key outside the staged set. The save is not refused for it -- `meta` is the one locked field that is stripped rather than refused, described under [REST](#rest) above -- so this is the one place a site sees that the key did not follow the rest of the save onto the copy.

`swpub_published_via_carveout` fires on every use of the programmatic first-save seam. The plugin logs nothing itself, so this is where a site sees the seam being used:

```php
add_action(
    'swpub_published_via_carveout',
    function ( $live_id, $channel, $user_id ) {
        error_log( "swpub: post {$live_id} published via {$channel} by user {$user_id}" );
    },
    10,
    3
);
```

`swpub_staging_write_failed` fires when a first write was diverted but the staged copy could not be written. The whole update is aborted, so the published post is unchanged and so is the copy. The caller receives core's `empty_content` failure, whose message is wrong by construction; `$reason` is the real one.

`swpub_drift_overridden` fires only when a merge proceeds over a change made after staging began. It is the one moment the plugin knowingly overwrites somebody else's edit. `$confirmed` is the state token the editor sent back, naming the exact published state -- content included -- they were shown; `$drift_kind` is `content`, `other`, or `unknown` (below).

`swpub_merge_completed` fires once per applied merge, after the staged copy is gone. Its payload carries what nothing can read back afterwards:

```php
array(
    'staged_copy_id' => 456,                    // the staged copy, now deleted
    'snapshot_id'    => 789,                    // revision holding the pre-merge published content
    'staged_by'      => 12,                     // who staged it
    'forked_at'      => '2026-08-13 21:28:06',  // when staging began (GMT)
    'merged_by'      => 12,                     // who published it
    'drifted'        => true,                   // had the published post moved
    'override'       => 'e3b0c44298fc1c14...',  // the state token confirmed against, or ''
    'drift_kind'     => 'content',              // 'content', 'other', or 'unknown' when it drifted
    'revisions'      => array( 15, 16, 17 ),    // staged revisions adopted
    'attachments'    => array( 44 ),            // media moved to the published post
    'scheduled_for'  => '2026-09-22 09:00:00',  // GMT time this was scheduled to run at, or '' when it was not scheduled (VIPPROD-1247)
)
```

`snapshot_id` is the revision to restore to if a merge turns out to have been wrong. `drifted` with `override` records whether the person publishing was shown someone else's change and confirmed past it. `drift_kind` says what that change actually was: `content` means the published post's title, content, or excerpt moved -- the one thing this merge could have overwritten; `other` means something else changed, such as a category, that the merge never writes and could not have overwritten; `unknown` means the copy predates the fingerprint this distinction relies on and was never backfilled, so the plugin cannot tell the two apart and treats it as it always has.

The plugin implements no notification and no audit log of its own. These actions are the seam for a site that wants either.

### Filters

```php
// Stage edits to published posts. Return false to disable staging entirely.
apply_filters( 'swpub_is_enabled', bool $enabled );

// Whether this user's saves may publish this post directly rather than stage.
// Three answers: null, false, true. See below.
apply_filters( 'swpub_can_publish_directly', ?bool $decision, int $post_id, int $user_id );

// Whether a programmatic first save stages instead of publishing.
// Return true to close the seam site-wide. Default false.
apply_filters( 'swpub_enforce_programmatic_first_save', bool $enforce, int $live_id );

// Allow a merge. Return a WP_Error to veto it before anything is written.
apply_filters( 'swpub_pre_merge', bool $allowed, int $live_id, int $staged_copy_id );

// Let a scheduled publish proceed over drift. Default false: refuse, keep, and
// mark the copy, the same as an unattended run with nobody to confirm past it.
apply_filters( 'swpub_scheduled_publish_overrides_drift', bool $override, int $staged_copy_id, int $live_id, string $drift_kind );
```

`swpub_can_publish_directly` answers in three states, because a site needs to be able to say yes as well as no:

- **`null`**, the default, falls through to the `swpub_publish_directly_posts` capability, which no role holds. Whoever has been granted it publishes without staging, and everyone else stages.
- **`false`** forces staging, whatever the user's role says, including a role that has been granted the capability.
- **`true`** grants the bypass per post, to a user who does not hold the capability. This is how an integration keeps publishing: answer for its own account, rather than granting the capability to a role every human shares.

```php
add_filter(
    'swpub_can_publish_directly',
    function ( $decision, $post_id, $user_id ) {
        $sync = get_user_by( 'login', 'acme-sync' );

        if ( ! $sync || $sync->ID !== $user_id ) {
            return $decision;
        }

        // Scoped to the application password it authenticates with, so a
        // stolen browser session is not the same key.
        return null !== rest_get_authenticated_app_password();
    },
    10,
    3
);
```

Decide from server-derived facts only: the user, the post, the site's own configuration. Never from anything the request controls, such as a User-Agent string, a custom header, or a query argument, because any caller can send those. The signature carries no channel for the same reason: this filter also answers on the screens that render the editor's chrome, and a per-transport answer would make the interface say one thing and the write path do another.

**A grant is entry, never exemption.** It decides first saves. Once a post has a staged copy, a write to its title, content, or excerpt is refused regardless of what this filter returns.

One filter answers this question, and every surface that asks reads the same answer: the editor's context, the posts list, the save seam, and the write guard.

### Identifying a staged post

A staged copy is a real post row, so `save_post`, `wp_after_insert_post`, and `transition_post_status` all fire for staged writes. Code that should only react to published content can exclude them:

```php
add_action( 'save_post', function ( $post_id, $post ) {
    if ( function_exists( 'SaveWithoutPublish\is_staged' ) && SaveWithoutPublish\is_staged( $post ) ) {
        return;
    }

    // ... your integration
}, 10, 2 );
```

The `function_exists()` guard keeps the integration working if this plugin is deactivated.

## Incident procedure

**To stop staging immediately**, without deactivating the plugin:

```php
add_filter( 'swpub_is_enabled', '__return_false' );
```

Saves then behave exactly as core does. Existing staged copies stay registered and contained, and remain visible to the commands below. Deactivating the plugin instead would unregister the staged post status, which un-contains them (see the FAQ).

**To find staged copies:**

```bash
wp swpub list
```

Reports every staged copy, the post it stages, and its state: `healthy`, `drifted`, `live-unpublished`, `live-deleted`, `orphaned`, `merging`, or `merge-stranded`.

**To repair an interrupted merge:**

```bash
wp swpub repair <post-id>          # report what is outstanding, change nothing
wp swpub repair <post-id> --force  # revive a merge stranded past its attempt budget
wp swpub repair --all
```

Accepts either post of a pair. A merge that fails repeatedly stops re-attempting and is marked stranded, so a failing merge cannot make both posts unopenable; `--force` is how you retry once the cause is addressed.

## Upgrading

The pointer meta key was `_swpub_shadow_id` before the plugin settled on one word for the second copy. Visiting the admin after an upgrade renames it to `_swpub_staged_copy_id` once, tracked by the `swpub_schema` option. Staged copies created before the rename keep their `swpub-shadow-*` slug, which nothing reads.

Staged copies created before drift detection told content changes apart from everything else are backfilled at the same upgrade, from the published post's own row, for every copy whose published post has not been touched since staging began. A copy whose published post already moved is left as it was: drift on it reads `unknown`, exactly as it did before this existed.

## FAQ

**Does publishing change the post's URL, date, or comments?**

No. Only the title, content, and excerpt change. The published post keeps its ID, so nothing that references it breaks.

**What happens to the staged revision history?**

The staged revisions are adopted into the published post's history, so who changed what is preserved. The published post's pre-merge content is written as a revision first and is restorable from the core revision screen.

**We cap revisions. Does this affect that?**

Yes, worth knowing. Adopted staged revisions join the published post's history, so a single merge can push a capped post past its cap. WordPress trims oldest-first, so the entries lost are the oldest ones, not the merge. Sites using the default unlimited retention, or a cap in the hundreds, will not notice.

**Is staged content private?**

It is excluded from the front end, feeds, sitemaps, search, oEmbed, and the public REST API, and only users who can edit the published post can read it or its revisions.

One exception: **media uploaded while staging is reachable by direct URL**, like all WordPress uploads. Someone who guesses or is given the file URL can open it. Do not treat an unpublished image as embargoed.

**Does staged content appear in an export?**

Yes. Staged copies are posts, so a WordPress export or database dump includes them along with their content.

**What happens if I deactivate the plugin?**

Existing staged copies remain in the database, but the staged post status is no longer registered, which un-contains them. Discard them, or publish them, before deactivating. During an incident use the `swpub_is_enabled` filter instead: it stops staging while keeping existing copies contained.

**Which edits are staged?**

Changes to the title, content, or excerpt of a published post whose type supports revisions and appears in the REST API. Nothing else is ever staged: terms, slug, featured image, author, date, and meta apply to the published post immediately, as core.

**Once a post has a staged copy, every such change is refused**, whatever wrote it. The block editor, the classic editor, WP-CLI, XML-RPC, application passwords, OAuth, another plugin calling `wp_update_post()`, code running as no user at all: all of them are turned away, and neither the published post nor the copy changes. A REST client gets `swpub_live_locked` and HTTP 409. Those three fields are edited on the staged copy; everything else on the published post still saves normally.

**Before the post has a copy**, the first save decides. A user holding `swpub_publish_directly_posts` publishes, and no role holds it by default; everyone else stages; Quick Edit refuses; a write with nobody logged in publishes; a programmatic write without the capability publishes on the seam and fires an audit event; anything else stages. [Who stages, and who publishes](#who-stages-and-who-publishes) has the whole rule.

**We have an integration that writes to published posts. What happens to it?**

While the post it writes to has a staged copy, that write is refused with `swpub_live_locked` and HTTP 409, and its content does not reach readers until somebody clears the copy. No filter exempts it, and no capability grant reaches it either. Publish or discard the staged copy, or turn staging off with `swpub_is_enabled`. [What this means for an integration](#what-this-means-for-an-integration) covers the trade.

**Can two people stage changes to the same post?**

They share one staged copy. The second editor is told staged changes exist, and by whom, before they save, and is sent to the copy to make their change there rather than on the published post.

**What happens if the published post is unpublished or deleted?**

The staged copy is kept, never deleted and never promoted to a published post. It is marked as unable to publish and says why in the editor. If the post is published again, the staged copy can be published as normal, reporting anything that changed while it was away.

**Why can't I stage a category or featured image change?**

The review surface is core's revision screen, which only stores title, content, and excerpt. A staged term change could not be reviewed there, and the pre-merge snapshot could not roll it back. Change those on the published post directly, where they take effect immediately.

## Development

```bash
composer install
npm install
npm run env:start
npm run build
composer run phpcs   # WordPress VIP coding standards
npm run lint:js
npm run test:php     # PHPUnit
npm run test:e2e     # Playwright, against the running wp-env
npm test             # both
```

The two suites cover different things and neither replaces the other.

PHPUnit covers what a browser cannot reach: capability mapping, containment, idempotence, and killing a merge at each phase to prove it resumes. Playwright covers the layer where this plugin actually broke during development — an editor that navigates to a different post than it saved, a link whose `href` was empty, a redirect that looped back into its own handler. Every one of those passed PHPUnit.

End-to-end tests need `npm run env:start` first, and `npx playwright install chromium` once.

CI runs both suites on every push and pull request against the latest WordPress release, so a red run points at the plugin change rather than at core. A weekly scheduled run tests trunk, which `.wp-env.json` tracks locally, so a core change that will break the plugin is seen before it ships. Failed Playwright runs upload their screenshots, traces, and HTML report as a workflow artifact.

### What the editor still has no API for

Four of the editor's surfaces are reached by matching a CSS class or renaming a string, because the block editor offers nothing else. Most have an upstream home already tracked; none of them is work this plugin can do for itself:

- **The primary button's label and action.** There is no filter on `PostPublishButton`, so "Stage changes" (and, once a copy exists, "Save") is applied by matching the button and intercepting the click ([Gutenberg #16308](https://github.com/WordPress/gutenberg/issues/16308) is where that belongs). This one is cosmetic by construction: if the match fails, the editor says so in a notice, the click reaches core, and the server stages the save and steers the editor back into the staging flow. Disabling the button once a copy exists is not part of that surgery: it goes through `lockPostSaving()`, core's own stable switch for it, so it holds even if the label match rots.
- **The title's read-only state.** `PostTitle` has no public read-only prop. Core has one of its own — the title is not editable while a content-only section is being edited — but it is a private selector, and only since WordPress 7.0. Applied by attribute instead, with the same canary as the label above (no upstream issue yet).
- **Custom post statuses in the editor.** The editor cannot render one, so the Status row is a plugin panel fill rather than a status the editor understands ([#3144](https://github.com/WordPress/gutenberg/issues/3144), [#66199](https://github.com/WordPress/gutenberg/issues/66199)).
- **A header slot.** There is no registered slot in the editor header, which is why the button above is reached by selector at all. An RFC for one would retire that surgery outright.
