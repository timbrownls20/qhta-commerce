# Changelog

## 1.2.1 — 7 August 2026

### Changed
- **Administrators no longer bypass store preview mode.** `manage_options` is
  gone from `qhta_commerce_store_visible()`; the store is visible when
  `QHTA_STORE_LIVE` is `true` or the browser carries a valid preview cookie, and
  capabilities do not come into it. While hidden, store nav links are dropped
  and store URLs redirect home for administrators exactly as they do for the
  public.

  The bypass was there for convenience and it works against the point of the
  feature: an account that sees a different site from the one it is launching
  cannot tell whether the launch worked, and "looks fine when I check it" is how
  a broken shopfront survives to go-live. This also brings preview mode in line
  with the purchase gate, which no role bypasses either.

  An administrator who wants to see the hidden store takes the preview cookie
  like anybody else. wp-admin is untouched — nothing there redirects — so the
  store is still fully buildable while hidden.

  **`QHTA_STORE_PREVIEW_TOKEN` is now effectively required**, not optional. It
  was the fallback that made an unset token harmless; with no bypass left, an
  unset token means the hidden shopfront cannot be viewed by anyone until
  go-live. Still fail-closed, which is the right direction to fail, but set it.

  **Editor Preview on a gated page is caught by this too.** The Preview button
  produces an ordinary front-end request, so while the store is hidden and the
  browser has no preview cookie it redirects home like any other store URL. Turn
  preview on first. Say if that one wants an exception — it would be the one
  piece of admin special-casing left, so it is not in by default.
- **Admin notice reworded** to match: it no longer claims the reader can see the
  store, and points at the preview link instead. It still keys off
  `QHTA_STORE_LIVE` rather than `qhta_commerce_store_visible()` — an admin
  holding a preview cookie is still looking at a store the public cannot see,
  which is exactly when the notice needs saying.

## 1.2.0 — 4 August 2026

### Added
- **Store preview mode.** The store is hidden from the public by default —
  built and working, but invisible — so it can be finished and tested on the
  live site rather than half-deployed. While hidden, nav links flagged as store
  links are dropped from rendered menus and store URLs redirect home.

  It is visible when any one of these holds: `QHTA_STORE_LIVE` is `true`, the
  visitor can `manage_options`, or the browser carries a valid preview cookie.
  `qhta_commerce_store_visible()` is the single answer to that question and is
  filterable. (The `manage_options` bypass was removed in 1.2.1.)
- **Logged-out preview via a signed cookie.**
  `?qhta-store-preview=THE_TOKEN` sets it for 30 days, `?qhta-store-preview=off`
  clears it. This is the point of the feature: an administrator is the wrong
  person to test a shopfront with, so preview cannot depend on being signed in.

  The cookie holds `wp_hash( token )` rather than the token, compared with
  `hash_equals()` — unforgeable, and a leaked cookie does not hand over the
  secret that mints new ones. It is seeded into `$_COOKIE` as it is set, so the
  request that turns preview on is already a preview request and the link you
  click does not bounce you home.

  `QHTA_STORE_PREVIEW_TOKEN` has **no default and is not defined in the
  plugin** — this repo is in version control and a default would be a
  committed, shared password. Undefined means preview is unavailable: the safe
  way to be misconfigured. It goes in `wp-config.php`.
- **Per-menu-item "Store link" checkbox** in Appearance -> Menus, stored as
  `_qhta_store_link`. Editorial rather than automatic, because which links
  belong to the store is a judgement and matching URLs in code would break the
  moment a page moved. Unflagged items are never touched.
- **Blocking on `template_redirect`** at priority 5, ahead of the gate at 10: a
  hidden store should send a logged-out visitor home, not to `/login/` to sign
  in for content that is not on sale yet. Carries the gate's self-redirect
  guard, so pointing the destination at a page that is itself blocked fails open
  instead of looping.
- **Admin notice while the store is hidden.** From wp-admin, hidden and live
  look identical — which is how a launch gets forgotten, or how "the shop is
  broken" gets reported weeks later.
- `qhta_commerce_store_visible` and `qhta_commerce_store_hidden_redirect`
  filters.

### Changed
- **Blocking covers the product catalogue, not just the four WooCommerce
  pages.** The brief listed Cart, Checkout, My Account, Shop and the gated
  pages; single products, `/product/` archives and product category/tag
  archives are blocked too. Shutting the Shop page alone would leave every
  `/product/...` URL public and indexable — a store search engines can crawl
  item by item is not hidden. Say if this is wider than wanted.

### Notes
- **Caching, and this one bites.** Full-page caching serves a stored copy
  without consulting the cookie, so the logged-out preview does not work under
  cache and a stale "redirected away" response can reach real buyers after
  go-live. **Cart, Checkout and Shop now need excluding too** — gated pages,
  `/login/` and My Account already are. (As of 1.2.1 the cookie is the only
  preview there is, so these exclusions are what make preview work at all.)
- The token sits in a URL, so it reaches browser history, server access logs and
  any referrer sent off-site. Treat it as a password and rotate or drop it after
  launch.
- The preview cookie is derived from the site's salts, so rotating those signs
  everyone out of preview.
- Hiding a flagged menu item hides its descendants, so a flagged parent does not
  leave its children rendering at top level.
- The menu-item save only runs when `menu-item-db-id` is posted — i.e. a real
  save from the menu editor. `wp_update_nav_menu_item` also fires for
  programmatic updates and importers, where nothing was posted, and falling
  through to the delete branch would have silently unflagged every store link.
- Products can still appear as titles in **site search and the WordPress
  sitemap** while hidden; clicking through redirects home. Excluding them from
  search and indexing is presentation, not commerce logic, so it belongs in the
  theme or an SEO plugin rather than here.
- Without WooCommerce the blocking fails **open**, matching the gate. Nothing is
  hidden, nothing fatals, and the existing admin notice already flags the state.
- **Confirm:** that `QHTA_STORE_LIVE` in `wp-config.php` is the wanted go-live
  mechanism rather than an admin checkbox, and that blocked URLs should land on
  the home page rather than a "coming soon" page.

## 1.1.1 — 4 August 2026

### Fixed
- **The My Content tab 404ed after upgrading to 1.1.0.** The rewrite flush 1.1.0
  added runs on `register_activation_hook`, which a plugin *update* never fires:
  uploading a zip over an active plugin deactivates and reactivates it silently
  (`activate_plugin( …, $silent = true )`), skipping both the deactivation and
  activation hooks. So the endpoint was registered but its rewrite rule was
  never written, and `/my-account/my-content/` fell through to the theme's 404.

  Rewrite rules are now flushed **once per plugin version**, stamped in the
  autoloaded `qhta_commerce_rewrites_version` option and checked on `wp_loaded`.
  The first request after any upload rebuilds them, however the new code
  arrived. The activation hook stays as the belt to that braces; it also stamps
  the option so a fresh activate does not flush twice.

  On `wp_loaded` rather than `init` deliberately: `flush_rewrite_rules()` writes
  whatever is registered at that moment, so flushing mid-`init` risks baking in
  a rule set missing the endpoints of any plugin registering later than we do —
  WooCommerce's own account endpoints among them.

  **Upgrading from 1.1.0 needs no manual step.** If a tab ever 404s again, the
  fallback is still Settings -> Permalinks -> Save Changes.
- Deactivation now clears the version stamp, so reactivating rebuilds the rules.
  Its flush does **not** remove the endpoint — deactivation runs long after
  `init`, so the endpoint is still registered in that request and is written
  straight back, and WordPress has no `remove_rewrite_endpoint()`. The leftover
  rule is inert: nothing loads the query var once the plugin is gone, so the URL
  404s, which is the wanted outcome. The comment in 1.1.0 claiming otherwise was
  wrong.

### Notes
- The tab lives at **`/my-account/my-content/`** — it is a My Account endpoint,
  so `/my-content/` at the site root is not it and never renders the tab.

## 1.1.0 — 4 August 2026

### Added
- **"My Content" tab in My Account**, at `/my-account/my-content/`, sitting
  immediately before Log out. It lists every gated page the signed-in customer
  can currently open, each as a link.

  The list is **derived, not maintained**: all published pages carrying
  `_qhta_gate_product_id`, filtered to the ones the customer is entitled to. It
  reads the same meta the gate reads, through the same
  `qhta_commerce_is_entitled()`, so the two cannot drift. Gating a new page puts
  it in every entitled customer's tab with no code change; a refund drops the
  link at the same moment it drops access. No product IDs and no page slugs
  appear in the tab.

  This supersedes any hardcoded single-product version of the same idea. Pages
  are linked, never embedded — opening one runs the gate again, so the tab is a
  way in, not a way round.
- **Empty state** for a customer with no entitlements yet: a short message and a
  **Browse products** button, rather than a blank tab.
- `qhta_commerce_browse_url` filter for that button. Defaults to WooCommerce's
  own Shop page — found rather than hardcoded, so moving or renaming the shop
  does not break it — falling back to `qhta_commerce_sales_url()` if no Shop
  page is set. Deliberately a separate filter: `qhta_commerce_sales_url` is
  where a *blocked* visitor is sent and currently points at `/login/`, which is
  no use to someone already signed in and reading their own account page. The
  brief's `/recordings/` was not used as the default because that page does not
  exist on the site — see 1.0.1, where it was dropped for the same reason.
- Rewrite-rule flush on **activation** (and again on deactivation), so the tab
  works straight after install with no Settings -> Permalinks -> Save Changes.
  The endpoint is re-registered inside the activation hook first, since
  activation runs before this plugin's `init` has fired and the flush would
  otherwise write rules without it.
- `QHTA_CONTENT_ENDPOINT` constant, used for the slug, the menu-item key and the
  action/filter suffixes together, so those cannot disagree.
- Title for the endpoint page via `woocommerce_endpoint_my-content_title`.
  `WC_Query::endpoint_title()` only knows Woo's own endpoints and returns an
  empty string for anyone else's.

### Changed
- `qhta_commerce_is_entitled()` takes an **optional third `$user_id`**,
  defaulting to the current user, and passes it to the
  `qhta_commerce_is_entitled` filter as a fourth argument. Existing
  three-argument callbacks are unaffected, and the gate's behaviour is
  unchanged.

  This is what lets the tab ask the gate's question about a specific user
  instead of reimplementing it. One definition of "entitled", not two — so a
  filter widening access (the documented editor-preview example) now also lists
  those pages in that editor's own tab, which is the intent.
- The user lookup inside it is `get_userdata()` rather than
  `wp_get_current_user()`, so a user ID that no longer resolves is "not
  entitled" instead of a fatal on `->user_email`.

### Notes
- **Caching:** the exclusion My Account already needed has to cover its
  endpoints, `/my-account/my-content/` included. The tab is a different list per
  customer, so a cached copy would show one customer the titles of another's
  purchases.
- Published pages only. A gated page left in draft or private will not appear,
  even for someone who has bought its product.
- One `wc_customer_bought_product()` call per gated page per tab load. Fine at
  current size. If the catalogue reaches dozens, cache per user — but **not** in
  a plain transient, since Hostinger's object cache can hold transients past
  their TTL and a refunded customer would keep seeing the link. A `wp_options`
  row with `autoload=no` plus an explicit refresh, or a request-scoped static.
- **Confirm the label** — "My Content", with the endpoint slug `my-content`.
  Named generally on purpose, since the feature is not recordings-specific.
  Changing the slug needs a deactivate/reactivate to flush permalinks.

## 1.0.3 — 2 August 2026

### Changed
- **Non-entitled visitors now go to `/login/` instead of `/not-found/`.**
  Logged out, they carry `redirect_to` and land back on the gated page once
  signed in — a customer who was merely signed out is no longer told their paid
  content does not exist. Logged in but not a buyer, they go to the same page
  without `redirect_to`: there is nothing for them to come back to until they
  buy.

  `/login/` is the site's single branded login page — PMPro's Log In page,
  rebranded — and accepts `redirect_to` natively, the same mechanism PMPro's
  checkout "log in here" link uses. Not `/my-account/`, which is the post-login
  dashboard, and not `wp_login_url()`, which is a different, more
  administrative-looking door. Both destinations stay filterable, and stay
  separate filters, so a real sales page can take the non-buyer case as soon as
  there is one.

### Added
- `qhta_commerce_login_url` filter for the logged-out destination, passed the
  return URL. Point it back at `qhta_commerce_sales_url()` to collapse the two
  cases, or elsewhere if the login page ever moves.
- Hidden `redirect` field on **WooCommerce's** login form, populated from
  `redirect_to` — belt and braces for the My Account door, since /login/ handles
  the parameter itself. `WC_Form_Handler::process_login()` honours
  `$_POST['redirect']`, but the stock `myaccount/form-login.php` template
  renders no such field, so the parameter would otherwise be carried to the page
  and silently dropped at login. The value is validated against the site's own
  host before it is echoed, so a crafted login link cannot turn it into an open
  redirect.

### Notes
- This reverses the discretion 1.0.1 bought. Redirecting anyone to a login page
  reveals that *something* exists at that URL, which `/not-found/` was chosen to
  hide. Sending people somewhere that says something was judged worth more than
  that — the content itself is no less protected either way, and `/not-found/`
  goes back through `qhta_commerce_sales_url` if the quiet version is wanted.
- A logged-in non-buyer lands on `/login/` while already signed in, so they see
  whatever that page shows a logged-in visitor. Worth eyeballing once. It is the
  case a real sales page should take over.
- The self-redirect guard now compares without the query string, since the login
  URL carries `redirect_to`. Gating the login page itself still fails open
  rather than looping.
- **Deployment dependency:** `/login/` must keep honouring `redirect_to`. If it
  is ever rebuilt, or `qhta-membership` adds a login redirect of its own that
  ignores the parameter, buyers land on a fixed page instead of the gated one.
- `/login/` is excluded from full-page caching for the same reason gated pages
  are — it now carries a per-visitor `redirect_to`.
- Only the login form is wired up, not registration. Someone who checked out as
  a guest and then registers with the same email is entitled, but will land on
  My Account rather than back on the gated page.

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
