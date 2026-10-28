<?php
/**
 * Offline payment gateways — Events purchases only.
 *
 * Schools buying conference tickets pay by invoice, so Direct bank transfer
 * (bacs) and Check payments (cheque) are enabled in WooCommerce. Gateways are
 * store-wide, though: left alone, a study-package buyer would be offered bank
 * transfer too, and their order would sit On hold with its gated content locked
 * until somebody marked it Completed by hand.
 *
 * So the offline gateways are removed unless the purchase holds at least one
 * product in an Events category. Stripe is never touched.
 *
 * Scoping — the exception to "every hook is page-scoped". This filter is NOT
 * gated on is_checkout(): block checkout loads its gateways through the Store
 * API, a REST request where is_checkout() is false, so gating on it would leave
 * bank transfer showing for study-package buyers on block checkout. It is
 * scoped by context instead:
 *
 *   admin screens  untouched, so Settings -> Payments lists every gateway;
 *   order-pay      read the order being paid — there is no cart;
 *   everything     read WC()->cart (classic checkout, Store API, the
 *   else           update_order_review AJAX), and leave the list alone when
 *                  there is no cart or it is empty.
 *
 * Pure PHP — no markup, no script.
 *
 * @package QHTA_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product category slugs whose presence in a purchase allows offline payment.
 *
 * Filterable, so a second category can qualify without editing the plugin:
 *
 *   add_filter( 'qhta_commerce_offline_gateway_categories', function ( $slugs ) {
 *       $slugs[] = 'workshops';
 *       return $slugs;
 *   } );
 *
 * @return string[]
 */
function qhta_commerce_offline_gateway_categories() {
	return (array) apply_filters( 'qhta_commerce_offline_gateway_categories', array( 'events' ) );
}

/**
 * Is this product in an Events category?
 *
 * The single test for "is this a ticket" — the gateway filter, the dietary
 * field, the checkout notices and the attendees report all ask here, so the
 * category list is read in one place. Pass the parent ID for a variation;
 * variations have no categories of their own.
 *
 * @param int $product_id Product (parent) ID.
 * @return bool
 */
function qhta_commerce_is_event_product( $product_id ) {
	return has_term( qhta_commerce_offline_gateway_categories(), 'product_cat', (int) $product_id );
}

/**
 * Gateway IDs treated as offline, i.e. removed from non-Events purchases.
 *
 * @return string[]
 */
function qhta_commerce_offline_gateway_ids() {
	return (array) apply_filters( 'qhta_commerce_offline_gateway_ids', array( 'bacs', 'cheque' ) );
}

/**
 * Parent product IDs in the current purchase, or null if there is none.
 *
 * Parent, not variation: a variable product's cart line carries the variation
 * in variation_id, and variations have no categories of their own. product_id
 * on a cart line, and get_product_id() on an order item, are the parent for a
 * variation and the product itself otherwise.
 *
 * @return int[]|null
 */
function qhta_commerce_current_purchase_product_ids() {
	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
		$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );

		if ( ! $order ) {
			return null;
		}

		$ids = array();
		foreach ( $order->get_items() as $item ) {
			$ids[] = (int) $item->get_product_id();
		}

		return $ids;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return null;
	}

	$ids = array();
	foreach ( WC()->cart->get_cart() as $item ) {
		$ids[] = (int) $item['product_id'];
	}

	return $ids;
}

/**
 * Drop the offline gateways unless the purchase holds an Events product.
 *
 * A mixed cart — ticket plus study package — keeps them: one Events item is
 * enough. The study package in that order then stays locked until the order is
 * marked Completed, same as the ticket.
 *
 * @param array $gateways Gateway ID => WC_Payment_Gateway.
 * @return array
 */
function qhta_commerce_limit_offline_gateways( $gateways ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $gateways;
	}

	$product_ids = qhta_commerce_current_purchase_product_ids();

	// No cart or order to judge by — leave the list as WooCommerce built it.
	if ( null === $product_ids ) {
		return $gateways;
	}

	foreach ( $product_ids as $product_id ) {
		if ( qhta_commerce_is_event_product( $product_id ) ) {
			return $gateways;
		}
	}

	foreach ( qhta_commerce_offline_gateway_ids() as $gateway_id ) {
		unset( $gateways[ $gateway_id ] );
	}

	return $gateways;
}
add_filter( 'woocommerce_available_payment_gateways', 'qhta_commerce_limit_offline_gateways' );
