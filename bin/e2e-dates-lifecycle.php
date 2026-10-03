<?php
/** Real WooCommerce dates lifecycle proof; guarded disposable use only.
 *  Phases: setup (create fixture), verify (server-side validation + cart/order format), cleanup.
 *  Browser proof runs separately between setup and cleanup.
 */
if ( '1' !== getenv( 'OPF_DATES_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Requires an authorized disposable /tmp WordPress.' );
}
function dates_check( string $label, bool $ok ): void { echo "ok $label\n"; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } }
$phase = getenv( 'OPF_DATES_E2E_PHASE' ) ?: 'verify';
$state = get_option( 'opf_dates_e2e_state', [] );
if ( 'setup' === $phase ) {
	dates_check( 'no existing dates fixture', ! $state );
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF dates lifecycle fixture' );
	$product->set_slug( 'opf-dates-lifecycle' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$pid = $product->save();
	$fields = [
		[ 'id' => 'delivery', 'type' => 'date', 'label' => 'Delivery date', 'required' => true,
			'disabled_weekdays' => [ 6 ],
			'disabled_dates' => [ '2027-02-10 2027-02-12', '12-25' ],
			'cutoff_time' => '00:00' ],
		[ 'id' => 'loose', 'type' => 'date', 'label' => 'Loose date', 'required' => true ],
	];
	$gid = OPF\Service\FieldGroups::save( 0, [ 'fields' => $fields, 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ] ], [ 'title' => 'OPF dates lifecycle', 'status' => 'publish' ] );
	$uid = wp_create_user( 'opf_dates_e2e', wp_generate_password( 32 ), 'dates@example.invalid' );
	dates_check( 'fixture administrator created', ! is_wp_error( $uid ) );
	( new WP_User( $uid ) )->set_role( 'administrator' );
	$page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Dates classic checkout', 'post_name' => 'dates-classic-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	$state = [ 'product' => $pid, 'group' => $gid, 'user' => $uid, 'checkout_page' => $page ];
	update_option( 'opf_dates_e2e_state', $state );
	$format_prev = get_option( 'opf_date_format' );
	update_option( 'opf_date_format', 'yyyy.mm.dd' );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	file_put_contents( '/tmp/opf-dates-state.json', wp_json_encode( [ 'state' => $state, 'format_prev' => $format_prev ] ) );
	echo "ok fixture setup\n";
	return;
}
if ( 'cleanup' === $phase ) {
	foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $order ) {
		foreach ( $order->get_items() as $item ) { if ( $item->get_product_id() === $state['product'] ) { $order->delete( true ); break; } }
	}
	foreach ( [ 'product', 'group', 'checkout_page' ] as $key ) { wp_delete_post( $state[ $key ], true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $state['user'] );
	delete_option( 'opf_dates_e2e_state' );
	if ( file_exists( '/tmp/opf-dates-state.json' ) ) { $d = json_decode( file_get_contents( '/tmp/opf-dates-state.json' ), true ); if ( null !== $d['format_prev'] ) { update_option( 'opf_date_format', $d['format_prev'] ); } else { delete_option( 'opf_date_format' ); } }
	( new WP_User( 0 ) );
	echo "ok fixture cleanup\n";
	return;
}
dates_check( 'fixture exists', ! empty( $state['group'] ) );
$gid = (string) $state['group'];
$product = $state['product'];
$today = current_datetime()->format( 'Y-m-d' );
$error_cases = [
	'Saturday is a disabled weekday' => [ 'delivery' => '2027-02-13', 'loose' => '2027-02-15' ],
	'disabled date range rejected' => [ 'delivery' => '2027-02-11', 'loose' => '2027-02-15' ],
	// 2027-12-25 is also a Saturday, so '2028-12-25' (a Monday) isolates the
	// recurring MM-DD rule server-side. WAPF accepts this (documented gap).
	'recurring 12-25 rejected' => [ 'delivery' => '2028-12-25', 'loose' => '2027-02-15' ],
	'today rejected by cutoff' => [ 'delivery' => $today, 'loose' => $today ],
];
if ( ! WC()->cart ) { wc_load_cart(); }
add_filter( 'pre_wp_mail', '__return_true' );
$dispatch = static function ( array $params ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_get_server()->dispatch( $request );
};
foreach ( $error_cases as $label => $vals ) {
	$values = [ $gid => $vals ];
	$_POST['opf'] = $values;
	wc_clear_notices();
	dates_check( "classic rejects: $label", false === apply_filters( 'woocommerce_add_to_cart_validation', true, $product, 1 ) );
	unset( $_POST['opf'] );
	$response = $dispatch( [ 'id' => $product, 'quantity' => 1, 'opf_fields' => $values ] );
	dates_check( "Store API rejects: $label", $response->get_status() >= 400 && ! WC()->cart->get_cart() );
}
$valid = [ $gid => [ 'delivery' => '2027-02-15', 'loose' => '2027-02-16' ] ];
$_POST['opf'] = $valid;
dates_check( 'classic accepts enabled weekday/date', true === apply_filters( 'woocommerce_add_to_cart_validation', true, $product, 1 ) );
$key = WC()->cart->add_to_cart( $product, 1 );
unset( $_POST['opf'] );
dates_check( 'cart line added', (bool) $key );
WC()->cart->calculate_totals();
$item = WC()->cart->get_cart_item( $key );
$labels = \OPF\Service\CartIntegration::display_item_data( [], $item );
$values = array_column( $labels, 'value', 'name' );
dates_check( 'cart label uses configured format yyyy.mm.dd', '2027.02.15' === ( $values['Delivery date'] ?? null ) );
$order = wc_create_order();
$line_id = $order->add_product( $item['data'], 1 );
$line = $order->get_item( $line_id );
\OPF\Service\CartIntegration::persist_order_item( $line, $key, $item, $order );
$line->save();
$order->calculate_totals();
$order->save();
$reloaded = new WC_Order_Item_Product( $line_id );
$stored = json_decode( (string) $reloaded->get_meta( '_opf_fields', true ), true );
dates_check( 'order meta keeps ISO storage', '2027-02-15' === ( $stored[ $gid ]['delivery'] ?? null ) );
dates_check( 'order display uses configured format', '2027.02.15' === $reloaded->get_meta( 'Delivery date', true ) );
WC()->cart->empty_cart( true );
$order->delete( true );
// Deterministic cutoff isolation: inject a Monday "today" so the disabled
// weekday cannot shadow the cutoff boundary check.
$cutoff_field = [ 'type' => 'date', 'label' => 'Delivery date', 'required' => true, 'cutoff_time' => '12:00' ];
$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
dates_check( 'cutoff allows same-day before the cutoff time', [] === \OPF\Engine\FieldValue::validate( $cutoff_field, '2026-10-05', true, new \DateTimeImmutable( '2026-10-05 11:59:00', $tz ) ) );
dates_check( 'cutoff rejects same-day after the cutoff time', [] !== \OPF\Engine\FieldValue::validate( $cutoff_field, '2026-10-05', true, new \DateTimeImmutable( '2026-10-05 12:01:00', $tz ) ) );
dates_check( 'cutoff does not block a different day', [] === \OPF\Engine\FieldValue::validate( $cutoff_field, '2026-10-06', true, new \DateTimeImmutable( '2026-10-05 12:01:00', $tz ) ) );
dates_check( 'verification complete', true );
