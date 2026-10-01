<?php
/**
 * Guarded Store API E2E for quantity-repeated section sanitation/validation.
 *
 * Run only in a disposable WooCommerce database:
 *   OPF_SECTION_REPEATER_E2E_ALLOW=1 wp eval-file bin/e2e-repeater-section-values.php
 */

defined( 'ABSPATH' ) || exit;

if ( '1' !== getenv( 'OPF_SECTION_REPEATER_E2E_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_SECTION_REPEATER_E2E_ALLOW=1 only for a disposable WooCommerce database.' );
}

use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;

$product_id = 0;
$group_id = 0;
$admin_only_before = get_option( 'opf_admin_only', 'no' );

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$add_store_item = static function ( int $product_id, array $values ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$request->set_param( 'id', $product_id );
	$request->set_param( 'quantity', 2 );
	$request->set_param( 'opf_fields', $values );
	return rest_get_server()->dispatch( $request );
};

try {
	update_option( 'opf_admin_only', 'no' );
	FieldGroups::register_cpt();
	CartIntegration::init();

	$product = new WC_Product_Simple();
	$product->set_name( 'OPF quantity section E2E fixture' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product_id = (int) $product->save();
	$assert( $product_id > 0, 'Could not create the quantity-section E2E product.' );

	$group_id = FieldGroups::save( 0, [
		'fields' => [
			[ 'id' => 'attendees', 'label' => 'Attendees', 'type' => 'section', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity', 'label' => 'Guest {n}' ] ],
			[ 'id' => 'guest_name', 'label' => 'Guest name', 'type' => 'text', 'required' => true ],
			[ 'id' => 'guest_meal', 'label' => 'Guest meal', 'type' => 'select', 'required' => true, 'choices' => [
				[ 'slug' => 'soup', 'label' => 'Soup', 'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => true ] ],
				[ 'slug' => 'salad', 'label' => 'Salad', 'pricing' => [ 'type' => 'fixed', 'amount' => 3, 'per_unit' => true ] ],
			] ],
			[ 'id' => 'attendees-end', 'type' => 'section_end' ],
		],
	], [ 'title' => 'OPF quantity section E2E fixture' ] );
	$assert( $group_id > 0, 'Could not create the quantity-section E2E field group.' );

	$cart = WC()->cart;
	$cart->empty_cart();
	$response = $add_store_item( $product_id, [ (string) $group_id => [
		'guest_name' => [ ' Ada ', 'Grace' ],
		'guest_meal' => [ 'soup', 'salad' ],
	] ] );
	$assert( in_array( $response->get_status(), [ 200, 201 ], true ), 'Store API rejected valid section rows: ' . wp_json_encode( $response->get_data() ) );
	$items = array_values( array_filter( $cart->get_cart(), static fn( $item ) => (int) $item['product_id'] === $product_id ) );
	$assert( 2 === count( $items ), 'Store API did not split distinct quantity-section rows into separate cart lines.' );
	$lines_by_meal = [];
	foreach ( $items as $item ) {
		$section_values = $item[ CartIntegration::ITEM_KEY ][ (string) $group_id ] ?? [];
		$meal = $section_values['guest_meal'][0] ?? null;
		$lines_by_meal[ $meal ] = $item;
	}
	$assert( [ 'Ada' ] === array_values( $lines_by_meal['soup'][ CartIntegration::ITEM_KEY ][ (string) $group_id ]['guest_name'] ?? [] ), 'First section row was not isolated on its cart line.' );
	$assert( [ 'Grace' ] === array_values( $lines_by_meal['salad'][ CartIntegration::ITEM_KEY ][ (string) $group_id ]['guest_name'] ?? [] ), 'Second section row was not isolated on its cart line.' );
	$assert( 1 === (int) $lines_by_meal['soup']['quantity'] && 1 === (int) $lines_by_meal['salad']['quantity'], 'Distinct section rows did not retain one-unit cart quantities.' );
	$assert( 12.0 === (float) $lines_by_meal['soup']['data']->get_price( 'edit' ) && 13.0 === (float) $lines_by_meal['salad']['data']->get_price( 'edit' ), 'Per-row section choice pricing was not applied to split cart lines.' );
	$selections = CartIntegration::visible_selections( $lines_by_meal['salad']['data'], $lines_by_meal['salad'][ CartIntegration::ITEM_KEY ] );
	$assert( 2 === count( $selections ), 'Cart display did not retain child field rows: ' . wp_json_encode( $selections ) );

	$cart->empty_cart();
	$identical_response = $add_store_item( $product_id, [ (string) $group_id => [
		'guest_name' => [ 'Ada', 'Ada' ],
		'guest_meal' => [ 'soup', 'soup' ],
	] ] );
	$assert( in_array( $identical_response->get_status(), [ 200, 201 ], true ), 'Store API rejected identical quantity-section rows: ' . wp_json_encode( $identical_response->get_data() ) );
	$identical_items = array_values( array_filter( $cart->get_cart(), static fn( $item ) => (int) $item['product_id'] === $product_id ) );
	$assert( 1 === count( $identical_items ) && 2 === (int) $identical_items[0]['quantity'], 'Identical section rows did not merge into a quantity-2 cart line.' );
	$assert( 12.0 === (float) $identical_items[0]['data']->get_price( 'edit' ), 'Merged section row has incorrect per-unit pricing.' );
	$order = new WC_Order();
	$order_item = new WC_Order_Item_Product();
	$order_item->set_product( $identical_items[0]['data'] );
	$order_item->set_quantity( 2 );
	CartIntegration::persist_order_item( $order_item, $identical_items[0]['key'], $identical_items[0], $order );
	$order_values = json_decode( $order_item->get_meta( '_opf_fields', true ), true );
	$assert( [ 'Ada' ] === ( $order_values[ (string) $group_id ]['guest_name'] ?? null ), 'Merged section clone values were not persisted to order metadata.' );
	$assert( 'Ada' === $order_item->get_meta( 'Guest name', true ) && 'Soup' === $order_item->get_meta( 'Guest meal', true ), 'Section child display values were not persisted to order metadata.' );
	$restored = CartIntegration::restore_order_again( [], $order_item, $order );
	$assert( [ 'Ada', 'Ada' ] === array_values( $restored[ CartIntegration::ITEM_KEY ][ (string) $group_id ]['guest_name'] ?? [] ), 'Order-again did not restore each identical quantity-section row.' );

	$cart->empty_cart();
	$invalid_response = $add_store_item( $product_id, [ (string) $group_id => [
		'guest_name' => [ 'Ada' ],
		'guest_meal' => [ 'soup', 'salad' ],
	] ] );
	$assert( 400 === $invalid_response->get_status(), 'Store API accepted a required section field with a missing quantity row.' );
	$assert( false !== strpos( (string) ( $invalid_response->get_data()['message'] ?? '' ), 'exactly 2 rows' ), 'Missing section row did not report the expected quantity mismatch: ' . wp_json_encode( $invalid_response->get_data() ) );

	$cart->empty_cart();
	$invalid_choice_response = $add_store_item( $product_id, [ (string) $group_id => [
		'guest_name' => [ 'Ada', 'Grace' ],
		'guest_meal' => [ 'soup', 'not-a-choice' ],
	] ] );
	$assert( 400 === $invalid_choice_response->get_status(), 'Store API accepted an invalid required section choice.' );
	$assert( false !== strpos( (string) ( $invalid_choice_response->get_data()['message'] ?? '' ), '"Guest meal" is required in repeated row 2.' ), 'Invalid section choice did not fail in its own row: ' . wp_json_encode( $invalid_choice_response->get_data() ) );

	echo "ok Store API quantity-section sanitation, validation, pricing, cart splitting/merge, and cleanup\n";
} finally {
	update_option( 'opf_admin_only', $admin_only_before );
	if ( $group_id > 0 ) {
		wp_delete_post( $group_id, true );
	}
	if ( $product_id > 0 ) {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $key => $item ) {
				if ( (int) ( $item['product_id'] ?? 0 ) === $product_id ) {
					WC()->cart->remove_cart_item( $key );
				}
			}
		}
		wp_delete_post( $product_id, true );
	}
	FieldGroups::flush_cache();
	wc_clear_notices();
}
