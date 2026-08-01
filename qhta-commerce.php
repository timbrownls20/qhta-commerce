<?php
/**
 * Plugin Name:       QHTA Commerce
 * Description:       WooCommerce-side custom logic for qhta.com.au — purchase-gated content pages driven by a per-page product-ID field. No presentation, no conference domain logic.
 * Version:           1.0.2
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

define( 'QHTA_COMMERCE_VERSION', '1.0.2' );

/**
 * Post meta holding the product ID that unlocks a page.
 *
 * Underscore-prefixed so it stays out of the generic Custom Fields UI — the
 * meta box below is the only intended way to set it.
 */
const QHTA_GATE_META = '_qhta_gate_product_id';

/**
 * Where non-entitled visitors are sent.
 *
 * Filterable so the destination can be moved without editing the plugin:
 *
 *   add_filter( 'qhta_commerce_sales_url', function () {
 *       return home_url( '/some-other-page/' );
 *   } );
 *
 * Defaults to /not-found/ rather than a sales page: a non-buyer should not
 * learn that gated content exists at that URL. Point it at a real sales page if
 * that changes.
 *
 * @return string
 */
function qhta_commerce_sales_url() {
	return apply_filters( 'qhta_commerce_sales_url', home_url( '/not-found/' ) );
}

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
 * @param int $product_id Product that unlocks the page.
 * @param int $page_id    Page being gated.
 * @return bool
 */
function qhta_commerce_is_entitled( $product_id, $page_id ) {
	$entitled = false;

	if ( is_user_logged_in() && qhta_commerce_woo_active() ) {
		$user = wp_get_current_user();

		$entitled = (bool) wc_customer_bought_product(
			$user->user_email,
			$user->ID,
			$product_id
		);
	}

	return (bool) apply_filters( 'qhta_commerce_is_entitled', $entitled, $product_id, $page_id );
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

	$sales_url = qhta_commerce_sales_url();

	// A sales page that gates itself would redirect to itself forever. Fail
	// open on that misconfiguration — a visible unlocked page is easier to
	// diagnose than a redirect loop.
	if ( untrailingslashit( $sales_url ) === untrailingslashit( get_permalink( $page_id ) ) ) {
		return;
	}

	// 302, not 301: entitlement changes. A permanent redirect would be cached
	// by the browser and would keep bouncing the visitor after they bought.
	wp_safe_redirect( $sales_url, 302 );
	exit;
}
add_action( 'template_redirect', 'qhta_commerce_enforce_gate' );


/* -------------------------------------------------------------------------
 * 3. Admin notice when WooCommerce is missing
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
