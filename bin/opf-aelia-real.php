<?php
/* Aelia REAL-PLUGIN parity probe for OPF. Requires OPF active, WAPF inactive,
 * real Aelia CS active (EUR rate 2). Run: wp eval-file <this> --path=/tmp/opf-image-aelia-wp */
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'OPF\Service\FieldGroups' ) ) { throw new RuntimeException( 'Expects OPF active.' ); }
if ( ! class_exists( 'WC_Aelia_CurrencySwitcher' ) ) { throw new RuntimeException( 'Real Aelia required.' ); }

$RESULT = [ 'engine' => 'OPF', 'lane' => 'aelia-real', 'generated_utc' => gmdate( 'c' ), 'checks' => [] ];
$check = static function ( string $name, $actual, $expected, float $eps = 0.000001 ) use ( &$RESULT ): void {
	$pass = is_numeric( $actual ) && is_numeric( $expected ) ? abs( (float) $actual - (float) $expected ) <= $eps : $actual === $expected;
	$RESULT['checks'][] = [ 'name' => $name, 'actual' => $actual, 'expected' => $expected, 'pass' => $pass ];
};

$sel = static function () { return 'EUR'; };
add_filter( 'wc_aelia_cs_selected_currency', $sel );

$product_id = $group_id = $order_id = 0;
$old = [];
foreach ( [ 'woocommerce_currency', 'woocommerce_tax_display_shop' ] as $opt ) { $old[ $opt ] = get_option( $opt ); }

try {
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'woocommerce_tax_display_shop', 'excl' );

	$product = new WC_Product_Simple();
	$product->set_name( 'aelia-real OPF' );
	$product->set_regular_price( '10' );
	$product->set_status( 'publish' );
	$product_id = $product->save();

	$group = new OPF\Engine\FieldGroup( [
		'fields' => [
			[ 'id' => 'fixed', 'type' => 'text', 'label' => 'Fixed', 'pricing' => [ 'type' => 'fixed', 'amount' => 3, 'per_unit' => true ] ],
			[ 'id' => 'percent', 'type' => 'text', 'label' => 'Percent', 'pricing' => [ 'type' => 'percent', 'amount' => 10 ] ],
			[ 'id' => 'formula', 'type' => 'text', 'label' => 'Formula', 'pricing' => [ 'type' => 'formula', 'formula' => '[price]' ] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => 'aelia-real OPF' ] );
	OPF\Service\FieldGroups::flush_cache();

	$GLOBALS['product'] = wc_get_product( $product_id );
	$product = wc_get_product( $product_id );
	$RESULT['preview'] = [ 'raw_view_price' => (float) $product->get_price(), 'frontend_config' => OPF\Service\AeliaIntegration::merge_frontend_config( [] ) ];

	WC()->cart->empty_cart( true );
	$_POST['opf'] = [ (string) $group_id => [ 'fixed' => 'yes', 'percent' => 'yes', 'formula' => 'yes' ] ];
	$key = WC()->cart->add_to_cart( $product_id, 1 );
	unset( $_POST['opf'] );
	if ( ! $key ) { throw new RuntimeException( 'Cart add failed.' ); }
	WC()->cart->calculate_totals();
	$item = WC()->cart->get_cart_item( $key );
	$RESULT['cart'] = [
		'view_price' => (float) $item['data']->get_price(),
		'edit_price' => (float) $item['data']->get_price( 'edit' ),
		'subtotal'   => (float) WC()->cart->get_subtotal(),
	];

	$order = wc_create_order();
	$order_id = $order->get_id();
	$order->set_currency( 'EUR' );
	$line_id = $order->add_product( $item['data'], 1 );
	$line = $order->get_item( $line_id );
	OPF\Service\CartIntegration::persist_order_item( $line, $key, $item, $order );
	$line->save();
	$RESULT['order'] = [ 'line_total' => (float) $line->get_total(), 'order_currency' => $order->get_currency() ];
	$RESULT['status'] = 'pass';
} catch ( Throwable $e ) {
	$RESULT['status'] = 'fail';
	$RESULT['error'] = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
} finally {
	unset( $_POST['opf'], $GLOBALS['product'] );
	remove_filter( 'wc_aelia_cs_selected_currency', $sel );
	if ( WC()->cart ) { WC()->cart->empty_cart( true ); }
	if ( $order_id && wc_get_order( $order_id ) ) { wc_get_order( $order_id )->delete( true ); }
	if ( $group_id ) { wp_delete_post( $group_id, true ); }
	if ( $product_id && wc_get_product( $product_id ) ) { wc_get_product( $product_id )->delete( true ); }
	foreach ( $old as $k => $v ) { false === $v ? delete_option( $k ) : update_option( $k, $v ); }
	OPF\Service\FieldGroups::flush_cache();
}
echo "\n===RESULT_JSON===\n" . wp_json_encode( $RESULT, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
