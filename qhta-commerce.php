<?php
/**
 * Plugin Name:       QHTA Commerce
 * Description:       WooCommerce-side custom logic for qhta.com.au — purchase-gated content pages driven by a per-page product-ID field, a My Account tab listing what the customer can reach, store preview mode, shopfront personalisation (member-pricing banner, header cart button, styling for both), store checkout tweaks, and an "Access your resources" section on the thank-you page. No theme presentation, no conference domain logic.
 * Version:           1.6.0
 * Author:            QHTA
 * License:           GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * Scope rule: WooCommerce-side behaviour only. Theme presentation lives in
 * qhta-theme-extras; conference domain logic lives in the conference program plugin.
 *
 * One carve-out, decided deliberately: the shopfront components this plugin
 * *creates* — the member banner and the cart button — ship their own CSS here,
 * in assets/qhta-commerce.css. They exist only while this plugin is active, so
 * keeping their markup and their styling in one repo means one deploy per
 * change instead of two. It stays scoped to those components; styling anything
 * the theme owns still belongs in qhta-theme-extras.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QHTA_COMMERCE_VERSION', '1.6.0' );

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
 * 4. Store preview mode
 *
 * The store is hidden from the public until QHTA_STORE_LIVE says otherwise, so
 * it can be built and tested in place rather than half-deployed. While hidden:
 * flagged nav links are dropped from rendered menus, and store URLs redirect
 * away. Two ways to see it anyway — the live constant, or holding a signed
 * preview cookie.
 *
 * Being an administrator is deliberately not one of them. Hidden means hidden,
 * for everyone: the buying flow has to be walked as a logged-out visitor meets
 * it, and an administrator is exactly the wrong person to test that with. An
 * account that sees a different site from the one it is launching cannot tell
 * whether the launch worked. This matches the gate in section 2, which no role
 * bypasses either.
 *
 * An administrator who wants to look at the hidden store takes the preview
 * cookie like anybody else. wp-admin is untouched — the store can still be
 * built, priced and edited while the shopfront stays dark.
 *
 * This hides the shopfront. It does not replace the purchase gate in section 2,
 * which protects the content itself and keeps running once the store is live.
 * ---------------------------------------------------------------------- */

/**
 * Name of the preview cookie.
 */
const QHTA_STORE_PREVIEW_COOKIE = 'qhta_store_preview';

/**
 * Query parameter that turns preview on and off.
 */
const QHTA_STORE_PREVIEW_PARAM = 'qhta-store-preview';

/**
 * Menu-item meta flagging a link as belonging to the store.
 */
const QHTA_STORE_LINK_META = '_qhta_store_link';

/**
 * Menu-item meta turning a link into the cart button.
 *
 * Its own flag rather than a reuse of the one above, because they answer
 * different questions: "hide this while the store is hidden" and "render this as
 * the cart button". A cart-button item is hidden with the store as a
 * consequence — see qhta_commerce_filter_menu_items() — so it does not need
 * both boxes ticked, but ticking both is harmless.
 */
const QHTA_CART_BUTTON_META = '_qhta_cart_button';

/**
 * Is the store visible to whoever is asking?
 *
 * Live or preview cookie — capabilities do not come into it. Filterable, so
 * visibility can be widened without editing the plugin if that is ever wanted —
 * e.g. to let a whole role in during a staged launch:
 *
 *   add_filter( 'qhta_commerce_store_visible', function ( $visible ) {
 *       return $visible || current_user_can( 'edit_posts' );
 *   } );
 *
 * @return bool
 */
function qhta_commerce_store_visible() {
	$visible = ( defined( 'QHTA_STORE_LIVE' ) && QHTA_STORE_LIVE )
		|| qhta_commerce_has_preview_cookie();

	return (bool) apply_filters( 'qhta_commerce_store_visible', $visible );
}

/**
 * The preview token, if one has been configured.
 *
 * Deliberately has no default. The token is a shared secret and this file is in
 * version control, so a default here would be a committed password — and one
 * every copy of the plugin shares. Undefined means preview is simply
 * unavailable and nobody at all sees the hidden shopfront until QHTA_STORE_LIVE
 * goes true, which is the safe way to be misconfigured — a missing secret
 * should shut a door, not open one. That is not a lockout: wp-admin never
 * redirects, so the store is still fully editable. Define it in wp-config.php;
 * see the README.
 *
 * @return string Empty when unset or not a string.
 */
function qhta_commerce_preview_token() {
	if ( ! defined( 'QHTA_STORE_PREVIEW_TOKEN' ) || ! is_string( QHTA_STORE_PREVIEW_TOKEN ) ) {
		return '';
	}

	return QHTA_STORE_PREVIEW_TOKEN;
}

/**
 * The value a valid preview cookie carries.
 *
 * wp_hash() of the token rather than the token itself, so a cookie that leaks —
 * from a shared browser, a screenshot, a support session — does not hand over
 * the secret that mints new ones. It is derived from the site's salts, so
 * rotating those invalidates every outstanding preview cookie.
 *
 * @return string Empty when no token is configured.
 */
function qhta_commerce_preview_cookie_value() {
	$token = qhta_commerce_preview_token();

	return '' === $token ? '' : wp_hash( $token );
}

/**
 * Does this request carry a valid preview cookie?
 *
 * @return bool
 */
function qhta_commerce_has_preview_cookie() {
	$expected = qhta_commerce_preview_cookie_value();

	if ( '' === $expected || empty( $_COOKIE[ QHTA_STORE_PREVIEW_COOKIE ] ) ) {
		return false;
	}

	$cookie = $_COOKIE[ QHTA_STORE_PREVIEW_COOKIE ];

	// A request can send `qhta_store_preview[]=x` to make this an array, and
	// hash_equals() fatals on a non-string. Check before comparing.
	if ( ! is_string( $cookie ) ) {
		return false;
	}

	// hash_equals() rather than ===: constant-time, so the comparison cannot be
	// used to work out a valid value a character at a time.
	return hash_equals( $expected, $cookie );
}

/**
 * Write (or clear) the preview cookie.
 *
 * SameSite=Lax and HttpOnly: nothing in the browser needs to read this, and it
 * should not ride along on cross-site requests. Secure follows is_ssl() rather
 * than being hardcoded, so preview still works on a plain-HTTP local copy.
 *
 * @param string $value   Cookie value. Empty to clear.
 * @param int    $expires Expiry timestamp.
 */
function qhta_commerce_set_preview_cookie( $value, $expires ) {
	setcookie(
		QHTA_STORE_PREVIEW_COOKIE,
		$value,
		array(
			'expires'  => $expires,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
}

/**
 * Turn preview on or off from the query string.
 *
 *   ?qhta-store-preview=THE_TOKEN   sets the cookie for 30 days
 *   ?qhta-store-preview=off         clears it
 *
 * No nonce: the token *is* the authentication, and the whole point is that this
 * works for a logged-out visitor with no session to tie a nonce to. An
 * unrecognised value is ignored silently — saying "wrong token" would confirm
 * to anyone guessing that the parameter means something.
 */
function qhta_commerce_handle_preview_request() {
	if ( ! isset( $_GET[ QHTA_STORE_PREVIEW_PARAM ] ) ) {
		return;
	}

	$value = sanitize_text_field( wp_unslash( $_GET[ QHTA_STORE_PREVIEW_PARAM ] ) );

	if ( 'off' === $value ) {
		qhta_commerce_set_preview_cookie( '', time() - HOUR_IN_SECONDS );
		unset( $_COOKIE[ QHTA_STORE_PREVIEW_COOKIE ] );

		return;
	}

	$token = qhta_commerce_preview_token();

	if ( '' === $token || ! hash_equals( $token, $value ) ) {
		return;
	}

	$cookie = qhta_commerce_preview_cookie_value();

	qhta_commerce_set_preview_cookie( $cookie, time() + 30 * DAY_IN_SECONDS );

	// setcookie() only reaches the browser on the *next* request. Seed $_COOKIE
	// as well so the click that turns preview on is already a preview request —
	// otherwise the block below would bounce you off the very page you came to
	// see, and it would look like the token had not worked.
	$_COOKIE[ QHTA_STORE_PREVIEW_COOKIE ] = $cookie;
}
add_action( 'init', 'qhta_commerce_handle_preview_request' );

/**
 * Per-menu-item checkbox: "Store link".
 *
 * Which links belong to the store is an editorial question — Cart, Shop, My
 * Content, whatever else gets added — so it is answered in Appearance -> Menus
 * rather than by matching URLs in code, which would break the moment a page
 * moved.
 *
 * @param int     $item_id Menu item ID.
 * @param WP_Post $item    Menu item.
 */
function qhta_commerce_menu_item_field( $item_id, $item ) {
	$store_id   = 'qhta-store-link-' . (int) $item_id;
	$cart_id    = 'qhta-cart-button-' . (int) $item_id;
	$is_store   = '1' === get_post_meta( $item_id, QHTA_STORE_LINK_META, true );
	$is_cart    = '1' === get_post_meta( $item_id, QHTA_CART_BUTTON_META, true );
	?>
	<p class="description description-wide">
		<label for="<?php echo esc_attr( $store_id ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( $store_id ); ?>"
				name="qhta_store_link[<?php echo esc_attr( $item_id ); ?>]"
				value="1"
				<?php checked( $is_store ); ?>
			>
			<?php esc_html_e( 'Store link (hidden until the store is live or in preview)', 'qhta-commerce' ); ?>
		</label>
	</p>
	<p class="description description-wide">
		<label for="<?php echo esc_attr( $cart_id ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( $cart_id ); ?>"
				name="qhta_cart_button[<?php echo esc_attr( $item_id ); ?>]"
				value="1"
				<?php checked( $is_cart ); ?>
			>
			<?php esc_html_e( 'Cart button — replace this item with the cart icon and live count', 'qhta-commerce' ); ?>
		</label>
	</p>
	<?php
}
add_action( 'wp_nav_menu_item_custom_fields', 'qhta_commerce_menu_item_field', 10, 2 );

/**
 * Save the checkboxes.
 *
 * @param int $menu_id    Menu being saved.
 * @param int $item_db_id Menu item being saved.
 */
function qhta_commerce_save_menu_item_field( $menu_id, $item_db_id ) {
	// wp_update_nav_menu_item() also fires for programmatic updates and
	// importers, where no checkbox was posted at all. Falling through to the
	// delete branch there would silently unflag every store link the next time
	// anything touched a menu in code. `menu-item-db-id` is only posted by the
	// menu editor, so it is the marker for "a human really did save this form".
	if ( ! isset( $_POST['menu-item-db-id'] ) ) {
		return;
	}

	// Core nonce-checks the menu save itself (`update-nav_menu`), so this is the
	// capability half of the house rule rather than a second nonce.
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}

	// Only presence is read — an unchecked box posts nothing — so the value is
	// never used and needs no sanitising.
	if ( isset( $_POST['qhta_store_link'][ $item_db_id ] ) ) {
		update_post_meta( $item_db_id, QHTA_STORE_LINK_META, '1' );
	} else {
		delete_post_meta( $item_db_id, QHTA_STORE_LINK_META );
	}

	if ( isset( $_POST['qhta_cart_button'][ $item_db_id ] ) ) {
		update_post_meta( $item_db_id, QHTA_CART_BUTTON_META, '1' );
	} else {
		delete_post_meta( $item_db_id, QHTA_CART_BUTTON_META );
	}
}
add_action( 'wp_update_nav_menu_item', 'qhta_commerce_save_menu_item_field', 10, 2 );

/**
 * Drop flagged links from rendered menus when they should not be there.
 *
 * Two reasons an item goes: it is a store link and the store is hidden, or it
 * is the cart button and there is nothing to render one from — a hidden store,
 * or no WooCommerce at all. The cart button is dropped here rather than left to
 * render as nothing so the menu is not left with an empty <li> carrying the
 * theme's item padding and separators.
 *
 * Descendants of a removed item go too. Hiding a parent but keeping its
 * children would leave submenu entries hanging off nothing, which most walkers
 * render at top level — the links would still be there, just uglier. Parents
 * always precede their children in this array, so one pass is enough.
 *
 * @param array $items Menu items about to be rendered.
 * @return array
 */
function qhta_commerce_filter_menu_items( $items ) {
	$hide_store = ! qhta_commerce_store_visible();
	$hide_cart  = $hide_store || ! qhta_commerce_woo_active();

	if ( ! $hide_store && ! $hide_cart ) {
		return $items;
	}

	$removed = array();

	foreach ( $items as $key => $item ) {
		$store_link = $hide_store && '1' === get_post_meta( $item->ID, QHTA_STORE_LINK_META, true );
		$cart       = $hide_cart && '1' === get_post_meta( $item->ID, QHTA_CART_BUTTON_META, true );
		$orphan     = isset( $removed[ (int) $item->menu_item_parent ] );

		if ( $store_link || $cart || $orphan ) {
			$removed[ (int) $item->ID ] = true;
			unset( $items[ $key ] );
		}
	}

	return $items;
}
add_filter( 'wp_nav_menu_objects', 'qhta_commerce_filter_menu_items' );

/**
 * Is this request for something that belongs to the store?
 *
 * WooCommerce's four pages, plus the product catalogue, plus any gated content
 * page.
 *
 * The catalogue is included on purpose, beyond the brief's list of pages:
 * blocking the Shop page alone would shut the front door and leave every
 * `/product/...` URL public and indexable. A hidden store that search engines
 * can still crawl item by item is not hidden.
 *
 * @return bool
 */
function qhta_commerce_is_store_request() {
	if ( is_post_type_archive( 'product' ) || is_singular( 'product' ) ) {
		return true;
	}

	if ( is_tax( array( 'product_cat', 'product_tag' ) ) ) {
		return true;
	}

	$page_id = get_queried_object_id();

	if ( ! $page_id ) {
		return false;
	}

	// wc_get_page_id() returns -1 for a page that has never been set, so filter
	// on > 0 rather than on truthiness.
	$store_pages = array_filter(
		array(
			(int) wc_get_page_id( 'cart' ),
			(int) wc_get_page_id( 'checkout' ),
			(int) wc_get_page_id( 'myaccount' ),
			(int) wc_get_page_id( 'shop' ),
		),
		static function ( $id ) {
			return $id > 0;
		}
	);

	if ( in_array( (int) $page_id, $store_pages, true ) ) {
		return true;
	}

	// Gated pages are part of the store: hidden with it, then protected by the
	// gate once it is live.
	return (int) get_post_meta( $page_id, QHTA_GATE_META, true ) > 0;
}

/**
 * Redirect store URLs away while the store is hidden.
 *
 * Priority 5, ahead of the gate at 10: a hidden store should send a logged-out
 * visitor home, not to /login/ to sign in for content that is not on sale yet.
 *
 * Fails open without WooCommerce, matching the gate — see the README. With Woo
 * gone there is no store to hide.
 */
function qhta_commerce_block_store_pages() {
	if ( is_admin() || qhta_commerce_store_visible() ) {
		return;
	}

	if ( ! qhta_commerce_woo_active() || ! function_exists( 'wc_get_page_id' ) ) {
		return;
	}

	if ( ! qhta_commerce_is_store_request() ) {
		return;
	}

	$target = apply_filters( 'qhta_commerce_store_hidden_redirect', home_url( '/' ) );

	// Same guard as the gate: a destination that is itself blocked would
	// redirect to itself forever. Fail open on that misconfiguration — reachable
	// by pointing this at a "coming soon" page and then gating it.
	$here = get_queried_object_id() ? get_permalink( get_queried_object_id() ) : '';

	if ( $here && untrailingslashit( strtok( $target, '?' ) ) === untrailingslashit( $here ) ) {
		return;
	}

	// 302, like the gate: this is a temporary state by definition, and a 301
	// would sit in browser caches long after go-live.
	wp_safe_redirect( $target, 302 );
	exit;
}
add_action( 'template_redirect', 'qhta_commerce_block_store_pages', 5 );


/* -------------------------------------------------------------------------
 * 5. Shop personalisation — member banner and cart button
 *
 * Two pieces of shopfront UI: a banner telling non-members that logging in or
 * joining gets them a better price, and a header cart button with a live item
 * count.
 *
 * Styled here too, in assets/qhta-commerce.css, rather than in
 * qhta-theme-extras — see the scope note at the top of this file. These
 * components are commerce UI that only exists while this plugin is active, and
 * splitting the markup from the CSS that makes it a button meant a two-repo
 * deploy to change an icon and a class-name contract to keep in step.
 *
 * The stylesheet is scoped to .qhta-member-banner and .qhta-cart-button, with
 * no element selectors and no site-wide custom properties, so it still cannot
 * reach the theme. Colours stay in the CSS; nothing in this file emits one.
 * ---------------------------------------------------------------------- */

/**
 * Is PMPro present and far enough booted to ask about membership?
 *
 * The membership counterpart to qhta_commerce_woo_active(), and read the same
 * way: no PMPro, no membership questions to answer.
 *
 * @return bool
 */
function qhta_commerce_pmpro_active() {
	return function_exists( 'pmpro_hasMembershipLevel' );
}

/**
 * Does the current visitor hold a membership level?
 *
 * False without PMPro — but callers must not read that as "so they are a
 * non-member and can be sold a membership". Nobody is a member when there is no
 * membership system, and nobody can be sold one either; see the banner below.
 *
 * @return bool
 */
function qhta_commerce_is_member() {
	if ( ! qhta_commerce_pmpro_active() ) {
		return false;
	}

	return (bool) pmpro_hasMembershipLevel();
}

/**
 * Where "join QHTA" points.
 *
 * PMPro's own Levels page when it can be found, the same way
 * qhta_commerce_browse_url() asks WooCommerce for the Shop page rather than
 * hardcoding a slug — it keeps working if the page is renamed or moved.
 *
 * The fallback is the brief's /membership-account/. **Confirm which is wanted**:
 * in a stock PMPro install that path is the *account* page a signed-in member
 * lands on, and the Levels page is where someone chooses what to buy — so the
 * fallback may be sending a prospective member to the wrong end of the funnel.
 * Filterable either way:
 *
 *   add_filter( 'qhta_commerce_join_url', function () {
 *       return home_url( '/join/' );
 *   } );
 *
 * @return string
 */
function qhta_commerce_join_url() {
	$url = function_exists( 'pmpro_url' ) ? pmpro_url( 'levels' ) : '';

	if ( ! $url ) {
		$url = home_url( '/membership-account/' );
	}

	return apply_filters( 'qhta_commerce_join_url', $url );
}

/**
 * The banner's text, links already built in.
 *
 * Assembled with placeholders rather than concatenated around the anchors so
 * the sentence stays translatable as a sentence — a translator can move "log
 * in" and "join QHTA" to wherever the grammar puts them.
 *
 * Filterable for wording changes without a deploy. The result is passed through
 * wp_kses_post() on output, so a filter can return links and emphasis but not
 * script:
 *
 *   add_filter( 'qhta_commerce_member_banner_text', function () {
 *       return 'Members pay less. <a href="/login/">Log in</a>.';
 *   } );
 *
 * @return string HTML.
 */
function qhta_commerce_member_banner_text() {
	// Back to the Shop page after signing in, via the plugin's one answer to
	// "where do people log in" rather than a second hardcoded /login/. On a
	// category archive that returns them to the main shop rather than the exact
	// term they were browsing — accepted, since it is never a wrong page.
	$return_to = qhta_commerce_woo_active() ? wc_get_page_permalink( 'shop' ) : '';

	if ( ! $return_to ) {
		$return_to = home_url( '/' );
	}

	$text = sprintf(
		/* translators: 1: opening link tag to the login page. 2: closing link tag. 3: opening link tag to the join page. 4: closing link tag. */
		__( 'Members save on every package — %1$slog in%2$s for member pricing, or %3$sjoin QHTA%4$s.', 'qhta-commerce' ),
		'<a href="' . esc_url( qhta_commerce_login_url( $return_to ) ) . '">',
		'</a>',
		'<a href="' . esc_url( qhta_commerce_join_url() ) . '">',
		'</a>'
	);

	return (string) apply_filters( 'qhta_commerce_member_banner_text', $text );
}

/**
 * Print the member-pricing banner above the product grid.
 *
 * Priority 5 on woocommerce_before_shop_loop: above the result count (20) and
 * the ordering dropdown (30), below the page title, which is outside this hook.
 *
 * Two ways to see nothing, and they are different things:
 *
 * - The visitor is already a member. They have the discount; a nudge to go and
 *   get it is noise at best and looks like a billing error at worst.
 * - PMPro is inactive. The brief's snippet showed the banner in this case,
 *   which acceptance criterion 8 then contradicts — this follows the criterion,
 *   and it is also the only safe reading: with no membership plugin there is no
 *   member pricing to advertise, the join link points at a page that no longer
 *   does anything, and members cannot be told from non-members, so the one
 *   group the banner must never reach would get it. Advertise nothing.
 *
 * No store-preview guard, and none is missing: this hook only fires on the shop
 * and product archives, which qhta_commerce_block_store_pages() has already
 * redirected away from while the store is hidden. A guard here would be a check
 * that can never be false.
 */
function qhta_commerce_member_banner() {
	if ( ! qhta_commerce_pmpro_active() || qhta_commerce_is_member() ) {
		return;
	}

	printf(
		'<div class="qhta-member-banner">%s</div>',
		wp_kses_post( qhta_commerce_member_banner_text() )
	);
}
add_action( 'woocommerce_before_shop_loop', 'qhta_commerce_member_banner', 5 );

/**
 * Print the cart button.
 *
 * Shared by the shortcode and the cart fragment so the two cannot render
 * differently — the fragment's whole job is to replace this markup with an
 * identical node carrying a new number.
 *
 * WC()->cart is null on admin, REST and cron requests, where there is no
 * session to have a cart in; that reads as empty rather than fatal.
 *
 * The anchor is always emitted, even at zero. Returning nothing when the cart
 * is empty would be the obvious way to hide it and it breaks the feature: the
 * fragment replaces the node matching 'a.qhta-cart-button', so once that node
 * is gone there is nothing left for the next add-to-cart to update, and the
 * count stays invisible until a page reload. The empty state is a class
 * instead, and the stylesheet carries a commented-out rule that hides it if
 * that is wanted — the node survives either way.
 */
function qhta_commerce_cart_button_html() {
	$count = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
	$url   = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );

	$classes = 'qhta-cart-button';

	if ( ! $count ) {
		$classes .= ' qhta-cart-button--empty';
	}

	printf(
		'<a class="%1$s" href="%2$s" aria-label="%3$s"><span class="qhta-cart-icon" aria-hidden="true"></span><span class="qhta-cart-count" aria-hidden="true">%4$d</span></a>',
		esc_attr( $classes ),
		esc_url( $url ),
		esc_attr(
			sprintf(
				/* translators: %d: number of items currently in the cart. */
				_n( 'View cart, %d item', 'View cart, %d items', $count, 'qhta-commerce' ),
				$count
			)
		),
		(int) $count
	);

	// The count is aria-hidden because the label above already says it. Without
	// that a screen reader announces the number twice, once as a sentence and
	// once as a bare digit.
}

/**
 * [qhta_cart_button] — the header cart button.
 *
 * A shortcode rather than a hooked-in header element because the header is the
 * theme's, not this plugin's: Astra and Elementor both take a shortcode in a
 * header slot, and that keeps the placement decision in the site builder where
 * whoever is arranging the header can see it.
 *
 * Self-guarding on store visibility, unlike the banner. The per-menu-item
 * "Store link" checkbox only reaches items in a nav menu, and this is dropped
 * into a header widget — so nothing else would hide it, and a cart icon on a
 * store nobody can buy from is exactly the leak store-preview mode exists to
 * prevent.
 *
 * @return string
 */
function qhta_commerce_cart_button_shortcode() {
	if ( ! qhta_commerce_store_visible() || ! qhta_commerce_woo_active() ) {
		return '';
	}

	ob_start();
	qhta_commerce_cart_button_html();

	return ob_get_clean();
}
add_shortcode( 'qhta_cart_button', 'qhta_commerce_cart_button_shortcode' );

/**
 * Load WooCommerce's cart-fragments script so the count updates without a
 * reload.
 *
 * Woo registers this on every front-end load but only enqueues it on its own
 * pages — and the point of a header button is that it is on all of them.
 *
 * Site-wide while the store is visible, because there is no way to know at
 * enqueue time whether the header will render the shortcode: header widget
 * content is the theme's business and is composed long after wp_enqueue_scripts
 * has run. Hidden store, no script — that is the only case that can be decided
 * here.
 *
 * This is also what makes the count survive full-page caching. The cached HTML
 * carries whatever number the page was cached with; fragments overwrite it per
 * browser from the session, which is why the cache exclusions in the README
 * matter to this feature as well as to preview mode.
 */
function qhta_commerce_cart_fragments_script() {
	if ( ! qhta_commerce_store_visible() || ! qhta_commerce_woo_active() ) {
		return;
	}

	wp_enqueue_script( 'wc-cart-fragments' );
}
add_action( 'wp_enqueue_scripts', 'qhta_commerce_cart_fragments_script' );

/**
 * Load the shopfront stylesheet.
 *
 * Not loaded while the store is hidden: the banner cannot render there and the
 * cart button refuses to, so there is nothing for it to style.
 *
 * Versioned on QHTA_COMMERCE_VERSION, so the bump that ships a style change is
 * also what busts the browser and CDN caches holding the old one. Editing the
 * CSS without bumping the version means the change reaches nobody who has been
 * to the site before.
 */
function qhta_commerce_styles() {
	if ( ! qhta_commerce_store_visible() ) {
		return;
	}

	wp_enqueue_style(
		'qhta-commerce',
		plugins_url( 'assets/qhta-commerce.css', __FILE__ ),
		array(),
		QHTA_COMMERCE_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'qhta_commerce_styles' );

/**
 * Render a flagged menu item as the cart button.
 *
 * This is the answer to "can I just put the shortcode in a menu item's label?" —
 * no. Nav menu labels run through the_title, which has no do_shortcode on it,
 * so the shortcode would appear on the site as literal text. Adding
 * do_shortcode to that filter would fix it for every menu item on the site,
 * which is a much broader change than this needs and turns any label with
 * square brackets into a hazard.
 *
 * Replacing the item's whole output, rather than substituting inside the label,
 * is the other half of the reason. The walker has already opened an anchor by
 * the time a label is filtered, and the cart button is an anchor — nesting one
 * inside the other is invalid HTML that browsers silently tear apart, leaving
 * the button outside the link it was supposed to be. This filter hands over the
 * entire item, anchor included, so there is exactly one.
 *
 * Anything set on the menu item itself — URL, label, target — is discarded. Use
 * a Custom Link with '#' and any label; the label is what the admin screen shows
 * you, and nothing else.
 *
 * The guard is belt to qhta_commerce_filter_menu_items()' braces: that already
 * removed these items when the store is hidden, but it only runs inside
 * wp_nav_menu(), and a theme can render a menu another way. Failing to empty is
 * right here — a hidden store must not leak a cart link.
 *
 * @param string  $item_output The menu item's markup.
 * @param WP_Post $item        The menu item.
 * @return string
 */
function qhta_commerce_cart_button_menu_item( $item_output, $item ) {
	if ( '1' !== get_post_meta( $item->ID, QHTA_CART_BUTTON_META, true ) ) {
		return $item_output;
	}

	if ( ! qhta_commerce_store_visible() || ! qhta_commerce_woo_active() ) {
		return '';
	}

	ob_start();
	qhta_commerce_cart_button_html();

	return ob_get_clean();
}
add_filter( 'walker_nav_menu_start_el', 'qhta_commerce_cart_button_menu_item', 10, 2 );

/**
 * Refresh the button on AJAX add-to-cart.
 *
 * The array key is a jQuery selector for the node to replace, so it has to
 * match the button's outer element exactly — 'a.qhta-cart-button'. Change the
 * tag or the class in qhta_commerce_cart_button_html() and this string changes
 * with it, or the count silently stops updating while everything still renders.
 *
 * The modifier class on the empty state is on a second class deliberately: a
 * selector of 'a.qhta-cart-button' still matches a node carrying both, so the
 * button that is currently empty is still findable when the first item lands.
 *
 * @param array $fragments Selector => markup.
 * @return array
 */
function qhta_commerce_cart_button_fragment( $fragments ) {
	if ( ! qhta_commerce_store_visible() ) {
		return $fragments;
	}

	ob_start();
	qhta_commerce_cart_button_html();

	$fragments['a.qhta-cart-button'] = ob_get_clean();

	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'qhta_commerce_cart_button_fragment' );


/* -------------------------------------------------------------------------
 * 6. Checkout tweaks
 *
 * Adjustments to the WooCommerce store checkout and account: phone gone — from
 * the form *and* from what Stripe asks for, which are two different levers (see
 * qhta_commerce_checkout_phone_mode) — order notes gone, heading included,
 * password strength down to medium, help text on the account username field,
 * "Company name" relabelled to "School or Institution" and made required, and a
 * notice at the top of the form saying access arrives by email.
 *
 * These live here rather than in qhta-membership under the checkout-split rule:
 * a checkout tweak is homed by *which checkout it acts on*. These four act on
 * the WooCommerce store checkout (woocommerce_checkout_fields, order_comments,
 * Woo's password strength), so they are this plugin's. Anything keyed off
 * pmpro_is_checkout() or .pmpro_form is qhta-membership's, even when it does the
 * same thing to the same-looking field.
 *
 * Classic vs block, which decides how much of this is code at all:
 * woocommerce_checkout_fields is the *classic* (shortcode) checkout's field
 * array and does nothing on the block checkout, which builds its fields from
 * settings and block attributes instead. **qhta.com.au runs the classic
 * checkout** — confirmed on the live site, 8 August 2026 — so the field tweaks
 * below are the working code path, not a fallback.
 *
 * If the site is ever moved to the block checkout, three of the four stop
 * applying and become admin steps instead; the README tabulates them. Forcing
 * the underlying options from here would cover that, and is deliberately not
 * done — it would leave the settings screen saying one thing while the checkout
 * did another. The password strength filter is unaffected either way; it feeds
 * Woo's strength meter, not the field array.
 *
 * Which checkout is in use: Pages -> Checkout -> Edit. A "Checkout" block is
 * block-based; a [woocommerce_checkout] shortcode is classic.
 *
 * No WooCommerce guard on any of these hooks. Three are Woo's own filters, so
 * with WooCommerce inactive they never fire and no Woo function is called. The
 * fourth is WordPress core's pre_option_ hook, which does fire without
 * WooCommerce — and answers a question about a WooCommerce option that, with
 * WooCommerce gone, nothing is left to ask.
 * ---------------------------------------------------------------------- */

/**
 * What the checkout does with the phone field: 'required', 'optional', 'hidden'.
 *
 * One switch, because the phone field has **two** consumers and getting only one
 * of them is what broke checkout in 1.5.1.
 *
 * Removing the field from the checkout form left the Stripe gateway still asking
 * Stripe to collect a number, and the buyer hit a dead end at payment:
 *
 *   "A phone number is required to confirm this Checkout Session. Provide a
 *    phone number using updatePhoneNumber() or pass phoneNumber to confirm()."
 *
 * A Stripe.js error, not a WooCommerce one — worse than the asterisk it replaced,
 * because a buyer can neither pay nor see why. The gateway builds its Checkout
 * Session from an *option*, not from the filtered field array
 * (class-wc-stripe-checkout-sessions-ajax-handler.php):
 *
 *   if ( 'required' === get_option( 'woocommerce_checkout_phone_field', 'required' ) ) {
 *       $request['phone_number_collection'] = [ 'enabled' => 'true' ];
 *   }
 *
 * which no priority on woocommerce_checkout_fields can reach. So this mode drives
 * both: the field array below, and that option via the filter underneath.
 *
 * The option is safe for this plugin to answer for, which is not usually true of
 * forcing a setting from code. In WooCommerce core it is read **only by the
 * Blocks checkout**, and its only UI is the Checkout *block* editor — on this
 * site's classic checkout nothing reads it and nothing has ever written it, so
 * it sits at its 'required' default with no screen anywhere disagreeing. It is
 * effectively a Stripe-facing setting with no owner, and now it has one.
 *
 * The three modes, and what each does to each consumer:
 *
 *   'hidden'   field removed from the checkout; Stripe not asked for a number.
 *   'optional' field kept, required flag dropped; Stripe not asked for a number.
 *   'required' nothing touched — Woo's own behaviour, Stripe collects as before.
 *
 * Anything else is treated as 'required', i.e. as "leave it alone".
 *
 * Filterable, so it moves without a deploy:
 *
 *   add_filter( 'qhta_commerce_checkout_phone_mode', function () {
 *       return 'optional';
 *   } );
 *
 * This is also what was really behind the oddity 1.5.1 worked around by moving to
 * priority 9999: the phone field kept coming back required because the *option*
 * still said required. The high priority is now belt to this braces, and costs
 * nothing.
 *
 * If the checkout is ever rebuilt as blocks, note that this stops being an
 * ownerless setting — the block editor shows a control for it, and the filter
 * below would silently override what that control says.
 *
 * @return string One of 'required', 'optional', 'hidden'.
 */
function qhta_commerce_checkout_phone_mode() {
	return apply_filters( 'qhta_commerce_checkout_phone_mode', 'hidden' );
}

/**
 * Tell WooCommerce's phone-field option what the mode says.
 *
 * The half of the switch that reaches Stripe. The gateway reads
 * woocommerce_checkout_phone_field directly to decide whether to enable phone
 * number collection on the Checkout Session, so this is the only way to answer
 * it — the checkout field array it never looks at.
 *
 * pre_option_ rather than option_ because the option does not exist in the
 * database: get_option() returns a missing option through the default_option_
 * filter and never applies option_ at all, so an option_ callback here would
 * simply never run. pre_option_ short-circuits whether or not the row is there.
 *
 * Only 'optional' and 'hidden' answer. 'required' — including anything
 * unrecognised, which normalises to it — returns $pre untouched so WordPress
 * does its ordinary lookup, leaving a stored value (if one ever appears) to win.
 * "Required" here means "leave everything alone", not "assert required".
 *
 * Both values read the same to the gateway, which only tests for 'required'.
 * They differ for the block checkout, which is the other reader of this option
 * and would hide or merely un-require the field accordingly — so the value
 * carries through honestly rather than collapsing to one.
 *
 * @param mixed  $pre     Short-circuit value; false means "not short-circuited".
 * @param string $option  Option name (unused — the hook is option-specific).
 * @param mixed  $default Default get_option() was called with (unused).
 * @return mixed
 */
function qhta_commerce_checkout_phone_field_option( $pre, $option = '', $default = false ) {
	$mode = qhta_commerce_checkout_phone_mode();

	if ( 'hidden' === $mode || 'optional' === $mode ) {
		return $mode;
	}

	return $pre;
}
add_filter( 'pre_option_woocommerce_checkout_phone_field', 'qhta_commerce_checkout_phone_field_option', 10, 3 );

/**
 * Help text under the account username field on the classic checkout.
 *
 * Its own function, and filterable, so the wording can change without a deploy —
 * the same treatment qhta_commerce_member_banner_text() gets, and for the same
 * reason. Woo runs the description through wp_kses_post() on output, so a filter
 * can return links and emphasis but not script.
 *
 *   add_filter( 'qhta_commerce_account_username_description', function () {
 *       return 'Pick a username — it is how you get back in.';
 *   } );
 *
 * Accessibility caveat, and it is WooCommerce's rather than ours: Woo renders
 * field descriptions in a span carrying aria-hidden="true", so a screen reader
 * does not read this out. It is genuinely supplementary here — the field is
 * still labelled "Account username" — but it means the help text cannot be the
 * only place something important is said.
 *
 * @return string
 */
function qhta_commerce_account_username_description() {
	$text = __( "Choose a username — you'll use it (or your email) to log in and access your recordings anytime.", 'qhta-commerce' );

	return apply_filters( 'qhta_commerce_account_username_description', $text );
}

/**
 * Phone per the mode above, order notes removed, username explained, company
 * relabelled and required.
 *
 * Priority 9999, not the 20 this shipped with. At 20 the phone field came back
 * marked required on the live checkout while the username description — set in
 * the same pass — took fine, so the array was being changed again after us
 * (most likely the Stripe gateway; see qhta_commerce_checkout_phone_mode()).
 * Running last is the cheap fix, and it matters more when the mode is 'hidden':
 * anything re-asserting a *key* on a field this function has unset would
 * recreate that field as a bare `array( 'required' => true )`, which renders as
 * an unlabelled text input rather than as nothing.
 *
 * Phone is only half done here. This removes the field from the form; the option
 * filter above is what stops Stripe demanding a number the form no longer
 * collects. One without the other is what broke checkout in 1.5.1 — see
 * qhta_commerce_checkout_phone_mode().
 *
 * Order notes have no such complication and go outright — no fulfilment step to
 * instruct, so the field asked for something nobody reads. Removing the field
 * here is only half of it; the section's "Additional information" heading is
 * rendered from an option, not from the field, and needs the filter below.
 *
 * Every touch is guarded by isset(). The account fields only exist when Woo is
 * configured to collect them: account_username disappears the moment "Generate
 * account login" is switched on, and the whole account group disappears for a
 * logged-in buyer. Guarding means those cases are no-ops rather than notices.
 *
 * Classic checkout only — confirmed to be what this site runs — see the section
 * note above.
 *
 * @param array $fields Woo's checkout field array, keyed by group.
 * @return array
 */
function qhta_commerce_checkout_fields( $fields ) {
	// 1. Phone: whatever the mode says, and 'required' means leave Woo's own
	// setting alone rather than assert one over it.
	if ( isset( $fields['billing']['billing_phone'] ) ) {
		$phone_mode = qhta_commerce_checkout_phone_mode();

		if ( 'hidden' === $phone_mode ) {
			unset( $fields['billing']['billing_phone'] );
		} elseif ( 'optional' === $phone_mode ) {
			$fields['billing']['billing_phone']['required'] = false;
		}
	}

	// 2. Order notes: gone. The heading above them is the filter below.
	if ( isset( $fields['order']['order_comments'] ) ) {
		unset( $fields['order']['order_comments'] );
	}

	// 3. Say what the account is for. Buyers read "username" as one more form
	// field; it is actually how they reach what they just paid for.
	if ( isset( $fields['account']['account_username'] ) ) {
		$fields['account']['account_username']['description'] = qhta_commerce_account_username_description();
	}

	// 4. Company becomes School or Institution, and becomes required. Teachers
	// buy in an institutional context, so it is a wanted detail rather than the
	// optional afterthought "Company name" reads as — and it prints on the tax
	// invoice, which is the reason it has to be reliable.
	if ( isset( $fields['billing']['billing_company'] ) ) {
		$fields['billing']['billing_company']['label']       = __( 'School or Institution', 'qhta-commerce' );
		$fields['billing']['billing_company']['placeholder'] = __( 'Your school or institution', 'qhta-commerce' );
		$fields['billing']['billing_company']['required']    = true;
	}

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'qhta_commerce_checkout_fields', 9999 );

/**
 * Take the "Additional information" heading with the notes field.
 *
 * Unsetting order_comments empties the section but leaves its heading behind:
 * Woo's form-shipping.php template prints the <h3> inside a check of *this*
 * filter — which defaults to the woocommerce_enable_order_comments option — and
 * never asks whether any field survived. So the checkout showed a heading with
 * nothing under it.
 *
 * This is the switch for the whole section, heading included, which makes the
 * unset above technically redundant on the classic checkout. Both stay: this one
 * governs what that template renders, the unset governs what is in the field
 * array, and anything else reading get_checkout_fields( 'order' ) directly sees
 * the field gone rather than merely unrendered.
 *
 * Returning false rather than setting the option, because the option is the
 * block checkout's order-note toggle: writing it would move a control the block
 * editor owns and shows the state of.
 *
 * @return bool
 */
function qhta_commerce_disable_order_notes() {
	return false;
}
add_filter( 'woocommerce_enable_order_notes_field', 'qhta_commerce_disable_order_notes' );

/**
 * Relax the password requirement from strong to medium.
 *
 * WooCommerce's scale, straight from zxcvbn: 0 any, 1 weak, 2 medium, 3 strong
 * (Woo's default), 4 very strong.
 *
 * Medium still rejects the passwords that actually get accounts taken over —
 * 'password', '123456', the buyer's own email — while removing a wall that stops
 * people mid-purchase. The strength meter still shows and still says "medium";
 * this changes what is *accepted*, not what is displayed.
 *
 * Not tied to the classic checkout: the filter feeds Woo's password-strength
 * meter script, so it applies wherever that meter runs — My Account -> Account
 * details, the set-password link after checkout, lost-password reset, and the
 * classic checkout's account password field. The block checkout does not render
 * a password field by default (buyers get a set-password link by email), so
 * there is nothing there to relax, and the set-password screen it sends them to
 * is covered.
 *
 * Woo's own filter rather than one of ours wrapped around it: anything wanting a
 * different number can add_filter() on the same hook at a later priority, which
 * is the standard way in and needs nothing from this plugin.
 *
 * @return int
 */
function qhta_commerce_min_password_strength() {
	return 2;
}
add_filter( 'woocommerce_min_password_strength', 'qhta_commerce_min_password_strength' );

/**
 * The checkout's "how you get your resources" notice.
 *
 * Filterable for wording without a deploy, and passed through wp_kses_post() on
 * output, so a filter can return links and emphasis but not script — the same
 * treatment qhta_commerce_member_banner_text() gets.
 *
 * The tab is named by qhta_commerce_content_label() rather than written out, so
 * renaming My Content renames it here too instead of leaving the checkout
 * pointing at a tab that no longer goes by that name.
 *
 * @return string HTML.
 */
function qhta_commerce_email_notice_text() {
	$text = sprintf(
		/* translators: 1: opening <strong> tag. 2: name of the My Account tab holding purchased content. 3: closing </strong> tag. */
		__( 'After payment, we&rsquo;ll email you a link to access your resources — and they&rsquo;ll always be available under %1$s%2$s%3$s in your account.', 'qhta-commerce' ),
		'<strong>',
		esc_html( qhta_commerce_content_label() ),
		'</strong>'
	);

	return apply_filters( 'qhta_commerce_email_notice_text', $text );
}

/**
 * Say at the top of the checkout that access arrives by email.
 *
 * Sets the expectation before the buyer pays, which is the cheapest way to stop
 * the "where is my product?" email that a digital purchase otherwise invites.
 *
 * Top of the form rather than above the Place Order button — Tim's call. The
 * trade is real: above the button it is read at the moment of paying and cannot
 * be scrolled past, whereas here it is read first and may be scrolled past. Move
 * it by swapping the hook below for woocommerce_review_order_before_submit.
 *
 * Scoped by the hook: woocommerce_before_checkout_form fires inside Woo's own
 * checkout template only, so nothing is emitted on any other page — not the cart,
 * not the pay-for-order page, not order-received.
 *
 * Structure and class only. Appearance belongs in qhta-theme-extras, per this
 * spec; see the README for how that sits with the shopfront CSS carve-out.
 */
function qhta_commerce_email_notice() {
	$text = qhta_commerce_email_notice_text();

	if ( ! $text ) {
		return;
	}

	printf( '<p class="qhta-email-notice">%s</p>', wp_kses_post( $text ) );
}
add_action( 'woocommerce_before_checkout_form', 'qhta_commerce_email_notice' );


/* -------------------------------------------------------------------------
 * 7. Thank-you page — "Access your resources"
 *
 * The inverse of the My Content tab in section 3. That answers "everything this
 * customer owns"; this answers "what this one order just bought", by walking the
 * order's products back to the pages gated against them through the same
 * QHTA_GATE_META mapping.
 *
 * So gating a new page puts it on the thank-you page of every order containing
 * its product, with no code change — the same property the tab has, for the same
 * reason.
 *
 * Structure and classes only, no CSS. Appearance belongs in qhta-theme-extras
 * per this spec, which is a departure from the carve-out in section 5 — see the
 * README.
 * ---------------------------------------------------------------------- */

/**
 * Published gated pages unlocked by any of these products.
 *
 * The reverse of the per-page lookup the gate does: given products, find pages,
 * rather than given a page, find its product.
 *
 * A value comparison rather than the EXISTS that qhta_commerce_accessible_pages()
 * uses, because here we know which products to match — and 0 is filtered out on
 * the way in so a page storing 0 (public, per qhta_commerce_save_meta_box) can
 * never be matched by an order that happens to contain product 0.
 *
 * @param int[] $product_ids Product IDs from an order.
 * @return int[] Page IDs, ordered by title.
 */
function qhta_commerce_pages_for_products( $product_ids ) {
	$product_ids = array_filter( array_map( 'absint', (array) $product_ids ) );

	if ( empty( $product_ids ) ) {
		return array();
	}

	return get_posts(
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
					'value'   => array_values( $product_ids ),
					'compare' => 'IN',
				),
			),
		)
	);
}

/**
 * Gated pages this order bought that the buyer can actually open.
 *
 * Two steps, and the second is the important one: every candidate page is put
 * through qhta_commerce_is_entitled() — the same question the gate asks — so a
 * link can never be offered that the gate would then refuse. A thank-you page
 * that hands someone a link to a redirect is worse than one that stays quiet.
 *
 * That coupling has a consequence worth knowing: **an order that has not reached
 * a paid status yet shows nothing**, because wc_customer_bought_product() only
 * matches completed and processing. With a card payment the order is already
 * processing by the time this renders. With a slower method it would not be, and
 * the buyer would see no links here — correctly, since the gate would have
 * turned them away — and would find the pages under My Content once the payment
 * lands.
 *
 * Guest orders show nothing for the same reason: entitlement is checked against
 * the logged-in user, and a guest cannot pass the gate whatever this printed.
 * Buyers who create an account at checkout — the normal path here — are logged in
 * by the time they arrive.
 *
 * Variations need no special handling: get_product_id() returns the parent, which
 * is what pages are gated against.
 *
 * @param WC_Order $order Order to look up.
 * @return int[] Page IDs, ordered by title.
 */
function qhta_commerce_order_resource_pages( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return array();
	}

	$product_ids = array();

	foreach ( $order->get_items() as $item ) {
		$product_ids[] = (int) $item->get_product_id();
	}

	$pages      = qhta_commerce_pages_for_products( array_unique( $product_ids ) );
	$accessible = array();

	foreach ( $pages as $page_id ) {
		$product_id = (int) get_post_meta( $page_id, QHTA_GATE_META, true );

		if ( $product_id > 0 && qhta_commerce_is_entitled( $product_id, $page_id ) ) {
			$accessible[] = $page_id;
		}
	}

	return $accessible;
}

/**
 * The section's heading.
 *
 * @return string
 */
function qhta_commerce_access_resources_heading() {
	return apply_filters( 'qhta_commerce_access_resources_heading', __( 'Access your resources', 'qhta-commerce' ) );
}

/**
 * Print the links on the thank-you page.
 *
 * Priority 5 so this lands *above* the order table: WooCommerce hooks
 * woocommerce_order_details_table onto the same action at 10, and what a buyer
 * wants first is the way in to what they just bought, not a summary of what they
 * paid. Raise the number past 10 to put it below the table instead.
 *
 * Nothing renders when the order has no gated products, which is the ordinary
 * case for any future non-gated product, so no empty heading appears.
 *
 * Deliberately does not touch the product's Purchase Note — Tim's call — so a
 * product carrying its own link still shows it. Worth a look on a real order if
 * both end up saying the same thing.
 *
 * The `button` class is WooCommerce's own, so the links pick up whatever the
 * theme already gives Woo buttons; everything else is a qhta- class for
 * qhta-theme-extras to style.
 *
 * @param int $order_id Order just placed.
 */
function qhta_commerce_thankyou_resources( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	$pages = qhta_commerce_order_resource_pages( $order );

	if ( empty( $pages ) ) {
		return;
	}

	?>
	<section class="qhta-access-resources">
		<h2 class="qhta-access-resources__title"><?php echo esc_html( qhta_commerce_access_resources_heading() ); ?></h2>
		<ul class="qhta-access-resources__list">
			<?php foreach ( $pages as $page_id ) : ?>
				<li class="qhta-access-resources__item">
					<a class="button qhta-access-resources__link" href="<?php echo esc_url( get_permalink( $page_id ) ); ?>">
						<?php echo esc_html( get_the_title( $page_id ) ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php
}
add_action( 'woocommerce_thankyou', 'qhta_commerce_thankyou_resources', 5 );


/* -------------------------------------------------------------------------
 * 8. Admin notices
 *
 * Both states this plugin can be in that look fine from wp-admin and are not:
 * a gate that is not being enforced, and a store the public cannot see.
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

/**
 * Say in wp-admin that the store is hidden.
 *
 * wp-admin looks the same either way — the products, orders and pages are all
 * there whether or not the public can reach any of it — which is exactly how a
 * launch gets forgotten, or how "the shop is broken for customers" gets
 * reported weeks later. This is the only thing that tells the difference.
 *
 * Keyed off the live constant directly rather than qhta_commerce_store_visible():
 * an administrator holding a preview cookie is still looking at a store the
 * public cannot see, and that is precisely when this needs saying.
 */
function qhta_commerce_store_hidden_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( defined( 'QHTA_STORE_LIVE' ) && QHTA_STORE_LIVE ) {
		return;
	}
	?>
	<div class="notice notice-info">
		<p>
			<strong><?php esc_html_e( 'QHTA Commerce:', 'qhta-commerce' ); ?></strong>
			<?php esc_html_e( 'The store is hidden — store nav links are removed and store pages redirect away, for everyone including you. To look at it, use the preview link with the QHTA_STORE_PREVIEW_TOKEN secret. Set QHTA_STORE_LIVE to true in wp-config.php to go live.', 'qhta-commerce' ); ?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'qhta_commerce_store_hidden_notice' );
