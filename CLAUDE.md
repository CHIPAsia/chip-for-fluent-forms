# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CHIP for Fluent Forms is a WordPress plugin that integrates the [CHIP Digital Finance Platform](https://www.chip-in.asia) as a payment method in [Fluent Forms Pro](https://fluentforms.com/). It allows site owners to accept one-time payments (MYR only) via CHIP with optional payment-method whitelisting (FPX, FPX B2B1, Card, Duitnow QR, Apple Pay, Google Pay, Atome, GrabPay, Maybank QR, ShopeePay, Touch 'n Go, Shopee Pay, Crypto, DuitNow QR Legacy). Receipt emails and refund webhooks are managed by the merchant in the [CHIP dashboard](https://docs.chip-in.asia).

- **WordPress**: ≥ 6.1
- **PHP**: ≥ 7.4 (8.0+ recommended)
- **Hard dependency**: Fluent Forms Pro Add On Pack ≥ 4.3.21 — the plugin's bootstrap short-circuits if `FluentFormPro\Payments\PaymentHelper` or `FluentFormPro\Payments\PaymentMethods\BaseProcessor` are missing.
- **API base**: `https://gate.chip-in.asia/api/v1` (defined as `CHIP_FF_API_ROOT_URL`).
- **Text domain**: `chip-for-fluent-forms`.
- **Option keys**: `fluent_form_chip_settings` (global, the canonical store; mirrored to FF Pro's `fluentform_payment_settings_chip` on save), per-form `fluentform_form_meta` rows with `meta_key = '_chip_payment_settings'`. The form's `payment_method` field's `settings.payment_methods[chip].option_label` and `.notes` (in `fluentform_forms.form_fields` JSON) is what the form editor / render consults for per-form display strings.

## High-Level Architecture

The plugin is intentionally small — a single bootstrap class plus a handful of handler classes that integrate with Fluent Forms Pro's native Payment Methods tab via the `BasePaymentMethod` contract.

### Bootstrap — `chip-for-fluent-forms.php`
- `Chip_Fluent_Forms` is a singleton instantiated on the `init` hook at priority 0, gated by the Fluent Forms Pro class check in `load_chip_for_fluent_forms()`.
- Defines constants: `FF_CHIP_FILE`, `CHIP_FF_BASENAME`, `CHIP_FF_FSLUG` (`'fluent_form_chip'`), `FF_CHIP_MODULE_VERSION`.
- Loads includes in two phases — admin-only files are only included when `is_admin()` is true; runtime classes are always loaded.

### Runtime classes — `includes/`
- **`class-api.php` — `Chip_Fluent_Forms_API`** — thin wrapper around `wp_remote_request` for the CHIP REST API. Singleton keyed by `md5(secret_key . brand_id)`. Cache-busts GETs with `?time=` suffix. Endpoints used: `POST /purchases/`, `GET /purchases/{id}/`, `POST /purchases/{id}/refund/`, `GET /payment_methods/`. Returns `WP_Error` on transport / JSON / API-level errors; callers use `is_wp_error()`.
- **`class-chip-fluent-forms-handler.php` — `Chip_Fluent_Forms_Handler`** — extends `FluentFormPro\Payments\PaymentMethods\BasePaymentMethod`. Owns the per-method push into Fluent Forms Pro's Payment Methods tab. Registers `fluentform/available_payment_methods` + `fluentform/payment_methods_global_settings` + `fluentform/payment_settings_chip` filters (via the parent) and a `fluentform/payment_method_settings_save_chip` filter that mirrors FF Pro's save payload into our canonical option. The `push_payment_method` callback adds `chip` to `fluentform/available_payment_methods` so the form editor / renderer see the method.
- **`class-purchase.php` — `Chip_Fluent_Forms_Purchase`** — extends `FluentFormPro\Payments\PaymentMethods\BaseProcessor`. This is the core of the plugin. Registers three action hooks: `fluentform/process_payment_chip`, `fluentform/payment_frameless_chip` (user redirect back from CHIP), and `fluentform/ipn_endpoint_chip` (server-to-server callback). Currency support is hard-coded to `MYR` (`$supported_currencies`); subscriptions are rejected with HTTP 423.

### Admin — `includes/admin/`
All admin files only execute under `is_admin()`. They register fields and panels through Fluent Forms Pro's native `BasePaymentMethod` contract — see `class-chip-fluent-forms-handler.php` for the entry point.
- **`class-chip-fluent-forms-settings-page.php`** — Provides the field schema (`get_fields()`) consumed by `BasePaymentMethod::getGlobalFields()` for FF Pro's native Payment Methods tab. The schema uses the FF Pro shape: `{ label, fields: [{ settings_key, type, ... }] }` with `type` values like `yes-no-checkbox`, `input-radio`, `input-text`, `input-checkboxes`. The "standalone page" rendering path (`render_standalone_page`, `register_settings`, etc.) is legacy code kept for sites on very old FF Pro without `BasePaymentMethod` — the live UI is FF Pro's Payment Methods tab.
- **`class-chip-fluent-forms-per-form-page.php`** — Dedicated per-form admin page (`wp-admin/admin.php?page=chip-form-settings`, registered as a submenu of `fluent_forms`, cap `fluentform_dashboard_access`). The list view shows every FF form with a Customized Yes/No column. The per-form edit view (`&form_id=N`) renders a WP Settings API form with seven fields (Customize toggle, Payment Mode, Brand ID, Secret Key, Due Strict, Due Strict Timing, Payment Method Whitelist). Save POSTs to `wp-admin/admin-post.php?action=chip_save_per_form_settings` → `Chip_Fluent_Forms_Settings::save_form()` → `_chip_payment_settings` row in `fluentform_form_meta`. The cap matches the parent FF menu's gate so admins who can see FF Forms also see CHIP Per-Form.
- **`class-chip-fluent-forms-migration.php`** — Two-phase upgrade from the legacy `fluent_form_chip` option (1.x) to the new schema. Phase 1 writes the new global option and per-form `_chip_payment_settings` rows, and also walks every form with a `payment_method` field to ensure `settings.payment_methods[chip].enabled === 'yes'` (preserves explicit `'no'`). Phase 2 verifies the writes; on success it deletes the legacy option. Loosened `resolve_legacy_is_active()` so any non-empty legacy option flips `is_active='yes'` for an upgrading user — the 1.x plugin had no master enable toggle, so the option existing is the signal the merchant was running CHIP.

### Settings resolution — `Chip_Fluent_Forms_Purchase::create_purchase()`
At purchase time the runtime calls `Chip_Fluent_Forms_Purchase::resolve_effective_config($form_id, $methodSettings)` which merges two layers in priority order:

1. **`_chip_payment_settings` row** (highest priority) — read via `Chip_Fluent_Forms_Settings::for_form($form_id)`. The migration creates these for 1.x → 2.0 upgraded users; the dedicated per-form admin page writes to them when a merchant customizes a form. If the row's `is_active` is `'no'` (or missing), `for_form()` falls back to global for the credential keys while preserving the per-form `is_active` flag itself.
2. **Global `fluent_form_chip_settings`** (lowest priority) — the values from the FF Pro Payment Methods tab (`brand_id`, `secret_key`, `payment_mode`, `due_strict`, `due_strict_timing`, `payment_method_whitelist`).

`option_label` and `notes` are read separately from `$methodSettings['settings.notes.value']` (the per-form `payment_method` field's chip sub-panel) and are not affected by this merge.

### Payment flow
1. Fluent Forms Pro fires `fluentform/process_payment_chip` → `handlePaymentAction()` → `create_purchase()`.
2. A pending transaction row is inserted via `BaseProcessor::insertTransaction()`.
3. `Chip_Fluent_Forms_API::create_payment()` is called with the params built from `get_settings()` + submission data. The `creator_agent` is `'FluentForms: ' . FF_CHIP_MODULE_VERSION`; `platform` is hard-coded `'fluentforms'`.
4. The `checkout_url` from the response is returned via `wp_send_json_success()` with `nextAction = payment`, which Fluent Forms' JS uses to redirect the browser.
5. On the way back, both `fluentform/payment_frameless_chip` (user redirect) and `fluentform/ipn_endpoint_chip` (server callback) re-fetch the purchase from CHIP, take a MySQL named lock (`GET_LOCK('ff_chip_payment_{submission_id}', 15)`) to make paid/failed handling race-safe, and dispatch to `handlePaid()` or `handleFailed()`.
6. `handlePaid()` triggers Fluent Forms' submission processing (`processSubmissionData`), updates the transaction, recalculates paid totals, and runs after-success email notifications guarded by a submission meta flag `_ff_chip_on_payment_success` to prevent duplicates.
7. Refund webhooks are received on `callback()` → `refund_callback()` which now logs a one-time deprecation notice (refund sync is no longer managed by this plugin — see `Chip_Fluent_Forms_Purchase::handleRefund()` for the no-op override and the merchant-dashboard configuration note).

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
- Generated code paths must respect the `fluent_form_chip` option schema; per-form field ids must be suffixed with `-{form_id}` and read via `get_settings()` so the postfix logic continues to work.
- Use `do_action( 'ff_log_data', ... )` for any user-visible failure paths in the payment flow (the existing code does so for purchase creation failure, redirect, test-mode notice, and deprecation notices).
- When extending `Chip_Fluent_Forms_API`, take the MySQL `GET_LOCK` pattern around any state-mutating callback (paid/failed/refund) so concurrent IPN + redirect handling can't double-process a submission.
