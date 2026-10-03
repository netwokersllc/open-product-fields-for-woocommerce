<?php
/* Aelia REAL-PLUGIN parity probe for WAPF Extended 3.1.5.
 * Requires WAPF Extended active, OPF inactive, real Aelia CS active (EUR rate 2).
 * Run: wp eval-file <this> --path=/tmp/opf-image-aelia-wp */
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) { throw new RuntimeException( 'Expects WAPF active.' ); }
if ( ! class_exists( 'WC_Aelia_CurrencySwitcher' ) ) { throw new RuntimeException( 'Real Aelia required.' ); }

$RESULT = [ 'engine' => 'WAPF', 'lane' => 'aelia-real', 'generated_utc' => gmdate( 'c' ), 'checks' => [] ];
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
	$product->set_name( 'aelia-real WAPF' );
	$product->set_regular_price( '10' );
	$product->set_status( 'publish' );
	$product_id = $product->save();

	$fg = new SW_WAPF_PRO\Includes\Models\FieldGroup();
	$fg->from_array( [
		'id' => 0, 'type' => 'wapf_product', 'layout' => [], 'variables' => [],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'condition' => 'product', 'value' => [ (string) $product_id ] ] ] ] ],
		'fields' => [
			[ 'id' => 'fixed', 'label' => 'Fixed', 'description' => '', 'type' => 'text', 'required' => false, 'class' => '', 'width' => '', 'options' => [], 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'pricing' => [ 'type' => 'qt', 'enabled' => true, 'amount' => 3 ] ],
			[ 'id' => 'percent', 'label' => 'Percent', 'description' => '', 'type' => 'text', 'required' => false, 'class' => '', 'width' => '', 'options' => [], 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'pricing' => [ 'type' => 'percent', 'enabled' => true, 'amount' => 10 ] ],
			[ 'id' => 'formula', 'label' => 'Formula', 'description' => '', 'type' => 'text', 'required' => false, 'class' => '', 'width' => '', 'options' => [], 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'pricing' => [ 'type' => 'fx', 'enabled' => true, 'amount' => '[price]' ] ],
		],
	] );
	$group_id = SW_WAPF_PRO\Includes\Classes\Field_Groups::save( $fg, 'wapf_product', null, 'aelia-real WAPF', 'publish' );
	if ( method_exists( 'SW_WAPF_PRO\Includes\Classes\Cache', 'clear' ) ) { SW_WAPF_PRO\Includes\Classes\Cache::clear(); }

	$GLOBALS['product'] = wc_get_product( $product_id );
	$product = wc_get_product( $product_id );
	$RESULT['preview'] = [ 'raw_view_price' => (float) $product->get_price(), 'wapf_base_filter' => (float) apply_filters( 'wapf/pricing/product', $product->get_price(), $product ) ];

	WC()->cart->empty_cart( true );
	$_REQUEST['wapf_field_groups'] = (string) $group_id;
	$_REQUEST['wapf'] = [ 'field_fixed' => 'yes', 'field_percent' => 'yes', 'field_formula' => 'yes' ];
	$key = WC()->cart->add_to_cart( $product_id, 1 );
	unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'] );
	if ( ! $key ) { throw new RuntimeException( 'Cart add failed.' ); }
	WC()->cart->calculate_totals();
	$item = WC()->cart->get_cart_item( $key );
	$RESULT['cart'] = [
		'view_price' => (float) $item['data']->get_price(),
		'edit_price' => (float) $item['data']->get_price( 'edit' ),
		'subtotal'   => (float) WC()->cart->get_subtotal(),
	];
	$RESULT['wapf_item_pricing'] = $item['wapf_item_price'] ?? null;

	$order = wc_create_order();
	$order_id = $order->get_id();
	$order->set_currency( 'EUR' );
	$line_id = $order->add_product( $item['data'], 1 );
	$line = $order->get_item( $line_id );
	$line->save();
	$RESULT['order'] = [ 'line_total' => (float) $line->get_total(), 'order_currency' => $order->get_currency() ];
	$RESULT['status'] = 'pass';
} catch ( Throwable $e ) {
	$RESULT['status'] = 'fail';
	$RESULT['error'] = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
} finally {
	unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'], $GLOBALS['product'] );
	remove_filter( 'wc_aelia_cs_selected_currency', $sel );
	if ( WC()->cart ) { WC()->cart->empty_cart( true ); }
	if ( $order_id && wc_get_order( $order_id ) ) { wc_get_order( $order_id )->delete( true ); }
	if ( $group_id ) { wp_delete_post( $group_id, true ); }
	if ( $product_id && wc_get_product( $product_id ) ) { wc_get_product( $product_id )->delete( true ); }
	foreach ( $old as $k => $v ) { false === $v ? delete_option( $k ) : update_option( $k, $v ); }
}
echo "\n===RESULT_JSON===\n" . wp_json_encode( $RESULT, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
