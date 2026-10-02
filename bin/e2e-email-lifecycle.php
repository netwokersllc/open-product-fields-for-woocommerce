<?php
/** Real WooCommerce lifecycle proof. Explicitly disposable /tmp sites only. */
if ( '1' !== getenv( 'OPF_EMAIL_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Requires an explicitly authorized disposable /tmp WordPress.' );
}
function email_check( string $label, bool $ok ): void {
	if ( ! $ok ) { throw new RuntimeException( $label ); }
	echo "ok $label\n";
}
$phase = getenv( 'OPF_EMAIL_E2E_PHASE' ) ?: 'commerce';
$state = get_option( 'opf_email_e2e_state', [] );
if ( 'setup' === $phase ) {
	email_check( 'no existing fixture', ! $state );
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF email lifecycle fixture' );
	$product->set_slug( 'opf-email-lifecycle' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$pid = $product->save();
	$gid = OPF\Service\FieldGroups::save( 0, [
		'fields' => [ [ 'id' => 'contact', 'type' => 'email', 'label' => 'Contact email', 'required' => true ], [ 'id' => 'optional', 'type' => 'email', 'label' => 'Optional email' ] ],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
	], [ 'title' => 'OPF email lifecycle', 'status' => 'publish' ] );
	$uid = wp_create_user( 'opf_email_e2e', wp_generate_password( 32 ), 'email@example.invalid' );
	email_check( 'fixture user created', ! is_wp_error( $uid ) );
	( new WP_User( $uid ) )->set_role( 'administrator' );
	$classic_page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Email classic checkout', 'post_name' => 'email-classic-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	$state = [ 'product' => $pid, 'group' => $gid, 'user' => $uid, 'classic_page' => $classic_page, 'orders' => [] ];
	update_option( 'opf_email_e2e_state', $state );
	update_option( 'opf_admin_only', 'no' );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	file_put_contents( '/tmp/opf-email-state.json', wp_json_encode( $state ) );
	echo "ok fixture setup\n";
	return;
}
email_check( 'fixture exists', ! empty( $state['group'] ) );
if ( 'cleanup' === $phase ) {
	foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $order ) {
		foreach ( $order->get_items() as $item ) { if ( $item->get_product_id() === $state['product'] ) { $order->delete( true ); break; } }
	}
	wp_delete_post( $state['group'], true );
	wp_delete_post( $state['product'], true );
	wp_delete_post( $state['classic_page'], true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $state['user'] );
	delete_option( 'opf_email_e2e_state' );
	echo "ok fixture cleanup\n";
	return;
}
$gid = (string) $state['group'];
$group = OPF\Service\FieldGroups::group_from_post( get_post( $state['group'] ) );
email_check( 'admin save/reload persists required email and edited label', 'email' === $group->data['fields'][0]['type'] && 'Contact email edited' === $group->data['fields'][0]['label'] && $group->data['fields'][0]['required'] );
$source = [ 'fields' => [ [ 'id' => 'contact', 'type' => 'email', 'label' => 'Contact email', 'required' => true, 'options' => [ 'placeholder' => 'Email address' ] ] ] ];
$mapped = OPF\Engine\WapfMapper::map( $source );
$mapped['group']['fields'] = array_values( $mapped['group']['fields'] );
email_check( 'WAPF email mapping retains type required and placeholder', 'email' === $mapped['group']['fields'][0]['type'] && $mapped['group']['fields'][0]['required'] && 'Email address' === $mapped['group']['fields'][0]['placeholder'] );
$exported = OPF\Service\WapfExporter::build_payload( $mapped['group'] );
email_check( 'WAPF Tools email export retains type required and placeholder', 'email' === $exported['fields'][0]['type'] && $exported['fields'][0]['required'] && 'Email address' === $exported['fields'][0]['placeholder'] );
$wapf_source = WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce/';
spl_autoload_register( static function ( $class ) use ( $wapf_source ) {
	if ( 0 !== strpos( $class, 'SW_WAPF\\' ) ) { return; }
	$segments = explode( '\\', substr( $class, 8 ) );
	$leaf = strtolower( str_replace( '_', '-', array_pop( $segments ) ) );
	$file = $wapf_source . strtolower( implode( '/', $segments ) ) . '/class-' . $leaf . '.php';
	if ( is_file( $file ) ) { require_once $file; }
} );
$model = SW_WAPF\Includes\Classes\Field_Groups::raw_json_to_field_group( array_merge( $exported, [ 'id' => 'email-proof', 'type' => 'wapf_product' ] ) );
email_check( 'installed WAPF Free Tools converter accepts OPF email payload', 'email' === $model->fields[0]->type && $model->fields[0]->required && 'Email address' === $model->fields[0]->options['placeholder'] );
$back = OPF\Engine\WapfMapper::map( [ 'fields' => [ $model->fields[0]->to_array() ], 'rule_groups' => [], 'layout' => $exported['layout'] ] );
$back['group']['fields'] = array_values( $back['group']['fields'] );
email_check( 'WAPF email roundtrip retains semantic field data', $mapped['group']['fields'][0]['type'] === $back['group']['fields'][0]['type'] && $mapped['group']['fields'][0]['label'] === $back['group']['fields'][0]['label'] && $mapped['group']['fields'][0]['required'] === $back['group']['fields'][0]['required'] && $mapped['group']['fields'][0]['placeholder'] === $back['group']['fields'][0]['placeholder'] );
if ( ! WC()->cart ) { wc_load_cart(); }
add_filter( 'pre_wp_mail', '__return_true' );
$cart = WC()->cart;
$cart->empty_cart();
$dispatch = static function ( string $path, array $params ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/' . $path );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	foreach ( $params as $name => $value ) { $request->set_param( $name, $value ); }
	return rest_get_server()->dispatch( $request );
};
$browser_ids = json_decode( file_get_contents( '/tmp/opf-email-artifacts/browser-orders.json' ), true );
foreach ( $browser_ids as $kind => $id ) {
	$order = wc_get_order( $id );
	email_check( "$kind browser order reloads", $order instanceof WC_Order );
	$item = array_values( $order->get_items() )[0];
	$stored = json_decode( $item->get_meta( '_opf_fields', true ), true );
	email_check( "$kind persists exact structured email", 'a&b@example.test' === $stored[$gid]['contact'] );
	email_check( "$kind keeps base price", 10.0 === (float) $item->get_total() );
	email_check( "$kind public order metadata includes email", 'a&b@example.test' === $item->get_meta( 'Contact email edited', true ) );
	$email = new WC_Email_Customer_On_Hold_Order();
	$email->object = $order;
	$email->recipient = 'email@example.invalid';
	email_check( "$kind HTML email escapes ampersand", false !== strpos( $email->get_content_html(), 'a&amp;b@example.test' ) );
	email_check( "$kind plain email includes Woo formatted email metadata", false !== strpos( $email->get_content_plain(), 'a&amp;b@example.test' ) );
	$again = apply_filters( 'woocommerce_order_again_cart_item_data', [], $item, $order );
	email_check( "$kind order again restores data", $again['opf_fields'] === $stored );
	$key = $cart->add_to_cart( $state['product'], 1, 0, [], $again );
	email_check( "$kind order again cart validates and retains email", (bool) $key && $cart->get_cart_item( $key )['opf_fields'] === $stored );
	$cart->empty_cart();
}
foreach ( [ 'a@localhost', 'a..b@example.test', '.a@example.test', 'a.@example.test', 'a+b@example.test', 'a&b@example.test', 'a@xn--bcher-kva.test' ] as $value ) {
	$_POST['opf'] = [ $gid => [ 'contact' => $value ] ];
	email_check( "classic native-valid $value", true === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
	$key = $cart->add_to_cart( $state['product'], 1 );
	email_check( "classic exact email $value", (bool) $key && $cart->get_cart_item( $key )['opf_fields'][$gid]['contact'] === $value );
	unset( $_POST['opf'] );
	$cart->empty_cart();
	$response = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => [ $gid => [ 'contact' => $value ] ] ] );
	email_check( "Store API native-valid $value", in_array( $response->get_status(), [ 200, 201 ], true ) );
	$cart->empty_cart();
}
foreach ( [ '', 'not-email', '"a"@example.test', 'a@-example.test', 'a@example-.test', 'a@ex_ample.test', 'a<b>@example.test', "a\0@example.test", "a@example.test\0", [ 'a@example.test' ] ] as $value ) {
	$_POST['opf'] = [ $gid => [ 'contact' => 'valid@example.test', 'optional' => $value ] ];
	if ( '' === $value ) { $_POST['opf'][$gid]['contact'] = ''; }
	wc_clear_notices();
	email_check( 'classic invalid required/optional ' . wp_json_encode( $value ), false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
	$payload = $_POST['opf'];
	unset( $_POST['opf'] );
	$response = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => $payload ] );
	email_check( 'Store API rejects invalid with empty cart ' . wp_json_encode( $value ), $response->get_status() >= 400 && 0 === count( $cart->get_cart() ) );
}
echo "SUCCESS email lifecycle\n";
