# Alpha testers: what to know

Thank you for testing Save without Publish. It is early software, so expect rough edges, and tell us about them. This page says what the plugin does, what it deliberately does not do, and the things that will most likely surprise you.

For what the plugin is, see [the listing](integration-center.md).

## Who stages by default

On a normal single site, everyone does, administrators included. When you save a change to a published post, it goes to a private staged copy and the published post stays as it was. You land on the staged copy and are told why.

**On a multisite network, super admins do not.** WordPress gives a super admin every capability, and that includes the one that lets a save skip staging. A super admin sees ordinary WordPress: a **Save** or **Update** button that changes the published post at once. If you are testing as a super admin and the plugin seems to do nothing, this is why. You can still stage on purpose: the **Stage changes** control in the editor's Summary panel moves what you have typed onto a staged copy without touching the published post. To make super admins stage like everyone else, a developer adds this to the site:

```php
add_filter( 'swpub_can_publish_directly', '__return_false' );
```

Test with a normal administrator or editor account as well as a super admin, because that is what most of your editors will be.

## What is staged, and what is not

**Only the title, content and excerpt are staged.** Everything else about a post is *locked* on the staged copy, and you change it on the published post, where it takes effect immediately:

| Setting | On the staged copy | Where to change it |
| --- | --- | --- |
| Slug (the post's address) | Locked | The published post |
| Status | Locked | The published post |
| Publish date | Locked | The published post |
| Author | Locked | The published post |
| Categories, tags and other terms | Locked | The published post |
| Featured image | Locked | The published post |
| Template, format, discussion settings, page attributes | Locked | The published post |
| Custom fields, including ACF fields | Not staged | The published post |

On a staged copy, the editor takes these controls off the screen, and its **Published post** row links to where they live. If a save does try to change one, it is refused with a message that names the field. The block editor offers **Undo those changes**, which puts the field back so the rest of your save can go through.

Custom fields behave differently from the others, and you should know how. A change to a custom field on a staged copy is not refused with a message. It is dropped, and nothing on screen says so. Publishing a staged copy only ever writes the title, content and excerpt, so a custom field edited on a staged copy never reaches the published post. Edit custom fields on the published post.

The reverse also holds: while a staged copy exists, you can still change the slug, categories, featured image and the rest on the published post, and those changes go live immediately. The published post's notice says so.

While a staged copy exists, the title, content and excerpt of the published post cannot be changed by anyone, by any route. The save is refused and nothing is written. Publish or discard the staged copy first.

## Reviewing and publishing

- Review in WordPress's revisions screen. On WordPress 7.0 and later the changes are marked on the blocks. A change to only the title or excerpt does not show there, so **Compare as text** is offered for those.
- **Publish changes** puts the staged title, content and excerpt on the published post. It does not ask first. It saves anything you have not yet saved, and if that save fails, nothing is published. Before it writes, it saves the published post's old content as a revision, so you can undo it from the revisions screen.
- **Discard staged changes** deletes the staged copy permanently. There is no trash to recover it from. The published post is never affected.

## Scheduled publishing

In the Summary panel of a staged copy, **Publish at** schedules **Publish changes** for a time you choose. When the time comes, the same publish runs, as you, and it can be refused for every reason a click can be refused.

Scheduled publishing runs on WordPress's scheduled-task system, WP-Cron, so be clear about the timing:

**What you can rely on**

- It does not publish meaningfully before the time you set. WordPress's own scheduler can fire a few seconds early, so the plugin accepts a run up to one minute ahead of the time, and no more.
- A late run still publishes. A schedule that misses its moment by a few minutes, or longer, is not abandoned.
- If something else is publishing the same post at that moment, the run is put off by about five minutes instead of being lost.
- If a scheduled publish cannot go ahead, it is not retried. The staged copy is kept exactly as it was, the schedule is cleared, and the posts list shows **Schedule stopped**. A notice on the staged copy says when it was due and why it stopped.
- Scheduling a time that has already passed by more than a minute is refused.

**What you cannot rely on**

- The exact minute. WP-Cron does not run on a stopwatch. On a site where it only wakes when someone visits, a quiet site can publish well after the time. How cron runs on your site is a platform question, so ask if the timing matters to you.
- A publish by someone who has since lost the right to publish. The schedule runs as the person who set it. If they can no longer publish that post when the time comes, it stops.
- A publish while staging is switched off. If staging is turned off at the moment a schedule comes due, it stops.

If a site has WP-CLI, `wp swpub run-due` fires every schedule that has come due. It is the right thing to run after a cron problem.

## When the published post changed

This is called *drift*: after you staged your change, the published post changed underneath it. That might be someone editing its words, or only a category or its featured image.

- **Publishing by hand** is refused until you have seen what changed. You are shown the difference (in a new tab, so your staged edits survive) and can confirm to overwrite. If only something other than the words changed, publishing still stops and says so plainly, because it will not overwrite that.
- **A scheduled publish stops.** Nobody is there to confirm, so it does not guess. The staged copy is kept, the schedule is cleared, and the posts list shows **Schedule stopped**. Someone reviews it, publishes it by hand, or schedules it again.
- **A site can opt in to publishing anyway.** A developer answers the `swpub_scheduled_publish_overrides_drift` filter, and can decide by what changed, such as publishing past a category change but still stopping when the words changed. The platform also offers a site-wide setting with the same effect. Either way, the staged words win. When that happens the plugin records that it did. See [the developer page](vip-integration.md#runtime-config).

If you test this, test both: a scheduled publish with nothing changed (it should publish), and one where you change the published post first (it should stop).

## Other things that may surprise you

- **Quick Edit refuses** on a post that would stage the change, and points you to the editor.
- **Two people share one staged copy.** The second person is told staged changes exist, and by whom, before they save.
- **Nothing publishes without a deliberate act.** If a save, a scheduled publish or an import ever puts new title, content or excerpt on a published post without anyone choosing it, report it. That is the one thing this plugin exists to prevent.

## Reporting a problem

Open an issue at https://github.com/Automattic/save-without-publish/issues.

Please include:

- What you did, step by step, and what you expected.
- What happened instead. A screenshot helps.
- Your account type: administrator, editor, super admin or another role. This matters here more than usual.
- Whether the site is a single site or a multisite network, and the WordPress and PHP versions.
- The numbers of the published post and its staged copy. The number is `post=` in the address bar when the post is open in the editor.
- For a scheduled publish: the time you set, the time it was due, and the time it ran or stopped.
- If you have WP-CLI: the output of `wp swpub list`.

Please do not post passwords, API keys or private content in an issue. The repository is public.
