# QHTA Commerce

WooCommerce-side logic plugin for qhta.com.au. Six jobs:

- **gating pages behind a WooCommerce purchase**, so paid content (conference
  recordings and the like) is locked without a membership plugin;
- a **"My Content" tab** in My Account listing what each customer can reach;
- **store preview mode** — the store stays hidden from the public until it is
  switched live, while remaining fully testable, including logged out;
- **shopfront personalisation** — a member-pricing banner on the shop, and a
  header cart button with a live item count;
- **checkout tweaks** — phone gone, order notes gone, password strength down to
  medium, the account username field explained, "School or Institution" made
  required, and a notice saying access arrives by email;
- **an "Access your resources" section on the thank-you page**, linking straight
  to the gated pages for what that order just bought.

## Scope

**Belongs here:** WooCommerce behaviour — purchase gates, future Woo tweaks.

**Does not belong here:** theme presentation (that's `qhta-theme-extras`),
conference domain logic (that's the conference program plugin).

**One carve-out:** the shopfront components this plugin *creates* — the member
banner and the cart button — ship their own CSS here, in
`assets/qhta-commerce.css`. They only exist while this plugin is active, so
keeping markup and styling in one repo means one deploy per change rather than
two, and no class-name contract to keep in step across repos. It stays scoped to
those components. Styling anything the **theme** owns still belongs in
`qhta-theme-extras`.

**Test:** "Is it WooCommerce behaviour that is *not* presentation and *not*
conference-specific?" Yes -> here.

**Checkout split:** there are two checkouts on this site, and a checkout tweak is
homed by *which one it acts on*, not by what it does. WooCommerce store checkout
and its thank-you page (`woocommerce_checkout_fields`, `order_comments`,
`woocommerce_thankyou`, Woo's password strength) -> here. PMPro membership
checkout (`pmpro_is_checkout()`, `.pmpro_form`) -> `qhta-membership`. The
same-looking change to the same-looking field lands in different plugins
depending on which form it is.

Deliberately out of scope: member pricing and discounts (the PMPro WooCommerce
Integration add-on owns that, per-product), account creation and emails (native
WooCommerce settings), and the look of the gated pages themselves.

The member banner is not an exception to that first one — it *advertises* member
pricing, it does not apply it. Nothing here touches a price.

There is **no settings screen**. The per-page field *is* the configuration.

## How gating works

Configuration lives on the page, not in code. Each Page gets a **Product** panel
in the editor sidebar holding a searchable product picker.

- **Blank** -> normal public page.
- **A product** -> the page only renders for a logged-in customer who has bought
  it. Everyone else is redirected:
  - **logged out** -> `/login/` to sign in, then back to the gated page;
  - **logged in, hasn't bought it** -> `/login/`, with no return trip.

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

## The "My Content" tab

My Account gets a **My Content** tab, sitting immediately before Log out, at
`/my-account/my-content/`. It lists every gated page the signed-in customer can
currently open, each as a link.

The list is **derived, never maintained**. It is "all published pages carrying
`_qhta_gate_product_id`, filtered to the ones this customer is entitled to" —
the same meta the gate reads, asked through the same `qhta_commerce_is_entitled()`.
Three things follow:

- gating a **new** page puts it in every entitled customer's tab with **no code
  change** — set the page's Product field and it appears;
- a **refund** drops the link on the next load, at the same moment it drops
  access, because both come from the same live purchase check;
- there are **no product IDs and no page slugs** in the tab. It cannot list a
  page the gate would block, and it cannot miss one the gate would allow.

A customer with nothing yet sees a short message and a **Browse products**
button rather than a blank tab. That button points at WooCommerce's Shop page —
found, not hardcoded, so moving or renaming the shop doesn't break it — and
falls back to `qhta_commerce_sales_url()` if no Shop page is set. Point it
somewhere else with the `qhta_commerce_browse_url` filter.

The tab links to pages; it never embeds their content. Opening one runs the gate
again, so the tab is a way *in*, not a way *round*.

### Permalinks

The tab is a My Account **endpoint**, so its only URL is
`/my-account/my-content/`. `/my-content/` at the site root is not the tab and
never renders it.

Endpoints need a rewrite-rule flush before they resolve, or the URL falls
through to the theme's 404. That happens **once per plugin version**, stamped in
the `qhta_commerce_rewrites_version` option and checked on `wp_loaded`, so the
first request after any upload rebuilds the rules — no Settings -> Permalinks ->
Save Changes needed, on a fresh install or an update.

Version-stamped rather than activation-only because **a plugin update never
fires the activation hook**: uploading a zip over an active plugin deactivates
and reactivates it silently, skipping both hooks. That is what made 1.1.0's tab
404 until permalinks were saved by hand. Bumping `QHTA_COMMERCE_VERSION` is
therefore what triggers the rebuild — it already has to be bumped on every
change, so nothing extra to remember.

If a tab ever 404s anyway, Settings -> Permalinks -> Save Changes is still the
fallback, and deactivate/reactivate works too.

### Performance

One purchase check per gated page, per tab load. Fine for a handful of gated
pages. If the catalogue grows to dozens and the tab feels slow, cache the result
per user — but **not** in a plain transient: Hostinger's object cache can hold
transients past their TTL, so a refunded customer could keep seeing the link.
Use a `wp_options` row with `autoload=no` plus an explicit refresh, or a
request-scoped static. Not needed at current size.

## Store preview mode

The store is **hidden from the public by default** — built, populated and
working, but invisible — so it can be finished and tested on the live site
rather than half-deployed. Going live is one constant.

While hidden:

- nav links **flagged as store links** are removed from rendered menus;
- store URLs **redirect home**: Cart, Checkout, My Account, Shop, the whole
  product catalogue (`/product/...`, product categories and tags), and every
  gated content page.

### Who sees it anyway

Either of these reveals it:

1. **Live** — the `QHTA_STORE_LIVE` constant is `true`. Everyone sees it.
2. **Preview cookie** — the "test it as a customer would meet it" path, below.

**Being an administrator is not one of them.** Hidden means hidden, for
everyone: an account that sees a different site from the one it is launching
cannot tell whether the launch worked, and the buying flow has to be walked as a
logged-out visitor meets it. An admin who wants to look at the hidden store
takes the preview cookie like anybody else. This matches the purchase gate,
which no role bypasses either.

wp-admin is untouched — products, orders and pages are all still there, so the
store can be built and priced while the shopfront stays dark. What an admin
loses is the *front end*: while the store is hidden and the browser has no
preview cookie, store URLs redirect home for them too. That includes the
**Preview** button on a gated page in the editor, which is a front-end request
like any other. Turn preview on first and it behaves normally.

### Preview, logged out

Preview does not depend on being logged in — that is the point of it. Visiting a
URL carrying the secret token sets a cookie, and that browser sees the store —
signed out, in incognito, on a phone:

```
https://qhta.com.au/?qhta-store-preview=THE_TOKEN   turn on  (30 days)
https://qhta.com.au/?qhta-store-preview=off         turn off
```

It takes effect on that same request, so the link you click lands where you
expect rather than bouncing home.

The cookie holds `wp_hash( token )`, never the token, and is compared with
`hash_equals()`, so it cannot be guessed or forged, and a leaked cookie does not
hand over the secret that mints new ones. It is derived from the site's salts —
rotating those signs everyone out of preview.

### The token

Define it in **`wp-config.php`**, above the "stop editing" line:

```php
define( 'QHTA_STORE_PREVIEW_TOKEN', 'some-long-random-string' );
```

**Not in the plugin** — this repo is in version control, and a token committed
here would be a shared, published password. There is deliberately **no
default**: with the constant unset, preview is simply unavailable and nobody at
all sees the hidden shopfront until go-live. That is the safe way to be
misconfigured — a missing secret should shut a door, not open one — and it is
not a lockout, since wp-admin never redirects.

Generate one with `wp eval 'echo wp_generate_password( 32, false );'` or any
random string of that order. Treat it like a password: it sits in the URL, so it
lands in browser history, server access logs and any referrer sent to another
site. Rotate it after launch, or just drop the constant.

### Going live

```php
define( 'QHTA_STORE_LIVE', true );   // wp-config.php
```

A deploy action rather than an admin checkbox, deliberately: launching is not
something to do by mis-clicking, and the constant is visible in the code review
that ships it. **Confirm this is the wanted mechanism** — a checkbox is a small
change if not.

While it is `false` or undefined, the store is preview-only. An admin notice in
wp-admin says so, because wp-admin looks the same either way and that is how a
launch gets forgotten.

### Flagging the nav links

Appearance -> Menus -> expand a menu item -> tick **"Store link (hidden until
the store is live or in preview)"**. Flag Cart, Shop, My Content and anything
else that only makes sense once the store is live.

Editorial rather than automatic, because "which links are store links" is a
judgement, and matching URLs in code would break the moment a page moved.
Unflagged items are never touched. Hiding an item hides its submenu items too,
so a flagged parent does not leave its children dangling at top level.

A menu item flagged as the **cart button** (the second checkbox — see Shop
personalisation) is hidden with the store automatically, so it does not need
this box ticked as well.

### Caching — this is the part that bites

Full-page caching serves a stored copy without consulting the cookie, so **the
logged-out preview does not work under cache**, and after go-live a cached
"redirected away" response can still be served to real buyers.

Exclude from full-page caching: **Cart, Checkout, Shop, My Account (and its
endpoints), and the gated pages.** The last two are already excluded for the
gate's sake — cart, checkout and shop are the ones this feature adds.

There is no admin-bypass path to fall back on, so the cookie is the only
preview there is and these exclusions are what make it work at all — as well as
what keeps behaviour correct after launch.

### Known limits

- Products are blocked from being *viewed*, but a hidden store's products can
  still surface in **site search results and the WordPress sitemap** as titles.
  Clicking through redirects home. If that matters before launch, exclude
  products from search and sitemaps at the theme or SEO-plugin level — it is
  presentation and indexing, not commerce logic, so it does not live here.
- **Without WooCommerce the blocking fails open**, matching the gate: no fatal,
  and nothing is hidden. There is no store to hide in that state, and the admin
  notice already flags it.

## Shop personalisation

Two pieces of shopfront UI: a banner on the shop, and a cart button for the
header. Markup **and** styling both live here — see the carve-out under Scope.

| Class | What it is |
| --- | --- |
| `.qhta-member-banner` | The banner wrapper on the shop |
| `.qhta-cart-button` | The cart link (outer element) |
| `.qhta-cart-button--empty` | Added to the above when the cart is empty |
| `.qhta-cart-icon` | The icon glyph |
| `.qhta-cart-count` | The number |

### Styling

`assets/qhta-commerce.css`, enqueued on the front end while the store is
visible — nothing loads before go-live, since neither component renders then.

It is scoped to the classes above: no element selectors, nothing that can reach
the theme, and the brand tokens (navy `#1d3461`, teal `#3ecfb2`, accent
`#8a1538`) are declared on the components rather than on `:root`, so a plugin
never defines site-wide custom properties.

- The **banner** is a tinted band, not a solid panel — a teal wash at 8%, a
  hairline of the same teal, navy text, accent links. It is a nudge above the
  product grid, so it is weighted like a note rather than an announcement.
- The **cart button** is white, to carry on the navy header. `--qhta-cart-ink`
  on `.qhta-cart-button` is the one line to change if it ever has to sit on a
  light header — set it to `var(--qhta-navy)` and the icon follows, since the
  glyph takes `currentColor`. Hover goes teal. The count badge is accent, which
  is meant to stand out.
- **An empty cart shows no number** — just the icon. A badge reading "0" says
  nothing the empty icon doesn't.
- The **icon** is Feather's `shopping-cart` (MIT), inlined as a data URI and
  applied with `mask-image` so it picks up the text colour. No icon font, no
  extra request, no library to keep in step.

The stylesheet is versioned on `QHTA_COMMERCE_VERSION`. **Editing the CSS
without bumping the plugin version means the change reaches nobody who has
visited the site before** — the old file stays in browser and CDN caches.

### Member-pricing banner

Sits above the product grid on the Shop page and product category/tag archives,
telling non-members that logging in or joining gets them a better price:

> Members save on every package — **log in** for member pricing, or **join
> QHTA**.

Hooked to `woocommerce_before_shop_loop` at priority 5: above the result count
and the ordering dropdown, below the page title.

**Who does not see it:**

- **Members.** They already have the discount, so the nudge is noise at best and
  reads as a billing error at worst.
- **Everyone, if PMPro is inactive.** With no membership plugin there is no
  member pricing to advertise and the join link points at a page that no longer
  does anything — and members cannot be told from non-members, so the one group
  it must never reach would get it. It advertises nothing instead. (The brief's
  snippet rendered it in this case; acceptance criterion 8 says fail safe. This
  follows the criterion.)

The **log in** link reuses the plugin's own login URL, so it carries
`redirect_to` back to the Shop page rather than stranding people on /login/. On
a category archive that returns them to the main shop rather than the exact term
— never a wrong page, just a general one.

The **join** link resolves to PMPro's own Levels page when it can be found —
same reasoning as the Shop lookup, no slug hardcoded, survives a rename — and
falls back to the brief's `/membership-account/`. See Open items: in a stock
PMPro install that fallback is the *account* page, not where someone chooses
what to join.

Both the destination and the wording are filterable — see Extension points.

**Store preview:** no guard, and none missing. The hook only fires on the shop
and product archives, which are already redirected away while the store is
hidden.

### Cart button

A cart icon with a live item count, linking to the Cart page. **Two ways to
place it** — one for a nav menu, one for anywhere else.

#### In a nav menu (the checkbox)

Appearance -> Menus -> add a **Custom Link**, URL `#`, any label -> expand it ->
tick **"Cart button — replace this item with the cart icon and live count"**.

The item's URL and label are then ignored; the label only ever shows in the
admin screen, so name it whatever makes the menu readable. The whole item is
replaced by the button.

**Pasting `[qhta_cart_button]` into a menu item's label does not work** — it
shows up on the site as literal text. Menu labels run through `the_title`, which
has no `do_shortcode` on it. Turning shortcodes on for that filter would enable
them for every menu item on the site and make any label containing square
brackets a hazard, which is a much broader change than this needs, so the
checkbox is the supported route instead.

Replacing the *whole item* is the other half of it: by the time a label is
filtered the walker has already opened an `<a>`, and the cart button is an `<a>`
— nesting them is invalid HTML that browsers silently tear apart, leaving the
button outside the link it belonged to. The checkbox hands over the entire item,
anchor included, so there is exactly one.

A cart-button item is **removed from the menu entirely** — not left as an empty
`<li>` — while the store is hidden or WooCommerce is inactive. You do not need
to tick "Store link" as well, though ticking both is harmless.

#### Anywhere else (the shortcode)

```
[qhta_cart_button]
```

For an Astra header HTML widget, an Elementor HTML element, or any slot that
runs shortcodes. It self-guards on `qhta_commerce_store_visible()` and renders
nothing while the store is hidden — it has to, since the menu-item checkboxes
can't reach a header widget.

**Confirm where in the header it should sit**, whichever route you use.

#### How the live count works

The count updates on add-to-cart **without a page reload**, via WooCommerce's
cart fragments. Two things make that work, and both are load-bearing:

- `wc-cart-fragments` is enqueued site-wide while the store is visible. Woo only
  enqueues it on its own pages, and a header button is on all of them. It cannot
  be conditional on the button being present — header and menu content is
  composed long after scripts are enqueued.
- The fragment is keyed on the selector `a.qhta-cart-button`, which must match
  the button's outer element **exactly**. Change the tag or the class and the
  count silently stops updating while everything still renders.

**Empty cart:** the icon alone — the count is hidden by CSS via the
`.qhta-cart-button--empty` class. To hide the whole button when empty as well,
uncomment the rule at the bottom of `assets/qhta-commerce.css`.

Note *hidden*, not *not rendered*, in both cases. The fragment replaces the node
matching `a.qhta-cart-button`; once that anchor is gone from the DOM there is
nothing for the next add-to-cart to update, and the count stays invisible until
a reload. Hiding keeps the node. Do not "fix" this by making the plugin return
empty markup.

**Caching:** the cached HTML carries whatever count it was cached with;
fragments overwrite it per browser. That is another reason the cache exclusions
below are not optional.

**Accessibility:** the link carries an `aria-label` reading "View cart, N
items"; the icon and the number are `aria-hidden` so a screen reader gets the
sentence rather than the sentence and then a bare digit.

## Checkout tweaks

Four adjustments to the **WooCommerce store checkout and account**. (Not the
PMPro membership checkout — that is `qhta-membership`'s; see the checkout split
under Scope.)

| # | Tweak | Applies to |
|---|-------|-----------|
| 1 | **Phone removed** — from the form and from what Stripe asks for | form: classic only; Stripe: either |
| 2 | **Order notes removed**, "Additional information" heading included | classic checkout only |
| 3 | **Password strength: medium**, down from strong | everywhere Woo's strength meter runs |
| 4 | **Account username help text** | classic checkout only |
| 5 | **"Company name" -> "School or Institution"**, and required | classic checkout only |
| 6 | **Email notice** at the top of the form | classic checkout only |

Tweak 4's wording:

> Choose a username — you'll use it (or your email) to log in and access your
> recordings anytime.

Tweak 6's wording:

> After payment, we'll email you a link to access your resources — and they'll
> always be available under **My Content** in your account.

The tab is named by `qhta_commerce_content_label()` rather than written out, so
renaming My Content renames it in the notice too.

### School or Institution

`billing_company` is relabelled **School or Institution** (placeholder "Your
school or institution") and made **required** — teachers buy in an institutional
context, so it is a wanted detail rather than the optional afterthought "Company
name" reads as. Required is enforced server-side: Woo validates against the same
filtered field array, so an order cannot complete without it.

**It reaches the invoice with no invoice-plugin change.** `qhta-woo-invoice`
renders the buyer block through `get_formatted_billing_address()`, which already
includes the company line — making the field required is what makes that line
reliable. A distinct labelled "School or Institution:" row on the invoice would
be a template override in that plugin, and is not done here.

Two things this does **not** cover, both deliberate:

- **My Account -> Addresses still says "Company name".** That form is built from
  `woocommerce_billing_fields`, not `woocommerce_checkout_fields`, so the relabel
  does not reach it. In scope was the checkout. If the address book should match,
  it is one more filter — say so and it goes in.
- **The `woocommerce_checkout_company_field` option is left alone.** Unlike the
  phone case above there is no second consumer needing to agree, so there is no
  reason to reach for an option nothing on a classic checkout reads. On a block
  checkout this tweak would instead be the editor's Company field set to
  Required.

### The phone field — two levers, one switch

Phone is `hidden` by default: gone from the checkout, and **no phone number
collected at all**, so orders and order emails carry none.

It takes two levers to get there, and having only one is what broke checkout in
1.5.1. Removing the field from the form left the **Stripe** gateway still asking
Stripe to collect a number, and buyers hit a dead end at the payment step:

> A phone number is required to confirm this Checkout Session. Provide a phone
> number using `updatePhoneNumber()` or pass `phoneNumber` to `confirm()`.

A Stripe.js error, not a WooCommerce one, and strictly worse than the asterisk it
replaced — a buyer who can neither pay nor see why. The gateway builds its
Checkout Session from an **option**, never from the filtered field array
(`class-wc-stripe-checkout-sessions-ajax-handler.php`):

```php
if ( 'required' === get_option( 'woocommerce_checkout_phone_field', 'required' ) ) {
	$request['phone_number_collection'] = [ 'enabled' => 'true' ];
}
```

No priority on `woocommerce_checkout_fields` can reach that. So
`qhta_commerce_checkout_phone_mode()` drives both — the field array, and that
option via `pre_option_woocommerce_checkout_phone_field`.

| Mode | Checkout form | Stripe |
|------|---------------|--------|
| `hidden` (default) | field removed | not asked for a number |
| `optional` | field kept, required flag dropped | not asked for a number |
| `required` | untouched, Woo's own behaviour | collects as before |

Anything unrecognised is treated as `required`, which here means "leave
everything alone" rather than "assert required". Change it without a deploy:

```php
add_filter( 'qhta_commerce_checkout_phone_mode', function () {
	return 'optional';
} );
```

**Why forcing that option is acceptable here**, when this plugin otherwise
refuses to (see the block-checkout note below): in WooCommerce core
`woocommerce_checkout_phone_field` is read **only by the Blocks checkout**, and
its only UI is the Checkout *block* editor. On this site's classic checkout,
nothing in core reads it and nothing has ever written it — it was sitting at its
`required` default with no screen anywhere to disagree. It is effectively a
Stripe-facing setting with no owner, and now it has one. **That changes if the
checkout is ever rebuilt as blocks:** the editor then shows a control for it, and
this filter would silently override what that control says.

Two mechanical notes: it hooks `pre_option_` rather than `option_` because the
option doesn't exist in the database, and `get_option()` returns a missing option
through `default_option_` without ever applying `option_`. And `required` returns
the short-circuit value untouched, so a stored value still wins if one ever
appears.

This is also what was really behind the priority puzzle below — the field kept
coming back required because the *option* still said required.

**Order notes take two hooks, not one.** Unsetting `order_comments` empties the
section but leaves its **"Additional information" heading** behind — Woo's
`form-shipping.php` prints that `<h3>` inside a check of
`woocommerce_enable_order_notes_field`, and never asks whether a field survived.
So the plugin returns false from that filter too, which is what actually removes
the heading. Both stay: the filter governs what the template renders, the unset
governs what is in the field array.

### Classic vs block checkout

**This site runs the classic (shortcode) checkout** — confirmed on the live site,
8 August 2026. Tweaks 1, 2 and 4 go through `woocommerce_checkout_fields`, which
is the classic checkout's field array, so they are the working code path here.

It matters because that filter **does nothing on the block checkout**, which
builds its fields from settings and block attributes instead — and a fresh 2026
WooCommerce install defaults to block. **If the checkout is ever rebuilt as
blocks, three of these four silently stop applying.** Check with Pages ->
Checkout -> Edit: a **"Checkout" block** = block-based; a
`[woocommerce_checkout]` shortcode = classic.

- **Classic** (today) -> nothing to do. The tweaks are live on activation.
- **Block** (if it ever moves) -> they become admin steps:

  | Tweak | Block equivalent |
  |-------|-----------------|
  | Phone | WooCommerce -> Settings -> checkout settings -> **Phone field** (Optional / Hidden). Stripe's veto above applies here too — its setting comes first. |
  | Order notes | Pages -> Checkout -> Edit -> select the order-note block -> turn the notes field off |
  | Username help text | No supported equivalent. Block checkout field descriptions are limited; it needs block bindings or a small custom block, or the description is accepted as absent. |

  Tweak 3 still applies — it is not part of the field array.

The plugin does **not** force those settings from code. It could, and it
deliberately doesn't: the settings screen would then say one thing while the
checkout did another, which is worse than a documented admin step.

### Password strength

`woocommerce_min_password_strength` returns **2**. Woo's scale, straight from
zxcvbn: 0 any, 1 weak, 2 medium, 3 strong (Woo's default), 4 very strong.

Medium still rejects the passwords that actually get accounts taken over —
`password`, `123456`, the buyer's own email — while removing a wall that stops
people mid-purchase. The meter still shows and still says "medium"; the filter
changes what is **accepted**, not what is displayed.

It feeds Woo's password-strength-meter script, so it covers My Account -> Account
details, the set-password link after checkout, lost-password reset, and the
classic checkout's account password field. The block checkout renders no password
field by default (buyers get a set-password link by email) — nothing to relax
there, and the screen that link leads to is covered.

To use a different number, filter Woo's hook at a later priority; there is no
QHTA wrapper to go through.

```php
add_filter( 'woocommerce_min_password_strength', function () {
	return 3; // back to strong
}, 20 );
```

### The email notice

A line at the **top of the checkout form** (`woocommerce_before_checkout_form`)
telling the buyer that access arrives by email and lives in their account. It
sets the expectation before they pay, which is the cheapest way to stop the
"where is my product?" email a digital purchase otherwise invites.

Top of form was the call over the alternative — above the Place Order button
(`woocommerce_review_order_before_submit`). The trade is real: above the button
it is read at the moment of paying and cannot be scrolled past; at the top it is
read first and can be. Swapping the hook is the whole change.

Wording is filterable via `qhta_commerce_email_notice_text` and passed through
`wp_kses_post()` on output, so a filter can return links and emphasis but not
script. Returning an empty string prints nothing.

The plugin ships `<p class="qhta-email-notice">` and no CSS — see Styling below.

### The username field's dependency

The **Account username** field — and so tweak 4 — exists only because
WooCommerce's **"Generate account login"** setting is **off**, i.e. buyers pick
their own username. Turn that on (email-only login) and the field disappears,
taking the help text with it. Nothing breaks: the tweak is guarded by `isset()`
and simply becomes a no-op. The same applies to a buyer who is already logged in
— no account fields, nothing to describe.

## Thank-you page — "Access your resources"

After paying, the buyer gets a section linking straight to the gated page(s) for
**what that order bought**, above the order summary.

It is the **inverse of the My Content tab**: that answers "everything this
customer owns", this answers "what this one order just bought". Both walk the
same `_qhta_gate_product_id` mapping, so gating a new page puts it on the
thank-you page of every order containing its product, with no code change.

**Every link is checked against `qhta_commerce_is_entitled()`** — the same
question the gate asks — so a link can never be offered that the gate would then
refuse. A thank-you page handing someone a link to a redirect is worse than one
that stays quiet. Three consequences follow:

- **An order that has not reached a paid status shows nothing.** With a card
  payment the order is already *processing* by the time this renders, so links
  appear. With a slower method they would not — correctly, since the gate would
  have turned the buyer away — and the pages turn up under My Content once the
  payment lands.
- **Guest orders show nothing**, because entitlement is checked against the
  logged-in user and a guest cannot pass the gate whatever this printed. Buyers
  who create an account at checkout, the normal path here, are logged in by the
  time they arrive.
- **An order with no gated products renders nothing at all** — no empty heading.

Variations need no special handling: an order records the parent product, which
is what pages are gated against.

**Position:** priority 5 on `woocommerce_thankyou`, which puts it *above* the
order table — WooCommerce hooks that table onto the same action at 10, and what a
buyer wants first is the way in, not a summary of what they paid. Raise the
number past 10 to move it below.

**Purchase Notes are left alone**, so a product carrying its own link still shows
it. Worth a look on a real order if both end up saying the same thing.

Heading text is filterable via `qhta_commerce_access_resources_heading`.

### Styling

**These two components ship structure and classes only — no CSS.** Appearance
belongs in `qhta-theme-extras`, using the brand tokens:

| Class | What it is |
|-------|-----------|
| `.qhta-access-resources` | the thank-you section, with `__title`, `__list`, `__item`, `__link` |
| `.qhta-email-notice` | the checkout notice — a subtle info line |

The resource links also carry WooCommerce's own `button` class, so they pick up
whatever the theme already gives Woo buttons. Until theme-extras ships the rest,
that is all the styling there is: the notice renders as a plain paragraph.

**This is a departure from the carve-out** in Scope above, which keeps the CSS
for plugin-created components (the member banner, the cart button) here in
`assets/qhta-commerce.css` — one repo, one deploy. The spec for these two
assigned their styling to `qhta-theme-extras` instead, and that is what shipped.
Worth settling one way or the other rather than leaving the codebase with two
answers: moving them into `assets/qhta-commerce.css` is a small change if
consistency is preferred.

## Structure

```
qhta-commerce/
  qhta-commerce.php           Bootstrap: header, per-page field, gate. Keep thin.
  assets/qhta-commerce.css    Banner and cart button. Shipped.
  scripts/build-zip.sh        Packages the deploy zip. Not shipped.
  README.md
  CHANGELOG.md
```

One PHP file was right for v1 and is getting long. If more Woo behaviour lands,
split into `includes/` required from the bootstrap rather than letting the
bootstrap sprawl further.

No dependencies, no build step, no Composer. Plain PHP and plain CSS, drop-in
plugin.

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

**Every gated page, `/login/`, My Account, Cart, Checkout and Shop must be
excluded from full-page caching.** A page cache that serves a stored copy never
reaches PHP, so the gate never runs and a cached copy of paid content can be
served to a non-buyer.

Cart, Checkout and Shop are on that list for store preview mode (see above): a
cache that ignores the preview cookie breaks the logged-out preview, and can
serve a stale "store hidden" redirect to real buyers after go-live.

`/login/` matters for a second reason: it now carries a per-visitor `redirect_to`,
so a cached copy would send the next person to whichever page the last one was
trying to reach.

Excluding My Account has to cover its **endpoints**, `/my-account/my-content/`
included — the tab is a different list for every customer, and a cached copy
would show one customer the titles of another's purchases.

The plugin sends no-cache headers on gated pages as defence in depth, but it
cannot enforce this — headers only help caches that actually ask PHP. Configure
the exclusion at the host: hPanel -> Websites -> qhta.com.au -> Advanced ->
Cache Manager, plus any CDN or caching plugin in front of it.

### WooCommerce must stay active

With WooCommerce deactivated the plugin fails **open**: no fatal error, and the
gate does nothing, so gated pages become public. This is deliberate — a broken
gate should not take the whole site down — but it means deactivating Woo
silently unlocks paid content. An admin notice flags it in wp-admin.

My Account is WooCommerce's own page, so the My Content tab goes with it; the
helper behind it returns an empty list rather than calling into functions that
are no longer there.

The editor field degrades with it: no WooCommerce means no product search, so
the panel falls back to a plain **Product ID** number input. Same meta, same
stored value — you just have to know the ID.

## Extension points

Twelve filters, all optional:

```php
// Where a logged-in non-buyer is sent — defaults to /login/, no redirect_to.
// Point it at a real sales page once there is one.
add_filter( 'qhta_commerce_sales_url', function () {
	return home_url( '/some-sales-page/' );
} );

// Where a logged-out visitor is sent — defaults to /login/ with the gated page
// as redirect_to. Move it without editing the plugin:
add_filter( 'qhta_commerce_login_url', function ( $url, $return_to ) {
	return add_query_arg( 'redirect_to', rawurlencode( $return_to ), home_url( '/sign-in/' ) );
}, 10, 2 );

// Or return the line below to stop offering a login and treat "logged out"
// exactly like "hasn't bought it".
add_filter( 'qhta_commerce_login_url', function () {
	return qhta_commerce_sales_url();
} );

// Where the My Content empty state's "Browse products" button goes — defaults
// to the WooCommerce Shop page.
add_filter( 'qhta_commerce_browse_url', function () {
	return home_url( '/recordings/' );
} );

// Widen who can see the hidden store — e.g. let every logged-in editor in
// during a staged launch.
add_filter( 'qhta_commerce_store_visible', function ( $visible ) {
	return $visible || current_user_can( 'edit_posts' );
} );

// Where a blocked store URL goes while the store is hidden — defaults to home.
// Point it at a "coming soon" page if there is one (don't gate that page, or
// the self-redirect guard will fail it open).
add_filter( 'qhta_commerce_store_hidden_redirect', function () {
	return home_url( '/coming-soon/' );
} );

// Widen entitlement — e.g. let editors preview a gated page they haven't bought.
// A fourth argument carries the user ID being checked, for filters that need it;
// three-argument callbacks like this one are unaffected.
add_filter( 'qhta_commerce_is_entitled', function ( $entitled, $product_id, $page_id ) {
	return $entitled || current_user_can( 'edit_post', $page_id );
}, 10, 3 );

// Where the member banner's "join QHTA" link goes — defaults to PMPro's Levels
// page, falling back to /membership-account/.
add_filter( 'qhta_commerce_join_url', function () {
	return home_url( '/join/' );
} );

// The member banner's wording, links included. Passed through wp_kses_post() on
// output, so links and emphasis survive and script does not.
add_filter( 'qhta_commerce_member_banner_text', function () {
	return 'Members pay less on every package. <a href="/login/">Log in</a>.';
} );

// The help text under the account username field on the classic checkout. Also
// wp_kses_post()'d on output, by WooCommerce.
add_filter( 'qhta_commerce_account_username_description', function () {
	return 'Pick a username — it is how you get back in.';
} );

// What the checkout does with the phone field: 'hidden' (default), 'optional',
// or 'required' to leave Woo and Stripe entirely alone. Drives both the checkout
// form and whether Stripe asks for a number — see the checkout section.
add_filter( 'qhta_commerce_checkout_phone_mode', function () {
	return 'optional';
} );

// The checkout's "access arrives by email" notice. wp_kses_post()'d on output;
// return an empty string to print nothing.
add_filter( 'qhta_commerce_email_notice_text', function () {
	return 'Your resources are emailed to you and saved to your account.';
} );

// The thank-you section's heading.
add_filter( 'qhta_commerce_access_resources_heading', function () {
	return 'Start reading';
} );
```

By default **nobody** bypasses the gate, administrators included. That keeps the
behaviour honest when testing, at the cost of having to log out (or use the
filter above) to preview a gated page you don't own.

`qhta_commerce_is_entitled` is the single definition of "entitled" — the gate
and the My Content tab both go through it, so widening it widens both. A filter
that lets editors preview gated pages also lists those pages in an editor's own
My Content tab. That is intended: the tab should show what the gate would let
you through, whatever the reason.

## Notes

- The redirect is a **302**, not a 301. Entitlement changes; a permanent
  redirect would be cached by the browser and keep bouncing a visitor who has
  since bought.
- A page that is both gated and the redirect destination would redirect to
  itself forever, so that misconfiguration fails open instead. That covers
  gating the login page as well as gating the sales page.
- **Logged out is treated as "unknown", not "denied".** It is the only case that
  carries `redirect_to`: a buyer who is merely signed out gets back to the page,
  where a logged-in non-buyer has nothing to return to.
- **Both destinations reveal that *something* exists at the gated URL.** A
  `/not-found/` redirect — the default up to 1.0.2 — hid that, at the cost of
  telling a real customer their paid content doesn't exist. Sending people
  somewhere that says something was judged worth more. Both filters above take
  it back if the quiet version is ever wanted.
- **`/login/` must honour `redirect_to`.** It is PMPro's Log In page, rebranded,
  and accepts the parameter natively — the same mechanism PMPro's checkout "log
  in here" link uses. If that page is ever rebuilt, or `qhta-membership` later
  adds a login redirect of its own, that has to keep holding or buyers land on a
  fixed page instead of the gated one they asked for.
- The plugin also adds a hidden `redirect` field to **WooCommerce's** login form
  (My Account), belt and braces for the other door — Woo reads
  `$_POST['redirect']` but its stock login template doesn't render one. Removing
  that hook doesn't break login; it just drops people on the My Account dashboard
  instead of the page they asked for.
- Registration is not wired up, only login. A guest-checkout buyer who later
  registers with the same email is entitled, but lands on My Account rather than
  back on the gated page.
- A logged-in non-buyer lands on `/login/` while already signed in, so whatever
  that page shows a logged-in visitor is what they see. Worth a look once, and
  the reason `qhta_commerce_sales_url` stays a separate filter — point it at a
  real sales page as soon as there is one.
- Deactivating the plugin removes all gate behaviour and the My Content tab, and
  flushes the tab's rewrite rule back out. Stored meta stays behind harmlessly
  and takes effect again on reactivation.
- The My Content tab lists **published** pages only. A gated page left in draft
  or set to private will not appear, even for someone who has bought its
  product.
- Page **titles** are visible in the tab only to customers already entitled to
  those pages, so the tab reveals nothing a non-buyer could not already see by
  requesting the URL.
- The meta key is underscore-prefixed (`_qhta_gate_product_id`) so it stays out
  of the generic Custom Fields UI. The sidebar panel is the intended way in.
- **Member pricing itself is not this plugin's**, and the banner does not make it
  happen. The discount is the PMPro WooCommerce Integration add-on's job,
  configured per product. The banner only says it exists — so if that add-on is
  not set up on a product, the banner is advertising a discount that will not
  appear at checkout. Worth checking together before launch.
- `wc-cart-fragments` runs site-wide while the store is visible, not just on Woo
  pages, because the cart button is in the header on every page. It is Woo's own
  script and small, but it does mean one more request on pages that have nothing
  to do with the store.
- The checkout-field filter runs at **priority 9999**, not the 20 it shipped with
  in 1.5.0. At 20 the phone field came back marked required while the username
  description — set in the same pass — took fine. The real cause turned out to be
  the phone *option*, not a rival filter (see above), so this is belt to that
  braces. It stays because it costs nothing and does guard a real edge: anything
  re-asserting a key on a field this filter has unset would recreate it as a bare
  `array( 'required' => true )`, which renders as an unlabelled text input rather
  than as nothing.
- **With WooCommerce inactive the checkout tweaks simply never run.** Both hooks
  are Woo's own filters, so nothing fires and no Woo function is called; unlike
  the gate, there is no failing-open to think about — there is no checkout
  either.
- WooCommerce renders field descriptions inside a span carrying
  `aria-hidden="true"`, so a **screen reader does not read the username help text
  out**. That is core Woo's markup, not something set here. It is supplementary —
  the field is still labelled "Account username" — but it does mean help text
  cannot be the only place something important is said.
- The password-strength number is enforced by Woo's strength-meter script in the
  browser, which is where Woo enforces it; Woo does not check strength again
  server-side. Relaxing it to medium does not weaken a server-side check, because
  there wasn't one.

## Open items

- Non-buyers currently go to `/login/` because there is no sales page. When one
  exists, point `qhta_commerce_sales_url` at it — filter, or edit the default in
  `qhta_commerce_sales_url()`.
- Confirm each page/product pairing when gating. The picker only offers real
  products, so a typo can no longer gate a page against everyone — but it will
  happily let you pick the wrong product, and that looks identical until a buyer
  reports being locked out.
- **Confirm the tab label**, currently "My Content", and the endpoint slug
  `my-content` with it. The feature is general rather than recordings-specific,
  which is why it is not named after any one product. Changing the slug needs a
  deactivate/reactivate to flush permalinks.
- **Confirm where "Browse products" should go.** It currently finds the
  WooCommerce Shop page. `/recordings/`, the destination in the brief, does not
  exist on the site — point `qhta_commerce_browse_url` at it once it does.
- **Set `QHTA_STORE_PREVIEW_TOKEN` in `wp-config.php`.** Now effectively
  required rather than optional: with no admin bypass, the cookie is the only
  way to see the hidden store, so without the token the shopfront cannot be
  looked at at all before go-live. Nothing breaks without it — wp-admin is
  unaffected — but there is nothing to test with.
- **Confirm `QHTA_STORE_LIVE` is the wanted go-live mechanism** (a constant in
  `wp-config.php`) rather than an admin checkbox.
- **Confirm where blocked store URLs should land** — currently home. A "coming
  soon" page goes in via `qhta_commerce_store_hidden_redirect`.
- **Flag the store nav links** in Appearance -> Menus before relying on the
  hidden state. Nothing is flagged by default, so until that is done the links
  stay visible even though the pages behind them redirect.
- Add **Cart, Checkout and Shop** to the Hostinger cache exclusions.
- **Confirm the "join QHTA" destination.** It resolves to PMPro's Levels page
  when that is configured, falling back to the brief's `/membership-account/` —
  which in a stock PMPro install is the *account* page a signed-in member lands
  on, not where a prospect chooses a level. Set the Levels page in PMPro, or
  point `qhta_commerce_join_url` wherever it should go.
- **Confirm where in the header the cart button sits**, and place it — either a
  menu item with the "Cart button" box ticked, or `[qhta_cart_button]` in a
  header widget. Nothing places it automatically.
- **Check the banner and button against the live header** once placed. The
  styling is a first pass: the banner commits to brand navy and teal, and the
  button deliberately inherits the header's own colour rather than asserting
  one. Say if either wants to look different.
- **Assign a real product category.** Both products currently show
  "Uncategorised" on the shop. Products -> Categories, e.g. "Study Packages".
  Manual admin task, no code involved.
- **Confirm the phone fix in Stripe's own logs**, once 1.5.2 is up. Stripe
  Dashboard -> Developers -> Logs -> the next `POST /v1/checkout/sessions`
  (creation, no ID on the path — not the update call that follows it): its
  request body should now have **no `phone_number_collection`** at all. That is
  the only real proof; the checkout looking right proves half of it. Test and
  live are separate log streams.
- **Watch for the checkout being rebuilt as blocks.** It is classic today and
  most of the checkout tweaks depend on that — see the block table above for what
  would then need doing by hand. It would also change the phone switch from
  "adopting an ownerless setting" to "overriding a control the block editor
  shows", which is a different bargain and worth revisiting then.
- **Style `.qhta-access-resources` and `.qhta-email-notice` in
  `qhta-theme-extras`.** Until that ships they render nearly bare — the resource
  links get whatever the theme gives Woo's `button` class, the notice is a plain
  paragraph. **Or settle the inconsistency the other way:** the member banner and
  cart button keep their CSS inside this plugin (Scope, above), and these two do
  not. One answer would be better than two.
- **Check the thank-you links against the product Purchase Note on a real
  order.** Purchase Notes are deliberately untouched, so a product carrying its
  own link will show both. Fine if they say different things, redundant if not.
- **Decide whether My Account -> Addresses should also say "School or
  Institution".** It still says "Company name" — that form is built from a
  different filter, and the checkout was the scope. One more filter if wanted.
