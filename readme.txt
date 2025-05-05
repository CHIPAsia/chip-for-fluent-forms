=== CHIP for Fluent Forms ===
Contributors: chipasia, wanzulnet
Tags: chip
Requires at least: 6.1
Tested up to: 6.8
Stable tag: 1.1.2
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

= 1.1.2 2025-05-05 =
* Fixed - Fixed issue with load_textdomain.

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