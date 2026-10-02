# VIP Integration Center

Save without Publish is built to ship through the WordPress VIP Integration Center. This page is for the developers who build and test it, and for the VIP platform team who register and load it. What the plugin does for editors is in [the listing](integration-center.md), and what testers should know is in [the alpha tester page](alpha-testers.md).

The platform reads [`vip-manifest.yaml`](../vip-manifest.yaml) at the root of the repository, and nothing else, to register the plugin, render its settings form and load it. The [VIP Integrations Starter Kit](https://github.com/Automattic/vip-integrations-starter-kit) describes the format, and `@automattic/vip-integration` checks it.

## Building and testing

```bash
composer install
npm ci
npm run build
npm run env:start    # wp-env; needed for everything below
npm run test:php     # PHPUnit, in wp-env's tests container
npm run test:e2e     # Playwright, against the running wp-env
npm test             # both
```

`composer test` runs PHPUnit and then Playwright, because the checker requires `composer test` to run both. It needs the WordPress test library and a running wp-env, so on a bare machine run `npm test`, which drives both through wp-env. `composer test:unit` is PHPUnit alone and is what `npm run test:php` calls inside the container.

`build/` is not committed. A checkout of the repository has no editor JavaScript until `npm run build` has run.

## Checking conformance

```bash
npm run validate:integration
```

This runs `@automattic/vip-integration` 0.1.2 against `git archive HEAD`, an export of what is committed, and exits 1 if any rule fails. It does not check the working tree, deliberately. The checker looks for `README.md` by exact name, and a case-insensitive filesystem would let `readme.md` pass here and fail on CI. It also reads every `.md` file under `docs/`, including local plans CI never sees. Commit before you run it.

CI runs the same checker as the `validate` job in `.github/workflows/ci.yml`. The checker is static. Two things stay with a human reviewer: whether the plugin's config matches the platform's schema, and the security review.

The checker's compatibility rule reads a literal PHP and WordPress matrix from the workflows, so the `php` and `wp` lists in the `tests` job are written out in full. Do not turn them into an expression.

## Runtime config

The platform defines one constant, a plain PHP array, before the plugin loads. All reads go through `SaveWithoutPublish\Config`.

Config constant: `VIP_SAVE_WITHOUT_PUBLISH_CONFIG`

Nothing is required. The plugin behaves exactly as it does without the Integration Center until a value is set, and no value can stop it loading.

| Key | Values | Default | Effect |
| --- | --- | --- | --- |
| `scheduled_publish_overrides_drift` | `never`, `non_content`, `always` | `never` | What a scheduled publish does when the published post changed after the change was staged. |

- **`never`** stops the scheduled publish and keeps the staged copy for a person to review. This is what the plugin has always done.
- **`non_content`** publishes anyway when what changed is a field the publish never writes (a term, the featured image, the slug, meta), and still stops if the title, content or excerpt changed.
- **`always`** publishes the staged words over any change, and records that it did so (the `swpub_drift_overridden` action fires, as it does when an editor confirms an overwrite).

Example valid config:

```php
define( 'VIP_SAVE_WITHOUT_PUBLISH_CONFIG', array(
	'scheduled_publish_overrides_drift' => 'non_content',
) );
```

Example incomplete config (setup in progress, nothing set yet):

```php
define( 'VIP_SAVE_WITHOUT_PUBLISH_CONFIG', array() );
```

With incomplete config the plugin does not fatal and shows no notice, because nothing is missing: a scheduled publish stops on drift, as `never` does. The same is true when the constant is not defined at all, when it is not an array, and when the setting holds anything that is not one of the three values above (including the right word in the wrong case, or with spaces around it). [`tests/fixtures/`](../tests/fixtures/README.md) holds one file for each of these states.

### How it meets the filter

The setting is the starting answer to the `swpub_scheduled_publish_overrides_drift` filter, not a replacement for it. The filter receives the setting's answer as `$override`, and a site's own callback has the last word in both directions: returning `false` stops a publish the setting would have allowed, and returning `true` allows one it would have stopped. A callback that returns `$override` unchanged keeps the setting.

The constant is read when a scheduled publish meets drift, so it only has to be defined by then. The platform defines it before plugins load.

## Handing off to the platform team

- **Registration:** `vip-manifest.yaml`. Its `release.plugin_version` must equal the plugin header's `Version`, the `VERSION` constant and `package.json`. `tests/test-manifest.php` fails if any of them disagree, and the release workflow refuses a tag that does not match the manifest.
- **What to install:** the zip a `v*` tag builds (`.github/workflows/release.yml`), not the repository. The zip holds `save-without-publish.php`, `includes/`, `build/`, `README.md` and `LICENSE`. It does not contain `vip-manifest.yaml`, so the platform reads that from the repository at the same tag.
- **Is-loaded check:** the platform's loader needs a constant the entry file defines, and the manifest has no field for it. Use `SaveWithoutPublish\PLUGIN_FILE`, which the entry file already defines: `defined( 'SaveWithoutPublish\PLUGIN_FILE' )`. The Starter Kit's own pattern is an early `return` guarded by a `VIP_*_LOADED` constant, which this plugin does not use. Its entry file declares functions, and PHP declares those before an early `return` can run, so a second copy would fatal anyway. Moving them would change how the plugin starts.
- **Namespace:** `SaveWithoutPublish`, not `Automattic\SaveWithoutPublish`. The manifest declares the real one.
- **Telemetry:** none. The plugin records no events, so the manifest has no telemetry section.
- **Compatibility:** WordPress 6.8 and later, PHP 8.2 and later. CI runs PHP 8.2 to 8.5 against WordPress 6.9 and the latest release on every push, and trunk and 6.8 weekly.
- **Scope:** per site. All of the plugin's state is post and post meta.
