<?php
/**
 * Real disposable Woo cart/order + fake Aelia API. The fake converts fresh
 * product view prices and leaves subsequent set_price amounts in that currency.
 * This verifies the contract, not the unavailable commercial Aelia plugin.
 * Run: wp eval-file bin/e2e-aelia-cart-test.php --path=/tmp/opf-aelia-...
 */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'FQDB' ) || false === strpos( FQDB, '/tmp/opf-aelia-' ) ) {
	throw new RuntimeException( 'Use a dedicated /tmp/opf-aelia-* SQLite clone.' );
}
if ( class_exists( 'WC_Aelia_CurrencySwitcher' ) ) {
	throw new RuntimeException( 'This is a fake-API fixture; do not run with real Aelia.' );
} else {
	class WC_Aelia_CurrencySwitcher {
	public static $rate = 2.0;
	public static $currency = 'EUR';
	public static function settings() { return new class { public function get_exchange_rate( $currency ) { return WC_Aelia_CurrencySwitcher::$rate; } }; }
	}
}
$GLOBALS['woocommerce-aelia-currencyswitcher'] = new stdClass();
$currency_filter = static function () { return WC_Aelia_CurrencySwitcher::$currency; };
$convert_filter = static function ( $amount, $from, $to ) { return $from === $to ? $amount : $amount * WC_Aelia_CurrencySwitcher::$rate; };
$price_filter = static function ( $price, $product ) {
	if ( 'USD' === WC_Aelia_CurrencySwitcher::$currency || array_key_exists( 'price', $product->get_changes() ) ) return $price;
	$fixed = $product->get_meta( '_opf_aelia_test_fixed' );
	return '' !== $fixed ? (float) $fixed : $price * WC_Aelia_CurrencySwitcher::$rate;
};
$check = static function ( string $label, float $actual, float $expected ): void {
	if ( abs( $actual - $expected ) > 0.000001 ) throw new RuntimeException( "$label: expected $expected, got $actual" );
};
$ids = [];
$group_id = $order_id = 0;
$old = [];
foreach ( [ 'woocommerce_currency', 'woocommerce_tax_display_shop' ] as $option ) $old[$option] = get_option( $option );
try {
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'woocommerce_tax_display_shop', 'excl' );
	add_filter( 'woocommerce_currency', $currency_filter );
	add_filter( 'wc_aelia_cs_convert', $convert_filter, 10, 3 );
	add_filter( 'woocommerce_product_get_price', $price_filter, 100, 2 );
	add_filter( 'woocommerce_product_variation_get_price', $price_filter, 100, 2 );
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF Disposable Aelia Contract' );
	$product->set_regular_price( '10' );
	$product->set_status( 'publish' );
	$product_id = $product->save();
	$ids[] = $product_id;
	$group = new OPF\Engine\FieldGroup( [
		'fields' => [
			[ 'id' => 'fixed', 'type' => 'text', 'label' => 'Fixed', 'pricing' => [ 'type' => 'fixed', 'amount' => 3, 'per_unit' => true ] ],
			[ 'id' => 'percent', 'type' => 'text', 'label' => 'Percent', 'pricing' => [ 'type' => 'percent', 'amount' => 10 ] ],
			[ 'id' => 'formula', 'type' => 'text', 'label' => 'Formula', 'pricing' => [ 'type' => 'formula', 'formula' => '[price]' ] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => 'OPF Disposable Aelia Contract' ] );
	OPF\Service\FieldGroups::flush_cache();
	$GLOBALS['product'] = wc_get_product( $product_id );
	$browser = [ 'initial' => apply_filters( 'opf_frontend_config', [] ) ];
	WC()->cart->empty_cart( true );
	$_POST['opf'] = [ (string) $group_id => [ 'fixed' => 'yes', 'percent' => 'yes', 'formula' => 'yes' ] ];
	$key = WC()->cart->add_to_cart( $product_id, 1 );
	unset( $_POST['opf'] );
	if ( ! $key ) throw new RuntimeException( 'Cart add failed.' );
	WC()->cart->calculate_totals();
	$item = WC()->cart->get_cart_item( $key );
	$check( 'foreign cart target', (float) $item['data']->get_price(), 48 );
	$check( 'cart subtotal', WC()->cart->get_subtotal(), 48 );
	WC()->cart->calculate_totals();
	$check( 'repeated totals', (float) $item['data']->get_price(), 48 );
	WC_Aelia_CurrencySwitcher::$rate = 3;
	WC()->cart->calculate_totals();
	$check( 'rate change', (float) $item['data']->get_price(), 72 );
	WC_Aelia_CurrencySwitcher::$rate = 2;
	WC()->cart->calculate_totals();
	$restored = OPF\Service\CartIntegration::restore_from_session( [ 'data' => wc_get_product( $product_id ) ], $item );
	$check( 'session base', (float) $restored['opf_base_price'], 10 );
	$order = wc_create_order();
	$order_id = $order->get_id();
	$order->set_currency( 'EUR' );
	$line_id = $order->add_product( $item['data'], 1 );
	$line = $order->get_item( $line_id );
	OPF\Service\CartIntegration::persist_order_item( $line, $key, $item, $order );
	$line->save();
	$check( 'order line', (float) $line->get_total(), 48 );
	WC_Aelia_CurrencySwitcher::$currency = 'USD';
	WC()->cart->calculate_totals();
	$check( 'default switch', (float) $item['data']->get_price(), 24 );
	WC_Aelia_CurrencySwitcher::$currency = 'EUR';
	$fresh = wc_get_product( $product_id );
	$fresh->update_meta_data( '_opf_aelia_test_fixed', 50 );
	$fresh->save();
	WC()->cart->calculate_totals();
	$check( 'fixed foreign product base', (float) $item['data']->get_price(), 81 );
	WC()->cart->calculate_totals();
	$check( 'fixed repeated totals', (float) $item['data']->get_price(), 81 );
	$parent = new WC_Product_Variable();
	$parent->set_name( 'OPF Aelia Variable Contract' );
	$parent->set_status( 'publish' );
	$ids[] = $parent->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent->get_id() );
	$variation->set_regular_price( '15' );
	$variation->set_status( 'publish' );
	$ids[] = $variation->save();
	$variation = wc_get_product( $variation->get_id() );
	$browser['variation'] = $parent->get_available_variation( $variation );
	$check( 'variation base', $browser['variation']['opf_base_price'], 15 );
	$check( 'variation formula base', $browser['variation']['opf_formula_base_price'], 15 );
	$variation->update_meta_data( '_opf_aelia_test_fixed', 50 );
	$variation->save();
	$browser['fixed_variation'] = $parent->get_available_variation( wc_get_product( $variation->get_id() ) );
	$check( 'fixed variation base', $browser['fixed_variation']['opf_base_price'], 25 );
	$check( 'fixed variation formula base', $browser['fixed_variation']['opf_formula_base_price'], 15 );
	file_put_contents( dirname( FQDB ) . '/opf-aelia-config.json', wp_json_encode( $browser ) );
	WP_CLI::success( 'Aelia fake-API contract passed in real Woo: foreign cart/subtotal/order 48; repeated totals stable; rate change 72; session base 10; default switch 24; fixed foreign product 81; variation bases 15/15 and fixed variation 25/15.' );
} finally {
	unset( $_POST['opf'], $GLOBALS['woocommerce-aelia-currencyswitcher'], $GLOBALS['product'] );
	remove_filter( 'woocommerce_currency', $currency_filter );
	remove_filter( 'wc_aelia_cs_convert', $convert_filter, 10 );
	remove_filter( 'woocommerce_product_get_price', $price_filter, 100 );
	remove_filter( 'woocommerce_product_variation_get_price', $price_filter, 100 );
	WC()->cart->empty_cart( true );
	if ( $order_id && wc_get_order( $order_id ) ) wc_get_order( $order_id )->delete( true );
	if ( $group_id ) wp_delete_post( $group_id, true );
	foreach ( array_reverse( $ids ) as $id ) if ( wc_get_product( $id ) ) wc_get_product( $id )->delete( true );
	foreach ( $old as $key => $value ) false === $value ? delete_option( $key ) : update_option( $key, $value );
	OPF\Service\FieldGroups::flush_cache();
}
