# AGENTS.md

This file provides guidance to AI coding agents (Claude Code, opencode, etc.) when working with code in this repository.

## Project Overview

**CHIP for Fluent Forms** is a WordPress plugin that adds CHIP as a payment
gateway to Fluent Forms (and Fluent Forms Pro). It registers a payment method
with Fluent Forms and turns a form submission into a CHIP purchase.

- Text domain: `chip-for-fluent-forms`
- Prefix: `FF_CHIP_` (constants), `ff_chip_` (hooks/functions),
  `Chip_Fluent_Forms_` (classes)
- PHP floor: **7.4** (the plugin must stay PHP 7.4 compatible)
- Plugin file: `chip-for-fluent-forms.php`

## Commands

```bash
composer install            # dev dependencies (PHPUnit, PHPCS, WPCS)
composer test               # PHPUnit
composer lint               # PHPCS against phpcs.xml - MUST exit 0
composer lint-fix           # phpcbf autofix
composer compat             # PHPCompatibilityWP, 7.4+
```

`composer lint` exiting 0 is the bar. The plugin must pass its own `phpcs.xml`
clean, the same way `chip-for-woocommerce` passes its own.

Any change to a sniff, exclusion or file name in `phpcs.xml` must be
re-verified with a mutation: introduce the violation in a file the ruleset
still covers and confirm the sniff fires. An exclusion that does not actually
exclude, or one that excludes more than intended, both look like "clean".

## Architecture

```
chip-for-fluent-forms.php          Entry point: plugin header, singleton bootstrap, activation
uninstall.php                      Deletes the plugin options (single site + every multisite site)
includes/
  class-chip-fluent-forms-register.php          add_action() - registers the payment method
  class-chip-fluent-forms-api.php               CHIP API client (keyed per credential pair)
  class-chip-fluent-forms-purchase.php          Payment flow: create, redirect, callback, refund
  admin/
    class-chip-fluent-forms-webhook-setup.php   Registers the payment.refunded webhook on save
    form-settings.php                           Per-form CHIP settings
    global-settings.php                         Global CHIP settings
    backup-settings.php                         Settings backup
  class-chip-ff-settings.php                    Settings framework - page/section registry
  class-chip-ff-settings-page.php               Settings page renderer and save handler
  assets/css/chip-ff-settings.css               Settings page styles
  assets/js/chip-ff-settings.js                 Switcher, tabs and field dependencies
  ```

The file name of a class file must match its class name, lowercased and
hyphenated. That is why the classes above are named `class-chip-fluent-forms-*`
rather than `class-api.php`: the WordPress file-name sniff requires the match,
and renaming is honest where an exclusion is not. The only remaining exclusion
is the entry-point file, whose name WordPress fixes.

## Fluent Forms integration contract

Fluent Forms Pro owns the payment flow. These hooks and their signatures are
external contracts - **never rename or reorder the parameters**:

| Hook | Handler | Notes |
| --- | --- | --- |
| `fluentform/available_payment_methods` | `Register::push` | Registers the gateway |
| `fluentform/process_payment_chip` | `Purchase::handlePaymentAction($submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable)` | Six positional args |
| `fluentform/payment_frameless_chip` | `Purchase::redirect($data)` | Customer returns from checkout |
| `fluentform/ipn_endpoint_chip` | `Purchase::callback()` | CHIP calls this directly; no nonce |

## Rules that are not obvious

- **One API client per credential pair.** `Chip_Fluent_Forms_API::get_instance()`
  keys its cache by `md5(secret_key . '|' . brand_id)`. A single shared instance
  silently reuses the first credentials in a request, which charges the wrong
  brand when a form overrides its credentials. Never pass `''` as the brand id.
- **A failed payment re-query must not return early.** It falls through to the
  paid/failed decision and leaves the CHIP callback as the authority on final
  status. That is the plugin's original behaviour and it is deliberate.
- **The CHIP webhook title is the reuse key.** It is also what the plugin looks
  for when storing the public key. Do not change it.
- **`uninstall.php` cannot use the plugin's constants** - the plugin is not
  loaded during uninstall. Write option names literally.
- **`readme.txt` carries only the current release.** `changelog.txt` keeps the
  full history.
- **The settings framework is ours.** `includes/class-chip-ff-settings*.php`
  replaced the vendored Codestar Framework, whose maintainer stopped
  maintaining it. Never reintroduce it: keep the stored option name and the
  flat `field id => value` shape, because `uninstall.php` and every settings
  read depend on it.
- **Settings are written through the framework, not around it.** A save
  requires the page nonce *and* `menu_capability`; validation failures keep
  the previously stored value rather than blanking the field.
- **Never tag or release without an explicit OK.**

## Tests

`tests/Unit/` holds the regression tests. A new test is only valid once it has
been shown to bite:

1. Confirm it FAILS against the code before the fix.
2. Mutate the fix and confirm each mutation turns the suite red.

A test that greps source text for a line proves the line exists, not that the
behaviour is correct - assert behaviour (call the code, compare results).
Two tests in this repo started as source greps and had to be rewritten after a
mutation passed them.

## Commits and PRs

- Commit as `Wan Zulkarnain <wanzulkarnain69@gmail.com>`.
- PRs are single-concern and small.
- Check `git branch --show-current` before editing; this checkout is shared.
- Push fast-forward only - **never `--force`**.
- Do not name the deployment hosts, internal hosts or IPs in this repository.

## Release

Pushing a version tag (`v1.2.3` or `1.2.3`) triggers `deploy.yml`, which pushes
trunk to WordPress.org SVN, creates `tags/1.2.3`, builds the zip and creates the
GitHub release. `prepare-release.yml` (manual dispatch) bumps the version and
opens the release PR. Use `bash scripts/bump-version.sh <version>` locally to
bump every file at once.

## Tooling / exclusions

`.gitattributes` holds the `export-ignore` list. `git archive` - which both
`deploy.yml` and `release-zip.yml` use - honours it, so anything added to the
repo root (tests, tooling, docs) must be added there too, or it ships to users.
