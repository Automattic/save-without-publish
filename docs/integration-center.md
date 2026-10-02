# Save without Publish

Change a published post without changing what readers see, until you decide.

Normally, saving an edit to a published post puts it in front of readers at once. Save without Publish keeps the published post as it is and holds your edit on a private **staged copy**. The post keeps serving, unchanged, until someone publishes the change on purpose.

## What it does

- **Stages edits instead of publishing them.** When you save a change to a published post, the change goes to a staged copy that only people who can edit it can see. Readers, search, feeds, sitemaps and public API requests keep seeing the published post.
- **Works where editors already work.** In the block editor the main button reads **Stage changes**, and once a staged copy exists it reads **Save**. The classic editor stages too, and tells you where the save went.
- **Lets you review the change.** You compare the staged words with the published ones in WordPress's own revisions screen, with the changes marked on the blocks that carry them (WordPress 7.0 and later). On earlier versions, WordPress's classic compare screen does the same job.
- **Publishes when you say so.** **Publish changes** puts the staged title, content and excerpt on the published post, and removes the staged copy. The published post keeps its address, publish date, author, comments, categories, tags and featured image. The old content is saved as a revision first, so a publish can be undone from the revisions screen.
- **Publishes at a set time.** Pick a date and time in the editor's Summary panel. At that time the same **Publish changes** runs, as the person who scheduled it.
- **Protects other people's edits.** If someone changed the published post after the change was staged, publishing asks you to look at what changed and confirm before it overwrites anything. A scheduled publish has nobody to ask, so it stops and keeps the staged copy instead.
- **Lets you throw a change away.** Discarding a staged copy never touches the published post.
- **Works from the command line** for sites that use WP-CLI: `wp swpub list`, `publish`, `schedule`, `unschedule`, `run-due` and `repair`.

By default, everyone stages. No role is allowed to skip it until a site chooses to allow that.

## What it does not do yet

- **It is not an approval workflow.** There is no reviewer role, no queue, no sign-off step and no new admin screen. Anyone who can edit a staged copy can publish it. That is deliberate, but it means this is not yet a way to require a second person's approval.
- **Only the title, content and excerpt are staged.** The slug, status, publish date, author, categories, tags, featured image, custom fields and similar post settings are not. Change those on the published post, and they take effect immediately.
- **One staged copy per post.** Everyone who edits the post shares it, so two people cannot stage competing versions of the same post.
- **Scheduled publishing is not to the second.** It runs on WordPress's scheduled-task system (WP-Cron), which makes no promise about the exact minute. A late run still publishes. See the [alpha tester page](alpha-testers.md) for what is and is not guaranteed.
- **No dashboard of staged changes.** The posts list marks posts that have a staged copy (**Staged changes**, **Scheduled**, **Schedule stopped**). There is no single page that lists them all with their state.
- **Multisite super admins publish directly by default**, so they see ordinary WordPress until a site changes that. The [alpha tester page](alpha-testers.md#who-stages-by-default) explains how.
- **Quick Edit does not stage.** On a post that would stage the change, Quick Edit refuses and points you to the editor.
- **No telemetry.** The plugin sends no usage data.

## Requirements

- WordPress 6.8 or later. Tested on 6.9 and the latest release, and on 6.8 every week.
- PHP 8.2 or later. Tested on 8.2, 8.3, 8.4 and 8.5.
- The block editor, for the full experience described above.

## Settings

One optional setting, set by the platform: what a scheduled publish does when the published post changed after the change was staged. The default is to stop and keep the staged copy for a person to look at. A site can instead allow it to go ahead when only things other than the words changed (such as a category), or in every case. A site's own code can override the setting either way. Details are in [the developer page](vip-integration.md#runtime-config).

## Getting help

Open an issue at https://github.com/Automattic/save-without-publish/issues. The [alpha tester page](alpha-testers.md#reporting-a-problem) lists what to include.
