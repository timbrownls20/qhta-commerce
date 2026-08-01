# QHTA Commerce

WooCommerce-side logic plugin for qhta.com.au. Currently one job: **gating pages
behind a WooCommerce purchase**, so paid content (conference recordings and the
like) is locked without a membership plugin.

## Scope

**Belongs here:** WooCommerce behaviour — purchase gates, future Woo tweaks.

**Does not belong here:** presentation (that's `qhta-theme-extras`), conference
domain logic (that's the conference program plugin).

**Test:** "Is it WooCommerce behaviour that is *not* presentation and *not*
conference-specific?" Yes -> here.

Deliberately out of scope: member pricing and discounts (the PMPro WooCommerce
Integration add-on owns that, per-product), account creation / checkout / emails
(native WooCommerce settings), and the look of the gated pages themselves.

There is **no settings screen**. The per-page field *is* the configuration.

## How gating works

Configuration lives on the page, not in code. Each Page gets a **Product** panel
in the editor sidebar holding a searchable product picker.

- **Blank** -> normal public page.
- **A product ID** -> the page only renders for a logged-in customer who has
  bought that product. Everyone else is redirected to `/not-found/`.

The gate reads the *current page's own* meta (`_qhta_gate_product_id`) on
`template_redirect`. There is no central map and no slug list, which means:

- adding a gated page needs **zero code** — create page, type product ID,
  publish;
- **renaming a page or changing its slug cannot break the gate**, because it
  keys off the page ID and its stored field, not a hardcoded string.

The purchase check is WooCommerce core's `wc_customer_bought_product()`, which
matches paid statuses (completed + processing). Two consequences worth knowing:

- virtual / downloadable products, which often sit at *processing*, unlock
  immediately — no manual order completion needed;
- a **refund revokes access on the next page load**. Nothing is stored, so
  nothing has to be kept in sync or manually revoked.

## Gating a page

1. Edit the Page -> **Product** panel in the sidebar.
2. Start typing the product name and pick it from the list.
3. Update.

To un-gate, clear the picker (the little x) and update. To gate a second page,
repeat — there is never a code change.

The picker is WooCommerce's own product search, borrowed rather than rebuilt.
Two things follow from that:

- It searches **products, not variations**. For a variable product, gate on the
  parent — an order records the parent as `_product_id` whichever variation was
  bought, so the parent unlocks all of them.
- Woo's search endpoint is nonce-protected but has no capability check of its
  own, so Editors can use the picker too, not only Administrators.

What's stored is still just the product ID, so nothing changes for pages that
were gated before the picker existed.

## Structure

```
qhta-commerce/
  qhta-commerce.php     Bootstrap: header, per-page field, gate. Keep thin.
  scripts/build-zip.sh  Packages the deploy zip. Not shipped.
  README.md
  CHANGELOG.md
```

Single file is right for v1. If more Woo behaviour lands, split into `includes/`
required from the bootstrap rather than letting the bootstrap sprawl.

No dependencies, no build step, no Composer. Plain PHP, drop-in plugin.

## Install

```bash
./scripts/build-zip.sh
```

Writes `qhta-commerce-X.Y.Z.zip` into the plugin root. Then wp-admin -> Plugins
-> Add New -> Upload Plugin -> Activate. (WordPress on Hostinger.)

Bump `Version` in the plugin header **and** `QHTA_COMMERCE_VERSION` together on
every change — the build script refuses to run if the two disagree.

Zipping the folder by hand works too; the archive just needs `qhta-commerce/` as
its top level.

## Deployment requirements

### Caching — required, not optional

**Every gated page and the My Account page must be excluded from full-page
caching.** A page cache that serves a stored copy never reaches PHP, so the gate
never runs and a cached copy of paid content can be served to a non-buyer.

The plugin sends no-cache headers on gated pages as defence in depth, but it
cannot enforce this — headers only help caches that actually ask PHP. Configure
the exclusion at the host: hPanel -> Websites -> qhta.com.au -> Advanced ->
Cache Manager, plus any CDN or caching plugin in front of it.

### WooCommerce must stay active

With WooCommerce deactivated the plugin fails **open**: no fatal error, and the
gate does nothing, so gated pages become public. This is deliberate — a broken
gate should not take the whole site down — but it means deactivating Woo
silently unlocks paid content. An admin notice flags it in wp-admin.

The editor field degrades with it: no WooCommerce means no product search, so
the panel falls back to a plain **Product ID** number input. Same meta, same
stored value — you just have to know the ID.

## Extension points

Two filters, both optional:

```php
// Move the redirect destination without editing the plugin.
add_filter( 'qhta_commerce_sales_url', function () {
	return home_url( '/some-sales-page/' );
} );

// Widen entitlement — e.g. let editors preview a gated page they haven't bought.
add_filter( 'qhta_commerce_is_entitled', function ( $entitled, $product_id, $page_id ) {
	return $entitled || current_user_can( 'edit_post', $page_id );
}, 10, 3 );
```

By default **nobody** bypasses the gate, administrators included. That keeps the
behaviour honest when testing, at the cost of having to log out (or use the
filter above) to preview a gated page you don't own.

## Notes

- The redirect is a **302**, not a 301. Entitlement changes; a permanent
  redirect would be cached by the browser and keep bouncing a visitor who has
  since bought.
- A page that is both gated and the redirect destination would redirect to
  itself forever, so that misconfiguration fails open instead.
- `/not-found/` need not exist as a Page. If it doesn't, WordPress serves the
  theme's 404, which is the intended outcome: a non-buyer sees "this page
  doesn't exist" rather than being told there is paid content behind the URL.
- Deactivating the plugin removes all gate behaviour. Stored meta stays behind
  harmlessly and takes effect again on reactivation.
- The meta key is underscore-prefixed (`_qhta_gate_product_id`) so it stays out
  of the generic Custom Fields UI. The sidebar panel is the intended way in.

## Open items

- The redirect destination is `/not-found/`. If a real sales page is wanted
  later, point it there via the `qhta_commerce_sales_url` filter or by editing
  the default in `qhta_commerce_sales_url()`.
- Confirm each page/product pairing when gating. The picker only offers real
  products, so a typo can no longer gate a page against everyone — but it will
  happily let you pick the wrong product, and that looks identical until a buyer
  reports being locked out.
