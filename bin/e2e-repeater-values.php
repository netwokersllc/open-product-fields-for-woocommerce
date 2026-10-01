<?php
/**
 * Guarded WooCommerce E2E for repeated field sanitation, validation, and pricing.
 *
 * Run inside a disposable WooCommerce install after loading this checkout's
 * autoloader and calling FieldGroups::register_cpt():
 *   OPF_REPEATER_E2E_ALLOW=1 wp eval '... require "bin/e2e-repeater-values.php";'
 */

defined( 'ABSPATH' ) || exit;

if ( '1' !== getenv( 'OPF_REPEATER_E2E_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_REPEATER_E2E_ALLOW=1 only for a disposable WooCommerce database.' );
}

use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;

$product_id = 0;
$group_id = 0;
$admin_only_before = get_option( 'opf_admin_only', 'no' );
$post_before = $_POST['opf'] ?? null;
$had_post_before = array_key_exists( 'opf', $_POST );

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

try {
	update_option( 'opf_admin_only', 'no' );
	FieldGroups::register_cpt();
	CartIntegration::init();

	$product = new WC_Product_Simple();
	$product->set_name( 'OPF repeated value E2E fixture' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product_id = (int) $product->save();
	$assert( $product_id > 0, 'Could not create the repeated-value E2E product.' );

	$group_id = FieldGroups::save( 0, [
		'fields' => [ [
			'id' => 'attendee_name',
			'label' => 'Attendee name',
			'type' => 'text',
			'required' => true,
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3, 'label' => 'Guest {n}' ],
			'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => true ],
		], [
			'id' => 'meal_choice',
			'label' => 'Meal choice',
			'type' => 'checkbox',
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ],
			'choices' => [
				[ 'slug' => 'noodles', 'label' => 'Noodles' ],
				[ 'slug' => 'soup', 'label' => 'Soup' ],
				[ 'slug' => 'salad', 'label' => 'Salad' ],
			],
		], [
			'id' => 'ticket_holder',
			'label' => 'Ticket holder',
			'type' => 'text',
			'required' => true,
			'repeat' => [ 'enabled' => true, 'mode' => 'quantity', 'label' => 'Ticket {n}' ],
			'pricing' => [ 'type' => 'fixed', 'amount' => 1, 'per_unit' => true ],
		] ],
	], [ 'title' => 'OPF repeated value E2E fixture' ] );
	$assert( $group_id > 0, 'Could not create the repeated-value E2E field group.' );

	$_POST['opf'] = [ (string) $group_id => [
		'attendee_name' => [ ' Ada ', 'Grace' ],
		'meal_choice' => [ [ 'noodles', 'soup' ], [ 'salad' ] ],
		'ticket_holder' => [ 'Ada', 'Grace' ],
	] ];
	$assert( CartIntegration::validate_add_to_cart( true, $product_id, 2 ), 'Valid repeated rows matching product quantity were rejected.' );
	$assert( ! CartIntegration::validate_add_to_cart( true, $product_id, 1 ), 'Quantity-repeat rows that do not match product quantity were accepted.' );
	wc_clear_notices();
	$cart_data = CartIntegration::attach( [], $product_id );
	$values = $cart_data[ CartIntegration::ITEM_KEY ][ (string) $group_id ]['attendee_name'] ?? null;
	$assert( [ 'Ada', 'Grace' ] === $values, 'Repeated row sanitation or ordering failed: ' . wp_json_encode( $values ) );
	$meal_values = $cart_data[ CartIntegration::ITEM_KEY ][ (string) $group_id ]['meal_choice'] ?? null;
	$assert( [ [ 'noodles', 'soup' ], [ 'salad' ] ] === $meal_values, 'Repeated multi-choice row sanitation failed: ' . wp_json_encode( $meal_values ) );
	$selections = CartIntegration::visible_selections( wc_get_product( $product_id ), $cart_data[ CartIntegration::ITEM_KEY ] );
	$assert( [
		[ 'label' => 'Attendee name', 'value' => 'Ada' ],
		[ 'label' => 'Guest 2', 'value' => 'Grace' ],
		[ 'label' => 'Meal choice', 'value' => 'Noodles, Soup' ],
		[ 'label' => 'Meal choice', 'value' => 'Salad' ],
		[ 'label' => 'Ticket holder', 'value' => 'Ada' ],
		[ 'label' => 'Ticket 2', 'value' => 'Grace' ],
	] === $selections, 'Repeated cart/order display did not retain per-row labels: ' . wp_json_encode( $selections ) );
	$order_item = new WC_Order_Item_Product();
	$order = new WC_Order();
	$order_cart_item = [ 'data' => wc_get_product( $product_id ), CartIntegration::ITEM_KEY => $cart_data[ CartIntegration::ITEM_KEY ] ];
	CartIntegration::persist_order_item( $order_item, 'opf-repeat-fixture', $order_cart_item, $order );
	$assert( 'Ada' === $order_item->get_meta( 'Attendee name', true ) && 'Grace' === $order_item->get_meta( 'Guest 2', true ), 'Repeated field labels or values were not added to order display metadata.' );
	$order_values = json_decode( $order_item->get_meta( '_opf_fields', true ), true );
	$assert( $cart_data[ CartIntegration::ITEM_KEY ] === $order_values, 'Structured repeated values were not persisted to the order item.' );
	$restored = CartIntegration::restore_order_again( [], $order_item, $order );
	$assert( $cart_data[ CartIntegration::ITEM_KEY ] === ( $restored[ CartIntegration::ITEM_KEY ] ?? null ), 'Order-again did not restore repeated values.' );
	$addon = CartIntegration::addons_per_unit( wc_get_product( $product_id ), $cart_data[ CartIntegration::ITEM_KEY ], 10.0, 1 );
	$assert( 6.0 === $addon, 'Repeated row pricing was not summed: ' . (string) $addon );
	$_POST['quantity'] = 2;
	$cart_add = WC()->cart->add_to_cart( $product_id, 2 );
	$assert( false !== $cart_add, 'Quantity-repeat fixture could not be added through WooCommerce cart.' );
	$fixture_cart_items = array_filter( WC()->cart->get_cart(), static fn( $item ) => (int) $item['product_id'] === $product_id );
	$assert( 2 === count( $fixture_cart_items ), 'Distinct quantity clone values were not split into per-unit cart lines: ' . wp_json_encode( array_column( $fixture_cart_items, 'quantity' ) ) );
	$unit_labels = [];
	foreach ( $fixture_cart_items as $cart_item ) {
		$unit_labels[] = CartIntegration::visible_selections( $cart_item['data'], $cart_item[ CartIntegration::ITEM_KEY ] );
	}
	$assert(
		[ 'label' => 'Ticket holder', 'value' => 'Ada' ] === $unit_labels[0][4]
		&& [ 'label' => 'Ticket 2', 'value' => 'Grace' ] === $unit_labels[1][4],
		'Quantity-cloned values or labels were not assigned to the matching cart line.'
	);

	WC()->cart->empty_cart();
	wc_clear_notices();
	$store_request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$store_request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$store_request->set_param( 'id', $product_id );
	$store_request->set_param( 'quantity', 2 );
	$store_request->set_param( 'opf_fields', [
		(string) $group_id => [
			'attendee_name' => [ 'Ada', 'Grace' ],
			'ticket_holder' => [ 'Ada', 'Grace' ],
		],
	] );
	$store_response = rest_get_server()->dispatch( $store_request );
	$assert(
		in_array( $store_response->get_status(), [ 200, 201 ], true ),
		'Store API rejected quantity-repeated fields: ' . wp_json_encode( $store_response->get_data() )
	);
	$store_items = array_values( array_filter(
		WC()->cart->get_cart(),
		static fn( $item ) => (int) ( $item['product_id'] ?? 0 ) === $product_id
	) );
	$assert( 2 === count( $store_items ), 'Store API did not split distinct quantity clones into two cart lines: ' . wp_json_encode( array_column( $store_items, 'quantity' ) ) );
	$store_labels = array_map(
		static fn( $item ) => CartIntegration::visible_selections( $item['data'], $item[ CartIntegration::ITEM_KEY ] ),
		$store_items
	);
	$store_ticket_labels = array_map(
		static fn( $selections ) => array_values( array_filter(
			$selections,
			static fn( $selection ) => in_array( $selection['label'] ?? '', [ 'Ticket holder', 'Ticket 2' ], true )
		) ),
		$store_labels
	);
	$assert(
		1 === (int) $store_items[0]['quantity']
		&& 1 === (int) $store_items[1]['quantity']
		&& [ [ 'label' => 'Ticket holder', 'value' => 'Ada' ] ] === $store_ticket_labels[0]
		&& [ [ 'label' => 'Ticket 2', 'value' => 'Grace' ] ] === $store_ticket_labels[1],
		'Store API quantity clones lost their per-line values or labels: ' . wp_json_encode( $store_ticket_labels )
	);
	WC()->cart->calculate_totals();
	$priced_store_items = array_values( array_filter(
		WC()->cart->get_cart(),
		static fn( $item ) => (int) ( $item['product_id'] ?? 0 ) === $product_id
	) );
	$assert(
		2 === count( $priced_store_items )
		&& 15.0 === (float) $priced_store_items[0]['data']->get_price()
		&& 15.0 === (float) $priced_store_items[1]['data']->get_price(),
		'Store API per-unit repeated field pricing was not applied to both lines: ' . wp_json_encode( array_map(
			static fn( $item ) => [
				'quantity' => $item['quantity'],
				'price' => $item['data']->get_price( 'edit' ),
				'base' => $item['opf_base_price'] ?? null,
				'values' => $item[ CartIntegration::ITEM_KEY ] ?? null,
			],
			$priced_store_items
		) )
	);
	$store_order = new WC_Order();
	$store_order_items = [];
	foreach ( $priced_store_items as $store_item ) {
		$order_item_id = $store_order->add_product( $store_item['data'], (int) $store_item['quantity'] );
		$store_order_item = $store_order->get_item( $order_item_id );
		CartIntegration::persist_order_item( $store_order_item, $store_item['key'], $store_item, $store_order );
		$store_order_items[] = $store_order_item;
	}
	$assert(
		2 === count( $store_order_items )
		&& 'Ada' === $store_order_items[0]->get_meta( 'Ticket holder', true )
		&& 'Grace' === $store_order_items[1]->get_meta( 'Ticket 2', true )
		&& [ 'Ada' ] === ( json_decode( $store_order_items[0]->get_meta( '_opf_fields', true ), true )[ (string) $group_id ]['ticket_holder'] ?? null )
		&& [ 1 => 'Grace' ] === ( json_decode( $store_order_items[1]->get_meta( '_opf_fields', true ), true )[ (string) $group_id ]['ticket_holder'] ?? null ),
		'Store API quantity clone values or labels were not persisted to order items.'
	);

	$merge_group_data = json_decode( (string) get_post_field( 'post_content', $group_id ), true );
	foreach ( $merge_group_data['fields'] as &$merge_field ) {
		if ( 'ticket_holder' === ( $merge_field['id'] ?? '' ) ) {
			unset( $merge_field['repeat']['label'] );
		}
	}
	unset( $merge_field );
	$assert( $group_id === FieldGroups::save( $group_id, $merge_group_data, [ 'title' => 'OPF repeated value E2E fixture' ] ), 'Could not switch quantity clone labels to the WAPF default for merge verification.' );

	WC()->cart->empty_cart();
	$merge_request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$merge_request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$merge_request->set_param( 'id', $product_id );
	$merge_request->set_param( 'quantity', 2 );
	$merge_request->set_param( 'opf_fields', [
		(string) $group_id => [
			'attendee_name' => [ 'Ada', 'Grace' ],
			'ticket_holder' => [ 'Ada', 'Ada' ],
		],
	] );
	$merge_response = rest_get_server()->dispatch( $merge_request );
	$assert( in_array( $merge_response->get_status(), [ 200, 201 ], true ), 'Store API rejected identical quantity clones: ' . wp_json_encode( $merge_response->get_data() ) );
	$merged_items = array_values( array_filter(
		WC()->cart->get_cart(),
		static fn( $item ) => (int) ( $item['product_id'] ?? 0 ) === $product_id
	) );
	$merged_ticket_labels = 1 === count( $merged_items )
		? array_values( array_filter(
			CartIntegration::visible_selections( $merged_items[0]['data'], $merged_items[0][ CartIntegration::ITEM_KEY ] ),
			static fn( $selection ) => 'Ticket holder' === ( $selection['label'] ?? '' )
		) )
		: [];
	$assert(
		1 === count( $merged_items )
		&& 2 === (int) $merged_items[0]['quantity']
		&& [ 'Ada' ] === ( $merged_items[0][ CartIntegration::ITEM_KEY ][ (string) $group_id ]['ticket_holder'] ?? null )
		&& [ [ 'label' => 'Ticket holder', 'value' => 'Ada' ] ] === $merged_ticket_labels,
		'Identical quantity clones did not merge while preserving the shared value and label: ' . wp_json_encode( [ 'items' => array_column( $merged_items, 'quantity' ), 'labels' => $merged_ticket_labels ] )
	);

	$_POST['opf'] = [ (string) $group_id => [ 'attendee_name' => [ 'Ada', '' ], 'ticket_holder' => [ 'Ada', 'Grace' ] ] ];
	$assert( ! CartIntegration::validate_add_to_cart( true, $product_id, 2 ), 'A required empty repeated row was accepted.' );
	wc_clear_notices();

	$_POST['opf'] = [ (string) $group_id => [ 'attendee_name' => [ 'A', 'B', 'C', 'D' ], 'ticket_holder' => [ 'Ada', 'Grace' ] ] ];
	$assert( ! CartIntegration::validate_add_to_cart( true, $product_id, 2 ), 'More than the configured button maximum was accepted.' );
	wc_clear_notices();

	echo "ok repeated row sanitation/order, custom numbered labels, classic and Store API quantity-clone cart lines, identical-clone merge, cart/order display, Store API clone order metadata, order-again restore, required-row validation, maximum enforcement, and price summation\n";
} finally {
	if ( $had_post_before ) {
		$_POST['opf'] = $post_before;
	} else {
		unset( $_POST['opf'] );
	}
	update_option( 'opf_admin_only', $admin_only_before );
	if ( $group_id > 0 ) {
		wp_delete_post( $group_id, true );
	}
	if ( $product_id > 0 ) {
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( (int) ( $cart_item['product_id'] ?? 0 ) === $product_id ) {
				WC()->cart->remove_cart_item( $cart_item_key );
			}
		}
		wp_delete_post( $product_id, true );
	}
	FieldGroups::flush_cache();
	wc_clear_notices();
}
