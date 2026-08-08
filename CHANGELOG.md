# Changelog

## 1.5.2 — 8 August 2026

**1.5.1 broke checkout.** Removing the phone field stopped buyers at the payment
step with a Stripe.js error:

> A phone number is required to confirm this Checkout Session. Provide a phone
> number using `updatePhoneNumber()` or pass `phoneNumber` to `confirm()`.

Worse than the asterisk it replaced — a buyer who can neither pay nor see why.
Upload this before taking any more orders.

### Fixed
- **Phone is now removed from what Stripe asks for, as well as from the form.**
  The two are different levers and 1.5.1 pulled only one.

  The WooCommerce Stripe gateway builds its Checkout Session from an **option**,
  never from the filtered checkout fields
  (`class-wc-stripe-checkout-sessions-ajax-handler.php`):

  ```php
  if ( 'required' === get_option( 'woocommerce_checkout_phone_field', 'required' ) ) {
      $request['phone_number_collection'] = [ 'enabled' => 'true' ];
  }
  ```

  No priority on `woocommerce_checkout_fields` reaches that — which also closes
  the 1.5.1 mystery about "something re-asserting the field after priority 20".
  Nothing was fighting the filter; the option simply still said `required`, and
  had never been set, so it was sitting at that default.

  Found in Stripe's API logs: the create call (`POST /v1/checkout/sessions`,
  no ID on the path) carried `phone_number_collection[enabled]=true` in its
  **request body**, which rules out the Stripe Dashboard toggle — a parameter in
  the request overrides it. Same log is how to verify the fix: the next create
  call should have no `phone_number_collection` at all.

### Added
- **`qhta_commerce_checkout_phone_mode()`** — one switch driving both levers,
  `hidden` by default, also `optional` or `required`:

  | Mode | Checkout form | Stripe |
  |------|---------------|--------|
  | `hidden` | field removed | not asked for a number |
  | `optional` | field kept, required flag dropped | not asked for a number |
  | `required` | untouched | collects as before |

  `required` means "leave everything alone", not "assert required", and anything
  unrecognised normalises to it. Filterable via
  `qhta_commerce_checkout_phone_mode`, so the site can move between the three
  without a deploy.

### Notes
- **This plugin now forces a WooCommerce option**, via
  `pre_option_woocommerce_checkout_phone_field` — the thing 1.5.0 explicitly
  refused to do for the block checkout, so the exception needs justifying.

  In WooCommerce core, `woocommerce_checkout_phone_field` is read **only by the
  Blocks checkout**, and its only UI is the Checkout *block* editor. On this
  site's classic checkout nothing in core reads it and nothing has ever written
  it. There is no settings screen left saying otherwise — it was an
  effectively Stripe-facing setting with no owner, and now it has one. The 1.5.0
  objection was to a plugin contradicting a visible control; there is no visible
  control here.

  **If the checkout is ever rebuilt as blocks that changes**, and the bargain
  should be revisited: the editor would then show a control for this option and
  the filter would silently override it.
- `pre_option_` rather than `option_`, because the option does not exist in the
  database and `get_option()` returns a missing option through `default_option_`
  without ever applying `option_` — an `option_` callback would simply never run.
- The `required` mode returns the short-circuit value untouched rather than
  asserting `'required'`, so a stored value still wins if one ever appears.
- Priority 9999 on the field filter stays. Its original reason turned out to be
  the option, not a rival filter, but it costs nothing and does guard a real
  edge: anything re-asserting a key on a field this filter has unset would
  recreate it as a bare `array( 'required' => true )` — an unlabelled text input
  rather than nothing.
- Still **no phone number collected at all** in the default `hidden` mode, so
  orders and order emails carry none. `optional` is the middle ground, and is now
  safe: Stripe stops requiring one in that mode too, so a buyer leaving it blank
  no longer hits the payment-step error.

## 1.5.1 — 8 August 2026

Everything here came from looking at the live checkout after 1.5.0 went up.

### Changed
- **Phone is removed entirely, not made optional.** Decided on sight: nothing on
  this site phones a buyer — the products are digital, delivery is an account and
  a link — so an optional field a buyer still stops to consider is a cost with no
  return.

  This means **no phone number is collected at all**; orders and order emails
  carry none. Putting it back as optional is one line, noted in the README.
- **Checkout-field filter moved from priority 20 to 9999.** At 20 the phone
  field came back marked required on the live checkout while the username
  description — set in the same pass, same function — took fine. So the field
  array is being changed again after us, and running last is the cheap fix.

  It matters more now that the tweak *unsets* fields rather than editing them:
  anything re-asserting a key on a field that is gone recreates it as a bare
  `array( 'required' => true )`, which renders as an unlabelled text input rather
  than as nothing.

  **What does the re-asserting is still unidentified.** Most likely a leftover
  Code Snippet from the batch these tweaks replace — worth finding and deleting,
  since two things arguing over the same field is the tangle the plugins are
  meant to end. Added to the README's open items.

### Fixed
- **The "Additional information" heading is gone**, not just the notes field
  under it. Unsetting `order_comments` left the checkout showing a heading with
  nothing beneath it: Woo's `form-shipping.php` prints that `<h3>` inside a check
  of `woocommerce_enable_order_notes_field`, and never asks whether any field
  survived the filter. The plugin now returns false from that filter too.

  That switch covers the whole section, heading included, which makes the unset
  technically redundant on the classic checkout. Both stay — the filter governs
  what the template renders, the unset governs what is in the field array, so
  anything reading `get_checkout_fields( 'order' )` directly sees the field gone
  rather than merely unrendered.

  Returning false rather than setting the `woocommerce_enable_order_comments`
  option, because that option is the block checkout's order-note toggle: writing
  it would move a control the block editor owns and displays the state of.

### Notes
- **The site runs the classic checkout** — confirmed live, so the field tweaks
  are the working code path rather than a fallback, and 1.5.0's open question
  about which checkout is closed. The block equivalents stay documented against
  the day the checkout is rebuilt, which is the one thing that would silently
  stop three of these four applying.

## 1.5.0 — 8 August 2026

### Added
- **Checkout tweaks** — four adjustments to the WooCommerce store checkout and
  account, in a new section 6 of the plugin file:
  - **Phone is optional.** The field stays; the required flag goes. Buyers who
    have a phone number can still leave one, they are just not stopped at the
    door for not having one. Removing it entirely is one line away
    (`unset( $fields['billing']['billing_phone'] )`) if that turns out to be
    wanted — optional was judged the smaller change.
  - **Order notes removed.** The products are digital and there is no fulfilment
    step to instruct, so the field was asking for something nobody reads.
  - **Password strength down to medium** (2 on Woo's zxcvbn scale, from Woo's
    default of 3/strong). Medium still rejects `password`, `123456` and the
    buyer's own email — it removes a wall, not the floor. The meter still shows;
    the filter changes what is *accepted*, not what is displayed.
  - **Help text on the account username field**, saying what the account is
    actually for: "you'll use it (or your email) to log in and access your
    recordings anytime." Buyers read "username" as one more form field; it is how
    they reach what they just paid for.

  Wording is filterable via `qhta_commerce_account_username_description`, the
  same treatment the member banner's text gets.

### Notes
- **These three sit behind classic-vs-block.** Phone, order notes and the
  username text all go through `woocommerce_checkout_fields`, which is the
  *classic* (shortcode) checkout's field array and **does nothing on the block
  checkout** — that builds its fields from settings and block attributes instead.
  A fresh 2026 Woo install defaults to block, so **confirm which this site uses**
  (Pages -> Checkout -> Edit) before testing: on block, the equivalents are admin
  steps, tabulated in the README.

  The plugin does **not** force the underlying options from code to cover the
  block case. It could — `option_woocommerce_checkout_phone_field` is right
  there — and deliberately doesn't: the settings screen would then say one thing
  while the checkout did another, which is a worse problem than a documented
  admin step. Password strength is unaffected either way; it feeds Woo's strength
  meter, not the field array.
- **The username tweak has a dependency worth knowing.** The Account username
  field only exists because Woo's "Generate account login" setting is off (buyers
  pick their own). Switch that on and the field — and the help text with it —
  disappears. Guarded by `isset()`, so it degrades to a no-op rather than a
  notice.
- **Homed here by the checkout-split rule**, now recorded in the README's Scope
  section: a checkout tweak belongs to the plugin owning *the checkout it acts
  on*, not the one owning the kind of change. Woo store checkout -> here; PMPro
  membership checkout (`pmpro_is_checkout()`, `.pmpro_form`) -> `qhta-membership`.
  Same-looking change, same-looking field, different plugin. This reassigns the
  backlog items "remove order notes", "limiting mandatory billing fields" and
  "password strength" out of `qhta-membership`.
- Filter runs at **priority 20**, after Woo builds the array and after anything
  adding fields at the default 10 — a field added later at 10 could otherwise put
  back the required flag this clears.
- No WooCommerce guard on either hook, and none needed: both are Woo's own
  filters, so with WooCommerce inactive neither fires and no Woo function is
  called.

## 1.4.1 — 7 August 2026

### Changed
- **Cart button is white**, rather than inheriting the header's link colour. On
  the navy header that inherited grey left it barely visible. Hover goes teal.

  `--qhta-cart-ink` on `.qhta-cart-button` is the single line to change if the
  button ever has to sit on a light header — set it to `var(--qhta-navy)` and
  the icon follows, since the glyph takes `currentColor`. Icon also up from
  1.35em to 1.5em.
- **An empty cart shows no number**, just the icon. A badge reading "0" says
  nothing the empty icon does not, so it went rather than being muted.

  Done in CSS on the existing `.qhta-cart-button--empty` class, not by rendering
  less markup. The anchor still has to be in the DOM: fragments replace the node
  matching `a.qhta-cart-button`, so removing it means the count never reappears
  when the first item is added. Only the number inside it is hidden.
- **Member banner is a tinted band, not a solid navy panel.** Full-strength
  brand above the product grid competed with the products and read heavier than
  the message deserves — it is a nudge, not an announcement. Now a teal wash at
  8%, a hairline of the same teal, navy text and accent links, on a softer
  radius with more air below. Same brand colours, at the weight of a note.

## 1.4.0 — 7 August 2026

### Added
- **"Cart button" checkbox on menu items**, so the cart button can go in a nav
  menu. Appearance -> Menus -> add a Custom Link (URL `#`, any label) -> tick
  the box. The item's URL and label are then ignored; the whole item is replaced
  by the button.

  This exists because **pasting `[qhta_cart_button]` into a menu item's label
  does not work** — it renders on the site as literal text. Nav labels run
  through `the_title`, which has no `do_shortcode` attached. Adding one would
  turn shortcodes on for every menu item on the site and make any label
  containing square brackets a hazard, which is far wider than this needs.

  Replacing the whole item — via `walker_nav_menu_start_el` rather than a label
  filter — is the other half. By the time a label is filtered the walker has
  already opened an `<a>`, and the cart button is an `<a>`; nesting them is
  invalid HTML that browsers silently tear apart, leaving the button outside the
  link it belonged to.

  Stored as `_qhta_cart_button`, its own flag rather than a reuse of
  `_qhta_store_link` — they answer different questions. A cart-button item is
  **removed from the menu entirely** (not left as an empty `<li>` collecting the
  theme's padding) when the store is hidden or WooCommerce is inactive, so
  "Store link" does not also need ticking.
- **`assets/qhta-commerce.css`** — the banner and cart button are now styled
  here rather than in `qhta-theme-extras`. Enqueued on the front end while the
  store is visible; nothing loads before go-live, since neither component
  renders then.

  Scoped to `.qhta-member-banner` and `.qhta-cart-button`: no element selectors,
  nothing that can reach the theme, and the brand tokens are declared on the
  components rather than on `:root`, so the plugin never defines site-wide
  custom properties.

  The banner commits to brand colours (navy panel, teal rule and links). The
  cart button does not — it takes `currentColor` from whatever header it sits
  in, so it matches the surrounding nav; only the count badge asserts a colour,
  in accent, which is the point of a badge. The icon is Feather's
  `shopping-cart` (MIT), inlined as a data URI and applied with `mask-image` so
  it picks up the text colour: no icon font, no extra request, no library to
  keep in step.

  Versioned on `QHTA_COMMERCE_VERSION`. **Editing the CSS without bumping the
  plugin version means the change reaches nobody who has visited before** — the
  old file sits in browser and CDN caches.

### Changed
- **Scope rule carved out.** "Presentation lives in `qhta-theme-extras`" now
  reads "*theme* presentation lives in `qhta-theme-extras`", with the shopfront
  components this plugin creates keeping their own CSS. They only exist while
  this plugin is active, so markup and styling in one repo means one deploy per
  change instead of two, and no cross-repo class-name contract to keep in step.
  Styling anything the theme owns still belongs in theme-extras.

## 1.3.0 — 7 August 2026

### Added
- **Member-pricing banner on the shop.** Above the product grid on the Shop page
  and product category/tag archives (`woocommerce_before_shop_loop`, priority 5 —
  above the result count and ordering dropdown, below the title), telling
  non-members that logging in or joining gets them a better price.

  Not shown to members, who already have the discount. Not shown to anyone when
  PMPro is inactive: the brief's snippet rendered it in that case and acceptance
  criterion 8 says fail safe, so this follows the criterion. It is also the only
  safe reading — with no membership plugin there is no member pricing to
  advertise, the join link points at a page that no longer does anything, and
  members cannot be told from non-members, so the one group it must never reach
  would get it.

  The log-in link reuses `qhta_commerce_login_url()` rather than hardcoding
  `/login/` a second time, so it carries `redirect_to` back to the Shop page
  instead of stranding people. The join link resolves to PMPro's Levels page when
  configured — same reasoning as the existing Shop-page lookup, no slug in code,
  survives a rename — falling back to the brief's `/membership-account/`. **That
  fallback wants confirming**: in a stock PMPro install it is the account page a
  signed-in member lands on, not where a prospect picks a level.

  No store-preview guard, and none is missing: the hook only fires on shop and
  product archives, which are already redirected away while the store is hidden.
- **`[qhta_cart_button]` shortcode** — cart icon plus live item count, linking to
  the Cart page. A shortcode rather than a hooked-in header element because the
  header belongs to the theme; drop it into an Astra header HTML widget or an
  Elementor HTML element. **Confirm where in the header it should sit.**

  The count updates on add-to-cart without a reload via
  `woocommerce_add_to_cart_fragments`, keyed on `a.qhta-cart-button`. That
  selector must match the button's outer element exactly — change the tag or the
  class and the count silently stops updating while everything still renders, so
  both live in one function that the shortcode and the fragment share.

  `wc-cart-fragments` is enqueued site-wide while the store is visible. Woo only
  enqueues it on its own pages and a header button is on all of them; it cannot
  be made conditional on the shortcode being present, because header widget
  content is composed long after `wp_enqueue_scripts` has run.

  Self-guards on `qhta_commerce_store_visible()` and renders nothing while the
  store is hidden. It has to: the per-menu-item "Store link" checkbox only
  reaches nav menu items, and this lives in a header widget.
- **`qhta_commerce_join_url` and `qhta_commerce_member_banner_text` filters.**
  The banner text is passed through `wp_kses_post()` on output, so a filter can
  return links and emphasis but not script.
- **`qhta_commerce_pmpro_active()` and `qhta_commerce_is_member()`** — the
  membership counterparts to `qhta_commerce_woo_active()`, so "is PMPro there"
  and "is this a member" are asked in one place each.

### Notes
- **Empty cart shows `0` plus a `.qhta-cart-button--empty` class, rather than
  rendering nothing.** The brief offered a `qhta_commerce_hide_empty_cart` filter
  as optional; a filter that returned empty markup would break the feature.
  Fragments replace the node matching `a.qhta-cart-button` — once that node is
  gone there is nothing for the next add-to-cart to update, and the count stays
  invisible until a page reload. Hiding when empty is one CSS rule against the
  modifier class, which does the same job and keeps the node. (Superseded in
  1.4.0, which ships that rule commented out in the plugin's own stylesheet.)
- **Styling was `qhta-theme-extras`' at this version** — this shipped
  `.qhta-member-banner`, `.qhta-cart-button`, `.qhta-cart-button--empty`,
  `.qhta-cart-icon` and `.qhta-cart-count` with no colours at all. Moved into
  the plugin in 1.4.0.
- The cart button carries an `aria-label` of "View cart, N items"; the icon and
  the number are `aria-hidden` so a screen reader gets the sentence rather than
  the sentence followed by a bare digit.
- **The banner advertises member pricing; it does not apply it.** The discount is
  the PMPro WooCommerce Integration add-on's, configured per product. If that is
  not set up on a product, the banner promises a discount that will not appear at
  checkout — worth checking together before launch.
- Closures were used throughout the brief's snippets; these are named
  `qhta_commerce_*` functions with the `add_action`/`add_filter` alongside, per
  the house convention in the rest of the file. Behaviour is unchanged, but every
  hook stays removable.
- Not code: both products still show **"Uncategorised"** on the shop. Assign a
  real product category (e.g. "Study Packages") in Products -> Categories before
  launch. Noted in the README's open items.

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
