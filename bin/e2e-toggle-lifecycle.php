<?php
/** Real WooCommerce proof; never production. Browser runs between setup and commerce. */
if ( '1' !== getenv( 'OPF_TOGGLE_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Requires an authorized disposable /tmp WordPress.' );
}
function toggle_check( string $label, bool $ok ): void {
	if ( ! $ok ) { throw new RuntimeException( $label ); }
	echo "ok $label\n";
}
$phase = getenv( 'OPF_TOGGLE_E2E_PHASE' ) ?: 'commerce';
$state = get_option( 'opf_toggle_e2e_state', [] );
if ( 'setup' === $phase ) {
	toggle_check( 'no existing fixture', ! $state );
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF toggle lifecycle fixture' );
	$product->set_slug( 'opf-toggle-lifecycle' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$pid = $product->save();
	$fields = [
		[ 'id' => 'accept', 'type' => 'toggle', 'label' => 'Accept terms', 'required' => true ],
		[ 'id' => 'wrap', 'type' => 'toggle', 'label' => 'Gift wrap', 'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => true ] ],
		[ 'id' => 'preset', 'type' => 'toggle', 'label' => 'Preset choice', 'default' => '1', 'message' => 'Preset & checked' ],
	];
	foreach ( [ 'false_only' => '0', 'true_only' => '1' ] as $id => $value ) {
		$fields[] = [ 'id' => $id, 'type' => 'toggle', 'label' => $id, 'required' => true, 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'wrap', 'operator' => 'is', 'value' => $value ] ] ] ] ];
	}
	$gid = OPF\Service\FieldGroups::save( 0, [ 'fields' => $fields, 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ] ], [ 'title' => 'OPF toggle lifecycle', 'status' => 'publish' ] );
	$uid = wp_create_user( 'opf_toggle_e2e', wp_generate_password( 32 ), 'toggle@example.invalid' );
	toggle_check( 'fixture administrator created', ! is_wp_error( $uid ) );
	( new WP_User( $uid ) )->set_role( 'administrator' );
	$page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Toggle classic checkout', 'post_name' => 'toggle-classic-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	$state = [ 'product' => $pid, 'group' => $gid, 'user' => $uid, 'classic_page' => $page ];
	update_option( 'opf_toggle_e2e_state', $state );
	update_option( 'opf_admin_only', 'no' );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	file_put_contents( '/tmp/opf-toggle-state.json', wp_json_encode( $state ) );
	echo "ok fixture setup\n";
	return;
}
toggle_check( 'fixture exists', ! empty( $state['group'] ) );
if ( 'cleanup' === $phase ) {
	foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $order ) {
		foreach ( $order->get_items() as $item ) { if ( $item->get_product_id() === $state['product'] ) { $order->delete( true ); break; } }
	}
	foreach ( [ 'product', 'group', 'classic_page' ] as $key ) { wp_delete_post( $state[$key], true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $state['user'] );
	delete_option( 'opf_toggle_e2e_state' );
	echo "ok fixture cleanup\n";
	return;
}
$gid = (string) $state['group'];
$group = OPF\Service\FieldGroups::group_from_post( get_post( $state['group'] ) );
toggle_check( 'admin persists native type label required message and checked default', 'toggle' === $group->data['fields'][0]['type'] && 'Accept terms edited' === $group->data['fields'][0]['label'] && $group->data['fields'][0]['required'] && 'Accept & continue' === $group->data['fields'][0]['message'] && '1' === $group->data['fields'][0]['default'] );
toggle_check( 'admin false condition persists canonical zero', '0' === $group->data['fields'][3]['conditionals'][0]['rules'][0]['value'] );
$wapf_source = WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce/';
spl_autoload_register( static function ( $class ) use ( $wapf_source ) {
	if ( 0 !== strpos( $class, 'SW_WAPF\\' ) ) { return; }
	$segments = explode( '\\', substr( $class, 8 ) );
	$leaf = strtolower( str_replace( '_', '-', array_pop( $segments ) ) );
	$file = $wapf_source . strtolower( implode( '/', $segments ) ) . '/class-' . $leaf . '.php';
	if ( is_file( $file ) ) { require_once $file; }
} );
$mapped = OPF\Engine\WapfMapper::map( [ 'fields' => [
	[ 'id' => 'source', 'type' => 'true-false', 'label' => 'Source', 'required' => true, 'options' => [ 'message' => 'Wrap & ribbon', 'default' => 'checked' ] ],
	[ 'id' => 'dependent', 'type' => 'true-false', 'options' => [ 'default' => 'unchecked' ], 'conditionals' => [ [ 'rules' => [ [ 'field' => 'source', 'condition' => '!check' ] ] ] ] ],
] ] );
toggle_check( 'WAPF import retains checked unchecked messages required and false condition without review', ! $mapped['needs_review'] && '1' === $mapped['group']['fields'][0]['default'] && 'Wrap & ribbon' === $mapped['group']['fields'][0]['message'] && $mapped['group']['fields'][0]['required'] && '0' === $mapped['group']['fields'][1]['default'] && 'is_not' === $mapped['group']['fields'][1]['conditionals'][0]['rules'][0]['operator'] && '1' === $mapped['group']['fields'][1]['conditionals'][0]['rules'][0]['value'] );
$exported = OPF\Service\WapfExporter::build_payload( $mapped['group'] );
$model = SW_WAPF\Includes\Classes\Field_Groups::raw_json_to_field_group( array_merge( $exported, [ 'id' => 'toggle-proof', 'type' => 'wapf_product' ] ) );
toggle_check( 'actual Free Tools model accepts OPF toggle settings', 'true-false' === $model->fields[0]->type && 'checked' === $model->fields[0]->options['default'] && 'Wrap & ribbon' === $model->fields[0]->options['message'] );
$back = OPF\Engine\WapfMapper::map( [ 'fields' => array_map( static function ( $field ) { return $field->to_array(); }, $model->fields ) ] );
toggle_check( 'actual Free Tools roundtrip retains field semantics', $mapped['group']['fields'] === $back['group']['fields'] );
foreach ( [ '0' => 'false', '1' => 'true', 'on' => 'false', 'garbage' => 'false' ] as $value => $expected ) {
	toggle_check( 'Free sanitizer observed ' . $value . ' as ' . $expected, $expected === SW_WAPF\Includes\Classes\Fields::sanitize_raw_value( $model->fields[0], (string) $value ) );
}
if ( ! WC()->cart ) { wc_load_cart(); }
add_filter( 'pre_wp_mail', '__return_true' );
$cart = WC()->cart;
$cart->empty_cart();
$dispatch = static function ( array $params ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	foreach ( $params as $name => $value ) { $request->set_param( $name, $value ); }
	return rest_get_server()->dispatch( $request );
};
foreach ( [ '0', '', 'garbage', [ '1' ] ] as $bad ) {
	$values = [ $gid => [ 'accept' => $bad, 'wrap' => '0', 'false_only' => '1' ] ];
	$_POST['opf'] = $values;
	wc_clear_notices();
	toggle_check( 'classic rejects required unchecked or malformed ' . wp_json_encode( $bad ), false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
	unset( $_POST['opf'] );
	$response = $dispatch( [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => $values ] );
	toggle_check( 'Store API rejects required unchecked or malformed ' . wp_json_encode( $bad ), $response->get_status() >= 400 && ! $cart->get_cart() );
}
foreach ( [ '0', '1' ] as $value ) {
	$visible = '1' === $value ? 'true_only' : 'false_only';
	$hidden = '1' === $value ? 'false_only' : 'true_only';
	$values = [ $gid => [ 'accept' => '1', 'wrap' => $value, $visible => '1', $hidden => '0' ] ];
	foreach ( [ 'classic', 'Store API' ] as $kind ) {
		if ( 'classic' === $kind ) {
			$_POST['opf'] = $values;
			toggle_check( "$kind valid $value", true === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['product'], 1 ) );
			$key = $cart->add_to_cart( $state['product'], 1 );
			unset( $_POST['opf'] );
		} else {
			$response = $dispatch( [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => $values ] );
			toggle_check( "$kind valid $value", $response->get_status() < 400 );
			$key = array_key_first( $cart->get_cart() );
		}
		$line = $cart->get_cart_item( $key );
		toggle_check( "$kind persists exact boolean and default $value and filters hidden forged zero", $value === $line['opf_fields'][$gid]['wrap'] && '1' === $line['opf_fields'][$gid]['preset'] && ! isset( $line['opf_fields'][$gid][$hidden] ) );
		$cart->calculate_totals();
		toggle_check( "$kind prices checked only $value", ( '1' === $value ? 12.0 : 10.0 ) === (float) $cart->get_total( 'edit' ) );
		$cart->empty_cart();
	}
}
$orders = json_decode( file_get_contents( '/tmp/opf-toggle-artifacts/browser-orders.json' ), true );
foreach ( $orders as $kind => $id ) {
	$order = wc_get_order( $id );
	toggle_check( "$kind durable order reloads", $order instanceof WC_Order );
	$item = array_values( $order->get_items() )[0];
	$stored = json_decode( $item->get_meta( '_opf_fields', true ), true );
	$value = substr( $kind, -1 );
	$hidden = '1' === $value ? 'false_only' : 'true_only';
	toggle_check( "$kind persists exact boolean with hidden field absent", $value === $stored[$gid]['wrap'] && '1' === $stored[$gid]['accept'] && '1' === $stored[$gid]['preset'] && ! isset( $stored[$gid][$hidden] ) );
	toggle_check( "$kind public metadata describes boolean", ( '1' === $value ? 'Yes' : 'No' ) === $item->get_meta( 'Gift wrap', true ) );
	toggle_check( "$kind persists checked-only price", ( '1' === $value ? 12.0 : 10.0 ) === (float) $item->get_total() );
	foreach ( [ 'get_content_html', 'get_content_plain' ] as $method ) {
		$email = new WC_Email_Customer_On_Hold_Order();
		$email->object = $order;
		$email->recipient = 'toggle@example.invalid';
		$content = $email->$method();
		toggle_check( "$kind $method includes boolean metadata", false !== strpos( $content, 'Gift wrap' ) && false !== strpos( $content, '1' === $value ? 'Yes' : 'No' ) );
	}
	$again = apply_filters( 'woocommerce_order_again_cart_item_data', [], $item, $order );
	$key = $cart->add_to_cart( $state['product'], 1, 0, [], $again );
	toggle_check( "$kind order again actual cart attachment preserves exact booleans", (bool) $key && $stored === $cart->get_cart_item( $key )['opf_fields'] );
	$cart->calculate_totals();
	toggle_check( "$kind order again retains checked-only price", ( '1' === $value ? 12.0 : 10.0 ) === (float) $cart->get_total( 'edit' ) );
	$cart->empty_cart();
	$order->set_customer_id( $state['user'] );
	$order->update_status( 'completed' );
	$order->save();
}
echo "SUCCESS toggle lifecycle\n";
