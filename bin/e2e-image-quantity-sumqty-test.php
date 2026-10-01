<?php
/**
 * Disposable WooCommerce image quantity / sumQty lifecycle proof.
 *
 * Run with OPF_IMAGE_QUANTITY_E2E_ALLOW=1 wp eval-file
 * bin/e2e-image-quantity-sumqty-test.php --path=/path/to/disposable/wordpress
 *
 * Oracle: WAPF Extended 3.1.5 extend/formulas.php sumQty() sums intval of
 * each image-swatch-qty cart value's label. Quantities 2 + 3 therefore yield
 * 5, regardless of each option's label or price.
 */

defined( 'ABSPATH' ) || exit;

if ( '1' !== getenv( 'OPF_IMAGE_QUANTITY_E2E_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_IMAGE_QUANTITY_E2E_ALLOW=1 only on a disposable WooCommerce clone.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( OPF\Service\CartIntegration::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce and OPF before running this proof.' );
}

use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$add_store_item = static function ( int $product_id, int $group_id, array $quantities, int $quantity = 1 ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$request->set_param( 'id', $product_id );
	$request->set_param( 'quantity', $quantity );
	$request->set_param( 'opf_fields', [ (string) $group_id => [ 'prints' => $quantities, 'quantity_fee' => 'selected' ] ] );
	return rest_get_server()->dispatch( $request );
};
$product_id = 0;
$group_id = 0;
$order_id = 0;
$admin_only_before = get_option( 'opf_admin_only', null );
$post_before = $_POST;
$cart = WC()->cart;
$assert( null !== $cart, 'WooCommerce cart did not initialize.' );
$assert( $cart->is_empty(), 'Disposable clone must have an empty cart before this proof.' );

try {
	update_option( 'opf_admin_only', 'no' );
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF image quantity sumQty lifecycle fixture' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product_id = (int) $product->save();
	$assert( $product_id > 0, 'Could not create the disposable product.' );
	$group_id = FieldGroups::save( 0, [
		'fields' => [
			[
				'id' => 'prints', 'label' => 'Prints', 'type' => 'image_quantity',
				'min_choices' => 3, 'max_choices' => 8,
				'choices' => [
					[ 'slug' => 'oak', 'label' => 'Oak', 'image' => '/oak.png', 'quantity' => [ 'min' => 1, 'max' => 12 ], 'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => true ] ],
					[ 'slug' => 'ash', 'label' => 'Ash', 'image' => '/ash.png', 'quantity' => [ 'min' => 0, 'max' => 12 ], 'pricing' => [ 'type' => 'fixed', 'amount' => 3, 'per_unit' => true ] ],
					[ 'slug' => 'disabled', 'label' => 'Disabled print', 'disabled' => true, 'quantity' => [ 'min' => 0, 'max' => 4 ], 'pricing' => [ 'type' => 'fixed', 'amount' => 100, 'per_unit' => true ] ],
				],
			],
			[ 'id' => 'quantity_fee', 'label' => 'Quantity fee', 'type' => 'text', 'pricing' => [ 'type' => 'formula', 'formula' => 'sumQty(prints)', 'per_unit' => true ] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	], [ 'title' => 'OPF image quantity sumQty lifecycle fixture' ] );
	$assert( $group_id > 0, 'Could not create the disposable image quantity group.' );
	FieldGroups::flush_cache();

	// Each Store API request must reject malformed quantities before cart insertion.
	$invalid_cases = [
		'below minimum' => [ 'oak' => '0', 'ash' => '3' ],
		'above choice maximum' => [ 'oak' => '13', 'ash' => '0' ],
		'below aggregate minimum' => [ 'oak' => '1', 'ash' => '1' ],
		'above aggregate maximum' => [ 'oak' => '5', 'ash' => '4' ],
		'fraction' => [ 'oak' => '2.5', 'ash' => '3' ],
		'negative' => [ 'oak' => '-1', 'ash' => '3' ],
		'non-numeric' => [ 'oak' => 'forged', 'ash' => '3' ],
		'nested array' => [ 'oak' => [ '2' ], 'ash' => '3' ],
		'disabled choice' => [ 'oak' => '2', 'ash' => '3', 'disabled' => '1' ],
		'huge integer' => [ 'oak' => '999999999999999999999999999', 'ash' => '3' ],
		'missing minimum choice' => [ 'ash' => '3' ],
	];
	foreach ( $invalid_cases as $case => $quantities ) {
		wc_clear_notices();
		$response = $add_store_item( $product_id, $group_id, $quantities );
		$assert( $response->get_status() >= 400, 'Store API accepted invalid ' . $case . ': ' . wp_json_encode( $response->get_data() ) );
		$assert( $cart->is_empty(), 'Rejected ' . $case . ' quantities leaked into the cart.' );
	}
	wc_clear_notices();

	// Classic form validation uses the same contract and rejects the same cases.
	foreach ( $invalid_cases as $case => $quantities ) {
		$_POST['opf'] = [ (string) $group_id => [ 'prints' => $quantities, 'quantity_fee' => 'selected' ] ];
		$assert( false === apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 ), 'Classic form accepted invalid ' . $case . '.' );
		$assert( wc_notice_count( 'error' ) > 0, 'Classic form did not explain invalid ' . $case . '.' );
		wc_clear_notices();
	}
	$_POST['opf'] = [ (string) $group_id => [ 'prints' => [ 'oak' => '2', 'ash' => '3' ], 'quantity_fee' => 'selected' ] ];
	$assert( true === apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 2 ), 'Classic form rejected canonical quantities.' );
	unset( $_POST['opf'] );

	// Unrecognized choice slugs are discarded; they must not affect sumQty/pricing.
	$response = $add_store_item( $product_id, $group_id, [ 'oak' => '2', 'ash' => '3', 'forged' => '999' ], 2 );
	$assert( in_array( $response->get_status(), [ 200, 201 ], true ), 'Store API rejected canonical quantities: ' . wp_json_encode( $response->get_data() ) );
	$cart->calculate_totals();
	$items = $cart->get_cart();
	$assert( 1 === count( $items ), 'Canonical request did not create exactly one cart line.' );
	$cart_item_key = (string) array_key_first( $items );
	$cart_item = $items[ $cart_item_key ];
	$values = $cart_item[ CartIntegration::ITEM_KEY ][ $group_id ] ?? [];
	$expected_quantities = [ 'oak' => 2, 'ash' => 3, 'disabled' => 0 ];
	$assert( 'image_quantity' === ( $values['prints']['_opf_type'] ?? '' ), 'Cart did not preserve the image quantity type.' );
	$assert( $expected_quantities === ( $values['prints']['quantities'] ?? null ), 'Cart quantities differ from canonical integers or retain a forged slug.' );
	$assert( [] === ( $values['prints']['invalid'] ?? null ), 'Valid quantities were marked invalid.' );
	$oracle = array_sum( array_map( 'intval', array_column( [ [ 'label' => '2' ], [ 'label' => '3' ] ], 'label' ) ) );
	$assert( 5 === $oracle, 'WAPF 3.1.5 oracle calculation changed.' );
	$expected_unit_price = 10.0 + ( 2 * 2.0 ) + ( 3 * 3.0 ) + $oracle;
	$assert( 28.0 === $expected_unit_price, 'Fixture expected price changed.' );
	$assert( abs( (float) $cart_item['data']->get_price() - $expected_unit_price ) < 0.001, 'Expected cart unit price 28.00: base 10 + choices 13 + sumQty 5.' );
	$assert( 2 === (int) $cart_item['quantity'] && abs( (float) $cart_item['line_total'] - 56.0 ) < 0.001, 'Two-unit cart line did not total 56.00.' );
	$display = apply_filters( 'woocommerce_get_item_data', [], $cart_item );
	$prints_display = array_values( array_filter( $display, static fn( $row ) => 'Prints' === $row['name'] ) );
	$assert( 1 === count( $prints_display ) && 'Oak: 2, Ash: 3' === $prints_display[0]['value'], 'Cart display did not preserve choice labels and quantities.' );

	$order = wc_create_order();
	$assert( ! is_wp_error( $order ), 'Could not create the disposable order.' );
	$order_id = $order->get_id();
	$line_id = $order->add_product( $cart_item['data'], 2 );
	$line = $order->get_item( $line_id );
	do_action( 'woocommerce_checkout_create_order_line_item', $line, $cart_item_key, $cart_item, $order );
	$line->save();
	$order->calculate_totals();
	$order->save();
	// Read a fresh object to prove storage, rather than only the in-memory item.
	$stored_line = new WC_Order_Item_Product( $line_id );
	$assert( abs( (float) $stored_line->get_total() - 56.0 ) < 0.001, 'Persisted two-unit order line did not total 56.00.' );
	$assert( 'Oak: 2, Ash: 3' === $stored_line->get_meta( 'Prints', true ), 'Persisted visible order values lost labels or quantities.' );
	$stored = json_decode( (string) $stored_line->get_meta( '_opf_fields', true ), true );
	$assert( $expected_quantities === ( $stored[ $group_id ]['prints']['quantities'] ?? null ), 'Persisted structured order metadata lost quantities.' );
	$assert( 'image_quantity' === ( $stored[ $group_id ]['prints']['_opf_type'] ?? '' ), 'Persisted structured order metadata lost type.' );
	$snapshot = json_decode( (string) $stored_line->get_meta( '_opf_fields_snapshot', true ), true );
	$assert( is_array( $snapshot ) && ! empty( $snapshot ), 'Persisted order did not retain a field snapshot.' );

	// Verify a later valid request cannot inherit invalid state or old quantities.
	$cart->empty_cart( true );
	$response = $add_store_item( $product_id, $group_id, [ 'oak' => '3' ] );
	$assert( in_array( $response->get_status(), [ 200, 201 ], true ), 'Store API rejected minimum/zero boundary quantities.' );
	$cart->calculate_totals();
	$boundary_item = array_values( $cart->get_cart() )[0];
	$assert( abs( (float) $boundary_item['data']->get_price() - 19.0 ) < 0.001, 'Zero choices or stale quantities affected aggregate minimum boundary price; expected 19.00.' );
	$boundary_display = CartIntegration::visible_selections( $boundary_item['data'], $boundary_item[ CartIntegration::ITEM_KEY ] );
	$assert( 'Oak: 3' === ( $boundary_display[0]['value'] ?? '' ), 'Zero choices appeared in visible values.' );
	$cart->empty_cart( true );
	$response = $add_store_item( $product_id, $group_id, [ 'oak' => '5', 'ash' => '3' ] );
	$assert( in_array( $response->get_status(), [ 200, 201 ], true ), 'Store API rejected maximum boundary quantities.' );
	$cart->calculate_totals();
	$maximum_item = array_values( $cart->get_cart() )[0];
	$assert( abs( (float) $maximum_item['data']->get_price() - 37.0 ) < 0.001, 'Maximum aggregate boundary price did not include all eight selected images; expected 37.00.' );
} finally {
	$_POST = $post_before;
	$cart->empty_cart( true );
	wc_clear_notices();
	if ( $order_id && wc_get_order( $order_id ) ) {
		wc_get_order( $order_id )->delete( true );
	}
	if ( $group_id ) {
		wp_delete_post( $group_id, true );
	}
	if ( $product_id && wc_get_product( $product_id ) ) {
		wc_get_product( $product_id )->delete( true );
	}
	if ( null === $admin_only_before ) {
		delete_option( 'opf_admin_only' );
	} else {
		update_option( 'opf_admin_only', $admin_only_before );
	}
	FieldGroups::flush_cache();
}

$assert( $cart->is_empty(), 'Cleanup left cart fixtures behind.' );
$assert( ! get_post( $product_id ) && ! get_post( $group_id ) && ! wc_get_order( $order_id ), 'Cleanup left product, group, or order fixtures behind.' );
WP_CLI::success( 'Image quantity / sumQty lifecycle passed: 11 invalid Store API and classic cases rejected; aggregate min/max enforced; canonical cart unit 28.00, two-unit order 56.00; labels and structured quantities persisted; minimum boundary 19.00, maximum aggregate boundary 37.00; fixtures/cart cleaned.' );
