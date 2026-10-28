<?php
/**
 * Event pages — a ticket product's buyers live on a page, not the product page.
 *
 * Conference tickets are sold from an Elementor page (the conference page)
 * rather than from WooCommerce's product page, so a ticket product carries an
 * "Event page" and the store's own routes are pointed at it:
 *
 *   /product/<slug>/           302 to the event page (GET only)
 *   add to cart                straight to checkout, not back to the form
 *   "Continue shopping"        to the event page of a ticket in the cart
 *   "Return to shop" (empty)   to the event page of the last product added, if
 *                              it had one
 *
 * Data-driven, the same way the gate is: the product -> page pairing is product
 * meta set in the editor, _qhta_event_page_id, a page ID so it survives slug
 * changes. A product with no event page behaves exactly as before. Nothing here
 * names a product or a URL.
 *
 * @package QHTA_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product meta holding the ID of the page a ticket is sold from.
 */
const QHTA_EVENT_PAGE_META = '_qhta_event_page_id';

/**
 * Permalink of a product's event page, or '' if it has none.
 *
 * A variation resolves to its parent, which is where the field lives. Only a
 * published page counts — a draft or binned page would 404 every redirect.
 *
 * @param int $product_id Product or variation ID.
 * @return string
 */
function qhta_commerce_event_page_url( $product_id ) {
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

	if ( ! $product ) {
		return '';
	}

	if ( $product->get_parent_id() ) {
		$product = wc_get_product( $product->get_parent_id() );

		if ( ! $product ) {
			return '';
		}
	}

	return qhta_commerce_published_page_url( (int) $product->get_meta( QHTA_EVENT_PAGE_META ) );
}

/**
 * Permalink of a published page, or ''.
 *
 * @param int $page_id Page ID.
 * @return string
 */
function qhta_commerce_published_page_url( $page_id ) {
	if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
		return '';
	}

	return (string) get_permalink( $page_id );
}

/* -------------------------------------------------------------------------
 * The "Event page" field — Product data -> General
 * ---------------------------------------------------------------------- */

/**
 * Render the event fields: Event page, Ask dietary requirements, Dietary options.
 *
 * The dietary pair is per event — see includes/event-checkout-fields.php for
 * what checkout does with them.
 */
function qhta_commerce_event_page_field() {
	global $product_object;

	$selected = $product_object ? (int) $product_object->get_meta( QHTA_EVENT_PAGE_META ) : 0;

	echo '<div class="options_group"><p class="form-field">';
	printf(
		'<label for="%1$s">%2$s</label>',
		esc_attr( QHTA_EVENT_PAGE_META ),
		esc_html__( 'Event page', 'qhta-commerce' )
	);

	// Flat, not hierarchical: get_pages() in hierarchical mode keeps only pages
	// whose whole ancestor chain is in the result, so a published page under a
	// draft or private parent (the conference page, under What's On) silently
	// vanished from the list. Flat lists every published page; the parent's
	// title is appended to tell same-named pages apart.
	add_filter( 'list_pages', 'qhta_commerce_event_page_option_label', 10, 2 );

	wp_dropdown_pages(
		array(
			'name'              => esc_attr( QHTA_EVENT_PAGE_META ),
			'id'                => esc_attr( QHTA_EVENT_PAGE_META ),
			'selected'          => absint( $selected ),
			'show_option_none'  => esc_html__( '— None —', 'qhta-commerce' ),
			'option_none_value' => '0',
			'class'             => 'select short',
			'hierarchical'      => 0,
			'sort_column'       => 'post_title',
			// Explicit, not just the default: the redirect sends buyers here, so
			// a private page is never a valid target.
			'post_status'       => 'publish',
		)
	);

	remove_filter( 'list_pages', 'qhta_commerce_event_page_option_label', 10 );

	echo wc_help_tip( __( 'Ticket buyers are sent here instead of the product page. Leave blank for normal products.', 'qhta-commerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() escapes.
	echo '</p>';

	woocommerce_wp_checkbox(
		array(
			'id'          => QHTA_ASK_DIETARY_META,
			'label'       => __( 'Ask dietary requirements', 'qhta-commerce' ),
			'description' => __( 'Show the dietary question at checkout for this event.', 'qhta-commerce' ),
			'value'       => $product_object && 'yes' === $product_object->get_meta( QHTA_ASK_DIETARY_META ) ? 'yes' : 'no',
			'cbvalue'     => 'yes',
		)
	);

	woocommerce_wp_textarea_input(
		array(
			'id'          => QHTA_DIETARY_OPTIONS_META,
			'label'       => __( 'Dietary options', 'qhta-commerce' ),
			'description' => __( 'One option per line. End a line with * to require details (e.g. Other / allergy *). Leave blank for the default list.', 'qhta-commerce' ),
			'desc_tip'    => true,
			'value'       => $product_object ? (string) $product_object->get_meta( QHTA_DIETARY_OPTIONS_META ) : '',
			'rows'        => 7,
		)
	);

	echo '</div>';
}
add_action( 'woocommerce_product_options_general_product_data', 'qhta_commerce_event_page_field' );

/**
 * Append the parent page's title to an Event page option.
 *
 * Only hooked for the duration of the dropdown above, so no other page list on
 * the site is touched.
 *
 * @param string  $title Option label.
 * @param WP_Post $page  Page the option is for.
 * @return string
 */
function qhta_commerce_event_page_option_label( $title, $page ) {
	if ( empty( $page->post_parent ) ) {
		return $title;
	}

	$parent = get_the_title( $page->post_parent );

	return '' !== $parent ? $title . ' (' . $parent . ')' : $title;
}

/**
 * Save the event fields.
 *
 * Event page: 0 (None) deletes the meta. Ask dietary: 'yes' / 'no' — an
 * unticked checkbox posts nothing, so the Event page field's presence is what
 * says this form was submitted. Dietary options: one per line, trimmed, blanks
 * and duplicates dropped; empty deletes the meta, meaning "default list".
 *
 * WooCommerce verifies its own meta-box nonce before this action fires.
 *
 * @param WC_Product $product Product being saved.
 */
function qhta_commerce_save_event_page_field( $product ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked by WooCommerce's product meta box save.
	if ( ! isset( $_POST[ QHTA_EVENT_PAGE_META ] ) ) {
		return;
	}

	$page_id = absint( wp_unslash( $_POST[ QHTA_EVENT_PAGE_META ] ) );
	$ask     = isset( $_POST[ QHTA_ASK_DIETARY_META ] ) ? 'yes' : 'no';
	$options = isset( $_POST[ QHTA_DIETARY_OPTIONS_META ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ QHTA_DIETARY_OPTIONS_META ] ) ) : '';
	// phpcs:enable

	if ( $page_id ) {
		$product->update_meta_data( QHTA_EVENT_PAGE_META, $page_id );
	} else {
		$product->delete_meta_data( QHTA_EVENT_PAGE_META );
	}

	$product->update_meta_data( QHTA_ASK_DIETARY_META, $ask );

	$lines = array_unique( array_filter( array_map( 'trim', explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $options ) ) ), 'strlen' ) );

	if ( $lines ) {
		$product->update_meta_data( QHTA_DIETARY_OPTIONS_META, implode( "\n", $lines ) );
	} else {
		$product->delete_meta_data( QHTA_DIETARY_OPTIONS_META );
	}
}
add_action( 'woocommerce_admin_process_product_object', 'qhta_commerce_save_event_page_field' );

/* -------------------------------------------------------------------------
 * Routing
 * ---------------------------------------------------------------------- */

/**
 * Send a ticket's product page to its event page.
 *
 * GET only, and never with add-to-cart in the URL: the event page's variation
 * form posts to the product permalink, and an add-to-cart link may point there
 * too — both must reach WooCommerce's form handler untouched.
 *
 * 302, like the gate: the pairing is editable and a 301 would outlive it in
 * browser caches.
 */
function qhta_commerce_redirect_event_product() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	if ( 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
	if ( isset( $_GET['add-to-cart'] ) ) {
		return;
	}

	$url = qhta_commerce_event_page_url( get_queried_object_id() );

	if ( ! $url ) {
		return;
	}

	wp_safe_redirect( $url, 302 );
	exit;
}
add_action( 'template_redirect', 'qhta_commerce_redirect_event_product' );

/**
 * After adding a ticket, go to checkout rather than back to the form.
 *
 * Without this the buyer lands on the product permalink the form posted to,
 * which the redirect above bounces back to the event page — looking as though
 * nothing happened. Anything without an event page keeps Woo's default.
 *
 * @param string $url Redirect WooCommerce would use.
 * @return string
 */
function qhta_commerce_event_add_to_cart_redirect( $url ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; WooCommerce has already added the item.
	$product_id = isset( $_REQUEST['add-to-cart'] ) ? absint( wp_unslash( $_REQUEST['add-to-cart'] ) ) : 0;

	if ( $product_id && qhta_commerce_event_page_url( $product_id ) ) {
		return wc_get_checkout_url();
	}

	return $url;
}
add_filter( 'woocommerce_add_to_cart_redirect', 'qhta_commerce_event_add_to_cart_redirect' );

/**
 * "Continue shopping" goes to the event page of the first ticket in the cart.
 *
 * @param string $url Default destination.
 * @return string
 */
function qhta_commerce_event_continue_shopping( $url ) {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $url;
	}

	foreach ( WC()->cart->get_cart() as $item ) {
		$event_url = qhta_commerce_event_page_url( $item['product_id'] );

		if ( $event_url ) {
			return $event_url;
		}
	}

	return $url;
}
add_filter( 'woocommerce_continue_shopping_redirect', 'qhta_commerce_event_continue_shopping' );

/**
 * Remember the event page, if any, of the product just added.
 *
 * The empty-cart "Return to shop" button has no cart to look at — the ticket
 * that was in it is gone — so the session carries the answer forward.
 *
 * @param string $cart_item_key Cart item key (unused).
 * @param int    $product_id    Parent product ID.
 */
function qhta_commerce_remember_event_page( $cart_item_key, $product_id ) {
	if ( ! WC()->session ) {
		return;
	}

	$product = wc_get_product( $product_id );
	$page_id = $product ? (int) $product->get_meta( QHTA_EVENT_PAGE_META ) : 0;

	// Set or cleared on every add, so the value follows the last thing added:
	// a study package added after a ticket sends an emptied cart back to the
	// shop, not to the conference.
	WC()->session->set( 'qhta_last_event_page', $page_id ? $page_id : null );
}
add_action( 'woocommerce_add_to_cart', 'qhta_commerce_remember_event_page', 10, 2 );

/**
 * "Return to shop" on an empty cart goes to the last added product's event page.
 *
 * @param string $url Default destination (the shop).
 * @return string
 */
function qhta_commerce_event_return_to_shop( $url ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return $url;
	}

	$event_url = qhta_commerce_published_page_url( (int) WC()->session->get( 'qhta_last_event_page' ) );

	return $event_url ? $event_url : $url;
}
add_filter( 'woocommerce_return_to_shop_redirect', 'qhta_commerce_event_return_to_shop' );
