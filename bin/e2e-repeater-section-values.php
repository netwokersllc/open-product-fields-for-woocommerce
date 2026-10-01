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
			[ 'id' => 'attendees', 'label' => 'Attendees', 'type' => 'section', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ],
			[ 'id' => 'guest_name', 'label' => 'Guest name', 'type' => 'text', 'required' => true ],
			[ 'id' => 'guest_meal', 'label' => 'Guest meal', 'type' => 'select', 'required' => true, 'choices' => [
				[ 'slug' => 'soup', 'label' => 'Soup' ],
				[ 'slug' => 'salad', 'label' => 'Salad' ],
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
	$assert( 1 === count( $items ), 'Store API did not create the quantity-section fixture cart item.' );
	$section_values = $items[0][ CartIntegration::ITEM_KEY ][ (string) $group_id ] ?? [];
	$assert( [ 'Ada', 'Grace' ] === $section_values['guest_name'] && [ 'soup', 'salad' ] === $section_values['guest_meal'], 'Section clone values were not sanitized and retained by row index: ' . wp_json_encode( $section_values ) );
	$selections = CartIntegration::visible_selections( $items[0]['data'], $items[0][ CartIntegration::ITEM_KEY ] );
	$assert( 4 === count( $selections ), 'Cart display did not expand section clone values into scalar rows: ' . wp_json_encode( $selections ) );

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

	echo "ok Store API quantity-section child sanitation, exact row validation, and cleanup\n";
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
