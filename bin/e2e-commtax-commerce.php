<?php
/**
 * commtax lane — OPF commerce lifecycle proof (tax / weight / coupon scope).
 *
 * Proves on the disposable commtax clone:
 *  - WAPF-COMMERCE-TAX: addon amounts merge into the product line price and
 *    are taxed at the PRODUCT's tax class through cart -> order -> order-again
 *    (standard, reduced, non-taxable, variation class, inclusive/exclusive
 *    modes) — the same model WAPF 3.1.5 uses. Documents the display-side
 *    data-tax/multiplier gap.
 *  - WAPF-COMMERCE-WEIGHT: OPF retains no weight metadata and does not adjust
 *    cart item weight (gap vs the reference run in
 *    bin/e2e-commtax-wapf-reference.php).
 *  - WAPF-PRICE-COUPON-SCOPE: OPF's percent-only exclusion math is equivalent
 *    to WAPF's recalculate_coupon_discount; live cart/order checks.
 *
 * Run: OPF_COMMTAX_ALLOW=1 wp eval-file bin/e2e-commtax-commerce.php --path=/tmp/opf-image-commtax-wp
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
if ( class_exists( \SW_WAPF_PRO\WAPF::class ) ) {
	throw new RuntimeException( 'Run the OPF phase with WAPF deactivated.' );
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

$created = [ 'products' => [], 'groups' => [], 'coupons' => [], 'tax_rates' => [], 'orders' => [], 'attachments' => [] ];
$option_names = [
	'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on',
	'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart', 'woocommerce_weight_unit',
	'woocommerce_currency', 'opf_admin_only', 'opf_theme_compat', 'opf_price_summary_mode',
	'opf_show_price_hints',
];
$original_options = [];
foreach ( $option_names as $name ) {
	$original_options[ $name ] = get_option( $name, null );
}

register_shutdown_function(
	static function () use ( &$created, &$original_options, &$results ): void {
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		unset( $_POST['opf'], $_POST['opf_excl_addons'] );
		foreach ( $created['orders'] as $id ) {
			$o = wc_get_order( (int) $id );
			if ( $o ) { $o->delete( true ); }
		}
		foreach ( $created['coupons'] as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( $created['groups'] as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( $created['products'] as $id ) {
			$p = wc_get_product( (int) $id );
			if ( $p ) { $p->delete( true ); }
		}
		foreach ( $created['tax_rates'] as $id ) {
			WC_Tax::_delete_tax_rate( (int) $id );
		}
		foreach ( $original_options as $name => $value ) {
			null === $value ? delete_option( $name ) : update_option( $name, $value );
		}
		\OPF\Service\FieldGroups::flush_cache();
		$out = getenv( 'OPF_COMMTAX_RESULTS' );
		if ( $out ) {
			file_put_contents( $out, wp_json_encode( [ 'utc' => gmdate( 'c' ), 'checks' => $results ], JSON_PRETTY_PRINT ) );
		}
	}
);

WP_CLI::log( '== OPF commtax commerce lifecycle ==' );

update_option( 'opf_admin_only', 'no' );

// --- Tax scaffolding ---------------------------------------------------------
$rate_std = WC_Tax::_insert_tax_rate( [
	'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '10.0000',
	'tax_rate_name' => 'commtax-std', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0,
	'tax_rate_shipping' => 0, 'tax_rate_order' => 900, 'tax_rate_class' => '',
] );
$created['tax_rates'][] = $rate_std;
$rate_red = WC_Tax::_insert_tax_rate( [
	'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '5.0000',
	'tax_rate_name' => 'commtax-red', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0,
	'tax_rate_shipping' => 0, 'tax_rate_order' => 901, 'tax_rate_class' => 'reduced-rate',
] );
$created['tax_rates'][] = $rate_red;

update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_based_on', 'base' );
update_option( 'woocommerce_tax_display_shop', 'excl' );
update_option( 'woocommerce_tax_display_cart', 'excl' );
update_option( 'woocommerce_weight_unit', 'kg' );

// --- Fixture helpers ---------------------------------------------------------
$mk = static function ( string $name, string $price, array $extra = [] ) use ( &$created ): int {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_status( 'publish' );
	foreach ( $extra as $method => $value ) {
		$p->{ 'set_' . $method }( $value );
	}
	$id = $p->save();
	$created['products'][] = $id;
	return $id;
};

$mk_group = static function ( string $title, array $product_ids, array $fields ) use ( &$created ): int {
	$group = new OPF\Engine\FieldGroup( [
		'fields' => $fields,
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => array_map( 'strval', $product_ids ) ] ] ] ],
	] );
	$id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => $title ] );
	$created['groups'][] = $id;
	OPF\Service\FieldGroups::flush_cache();
	return $id;
};

$add_opf = static function ( int $product_id, int $qty, int $gid, array $values, int $variation_id = 0, array $variation = [] ) {
	WC()->cart->empty_cart();
	$_POST['opf'] = [ (string) $gid => $values ];
	$key = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation );
	unset( $_POST['opf'] );
	return $key;
};

// === TAX =====================================================================
WP_CLI::log( '-- WAPF-COMMERCE-TAX: addon taxed at product tax class --' );

// Standard class: $100 + $10 per-unit addon, qty 2 -> line 220, tax 22.
$p_std = $mk( 'commtax opf std', '100', [ 'tax_status' => 'taxable', 'tax_class' => '' ] );
$g_std = $mk_group( 'commtax opf std group', [ $p_std ], [
	[ 'id' => 'addon', 'label' => 'Addon', 'type' => 'select',
	  'choices' => [
		[ 'slug' => 'no', 'label' => 'No', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
		[ 'slug' => 'yes', 'label' => 'Yes', 'pricing' => [ 'type' => 'fixed', 'amount' => 10, 'per_unit' => true ] ],
	  ] ],
] );
$key = $add_opf( $p_std, 2, $g_std, [ 'addon' => 'yes' ] );
$check( 'opf add-to-cart succeeds', false !== $key );
WC()->cart->calculate_totals();
$item = WC()->cart->get_cart_item( $key );
$check( 'merged unit price 110 (base 100 + addon 10)', abs( (float) $item['data']->get_price( 'edit' ) - 110.0 ) < 0.001, 'got ' . $item['data']->get_price( 'edit' ) );
$check( 'line subtotal 220', abs( (float) $item['line_subtotal'] - 220.0 ) < 0.001, 'got ' . $item['line_subtotal'] );
$check( 'line tax = 10% x 220 = 22', abs( (float) $item['line_tax'] - 22.0 ) < 0.01, 'got ' . $item['line_tax'] . ' / ' . wp_json_encode( $item['line_tax_data'] ?? null ) );
$check( 'cart contents tax 22', abs( (float) WC()->cart->get_cart_contents_tax() - 22.0 ) < 0.01, 'got ' . WC()->cart->get_cart_contents_tax() );
$order_id = WC()->checkout()->create_order( [ 'payment_method' => 'bacs', 'billing_email' => 'commtax-opf@example.test' ] );
$order = is_wp_error( $order_id ) ? null : wc_get_order( $order_id );
if ( $order ) {
	$created['orders'][] = $order->get_id();
	$line = array_values( $order->get_items() )[0] ?? null;
	$check( 'order line total tax 22 persisted', $line instanceof WC_Order_Item_Product && abs( (float) $line->get_total_tax() - 22.0 ) < 0.01, 'got ' . ( $line ? $line->get_total_tax() : 'n/a' ) );
	$check( 'order line keeps _opf_fields', $line instanceof WC_Order_Item_Product && '' !== (string) $line->get_meta( '_opf_fields' ) );
	$check( 'order tax total 22', abs( (float) $order->get_total_tax() - 22.0 ) < 0.01, 'got ' . $order->get_total_tax() );

	// Order-again path: OPF values restore; merged price + tax recompute identically.
	$oa_data = OPF\Service\CartIntegration::restore_order_again( [], $line, $order );
	$check( 'order-again restores structured opf values', isset( $oa_data[ OPF\Service\CartIntegration::ITEM_KEY ][ (string) $g_std ]['addon'] ) && 'yes' === $oa_data[ OPF\Service\CartIntegration::ITEM_KEY ][ (string) $g_std ]['addon'] );
	WC()->cart->empty_cart();
	$oa_key = WC()->cart->add_to_cart( $p_std, 2, 0, [], $oa_data );
	WC()->cart->calculate_totals();
	$oa_item = WC()->cart->get_cart_item( $oa_key );
	$check( 'order-again line tax recomputes to 22', $oa_item && abs( (float) $oa_item['line_tax'] - 22.0 ) < 0.01, 'got ' . ( $oa_item ? $oa_item['line_tax'] : 'n/a' ) );
} else {
	$check( 'order created', false );
	WC()->cart->empty_cart();
}

// Reduced class: same addon -> 5% of 110.
$p_red = $mk( 'commtax opf red', '100', [ 'tax_status' => 'taxable', 'tax_class' => 'reduced-rate' ] );
$g_red = $mk_group( 'commtax opf red group', [ $p_red ], [
	[ 'id' => 'addon', 'label' => 'Addon', 'type' => 'select',
	  'choices' => [
		[ 'slug' => 'no', 'label' => 'No', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
		[ 'slug' => 'yes', 'label' => 'Yes', 'pricing' => [ 'type' => 'fixed', 'amount' => 10, 'per_unit' => true ] ],
	  ] ],
] );
$key = $add_opf( $p_red, 1, $g_red, [ 'addon' => 'yes' ] );
WC()->cart->calculate_totals();
$item = WC()->cart->get_cart_item( $key );
$check( 'reduced class line tax = 5% x 110 = 5.5', abs( (float) $item['line_tax'] - 5.5 ) < 0.01, 'got ' . $item['line_tax'] );

// Non-taxable: addon never taxed.
$p_none = $mk( 'commtax opf notax', '100', [ 'tax_status' => 'none' ] );
$g_none = $mk_group( 'commtax opf notax group', [ $p_none ], [
	[ 'id' => 'addon', 'label' => 'Addon', 'type' => 'select',
	  'choices' => [
		[ 'slug' => 'no', 'label' => 'No', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
		[ 'slug' => 'yes', 'label' => 'Yes', 'pricing' => [ 'type' => 'fixed', 'amount' => 10, 'per_unit' => true ] ],
	  ] ],
] );
$key = $add_opf( $p_none, 1, $g_none, [ 'addon' => 'yes' ] );
WC()->cart->calculate_totals();
$item = WC()->cart->get_cart_item( $key );
$check( 'non-taxable product: addon line tax 0', 0.0 === (float) $item['line_tax'], 'got ' . var_export( $item['line_tax'], true ) );

// Variation class: parent standard, variation reduced -> addon at reduced rate.
$parent = new WC_Product_Variable();
$parent->set_name( 'commtax opf variable' );
$parent->set_status( 'publish' );
$parent_id = $parent->save();
$created['products'][] = $parent_id;
$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent_id );
$variation->set_regular_price( '80' );
$variation->set_tax_class( 'reduced-rate' );
$variation->set_status( 'publish' );
$variation_id = $variation->save();
$created['products'][] = $variation_id;
$g_var = $mk_group( 'commtax opf var group', [ $parent_id ], [
	[ 'id' => 'addon', 'label' => 'Addon', 'type' => 'select',
	  'choices' => [
		[ 'slug' => 'no', 'label' => 'No', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
		[ 'slug' => 'yes', 'label' => 'Yes', 'pricing' => [ 'type' => 'fixed', 'amount' => 10, 'per_unit' => true ] ],
	  ] ],
] );
$key = $add_opf( $parent_id, 1, $g_var, [ 'addon' => 'yes' ], $variation_id );
WC()->cart->calculate_totals();
$item = WC()->cart->get_cart_item( $key );
$check( 'variation addon uses variation tax class (5% x 90 = 4.5)', $item && abs( (float) $item['line_tax'] - 4.5 ) < 0.01, 'got ' . ( $item ? $item['line_tax'] : 'n/a' ) );

// Inclusive mode: entered prices incl tax; merged line still one tax class.
update_option( 'woocommerce_prices_include_tax', 'yes' );
$p_incl = $mk( 'commtax opf incl', '110', [ 'tax_status' => 'taxable', 'tax_class' => '' ] );
$g_incl = $mk_group( 'commtax opf incl group', [ $p_incl ], [
	[ 'id' => 'addon', 'label' => 'Addon', 'type' => 'select',
	  'choices' => [
		[ 'slug' => 'no', 'label' => 'No', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
		[ 'slug' => 'yes', 'label' => 'Yes', 'pricing' => [ 'type' => 'fixed', 'amount' => 10, 'per_unit' => true ] ],
	  ] ],
] );
$key = $add_opf( $p_incl, 1, $g_incl, [ 'addon' => 'yes' ] );
WC()->cart->calculate_totals();
$item = WC()->cart->get_cart_item( $key );
$incl_rates = WC_Tax::get_rates( '' );
$expected_incl_tax = array_sum( WC_Tax::calc_inclusive_tax( 120.0, $incl_rates ) );
$check( 'inclusive mode: merged gross line 120 taxed 10% inside', abs( (float) $item['line_tax'] - $expected_incl_tax ) < 0.02, 'got ' . $item['line_tax'] . ' expected ' . $expected_incl_tax );
update_option( 'woocommerce_prices_include_tax', 'no' );

// Display-side audit: data-tax is hardcoded 1 vs WAPF's per-product multiplier.
update_option( 'opf_theme_compat', 'yes' );
update_option( 'opf_price_summary_mode', 'three' );
$GLOBALS['product'] = wc_get_product( $p_std );
ob_start();
\OPF\Service\Renderer::render();
$rendered = (string) ob_get_clean();
$data_tax = preg_match( '/data-tax="([^"]*)"/', $rendered, $m ) ? $m[1] : null;
$wapf_multiplier = 1.1; // Helper::get_tax_multiplier equivalent for a 10% taxable product.
$check( 'BUG-DOC: opf totals data-tax is always 1 (dead conditional) while WAPF would emit 1.1', '1' === $data_tax, 'data-tax=' . var_export( $data_tax, true ) . ' wapf-equivalent=' . $wapf_multiplier );
$data_price = preg_match( '/data-product-price="([^"]*)"/', $rendered, $m ) ? $m[1] : null;
$check( 'totals data-product-price carries raw (excl) price', null !== $data_price && abs( (float) $data_price - 100.0 ) < 0.001, 'got ' . var_export( $data_price, true ) );
$hint = \OPF\Service\Renderer::pricing_hint_html( [ 'type' => 'fixed', 'amount' => 10 ], 100.0 );
$check( 'BUG-DOC: pricing hint renders raw amount, no tax display conversion (WAPF maybe_add_tax)', false !== strpos( $hint, '10' ) && false === strpos( $hint, '11' ), 'hint=' . wp_strip_all_tags( $hint ) );
unset( $GLOBALS['product'] );

// === WEIGHT ===================================================================
WP_CLI::log( '-- WAPF-COMMERCE-WEIGHT: OPF gap documentation --' );

$p_w = $mk( 'commtax opf weight', '50', [ 'weight' => '2.0' ] );

// OPF schema does not retain WAPF-style weight options anywhere.
$raw_field = [
	'id' => 'selw', 'label' => 'Packaging', 'type' => 'select',
	'options' => [ 'weight' => '1' ],
	'choices' => [
		[ 'slug' => 'light', 'label' => 'Light', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ], 'options' => [ 'weight' => '0.5' ] ],
		[ 'slug' => 'heavy', 'label' => 'Heavy', 'pricing' => [ 'type' => 'fixed', 'amount' => 10 ], 'options' => [ 'weight' => '[qty]' ] ],
	],
];
$normalized = \OPF\Engine\FieldGroup::normalize_field( $raw_field );
$field_json = wp_json_encode( $normalized );
$check( 'OPF field schema drops field-level weight metadata', false === strpos( (string) $field_json, 'weight' ), $field_json );
$calc_reflection = new ReflectionClass( \OPF\Engine\Calculator::class );
$builtin_fn = $calc_reflection->getMethod( 'builtin_formula_functions' );
$builtin_fn->setAccessible( true );
$builtin_functions = (array) $builtin_fn->invoke( null );
$check( 'Calculator has no weight formula function', ! array_key_exists( 'weight', $builtin_functions ), implode( ',', array_keys( $builtin_functions ) ) );

$g_w = $mk_group( 'commtax opf weight group', [ $p_w ], [
	[ 'id' => 'selw', 'label' => 'Packaging', 'type' => 'select',
	  'choices' => [
		[ 'slug' => 'light', 'label' => 'Light', 'pricing' => [ 'type' => 'fixed', 'amount' => 5, 'per_unit' => true ] ],
		[ 'slug' => 'heavy', 'label' => 'Heavy', 'pricing' => [ 'type' => 'fixed', 'amount' => 10, 'per_unit' => true ] ],
	  ] ],
] );
$key = $add_opf( $p_w, 3, $g_w, [ 'selw' => 'light' ] );
WC()->cart->calculate_totals();
$item = WC()->cart->get_cart_item( $key );
$check( 'GAP: OPF cart item weight stays at product base (2.0), WAPF would be 2.5', abs( (float) $item['data']->get_weight() - 2.0 ) < 0.0001, 'got ' . $item['data']->get_weight() );
$check( 'cart contents weight = 3 x 2.0 = 6.0', abs( (float) WC()->cart->get_cart_contents_weight() - 6.0 ) < 0.0001, 'got ' . WC()->cart->get_cart_contents_weight() );

// === COUPON-SCOPE ==============================================================
WP_CLI::log( '-- WAPF-PRICE-COUPON-SCOPE: math equivalence + live cart --' );

// WAPF 3.1.5 Integrations_Controller::recalculate_coupon_discount, verbatim port.
$wapf_percent_discount = static function ( float $discounting_amount, float $options_total_per_unit, int $apply_qty, float $percent ): float {
	$price_to_discount = $discounting_amount - ( $options_total_per_unit * $apply_qty );
	return (float) ( $price_to_discount * ( $percent / 100 ) );
};
$matrix_ok = true;
$matrix_cases = [];
// Reachable shape: base >= 0, addon >= 0, discounting = adjusted_unit x apply_qty.
foreach ( [ 100.0, 33.37, 99.99 ] as $unit_base ) {
	foreach ( [ 0.0, 10.0, 20.0, 47.5 ] as $addon_unit ) {
		$adjusted_unit = $unit_base + $addon_unit;
		foreach ( [ 1, 2, 3 ] as $qty ) {
			$discounting = $adjusted_unit * $qty;
			foreach ( [ 10.0, 12.5 ] as $percent ) {
				$wapf = $wapf_percent_discount( $discounting, $addon_unit, $qty, $percent );
				$opf  = \OPF\Service\CartIntegration::base_only_percent_discount( $discounting, $unit_base, $adjusted_unit, $qty, $percent );
				$matrix_cases[] = [ 'base' => $unit_base, 'addon' => $addon_unit, 'qty' => $qty, 'percent' => $percent, 'wapf' => $wapf, 'opf' => $opf ];
				// OPF clamps into [0, discounting_amount]; WAPF can go negative
				// when addon*qty exceeds the discounting amount. Within WAPF's
				// reachable range the formulas must match to the cent.
				if ( abs( $wapf - $opf ) > 0.005 && $wapf >= 0 ) {
					$matrix_ok = false;
				}
			}
		}
	}
}
$check( 'OPF base_only_percent_discount == WAPF recalculate_coupon_discount on reachable matrix', $matrix_ok, count( $matrix_cases ) . ' cases' );
$neg_case = $wapf_percent_discount( 100.0, 200.0, 1, 10.0 );
$check( 'documents OPF clamps negative WAPF result (wapf=' . round( $neg_case, 2 ) . ', opf=0)', abs( \OPF\Service\CartIntegration::base_only_percent_discount( 100.0, -100.0, 100.0, 1, 10.0 ) - 0.0 ) < 0.0001 && $neg_case < 0 );

$p_c = $mk( 'commtax opf coupon', '100' );
$g_c = $mk_group( 'commtax opf coupon group', [ $p_c ], [
	[ 'id' => 'addon', 'label' => 'Addon', 'type' => 'select',
	  'choices' => [
		[ 'slug' => 'no', 'label' => 'No', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
		[ 'slug' => 'yes', 'label' => 'Yes', 'pricing' => [ 'type' => 'fixed', 'amount' => 20, 'per_unit' => true ] ],
	  ] ],
] );
$coupon = new WC_Coupon();
$coupon->set_code( 'commtax-opf-' . strtolower( wp_generate_password( 6, false, false ) ) );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( 10 );
$coupon->set_product_ids( [ $p_c ] );
$coupon->save();
$created['coupons'][] = $coupon->get_id();

$key = $add_opf( $p_c, 1, $g_c, [ 'addon' => 'yes' ] );
WC()->cart->apply_coupon( $coupon->get_code() );
WC()->cart->calculate_totals();
$check( 'unscoped percent coupon discounts base+addon (12)', abs( (float) WC()->cart->get_discount_total() - 12.0 ) < 0.01, 'got ' . WC()->cart->get_discount_total() );
WC()->cart->remove_coupon( $coupon->get_code() );

$coupon->update_meta_data( 'wapf_excl_addons', 'yes' );
$coupon->save();
WC()->cart->apply_coupon( $coupon->get_code() );
WC()->cart->calculate_totals();
$check( 'scoped percent coupon discounts base only (10)', abs( (float) WC()->cart->get_discount_total() - 10.0 ) < 0.01, 'got ' . WC()->cart->get_discount_total() );
$order_id = WC()->checkout()->create_order( [ 'payment_method' => 'bacs', 'billing_email' => 'commtax-opf@example.test' ] );
$order = is_wp_error( $order_id ) ? null : wc_get_order( $order_id );
if ( $order ) {
	$created['orders'][] = $order->get_id();
	$line = array_values( $order->get_items() )[0] ?? null;
	$check( 'order persists scoped discount: line 110 total after 10 off', $line instanceof WC_Order_Item_Product && abs( (float) $line->get_total() - 110.0 ) < 0.01, 'got ' . ( $line ? $line->get_total() : 'n/a' ) );
	$check( 'order discount total 10', abs( (float) $order->get_discount_total() - 10.0 ) < 0.01, 'got ' . $order->get_discount_total() );
} else {
	$check( 'scoped order created', false );
}

if ( $failures > 0 ) {
	WP_CLI::error( "$failures commtax commerce check(s) failed." );
}
WP_CLI::success( 'OPF commtax commerce checks passed (see BUG-DOC/GAP lines for documented differences).' );
