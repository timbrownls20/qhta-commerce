# Healthcheck note — keep qhta-commerce's canaries current

**Standing rule.** When this plugin is created or changed in a way that adds or alters an external
dependency (a WooCommerce/PMPro function, a hook, a DB table/column, an order/post meta key, a rewrite endpoint, a redirect target, or a constant), update its **qhta-healthcheck** canaries in the *same* change.

The canaries live **in this repository**, in `includes/healthcheck.php`, registered on qhta-healthcheck's
`qhta_healthcheck_checks` filter. That is the whole point: changing the dependency and changing the
canary for it are the same diff, in the same review, deployed together. There is no central copy to
keep in step — `qhta-healthcheck/includes/checks.php` deliberately holds none.

A new dependency with no canary is the silent-failure risk qhta-healthcheck exists to catch.

## How it behaves

- Nothing runs unless **qhta-healthcheck** is installed. `add_filter()` on a hook nobody applies
  costs nothing, so this file is inert without it.
- The assertion helpers (`qhta_healthcheck_assert_*`) belong to qhta-healthcheck and are only ever
  called from inside its runner, which wraps every check in a try/catch. An older qhta-healthcheck
  missing one shows up as a failed check, never a fatal.
- Until this plugin is deployed at **1.7.0** or later, qhta-healthcheck reports it amber —
  *"no canaries defined"*. That is expected during the rollout, not a bug: each plugin joins on its
  own next deploy, no coordinated release needed.

## This plugin's canaries (14)

| Canary | Sev | Watches |
|---|---|---|
| WooCommerce entitlement API | critical | `wc_customer_bought_product`, `wc_get_product`, `wc_get_order`, `wc_get_page_id`, `wc_get_page_permalink` |
| Gate field `_qhta_gate_product_id` | warning | post meta still carrying the gating product ID |
| My Account → `my-content` endpoint | critical | endpoint routed **and** rewrite rules flushed |
| `woocommerce_account_menu_items` | critical | the My Content tab is still injected |
| `woocommerce_account_{endpoint}_endpoint` | critical | the tab body still renders |
| Rewrite rules match deployed version | warning | `qhta_commerce_rewrites_version` vs `QHTA_COMMERCE_VERSION` |
| Login redirect target | critical | the page the gate sends people to is published |
| WooCommerce shop page | warning | `wc_get_page_id( 'shop' )` set and published |
| Store is reachable by someone | warning | `QHTA_STORE_LIVE` or `QHTA_STORE_PREVIEW_TOKEN` |
| Checkout phone-field override | warning | filter attached **and** the option row still absent |
| Thank-you page resources | warning | `woocommerce_thankyou` |
| Cart-count fragment | warning | `woocommerce_add_to_cart_fragments` |
| PMPro member-pricing banner | warning | `pmpro_hasMembershipLevel`, `pmpro_url` |
| Offline gateways limited to Events | warning | `woocommerce_available_payment_gateways` filter attached **and** each `qhta_commerce_offline_gateway_categories()` slug exists in `product_cat` |

Two of them ask this plugin's own resolvers rather than restating a constant: the login-page check
goes through `qhta_commerce_login_url()`, and the store check through `qhta_commerce_preview_token()`.
Override either destination and its canary moves with it.

The phone-field canary is the subtle one — the filter only fires *because the option row is absent*.
Saving WooCommerce's checkout settings creates the row, the `pre_option_` filter stops being
consulted, and the phone field silently returns. The canary checks both halves.

## More
- Full rule, rationale and the per-plugin canary list: `qhta-healthcheck/qhta-healthcheck-handover.md`.
- qhta-healthcheck covers internal correctness only; site-up liveness is the external "QHTA site guardian" HTTP task. Keep both.
