<img src="./assets/logo.svg" alt="drawing" width="50"/>

# CHIP for Fluent Forms

This module adds CHIP payment method option to your Fluent Forms.

## Requirements

* Fluent Forms **Pro** — the payment framework this gateway plugs into is part of the paid
  version.
* WordPress 6.1 or greater.
* PHP 7.4 or greater (PHP 8.0 or greater recommended).
* A CHIP account with a **Brand ID** and **Secret Key**.

## Installation

* Install and activate the [Fluent Forms](https://wordpress.org/plugins/fluentform/) plugin, and
  the Fluent Forms Pro add-on.
* [Download the latest CHIP for Fluent Forms zip.](https://github.com/CHIPAsia/chip-for-fluent-forms/archive/refs/heads/main.zip)
* Log in to your WordPress admin panel and go: **Plugins** -> **Add New**
* Select **Upload Plugin**, choose the CHIP for Fluent Forms zip file you downloaded and press
  **Install Now**
* Activate plugin

## Configuration

Set the **Brand ID** and **Secret Key** in the plugin settings. Both can be set globally and
overridden per form.

The settings let you:

* choose the **Method label** shown to the payer, and add payment **Notes** (supports Fluent
  Forms `{inputs.<Field Name>}` shortcodes);
* enable or disable the **receipt email** sent by CHIP;
* set **Due Strict** and its **timing**, to expire a purchase after a number of minutes;
* restrict the payment methods offered, via the **payment method whitelist** (FPX, FPX B2B1,
  DuitNow QR, Shopee Pay, Card, Crypto Coin);
* turn on **Refund Synchronization**, so a refund made on the CHIP dashboard is reflected here.

## Development

```bash
composer install     # dev dependencies
composer test        # PHPUnit
composer lint        # PHPCS (WordPress coding standards) — must exit 0
composer compat      # PHP 7.4+ compatibility
```

See `AGENTS.md` for architecture notes.

## Other

Facebook: [Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
