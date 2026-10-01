<?php
/** Exercise the supported OPF developer API against a disposable WooCommerce site. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'OPF_DEVELOPER_API_E2E' ) ) {
	throw new RuntimeException( 'Run only on a disposable WordPress clone with OPF_DEVELOPER_API_E2E=1.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( OPF\API::class ) ) {
	WP_CLI::error( 'Activate WooCommerce and the OPF source under test first.' );
}

$suffix = wp_generate_password( 8, false, false );
$title = 'OPF developer API fixture ' . $suffix;
$product_id = 0;
$group_id = 0;
$order_id = 0;
$setting = 'opf_api_probe_' . strtolower( $suffix );
$setting_key = $setting;
$filter = 'opf/setting/' . $setting;
$filter_callback = static fn( $value ) => 'filtered-' . (string) $value;
$product = null;
$cart = WC()->cart;

try {
	$product = new WC_Product_Simple();
	$product->set_name( $title );
	$product->set_status( 'publish' );
	$product->set_regular_price( '20' );
	$product_id = (int) $product->save();
	OPF\API::add_formula_function(
		'opf_api_e2e_fee',
		static function ( array $args, array $context ) use ( $product_id ) {
			return 1 === count( $args ) && (int) ( $context['product_id'] ?? 0 ) === $product_id
				? 2 * (float) $args[0]
				: 'invalid';
		}
	);
	$data = OPF\Engine\FieldGroup::normalize( [ 'fields' => [ [
		'id' => 'finish',
		'label' => 'Finish',
		'type' => 'select',
		'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'pricing' => [ 'type' => 'formula', 'formula' => 'opf_api_e2e_fee(3)' ] ] ],
	] ], 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ] ] );
	$group_id = OPF\Service\FieldGroups::save( 0, $data, [ 'title' => $title ] );
	if ( ! $group_id ) {
		throw new RuntimeException( 'Could not create the field group fixture.' );
	}

	$entry = OPF\API::get_field_group_by_id( $group_id );
	if ( ! $entry || 'Finish' !== $entry['group']->data['fields'][0]['label'] ) {
		throw new RuntimeException( 'Field-group lookup did not return normalized OPF data.' );
	}
	if ( 1 !== count( OPF\API::get_field_groups_by_ids( [ 0, $group_id, 99999999 ] ) ) ) {
		throw new RuntimeException( 'ID lookup did not preserve and filter requested groups.' );
	}
	$product_groups = OPF\API::get_field_groups_of_product( $product );
	if ( ! OPF\API::product_has_options( $product_id ) || ! in_array( $group_id, array_column( $product_groups, 'id' ), true ) ) {
		throw new RuntimeException( 'Product placement lookup did not find the matching group.' );
	}
	$html = OPF\API::display_field_groups_for_product( $product );
	if ( false === strpos( $html, 'name="opf[' . $group_id . '][finish]"' ) ) {
		throw new RuntimeException( 'Product field rendering did not return the expected form control.' );
	}

	add_option( $setting_key, false, '', false );
	add_filter( $filter, $filter_callback );
	if ( ! OPF\API::has_setting( $setting ) || 'filtered-' !== OPF\API::get_setting( $setting, 'fallback' ) ) {
		throw new RuntimeException( 'Setting presence, stored-value, or filter behavior failed.' );
	}

	$cart->empty_cart( true );
	$cart_key = $cart->add_to_cart( $product_id, 2 );
	if ( ! $cart_key ) {
		throw new RuntimeException( 'Could not create the cart fixture.' );
	}
	$values = [ (string) $group_id => [ 'finish' => 'oak' ] ];
	$addon = OPF\Service\CartIntegration::addons_per_unit( $product, $values, 20.0, 2 );
	if ( 6.0 !== $addon ) {
		throw new RuntimeException( 'Registered formula callback did not reach server-side field pricing.' );
	}
	$cart->cart_contents[ $cart_key ][ OPF\Service\CartIntegration::ITEM_KEY ] = $values;
	$cart_fields = OPF\API::get_custom_fields_in_cart();
	if ( 1 !== count( $cart_fields ) || 'oak' !== $cart_fields[0]['fields'][0]['value'] ) {
		throw new RuntimeException( 'Cart field retrieval did not return the canonical value.' );
	}

	$order = wc_create_order();
	$item = new WC_Order_Item_Product();
	$item->set_product( $product );
	$item->set_quantity( 2 );
	$order->add_item( $item );
	$order->save();
	$order_id = (int) $order->get_id();
	OPF\Service\CartIntegration::persist_order_item(
		$item,
		'api-fixture',
		[ 'data' => $product, 'quantity' => 2, OPF\Service\CartIntegration::ITEM_KEY => $values ],
		$order
	);
	$item->save();
	$order->save();
	wp_delete_post( $group_id, true );
	$group_id = 0;
	$order_fields = OPF\API::get_options_from_order( $order_id );
	if ( 1 !== count( $order_fields ) || 'Finish' !== $order_fields[0]['options'][0]['label'] || 'oak' !== $order_fields[0]['options'][0]['value'] ) {
		throw new RuntimeException( 'Order field snapshot did not survive field-group deletion.' );
	}
	WP_CLI::success( 'Developer API settings, groups, rendering, cart values, and immutable order snapshot passed.' );
} finally {
	remove_filter( $filter, $filter_callback );
	delete_option( $setting_key );
	if ( $cart ) {
		$cart->empty_cart( true );
	}
	if ( $order_id ) {
		if ( function_exists( 'wc_delete_order' ) ) {
			wc_delete_order( $order_id );
		} else {
			wp_delete_post( $order_id, true );
		}
	}
	if ( $group_id ) {
		wp_delete_post( $group_id, true );
	}
	if ( $product_id ) {
		wp_delete_post( $product_id, true );
	}
}
