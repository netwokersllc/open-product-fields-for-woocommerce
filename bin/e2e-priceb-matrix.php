<?php
/**
 * WAPF-PRICE-MATRIX proof: lookuptable() runtime parity + import remap.
 *
 * WAPF Extended 3.1.5 defines lookup tables ONLY via the `wapf/lookup_tables`
 * PHP filter (class-product-controller.php:991 injects them to JS; the
 * lookuptable() formula function reads the same filter). OPF mirrors the
 * contract: Calculator falls back `opf_lookup_tables` → `wapf/lookup_tables`,
 * and the frontend reads window.wapf_lookup_tables — so a site migrating an
 * existing WAPF lookup snippet keeps working verbatim. This script registers
 * one such migration snippet and proves the native WAPF engine and the
 * mapper-imported OPF group price identical carts.
 *
 * Run with:
 *   OPF_PRICEB_E2E_ALLOW=1 OPF_PRICEB_OUT=/tmp/opf-lane-priceb-evidence \
 *   wp eval-file bin/e2e-priceb-matrix.php --path=/tmp/opf-image-priceb-wp
 */

defined( 'ABSPATH' ) || exit;

use OPF\Engine\WapfMapper;
use OPF\Service\FieldGroups;
use SW_WAPF_PRO\Includes\Classes\Field_Groups;

if ( '1' !== getenv( 'OPF_PRICEB_E2E_ALLOW' ) || realpath( ABSPATH ) !== '/tmp/opf-image-priceb-wp' ) {
	throw new RuntimeException( 'Set OPF_PRICEB_E2E_ALLOW=1 only on the disposable /tmp/opf-image-priceb-wp clone.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( FieldGroups::class ) || ! class_exists( Field_Groups::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce, OPF, and WAPF Extended before running this proof.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$wapf_version = (string) ( get_plugin_data( WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' )['Version'] ?? '' );
if ( '3.1.5' !== $wapf_version ) {
	throw new RuntimeException( 'WAPF Extended 3.1.5 required, found ' . $wapf_version );
}
$out = getenv( 'OPF_PRICEB_OUT' );
if ( ! $out || ! is_dir( $out ) || ! is_writable( $out ) ) {
	throw new RuntimeException( 'Set OPF_PRICEB_OUT to a writable evidence directory.' );
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$cart = WC()->cart;
$assert( null !== $cart && $cart->is_empty(), 'Disposable clone must have an empty cart.' );

// Migration-style lookup table snippet — the exact filter WAPF reads.
// Two numeric axes; leaf values are the per-line addon amount.
$lookup_tables = [
	'cutting' => [
		'10' => [ '5' => 100, '12' => 150 ],
		'15' => [ '5' => 200, '12' => 300 ],
	],
];
add_filter( 'wapf/lookup_tables', static function () use ( $lookup_tables ) {
	return $lookup_tables;
} );

// WAPF-shaped group: two number dims + one text field priced by a 2-axis
// lookuptable formula. Dim args are field ids (>=6 chars → field resolution).
$wapf_export = [
	'fields' => [
		[ 'id' => 'widthf',  'type' => 'number', 'label' => 'Width',  'required' => true ],
		[ 'id' => 'heightf', 'type' => 'number', 'label' => 'Height', 'required' => true ],
		[
			'id' => 'quoted', 'type' => 'text', 'label' => 'Quote', 'required' => false,
			'pricing' => [ 'enabled' => 'true', 'type' => 'fx', 'amount' => 'lookuptable(cutting;widthf;heightf)' ],
		],
	],
	'conditions' => [],
	'layout'     => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ],
	'variables'  => [],
];

// Dim values exercise exact hits, below-first clamp, between-keys round-up.
$scenarios = [
	[ 'id' => 'exact',       'qty' => 1, 'width' => '10', 'height' => '5',  'quote' => 'x' ],
	[ 'id' => 'round_up',    'qty' => 2, 'width' => '13', 'height' => '7',  'quote' => 'x' ],
	[ 'id' => 'below_first', 'qty' => 1, 'width' => '2',  'height' => '1',  'quote' => 'x' ],
	[ 'id' => 'qty_scale',   'qty' => 3, 'width' => '15', 'height' => '12', 'quote' => 'x' ],
];
// Beyond-last axes: WAPF reads an undefined index inside find_nearest (null
// → mid-chain TypeError on PHP 8) where OPF fails closed to 0 — observed,
// documented divergence rather than a strict-match assertion.
$observed = [
	[ 'id' => 'beyond_last', 'qty' => 1, 'width' => '99', 'height' => '99', 'quote' => 'x' ],
];

$results = [];
$observed_results = [];
$owned_products = [];
$owned_groups = [];
$post_before    = $_POST;
$request_before = $_REQUEST;
$admin_only_before = get_option( 'opf_admin_only', null );

$lines_snapshot = static function ( \WC_Cart $wc_cart, int $product_id ): array {
	$lines = [];
	foreach ( $wc_cart->get_cart() as $item ) {
		if ( (int) ( $item['product_id'] ?? 0 ) !== $product_id ) {
			continue;
		}
		$lines[] = [
			'quantity'   => (int) $item['quantity'],
			'unit_price' => round( (float) $item['data']->get_price(), 4 ),
			'line_total' => round( (float) $item['line_total'], 4 ),
		];
	}
	return $lines;
};

$reset = static function () use ( $cart ): void {
	$cart->empty_cart( true );
	$_POST = [];
	$_REQUEST = [];
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	wc_clear_notices();
};

$run_pair = static function ( array $scenario ) use (
	$cart, $reset, $lines_snapshot, &$wapf_pid, &$opf_pid, &$group_id, &$label_to_mapped
) {
	$qty = (int) $scenario['qty'];

	$reset();
	$_REQUEST['wapf_field_groups'] = 'p_' . $wapf_pid;
	$_REQUEST['wapf'] = [
		'field_widthf'  => (string) $scenario['width'],
		'field_heightf' => (string) $scenario['height'],
		'field_quoted'  => (string) $scenario['quote'],
	];
	try {
		$key = $cart->add_to_cart( $wapf_pid, $qty );
	} catch ( \Throwable $e ) {
		$key = 'engine-error: ' . get_class( $e ) . ' ' . $e->getMessage();
	}
	unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'] );
	if ( false === $key ) {
		return [ 'wapf' => 'rejected', 'opf' => null, 'match' => false ];
	}
	if ( ! is_string( $key ) ) {
		// Beyond-last axes fatal inside add_to_cart's price hook on WAPF —
		// record the observed engine error and skip the OPF leg pairing.
		return [ 'wapf' => $key, 'opf' => null, 'match' => false ];
	}
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	try {
		$cart->calculate_totals();
		$wapf_lines = $lines_snapshot( $cart, $wapf_pid );
	} catch ( \Throwable $e ) {
		$wapf_lines = 'engine-error: ' . get_class( $e ) . ' ' . $e->getMessage();
	}

	$reset();
	$values = [
		$label_to_mapped['Width']  => (string) $scenario['width'],
		$label_to_mapped['Height'] => (string) $scenario['height'],
		$label_to_mapped['Quote']  => (string) $scenario['quote'],
	];
	$_POST['opf'] = [ (string) $group_id => $values ];
	$_REQUEST['opf'] = $_POST['opf'];
	try {
		$key = $cart->add_to_cart( $opf_pid, $qty );
	} catch ( \Throwable $e ) {
		$key = 'engine-error: ' . get_class( $e ) . ' ' . $e->getMessage();
	}
	unset( $_POST['opf'], $_REQUEST['opf'] );
	if ( false === $key ) {
		return [ 'wapf' => $wapf_lines, 'opf' => 'rejected', 'match' => false ];
	}
	if ( ! is_string( $key ) ) {
		return [ 'wapf' => $wapf_lines, 'opf' => $key, 'match' => false ];
	}
	try {
		$cart->calculate_totals();
		$opf_lines = $lines_snapshot( $cart, $opf_pid );
	} catch ( \Throwable $e ) {
		$opf_lines = 'engine-error: ' . get_class( $e ) . ' ' . $e->getMessage();
	}

	return [ 'wapf' => $wapf_lines, 'opf' => $opf_lines, 'match' => $wapf_lines === $opf_lines ];
};

try {
	update_option( 'opf_admin_only', 'no' );
	update_option( 'woocommerce_calc_taxes', 'no' );

	$wapf_product = new WC_Product_Simple();
	$wapf_product->set_name( 'PriceB matrix WAPF' );
	$wapf_product->set_regular_price( '10.00' );
	$wapf_product->set_status( 'publish' );
	$wapf_product->set_tax_status( 'none' );
	$wapf_pid = (int) $wapf_product->save();
	$owned_products[] = $wapf_pid;

	$opf_product = new WC_Product_Simple();
	$opf_product->set_name( 'PriceB matrix OPF' );
	$opf_product->set_regular_price( '10.00' );
	$opf_product->set_status( 'publish' );
	$opf_product->set_tax_status( 'none' );
	$opf_pid = (int) $opf_product->save();
	$owned_products[] = $opf_pid;

	$wapf_model = Field_Groups::raw_json_to_field_group( [
		'id' => 'p_' . $wapf_pid, 'type' => 'wapf_product',
		'fields' => array_map( static function ( array $field ): array {
			$field['conditionals'] = [];
			return $field;
		}, $wapf_export['fields'] ),
		'conditions' => [], 'layout' => $wapf_export['layout'], 'variables' => [],
	] );
	$assert( $wapf_model && 3 === count( $wapf_model->fields ), 'WAPF matrix model did not parse.' );
	update_post_meta( $wapf_pid, '_wapf_fieldgroup', $wapf_model->to_array() );

	$serialized = $wapf_model->to_array();
	$mapped     = WapfMapper::map( $serialized, [ 'attach_product_ids' => [ $opf_pid ] ] );
	$assert( is_array( $mapped ) && ! empty( $mapped['group']['fields'] ), 'WapfMapper returned no fields.' );
	$label_to_mapped = [];
	$mapped_quote_formula = null;
	foreach ( $mapped['group']['fields'] as $field ) {
		$label_to_mapped[ (string) $field['label'] ] = (string) $field['id'];
		if ( 'Quote' === (string) $field['label'] ) {
			$mapped_quote_formula = $field['pricing']['formula'] ?? null;
		}
	}
	// The mapper must have rewritten the lookuptable dim args to the new OPF
	// field ids and flagged the lookup-dependent formula for review.
	$assert( is_string( $mapped_quote_formula ) && false !== strpos( $mapped_quote_formula, 'lookuptable(cutting;' ), 'Mapped formula lost lookuptable: ' . var_export( $mapped_quote_formula, true ) );
	$assert( true === ( $mapped['needs_review'] ?? false ), 'Mapper did not flag lookuptable for review.' );

	$group_id = FieldGroups::save( 0, $mapped['group'], [ 'title' => 'PriceB matrix group', 'status' => 'publish' ] );
	$assert( $group_id > 0, 'Could not save the matrix OPF group.' );
	$owned_groups[] = $group_id;
	FieldGroups::flush_cache();

	foreach ( $scenarios as $scenario ) {
		$r = $run_pair( $scenario );
		$results[] = [
			'scenario' => $scenario['id'],
			'qty'      => $scenario['qty'],
			'width'    => $scenario['width'],
			'height'   => $scenario['height'],
			'wapf'     => $r['wapf'],
			'opf'      => $r['opf'],
			'match'    => $r['match'],
		];
	}
	foreach ( $observed as $scenario ) {
		$r = $run_pair( $scenario );
		$observed_results[] = [
			'scenario' => $scenario['id'],
			'width'    => $scenario['width'],
			'height'   => $scenario['height'],
			'wapf'     => $r['wapf'],
			'opf'      => $r['opf'],
		];
	}
} finally {
	$_POST = $post_before;
	$_REQUEST = $request_before;
	$cart->empty_cart( true );
	wc_clear_notices();
	foreach ( array_reverse( $owned_groups ) as $gid ) {
		wp_delete_post( $gid, true );
	}
	foreach ( array_reverse( $owned_products ) as $pid ) {
		$product = wc_get_product( $pid );
		if ( $product ) {
			$product->delete( true );
		}
	}
	if ( null === $admin_only_before ) {
		delete_option( 'opf_admin_only' );
	} else {
		update_option( 'opf_admin_only', $admin_only_before );
	}
	FieldGroups::flush_cache();
}

$mismatches = array_values( array_map( static fn( $r ) => $r['scenario'], array_filter( $results, static fn( $r ) => ! $r['match'] ) ) );

file_put_contents(
	$out . '/priceb-matrix-parity.json',
	wp_json_encode(
		[
			'generated'       => gmdate( 'c' ),
			'wapf_version'    => $wapf_version,
			'woocommerce'     => WC_VERSION,
			'php'             => PHP_VERSION,
			'lookup_tables'   => $lookup_tables,
			'mapped_formula'  => $mapped_quote_formula,
			'mapper_notes'    => $mapped['notes'] ?? [],
			'needs_review'    => $mapped['needs_review'] ?? null,
			'scenarios'       => $results,
			'observed_beyond' => $observed_results,
			'mismatches'      => $mismatches,
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	)
);

$assert( $cart->is_empty(), 'Cleanup left cart fixtures behind.' );
foreach ( array_merge( $owned_products, $owned_groups ) as $owned_id ) {
	$assert( ! get_post( $owned_id ), 'Cleanup left fixtures behind: ' . $owned_id );
}
$assert( [] === $mismatches, 'Matrix parity mismatches: ' . implode( ', ', $mismatches ) );

WP_CLI::success( sprintf( 'Matrix parity passed: %d scenarios through WapfMapper::map() + live cart; lookuptable() identical between imported OPF group and native WAPF Extended 3.1.5 via wapf/lookup_tables filter. Evidence: %s/priceb-matrix-parity.json', count( $results ), $out ) );
