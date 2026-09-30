<?php
/**
 * Disposable WooCommerce proof for WAPF date-field formula references.
 * Run with: wp eval-file bin/e2e-date-formula-reference-test.php
 */
defined( 'ABSPATH' ) || exit;

$product_id = 0;
$group_id   = 0;
$order_id   = 0;
$cart       = WC()->cart;

try {
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF E2E Formula Date Reference Product' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product_id = $product->save();
	update_post_meta( $product_id, '_opf_date_formula_reference_fixture', '1' );

	$group = new \OPF\Engine\FieldGroup( [
		'fields' => [
			[ 'id' => 'start_date', 'label' => 'Start date', 'type' => 'text', 'pricing' => [ 'type' => 'none', 'amount' => 0, 'formula' => '' ] ],
			[ 'id' => 'end_date', 'label' => 'End date', 'type' => 'text', 'pricing' => [ 'type' => 'none', 'amount' => 0, 'formula' => '' ] ],
			[ 'id' => 'date_price', 'label' => 'Date price', 'type' => 'text', 'pricing' => [ 'type' => 'formula', 'amount' => 0, 'formula' => 'dow([field.start_date]) + month([field.end_date])' ] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = \OPF\Service\FieldGroups::save( 0, $group, [ 'title' => 'OPF E2E Formula Date Reference Group' ] );
	if ( ! $product_id || ! $group_id ) {
		throw new RuntimeException( 'Could not create date formula fixtures.' );
	}
	\OPF\Service\FieldGroups::flush_cache();

	$cart->empty_cart( true );
	$_POST['opf'] = [ (string) $group_id => [ 'start_date' => '2024-01-01', 'end_date' => '2024-02-29', 'date_price' => 'selected' ] ];
	$cart_item_key = $cart->add_to_cart( $product_id, 1 );
	unset( $_POST['opf'] );
	if ( ! $cart_item_key ) {
		throw new RuntimeException( 'WooCommerce rejected the date formula cart item.' );
	}
	$cart->calculate_totals();
	$cart_item = $cart->get_cart_item( $cart_item_key );
	$cart_price = $cart_item ? (float) $cart_item['data']->get_price() : -1;
	if ( abs( $cart_price - 13.0 ) > 0.001 ) {
		throw new RuntimeException( sprintf( 'Expected cart price 13.00; received %.2f.', $cart_price ) );
	}

	$order    = wc_create_order();
	$line_id  = $order->add_product( $cart_item['data'], 1 );
	$line     = $order->get_item( $line_id );
	\OPF\Service\CartIntegration::persist_order_item( $line, (string) $cart_item_key, $cart_item, $order );
	$line->save();
	$order->calculate_totals();
	$order->save();
	$order_id = $order->get_id();
	if ( abs( (float) $line->get_total() - 13.0 ) > 0.001 ) {
		throw new RuntimeException( sprintf( 'Expected order line total 13.00; received %.2f.', (float) $line->get_total() ) );
	}
	$stored = json_decode( (string) $line->get_meta( '_opf_fields', true ), true );
	if ( ! is_array( $stored ) || '2024-01-01' !== ( $stored[ $group_id ]['start_date'] ?? '' ) ) {
		throw new RuntimeException( 'Order line did not preserve the source date field.' );
	}

	WP_CLI::success( sprintf( 'Date field formulas passed: cart %.2f, order %.2f, saved date %s.', $cart_price, (float) $line->get_total(), $stored[ $group_id ]['start_date'] ) );
} finally {
	unset( $_POST['opf'] );
	$cart->empty_cart( true );
	if ( $order_id && wc_get_order( $order_id ) ) {
		wc_get_order( $order_id )->delete( true );
	}
	if ( $group_id ) {
		wp_delete_post( (int) $group_id, true );
	}
	if ( $product_id && wc_get_product( $product_id ) ) {
		wc_get_product( $product_id )->delete( true );
	}
	\OPF\Service\FieldGroups::flush_cache();
}
