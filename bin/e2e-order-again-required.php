<?php
/** Disposable WooCommerce fixtures and durable order-again checks. */
defined( 'ABSPATH' ) || exit;
if ( '1' !== getenv( 'OPF_ORDER_AGAIN_E2E_ALLOW' ) || '/tmp/opf-order-again-required-wp' !== realpath( ABSPATH ) || 'http://127.0.0.1:8173' !== site_url() ) {
	WP_CLI::error( 'Explicit disposable clone and loopback fixture guard required.' );
}
$phase = getenv( 'OPF_ORDER_AGAIN_PHASE' ) ?: 'setup';
$dir = '/tmp/opf-order-again-required-artifacts';
wp_mkdir_p( $dir );
function oa_check( $label, $pass ) { if ( ! $pass ) { WP_CLI::error( $label ); } WP_CLI::log( 'ok ' . $label ); }
if ( 'setup' === $phase ) {
	foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'publish', 'posts_per_page' => -1 ] ) as $post ) { wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'draft' ] ); }
	update_option( 'opf_admin_only', 'no' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_enable_guest_checkout', 'yes' );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF order-again required choices' );
	$product->set_slug( 'opf-order-again-required-choices' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product->set_status( 'publish' );
	$simple = $product->save();
	$variable = new WC_Product_Variable();
	$variable->set_name( 'OPF order-again required variation' );
	$variable->set_slug( 'opf-order-again-required-variation' );
	$variable->set_status( 'publish' );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( [ 'Small' ] );
	$attribute->set_variation( true );
	$variable->set_attributes( [ $attribute ] );
	$parent = $variable->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent );
	$variation->set_regular_price( '30' );
	$variation->set_virtual( true );
	$variation->set_attributes( [ 'size' => 'Small' ] );
	$variation->set_status( 'publish' );
	$variation_id = $variation->save();
	WC_Product_Variable::sync( $parent );
	$group = new OPF\Engine\FieldGroup( [ 'fields' => [
		[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'required' => true, 'choices' => [ [ 'slug' => 'matte', 'label' => 'Matte finish', 'pricing' => [ 'type' => 'fixed', 'amount' => 1.5, 'per_unit' => true ] ] ] ],
		[ 'id' => 'style', 'label' => 'Style', 'type' => 'radio', 'required' => true, 'choices' => [ [ 'slug' => 'gloss', 'label' => 'Gloss style', 'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => false ] ] ] ],
	], 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $simple, (string) $parent ] ] ] ] ] ] );
	$gid = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => 'Order-again required choices' ] );
	$user = wp_insert_user( [ 'user_login' => 'opf-order-again-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'customer', 'user_email' => 'order-again@example.invalid' ] );
	$checkout = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Order-again checkout', 'post_name' => 'order-again-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	update_option( 'woocommerce_checkout_page_id', $checkout );
	$state = [ 'simple' => $simple, 'parent' => $parent, 'variation' => $variation_id, 'group' => $gid, 'user' => $user, 'checkout' => $checkout ];
	update_option( 'opf_order_again_fixture', $state );
	file_put_contents( $dir . '/state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) );
	flush_rewrite_rules();
	oa_check( 'fixtures saved and reloaded in independent disposable Woo clone', $simple && $parent && $variation_id && $gid && ! is_wp_error( $user ) );
	return;
}
$state = get_option( 'opf_order_again_fixture' );
if ( 'prepare' === $phase ) {
	foreach ( json_decode( file_get_contents( $dir . '/fresh-orders.json' ), true ) as $id ) {
		$order = wc_get_order( $id ); $order->set_customer_id( $state['user'] ); $order->set_status( 'completed' ); $order->save();
	}
	foreach ( [ $state['simple'] => 12, $state['variation'] => 35 ] as $id => $price ) { $product = wc_get_product( $id ); $product->set_regular_price( (string) $price ); $product->save(); }
	$group = OPF\Service\FieldGroups::group_from_post( get_post( $state['group'] ) );
	$group->data['fields'][0]['choices'][0]['pricing']['amount'] = 2;
	$group->data['fields'][0]['choices'][0]['disabled'] = false;
	$group->data['fields'][1]['choices'][0]['pricing']['amount'] = 3;
	OPF\Service\FieldGroups::save( $state['group'], $group );
	oa_check( 'original orders completed and current catalog and fees changed', true );
	return;
}
if ( 'retire' === $phase ) {
	$group = OPF\Service\FieldGroups::group_from_post( get_post( $state['group'] ) );
	$group->data['fields'][0]['choices'][0]['disabled'] = true;
	OPF\Service\FieldGroups::save( $state['group'], $group );
	return;
}
if ( 'attach' === $phase ) {
	foreach ( [ 'simple' => 31, 'variation' => 77 ] as $type => $total ) {
		WC()->cart->empty_cart();
		$id = 'simple' === $type ? $state['simple'] : $state['parent'];
		$variation = 'variation' === $type ? $state['variation'] : 0;
		$data = [ 'opf_fields' => [ (string) $state['group'] => [ 'finish' => 'matte', 'style' => 'gloss', 'obsolete' => 'discarded' ] ], 'opf_base_price' => 999, 'other_plugin' => 'preserved' ];
		$key = WC()->cart->add_to_cart( $id, 2, $variation, $variation ? [ 'attribute_size' => 'Small' ] : [], $data );
		oa_check( "$type actual cart attachment accepts restored data without POST", (bool) $key );
		WC()->cart->calculate_totals();
		$item = WC()->cart->get_cart_item( $key );
		oa_check( "$type restored cart attachment rechecks current field definitions", [ (string) $state['group'] => [ 'finish' => 'matte', 'style' => 'gloss' ] ] === $item['opf_fields'] );
		oa_check( "$type restored cart attachment refreshes current base and retains other data", ( 'simple' === $type ? 12.0 : 35.0 ) === $item['opf_base_price'] && 'preserved' === $item['other_plugin'] );
		oa_check( "$type restored cart attachment prices current base and current fees", abs( (float) WC()->cart->get_total( 'edit' ) - $total ) < 0.001 );
	}
	WC()->cart->empty_cart();
	return;
}
foreach ( [ 'fresh' => [ 'simple' => 25, 'variation' => 65 ], 'again' => [ 'simple' => 31, 'variation' => 77 ] ] as $kind => $expected ) {
	$orders = json_decode( file_get_contents( $dir . '/' . $kind . '-orders.json' ), true );
	foreach ( $orders as $path => $id ) {
		$order = wc_get_order( $id );
		oa_check( "$kind $path reloads real Woo order", $order instanceof WC_Order );
		$item = array_values( $order->get_items() )[0];
		$values = json_decode( $item->get_meta( '_opf_fields', true ), true );
		oa_check( "$kind $path persists exact required selections", [ (string) $state['group'] => [ 'finish' => 'matte', 'style' => 'gloss' ] ] === $values );
		oa_check( "$kind $path persists visible selection labels", 'Matte finish' === $item->get_meta( 'Finish', true ) && 'Gloss style' === $item->get_meta( 'Style', true ) );
		$type = false !== strpos( $path, 'variation' ) ? 'variation' : 'simple';
		oa_check( "$kind $path persists quantity and current server total", 2 === $item->get_quantity() && abs( (float) $item->get_total() - $expected[$type] ) < 0.001 );
		oa_check( "$kind $path preserves product and variation identity", 'variation' === $type ? (int) $state['variation'] === $item->get_variation_id() && (int) $state['parent'] === $item->get_product_id() : 0 === $item->get_variation_id() && (int) $state['simple'] === $item->get_product_id() );
	}
}
WP_CLI::success( 'Durable required-choice order-again commerce proof passed.' );
