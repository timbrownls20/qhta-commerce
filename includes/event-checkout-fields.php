<?php
/**
 * Event checkout fields — dietary requirements.
 *
 * Adds "Dietary requirements" to the classic checkout when the cart contains an
 * Events-category product (e.g. Early Careers Conference, product 4311):
 *   - a required choice from a preset list (radio buttons), and
 *   - a details box, required when "Other / allergy" is chosen.
 *
 * Saved to order meta, shown in wp-admin and in order emails:
 *   _qhta_dietary_choice   option key, e.g. "vegetarian"
 *   _qhta_dietary_details  free text
 *   _qhta_dietary          combined display string, e.g. "Other / allergy: nut
 *                          allergy" — the one the admin screen, emails and the
 *                          attendees report read
 *
 * One box per order, not per attendee — the placeholder asks bookers to list
 * each person. Classic checkout only: the block checkout ignores these hooks.
 *
 * The option list is filterable: qhta_dietary_options.
 *
 * @package QHTA_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preset dietary options, key => label.
 *
 * "none" and "other" are special: "other" makes the details box required.
 *
 * @return array
 */
function qhta_dietary_options() {
	return apply_filters(
		'qhta_dietary_options',
		array(
			'none'        => __( 'No special requirements', 'qhta-commerce' ),
			'vegetarian'  => __( 'Vegetarian', 'qhta-commerce' ),
			'vegan'       => __( 'Vegan', 'qhta-commerce' ),
			'gluten_free' => __( 'Gluten free', 'qhta-commerce' ),
			'dairy_free'  => __( 'Dairy free', 'qhta-commerce' ),
			'halal'       => __( 'Halal', 'qhta-commerce' ),
			'other'       => __( 'Other / allergy (please give details)', 'qhta-commerce' ),
		)
	);
}

/**
 * True if the current cart contains a product in an Events category.
 *
 * Checks the parent product ID, so variations (4312, 4313) resolve to 4311.
 *
 * @return bool
 */
function qhta_commerce_cart_has_event() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return false;
	}

	foreach ( WC()->cart->get_cart() as $item ) {
		if ( qhta_commerce_is_event_product( $item['product_id'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * True if the current cart contains at least one product NOT in an Events
 * category — i.e. something that unlocks content under My Content.
 *
 * False for an empty cart, and for a cart of tickets only.
 *
 * @return bool
 */
function qhta_commerce_cart_has_non_event() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return false;
	}

	foreach ( WC()->cart->get_cart() as $item ) {
		if ( ! qhta_commerce_is_event_product( $item['product_id'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Render the fields.
 *
 * Hooked after customer details rather than into the "order" field group,
 * because that group is hidden when order notes are off — which they are, see
 * the checkout tweaks.
 *
 * @param WC_Checkout|null $checkout Checkout object, when the hook passes one.
 */
function qhta_commerce_render_dietary_field( $checkout = null ) {
	if ( ! qhta_commerce_cart_has_event() ) {
		return;
	}

	$checkout = $checkout ? $checkout : WC()->checkout();

	echo '<div class="qhta-event-fields"><h3>' . esc_html__( 'Conference details', 'qhta-commerce' ) . '</h3>';

	woocommerce_form_field(
		'qhta_dietary_choice',
		array(
			'type'     => 'radio',
			'label'    => __( 'Dietary requirements', 'qhta-commerce' ),
			'required' => true,
			'options'  => qhta_dietary_options(),
			'class'    => array( 'form-row-wide' ),
		),
		$checkout->get_value( 'qhta_dietary_choice' )
	);

	woocommerce_form_field(
		'qhta_dietary_details',
		array(
			'type'        => 'textarea',
			'label'       => __( 'Details', 'qhta-commerce' ),
			'placeholder' => __( 'Allergies or other needs. If booking for several people, list each name and their requirements.', 'qhta-commerce' ),
			'required'    => false,
			'class'       => array( 'form-row-wide' ),
		),
		$checkout->get_value( 'qhta_dietary_details' )
	);

	echo '</div>';
}
add_action( 'woocommerce_checkout_after_customer_details', 'qhta_commerce_render_dietary_field' );

/**
 * Validate: a known choice, and details when the choice is "other".
 */
function qhta_commerce_validate_dietary_field() {
	if ( ! qhta_commerce_cart_has_event() ) {
		return;
	}

	$options = qhta_dietary_options();
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked by WooCommerce checkout.
	$choice  = isset( $_POST['qhta_dietary_choice'] ) ? sanitize_key( wp_unslash( $_POST['qhta_dietary_choice'] ) ) : '';
	$details = isset( $_POST['qhta_dietary_details'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['qhta_dietary_details'] ) ) ) : '';
	// phpcs:enable

	if ( '' === $choice || ! isset( $options[ $choice ] ) ) {
		wc_add_notice( __( 'Please choose a dietary requirement option.', 'qhta-commerce' ), 'error' );
		return;
	}

	if ( 'other' === $choice && '' === $details ) {
		wc_add_notice( __( 'Please give details of your dietary requirements or allergy.', 'qhta-commerce' ), 'error' );
	}
}
add_action( 'woocommerce_checkout_process', 'qhta_commerce_validate_dietary_field' );

/**
 * Save to order meta.
 *
 * Through the order object rather than post meta, so it works with HPOS and
 * legacy storage alike.
 *
 * @param WC_Order $order Order being created.
 * @param array    $data  Posted checkout data (unused).
 */
function qhta_commerce_save_dietary_field( $order, $data ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked by WooCommerce checkout.
	if ( ! isset( $_POST['qhta_dietary_choice'] ) ) {
		return;
	}

	$options = qhta_dietary_options();
	$choice  = sanitize_key( wp_unslash( $_POST['qhta_dietary_choice'] ) );
	$details = isset( $_POST['qhta_dietary_details'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['qhta_dietary_details'] ) ) ) : '';
	// phpcs:enable

	if ( ! isset( $options[ $choice ] ) ) {
		return;
	}

	$label   = $options[ $choice ];
	$display = '' !== $details ? $label . ': ' . $details : $label;

	$order->update_meta_data( '_qhta_dietary_choice', $choice );
	$order->update_meta_data( '_qhta_dietary_details', $details );
	$order->update_meta_data( '_qhta_dietary', $display );
}
add_action( 'woocommerce_checkout_create_order', 'qhta_commerce_save_dietary_field', 10, 2 );

/**
 * Show on the wp-admin order screen, after the billing address.
 *
 * @param WC_Order $order Order being edited.
 */
function qhta_commerce_admin_dietary_field( $order ) {
	$value = $order->get_meta( '_qhta_dietary' );

	if ( '' === $value ) {
		return;
	}

	echo '<p><strong>' . esc_html__( 'Dietary requirements:', 'qhta-commerce' ) . '</strong><br>' . nl2br( esc_html( $value ) ) . '</p>';
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'qhta_commerce_admin_dietary_field' );

/**
 * Include in order emails, customer and admin.
 *
 * @param array    $fields        Meta fields shown in the email.
 * @param bool     $sent_to_admin Whether the email goes to the admin (unused).
 * @param WC_Order $order         Order the email is about.
 * @return array
 */
function qhta_commerce_email_dietary_field( $fields, $sent_to_admin, $order ) {
	$value = $order->get_meta( '_qhta_dietary' );

	if ( '' !== $value ) {
		$fields['qhta_dietary'] = array(
			'label' => __( 'Dietary requirements', 'qhta-commerce' ),
			'value' => $value,
		);
	}

	return $fields;
}
add_filter( 'woocommerce_email_order_meta_fields', 'qhta_commerce_email_dietary_field', 10, 3 );
