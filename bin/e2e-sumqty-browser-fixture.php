<?php
/** Private native sumQty browser/cart/order proof. Never run on production. */

use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;

$private_path = dirname( __DIR__ ) . '/vendor/sumqty-wordpress/';
if ( '1' !== getenv( 'OPF_SUMQTY_E2E_ALLOW' ) || ABSPATH !== $private_path || 0 !== strpos( $private_path, '/tmp/' ) ) {
	throw new RuntimeException( 'Requires this /tmp worktree\'s private vendor/sumqty-wordpress installation and OPF_SUMQTY_E2E_ALLOW=1.' );
}
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$mode = getenv( 'OPF_SUMQTY_E2E_MODE' );
$product_id = (int) getenv( 'OPF_SUMQTY_PRODUCT' );
$group_id = (int) getenv( 'OPF_SUMQTY_GROUP' );

if ( 'oracle' === $mode ) {
	$source = getenv( 'OPF_WAPF_SUMQTY_SOURCE' );
	$assert( is_string( $source ) && is_file( $source . '/extend/formulas.php' ), 'Set OPF_WAPF_SUMQTY_SOURCE to the installed Extended source directory.' );
	require_once $source . '/includes/classes/class-helper.php';
	function wapf_add_formula_function( $name, $callback ) {
		SW_WAPF_PRO\Includes\Classes\Helper::add_formula_function( $name, $callback );
	}
	require $source . '/extend/formulas.php';
	$wapf_fields = [ [ 'id' => 'prints', 'values' => [ [ 'label' => '2' ], [ 'label' => '3' ], [ 'label' => '0' ] ] ], [ 'id' => 'empty', 'values' => [] ] ];
	$observed = [];
	foreach ( [ 'sumQty(prints)' => 5, 'sumQty(missing)' => 0, 'sumQty(empty)' => 0, 'sumQty(prints)*2' => 10 ] as $formula => $expected ) {
		$result = SW_WAPF_PRO\Includes\Classes\Helper::parse_math_string( $formula, $wapf_fields );
		$assert( (float) $expected === (float) $result, 'Native WAPF source callback mismatch: ' . $formula );
		$observed[ $formula ] = $result;
	}
	echo wp_json_encode( [ 'source_sha256' => hash_file( 'sha256', $source . '/extend/formulas.php' ), 'observed' => $observed ] );
	return;
}

if ( 'create' === $mode ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF private sumQty browser fixture' );
	$product->set_regular_price( '10.00' );
	$product->set_virtual( true );
	$product->set_status( 'publish' );
	$product_id = (int) $product->save();
	try {
		$group_id = FieldGroups::save( 0, [ 'fields' => [
			[ 'id' => 'prints', 'label' => 'Prints', 'type' => 'image_quantity', 'min_choices' => 3, 'max_choices' => 8, 'choices' => [
				[ 'slug' => 'oak', 'label' => 'Oak', 'quantity' => [ 'min' => 1, 'max' => 12 ], 'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => false ] ],
				[ 'slug' => 'ash', 'label' => 'Ash', 'quantity' => [ 'min' => 0, 'max' => 12 ], 'pricing' => [ 'type' => 'fixed', 'amount' => 3, 'per_unit' => false ] ],
				[ 'slug' => 'disabled', 'label' => 'Disabled', 'disabled' => true, 'quantity' => [ 'min' => 0, 'max' => 4 ], 'pricing' => [ 'type' => 'fixed', 'amount' => 100 ] ],
			] ],
			[ 'id' => 'notes', 'label' => 'Notes', 'type' => 'text' ],
			[ 'id' => 'fee', 'label' => 'Quantity fee', 'type' => 'select', 'choices' => [ [ 'slug' => 'selected', 'label' => 'Selected', 'pricing' => [ 'type' => 'formula', 'formula' => 'sumQty(prints)', 'formula_raw' => 'sumQty(prints)*[qty]' ] ] ] ],
			[ 'id' => 'unrelated', 'label' => 'Wrong type fee', 'type' => 'select', 'choices' => [ [ 'slug' => 'selected', 'label' => 'Selected', 'pricing' => [ 'type' => 'formula', 'formula' => 'sumQty(notes)', 'formula_raw' => 'sumQty(notes)*[qty]' ] ] ] ],
			[ 'id' => 'missing', 'label' => 'Missing field fee', 'type' => 'select', 'choices' => [ [ 'slug' => 'selected', 'label' => 'Selected', 'pricing' => [ 'type' => 'formula', 'formula' => 'sumQty(absent)', 'formula_raw' => 'sumQty(absent)*[qty]' ] ] ] ],
		], 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ] ], [ 'title' => 'OPF private sumQty browser fixture' ] );
		$assert( $group_id > 0, 'Could not create group.' );
		FieldGroups::flush_cache();
		echo wp_json_encode( [ 'product_id' => $product_id, 'group_id' => $group_id, 'url' => get_permalink( $product_id ) ] );
	} catch ( Throwable $error ) {
		if ( $group_id ) { wp_delete_post( $group_id, true ); }
		wp_delete_post( $product_id, true );
		throw $error;
	}
	return;
}
$assert( $product_id > 0 && $group_id > 0 && 'OPF private sumQty browser fixture' === get_the_title( $product_id ) && 'OPF private sumQty browser fixture' === get_the_title( $group_id ), 'Fixture identity mismatch.' );
if ( 'cleanup' === $mode ) {
	wp_delete_post( $product_id, true );
	wp_delete_post( $group_id, true );
	FieldGroups::flush_cache();
	$assert( ! get_post( $product_id ) && ! get_post( $group_id ), 'Fixture cleanup failed.' );
	echo 'Private browser fixture product/group cleaned.';
	return;
}
$assert( 'commerce' === $mode, 'Unknown proof mode.' );
$rows = json_decode( (string) getenv( 'OPF_SUMQTY_OBSERVATIONS' ), true );
$assert( is_array( $rows ) && count( $rows ) >= 4, 'Pass browser observations to the commerce comparison.' );
$cart = WC()->cart;
$assert( $cart && $cart->is_empty(), 'Private cart must begin empty.' );
$order_id = 0;
$results = [];
try {
	foreach ( $rows as $row ) {
		$quantities = [ 'oak' => (string) $row['oak'], 'ash' => (string) $row['ash'], 'forged' => '999' ];
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_param( 'id', $product_id );
		$request->set_param( 'quantity', $row['quantity'] );
		$request->set_param( 'opf_fields', [ $group_id => [ 'prints' => $quantities, 'notes' => '23', 'fee' => 'selected', 'unrelated' => 'selected', 'missing' => 'selected', 'absent' => [ 'oak' => 999 ] ] ] );
		$response = rest_get_server()->dispatch( $request );
		$assert( $response->get_status() < 400, 'Store API rejected browser values.' );
		$cart->calculate_totals();
		$items = $cart->get_cart();
		$assert( count( $items ) === 1, 'Expected one cart item.' );
		$key = array_key_first( $items );
		$item = $items[ $key ];
		$values = $item[ CartIntegration::ITEM_KEY ][ $group_id ];
		$expected_quantities = [ 'oak' => $row['oak'], 'ash' => $row['ash'], 'disabled' => 0 ];
		$assert( $values['prints']['quantities'] === $expected_quantities && ! isset( $values['absent'] ), 'Unknown slug/field survived validation.' );
		$assert( abs( (float) $item['line_total'] - (float) $row['total'] ) < 0.001, 'Browser/server total mismatch: ' . wp_json_encode( [ 'browser' => $row, 'server' => $item['line_total'] ] ) );
		$results[] = [ 'oak' => $row['oak'], 'ash' => $row['ash'], 'quantity' => $row['quantity'], 'browser' => $row['total'], 'cart' => $item['line_total'] ];
		if ( 2 === $row['quantity'] && 2 === $row['oak'] && 3 === $row['ash'] ) {
			$order = wc_create_order();
			$assert( ! is_wp_error( $order ), 'Order creation failed.' );
			$order_id = $order->get_id();
			$line_id = $order->add_product( $item['data'], 2 );
			$line = $order->get_item( $line_id );
			do_action( 'woocommerce_checkout_create_order_line_item', $line, $key, $item, $order );
			$line->save();
			$order->calculate_totals();
			$order->save();
			$stored = new WC_Order_Item_Product( $line_id );
			$stored_values = json_decode( (string) $stored->get_meta( '_opf_fields', true ), true );
			$assert( abs( (float) $stored->get_total() - 43 ) < 0.001 && 2 === $stored->get_quantity(), 'Stored order quantity/total mismatch.' );
			$assert( $expected_quantities === $stored_values[ $group_id ]['prints']['quantities'] && ! isset( $stored_values[ $group_id ]['absent'] ), 'Stored structured values retained unknown keys or lost quantities.' );
			$assert( 'Oak: 2, Ash: 3' === $stored->get_meta( 'Prints', true ) && 'selected' === $stored_values[ $group_id ]['fee'], 'Stored visible label or formula selection changed.' );
			$assert( ! empty( json_decode( (string) $stored->get_meta( '_opf_fields_snapshot', true ), true ) ), 'Stored snapshot missing.' );
			$order->delete( true );
			$assert( ! wc_get_order( $order_id ), 'Order cleanup failed.' );
			$order_id = 0;
		}
		$cart->empty_cart( true );
	}
} finally {
	$cart->empty_cart( true );
	wc_clear_notices();
	if ( $order_id && wc_get_order( $order_id ) ) { wc_get_order( $order_id )->delete( true ); }
}
echo wp_json_encode( [ 'matched' => $results, 'persisted_order_line' => 43, 'cleanup' => 'cart/orders clean' ] );
