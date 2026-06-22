# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CHIP for Fluent Forms is a WordPress plugin that integrates the [CHIP Digital Finance Platform](https://www.chip-in.asia) as a payment method in [Fluent Forms Pro](https://fluentforms.com/). It allows site owners to accept one-time payments (MYR only) via CHIP with optional payment-method whitelisting (FPX, FPX B2B1, Card, Duitnow QR, Apple Pay, Google Pay, Atome, GrabPay, Maybank QR, ShopeePay, Touch 'n Go, Shopee Pay, Crypto, DuitNow QR Legacy). Receipt emails and refund webhooks are managed by the merchant in the [CHIP dashboard](https://docs.chip-in.asia).

- **WordPress**: ≥ 6.1
- **PHP**: ≥ 7.4 (8.0+ recommended)
- **Hard dependency**: Fluent Forms Pro Add On Pack ≥ 4.3.21 — the plugin's bootstrap short-circuits if `FluentFormPro\Payments\PaymentHelper` or `FluentFormPro\Payments\PaymentMethods\BaseProcessor` are missing.
- **API base**: `https://gate.chip-in.asia/api/v1` (defined as `CHIP_FF_API_ROOT_URL` in `class-chip-fluent-forms-api.php`).
- **Text domain**: `chip-for-fluent-forms`.
- **Single storage option**: `fluent_form_chip` — owned by the codestar framework. Holds both global values (`secret-key`, `brand-id`, `payment-title`, `due-strict`, `due-strict-timing`, `payment-method-whitelist`, etc.) and per-form values keyed with `-{form_id}` suffix (`secret-key-3`, `brand-id-3`, `form-customize-3`, etc.). The runtime reads everything from this single option.

## High-Level Architecture

The plugin is intentionally small — a single bootstrap class plus a vendored codestar framework and a handful of handler classes that integrate with Fluent Forms Pro's payment pipeline.

### Bootstrap — `chip-for-fluent-forms.php`
- `Chip_Fluent_Forms` is a singleton instantiated on the `init` hook at priority 0, gated by the Fluent Forms Pro class check.
- Defines constants: `FF_CHIP_FILE`, `FF_CHIP_BASENAME`, `FF_CHIP_FSLUG` (`'fluent_form_chip'`), `FF_CHIP_MODULE_VERSION`.
- Loads includes in two phases — codestar framework + admin pages on every request (CSF's `createOptions` registers admin pages on every load); admin-only files only included when `is_admin()` is true; runtime classes are always loaded.

### Vendored Codestar Framework — `includes/codestar-framework/`
A vendored copy of the [Codestar Framework](https://codestarframework.com/) v2.3.1. Ships as-is from the 1.x plugin. We do not modify these files. The `CSF_Setup` class is what `CSF_Setup::createOptions($slug, ...)` uses to register the global admin page and the per-form editor fields. Field schemas (Credentials, Miscellaneous, Refund) live in `includes/admin/global-settings.php`; per-form field schemas live in `includes/admin/form-settings.php`.

### Runtime classes — `includes/`
- **`class-chip-fluent-forms-api.php` — `Chip_Fluent_Forms_API`** — thin wrapper around `wp_remote_request` for the CHIP REST API. Singleton keyed by `md5(secret_key . brand_id)`. Cache-busts GETs with `?time=` suffix. Endpoints used: `POST /purchases/`, `GET /purchases/{id}/`, `POST /purchases/{id}/refund/`, `GET /payment_methods/`. Returns `WP_Error` on transport / JSON / API-level errors; callers use `is_wp_error()`.
- **`class-register.php` — `Chip_Fluent_Forms_Register`** — adds `chip` to the `fluentform/available_payment_methods` filter so the form editor / renderer see the method. Reads the `payment-title` field from the codestar option for the display label.
- **`class-purchase.php` — `Chip_Fluent_Forms_Purchase`** — extends `FluentFormPro\Payments\PaymentMethods\BaseProcessor`. This is the core of the plugin. Registers three action hooks: `fluentform/process_payment_chip`, `fluentform/payment_frameless_chip` (user redirect back from CHIP), and `fluentform/ipn_endpoint_chip` (server-to-server callback). Currency support is hard-coded to `MYR` (`$supported_currencies`); subscriptions are rejected with HTTP 423.

### Admin — `includes/admin/`
All admin files only execute under `is_admin()`. They register pages and fields through the codestar framework (CSF_Setup).
- **`global-settings.php`** — Registers the global CHIP settings page (menu slug `chip-for-fluent-forms`, submenu of `fluent_forms`). Sections: Credentials (Secret Key, Brand ID), Miscellaneous (Payment Title, Due Strict, Due Strict Timing, Payment Method Whitelist + per-method toggles), Refund Synchronization (Public Key). All values are persisted into the single `fluent_form_chip` option by codestar.
- **`form-settings.php`** — Registers the per-form fields shown inside each Fluent Forms form's editor (via the `fluentform/form_settings_component` filter). Per-form fields use the same field ids as the global settings, suffixed with `-{form_id}`. The Customization toggle (`form-customize-{form_id}`) gates whether the per-form values override the global ones.
- **`backup-settings.php`** — Codestar's built-in backup/restore sub-page. Imports and exports the full `fluent_form_chip` option.
- **`class-webhook-setup.php`** — Webhook admin UI. Handles the `fluent_form_chip_public_key` option for refund-webhook signature verification.

### Settings resolution — `Chip_Fluent_Forms_Purchase::create_purchase()`
At purchase time the runtime calls `Chip_Fluent_Forms_Purchase::get_settings($form_id)` (private helper in `class-purchase.php`), which reads `get_option('fluent_form_chip')` and merges:

1. **Global values** (lowest priority) — `secret-key`, `brand-id`, `due-strict`, `due-strict-timing`, `payment-method-whitelist` etc.
2. **Per-form overrides** (highest priority) — if `form-customize-{form_id}` is truthy, the per-form fields suffixed with `-{form_id}` override the global values.

The result is a flat array (`secret_key`, `brand_id`, `send_rcpt`, `due_strict`, `due_time`, `refund`, `payment_whitelist`, `payment_method_fpx`, etc.) consumed by `create_purchase()` and the redirect/IPN callbacks.

### Payment flow
1. Fluent Forms Pro fires `fluentform/process_payment_chip` → `handlePaymentAction()` → `create_purchase()`.
2. A pending transaction row is inserted via `BaseProcessor::insertTransaction()`.
3. `Chip_Fluent_Forms_API::create_payment()` is called with the params built from `get_settings($form->id)` + submission data. The `creator_agent` is `'FluentForms: ' . FF_CHIP_MODULE_VERSION`; `platform` is hard-coded `'fluentforms'`.
4. The `checkout_url` from the response is returned via `wp_send_json_success()` with `nextAction = payment`, which Fluent Forms' JS uses to redirect the browser.
5. On the way back, both `fluentform/payment_frameless_chip` (user redirect) and `fluentform/ipn_endpoint_chip` (server callback) re-fetch the purchase from CHIP, take a MySQL named lock (`GET_LOCK('ff_chip_payment_{submission_id}', 15)`) to make paid/failed handling race-safe, and dispatch to `handlePaid()` or `handleFailed()`.
6. `handlePaid()` triggers Fluent Forms' submission processing (`processSubmissionData`), updates the transaction, recalculates paid totals, and runs after-success email notifications guarded by a submission meta flag `_ff_chip_on_payment_success` to prevent duplicates.
7. Refund webhooks are received on `callback()` → `refund_callback()` which verifies the signature with `fluent_form_chip_public_key` and dispatches to `handleRefund()`.

### Filters & actions for extension
- `ff_chip_sslverify` (bool, default `true`) — toggles `sslverify` on outbound requests.
- `ff_chip_purchase_timezone` (string) — overrides the timezone in purchase params.
- `ff_chip_create_purchase_params` ($params, $transaction, $submission, $form) — last-mile mutation of the create-purchase payload.
- `ff_chip_after_purchase_create` ($transaction, $submission, $form, $payment) — fired after a successful CHIP purchase creation.
- `ff_chip_handle_paid_data` ($updateData, $submission, $transaction, $vendorTransaction) — mutates the transaction update payload on paid.
- `ff_log_data` — Fluent Forms' standard logging action; used throughout for payment-component log entries.

## Build / Test / Lint

There is no application-level build, no test suite, and no linter configured. `composer.json` and `package.json` are empty `{}` placeholders (a PHP class autoloader / JS build would go here if added). CI uses the placeholder workflows in `.github/workflows/`.

- **CI activation test** — `.github/workflows/plugin-activation.yml` boots MariaDB + WordPress test library, copies the plugin into `/tmp/wordpress/wp-content/plugins/`, and runs `wp plugin activate chip-for-fluent-forms`. PHP 8.2. There is no unit-test step.
- **Release zip** — `.github/workflows/build-zip.yml` runs `composer install --no-dev` and `npm install` (both no-ops given empty manifests) then uses `10up/action-wordpress-plugin-build-zip` to produce a WordPress.org-ready zip. The build respects `.gitattributes` export-ignore rules (drops `.github`, `composer.json`, `package.json`, `*.md`, etc.).

To run the activation test locally, replicate the CI shell:
```
wp plugin activate chip-for-fluent-forms --path=/tmp/wordpress
```
The plugin must be reachable as `chip-for-fluent-forms` under `wp-content/plugins/`.

## Conventions

- Indentation is **tabs** (the codebase uses tabs throughout — preserve them).
- All public-facing strings are wrapped in `__()` / `esc_html__()` / `sprintf()` with the `chip-for-fluent-forms` text domain; preserve that on any new copy.
- Per-form settings live in the `fluent_form_chip` option with field id suffixes `-{form_id}`; read via `get_settings($form_id)` so the postfix logic continues to work.
- Use `do_action( 'ff_log_data', ... )` for any user-visible failure paths in the payment flow (the existing code does so for purchase creation failure, redirect, test-mode notice, and deprecation notices).
- When extending `Chip_Fluent_Forms_API`, take the MySQL `GET_LOCK` pattern around any state-mutating callback (paid/failed/refund) so concurrent IPN + redirect handling can't double-process a submission.
- The vendored codestar framework (`includes/codestar-framework/`) is read-only — do not modify its files. Any custom fields belong in `includes/admin/global-settings.php` or `includes/admin/form-settings.php`.