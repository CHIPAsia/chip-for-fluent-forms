=== CHIP for Fluent Forms ===
Contributors: chipasia, wanzulnet
Tags: chip
Requires at least: 6.1
Tested up to: 7.1
Stable tag: 1.1.3
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

= 1.1.3 2026-09-24 =
* Added   - DuitNow QR (dnqr) support alongside the legacy duitnow_qr, exposed as a single DuitNow QR option that resolves to whichever identifier your brand supports.
* Added   - ShopeePay is stored as shopee_pay, with an existing razer_shopeepay value migrated automatically.
* Added   - Crypto Coin payment method option.
* Added   - Payment method availability is now looked up with the order amount, so methods with a minimum amount are no longer hidden.
* Added   - Payment Notes setting, so a note from the form can be attached to the purchase in the CHIP dashboard.
* Fixed   - Purchases were rejected by CHIP with "due cannot be in the past" whenever the Timing setting was left empty, so the form could not be paid. The due limit is now omitted when it is not configured.
* Fixed   - A failed CHIP API call during payment surfaced as a fatal error instead of a message the payer could act on. Every API response is now checked before it is read.
* Fixed   - A form with its own Brand ID and Secret Key used the account configured first, so payments for that form could be created on the wrong account.
* Fixed   - Refund synchronization registered its webhook on the global account even when the form had its own credentials, so refunds on those forms were never verified.
* Fixed   - Saving settings could drop the webhook registered for the global account, and a form without a public key stopped every remaining form from being processed.
* Changed - The bundled settings framework was replaced with our own. Your saved settings are kept exactly as they were, so nothing needs to be reconfigured.
* Fixed   - Changing settings from a crafted link is no longer possible, and saving settings now also checks that the user is allowed to manage the site.

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