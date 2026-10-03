<?php
/**
 * commtax lane — OPF currency integration proofs (WOOCS / Aelia / FOX).
 *
 * Real WooCommerce cart/order on the disposable commtax clone with fake
 * currency-provider APIs matching the shapes OPF's integrations detect:
 *   - WOOCS:   $GLOBALS['WOOCS'] (no-arg get_currencies).
 *   - FOX:     same global with FOX's get_currencies( $suppress_filters )
 *              signature and etalon currency table (FOX documents API parity).
 *   - Aelia:   $GLOBALS['woocommerce-aelia-currencyswitcher'] +
 *              WC_Aelia_CurrencySwitcher::settings() + wc_aelia_cs_convert.
 *
 * Proves addon prices convert correctly end-to-end: base back-conversion,
 * fixed/percent/formula addon math on the shop base, converted cart/order
 * totals, repeat-totals stability, rate changes, session base, order-again
 * base, variation bases, and the disabled-multiple-currency preview gate.
 *
 * Run: OPF_COMMTAX_ALLOW=1 wp eval-file bin/e2e-commtax-currency.php --path=/tmp/opf-image-commtax-wp
 */

defined( 'ABSPATH' ) || exit;

if (
	'1' !== getenv( 'OPF_COMMTAX_ALLOW' ) || ! defined( 'WP_CLI' ) || ! WP_CLI
	|| '/tmp/opf-image-commtax-wp' !== realpath( ABSPATH )
	|| ! defined( 'FQDB' ) || 0 !== strpos( realpath( FQDB ), realpath( ABSPATH ) . '/' )
	|| ! defined( 'SQLITE_DB_DROPIN_VERSION' )
	|| '127.0.0.1' !== wp_parse_url( home_url(), PHP_URL_HOST )
	|| ! defined( 'OPF_VERSION' )
) {
	throw new RuntimeException( 'Guarded: owned commtax SQLite clone + OPF active + OPF_COMMTAX_ALLOW=1 only.' );
}
if ( class_exists( 'WC_Aelia_CurrencySwitcher' ) ) {
	throw new RuntimeException( 'Fake-API fixture; do not run with a real Aelia plugin.' );
}

$failures = 0;
$results  = [];
$check = static function ( string $label, bool $condition, string $detail = '' ) use ( &$failures, &$results ): void {
	$results[] = [ 'label' => $label, 'pass' => (bool) $condition, 'detail' => $detail ];
	if ( $condition ) {
		WP_CLI::log( "  ok    $label" . ( '' !== $detail ? " ($detail)" : '' ) );
	} else {
		$failures++;
		WP_CLI::log( "  FAIL  $label" . ( '' !== $detail ? " ($detail)" : '' ) );
	}
};

$created = [ 'products' => [], 'groups' => [], 'orders' => [] ];
$option_names = [ 'woocs_is_multiple_allowed', 'woocs_is_fixed_enabled', 'woocommerce_tax_display_shop', 'woocommerce_currency', 'opf_admin_only' ];
$original_options = [];
foreach ( $option_names as $name ) {
	$original_options[ $name ] = get_option( $name, null );
}
$had_woocs = array_key_exists( 'WOOCS', $GLOBALS );
$old_woocs = $GLOBALS['WOOCS'] ?? null;
$had_product = array_key_exists( 'product', $GLOBALS );
$old_product = $GLOBALS['product'] ?? null;
$active_filters = [];

register_shutdown_function(
	static function () use ( &$created, &$original_options, $had_woocs, $old_woocs, $had_product, $old_product, &$active_filters, &$results ): void {
		$out = getenv( 'OPF_COMMTAX_RESULTS' );
		if ( $out ) {
			file_put_contents( $out, wp_json_encode( [ 'utc' => gmdate( 'c' ), 'checks' => $results ], JSON_PRETTY_PRINT ) );
		}
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		unset( $_POST['opf'] );
		foreach ( $active_filters as [ $hook, $cb, $prio, $args ] ) {
			remove_filter( $hook, $cb, $prio );
		}
		foreach ( $created['orders'] as $id ) {
			$o = wc_get_order( (int) $id );
			if ( $o ) { $o->delete( true ); }
		}
		foreach ( $created['groups'] as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( $created['products'] as $id ) {
			$p = wc_get_product( (int) $id );
			if ( $p ) { $p->delete( true ); }
		}
		foreach ( $original_options as $name => $value ) {
			null === $value ? delete_option( $name ) : update_option( $name, $value );
		}
		if ( $had_woocs ) {
			$GLOBALS['WOOCS'] = $old_woocs;
		} else {
			unset( $GLOBALS['WOOCS'] );
		}
		unset( $GLOBALS['woocommerce-aelia-currencyswitcher'] );
		if ( $had_product ) {
			$GLOBALS['product'] = $old_product;
		} else {
			unset( $GLOBALS['product'] );
		}
		\OPF\Service\FieldGroups::flush_cache();
	}
);

WP_CLI::log( '== OPF commtax currency lifecycle ==' );
update_option( 'opf_admin_only', 'no' );
update_option( 'woocommerce_tax_display_shop', 'excl' );

$mk_fields = static function (): array {
	return [
		[ 'id' => 'fixed', 'type' => 'text', 'label' => 'Fixed', 'pricing' => [ 'type' => 'fixed', 'amount' => 3, 'per_unit' => true ] ],
		[ 'id' => 'percent', 'type' => 'text', 'label' => 'Percent', 'pricing' => [ 'type' => 'percent', 'amount' => 10 ] ],
		[ 'id' => 'formula', 'type' => 'text', 'label' => 'Formula', 'pricing' => [ 'type' => 'formula', 'formula' => '[price]' ] ],
	];
};
$add_opf = static function ( int $product_id, int $gid ) {
	WC()->cart->empty_cart( true );
	$_POST['opf'] = [ (string) $gid => [ 'fixed' => 'yes', 'percent' => 'yes', 'formula' => 'yes' ] ];
	$key = WC()->cart->add_to_cart( $product_id, 1 );
	unset( $_POST['opf'] );
	return $key;
};
$mk_product = static function ( string $name, string $price ) use ( &$created ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_status( 'publish' );
	$id = $p->save();
	$created['products'][] = $id;
	return $id;
};
$mk_group = static function ( string $title, int $product_id ) use ( &$created, $mk_fields ): int {
	$group = new OPF\Engine\FieldGroup( [
		'fields' => $mk_fields(),
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => $title ] );
	$created['groups'][] = $id;
	OPF\Service\FieldGroups::flush_cache();
	return $id;
};

// ======================================================================
WP_CLI::log( '-- WOOCS-shaped fake --' );

class OPF_Commtax_Woocs_Api {
	public $current_currency = 'EUR';
	public $default_currency = 'USD';
	public $rate = 2.0;
	public function get_currencies(): array {
		return [
			'EUR' => [ 'rate' => $this->rate, 'symbol' => '&euro;', 'position' => 'left_space' ],
			'USD' => [ 'rate' => 1, 'symbol' => '&#36;' ],
		];
	}
	public function back_convert( $price, $rate, $precision ) {
		return round( $price / $rate, $precision );
	}
}

$GLOBALS['WOOCS'] = new OPF_Commtax_Woocs_Api();
update_option( 'woocs_is_multiple_allowed', 1 );
update_option( 'woocs_is_fixed_enabled', 1 );
$woocs_price_filter = static function ( $price ) {
	$api = $GLOBALS['WOOCS'];
	return 'USD' === $api->current_currency ? $price : (float) $price * $api->rate;
};
add_filter( 'woocommerce_product_get_price', $woocs_price_filter, 100 );
$active_filters[] = [ 'woocommerce_product_get_price', $woocs_price_filter, 100, 1 ];

$p_woocs = $mk_product( 'commtax woocs product', '10' );
$g_woocs = $mk_group( 'commtax woocs group', $p_woocs );
$GLOBALS['product'] = wc_get_product( $p_woocs );

// Preview gates: original shop base under both multiple-currency modes.
foreach ( [ 0, 1 ] as $multiple_allowed ) {
	update_option( 'woocs_is_multiple_allowed', $multiple_allowed );
	$config = OPF\Service\WoocsIntegration::merge_frontend_config( [] );
	$check( "woocs preview base in multiple={$multiple_allowed} stays shop-priced", abs( (float) $config['product_base_price'] - 10.0 ) < 0.00001, 'got ' . $config['product_base_price'] );
	$check( "woocs formula base in multiple={$multiple_allowed}", abs( (float) $config['formula_base_price'] - 10.0 ) < 0.00001 );
	$check( "woocs cart base gate in multiple={$multiple_allowed}", abs( OPF\Service\WoocsIntegration::cart_base_price( 10, $GLOBALS['product'] ) - ( $multiple_allowed ? 10.0 : 20.0 ) ) < 0.00001, 'got ' . OPF\Service\WoocsIntegration::cart_base_price( 10, $GLOBALS['product'] ) );
}
update_option( 'woocs_is_multiple_allowed', 1 );

$key = $add_opf( $p_woocs, $g_woocs );
$check( 'woocs cart add succeeds', false !== $key );
WC()->cart->calculate_totals();
$item = WC()->cart->get_cart_item( $key );
$check( 'woocs shop-currency target 24 (base 10 + addons 14)', abs( (float) $item['data']->get_price( 'edit' ) - 24.0 ) < 0.00001, 'got ' . $item['data']->get_price( 'edit' ) );
$check( 'woocs converted view 48 (rate 2)', abs( (float) $item['data']->get_price() - 48.0 ) < 0.00001, 'got ' . $item['data']->get_price() );
$check( 'woocs cart line subtotal 48 incl addon conversion', abs( (float) $item['line_subtotal'] - 48.0 ) < 0.00001, 'got ' . $item['line_subtotal'] );
WC()->cart->calculate_totals();
$check( 'woocs repeat totals stable', abs( (float) $item['data']->get_price() - 48.0 ) < 0.00001 );
$GLOBALS['WOOCS']->rate = 3;
WC()->cart->calculate_totals();
$check( 'woocs rate change recomputes (72)', abs( (float) $item['data']->get_price() - 72.0 ) < 0.00001, 'got ' . $item['data']->get_price() );
$restored = OPF\Service\CartIntegration::restore_from_session( [ 'data' => wc_get_product( $p_woocs ) ], $item );
$check( 'woocs session base 10', abs( (float) $restored['opf_base_price'] - 10.0 ) < 0.00001 );
$GLOBALS['WOOCS']->rate = 2;
WC()->cart->calculate_totals();
$order = wc_create_order();
$created['orders'][] = $order->get_id();
$line_id = $order->add_product( $item['data'], 1 );
$line = $order->get_item( $line_id );
OPF\Service\CartIntegration::persist_order_item( $line, $key, $item, $order );
$line->save();
$check( 'woocs order line 48', abs( (float) $line->get_total() - 48.0 ) < 0.00001, 'got ' . $line->get_total() );
$GLOBALS['WOOCS']->current_currency = 'USD';
WC()->cart->calculate_totals();
$check( 'woocs default currency restores 24', abs( (float) $item['data']->get_price() - 24.0 ) < 0.00001 );
$GLOBALS['WOOCS']->current_currency = 'EUR';
remove_filter( 'woocommerce_product_get_price', $woocs_price_filter, 100 );
unset( $GLOBALS['product'] );
unset( $GLOBALS['WOOCS'] );

// ======================================================================
WP_CLI::log( '-- FOX-shaped fake (same WOOCS global, FOX API signature) --' );

class OPF_Commtax_Fox_Api {
	public string $current_currency = 'EUR';
	public string $default_currency = 'USD';
	public array $currency_calls = [];
	public function get_currencies( bool $suppress_filters = false ): array {
		$this->currency_calls[] = $suppress_filters;
		return [
			'USD' => [ 'name' => 'USD', 'rate' => 1, 'symbol' => '&#36;', 'position' => 'right', 'is_etalon' => 1, 'description' => 'USA dollar', 'hide_cents' => 0, 'flag' => '' ],
			'EUR' => [ 'name' => 'EUR', 'rate' => 0.89, 'symbol' => '&euro;', 'position' => 'left_space', 'is_etalon' => 0, 'description' => 'Euro', 'hide_cents' => 0, 'flag' => '' ],
		];
	}
	public function back_convert( float $amount, float $rate, int $decimals ): float {
		return round( $amount / $rate, $decimals );
	}
}

$GLOBALS['WOOCS'] = new OPF_Commtax_Fox_Api();
$fox_price_filter = static function ( $price ) {
	$api = $GLOBALS['WOOCS'];
	if ( 'USD' === $api->current_currency ) {
		return $price;
	}
	$rate = $api->get_currencies( true )[ $api->current_currency ]['rate'] ?? 1;
	return (float) $price * $rate; // FOX: foreign = default-currency price x rate.
};
add_filter( 'woocommerce_product_get_price', $fox_price_filter, 100 );
$active_filters[] = [ 'woocommerce_product_get_price', $fox_price_filter, 100, 1 ];

$p_fox = $mk_product( 'commtax fox product', '10' );
$g_fox = $mk_group( 'commtax fox group', $p_fox );
$GLOBALS['product'] = wc_get_product( $p_fox );
$config = OPF\Service\WoocsIntegration::merge_frontend_config( [] );
$check( 'fox currency table feeds frontend config (rate 0.89)', abs( (float) $config['currency_rate'] - 0.89 ) < 0.00001, 'got ' . $config['currency_rate'] );
$check( 'fox display options reach config', '&euro;' === $config['display_options']['symbol'] && '%1$s&nbsp;%2$s' === $config['display_options']['format'], wp_json_encode( $config['display_options'] ) );
$check( 'fox get_currencies called with documented default flag', in_array( false, $GLOBALS['WOOCS']->currency_calls, true ), wp_json_encode( $GLOBALS['WOOCS']->currency_calls ) );

$key = $add_opf( $p_fox, $g_fox );
WC()->cart->calculate_totals();
$item = WC()->cart->get_cart_item( $key );
$check( 'fox shop-currency target 24 (base 10 + addons 14)', abs( (float) $item['data']->get_price( 'edit' ) - 24.0 ) < 0.00001, 'got ' . $item['data']->get_price( 'edit' ) );
$fox_view = (float) $item['data']->get_price();
$check( 'fox foreign view = 24 x 0.89', abs( $fox_view - 24.0 * 0.89 ) < 0.001, 'got ' . $fox_view );
$check( 'fox back_convert restores shop base', abs( OPF\Service\WoocsIntegration::back_convert( $fox_view ) - 24.0 ) < 0.001, 'got ' . OPF\Service\WoocsIntegration::back_convert( $fox_view ) );
$order = wc_create_order();
$created['orders'][] = $order->get_id();
$line_id = $order->add_product( $item['data'], 1 );
$line = $order->get_item( $line_id );
OPF\Service\CartIntegration::persist_order_item( $line, $key, $item, $order );
$line->save();
$check( 'fox order line keeps converted total (21.36)', abs( (float) $line->get_total() - 24.0 * 0.89 ) < 0.001, 'got ' . $line->get_total() );
remove_filter( 'woocommerce_product_get_price', $fox_price_filter, 100 );
unset( $GLOBALS['product'], $GLOBALS['WOOCS'] );

// ======================================================================
WP_CLI::log( '-- Aelia-shaped fake --' );

// Declared conditionally so PHP does not early-bind the class (top-level
// unconditional class declarations are hoisted before line 33's guard runs).
if ( ! class_exists( 'WC_Aelia_CurrencySwitcher', false ) ) {
	class WC_Aelia_CurrencySwitcher {
		public static $rate = 2.0;
		public static $currency = 'EUR';
		public static function settings() {
			return new class {
				public function get_exchange_rate( $currency ) {
					return WC_Aelia_CurrencySwitcher::$rate;
				}
			};
		}
	}
}
$GLOBALS['woocommerce-aelia-currencyswitcher'] = new stdClass();
$aelia_currency_filter = static function () {
	return WC_Aelia_CurrencySwitcher::$currency;
};
$aelia_convert_filter = static function ( $amount, $from, $to ) {
	return $from === $to ? $amount : $amount * WC_Aelia_CurrencySwitcher::$rate;
};
$aelia_price_filter = static function ( $price, $product ) {
	if ( 'USD' === WC_Aelia_CurrencySwitcher::$currency || array_key_exists( 'price', $product->get_changes() ) ) {
		return $price;
	}
	$fixed = $product->get_meta( '_opf_commtax_aelia_fixed' );
	return '' !== $fixed ? (float) $fixed : $price * WC_Aelia_CurrencySwitcher::$rate;
};
add_filter( 'woocommerce_currency', $aelia_currency_filter );
add_filter( 'wc_aelia_cs_convert', $aelia_convert_filter, 10, 3 );
add_filter( 'woocommerce_product_get_price', $aelia_price_filter, 100, 2 );
add_filter( 'woocommerce_product_variation_get_price', $aelia_price_filter, 100, 2 );
$active_filters[] = [ 'woocommerce_currency', $aelia_currency_filter, 10, 1 ];
$active_filters[] = [ 'wc_aelia_cs_convert', $aelia_convert_filter, 10, 3 ];
$active_filters[] = [ 'woocommerce_product_get_price', $aelia_price_filter, 100, 2 ];
$active_filters[] = [ 'woocommerce_product_variation_get_price', $aelia_price_filter, 100, 2 ];
update_option( 'woocommerce_currency', 'USD' );

$p_aelia = $mk_product( 'commtax aelia product', '10' );
$g_aelia = $mk_group( 'commtax aelia group', $p_aelia );
$GLOBALS['product'] = wc_get_product( $p_aelia );

$key = $add_opf( $p_aelia, $g_aelia );
$check( 'aelia cart add succeeds', false !== $key );
WC()->cart->calculate_totals();
$item = WC()->cart->get_cart_item( $key );
$check( 'aelia foreign cart target 48 (24 x rate 2)', abs( (float) $item['data']->get_price() - 48.0 ) < 0.000001, 'got ' . $item['data']->get_price() );
$check( 'aelia line subtotal 48 includes converted addons', abs( (float) $item['line_subtotal'] - 48.0 ) < 0.000001, 'got ' . $item['line_subtotal'] );
WC()->cart->calculate_totals();
$check( 'aelia repeat totals stable', abs( (float) $item['data']->get_price() - 48.0 ) < 0.000001 );
WC_Aelia_CurrencySwitcher::$rate = 3;
WC()->cart->calculate_totals();
$check( 'aelia rate change recomputes (72)', abs( (float) $item['data']->get_price() - 72.0 ) < 0.000001, 'got ' . $item['data']->get_price() );
WC_Aelia_CurrencySwitcher::$rate = 2;
WC()->cart->calculate_totals();
$restored = OPF\Service\CartIntegration::restore_from_session( [ 'data' => wc_get_product( $p_aelia ) ], $item );
$check( 'aelia session base 10', abs( (float) $restored['opf_base_price'] - 10.0 ) < 0.000001 );
$order = wc_create_order();
$created['orders'][] = $order->get_id();
$order->set_currency( 'EUR' );
$line_id = $order->add_product( $item['data'], 1 );
$line = $order->get_item( $line_id );
OPF\Service\CartIntegration::persist_order_item( $line, $key, $item, $order );
$line->save();
$check( 'aelia order line 48', abs( (float) $line->get_total() - 48.0 ) < 0.000001, 'got ' . $line->get_total() );
WC_Aelia_CurrencySwitcher::$currency = 'USD';
WC()->cart->calculate_totals();
$check( 'aelia default currency restores 24', abs( (float) $item['data']->get_price() - 24.0 ) < 0.000001, 'got ' . $item['data']->get_price() );
WC_Aelia_CurrencySwitcher::$currency = 'EUR';

// Manual foreign price (Aelia per-product fixed price) — formula base stays original.
$fresh = wc_get_product( $p_aelia );
$fresh->update_meta_data( '_opf_commtax_aelia_fixed', 50 );
$fresh->save();
WC()->cart->calculate_totals();
$check( 'aelia fixed foreign price: (50/2 base + 3 fixed + 2.5 percent + 10 formula) x2 = 81', abs( (float) $item['data']->get_price() - 81.0 ) < 0.000001, 'got ' . $item['data']->get_price() );
$fresh->delete_meta_data( '_opf_commtax_aelia_fixed' );
$fresh->save();

// Variation bases via woocommerce_available_variation bridge.
$parent = new WC_Product_Variable();
$parent->set_name( 'commtax aelia variable' );
$parent->set_status( 'publish' );
$parent_id = $parent->save();
$created['products'][] = $parent_id;
$var = new WC_Product_Variation();
$var->set_parent_id( $parent_id );
$var->set_regular_price( '15' );
$var->set_status( 'publish' );
$var_id = $var->save();
$created['products'][] = $var_id;
$variation_data = $parent->get_available_variation( wc_get_product( $var_id ) );
$check( 'aelia variation base 15 (original shop price)', isset( $variation_data['opf_base_price'] ) && abs( (float) $variation_data['opf_base_price'] - 15.0 ) < 0.000001, 'got ' . var_export( $variation_data['opf_base_price'] ?? null, true ) );
$check( 'aelia variation formula base 15', isset( $variation_data['opf_formula_base_price'] ) && abs( (float) $variation_data['opf_formula_base_price'] - 15.0 ) < 0.000001, 'got ' . var_export( $variation_data['opf_formula_base_price'] ?? null, true ) );

// Order-again: base comes from current catalog price, not the stored foreign amount.
$oa_data = OPF\Service\CartIntegration::restore_order_again( [], $line, $order );
$check( 'aelia order-again base re-derives from catalog (edit 10)', isset( $oa_data['opf_base_price'] ) && abs( (float) $oa_data['opf_base_price'] - 10.0 ) < 0.000001, 'got ' . var_export( $oa_data['opf_base_price'] ?? null, true ) );

if ( $failures > 0 ) {
	WP_CLI::error( "$failures currency check(s) failed." );
}
WP_CLI::success( 'Currency lifecycle passed: WOOCS + FOX-shaped + Aelia fake APIs through real Woo cart/order.' );
