<?php
/**
 * Event attendees — WooCommerce -> Event attendees, plus a CSV download.
 *
 * One row per Events line item, from orders in the statuses below (default
 * Processing, Completed, On hold — On hold so unpaid invoice bookings are
 * counted), with totals by ticket type at the top.
 *
 *   ?product_id=4311   limit the list to one event (parent product ID)
 *
 * The CSV goes through admin-post.php?action=qhta_attendees_csv, nonce- and
 * capability-checked. UTF-8 with a BOM so Excel reads accents, and every cell
 * guarded against formula injection — buyer-entered text ends up in it.
 *
 * Orders are read through wc_get_orders() only, so it is HPOS-safe.
 *
 * @package QHTA_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page slug.
 */
const QHTA_ATTENDEES_PAGE = 'qhta-event-attendees';

/**
 * Order statuses counted as attendees, without the wc- prefix.
 *
 *   add_filter( 'qhta_attendees_statuses', function ( $statuses ) {
 *       return array( 'processing', 'completed' ); // paid only
 *   } );
 *
 * @return string[]
 */
function qhta_attendees_statuses() {
	return (array) apply_filters( 'qhta_attendees_statuses', array( 'processing', 'completed', 'on-hold' ) );
}

/**
 * The product filter from the request, or 0 for all events.
 *
 * @return int
 */
function qhta_attendees_requested_product() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on an admin listing.
	return isset( $_REQUEST['product_id'] ) ? absint( wp_unslash( $_REQUEST['product_id'] ) ) : 0;
}

/**
 * Attendee rows: one per Events line item.
 *
 * Each row carries raw values; the page and the CSV format them differently.
 *
 * @param int $product_id Parent product ID to limit to, or 0 for every event.
 * @return array[]
 */
function qhta_attendees_rows( $product_id = 0 ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$orders = wc_get_orders(
		array(
			'type'    => 'shop_order',
			'status'  => qhta_attendees_statuses(),
			'limit'   => -1,
			'orderby' => 'date',
			'order'   => 'ASC',
		)
	);

	$rows = array();

	foreach ( $orders as $order ) {
		foreach ( $order->get_items() as $item ) {
			$parent_id = (int) $item->get_product_id();

			if ( $product_id && $parent_id !== $product_id ) {
				continue;
			}

			if ( ! qhta_commerce_is_event_product( $parent_id ) ) {
				continue;
			}

			$created = $order->get_date_created();

			$rows[] = array(
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'edit_url'     => $order->get_edit_order_url(),
				'date'         => $created ? $created->date_i18n( 'Y-m-d H:i' ) : '',
				'status'       => wc_get_order_status_name( $order->get_status() ),
				'event'        => get_the_title( $parent_id ),
				'ticket_type'  => qhta_attendees_ticket_type( $item ),
				'qty'          => (int) $item->get_quantity(),
				'total'        => (float) $item->get_total() + (float) $item->get_total_tax(),
				'buyer'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'school'       => $order->get_billing_company(),
				'email'        => $order->get_billing_email(),
				'phone'        => $order->get_billing_phone(),
				'payment'      => $order->get_payment_method_title(),
				'dietary'      => (string) $order->get_meta( '_qhta_dietary' ),
			);
		}
	}

	return $rows;
}

/**
 * Ticket type for a line item — the variation's attribute values.
 *
 * Read from the item's own stored meta, not the live variation, so the report
 * still says "Standard" after a variation is renamed or deleted.
 *
 * @param WC_Order_Item_Product $item Line item.
 * @return string
 */
function qhta_attendees_ticket_type( $item ) {
	$values = array();

	foreach ( $item->get_formatted_meta_data() as $meta ) {
		$values[] = trim( wp_strip_all_tags( $meta->display_value ) );
	}

	return implode( ', ', array_filter( $values ) );
}

/**
 * Totals by ticket type: label => [ qty, total ].
 *
 * @param array[] $rows Attendee rows.
 * @return array
 */
function qhta_attendees_totals( $rows ) {
	$totals = array();

	foreach ( $rows as $row ) {
		$key = '' !== $row['ticket_type'] ? $row['ticket_type'] : __( '(no type)', 'qhta-commerce' );

		if ( ! isset( $totals[ $key ] ) ) {
			$totals[ $key ] = array(
				'qty'   => 0,
				'total' => 0.0,
			);
		}

		$totals[ $key ]['qty']   += $row['qty'];
		$totals[ $key ]['total'] += $row['total'];
	}

	ksort( $totals );

	return $totals;
}

/**
 * Column key => heading, shared by the page and the CSV.
 *
 * @return array
 */
function qhta_attendees_columns() {
	return array(
		'order_number' => __( 'Order', 'qhta-commerce' ),
		'date'         => __( 'Date', 'qhta-commerce' ),
		'status'       => __( 'Status', 'qhta-commerce' ),
		'event'        => __( 'Event', 'qhta-commerce' ),
		'ticket_type'  => __( 'Ticket type', 'qhta-commerce' ),
		'qty'          => __( 'Qty', 'qhta-commerce' ),
		'total'        => __( 'Total inc GST', 'qhta-commerce' ),
		'buyer'        => __( 'Buyer', 'qhta-commerce' ),
		'school'       => __( 'School', 'qhta-commerce' ),
		'email'        => __( 'Email', 'qhta-commerce' ),
		'phone'        => __( 'Phone', 'qhta-commerce' ),
		'payment'      => __( 'Payment method', 'qhta-commerce' ),
		'dietary'      => __( 'Dietary', 'qhta-commerce' ),
	);
}

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */

/**
 * Register WooCommerce -> Event attendees.
 */
function qhta_attendees_menu() {
	add_submenu_page(
		'woocommerce',
		__( 'Event attendees', 'qhta-commerce' ),
		__( 'Event attendees', 'qhta-commerce' ),
		'manage_woocommerce',
		QHTA_ATTENDEES_PAGE,
		'qhta_attendees_render_page'
	);
}
add_action( 'admin_menu', 'qhta_attendees_menu', 60 );

/**
 * Render the page.
 */
function qhta_attendees_render_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$product_id = qhta_attendees_requested_product();
	$rows       = qhta_attendees_rows( $product_id );
	$totals     = qhta_attendees_totals( $rows );
	$columns    = qhta_attendees_columns();

	$csv_args = array( 'action' => 'qhta_attendees_csv' );
	if ( $product_id ) {
		$csv_args['product_id'] = $product_id;
	}
	$csv_url = wp_nonce_url( add_query_arg( $csv_args, admin_url( 'admin-post.php' ) ), 'qhta_attendees_csv' );
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Event attendees', 'qhta-commerce' ); ?></h1>
		<a href="<?php echo esc_url( $csv_url ); ?>" class="page-title-action"><?php esc_html_e( 'Download CSV', 'qhta-commerce' ); ?></a>
		<hr class="wp-header-end">

		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: comma-separated order statuses. */
					__( 'Orders that are %s.', 'qhta-commerce' ),
					implode( ', ', array_map( 'wc_get_order_status_name', qhta_attendees_statuses() ) )
				)
			);

			if ( $product_id ) {
				echo ' ';
				echo esc_html(
					sprintf(
						/* translators: %s: product title. */
						__( 'Showing %s only.', 'qhta-commerce' ),
						get_the_title( $product_id )
					)
				);
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . QHTA_ATTENDEES_PAGE ) ) . '">' . esc_html__( 'Show all events', 'qhta-commerce' ) . '</a>';
			}
			?>
		</p>

		<h2><?php esc_html_e( 'Totals by ticket type', 'qhta-commerce' ); ?></h2>
		<table class="widefat striped" style="max-width:40em">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Ticket type', 'qhta-commerce' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'qhta-commerce' ); ?></th>
					<th><?php esc_html_e( 'Total inc GST', 'qhta-commerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $totals ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No attendees yet.', 'qhta-commerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $totals as $type => $sum ) : ?>
					<tr>
						<td><?php echo esc_html( $type ); ?></td>
						<td><?php echo esc_html( (string) $sum['qty'] ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $sum['total'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Attendees', 'qhta-commerce' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<?php foreach ( $columns as $heading ) : ?>
						<th><?php echo esc_html( $heading ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="<?php echo esc_attr( (string) count( $columns ) ); ?>"><?php esc_html_e( 'No attendees yet.', 'qhta-commerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<?php foreach ( array_keys( $columns ) as $key ) : ?>
							<td>
								<?php
								if ( 'order_number' === $key ) {
									printf( '<a href="%s">#%s</a>', esc_url( $row['edit_url'] ), esc_html( $row['order_number'] ) );
								} elseif ( 'total' === $key ) {
									echo wp_kses_post( wc_price( $row['total'] ) );
								} elseif ( 'email' === $key ) {
									printf( '<a href="mailto:%1$s">%1$s</a>', esc_html( $row['email'] ) );
								} else {
									echo nl2br( esc_html( (string) $row[ $key ] ) );
								}
								?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * CSV
 * ---------------------------------------------------------------------- */

/**
 * Neutralise a cell that a spreadsheet would read as a formula.
 *
 * Buyer-entered fields (name, school, dietary details) land in the CSV, and a
 * leading = + - @ (or tab / CR) makes Excel evaluate them. A leading quote
 * makes it text.
 *
 * @param mixed $value Cell value.
 * @return mixed
 */
function qhta_attendees_csv_cell( $value ) {
	if ( is_string( $value ) && '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
		return "'" . $value;
	}

	return $value;
}

/**
 * Stream the CSV.
 */
function qhta_attendees_csv() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to download this.', 'qhta-commerce' ), 403 );
	}

	check_admin_referer( 'qhta_attendees_csv' );

	$product_id = qhta_attendees_requested_product();
	$rows       = qhta_attendees_rows( $product_id );
	$columns    = qhta_attendees_columns();

	$filename = 'event-attendees-' . ( $product_id ? $product_id . '-' : '' ) . gmdate( 'Y-m-d' ) . '.csv';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	$out = fopen( 'php://output', 'w' );

	// UTF-8 BOM, or Excel opens the file as ANSI and mangles accents.
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

	fputcsv( $out, array_values( $columns ) );

	foreach ( $rows as $row ) {
		$line = array();

		foreach ( array_keys( $columns ) as $key ) {
			$value = $row[ $key ];

			if ( 'total' === $key ) {
				$value = wc_format_decimal( $value, wc_get_price_decimals() );
			}

			$line[] = qhta_attendees_csv_cell( $value );
		}

		fputcsv( $out, $line );
	}

	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
add_action( 'admin_post_qhta_attendees_csv', 'qhta_attendees_csv' );
