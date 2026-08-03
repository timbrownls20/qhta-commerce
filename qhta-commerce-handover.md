# qhta-commerce — Handover Notes for Claude Code

Notes to build a small WordPress plugin for **qhta.com.au**. This is a briefing, not a rigid spec — implement it idiomatically, but keep to the scope boundary and house conventions below. Where a value is marked **TBD**, leave a clearly-commented placeholder; don't invent it.

> **Status:** these are the notes the plugin was built from, kept as the statement of intent. What shipped has moved on in places — the product-ID input is now a searchable picker, and both non-entitled cases redirect to `/login/`. **CHANGELOG.md records what changed and why; README.md is the current behaviour.** Where this brief and those two disagree, they win.

---

## What this plugin is

A single-responsibility plugin that **gates WordPress pages behind a WooCommerce purchase**. If a visitor has bought the required product, they see the page; if not, they're redirected to a sales page. It's how paid content (e.g. conference recording pages) is locked without a membership plugin.

**Config lives on the page itself.** Each Page gets a **"Purchase gate → product ID" field** in the editor. The gate reads the *current page's own* product-ID meta and acts on it. There is **no central map, no slug list, and no code to edit when adding a gated page** — you create the page, type a product ID into the field, publish. A blank field = a normal public page.

This design was chosen over a central `slug => product ID` map specifically because: (a) adding a gated page needs **zero code** (editor-only, so non-developers can do it), and (b) it's **not coupled to slugs** — renaming a page or changing its slug can't silently break the gate, because the gate keys off the page's ID and its stored field, not a hardcoded string. The map alternative is noted at the end as the rejected option.

## Where it fits (scope boundary — important)

The QHTA site has a deliberate three-plugin separation. Respect it:

- **conference program plugin** — conference *domain logic* (session shortcodes, program data).
- **qhta-theme-extras** — *presentation* only (Astra hooks, mega menu, global CSS tokens, sitewide display tweaks).
- **qhta-commerce (this plugin)** — *WooCommerce-side logic*: purchase gates, and any future Woo behaviour tweaks.

A fourth plugin is **planned but not yet built** (deferred — we'll come back to it): **`qhta-membership`** — a home for PMPro *domain logic* currently living as loose Code Snippets (checkout username generation, field reorder/read-only, checkout relabels, billing-field tweaks). Not part of this build; noted so the separation is understood. When built, PMPro customisations move there — not into qhta-commerce or theme-extras.

**Scoping discipline (applies to all four plugins — a hard rule).** A real bug prompted this: a PMPro checkout snippet ran `document.getElementById('username')` in an unscoped `wp_footer` on *every* page and silently forced `readonly` on the WooCommerce **My Account login** field (which also has `id="username"`), locking customers out. Therefore:
- **Page-scope every hook.** No bare site-wide `wp_footer`/`init` output. Guard with `pmpro_is_checkout()`, `is_account_page()`, `is_page(...)`, etc.
- **No unscoped global DOM selectors.** Scope queries to the specific form container, never a bare shared `id`.
- These rules prevent cross-page collateral damage — the reason loose snippets are being consolidated into plugins in the first place.

Test for what belongs here: "is it WooCommerce behaviour that is **not** presentation and **not** conference-specific domain logic?" → qhta-commerce. Do **not** put CSS/theme concerns or conference logic in here, and do **not** put commerce logic in the other two.

## Explicitly out of scope

- **Member pricing / discounts** — handled entirely by the PMPro WooCommerce Integration add-on (per-product member price field). Do **not** implement any pricing logic here.
- **Account creation, checkout, emails** — native WooCommerce settings, not this plugin.
- **Presentation** of the gated pages — that's page content + theme, not this plugin.
- **A settings/options screen** — not needed. The per-page field *is* the configuration.

---

## Core requirement — the gate

**Two parts: a per-page field, and the gate that reads it.**

### 1. Per-page field
- Register a **"Purchase gate" field on the `page` post type** — a numeric "Gated by product ID" input, editable in the page editor.
- Simplest implementation: a classic `add_meta_box` in the `side` context — it renders as a panel in the Block Editor's document sidebar, so it works in Gutenberg without extra JS.
- Store as post meta key **`_qhta_gate_product_id`** (underscore-prefixed = hidden from the generic Custom Fields UI). Save with a nonce + capability check. An empty value deletes the meta (page becomes public).
- Include a short description in the field: "Leave blank for a public page. Enter the WooCommerce product ID that unlocks this page."

### 2. The gate (behaviour)
- On every front-end **page** load (`template_redirect`):
  - Read the current page's `_qhta_gate_product_id`.
  - **Blank / 0** → do nothing (public page).
  - **Set** → run the purchase check:
    - **Logged out** → redirect to the branded **`/login/`** page with `redirect_to` set to the gated page (so they land back here after logging in). Do **not** send them to a 404 or the sales page.
    - **Logged in, has not bought that product** → redirect to the **sales page**.
    - **Logged in, has bought it** → allow the page to render.
- Server-side, so it's a real gate, not client-side hiding.

**Purchase check:** WooCommerce core `wc_customer_bought_product( $email, $user_id, $product_id )`. It matches paid statuses (completed + processing) by default, which gives two properties for free:
- Virtual + downloadable products (often "processing") still unlock immediately.
- A **refund auto-revokes** access on the next page load — no stored flag, no manual step, nothing to keep in sync.

### Reference implementation (starting point, not gospel)

```php
<?php
/**
 * Plugin Name:       QHTA Commerce
 * Description:       WooCommerce-side custom logic for qhta.com.au — purchase-gated content pages driven by a per-page product-ID field. No presentation, no conference domain logic.
 * Version:           1.0.0
 * Author:            QHTA
 * License:           GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * Scope rule: WooCommerce-side behaviour only. Presentation lives in qhta-theme-extras;
 * conference domain logic lives in the conference program plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'QHTA_COMMERCE_VERSION', '1.0.0' );

const QHTA_GATE_META = '_qhta_gate_product_id';

/**
 * Where non-entitled visitors are sent. Filterable.
 */
function qhta_commerce_sales_url() {
    return apply_filters( 'qhta_commerce_sales_url', home_url( '/recordings/' ) );
}

/**
 * Login URL for logged-out visitors, returning them to $return_to after login.
 * The site's single branded login page is /login/ (PMPro's Log In page, rebranded).
 * PMPro's Log In page natively accepts redirect_to and returns the user there after
 * login — the same mechanism its checkout "log in here" link uses. Filterable.
 * NOTE: /login/ (and qhta-membership's login redirect) must honour redirect_to,
 * or the buyer gets bounced to a fixed page instead of back to the gated page.
 */
function qhta_commerce_login_url( $return_to ) {
    $default = add_query_arg( 'redirect_to', rawurlencode( $return_to ), home_url( '/login/' ) );
    return apply_filters( 'qhta_commerce_login_url', $default, $return_to );
}

/**
 * Per-page field: "Gated by product ID".
 */
add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'qhta_gate',
        'Purchase gate',
        function ( $post ) {
            $val = get_post_meta( $post->ID, QHTA_GATE_META, true );
            wp_nonce_field( 'qhta_gate_save', 'qhta_gate_nonce' );
            echo '<label for="qhta_gate_product_id"><strong>Gated by product ID</strong></label>';
            echo '<input type="number" min="0" id="qhta_gate_product_id" name="qhta_gate_product_id" value="' . esc_attr( $val ) . '" style="width:100%;margin-top:4px">';
            echo '<p class="description">Leave blank for a public page. Enter the WooCommerce product ID that unlocks this page.</p>';
        },
        'page',
        'side'
    );
} );

add_action( 'save_post_page', function ( $post_id ) {
    if ( ! isset( $_POST['qhta_gate_nonce'] ) || ! wp_verify_nonce( $_POST['qhta_gate_nonce'], 'qhta_gate_save' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_page', $post_id ) ) {
        return;
    }
    $val = isset( $_POST['qhta_gate_product_id'] ) ? absint( $_POST['qhta_gate_product_id'] ) : 0;
    if ( $val > 0 ) {
        update_post_meta( $post_id, QHTA_GATE_META, $val );
    } else {
        delete_post_meta( $post_id, QHTA_GATE_META );
    }
} );

/**
 * The gate: reads the current page's own field.
 */
add_action( 'template_redirect', function () {
    if ( ! is_page() || ! function_exists( 'wc_customer_bought_product' ) ) {
        return;
    }
    $product_id = (int) get_post_meta( get_queried_object_id(), QHTA_GATE_META, true );
    if ( $product_id <= 0 ) {
        return; // not a gated page
    }

    // Logged out → send to login, returning here afterwards (not a 404, not sales).
    if ( ! is_user_logged_in() ) {
        wp_safe_redirect( qhta_commerce_login_url( get_permalink() ) );
        exit;
    }

    // Logged in but hasn't bought this product → sales page.
    $bought = wc_customer_bought_product(
        wp_get_current_user()->user_email,
        get_current_user_id(),
        $product_id
    );
    if ( ! $bought ) {
        wp_safe_redirect( qhta_commerce_sales_url() );
        exit;
    }
} );
```

*(Optional polish, not required: register the meta via `register_post_meta` and add a small Block Editor sidebar panel for a more native feel. The `add_meta_box` above already works in Gutenberg, so only do this if there's appetite.)*

---

## House conventions (match the other QHTA plugins)

- Standard plugin header with Name / Description / Version / Author / License / Requires (as above).
- `if ( ! defined( 'ABSPATH' ) ) exit;` guard.
- Licence **GPL-2.0-or-later**.
- Version constant (`QHTA_COMMERCE_VERSION`).
- Function prefix **`qhta_commerce_`** / meta prefixed `_qhta_`. No classes needed for v1; keep it flat and readable.
- Maintain **CHANGELOG.md** and **README.md** in the same style as the conference plugin and qhta-theme-extras — this codebase is kept handoverable.
- Guard for WooCommerce being active before calling Woo functions.
- Nonce + capability check on save. No external dependencies, no build step, no Composer. Plain PHP, drop-in plugin.

## Suggested file structure

```
qhta-commerce/
├── qhta-commerce.php     # main plugin file (header + field + gate)
├── README.md             # what it does, how to gate a page, house-style
└── CHANGELOG.md          # start at 1.0.0
```

Single-file is fine for v1. If it grows (more Woo tweaks), split into an `includes/` dir then.

## Deployment / environment notes (flag in README)

- Install by zipping the `qhta-commerce` folder → Plugins → Add New → Upload Plugin → Activate. (WordPress on Hostinger.)
- **Gating a page after install:** edit the Page → set the "Gated by product ID" field to the unlocking product's ID → update. No code.
- **Caching:** every gated page **and** My Account must be excluded from full-page caching, or a cached copy can leak to a non-buyer. Host/cache config, not something the plugin can guarantee — note it in the README as a required deployment step.

## Acceptance criteria (done =)

1. Activating adds a "Purchase gate" field to the Page editor; deactivating removes all gate behaviour (stored meta can remain harmlessly).
2. A page with the field **blank** behaves as a normal public page.
3. A page with the field **set to a product ID**:
   - redirects a **logged-out** visitor to the **login page**, returning them to the gated page after login (not a 404, not the sales page);
   - redirects a **logged-in non-buyer** to the sales URL;
   - renders normally for a **logged-in buyer** of that product.
4. **Renaming the page or changing its slug** does not break the gate (it keys off page ID + meta, not slug).
5. **Refunding** a buyer's order blocks them on the next load, with no other change.
6. Gating a second page is editor-only — set its field, no code change.
7. With WooCommerce deactivated, the plugin fails safe (no fatal error; gate does nothing).
8. Save path is protected by nonce + `current_user_can`.
9. README documents how to gate a page and the caching-exclusion requirement; CHANGELOG starts at 1.0.0.

## Open items for Tim (post-install, not needed to build)

The plugin is **content-agnostic** — it needs no product IDs to be built. After install, Tim sets them in the editor:
- On each gated page, enter the **product ID** that unlocks it. (Confirm the pairing at that point — e.g. product 3952 is titled "EA Student Study Package 2026", so check it unlocks the page you intend.)
- Confirm the **sales/redirect URL** (default `/recordings/`) via the `qhta_commerce_sales_url` filter or by editing the default.

## Rejected alternative (for context)

A central `slug => product ID` map in a filterable function. Rejected because it needs a code edit per gated page and breaks silently if a slug changes. The per-page field supersedes it. (If a map is ever wanted for bulk/programmatic gating, it could be added *alongside* the field as a fallback — but it's not part of v1.)
