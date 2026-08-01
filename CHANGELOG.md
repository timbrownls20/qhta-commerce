# Changelog

## 1.0.2 — 1 August 2026

### Changed
- The gate field is now a **searchable product picker** instead of a number
  input — type a product name, pick it, done. No more looking an ID up in
  Products first and pasting it across.

  It reuses WooCommerce's own `wc-enhanced-select` control and its
  `woocommerce_json_search_products` endpoint, so there is still no JS or build
  step of ours. Woo registers that script on every admin load but only enqueues
  it on its own screens, so the page editor pulls in the script and
  `woocommerce_admin_styles` itself.
- Panel heading is now **Product** (**Product ID** when WooCommerce is inactive
  and the field falls back to the number input), and the redundant "Gated by
  product ID" label above the control is gone. The control keeps an `aria-label`
  so it is still named for screen readers.

### Notes
- Stored data is unchanged — the picker posts a product ID, same as before, so
  pages gated under 1.0.0/1.0.1 keep working and no migration is needed.
- The search finds products, not variations. For a variable product, gate on the
  parent: an order stores the parent as `_product_id` whichever variation was
  bought, so the parent unlocks for all of them.
- If the stored product is later deleted, the picker shows
  `#1234 — product not found` rather than appearing empty, so opening and saving
  the page cannot silently un-gate it.
- Woo's product-search endpoint is nonce-protected but has no capability check
  of its own, so Editors can use the picker, not just Administrators.

## 1.0.1 — 1 August 2026

### Changed
- Non-entitled visitors now go to `/not-found/` instead of `/recordings/`.
  `/recordings/` does not exist on the site, so the previous default landed
  everyone on the theme's 404 anyway. Sending them to a not-found URL is the
  deliberate version of that: a non-buyer is not told there is paid content
  behind the URL they asked for. Still filterable via `qhta_commerce_sales_url`
  if a real sales page is wanted later.

## 1.0.0 — 1 August 2026

Initial release. Stood up to lock paid content pages behind a WooCommerce
purchase without adding a membership plugin.

### Added
- Plugin bootstrap, `QHTA_COMMERCE_VERSION`, WooCommerce-active guard.
- **Purchase gate** meta box on the `page` post type: a numeric "Gated by
  product ID" field in the editor sidebar, stored as `_qhta_gate_product_id`.
  Saved behind a nonce and an `edit_page` capability check; an empty value
  deletes the meta, so a blank field is a public page.
- **The gate** on `template_redirect`: a page with a product ID set renders only
  for a logged-in customer who has bought it, checked with WooCommerce core's
  `wc_customer_bought_product()`. Everyone else is redirected (302) to the sales
  URL. Server-side, so it is a real gate rather than hidden markup.
- `qhta_commerce_sales_url` filter for the redirect destination — defaults to
  `/recordings/`, still to be confirmed.
- `qhta_commerce_is_entitled` filter for widening entitlement (e.g. letting
  editors preview a gated page). Nothing bypasses the gate by default,
  administrators included.
- `nocache_headers()` on gated pages as defence in depth. Does not replace
  excluding gated pages and My Account from full-page caching at the host.
- Admin notice when WooCommerce is inactive, since the gate fails open in that
  state and would otherwise unlock paid pages silently.
- `scripts/build-zip.sh`, which refuses to build on a plugin-header /
  `QHTA_COMMERCE_VERSION` mismatch and `php -l`s every file first.

### Notes
- Configuration is per page, not central. There is no `slug => product ID` map,
  so gating a page needs no code change and a slug rename cannot break a gate —
  the gate keys off page ID and stored meta.
- Refunds revoke access on the next page load. `wc_customer_bought_product()`
  matches only paid statuses, so nothing is stored and nothing needs syncing.
- With WooCommerce deactivated the plugin does nothing rather than fataling.
