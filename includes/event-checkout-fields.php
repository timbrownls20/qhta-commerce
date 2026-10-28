<?php
/**
 * Event checkout fields — dietary requirements.
 *
 * Adds "Dietary requirements" to the classic checkout when the cart contains an
 * Events-category product that has "Ask dietary requirements" ticked (product
 * meta _qhta_ask_dietary, set in Product data -> General — unticked by default):
 *   - a required choice from that event's list (radio buttons), and
 *   - a details box, required when the chosen option needs details.
 *
 * The list is the event's own _qhta_dietary_options, one label per line with a
 * trailing * marking "details required", or the default list when that is
 * empty.
 *
 * Saved to order meta, shown in wp-admin and in order emails:
 *   _qhta_dietary_choice   option key (sanitize_title of the label), e.g.
 *                          "gluten-free"
 *   _qhta_dietary_details  free text
 *   _qhta_dietary          combined display string, e.g. "Other / allergy: nut
 *                          allergy" — the one the admin screen, emails and the
 *                          attendees report read
 *
 * One box per order, not per attendee — the placeholder asks bookers to list
 * each person. Classic checkout only: the block checkout ignores these hooks.
 *
 * The default list is filterable, as an array of lines: qhta_dietary_options.
 *
 * @package QHTA_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product meta: whether this event asks the dietary question, 'yes' / 'no'.
 *
 * Unset reads as 'no' — no event asks unless somebody ticks the box.
 */
const QHTA_ASK_DIETARY_META = '_qhta_ask_dietary';

/**
 * Product meta: this event's dietary options, one label per line.
 *
 * A trailing * marks an option that needs details. Empty or unset means the
 * default list.
 */
const QHTA_DIETARY_OPTIONS_META = '_qhta_dietary_options';

/**
 * The default option lines, used when an event sets none of its own.
 *
 * Filterable as an array of lines, in the same format as the product field:
 *
 *   add_filter( 'qhta_dietary_options', function ( $lines ) {
 *       $lines[] = 'Kosher';
 *       return $lines;
 *   } );
 *
 * @return string[]
 */
function qhta_dietary_default_lines() {
	return (array) apply_filters(
		'qhta_dietary_options',
		array(
			__( 'No special requirements', 'qhta-commerce' ),
			__( 'Vegetarian', 'qhta-commerce' ),
			__( 'Vegan', 'qhta-commerce' ),
			__( 'Gluten free', 'qhta-commerce' ),
			__( 'Dairy free', 'qhta-commerce' ),
			__( 'Halal', 'qhta-commerce' ),
			__( 'Other / allergy (please give details)', 'qhta-commerce' ) . ' *',
		)
	);
}

/**
 * Dietary options for an event: key => [ label, needs_details ].
 *
 * The event's own list when it has one, otherwise the default. Keys are
 * sanitize_title() of the label, so renaming an option changes its key — past
 * orders are unaffected, since everything downstream reads the saved display
 * string in _qhta_dietary, not the key.
 *
 * @param int $product_id Event (parent) product ID, or 0 for the default list.
 * @return array
 */
function qhta_dietary_options( $product_id = 0 ) {
	$custom  = '';
	$product = $product_id ? wc_get_product( $product_id ) : null;

	if ( $product ) {
		$custom = trim( (string) $product->get_meta( QHTA_DIETARY_OPTIONS_META ) );
	}

	$lines = '' !== $custom ? explode( "\n", $custom ) : qhta_dietary_default_lines();

	$options = array();

	foreach ( $lines as $line ) {
		$line          = trim( (string) $line );
		$needs_details = '*' === substr( $line, -1 );
		$label         = $needs_details ? rtrim( substr( $line, 0, -1 ) ) : $line;
		$key           = sanitize_title( $label );

		// A blank line, or a second line that slugs to the same key: first wins.
		if ( '' === $key || isset( $options[ $key ] ) ) {
			continue;
		}

		$options[ $key ] = array(
			'label'         => $label,
			'needs_details' => $needs_details,
		);
	}

	return $options;
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
 * The event whose dietary question checkout asks, or 0 for none.
 *
 * The parent ID of the first cart item that is in an Events category AND has
 * "Ask dietary requirements" ticked. Several events in one cart is unlikely;
 * when it happens, the first ticked event's list is the one asked.
 *
 * @return int
 */
function qhta_commerce_cart_dietary_product_id() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return 0;
	}

	foreach ( WC()->cart->get_cart() as $item ) {
		$product_id = (int) $item['product_id'];

		if ( ! qhta_commerce_is_event_product( $product_id ) ) {
			continue;
		}

		$product = wc_get_product( $product_id );

		if ( $product && 'yes' === $product->get_meta( QHTA_ASK_DIETARY_META ) ) {
			return $product_id;
		}
	}

	return 0;
}

/**
 * The posted dietary choice and details, sanitised.
 *
 * The choice is only ever compared against the resolved option keys, so an
 * unknown value simply fails that lookup.
 *
 * @return array { choice, details }
 */
function qhta_commerce_posted_dietary() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked by WooCommerce checkout.
	$choice  = isset( $_POST['qhta_dietary_choice'] ) && is_string( $_POST['qhta_dietary_choice'] ) ? sanitize_text_field( wp_unslash( $_POST['qhta_dietary_choice'] ) ) : '';
	$details = isset( $_POST['qhta_dietary_details'] ) && is_string( $_POST['qhta_dietary_details'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['qhta_dietary_details'] ) ) ) : '';
	// phpcs:enable

	return array(
		'choice'  => $choice,
		'details' => $details,
	);
}

/**
 * Render the fields.
 *
 * On woocommerce_after_order_notes: inside the customer-details column, below
 * where order notes would be. That action fires whether or not order notes are
 * enabled — only the notes field itself is switched off — so the block sits
 * in the form's left column. It used to hang off
 * woocommerce_checkout_after_customer_details, which is *outside* that column
 * set: the block ran full width and pushed the floated "Your order" panel down.
 *
 * The radios are hand-rolled rather than woocommerce_form_field( 'radio' ),
 * which puts every option's label on its own line under its radio, bolds each
 * like a field label, and repeats the required asterisk on every option. Here
 * the question is a legend carrying the one asterisk, and each radio sits
 * inline inside its own label. Same field name and value, so validation and
 * save are untouched. Spacing and weight are in assets/qhta-commerce.css.
 *
 * @param WC_Checkout|null $checkout Checkout object, when the hook passes one.
 */
function qhta_commerce_render_dietary_field( $checkout = null ) {
	$product_id = qhta_commerce_cart_dietary_product_id();

	if ( ! $product_id ) {
		return;
	}

	$checkout = $checkout ? $checkout : WC()->checkout();
	$current  = (string) $checkout->get_value( 'qhta_dietary_choice' );

	echo '<div class="qhta-event-fields"><h3>' . esc_html__( 'Conference details', 'qhta-commerce' ) . '</h3>';

	echo '<fieldset class="form-row form-row-wide validate-required qhta-dietary-choice" id="qhta_dietary_choice_field">';
	printf(
		'<legend class="qhta-dietary-heading">%1$s&nbsp;<abbr class="required" title="%2$s">*</abbr></legend>',
		esc_html__( 'Dietary requirements', 'qhta-commerce' ),
		esc_attr__( 'required', 'qhta-commerce' )
	);

	foreach ( qhta_dietary_options( $product_id ) as $key => $option ) {
		printf(
			'<label class="qhta-dietary-option"><input type="radio" class="input-radio" name="qhta_dietary_choice" value="%1$s"%2$s> %3$s</label>',
			esc_attr( $key ),
			checked( $current, $key, false ),
			esc_html( $option['label'] )
		);
	}

	echo '</fieldset>';

	woocommerce_form_field(
		'qhta_dietary_details',
		array(
			'type'              => 'textarea',
			'label'             => __( 'Details', 'qhta-commerce' ),
			'placeholder'       => __( 'Allergies or other needs. If booking for several people, list each name and their requirements.', 'qhta-commerce' ),
			'required'          => false,
			'class'             => array( 'form-row-wide', 'qhta-dietary-details' ),
			'custom_attributes' => array( 'rows' => 2 ),
		),
		$checkout->get_value( 'qhta_dietary_details' )
	);

	echo '</div>';
}
add_action( 'woocommerce_after_order_notes', 'qhta_commerce_render_dietary_field' );

/**
 * Validate: a known choice, and details when that choice needs them.
 */
function qhta_commerce_validate_dietary_field() {
	$product_id = qhta_commerce_cart_dietary_product_id();

	if ( ! $product_id ) {
		return;
	}

	$options = qhta_dietary_options( $product_id );
	$posted  = qhta_commerce_posted_dietary();
	$choice  = $posted['choice'];

	if ( '' === $choice || ! isset( $options[ $choice ] ) ) {
		wc_add_notice( __( 'Please choose a dietary requirement option.', 'qhta-commerce' ), 'error' );
		return;
	}

	if ( $options[ $choice ]['needs_details'] && '' === $posted['details'] ) {
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
	$product_id = qhta_commerce_cart_dietary_product_id();

	if ( ! $product_id ) {
		return;
	}

	$options = qhta_dietary_options( $product_id );
	$posted  = qhta_commerce_posted_dietary();
	$choice  = $posted['choice'];
	$details = $posted['details'];

	if ( ! isset( $options[ $choice ] ) ) {
		return;
	}

	$label   = $options[ $choice ]['label'];
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
