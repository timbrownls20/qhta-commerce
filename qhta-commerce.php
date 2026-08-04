<?php
/**
 * Plugin Name:       QHTA Commerce
 * Description:       WooCommerce-side custom logic for qhta.com.au — purchase-gated content pages driven by a per-page product-ID field, plus a My Account tab listing what the customer can reach. No presentation, no conference domain logic.
 * Version:           1.1.1
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

define( 'QHTA_COMMERCE_VERSION', '1.1.1' );

/**
 * Post meta holding the product ID that unlocks a page.
 *
 * Underscore-prefixed so it stays out of the generic Custom Fields UI — the
 * meta box below is the only intended way to set it.
 */
const QHTA_GATE_META = '_qhta_gate_product_id';

/**
 * My Account endpoint slug for the "My Content" tab.
 *
 * Doubles as the menu-item key in woocommerce_account_menu_items and as the
 * suffix on the woocommerce_account_{endpoint}_endpoint action, so the slug and
 * the tab cannot drift apart. Changing it needs a permalink flush — deactivate
 * and reactivate the plugin, which flushes on both.
 */
const QHTA_CONTENT_ENDPOINT = 'my-content';

/**
 * Where a logged-in visitor who has not bought the product is sent.
 *
 * Defaults to the login page, same destination as a logged-out visitor gets —
 * there is no sales page yet, and /login/ is a real page that says something,
 * where the previous /not-found/ default served the theme's 404. It does not
 * carry redirect_to: they are already signed in, so returning them here would
 * only bounce them straight back out.
 *
 * Filterable, so a real sales page can take over without editing the plugin:
 *
 *   add_filter( 'qhta_commerce_sales_url', function () {
 *       return home_url( '/some-sales-page/' );
 *   } );
 *
 * Note the trade this makes: any redirect to a login or sales page reveals that
 * *something* exists at the gated URL. The old /not-found/ default hid that, at
 * the cost of telling a real customer their paid content does not exist. Put
 * /not-found/ back through this filter if the quiet version is ever wanted.
 *
 * @return string
 */
function qhta_commerce_sales_url() {
	return apply_filters( 'qhta_commerce_sales_url', home_url( '/login/' ) );
}

/**
 * Where a logged-out visitor to a gated page is sent.
 *
 * The site's single branded login page, /login/ — PMPro's Log In page,
 * rebranded — carrying the gated page as `redirect_to` so they land back on it
 * once signed in. A buyer who is merely logged out gets in, rather than being
 * told the page does not exist. PMPro's Log In page accepts `redirect_to`
 * natively; it is the same mechanism its checkout "log in here" link uses.
 *
 * Not My Account (/my-account/ is the post-login dashboard, not where customers
 * sign in) and not wp_login_url() (wp-login.php is a different, more
 * administrative-looking door).
 *
 * Deployment dependency: /login/ — and any login redirect qhta-membership grows
 * later — must honour redirect_to, or the buyer is bounced to a fixed page
 * instead of back to the gated page.
 *
 * Like qhta_commerce_sales_url(), the destination is a plain path rather than a
 * looked-up page, and is filterable so it can be moved without editing the
 * plugin:
 *
 *   add_filter( 'qhta_commerce_login_url', function ( $url, $return_to ) {
 *       return add_query_arg( 'redirect_to', rawurlencode( $return_to ), home_url( '/sign-in/' ) );
 *   }, 10, 2 );
 *
 * Sending anyone here does reveal that *something* exists at the URL, which a
 * /not-found/ redirect would hide. That is the trade: discretion, for buyers not
 * getting stranded. Both destinations are filterable if the quiet version is
 * ever wanted back.
 *
 * @param string $return_to URL to return the visitor to after login.
 * @return string
 */
function qhta_commerce_login_url( $return_to ) {
	// add_query_arg() does not encode values, so encode the URL here or a page
	// with a query string of its own would corrupt the parameter.
	$url = add_query_arg(
		'redirect_to',
		rawurlencode( $return_to ),
		home_url( '/login/' )
	);

	return apply_filters( 'qhta_commerce_login_url', $url, $return_to );
}

/**
 * Make WooCommerce's login form honour that redirect_to as well.
 *
 * Belt and braces: /login/ is PMPro's page and handles redirect_to itself, so
 * this is for the other door — the login form on My Account, which a customer
 * can still reach on their own.
 *
 * WC_Form_Handler::process_login() uses `$_POST['redirect']` when present, but
 * the stock myaccount/form-login.php template never renders that field — so
 * without this the parameter is carried to the page and then silently dropped
 * at login. Adding the hidden field is the whole fix.
 *
 * Scoped by the hook itself: woocommerce_login_form only fires inside Woo's own
 * login form, so nothing is emitted on any other page.
 *
 * Validated against the site's own host on the way in, so the field cannot be
 * turned into an open redirect by handing someone a crafted login link.
 * (WooCommerce validates again on submit; this is the near-side check.)
 */
function qhta_commerce_login_redirect_field() {
	// No nonce here on purpose: this only reads a URL out of the query string
	// and echoes it back after validating it against our own host. It changes
	// nothing, and the login POST itself is nonce-checked by WooCommerce.
	if ( empty( $_GET['redirect_to'] ) ) {
		return;
	}

	$redirect = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), '' );

	if ( ! $redirect ) {
		return;
	}

	printf( '<input type="hidden" name="redirect" value="%s" />', esc_url( $redirect ) );
}
add_action( 'woocommerce_login_form', 'qhta_commerce_login_redirect_field' );

/**
 * Is WooCommerce present and far enough booted to ask about purchases?
 *
 * Checked before every Woo call so deactivating WooCommerce degrades to "no
 * gating" rather than a fatal error on every page of the site.
 *
 * @return bool
 */
function qhta_commerce_woo_active() {
	return function_exists( 'wc_customer_bought_product' );
}


/* -------------------------------------------------------------------------
 * 1. Per-page field — the product picker
 *
 * A classic meta box rather than a Block Editor sidebar panel: Gutenberg
 * renders `side` context meta boxes in the document sidebar already, so this
 * gets a native-enough panel with no build step and no dependencies of our own.
 *
 * The control is WooCommerce's own product search (select2 over its
 * woocommerce_json_search_products endpoint) so a product is chosen by name
 * rather than by looking an ID up first. It degrades to a plain number input
 * if that search is unavailable — see qhta_commerce_product_search_ready().
 * Either way the posted value is a product ID, so the save path is unchanged.
 * ---------------------------------------------------------------------- */

/**
 * Load WooCommerce's enhanced-select assets on the page editor.
 *
 * WooCommerce registers `wc-enhanced-select` (and localises the AJAX nonce it
 * needs) on every admin load, but only *enqueues* it — and its stylesheet — on
 * WooCommerce's own screens. The page editor is not one, so both are pulled in
 * here. Nothing is registered under our own handle; this is Woo's UI, borrowed.
 *
 * @param string $hook Current admin page.
 */
function qhta_commerce_admin_assets( $hook ) {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'page' !== $screen->post_type ) {
		return;
	}

	if ( ! qhta_commerce_woo_active() ) {
		return;
	}

	// WordPress silently drops an enqueue whose handle was never registered, so
	// check rather than assume — a future Woo could move these.
	if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
		wp_enqueue_script( 'wc-enhanced-select' );
	}
	if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
		wp_enqueue_style( 'woocommerce_admin_styles' );
	}
}
add_action( 'admin_enqueue_scripts', 'qhta_commerce_admin_assets' );

/**
 * Did the product search actually load?
 *
 * Called when the meta box renders, which is after admin_enqueue_scripts has
 * run, so this reports what really happened rather than what we hoped for.
 * Without the script the select would be an empty dropdown with no way to pick
 * anything, which is worse than the number input it falls back to.
 *
 * @return bool
 */
function qhta_commerce_product_search_ready() {
	return qhta_commerce_woo_active()
		&& wp_script_is( 'wc-enhanced-select', 'enqueued' );
}

/**
 * Register the meta box on the page post type.
 *
 * Titled for whichever control will be shown. The title is fixed at
 * registration time, before the enqueue has run, so it keys off WooCommerce
 * being active rather than the narrower check the renderer uses.
 */
function qhta_commerce_add_meta_box() {
	$title = qhta_commerce_woo_active()
		? __( 'Product', 'qhta-commerce' )
		: __( 'Product ID', 'qhta-commerce' );

	add_meta_box(
		'qhta_gate',
		$title,
		'qhta_commerce_render_meta_box',
		'page',
		'side'
	);
}
add_action( 'add_meta_boxes', 'qhta_commerce_add_meta_box' );

/**
 * Render the field.
 *
 * No visible label — the panel heading already says what this is. The control
 * carries an aria-label so it is still named for screen readers.
 *
 * @param WP_Post $post Page being edited.
 */
function qhta_commerce_render_meta_box( $post ) {
	$product_id = (int) get_post_meta( $post->ID, QHTA_GATE_META, true );

	wp_nonce_field( 'qhta_gate_save', 'qhta_gate_nonce' );

	if ( qhta_commerce_product_search_ready() ) {
		?>
		<select
			class="wc-product-search"
			id="qhta_gate_product_id"
			name="qhta_gate_product_id"
			style="width:100%"
			data-placeholder="<?php esc_attr_e( 'Search for a product…', 'qhta-commerce' ); ?>"
			data-action="woocommerce_json_search_products"
			data-allow_clear="true"
			aria-label="<?php esc_attr_e( 'Product that unlocks this page', 'qhta-commerce' ); ?>"
		>
			<option value=""></option>
			<?php if ( $product_id > 0 ) : ?>
				<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected">
					<?php echo esc_html( qhta_commerce_product_label( $product_id ) ); ?>
				</option>
			<?php endif; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Leave blank for a public page. Choose the WooCommerce product that unlocks this page.', 'qhta-commerce' ); ?>
		</p>
		<?php
		return;
	}
	?>
	<input
		type="number"
		min="0"
		step="1"
		id="qhta_gate_product_id"
		name="qhta_gate_product_id"
		value="<?php echo $product_id > 0 ? esc_attr( $product_id ) : ''; ?>"
		style="width:100%"
		aria-label="<?php esc_attr_e( 'ID of the product that unlocks this page', 'qhta-commerce' ); ?>"
	>
	<p class="description">
		<?php esc_html_e( 'Leave blank for a public page. Enter the WooCommerce product ID that unlocks this page.', 'qhta-commerce' ); ?>
	</p>
	<?php
}

/**
 * Label for the currently-selected product.
 *
 * A stored ID whose product has since been deleted still gets an option, so
 * opening and saving the page cannot silently drop the gate. It reads as
 * broken, which it is.
 *
 * @param int $product_id Stored product ID.
 * @return string
 */
function qhta_commerce_product_label( $product_id ) {
	$product = qhta_commerce_woo_active() ? wc_get_product( $product_id ) : false;

	if ( $product ) {
		return wp_strip_all_tags( $product->get_formatted_name() );
	}

	/* translators: %d: product ID that no longer resolves to a product. */
	return sprintf( __( '#%d — product not found', 'qhta-commerce' ), $product_id );
}

/**
 * Save the field.
 *
 * An empty or zero value deletes the meta outright, so "blank field" and "meta
 * absent" are the same state and a page can be un-gated by clearing the box.
 *
 * @param int $post_id Page being saved.
 */
function qhta_commerce_save_meta_box( $post_id ) {
	// No nonce means this save did not come from the page editor form — an
	// autosave, a REST-only save, or a programmatic wp_update_post. Leaving the
	// meta untouched is correct in all three cases.
	if ( ! isset( $_POST['qhta_gate_nonce'] ) ) {
		return;
	}

	$nonce = sanitize_text_field( wp_unslash( $_POST['qhta_gate_nonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'qhta_gate_save' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_page', $post_id ) ) {
		return;
	}

	$product_id = isset( $_POST['qhta_gate_product_id'] ) ? absint( wp_unslash( $_POST['qhta_gate_product_id'] ) ) : 0;

	if ( $product_id > 0 ) {
		update_post_meta( $post_id, QHTA_GATE_META, $product_id );
	} else {
		delete_post_meta( $post_id, QHTA_GATE_META );
	}
}
add_action( 'save_post_page', 'qhta_commerce_save_meta_box' );


/* -------------------------------------------------------------------------
 * 2. The gate
 * ---------------------------------------------------------------------- */

/**
 * Has this visitor bought the product that unlocks the page?
 *
 * wc_customer_bought_product() matches paid statuses (completed + processing)
 * by default, which buys two behaviours without any stored state:
 *   - virtual/downloadable products, which often sit at "processing", unlock
 *     immediately rather than waiting for a manual completion;
 *   - a refund revokes access on the next page load, because the order leaves
 *     the paid statuses. Nothing to keep in sync, nothing to expire.
 *
 * Filterable so entitlement can be widened — e.g. to let editors preview a
 * gated page without owning the product:
 *
 *   add_filter( 'qhta_commerce_is_entitled', function ( $entitled, $product_id, $page_id ) {
 *       return $entitled || current_user_can( 'edit_post', $page_id );
 *   }, 10, 3 );
 *
 * The user is a parameter rather than always the current one so the My Content
 * tab can ask the same question this gate asks. Anything the filter lets
 * through therefore also shows up in that user's tab, which is the point — one
 * definition of "entitled", not two that drift. A fourth argument carries the
 * user ID for filters that want it; three-argument callbacks like the one above
 * are unaffected.
 *
 * @param int      $product_id Product that unlocks the page.
 * @param int      $page_id    Page being gated.
 * @param int|null $user_id    User to check. Defaults to the current user.
 * @return bool
 */
function qhta_commerce_is_entitled( $product_id, $page_id, $user_id = null ) {
	$user_id  = null === $user_id ? get_current_user_id() : (int) $user_id;
	$entitled = false;

	if ( $user_id > 0 && qhta_commerce_woo_active() ) {
		// get_userdata() rather than wp_get_current_user(), so a user ID that no
		// longer exists is "not entitled" instead of a fatal on ->user_email.
		$user = get_userdata( $user_id );

		if ( $user ) {
			$entitled = (bool) wc_customer_bought_product(
				$user->user_email,
				$user->ID,
				$product_id
			);
		}
	}

	return (bool) apply_filters( 'qhta_commerce_is_entitled', $entitled, $product_id, $page_id, $user_id );
}

/**
 * Enforce the gate on the front end.
 *
 * Runs on template_redirect — early enough that nothing of the page has been
 * sent, late enough that the query is resolved. Server-side by design: hiding
 * the content in the template would still ship it to the browser.
 */
function qhta_commerce_enforce_gate() {
	if ( is_admin() || ! is_page() ) {
		return;
	}

	// WooCommerce gone: fail open rather than fatal. Documented in the README —
	// deactivating Woo un-gates the site.
	if ( ! qhta_commerce_woo_active() ) {
		return;
	}

	$page_id = get_queried_object_id();
	if ( ! $page_id ) {
		return;
	}

	$product_id = (int) get_post_meta( $page_id, QHTA_GATE_META, true );
	if ( $product_id <= 0 ) {
		return; // Not a gated page.
	}

	// Defence in depth against a cached copy leaking to a non-buyer. This does
	// NOT replace excluding gated pages from full-page caching at the host —
	// page caches that never reach PHP never see these headers. See README.
	nocache_headers();

	if ( qhta_commerce_is_entitled( $product_id, $page_id ) ) {
		return;
	}

	// Both defaults land on /login/; the difference is the return trip. Logged
	// out is "entitlement unknown", not "not entitled", so they get redirect_to
	// and come back here after signing in — a buyer following a link from their
	// own confirmation email gets in rather than being told the page is missing.
	// A logged-in non-buyer has nothing to come back for, so no redirect_to, and
	// the destination is separately filterable for when a sales page exists.
	// Checked after entitlement so the qhta_commerce_is_entitled filter can
	// still let an anonymous visitor through if a future rule ever needs to.
	$target = is_user_logged_in()
		? qhta_commerce_sales_url()
		: qhta_commerce_login_url( get_permalink( $page_id ) );

	// A page that redirects to itself would do so forever. Fail open on that
	// misconfiguration — a visible unlocked page is easier to diagnose than a
	// redirect loop. Reachable by gating the sales page, or by gating the login
	// page itself. Compared without the query string, because the login URL
	// carries redirect_to and would otherwise never match.
	if ( untrailingslashit( strtok( $target, '?' ) ) === untrailingslashit( get_permalink( $page_id ) ) ) {
		return;
	}

	// 302, not 301: entitlement changes. A permanent redirect would be cached
	// by the browser and would keep bouncing the visitor after they bought.
	wp_safe_redirect( $target, 302 );
	exit;
}
add_action( 'template_redirect', 'qhta_commerce_enforce_gate' );


/* -------------------------------------------------------------------------
 * 3. "My Content" — the My Account tab
 *
 * Lists every gated page the logged-in customer can currently reach, each as a
 * link. The list is derived, never maintained: it is "all pages carrying
 * QHTA_GATE_META, filtered to the ones this customer is entitled to" — the same
 * meta the gate reads, asked through the same qhta_commerce_is_entitled().
 *
 * So gating a new page makes it appear in every entitled customer's tab with no
 * code change, and a refund drops the link on the next load exactly as it drops
 * access. No product IDs and no page slugs live in here.
 * ---------------------------------------------------------------------- */

/**
 * Pages the given user can currently access.
 *
 * @param int|null $user_id User to check. Defaults to the current user.
 * @return int[] Page IDs, ordered by title.
 */
function qhta_commerce_accessible_pages( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

	if ( $user_id <= 0 || ! qhta_commerce_woo_active() ) {
		return array();
	}

	// Every gated page, entitled or not. EXISTS rather than a value comparison
	// because the gate stores nothing when a page is public — "meta absent" and
	// "blank field" are the same state, per qhta_commerce_save_meta_box().
	$gated = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'orderby'     => 'title',
			'order'       => 'ASC',
			'meta_query'  => array(
				array(
					'key'     => QHTA_GATE_META,
					'compare' => 'EXISTS',
				),
			),
		)
	);

	$accessible = array();

	foreach ( $gated as $page_id ) {
		$product_id = (int) get_post_meta( $page_id, QHTA_GATE_META, true );

		// A stored 0 would mean an un-gated page — treat it as public content
		// rather than listing it here, since this tab is only about paid pages.
		if ( $product_id > 0 && qhta_commerce_is_entitled( $product_id, $page_id, $user_id ) ) {
			$accessible[] = $page_id;
		}
	}

	return $accessible;
}

/**
 * Where the empty state's "Browse products" button points.
 *
 * WooCommerce's own Shop page, because that is the one destination this plugin
 * can find without being told — no slug hardcoded here, and it keeps working if
 * the shop is moved or renamed. Falls back to qhta_commerce_sales_url() if no
 * Shop page is configured.
 *
 * Deliberately its own filter rather than reusing qhta_commerce_sales_url():
 * that one is where a *blocked* visitor is sent and currently points at
 * /login/, which is no use to someone already signed in and reading their own
 * account page.
 *
 *   add_filter( 'qhta_commerce_browse_url', function () {
 *       return home_url( '/recordings/' );
 *   } );
 *
 * @return string
 */
function qhta_commerce_browse_url() {
	$url = qhta_commerce_woo_active() ? wc_get_page_permalink( 'shop' ) : '';

	if ( ! $url ) {
		$url = qhta_commerce_sales_url();
	}

	return apply_filters( 'qhta_commerce_browse_url', $url );
}

/**
 * The tab's label, used for both the menu item and the endpoint page title.
 *
 * One function so those two cannot disagree — the label is still to be
 * confirmed with Tim, and this is the only line to change. It is deliberately
 * general rather than named after recordings or any other single product: the
 * tab lists whatever is gated.
 *
 * The slug is a separate decision — changing QHTA_CONTENT_ENDPOINT alters the
 * URL and needs a permalink flush, where changing this does not.
 *
 * @return string
 */
function qhta_commerce_content_label() {
	return __( 'My Content', 'qhta-commerce' );
}

/**
 * Register the endpoint.
 *
 * EP_ROOT | EP_PAGES because My Account is a page, and the endpoint has to work
 * whether or not that page sits at the site root.
 */
function qhta_commerce_add_content_endpoint() {
	add_rewrite_endpoint( QHTA_CONTENT_ENDPOINT, EP_ROOT | EP_PAGES );
}
add_action( 'init', 'qhta_commerce_add_content_endpoint' );

/**
 * Option stamping the version whose rewrite rules were last flushed.
 *
 * Autoloaded on purpose — it is read on every request, so a row that comes in
 * with the rest of the options is cheaper than a query of its own.
 */
const QHTA_REWRITES_OPTION = 'qhta_commerce_rewrites_version';

/**
 * Flush the rewrite rules once per plugin version.
 *
 * Rewrite rules are cached, so an endpoint registered on `init` alone 404s
 * until something rebuilds them. The activation hook below covers a fresh
 * activate — but *not* an update: uploading a new zip over an active plugin
 * deactivates and reactivates it silently (`activate_plugin( …, $silent = true )`),
 * which fires neither hook. That is exactly how 1.1.0 shipped a tab that 404ed
 * until Settings -> Permalinks was saved by hand.
 *
 * So the version stamp is the real mechanism and the hooks are the belt: bump
 * QHTA_COMMERCE_VERSION and the first request after the upload rebuilds the
 * rules itself, however the new code arrived.
 *
 * On `wp_loaded`, not `init` — flush_rewrite_rules() writes whatever rules are
 * registered *at that moment*, so running it mid-`init` could bake in a set
 * missing the endpoints of any plugin that registers later than we do,
 * WooCommerce's account endpoints included.
 *
 * It is expensive, hence once per version and never otherwise.
 */
function qhta_commerce_maybe_flush_rewrites() {
	if ( get_option( QHTA_REWRITES_OPTION ) === QHTA_COMMERCE_VERSION ) {
		return;
	}

	flush_rewrite_rules();
	update_option( QHTA_REWRITES_OPTION, QHTA_COMMERCE_VERSION );
}
add_action( 'wp_loaded', 'qhta_commerce_maybe_flush_rewrites' );

/**
 * Register the endpoint and flush on activation.
 *
 * The endpoint has to be registered again here: activation runs before this
 * plugin's own `init` hook has fired, so the flush would otherwise write rules
 * that do not include it.
 */
function qhta_commerce_activate() {
	qhta_commerce_add_content_endpoint();
	flush_rewrite_rules();
	update_option( QHTA_REWRITES_OPTION, QHTA_COMMERCE_VERSION );
}
register_activation_hook( __FILE__, 'qhta_commerce_activate' );

/**
 * Clear the stamp on the way out, so reactivating rebuilds the rules.
 *
 * The flush here does *not* remove our endpoint: deactivation runs long after
 * `init`, so the endpoint is still registered in this request and gets written
 * straight back. WordPress has no remove_rewrite_endpoint() to do better. The
 * leftover rule is inert — nothing loads the query var once the plugin is gone,
 * so the URL 404s, which is the wanted outcome anyway — and the next flush
 * clears it. Dropping the stamp is the part that matters.
 */
function qhta_commerce_deactivate() {
	delete_option( QHTA_REWRITES_OPTION );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'qhta_commerce_deactivate' );

/**
 * Add the tab to the My Account menu, immediately before "Log out".
 *
 * Woo renders this array in order, so "before Log out" means removing Log out
 * and putting it back last. Guarded rather than assumed: the key is absent
 * when another plugin has already taken it out.
 *
 * @param array $items Menu items, keyed by endpoint.
 * @return array
 */
function qhta_commerce_account_menu_items( $items ) {
	$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;

	unset( $items['customer-logout'] );

	$items[ QHTA_CONTENT_ENDPOINT ] = qhta_commerce_content_label();

	if ( null !== $logout ) {
		$items['customer-logout'] = $logout;
	}

	return $items;
}
add_filter( 'woocommerce_account_menu_items', 'qhta_commerce_account_menu_items' );

/**
 * Title for the endpoint's own page.
 *
 * WC_Query::endpoint_title() only knows its own endpoints and returns an empty
 * string for anyone else's, which leaves the tab headed by the account page
 * title. Filter named for the endpoint, so nothing else is touched.
 *
 * @return string
 */
function qhta_commerce_content_endpoint_title() {
	return qhta_commerce_content_label();
}
add_filter( 'woocommerce_endpoint_' . QHTA_CONTENT_ENDPOINT . '_title', 'qhta_commerce_content_endpoint_title' );

/**
 * Render the tab.
 *
 * Links only. The pages themselves stay behind the gate, which re-checks
 * entitlement when one is opened — this tab is a way in, not a way round.
 * Nothing is embedded here, so nothing paid is served from My Account.
 */
function qhta_commerce_content_endpoint_content() {
	$pages = qhta_commerce_accessible_pages();

	if ( empty( $pages ) ) {
		printf(
			'<p>%s</p>',
			esc_html__( 'You don’t have access to any content yet.', 'qhta-commerce' )
		);
		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( qhta_commerce_browse_url() ),
			esc_html__( 'Browse products', 'qhta-commerce' )
		);

		return;
	}

	echo '<ul class="qhta-my-content">';

	foreach ( $pages as $page_id ) {
		printf(
			'<li><a href="%s">%s</a></li>',
			esc_url( get_permalink( $page_id ) ),
			esc_html( get_the_title( $page_id ) )
		);
	}

	echo '</ul>';
}
add_action( 'woocommerce_account_' . QHTA_CONTENT_ENDPOINT . '_endpoint', 'qhta_commerce_content_endpoint_content' );


/* -------------------------------------------------------------------------
 * 4. Admin notice when WooCommerce is missing
 *
 * The gate fails open without Woo, which is silent by design. Say so in the
 * admin so a deactivated WooCommerce cannot quietly unlock paid pages.
 * ---------------------------------------------------------------------- */

/**
 * Warn in wp-admin if WooCommerce is not available.
 */
function qhta_commerce_woo_missing_notice() {
	if ( qhta_commerce_woo_active() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e( 'QHTA Commerce:', 'qhta-commerce' ); ?></strong>
			<?php esc_html_e( 'WooCommerce is not active, so purchase gates are not being enforced. Any page with a gate product set is currently public.', 'qhta-commerce' ); ?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'qhta_commerce_woo_missing_notice' );
