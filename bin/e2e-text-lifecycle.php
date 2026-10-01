<?php
/**
 * Real disposable Woo proof for the native single-line text field.
 * OPF_TEXT_E2E_ALLOW=1 OPF_TEXT_E2E_PHASE=setup|commerce|cleanup wp eval-file bin/e2e-text-lifecycle.php
 * Browser proof runs between setup and commerce. Never run against production.
 */
if ( '1' !== getenv( 'OPF_TEXT_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'This proof requires an explicitly authorized disposable /tmp WordPress site.' );
}
function text_check( string $label, bool $ok ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
	echo "ok $label\n";
}
$phase = getenv( 'OPF_TEXT_E2E_PHASE' ) ?: 'commerce';
$state = get_option( 'opf_text_e2e_state', [] );
if ( 'setup' === $phase ) {
	text_check( 'no pre-existing fixture', ! $state );
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF single-line text lifecycle fixture' );
	$product->set_slug( 'opf-text-lifecycle' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$pid = $product->save();
	$gid = OPF\Service\FieldGroups::save( 0, [
		'fields' => [
			[ 'id' => 'message', 'type' => 'text', 'label' => 'Personal message', 'required' => true ],
			[ 'id' => 'prefill', 'type' => 'text', 'label' => 'Default message', 'required' => true, 'default' => 'Initial value' ],
			[ 'id' => 'optional', 'type' => 'text', 'label' => 'Optional message' ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
	], [ 'title' => 'OPF text lifecycle', 'status' => 'publish' ] );
	$uid = wp_create_user( 'opf_text_e2e', wp_generate_password( 32 ), 'text@example.invalid' );
	text_check( 'fixture administrator created', ! is_wp_error( $uid ) );
	( new WP_User( $uid ) )->set_role( 'administrator' );
	$state = [ 'product' => $pid, 'group' => $gid, 'user' => $uid, 'orders' => [] ];
	update_option( 'opf_text_e2e_state', $state );
	update_option( 'opf_admin_only', 'no' );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	file_put_contents( '/tmp/opf-text-state.json', wp_json_encode( $state ) );
	echo "ok fixtures created\n";
	return;
}
text_check( 'fixture exists', ! empty( $state['group'] ) );
if ( 'cleanup' === $phase ) {
	// Repeated browser attempts can create orders before their manifests are read.
	foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $candidate ) {
		foreach ( $candidate->get_items() as $item ) {
			if ( (int) $state['product'] === $item->get_product_id() ) {
				$state['orders'][] = $candidate->get_id();
				break;
			}
		}
	}
	foreach ( array_unique( $state['orders'] ) as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) { $order->delete( true ); }
	}
	wp_delete_post( $state['group'], true );
	wp_delete_post( $state['product'], true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $state['user'] );
	delete_option( 'opf_text_e2e_state' );
	echo "ok fixture cleanup\n";
	return;
}
$gid = (string) $state['group'];
$group = OPF\Service\FieldGroups::group_from_post( get_post( $state['group'] ) );
text_check( 'browser admin save persists label required placeholder and default',
	'Personal message edited' === $group->data['fields'][0]['label']
	&& true === $group->data['fields'][0]['required']
	&& 'Type your message' === $group->data['fields'][0]['placeholder']
	&& 'Saved default' === $group->data['fields'][1]['default'] );
$browser_order_file = getenv( 'OPF_TEXT_BROWSER_ORDER_FILE' ) ?: '/tmp/opf-text-artifacts/browser-order.json';
text_check( 'browser order manifest exists', is_file( $browser_order_file ) );
$browser_order_id = json_decode( file_get_contents( $browser_order_file ), true )['order_id'];
$browser_order = wc_get_order( $browser_order_id );
text_check( 'browser checkout order reloads from Woo data store', $browser_order instanceof WC_Order );
$browser_item = array_values( $browser_order->get_items() )[0];
$browser_stored = json_decode( $browser_item->get_meta( '_opf_fields', true ), true );
text_check( 'browser checkout text and default persisted', 'Browser Ada' === $browser_stored[$gid]['message'] && 'Saved default' === $browser_stored[$gid]['prefill'] );
$state['orders'][] = $browser_order_id;
update_option( 'opf_text_e2e_state', $state );
if ( ! WC()->cart ) { wc_load_cart(); }
add_filter( 'pre_wp_mail', '__return_true' ); // Generate email bodies, never deliver mail.
$cart = WC()->cart;
$cart->empty_cart();
$_POST['opf'] = [ $gid => [ 'message' => '' ] ];
wc_clear_notices();
text_check( 'classic required empty rejected', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
text_check( 'classic required error notice', wc_notice_count( 'error' ) > 0 );
$_POST['opf'] = [ $gid => [ 'message' => [ 'forged array' ] ] ];
wc_clear_notices();
text_check( 'classic required array rejected', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
$_POST['opf'] = [ $gid => [ 'message' => 'Ada', 'prefill' => '' ] ];
wc_clear_notices();
text_check( 'explicitly cleared required default rejected', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
$_POST['opf'] = [ $gid => [ 'message' => "  Hola <b>Ada</b>\nLovelace  " ] ];
wc_clear_notices();
$key = $cart->add_to_cart( $state['product'], 1 );
unset( $_POST['opf'] );
text_check( 'classic cart accepts text', (bool) $key );
$line = $cart->get_cart_item( $key );
text_check( 'classic sanitized single line and omitted default captured',
	'Hola Ada Lovelace' === $line['opf_fields'][$gid]['message']
	&& 'Saved default' === $line['opf_fields'][$gid]['prefill']
	&& ! isset( $line['opf_fields'][$gid]['optional'] ) );
$cart->empty_cart();
$dispatch = static function ( string $path, array $params ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/' . $path );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	foreach ( $params as $name => $value ) { $request->set_param( $name, $value ); }
	return rest_get_server()->dispatch( $request );
};
$bad = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => [ $gid => [ 'message' => '' ] ] ] );
text_check( 'Store API required empty rejected with empty cart', $bad->get_status() >= 400 && 0 === count( $cart->get_cart() ) );
$response = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 2, 'opf_fields' => [ $gid => [ 'message' => 'Store Ada', 'prefill' => 'Custom override' ] ] ] );
text_check( 'Store API accepts text', in_array( $response->get_status(), [ 200, 201 ], true ) );
$line = reset( $cart->cart_contents );
text_check( 'Store API explicit override captured', 'Custom override' === $line['opf_fields'][$gid]['prefill'] );
$display = wp_json_encode( $response->get_data()['items'][0]['item_data'] );
text_check( 'Store API cart display includes text and default override', false !== strpos( $display, 'Store Ada' ) && false !== strpos( $display, 'Custom override' ) );
WC()->payment_gateways()->init();
$checkout = $dispatch( 'checkout', [
	'payment_method' => 'bacs',
	'billing_address' => [ 'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'text@example.invalid', 'address_1' => '1 Test Street', 'city' => 'Testville', 'postcode' => '90210', 'country' => 'US', 'state' => 'CA' ],
] );
text_check( 'Store API checkout accepted', in_array( $checkout->get_status(), [ 200, 201 ], true ) );
$order = wc_get_order( $checkout->get_data()['order_id'] );
$state['orders'][] = $order->get_id();
update_option( 'opf_text_e2e_state', $state );
$item = array_values( $order->get_items() )[0];
$stored = json_decode( $item->get_meta( '_opf_fields', true ), true );
text_check( 'order structured text persisted', 'Store Ada' === $stored[$gid]['message'] && 'Custom override' === $stored[$gid]['prefill'] );
text_check( 'order public display meta persisted', 'Store Ada' === $item->get_meta( 'Personal message edited', true ) );
text_check( 'order unchanged base total', abs( 20 - (float) $item->get_total() ) < 0.001 );
$email = new WC_Email_Customer_On_Hold_Order();
$email->object = $order;
$email->recipient = 'text@example.invalid';
foreach ( [ 'get_content_html', 'get_content_plain' ] as $method ) {
	$content = $email->$method();
	text_check( "email $method includes labels and values", false !== strpos( $content, 'Personal message edited' ) && false !== strpos( $content, 'Store Ada' ) && false !== strpos( $content, 'Custom override' ) );
}
$again = apply_filters( 'woocommerce_order_again_cart_item_data', [], $item, $order );
text_check( 'order again restores exact text values', $again['opf_fields'] === $stored );
$cart->empty_cart();
$key = $cart->add_to_cart( $state['product'], 2, 0, [], $again );
text_check( 'order again cart preserves restored selections', (bool) $key && $cart->get_cart_item( $key )['opf_fields'] === $stored );
$cart->empty_cart();
echo "SUCCESS native text commerce lifecycle\n";
