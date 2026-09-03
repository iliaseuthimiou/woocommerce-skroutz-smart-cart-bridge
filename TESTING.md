# Manual release checklist

Run these checks on a staging WooCommerce site before publishing a release or installing it in production.

## Environment

- Test once with legacy order storage and once with HPOS enabled.
- Use products whose IDs, SKUs, or custom-meta identifiers are known.
- Keep a database backup and use a non-production Skroutz demo order when available.

## Activation and settings

1. Activate the plugin with WooCommerce active.
2. Confirm **WooCommerce → Skroutz Bridge** opens without notices or PHP errors.
3. Save each matching mode and confirm the selection persists.
4. Confirm the displayed webhook URL uses HTTPS and contains a non-empty `key` parameter.
5. Regenerate the URL and confirm the previous key receives HTTP 401.

## New-order event

1. Send a valid `new_order` payload with the current key.
2. Confirm exactly one WooCommerce order is created.
3. Confirm customer, address, items, quantities, totals, order code, and Skroutz state are correct.
4. Confirm the matching product or variation stock is reduced once.
5. Send the exact payload again.
6. Confirm no second order is created and stock is not reduced again.

## Validation failures

Confirm each of these requests creates no partial WooCommerce order:

- Missing or invalid webhook key.
- Missing order code.
- Missing `shop_uid`.
- Unknown product identifier.
- Unknown variation identifier.
- Variation belonging to a different parent product.
- Zero quantity or invalid price.

## Order updates

1. Send an `accepted` update and confirm the order remains `processing`.
2. Send a courier voucher or tracking update and confirm the metabox changes.
3. Send a `delivered` update and confirm the order becomes `completed`.
4. In a fresh test order, send a `cancelled` update and confirm the order becomes `cancelled` and stock is restored once.
5. Repeat the cancellation and confirm stock does not increase again.
6. Send an event with an older `event_time` and confirm it does not overwrite newer metadata.

## Administration and privacy

1. Confirm the Skroutz column and order metabox work in both order-storage modes.
2. Enable debug logging and send a test event.
3. Confirm the log contains only event type, order code, state, item count, and technical outcome.
4. Confirm the log does not contain customer name, street, email, telephone number, or the full payload.
