<?php
/** Disposable math import proof. Modes: create, commerce, cleanup. */

use OPF\Engine\WapfMapper;
use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;

if ( '1' !== getenv( 'OPF_MATH_IMPORT_E2E_ALLOW' ) || '/tmp/opf-math-import-wp/' !== ABSPATH ) {
	throw new RuntimeException( 'This proof requires the private /tmp/opf-math-import-wp clone and OPF_MATH_IMPORT_E2E_ALLOW=1.' );
}
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$mode = getenv( 'OPF_MATH_IMPORT_E2E_MODE' );
$product_id = (int) getenv( 'OPF_MATH_IMPORT_PRODUCT' );
$group_id = (int) getenv( 'OPF_MATH_IMPORT_GROUP' );
if ( 'create' === $mode ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF math import disposable proof' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product->set_status( 'publish' );
	$product_id = (int) $product->save();
	try {
		$formula = '(round(pow([field.count-src]; 2) / 3; 2) + max(abs(-4); floor(2.9); ceil(2.1)) + sqrt(9) + sin(0) + cos(0) + tan(0) + min(5; 1; 3)) * [qty]';
		$mapped = WapfMapper::map( [ 'fields' => [
			[ 'id' => 'count-src', 'label' => 'Count', 'type' => 'number', 'required' => true ],
			[ 'id' => 'plan-src', 'label' => 'Plan', 'type' => 'select', 'required' => true, 'options' => [ 'choices' => [ [ 'slug' => 'math', 'label' => 'Math', 'pricing_type' => 'fx', 'pricing_amount' => $formula ] ] ] ],
		] ], [ 'attach_product_ids' => [ $product_id ] ] );
		$assert( ! $mapped['needs_review'], 'Math import needs review: ' . implode( ' ', $mapped['notes'] ) );
		$group_id = FieldGroups::save( 0, $mapped['group'], [ 'title' => 'OPF math import disposable proof' ] );
		$assert( $group_id > 0, 'Could not save mapped group.' );
		FieldGroups::flush_cache();
		echo wp_json_encode( [ 'product_id' => $product_id, 'group_id' => $group_id, 'url' => get_permalink( $product_id ), 'raw' => $mapped['group']['fields'][1]['choices'][0]['pricing']['formula_raw'] ] );
	} catch ( Throwable $error ) {
		if ( $group_id ) { wp_delete_post( $group_id, true ); }
		wp_delete_post( $product_id, true );
		throw $error;
	}
	return;
}
$assert( $product_id > 0 && $group_id > 0, 'Fixture IDs are required.' );
$assert( 'OPF math import disposable proof' === get_the_title( $product_id ), 'Fixture product identity mismatch.' );
$assert( 'OPF math import disposable proof' === get_the_title( $group_id ), 'Fixture group identity mismatch.' );
if ( 'cleanup' === $mode ) {
	wp_delete_post( $group_id, true );
	wp_delete_post( $product_id, true );
	FieldGroups::flush_cache();
	$assert( ! get_post( $group_id ) && ! get_post( $product_id ), 'Fixture cleanup failed.' );
	echo 'Fixture product and imported group cleaned.';
	return;
}
$assert( 'commerce' === $mode, 'Unknown proof mode.' );
$cart = WC()->cart;
$assert( $cart && $cart->is_empty(), 'The disposable cart must be empty.' );
$order_id = 0;
try {
	foreach ( [ 1, 3 ] as $qty ) {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_param( 'id', $product_id );
		$request->set_param( 'quantity', $qty );
		$request->set_param( 'opf_fields', [ $group_id => [ 'count' => '3', 'plan' => 'math' ] ] );
		$response = rest_get_server()->dispatch( $request );
		$assert( $response->get_status() < 400, 'Store API add-item failed.' );
		$cart->calculate_totals();
		$items = $cart->get_cart();
		$assert( 1 === count( $items ), 'Expected one cart line.' );
		$key = array_key_first( $items );
		$item = $items[ $key ];
		$assert( abs( (float) $item['data']->get_price() - 22 ) < 0.001, 'Cart unit price must be 22.' );
		$assert( abs( (float) $item['line_total'] - 22 * $qty ) < 0.001, 'Cart line total mismatch.' );
		if ( 3 === $qty ) {
			$order = wc_create_order();
			$order_id = $order->get_id();
			$line_id = $order->add_product( $item['data'], $qty );
			$line = $order->get_item( $line_id );
			do_action( 'woocommerce_checkout_create_order_line_item', $line, $key, $item, $order );
			$line->save();
			$order->calculate_totals();
			$order->save();
			$stored = new WC_Order_Item_Product( $line_id );
			$assert( abs( (float) $stored->get_total() - 66 ) < 0.001, 'Stored order line must total 66.' );
			$values = json_decode( (string) $stored->get_meta( '_opf_fields', true ), true );
			$assert( '3' === $values[ $group_id ]['count'] && 'math' === $values[ $group_id ]['plan'], 'Stored order lost imported selections.' );
		}
		$cart->empty_cart( true );
	}
} finally {
	$cart->empty_cart( true );
	if ( $order_id ) { wc_get_order( $order_id )->delete( true ); }
}
$assert( $cart->is_empty() && ! wc_get_order( $order_id ), 'Cart/order cleanup failed.' );
echo 'Store API q=1 line 22.00, q=3 line 66.00; fresh stored order 66.00 and structured Count/Plan selections; cart/order cleaned.';
