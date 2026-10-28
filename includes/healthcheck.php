<?php
/**
 * Healthcheck canaries for qhta-commerce.
 *
 * Registered on qhta-healthcheck's `qhta_healthcheck_checks` filter, and living
 * here rather than in that plugin for one reason: everything below is a
 * statement about what THIS plugin depends on, and it should be impossible to
 * change the gate without the canary for the gate being in the same diff.
 *
 * If qhta-healthcheck is not installed, nothing here runs — the filter is never
 * applied and add_filter() on an unused hook costs nothing. The assertion
 * helpers are qhta-healthcheck's, and are only ever called from inside its
 * runner, which wraps every check in a try/catch; an older healthcheck missing
 * one of them surfaces as a visible failed check rather than a fatal.
 *
 * Severity: 'critical' fails red, 'warning' (the default) fails amber. The test
 * for what is critical here is not "is this feature important" but "does a
 * customer who paid us lose something".
 *
 * @package QHTA_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register this plugin's canaries.
 *
 * @param array $checks Slug => list of check specs.
 * @return array
 */
function qhta_commerce_healthcheck_checks( $checks ) {
	$checks['qhta-commerce'] = array_merge(
		isset( $checks['qhta-commerce'] ) ? (array) $checks['qhta-commerce'] : array(),
		array(
			array(
				'id'       => 'woo-entitlement-api',
				'label'    => __( 'WooCommerce entitlement API', 'qhta-commerce' ),
				'why'      => __( 'wc_customer_bought_product() is the whole gate. It is called behind a function_exists() guard, so if it disappears every gated page silently redirects paying customers to /login/ instead of letting them in.', 'qhta-commerce' ),
				'severity' => 'critical',
				'test'     => function () {
					return qhta_healthcheck_assert_functions(
						array( 'wc_customer_bought_product', 'wc_get_product', 'wc_get_order', 'wc_get_page_id', 'wc_get_page_permalink' )
					);
				},
			),
			array(
				'id'       => 'gate-meta-in-use',
				'label'    => __( 'Gate field _qhta_gate_product_id', 'qhta-commerce' ),
				'why'      => __( 'The per-page product ID that decides what unlocks a page. No rows carrying it means either nothing is gated, or the meta key has drifted and every gated page is now open.', 'qhta-commerce' ),
				'severity' => 'warning',
				'test'     => function () {
					return qhta_healthcheck_assert_meta_in_use( QHTA_GATE_META );
				},
			),
			array(
				'id'       => 'my-content-endpoint',
				'label'    => __( 'My Account → my-content endpoint', 'qhta-commerce' ),
				'why'      => __( 'The tab is added to the account menu by one hook and routed by a rewrite rule added at another. A deploy that adds the endpoint without flushing permalinks leaves a visible tab that 404s.', 'qhta-commerce' ),
				'severity' => 'critical',
				'test'     => function () {
					return qhta_healthcheck_assert_account_endpoint( QHTA_CONTENT_ENDPOINT );
				},
			),
			array(
				'id'       => 'account-menu-hook',
				'label'    => __( 'woocommerce_account_menu_items', 'qhta-commerce' ),
				'why'      => __( 'Where the My Content tab is injected. WooCommerce renaming or dropping this filter removes the tab with no error anywhere.', 'qhta-commerce' ),
				'severity' => 'critical',
				'test'     => function () {
					return qhta_healthcheck_assert_hooked( 'woocommerce_account_menu_items', 'qhta_commerce_account_menu_items' );
				},
			),
			array(
				'id'       => 'endpoint-content-hook',
				'label'    => __( 'woocommerce_account_{endpoint}_endpoint', 'qhta-commerce' ),
				'why'      => __( 'Renders the tab body. The hook name is built from the endpoint slug, so a slug change breaks this without breaking the menu item — the tab opens empty.', 'qhta-commerce' ),
				'severity' => 'critical',
				'test'     => function () {
					return qhta_healthcheck_assert_hooked(
						'woocommerce_account_' . QHTA_CONTENT_ENDPOINT . '_endpoint',
						'qhta_commerce_content_endpoint_content'
					);
				},
			),
			array(
				'id'       => 'rewrites-current',
				'label'    => __( 'Rewrite rules match the deployed version', 'qhta-commerce' ),
				'why'      => __( 'The rewrites option records the version whose rules were last flushed. Behind the installed version means the endpoint was changed by an update that has not flushed yet.', 'qhta-commerce' ),
				'severity' => 'warning',
				'test'     => function () {
					$stored = (string) get_option( QHTA_REWRITES_OPTION, '' );

					if ( $stored !== QHTA_COMMERCE_VERSION ) {
						return qhta_healthcheck_fail(
							sprintf(
								/* translators: 1: stored version, 2: installed version. */
								__( 'Rules were flushed for %1$s; %2$s is installed.', 'qhta-commerce' ),
								$stored ? $stored : __( '(never)', 'qhta-commerce' ),
								QHTA_COMMERCE_VERSION
							)
						);
					}

					return qhta_healthcheck_pass( QHTA_COMMERCE_VERSION );
				},
			),
			array(
				'id'       => 'login-page',
				'label'    => __( 'Login redirect target', 'qhta-commerce' ),
				'why'      => __( 'Both the logged-out and the unentitled visitor are sent to the login page with redirect_to. If that page is unpublished or renamed, the gate dumps buyers on a 404 — and the redirect itself still works perfectly, so nothing logs an error.', 'qhta-commerce' ),
				'severity' => 'critical',
				'test'     => function () {
					// Asked through the plugin's own filterable resolver rather
					// than a hardcoded '/login/', so overriding the destination
					// moves the canary with it instead of leaving it watching a
					// page nobody is sent to any more.
					$url  = qhta_commerce_login_url( home_url( '/' ) );
					$path = (string) wp_parse_url( $url, PHP_URL_PATH );

					return qhta_healthcheck_assert_page_at( $path );
				},
			),
			array(
				'id'       => 'shop-page',
				'label'    => __( 'WooCommerce shop page', 'qhta-commerce' ),
				'why'      => __( 'The "browse the shop" link on the gate and the member banner both resolve through wc_get_page_permalink( \'shop\' ). Unset, they render as links to the home page.', 'qhta-commerce' ),
				'severity' => 'warning',
				'test'     => function () {
					return qhta_healthcheck_assert_wc_page( 'shop' );
				},
			),
			array(
				'id'       => 'store-reachable',
				'label'    => __( 'Store is reachable by someone', 'qhta-commerce' ),
				'why'      => __( 'The shopfront is hidden unless QHTA_STORE_LIVE is defined or a preview token is set. With neither, cart, checkout and shop redirect away for everybody, including you — which looks identical to the store being broken.', 'qhta-commerce' ),
				'severity' => 'warning',
				'test'     => function () {
					if ( defined( 'QHTA_STORE_LIVE' ) && QHTA_STORE_LIVE ) {
						return qhta_healthcheck_pass( __( 'QHTA_STORE_LIVE is set — the store is public.', 'qhta-commerce' ) );
					}

					if ( '' !== (string) qhta_commerce_preview_token() ) {
						return qhta_healthcheck_pass( __( 'Store is hidden; preview token is configured.', 'qhta-commerce' ) );
					}

					return qhta_healthcheck_fail( __( 'Store is hidden and no preview token is defined in wp-config.php — nobody can reach it.', 'qhta-commerce' ) );
				},
			),
			array(
				'id'       => 'checkout-phone-filter',
				'label'    => __( 'Checkout phone-field override', 'qhta-commerce' ),
				'why'      => __( 'Hooked on pre_option_woocommerce_checkout_phone_field, which only fires because the option row does not exist. If somebody saves WooCommerce checkout settings the row appears, the filter stops firing and the phone field silently comes back.', 'qhta-commerce' ),
				'severity' => 'warning',
				'test'     => function () {
					$hooked = qhta_healthcheck_assert_hooked(
						'pre_option_woocommerce_checkout_phone_field',
						'qhta_commerce_checkout_phone_field_option'
					);

					if ( ! $hooked['ok'] ) {
						return $hooked;
					}

					global $wpdb;

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a once-daily existence probe on one option row.
					$row = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s",
							'woocommerce_checkout_phone_field'
						)
					);

					if ( $row ) {
						return qhta_healthcheck_fail( __( 'The woocommerce_checkout_phone_field option row now exists in the database, so the pre_option_ filter no longer decides the phone field.', 'qhta-commerce' ) );
					}

					return qhta_healthcheck_pass( $hooked['detail'] );
				},
			),
			array(
				'id'       => 'thankyou-resources',
				'label'    => __( 'Thank-you page resources section', 'qhta-commerce' ),
				'why'      => __( 'The "Access your resources" links are hung off woocommerce_thankyou. Losing it means a buyer completes a purchase and is shown no way to reach what they just bought.', 'qhta-commerce' ),
				'severity' => 'warning',
				'test'     => function () {
					return qhta_healthcheck_assert_hooked( 'woocommerce_thankyou', 'qhta_commerce_thankyou_resources' );
				},
			),
			array(
				'id'       => 'cart-fragments',
				'label'    => __( 'Cart-count fragment', 'qhta-commerce' ),
				'why'      => __( 'The header cart button updates through woocommerce_add_to_cart_fragments. Without it the count sticks at whatever it was when the page was rendered, which reads as "add to cart is broken".', 'qhta-commerce' ),
				'severity' => 'warning',
				'test'     => function () {
					return qhta_healthcheck_assert_hooked( 'woocommerce_add_to_cart_fragments', 'qhta_commerce_cart_button_fragment' );
				},
			),
			array(
				'id'       => 'pmpro-member-banner',
				'label'    => __( 'PMPro member-pricing banner', 'qhta-commerce' ),
				'why'      => __( 'The shopfront banner asks PMPro whether the visitor is a member. Optional — the shop works without it — but its absence means non-members stop being told that membership is cheaper.', 'qhta-commerce' ),
				'severity' => 'warning',
				'test'     => function () {
					return qhta_healthcheck_assert_functions( array( 'pmpro_hasMembershipLevel', 'pmpro_url' ) );
				},
			),
			array(
				'id'       => 'offline-gateways-events-only',
				'label'    => __( 'Offline gateways limited to Events', 'qhta-commerce' ),
				'why'      => __( 'Bank transfer and cheque are store-wide WooCommerce gateways; this filter is the only thing keeping them off study-package checkouts. Unhooked, or with the Events category slug gone, study-package buyers can pay offline and sit On hold with their content locked.', 'qhta-commerce' ),
				'severity' => 'warning',
				'test'     => function () {
					$hooked = qhta_healthcheck_assert_hooked(
						'woocommerce_available_payment_gateways',
						'qhta_commerce_limit_offline_gateways'
					);

					if ( ! $hooked['ok'] ) {
						return $hooked;
					}

					// Asked through the plugin's own resolver, so a filtered
					// slug list moves the canary with it.
					$missing = array();
					foreach ( qhta_commerce_offline_gateway_categories() as $slug ) {
						if ( ! get_term_by( 'slug', $slug, 'product_cat' ) ) {
							$missing[] = $slug;
						}
					}

					if ( $missing ) {
						return qhta_healthcheck_fail(
							sprintf(
								/* translators: %s: comma-separated category slugs. */
								__( 'No product category with slug %s — no purchase can qualify for offline payment.', 'qhta-commerce' ),
								implode( ', ', $missing )
							)
						);
					}

					return qhta_healthcheck_pass( $hooked['detail'] );
				},
			),
		)
	);

	return $checks;
}
add_filter( 'qhta_healthcheck_checks', 'qhta_commerce_healthcheck_checks' );
