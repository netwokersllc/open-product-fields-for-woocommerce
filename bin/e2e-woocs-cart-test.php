<?php
/**
 * Real disposable WooCommerce cart with a fake WOOCS API/price filter.
 * This verifies OPF's bridge contract, not the unavailable WOOCS plugin itself.
 * Run: wp eval-file bin/e2e-woocs-cart-test.php --path=/disposable/wordpress
 */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'FQDB' ) || false === strpos( FQDB, '/tmp/opf-woocs-' ) ) {
	throw new RuntimeException( 'Use a dedicated /tmp/opf-woocs-* SQLite clone.' );
}

class OPF_E2E_Woocs_Api {
	public $current_currency = 'EUR';
	public $default_currency = 'USD';
	public $rate = 2.0;
	public function get_currencies(): array { return [ 'EUR' => [ 'rate' => $this->rate, 'symbol' => '€' ], 'USD' => [ 'rate' => 1, 'symbol' => '$' ] ]; }
	public function back_convert( $price, $rate, $precision ) { return round( $price / $rate, $precision ); }
}

$product_id = $group_id = $order_id = 0;
$old_options = [];
foreach ( [ 'woocs_is_multiple_allowed', 'woocs_is_fixed_enabled', 'woocommerce_tax_display_shop' ] as $option ) {
	$old_options[$option] = get_option( $option );
}
$old_woocs = $GLOBALS['WOOCS'] ?? null;
$GLOBALS['WOOCS'] = new OPF_E2E_Woocs_Api();
$price_filter = static function ( $price ) {
	$api = $GLOBALS['WOOCS'];
	return 'USD' === $api->current_currency ? $price : (float) $price * $api->rate;
};
$check = static function ( string $name, float $actual, float $expected ): void {
	if ( abs( $actual - $expected ) > 0.00001 ) {
		throw new RuntimeException( "$name: expected $expected, got $actual" );
	}
};

try {
	update_option( 'woocs_is_multiple_allowed', 1 );
	update_option( 'woocs_is_fixed_enabled', 1 );
	update_option( 'woocommerce_tax_display_shop', 'excl' );
	add_filter( 'woocommerce_product_get_price', $price_filter, 100 );
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF Disposable WOOCS Contract' );
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
	$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => 'OPF Disposable WOOCS Contract' ] );
	OPF\Service\FieldGroups::flush_cache();
	$cart = WC()->cart;
	$cart->empty_cart( true );
	$_POST['opf'] = [ (string) $group_id => [ 'fixed' => 'yes', 'percent' => 'yes', 'formula' => 'yes' ] ];
	$key = $cart->add_to_cart( $product_id, 1 );
	unset( $_POST['opf'] );
	if ( ! $key ) throw new RuntimeException( 'Cart add failed.' );
	$cart->calculate_totals();
	$item = $cart->get_cart_item( $key );
	$check( 'base currency target', (float) $item['data']->get_price( 'edit' ), 24 );
	$check( 'converted view target', (float) $item['data']->get_price(), 48 );
	$cart->calculate_totals();
	$check( 'repeat totals', (float) $item['data']->get_price(), 48 );
	$GLOBALS['WOOCS']->rate = 3;
	$cart->calculate_totals();
	$check( 'currency rate change', (float) $item['data']->get_price(), 72 );
	$restored = OPF\Service\CartIntegration::restore_from_session( [ 'data' => wc_get_product( $product_id ) ], $item );
	$check( 'session base', (float) $restored['opf_base_price'], 10 );
	$GLOBALS['WOOCS']->rate = 2;
	$cart->calculate_totals();
	$order = wc_create_order();
	$order_id = $order->get_id();
	$line_id = $order->add_product( $item['data'], 1 );
	$line = $order->get_item( $line_id );
	OPF\Service\CartIntegration::persist_order_item( $line, $key, $item, $order );
	$line->save();
	$check( 'order line', (float) $line->get_total(), 48 );
	$GLOBALS['WOOCS']->current_currency = 'USD';
	$cart->calculate_totals();
	$check( 'default currency after switch', (float) $item['data']->get_price(), 24 );
	WP_CLI::success( 'WOOCS fake-API contract passed in real Woo: base 24, foreign 48, repeated totals stable, rate change 72, session base 10, order 48, default switch 24.' );
} finally {
	unset( $_POST['opf'] );
	remove_filter( 'woocommerce_product_get_price', $price_filter, 100 );
	WC()->cart->empty_cart( true );
	if ( $order_id && wc_get_order( $order_id ) ) wc_get_order( $order_id )->delete( true );
	if ( $group_id ) wp_delete_post( $group_id, true );
	if ( $product_id && wc_get_product( $product_id ) ) wc_get_product( $product_id )->delete( true );
	foreach ( $old_options as $option => $value ) {
		false === $value ? delete_option( $option ) : update_option( $option, $value );
	}
	$GLOBALS['WOOCS'] = $old_woocs;
	OPF\Service\FieldGroups::flush_cache();
}
