# Changelog

All notable changes to this project are documented here.

## 1.0.0 — 2026-09-03

- Initial public release.
- Added authenticated webhook URLs with regeneratable random secrets.
- Added idempotent order creation with a per-order import lock.
- Added product and variation matching by ID, SKU, or custom meta.
- Added safe WooCommerce state transitions and cancellation restocking behavior.
- Added legacy order-table and HPOS administration support.
- Added privacy-safe optional logging.
- Added validation that rejects incomplete or mismatched line items before order creation.
