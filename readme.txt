=== Skroutz Smart Cart Bridge for WooCommerce ===
Tags: woocommerce, skroutz, marketplace, webhook, orders
Requires at least: 6.3
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Skroutz Smart Cart webhook orders into WooCommerce and synchronize their state and shipment details.

== Description ==

This unofficial integration receives Skroutz Smart Cart new-order and order-update webhooks.

Features:

* Secure, randomly generated webhook URL.
* Idempotent order creation during webhook retries.
* Product and variation matching by ID, SKU, or custom meta.
* WooCommerce order creation and safe state synchronization.
* Courier, tracking, pickup, invoice, and fulfillment metadata.
* Legacy order storage and HPOS support.
* Privacy-safe optional logging.

This independent project is not affiliated with, endorsed by, or maintained by Skroutz.

== Installation ==

1. Upload the plugin ZIP from Plugins > Add New Plugin > Upload Plugin.
2. Activate the plugin.
3. Open WooCommerce > Skroutz Bridge.
4. Configure product and variation matching.
5. Copy the complete webhook URL into the Skroutz Marketplace merchant settings.

Treat the complete webhook URL as a credential. Regenerate it from the settings page if it is exposed.

== Frequently Asked Questions ==

= Does it accept or reject orders through the Orders API? =

No. Version 1.0.0 imports and synchronizes incoming webhook events only.

= What happens when Skroutz retries a webhook? =

The unique Skroutz order code is checked before creation, and a short-lived import lock protects against concurrent duplicate events.

= Are raw customer details written to debug logs? =

No. Logging is disabled by default and includes only the event type, order code, state, item count, and technical outcome.

== Changelog ==

= 1.0.0 =

* Initial public release.
