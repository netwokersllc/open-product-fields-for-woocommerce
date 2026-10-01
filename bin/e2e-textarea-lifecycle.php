<?php
/** Disposable real WooCommerce proof for textarea newlines and safe output. */
if ( '1' !== getenv( 'OPF_TEXTAREA_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Requires explicitly authorized disposable /tmp WordPress.' );
}
function textarea_check( string $label, bool $ok ): void {
	if ( ! $ok ) { throw new RuntimeException( $label ); }
	echo "ok $label\n";
}
$phase = getenv( 'OPF_TEXTAREA_E2E_PHASE' ) ?: 'commerce';
$state = get_option( 'opf_textarea_e2e_state', [] );
if ( 'setup' === $phase ) {
	textarea_check( 'no pre-existing fixture', ! $state );
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF textarea newline lifecycle fixture' );
	$product->set_slug( 'opf-textarea-lifecycle' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$pid = $product->save();
	$gid = OPF\Service\FieldGroups::save( 0, [
		'fields' => [
			[ 'id' => 'note', 'type' => 'textarea', 'label' => 'Delivery note', 'required' => true ],
			[ 'id' => 'optional', 'type' => 'textarea', 'label' => 'Optional note' ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
	], [ 'title' => 'OPF textarea newline lifecycle', 'status' => 'publish' ] );
	$uid = wp_create_user( 'opf_textarea_e2e', wp_generate_password( 32 ), 'textarea@example.invalid' );
	textarea_check( 'fixture administrator created', ! is_wp_error( $uid ) );
	( new WP_User( $uid ) )->set_role( 'administrator' );
	$state = [ 'product' => $pid, 'group' => $gid, 'user' => $uid, 'orders' => [] ];
	update_option( 'opf_textarea_e2e_state', $state );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	file_put_contents( '/tmp/opf-textarea-state.json', wp_json_encode( $state ) );
	echo "ok fixtures created\n";
	return;
}
textarea_check( 'fixture exists', ! empty( $state['group'] ) );
if ( 'cleanup' === $phase ) {
	foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $candidate ) {
		foreach ( $candidate->get_items() as $item ) {
			if ( (int) $state['product'] === $item->get_product_id() ) { $state['orders'][] = $candidate->get_id(); break; }
		}
	}
	foreach ( array_unique( $state['orders'] ) as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	wp_delete_post( $state['group'], true );
	wp_delete_post( $state['product'], true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $state['user'] );
	delete_option( 'opf_textarea_e2e_state' );
	echo "ok fixture cleanup\n";
	return;
}
$gid = (string) $state['group'];
$group = OPF\Service\FieldGroups::group_from_post( get_post( $state['group'] ) );
textarea_check( 'browser admin save/reload retained textarea type and edited label', 'textarea' === $group->data['fields'][0]['type'] && 'Delivery note edited' === $group->data['fields'][0]['label'] );
if ( ! WC()->cart ) { wc_load_cart(); }
add_filter( 'pre_wp_mail', '__return_true' );
$cart = WC()->cart;
$cart->empty_cart();
$_POST['opf'] = [ $gid => [ 'note' => [ 'forged array' ] ] ];
wc_clear_notices();
textarea_check( 'classic forged array rejected', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
$_POST['opf'] = [ $gid => [ 'note' => "  First line\r\nSecond <b>line</b> & safe  " ] ];
wc_clear_notices();
$key = $cart->add_to_cart( $state['product'], 1 );
unset( $_POST['opf'] );
textarea_check( 'classic cart accepts textarea', (bool) $key );
$line = $cart->get_cart_item( $key );
$classic_expected = "First line\r\nSecond line & safe";
file_put_contents( '/tmp/opf-textarea-artifacts/classic-cart-value.json', wp_json_encode( $line['opf_fields'][$gid]['note'] ) );
textarea_check( 'classic cart preserves browser CRLF and strips markup', $classic_expected === $line['opf_fields'][$gid]['note'] );
$cart->empty_cart();
$dispatch = static function ( string $path, array $params ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/' . $path );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	foreach ( $params as $name => $value ) { $request->set_param( $name, $value ); }
	return rest_get_server()->dispatch( $request );
};
$invalid = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => [ $gid => [ 'note' => [ 'forged array' ] ] ] ] );
textarea_check( 'Store API forged array rejected with empty cart', $invalid->get_status() >= 400 && 0 === count( $cart->get_cart() ) );
$response = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => [ $gid => [ 'note' => "First line\nSecond <b>line</b> & safe" ] ] ] );
textarea_check( 'Store API accepts textarea', in_array( $response->get_status(), [ 200, 201 ], true ) );
$store_line = reset( $cart->cart_contents );
$expected = "First line\nSecond line & safe";
textarea_check( 'Store API cart preserves submitted LF and strips markup', $expected === $store_line['opf_fields'][$gid]['note'] );
$display = wp_json_encode( $response->get_data()['items'][0]['item_data'] );
textarea_check( 'Store API display keeps safe escaped multiline content', false !== strpos( $display, 'First line' ) && false !== strpos( $display, 'Second line' ) && false !== strpos( $display, '&amp;' ) && false === strpos( $display, '<b>' ) );
$runtime_display = wc_get_formatted_cart_item_data( $store_line );
file_put_contents( '/tmp/opf-textarea-artifacts/runtime-display.html', $runtime_display );
textarea_check( 'Woo cart item-data HTML escapes text and renders preserved newline as a line break', false !== strpos( $runtime_display, 'First line<br' ) && false !== strpos( $runtime_display, "Second line &amp; safe" ) && false === strpos( $runtime_display, '<b>line</b>' ) );
WC()->payment_gateways()->init();
$checkout = $dispatch( 'checkout', [ 'payment_method' => 'bacs', 'billing_address' => [ 'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'textarea@example.invalid', 'address_1' => '1 Test Street', 'city' => 'Testville', 'postcode' => '90210', 'country' => 'US', 'state' => 'CA' ] ] );
textarea_check( 'Store API checkout accepted', in_array( $checkout->get_status(), [ 200, 201 ], true ) );
$order = wc_get_order( $checkout->get_data()['order_id'] );
$state['orders'][] = $order->get_id();
update_option( 'opf_textarea_e2e_state', $state );
$item = array_values( $order->get_items() )[0];
$ordered = json_decode( $item->get_meta( '_opf_fields', true ), true );
textarea_check( 'order structured metadata preserves newlines', $expected === $ordered[$gid]['note'] );
textarea_check( 'order public display metadata is escaped and retains line breaks', false !== strpos( $item->get_meta( 'Delivery note edited', true ), "First line\nSecond line" ) );
$email = new WC_Email_Customer_On_Hold_Order();
$email->object = $order;
$email->recipient = 'textarea@example.invalid';
$html = $email->get_content_html();
$plain = $email->get_content_plain();
textarea_check( 'HTML email renders multiline value safely', false !== strpos( $html, 'First line' ) && false !== strpos( $html, 'Second line' ) && false === strpos( $html, '<b>line</b>' ) );
textarea_check( 'plain email preserves multiline value', false !== strpos( $plain, "First line\nSecond line" ) );
$again = apply_filters( 'woocommerce_order_again_cart_item_data', [], $item, $order );
textarea_check( 'order again data restores exact multiline value', $again['opf_fields'] === $ordered );
$cart->empty_cart();
$again_key = $cart->add_to_cart( $state['product'], 1, 0, [], $again );
textarea_check( 'order again cart keeps exact multiline value', (bool) $again_key && $cart->get_cart_item( $again_key )['opf_fields'] === $ordered );
$cart->empty_cart();
echo "SUCCESS textarea newline lifecycle\n";
