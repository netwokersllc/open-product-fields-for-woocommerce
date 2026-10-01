<?php
/**
 * Real disposable Woo proof for the native URL field.
 * OPF_URL_E2E_ALLOW=1 OPF_URL_E2E_PHASE=setup|commerce|cleanup wp eval-file bin/e2e-url-lifecycle.php
 * Browser proof runs between setup and commerce. Never run against production.
 */
if ( '1' !== getenv( 'OPF_URL_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'This proof requires an explicitly authorized disposable /tmp WordPress site.' );
}
function url_check( string $label, bool $ok ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
	echo "ok $label\n";
}
$phase = getenv( 'OPF_URL_E2E_PHASE' ) ?: 'commerce';
$state = get_option( 'opf_url_e2e_state', [] );
if ( 'setup' === $phase ) {
	url_check( 'no pre-existing fixture', ! $state );
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF native URL lifecycle fixture' );
	$product->set_slug( 'opf-url-lifecycle' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$pid = $product->save();
	$gid = OPF\Service\FieldGroups::save( 0, [
		'fields' => [
			[ 'id' => 'message', 'type' => 'url', 'label' => 'Profile URL', 'required' => true ],
			[ 'id' => 'prefill', 'type' => 'url', 'label' => 'Default URL', 'required' => true, 'default' => 'https://example.invalid/initial' ],
			[ 'id' => 'optional', 'type' => 'url', 'label' => 'Optional URL' ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
	], [ 'title' => 'OPF URL lifecycle', 'status' => 'publish' ] );
	$uid = wp_create_user( 'opf_url_e2e', wp_generate_password( 32 ), 'url@example.invalid' );
	url_check( 'fixture administrator created', ! is_wp_error( $uid ) );
	( new WP_User( $uid ) )->set_role( 'administrator' );
	$state = [ 'product' => $pid, 'group' => $gid, 'user' => $uid, 'orders' => [] ];
	update_option( 'opf_url_e2e_state', $state );
	update_option( 'opf_admin_only', 'no' );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	file_put_contents( '/tmp/opf-url-state.json', wp_json_encode( $state ) );
	echo "ok fixtures created\n";
	return;
}
url_check( 'fixture exists', ! empty( $state['group'] ) );
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
	delete_option( 'opf_url_e2e_state' );
	echo "ok fixture cleanup\n";
	return;
}
$gid = (string) $state['group'];
$group = OPF\Service\FieldGroups::group_from_post( get_post( $state['group'] ) );
url_check( 'browser admin save persists label required placeholder and default',
	'Profile URL edited' === $group->data['fields'][0]['label']
	&& true === $group->data['fields'][0]['required']
	&& 'https://example.invalid/' === $group->data['fields'][0]['placeholder']
	&& 'https://example.invalid/default?a=1&b=2' === $group->data['fields'][1]['default'] );
$browser_order_file = getenv( 'OPF_URL_BROWSER_ORDER_FILE' ) ?: '/tmp/opf-url-artifacts/browser-order.json';
url_check( 'browser order manifest exists', is_file( $browser_order_file ) );
$browser_order_id = json_decode( file_get_contents( $browser_order_file ), true )['order_id'];
$browser_order = wc_get_order( $browser_order_id );
url_check( 'browser checkout order reloads from Woo data store', $browser_order instanceof WC_Order );
$browser_item = array_values( $browser_order->get_items() )[0];
$browser_stored = json_decode( $browser_item->get_meta( '_opf_fields', true ), true );
url_check( 'browser checkout URL and default persisted', 'https://example.invalid/browser?a=1&b=2' === $browser_stored[$gid]['message'] && 'https://example.invalid/default?a=1&b=2' === $browser_stored[$gid]['prefill'] );
$state['orders'][] = $browser_order_id;
update_option( 'opf_url_e2e_state', $state );
if ( ! WC()->cart ) { wc_load_cart(); }
add_filter( 'pre_wp_mail', '__return_true' ); // Generate email bodies, never deliver mail.
$cart = WC()->cart;
$cart->empty_cart();
$_POST['opf'] = [ $gid => [ 'message' => '' ] ];
wc_clear_notices();
url_check( 'classic required empty rejected', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
url_check( 'classic required error notice', wc_notice_count( 'error' ) > 0 );
$_POST['opf'] = [ $gid => [ 'message' => [ 'forged array' ] ] ];
wc_clear_notices();
url_check( 'classic required array rejected', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
$_POST['opf'] = [ $gid => [ 'message' => 'https://example.invalid/ada', 'prefill' => '' ] ];
wc_clear_notices();
url_check( 'explicitly cleared required default rejected', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
$_POST['opf'] = [ $gid => [ 'message' => '  https://example.invalid/classic?a=1&b=2  ' ] ];
wc_clear_notices();
$key = $cart->add_to_cart( $state['product'], 1 );
unset( $_POST['opf'] );
url_check( 'classic cart accepts URL', (bool) $key );
$line = $cart->get_cart_item( $key );
url_check( 'classic trimmed URL and omitted default captured',
	'https://example.invalid/classic?a=1&b=2' === $line['opf_fields'][$gid]['message']
	&& 'https://example.invalid/default?a=1&b=2' === $line['opf_fields'][$gid]['prefill']
	&& ! isset( $line['opf_fields'][$gid]['optional'] ) );
$cart->empty_cart();
$dispatch = static function ( string $path, array $params ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/' . $path );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	foreach ( $params as $name => $value ) { $request->set_param( $name, $value ); }
	return rest_get_server()->dispatch( $request );
};
foreach ( [ 'https://example.invalid/', 'http://localhost/x', 'ftp://example.invalid/x', 'ftps://example.invalid/x', 'mailto:ada@example.invalid', 'irc://example.invalid/channel' ] as $valid_url ) {
	$_POST['opf'] = [ $gid => [ 'message' => $valid_url, 'optional' => '' ] ];
	wc_clear_notices();
	url_check( 'classic valid URL scheme and empty optional accepted ' . $valid_url, true === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
	unset( $_POST['opf'] );
	$valid_response = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => [ $gid => [ 'message' => $valid_url ] ] ] );
	url_check( 'Store API valid URL scheme accepted ' . $valid_url, in_array( $valid_response->get_status(), [ 200, 201 ], true ) );
	$cart->empty_cart();
}
foreach ( [ 'not-a-url', 'example.invalid/path', 'https://', 'javascript:alert(1)', 'data:text/html,x', 'https://example.invalid/<script>', 'https://example.invalid/"onclick="x', [ 'https://example.invalid/' ] ] as $invalid ) {
	$_POST['opf'] = [ $gid => [ 'message' => $invalid ] ];
	wc_clear_notices();
	url_check( 'classic malformed URL rejected ' . wp_json_encode( $invalid ), false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
	unset( $_POST['opf'] );
	$bad_url = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => [ $gid => [ 'message' => $invalid ] ] ] );
	url_check( 'Store API malformed URL rejected with empty cart ' . wp_json_encode( $invalid ), $bad_url->get_status() >= 400 && 0 === count( $cart->get_cart() ) );
}
foreach ( [ 'not-a-url', [ 'https://example.invalid/' ] ] as $invalid ) {
	$_POST['opf'] = [ $gid => [ 'message' => 'https://example.invalid/', 'optional' => $invalid ] ];
	wc_clear_notices();
	url_check( 'classic nonempty malformed optional URL rejected', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
	unset( $_POST['opf'] );
	$bad_optional = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => [ $gid => [ 'message' => 'https://example.invalid/', 'optional' => $invalid ] ] ] );
	url_check( 'Store API nonempty malformed optional URL rejected', $bad_optional->get_status() >= 400 && 0 === count( $cart->get_cart() ) );
}
$empty_optional = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => [ $gid => [ 'message' => 'https://example.invalid/', 'optional' => '' ] ] ] );
url_check( 'Store API empty optional and omitted URL default accepted', in_array( $empty_optional->get_status(), [ 200, 201 ], true ) );
$optional_line = reset( $cart->cart_contents );
url_check( 'Store API omitted URL default captured and empty optional omitted', 'https://example.invalid/default?a=1&b=2' === $optional_line['opf_fields'][$gid]['prefill'] && ! isset( $optional_line['opf_fields'][$gid]['optional'] ) );
$cart->empty_cart();
$cleared_default = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => [ $gid => [ 'message' => 'https://example.invalid/', 'prefill' => '' ] ] ] );
url_check( 'Store API explicitly cleared required URL default rejected', $cleared_default->get_status() >= 400 && 0 === count( $cart->get_cart() ) );
$bad = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => [ $gid => [ 'message' => '' ] ] ] );
url_check( 'Store API required empty rejected with empty cart', $bad->get_status() >= 400 && 0 === count( $cart->get_cart() ) );
$response = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 2, 'opf_fields' => [ $gid => [ 'message' => 'https://example.invalid/store?a=1&b=2', 'prefill' => 'https://example.invalid/override' ] ] ] );
url_check( 'Store API accepts URL', in_array( $response->get_status(), [ 200, 201 ], true ) );
$line = reset( $cart->cart_contents );
url_check( 'Store API explicit override captured', 'https://example.invalid/override' === $line['opf_fields'][$gid]['prefill'] );
$display = html_entity_decode( wp_json_encode( $response->get_data()['items'][0]['item_data'], JSON_UNESCAPED_SLASHES ), ENT_QUOTES );
url_check( 'Store API cart display includes URL and default override', false !== strpos( $display, 'https://example.invalid/store?a=1&b=2' ) && false !== strpos( $display, 'https://example.invalid/override' ) );
WC()->payment_gateways()->init();
$checkout = $dispatch( 'checkout', [
	'payment_method' => 'bacs',
	'billing_address' => [ 'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'url@example.invalid', 'address_1' => '1 Test Street', 'city' => 'Testville', 'postcode' => '90210', 'country' => 'US', 'state' => 'CA' ],
] );
url_check( 'Store API checkout accepted', in_array( $checkout->get_status(), [ 200, 201 ], true ) );
$order = wc_get_order( $checkout->get_data()['order_id'] );
$state['orders'][] = $order->get_id();
update_option( 'opf_url_e2e_state', $state );
$item = array_values( $order->get_items() )[0];
$stored = json_decode( $item->get_meta( '_opf_fields', true ), true );
url_check( 'order structured URL persisted', 'https://example.invalid/store?a=1&b=2' === $stored[$gid]['message'] && 'https://example.invalid/override' === $stored[$gid]['prefill'] );
url_check( 'order public display meta persisted', 'https://example.invalid/store?a=1&b=2' === $item->get_meta( 'Profile URL edited', true ) );
url_check( 'order unchanged base total', abs( 20 - (float) $item->get_total() ) < 0.001 );
$email = new WC_Email_Customer_On_Hold_Order();
$email->object = $order;
$email->recipient = 'url@example.invalid';
foreach ( [ 'get_content_html', 'get_content_plain' ] as $method ) {
	$content = $email->$method();
	url_check( "email $method includes labels and values", false !== strpos( $content, 'Profile URL edited' ) && false !== strpos( html_entity_decode( $content, ENT_QUOTES ), 'https://example.invalid/store?a=1&b=2' ) && false !== strpos( $content, 'https://example.invalid/override' ) );
}
url_check( 'HTML email URL query is escaped', false !== strpos( $email->get_content_html(), 'a=1&amp;b=2' ) && false === strpos( $email->get_content_html(), '<script>' ) );
$again = apply_filters( 'woocommerce_order_again_cart_item_data', [], $item, $order );
url_check( 'order again restores exact URL values', $again['opf_fields'] === $stored );
$cart->empty_cart();
$key = $cart->add_to_cart( $state['product'], 2, 0, [], $again );
url_check( 'order again cart preserves restored selections', (bool) $key && $cart->get_cart_item( $key )['opf_fields'] === $stored );
$cart->empty_cart();
echo "SUCCESS native URL commerce lifecycle\n";
