# Skroutz Smart Cart Bridge for WooCommerce

An unofficial WordPress plugin that receives Skroutz Smart Cart order webhooks, creates the corresponding WooCommerce orders, and synchronizes later order-state and shipment updates.

> This is an independent open-source project. It is not affiliated with, endorsed by, or maintained by Skroutz.

Created and maintained by [Ilias Euthimiou](https://iliaseuthimiou.com).

## What it does

- Receives `new_order` and `order_updated` webhook events.
- Creates WooCommerce orders with customer addresses and line items.
- Matches products and variations by WooCommerce ID, SKU, or an exact custom-meta value.
- Prevents duplicate WooCommerce orders when a webhook is retried.
- Protects the webhook with a randomly generated secret URL.
- Synchronizes order state, courier, voucher, tracking, pickup, invoice, and fulfillment metadata.
- Supports both legacy WooCommerce order storage and High-Performance Order Storage (HPOS).
- Adds a Skroutz information box and state column to WooCommerce order administration.
- Logs only privacy-safe event summaries when debug logging is enabled.

## Requirements

- WordPress 6.3 or newer
- PHP 7.4 or newer
- WooCommerce 8.0 or newer
- A public HTTPS website
- Skroutz Marketplace access with order webhooks enabled

## Installation

### Install the ZIP

1. Download the release ZIP.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP and activate **Skroutz Smart Cart Bridge for WooCommerce**.

### Install from source

Clone this repository into `wp-content/plugins/skroutz-smart-cart-bridge`, then activate it from the WordPress Plugins screen.

## Configuration

1. Open **WooCommerce → Skroutz Bridge**.
2. Select how `shop_uid` maps to WooCommerce products.
3. Select how `shop_variation_uid` maps to WooCommerce variations.
4. When using custom-meta matching, enter the exact meta key used by the products or variations.
5. Save the settings.
6. Copy the complete webhook URL shown on the page.
7. Register that URL in the Skroutz Marketplace merchant settings.

The URL contains a random secret in its `key` parameter. Treat the full URL as a credential. If it is exposed, generate a new URL from the plugin settings and immediately replace the old URL in the merchant panel.

## Product matching

The plugin supports three exact matching modes:

| Mode | Incoming value | WooCommerce value |
| --- | --- | --- |
| ID | `shop_uid` or `shop_variation_uid` | Numeric product or variation ID |
| SKU | `shop_uid` or `shop_variation_uid` | Product or variation SKU |
| Custom meta | `shop_uid` or `shop_variation_uid` | Exact value in the configured meta key |

All line items are validated before the WooCommerce order is created. If any product or variation cannot be matched, the webhook returns a non-success response so the delivery can be retried after the catalog mapping is corrected. A variation must belong to the resolved parent product.

## Order status behavior

| Skroutz state | WooCommerce behavior |
| --- | --- |
| `open`, `accepted`, `dispatched` | `processing` |
| `delivered` | `completed` |
| `cancelled`, `rejected`, `expired` | `cancelled` |
| Return-related or unknown states | Stored as metadata; no automatic destructive status transition |

Terminal WooCommerce orders are not moved backwards by later webhook retries.

## Webhook security

Skroutz order webhooks do not include a signature or bearer credential. This plugin therefore creates a long random secret and requires it on every webhook request through the registered URL.

For defense in depth, you can also allow the official Skroutz webhook IP ranges at your firewall or security service. Consult the current official documentation before applying an IP allowlist because ranges can change and reverse proxies require correct client-IP handling.

Official documentation:

- [Skroutz Smart Cart webhook](https://developer.skroutz.gr/smart_cart/webhook/)
- [Skroutz Smart Cart Orders API](https://developer.skroutz.gr/smart_cart/orders_api/)

## Privacy

Customer address details received in a valid order event are stored in the WooCommerce order, as required to fulfill the order. Debug logging is disabled by default and never writes the raw webhook payload, customer name, address, email, or telephone number.

Site owners remain responsible for their privacy policy, retention settings, access controls, and legal obligations.

## Scope and limitations

This release imports and synchronizes incoming order webhooks. It does not call the separate Skroutz Orders API to accept or reject an order, upload an invoice, download a voucher, or mark a parcel as ready for dispatch.

The WooCommerce order total is built from the line-item totals supplied in the webhook. Marketplace settlements, commissions, and merchant-side fees are not added as customer-facing WooCommerce charges.

This package uses its own options and order metadata namespace. It is not intended as a drop-in update for differently prefixed private forks without a planned data migration.

## Development

The plugin has no build step and no production dependencies outside WordPress and WooCommerce.

Run a PHP syntax check from the repository root:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

Before tagging a release, complete the staging checks in [TESTING.md](TESTING.md).

## License

Copyright © 2026 Ilias Euthimiou.

Licensed under the GNU General Public License v2.0 or later. See [LICENSE](LICENSE).
