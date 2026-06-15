=== CHIP for Fluent Forms ===
Contributors: chipasia, wanzulnet
Tags: chip
Requires at least: 6.1
Tested up to: 7.0
Stable tag: 2.1.0
Requires PHP: 7.4
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

CHIP - Digital Finance Platform. Securely accept one-time and subscription payment with CHIP for Fluent Forms.

== Description ==

This is an official CHIP plugin for Fluent Forms.

CHIP is a comprehensive Digital Finance Platform specifically designed to support and empower Micro, Small and Medium Enterprises (MSMEs). We provide a suite of solutions encompassing payment collection, expense management, risk mitigation, and treasury management.

Our aim is to help businesses streamline their financial processes, reduce operational complexity, and drive growth.

With CHIP, you gain a financial partner committed to simplifying, digitizing, and enhancing your financial operations for ultimate success.

This plugin will enable your Fluent Forms Pro to be integrated with CHIP as per documented in [API Documentation](https://docs.chip-in.asia).

== Screenshots ==
* Fill up the form with Brand ID and Secret Key on Global Configuration.
* Fill up the form with Brand ID and Secret Key on Form-specific Configuration.
* Form that have been integrated with CHIP.
* Test mode payment page.
* Confirmation page after successful payment.

== Changelog ==

= 2.1.0 2026-06-15 =
* Removed `send_receipt` and `synchronize_refund` settings (global and per-form). The CHIP `send_receipt` parameter is now always `false`; receipt emails and refund webhooks are managed via the CHIP merchant dashboard. The entire `Chip_Fluent_Forms_Webhook_Setup` class has been deleted. Refund webhooks received on `fluentform/ipn_endpoint_chip` now log a one-time deprecation notice instead of verifying signatures.

= 2.0.0 2026-06-15 =
* Major rewrite: dropped bundled Codestar Framework (2.4M) in favour of Fluent Forms Pro's native Payment Methods tab via `BasePaymentMethod`.
* Added 10 new payment-method whitelist keys (crypto_coin, dnqr, mpgs_apple_pay, mpgs_google_pay, razer_atome, razer_grabpay, razer_maybankqr, razer_shopeepay, razer_tng, shopee_pay). The `cards` shortcut still expands to visa+mastercard+maestro at send-time.
* Per-form "Customize" panel (per-form credentials, payment-mode override, payment-method whitelist, refund-sync toggle) wired through FF Pro's per-form payment settings.
* Form-level "Notes" field on the payment method (merged from add/notes_parameter), with `{inputs.<Name Attribute>}` substitution.
* `ff_chip_payment_paid_chip` action hook fired after a submission is marked as paid (replaces the undocumented `_ff_chip_on_payment_success` meta flag).
* `ff_chip_ipn_domain` filter and `FF_CHIP_IPN_DOMAIN` constant for reverse-proxy / custom-hostname sites.
* `ff_chip_payment_mode` filter.
* New settings option schema: global `fluent_form_chip_settings` option + per-form `fluentform_form_meta` rows with `meta_key = '_chip_payment_settings'`. One-time migration copies values from the legacy `fluent_form_chip` (and `fluent_form_chip_public_key`) options, then deletes them.
* `Chip_Fluent_Forms_Purchase::init()` now follows the modern `BaseProcessor::init()` convention.
* `handlePaymentAction` now uses `createInitialPendingTransaction()` and `getPaymentMode()`.
* `handlePaid` cross-verifies the amount reported by CHIP and downgrades to `requires_review` on mismatch.
* `handleRefund` uses `updateRefund()` for idempotency (no more duplicate refund rows on replay).
* `Chip_Fluent_Forms_API::call` returns `WP_Error` on transport / JSON / API-level errors; callers now use `is_wp_error()`.
* Fixed per-form refund signature verification (was reading the global public key instead of the per-form one).
* Logs when the CHIP public key is missing for a refund.
* Removed dead email-notification code; replaced with a documented `do_action`.
* Removed standalone `CHIP Settings` admin submenu page.
* Updated inline API documentation URL to https://docs.chip-in.asia.
* WordPress 7.0 readiness; PHPCS (WordPress coding standards) clean across all PHP files.

[See changelog for all versions](https://raw.githubusercontent.com/CHIPAsia/chip-for-fluent-forms/main/changelog.txt).

== Installation ==

= Minimum Requirements =

* WordPress 6.1 or greater
* PHP 7.4 or greater is required (PHP 8.0 or greater is recommended)
* MySQL 5.6 or greater, OR MariaDB version 10.1 or greater, is required
* Fluent Forms Pro Add On Pack 4.3.21 or greater

= Automatic installation =

Automatic installation is the easiest option as WordPress handles the file transfers itself and you don’t need to leave your web browser. To do an automatic install of, log in to your WordPress dashboard, navigate to the Plugins menu and click Add New.

In the search field type "CHIP for Fluent Forms" and click Search Plugins. Once you’ve found our plugin you can view details about it such as the point release, rating and description. Most importantly of course, you can install it by simply clicking “Install Now”.

= Manual installation =

The manual installation method involves downloading our plugin and uploading it to your webserver via your favorite FTP application. The
WordPress codex contains [instructions on how to do this here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

= Updating =

Automatic updates should work like a charm; as always though, ensure you backup your site just in case.

== Frequently Asked Questions ==

= Where is the Brand ID and Secret Key located? =

Brand ID and Secret Key available through our merchant dashboard.

= Do I need to set public key for webhook? =

No.

= Where can I find documentation? =

You can visit our [API documentation](https://docs.chip-in.asia/) for your reference.

= What CHIP API services used in this plugin? =

This plugin rely on CHIP API ([FLUENT_FORMS_CHIP_ROOT_URL](https://gate.chip-in.asia)) as follows:

  - **/purchases/**
    - This is for accepting payment
  - **/purchases/<id\>**
    - This is for getting payment status from CHIP

== Links ==

[CHIP Website](https://www.chip-in.asia)

[Terms of Service](https://www.chip-in.asia/terms-of-service)

[Privacy Policy](https://www.chip-in.asia/privacy-policy)

[API Documentation](https://docs.chip-in.asia/)

[CHIP Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)