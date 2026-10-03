<?php
/**
 * WAPF Extended 3.1.5 reference fixture for the commtax lane.
 *
 * Runs against the live WAPF plugin (activated for this phase only, OPF
 * deactivated) on the disposable commtax clone. Proves the reference
 * behavior OPF must match for WAPF-COMMERCE-WEIGHT, WAPF-COMMERCE-TAX and
 * WAPF-PRICE-COUPON-SCOPE.
 *
 * Run: OPF_COMMTAX_ALLOW=1 wp eval-file bin/e2e-commtax-wapf-reference.php --path=/tmp/opf-image-commtax-wp
 */

defined( 'ABSPATH' ) || exit;

// Lane clones pin the run to their own disposable path via OPF_COMMTAX_ABSPATH
// (default: the commtax lane clone). The remaining guards still demand an
// owned SQLite dropin on localhost.
$opf_commtax_abspath = getenv( 'OPF_COMMTAX_ABSPATH' ) ?: '/tmp/opf-image-commtax-wp';
if (
	'1' !== getenv( 'OPF_COMMTAX_ALLOW' ) || ! defined( 'WP_CLI' ) || ! WP_CLI
	|| $opf_commtax_abspath !== realpath( ABSPATH )
	|| ! defined( 'FQDB' ) || 0 !== strpos( realpath( FQDB ), realpath( ABSPATH ) . '/' )
	|| ! defined( 'SQLITE_DB_DROPIN_VERSION' )
	|| '127.0.0.1' !== wp_parse_url( home_url(), PHP_URL_HOST )
) {
	throw new RuntimeException( 'Guarded: owned commtax SQLite clone + OPF_COMMTAX_ALLOW=1 only.' );
}
if ( ! class_exists( \SW_WAPF_PRO\WAPF::class ) || ! function_exists( 'wapf_get_setting' ) ) {
	throw new RuntimeException( 'WAPF Extended must be active for the reference run.' );
}
if ( defined( 'OPF_VERSION' ) ) {
	throw new RuntimeException( 'OPF must be deactivated for the reference run.' );
}

use SW_WAPF_PRO\Includes\Classes\Field_Groups;
use SW_WAPF_PRO\Includes\Classes\Fields;
use SW_WAPF_PRO\Includes\Classes\Helper;
use SW_WAPF_PRO\Includes\Models\FieldGroup as WapfFieldGroup;

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

$created = [ 'products' => [], 'groups' => [], 'coupons' => [], 'tax_rates' => [], 'orders' => [] ];
$option_names = [
	'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on',
	'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart', 'woocommerce_weight_unit',
];
$original_options = [];
foreach ( $option_names as $name ) {
	$original_options[ $name ] = get_option( $name, null );
}

register_shutdown_function(
	static function () use ( &$created, &$original_options, &$results ): void {
		$out = getenv( 'OPF_COMMTAX_RESULTS' );
		if ( $out ) {
			file_put_contents( $out, wp_json_encode( [ 'utc' => gmdate( 'c' ), 'checks' => $results ], JSON_PRETTY_PRINT ) );
		}
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'] );
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
	}
);

WP_CLI::log( '== WAPF 3.1.5 reference: commtax ==' );

// --- Tax scaffolding -------------------------------------------------------
$rate_std = WC_Tax::_insert_tax_rate( [
	'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '10.0000',
	'tax_rate_name' => 'commtax-std', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0,
	'tax_rate_shipping' => 0, 'tax_rate_order' => 900, 'tax_rate_class' => '',
] );
$created['tax_rates'][] = $rate_std;
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_based_on', 'base' );
update_option( 'woocommerce_tax_display_shop', 'excl' );
update_option( 'woocommerce_tax_display_cart', 'excl' );
update_option( 'woocommerce_weight_unit', 'kg' );

// --- Products --------------------------------------------------------------
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

$p_weight   = $mk( 'commtax wapf weight', '100', [ 'weight' => '2.0' ] );
$p_virtual  = $mk( 'commtax wapf virtual', '100', [ 'virtual' => true ] );
$p_notax    = $mk( 'commtax wapf notax', '100', [ 'tax_status' => 'none' ] );
$p_taxable  = $mk( 'commtax wapf taxable', '100' );

// --- WAPF field group (choice weight + numeric [x] weight + [qty] weight) --
$group_data = [
	'id' => 0,
	'type' => 'wapf_product',
	'layout' => [],
	'variables' => [],
	'fields' => [
		[
			'id' => 'selw', 'label' => 'Packaging', 'description' => '', 'type' => 'select',
			'required' => false, 'class' => '', 'width' => 100, 'parent_clone' => [],
			'options' => [
				'choices' => [
					[ 'slug' => 'light', 'label' => 'Light', 'pricing_type' => 'fx', 'pricing_amount' => 5, 'options' => [ 'weight' => '0.5' ] ],
					[ 'slug' => 'heavy', 'label' => 'Heavy', 'pricing_type' => 'fx', 'pricing_amount' => 10, 'options' => [ 'weight' => '[qty]' ] ],
					[ 'slug' => 'neg', 'label' => 'Negative', 'pricing_type' => 'none', 'pricing_amount' => 0, 'options' => [ 'weight' => '-10' ] ],
				],
			],
			'conditionals' => [], 'clone' => [ 'enabled' => false ],
			'pricing' => [ 'type' => 'none', 'amount' => 0, 'enabled' => false ],
		],
		[
			'id' => 'numw', 'label' => 'Units of cable', 'description' => '', 'type' => 'number',
			'required' => false, 'class' => '', 'width' => 100, 'parent_clone' => [],
			'options' => [ 'weight' => '[x]' ],
			'conditionals' => [], 'clone' => [ 'enabled' => false ],
			'pricing' => [ 'type' => 'none', 'amount' => 0, 'enabled' => false ],
		],
	],
	'rule_groups' => [
		[ 'rules' => [ [
			'condition' => 'product',
			'value'     => array_map(
				static fn( $id ) => [ 'id' => $id, 'text' => (string) $id ],
				[ $p_weight, $p_virtual, $p_notax, $p_taxable ]
			),
			'subject'   => 'product',
		] ] ],
	],
];
$fg = ( new WapfFieldGroup() )->from_array( $group_data );
$wapf_gid = Field_Groups::save( $fg, 'wapf_product', null, 'commtax wapf weight group', 'publish' );
$created['groups'][] = $wapf_gid;
\SW_WAPF_PRO\Includes\Classes\Cache::clear();
$check( 'wapf field group saved', $wapf_gid > 0, 'id=' . $wapf_gid );

// WAPF 3.1.5 runs pricing + weight only on the FIRST
// woocommerce_before_calculate_totals firing per request
// (did_action() > 1 early return in class-product-controller.php:617 and
// $weight_was_calculated in class-extended-controller.php:378). A real request
// calculates totals once; this multi-scenario fixture resets both guards before
// each totals pass so every scenario observes first-pass behavior.
$reset_wapf_once_guards = static function (): void {
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	$hook = $GLOBALS['wp_filter']['woocommerce_before_calculate_totals'] ?? null;
	if ( $hook instanceof WP_Hook ) {
		foreach ( $hook->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn = $cb['function'] ?? null;
				if ( is_array( $fn ) && is_object( $fn[0] ) && str_contains( get_class( $fn[0] ), 'Extended' ) ) {
					$prop = new ReflectionProperty( $fn[0], 'weight_was_calculated' );
					$prop->setAccessible( true );
					$prop->setValue( $fn[0], false );
				}
			}
		}
	}
};
$recalc = static function () use ( $reset_wapf_once_guards ): void {
	$reset_wapf_once_guards();
	WC()->cart->calculate_totals();
};

// WC_Cart::add_to_cart() itself fires woocommerce_before_calculate_totals once
// (verified: did_action() increments during add). The guards are reset BEFORE
// the add so that single first-pass calc prices fields and adds weight; an
// extra calculate_totals() call would re-add weight to the already-mutated
// product, so callers must NOT recalc right after adding.
$add_wapf = static function ( int $product_id, int $qty, array $values ) use ( $wapf_gid, $reset_wapf_once_guards ) {
	WC()->cart->empty_cart();
	$reset_wapf_once_guards();
	$_REQUEST['wapf_field_groups'] = (string) $wapf_gid;
	$_REQUEST['wapf'] = $values;
	$key = WC()->cart->add_to_cart( $product_id, $qty );
	unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'] );
	return $key;
};
$item_of = static function ( string $key ) {
	$item = WC()->cart->get_cart_item( $key );
	return $item;
};

// --- WEIGHT reference proofs -----------------------------------------------
WP_CLI::log( '-- weight: choice + [x] + [qty] --' );

$key = $add_wapf( $p_weight, 3, [ 'field_selw' => 'light', 'field_numw' => '4' ] );
$check( 'wapf add-to-cart succeeds', false !== $key );
$item = $item_of( $key );
$check( 'cart field carries calc_weight marker', ! empty( $item['wapf'][0]['calc_weight'] ) || ! empty( $item['wapf'][1]['calc_weight'] ), wp_json_encode( array_map( static fn( $f ) => $f['calc_weight'] ?? null, (array) $item['wapf'] ) ) );
$check( 'item weight = base 2.0 + choice 0.5 + [x]=4 => 6.5', abs( (float) $item['data']->get_weight() - 6.5 ) < 0.0001, 'got ' . $item['data']->get_weight() . ' wapf=' . wp_json_encode( array_map( static fn( $f ) => [ 'id' => $f['id'] ?? '?', 'values' => count( (array) ( $f['values'] ?? [] ) ), 'raw' => $f['raw'] ?? null ], (array) $item['wapf'] ) ) );
$check( 'cart contents weight scales with qty 3 => 19.5', abs( (float) WC()->cart->get_cart_contents_weight() - 19.5 ) < 0.0001, 'got ' . WC()->cart->get_cart_contents_weight() );

// [qty] substitutes the line quantity once per unit.
$key = $add_wapf( $p_weight, 3, [ 'field_selw' => 'heavy' ] );
$item = $item_of( $key );
$check( '[qty] choice weight = base 2.0 + line qty 3 => 5.0', abs( (float) $item['data']->get_weight() - 5.0 ) < 0.0001, 'got ' . $item['data']->get_weight() );

// Virtual products keep weight off entirely.
$key = $add_wapf( $p_virtual, 1, [ 'field_selw' => 'light' ] );
$item = $item_of( $key );
$check( 'virtual product weight untouched', 0.0 === (float) $item['data']->get_weight() || '' === $item['data']->get_weight(), 'got ' . var_export( $item['data']->get_weight(), true ) );

// Negative sums floor at zero.
$key = $add_wapf( $p_weight, 1, [ 'field_selw' => 'neg' ] );
$item = $item_of( $key );
$check( 'negative weight sum floors at 0', 0.0 === (float) $item['data']->get_weight(), 'got ' . $item['data']->get_weight() );

// 3.1.5 substitution semantics: '[x]*0.5' floatvals to x only (real formulas are a 3.2 feature).
$_REQUEST['wapf_field_groups'] = (string) $wapf_gid;
$raw_field = null;
foreach ( Field_Groups::get_by_id( (string) $wapf_gid )->fields as $f ) {
	if ( 'numw' === $f->id ) { $raw_field = $f; }
}
$check( 'group reloaded with numw field', null !== $raw_field );
if ( $raw_field ) {
	$substituted = str_replace( [ '[qty]', '[x]' ], [ '2', '4' ], '[x]*0.5' );
	$check( 'reference weight expression is floatval substitution, not arithmetic', 4.0 === floatval( $substituted ), 'substituted="' . $substituted . '"' );
}
unset( $_REQUEST['wapf_field_groups'] );

// Order persistence: Woo order lines carry no weight; cart weight is the contract.
$key = $add_wapf( $p_weight, 2, [ 'field_selw' => 'light' ] );
$order_id = WC()->checkout()->create_order( [ 'payment_method' => 'bacs', 'billing_email' => 'commtax-wapf@example.test' ] );
$order = is_wp_error( $order_id ) ? null : wc_get_order( $order_id );
if ( $order ) {
	$created['orders'][] = $order->get_id();
	$items = array_values( $order->get_items() );
	$line = $items[0] ?? null;
	$check( 'wapf order line keeps field meta', $line instanceof WC_Order_Item_Product && '' !== (string) $line->get_meta( 'Packaging' ), wp_json_encode( array_keys( (array) ( $line ? $line->get_meta_data() : [] ) ) ) );
} else {
	$check( 'wapf order created', false );
}

// --- TAX reference proofs ----------------------------------------------------
WP_CLI::log( '-- tax: addon folded into product-price line tax --' );

// Priced select choice 'heavy' = fx 10 on a $100 taxable product.
$key = $add_wapf( $p_taxable, 1, [ 'field_selw' => 'heavy' ] );
$item = $item_of( $key );
$line_taxes = $item['line_tax_data']['total'] ?? [];
$check( 'wapf addon merges into line price 110', abs( (float) $item['data']->get_price( 'edit' ) - 110.0 ) < 0.001, 'got ' . $item['data']->get_price( 'edit' ) );
$check( 'wapf line tax = 10% of merged 110 => 11', abs( array_sum( $line_taxes ) - 11.0 ) < 0.01, wp_json_encode( $line_taxes ) );

$key = $add_wapf( $p_notax, 1, [ 'field_selw' => 'heavy' ] );
$item = $item_of( $key );
$check( 'non-taxable product: addon line tax 0', 0.0 === (float) ( $item['line_tax'] ?? 0 ), 'got ' . var_export( $item['line_tax'] ?? null, true ) );

// Reference display-side multiplier used by WAPF JS (data-tax / variation tax).
$taxable_product = wc_get_product( $p_taxable );
$check( 'wapf get_tax_multiplier = 1.1 for taxable product', abs( Helper::get_tax_multiplier( $taxable_product ) - 1.1 ) < 0.0001, 'got ' . Helper::get_tax_multiplier( $taxable_product ) );
$check( 'wapf maybe_add_tax converts addon display (10 -> 11 cart excl unchanged; shop excl)', abs( Helper::maybe_add_tax( $taxable_product, 10.0, 'shop' ) - 10.0 ) < 0.001, 'got ' . Helper::maybe_add_tax( $taxable_product, 10.0, 'shop' ) );
update_option( 'woocommerce_tax_display_shop', 'incl' );
$check( 'wapf maybe_add_tax shop incl converts addon 10 -> 11', abs( Helper::maybe_add_tax( $taxable_product, 10.0, 'shop' ) - 11.0 ) < 0.001, 'got ' . Helper::maybe_add_tax( $taxable_product, 10.0, 'shop' ) );
update_option( 'woocommerce_tax_display_shop', 'excl' );

// --- COUPON reference proofs -------------------------------------------------
WP_CLI::log( '-- coupon scope: wapf_excl_addons percent-only --' );
$coupon = new WC_Coupon();
$coupon->set_code( 'commtax-wapf-' . strtolower( wp_generate_password( 6, false, false ) ) );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( 10 );
$coupon->set_product_ids( [ $p_taxable ] );
$coupon->save();
$created['coupons'][] = $coupon->get_id();

$key = $add_wapf( $p_taxable, 1, [ 'field_selw' => 'heavy' ] );
WC()->cart->apply_coupon( $coupon->get_code() );
$check( 'default coupon discounts base+addon (11 = 10% of 110)', abs( (float) WC()->cart->get_discount_total() - 11.0 ) < 0.01, 'got ' . WC()->cart->get_discount_total() );

$coupon->update_meta_data( 'wapf_excl_addons', 'yes' );
$coupon->save();
WC()->cart->remove_coupon( $coupon->get_code() );
WC()->cart->apply_coupon( $coupon->get_code() );
$check( 'wapf_excl_addons=yes discounts base only (10)', abs( (float) WC()->cart->get_discount_total() - 10.0 ) < 0.01, 'got ' . WC()->cart->get_discount_total() );

if ( $failures > 0 ) {
	WP_CLI::error( "$failures reference check(s) failed." );
}
WP_CLI::success( 'WAPF reference checks passed: weight choice/[x]/[qty]/virtual/floor + order meta, tax merge + multiplier + maybe_add_tax, coupon scope default/excluded.' );
