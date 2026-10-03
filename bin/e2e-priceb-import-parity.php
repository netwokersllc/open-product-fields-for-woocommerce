<?php
/**
 * WAPF export → OPF WapfMapper import → live cart parity proof.
 *
 * Feeds a WAPF Extended 3.1.5-shaped field-group payload through the REAL
 * WapfMapper::map() importer (including the nr/nrq/char/charq pricing rows
 * added for this lane), saves the mapped OPF group, and proves the imported
 * configuration prices the cart identically to the native WAPF engine on an
 * equivalent product — the "legacy formula import" residual on
 * WAPF-PRICE-FORMULA plus import-path proof for the VALUE/CHARACTERS rows.
 *
 * Run with:
 *   OPF_PRICEB_E2E_ALLOW=1 OPF_PRICEB_OUT=/tmp/opf-lane-priceb-evidence \
 *   wp eval-file bin/e2e-priceb-import-parity.php --path=/tmp/opf-image-priceb-wp
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

// One WAPF-shaped export covering every priced mode the importer can express
// in a single group: choice-level fixed/qt/p/percent/fx and field-level
// nr/nrq/char/charq/fx (a [qty]-scaled formula exercising normalize_formula).
$wapf_export = [
	'fields' => [
		[
			'id' => 'wrap', 'type' => 'select', 'label' => 'Wrap', 'required' => false,
			'choices' => [
				[ 'slug' => 'plain',  'label' => 'Plain',  'pricing_type' => 'fixed',   'pricing_amount' => '5' ],
				[ 'slug' => 'gift',   'label' => 'Gift',   'pricing_type' => 'qt',      'pricing_amount' => '1.5' ],
				[ 'slug' => 'silk',   'label' => 'Silk',   'pricing_type' => 'p',       'pricing_amount' => '10' ],
				[ 'slug' => 'gold',   'label' => 'Gold',   'pricing_type' => 'percent', 'pricing_amount' => '15' ],
				[ 'slug' => 'custom', 'label' => 'Custom', 'pricing_type' => 'fx',      'pricing_amount' => '([price]/10)+(2*[qty])' ],
			],
		],
		[
			'id' => 'engrave', 'type' => 'text', 'label' => 'Engraving', 'required' => false,
			'pricing' => [ 'enabled' => 'true', 'type' => 'charq', 'amount' => '0.5' ],
		],
		[
			'id' => 'copies', 'type' => 'number', 'label' => 'Copies', 'required' => false,
			'pricing' => [ 'enabled' => 'true', 'type' => 'nrq', 'amount' => '2' ],
		],
		[
			'id' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => false,
			'pricing' => [ 'enabled' => 'true', 'type' => 'char', 'amount' => '0.25' ],
		],
		[
			'id' => 'weightkg', 'type' => 'number', 'label' => 'Weight', 'required' => false,
			'pricing' => [ 'enabled' => 'true', 'type' => 'nr', 'amount' => '1.5' ],
		],
		[
			'id' => 'service', 'type' => 'text', 'label' => 'Service fee', 'required' => false,
			'pricing' => [ 'enabled' => 'true', 'type' => 'fx', 'amount' => '(3*[qty])' ],
		],
	],
	'conditions' => [],
	'layout'     => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ],
	'variables'  => [],
];

// Scenario submissions exercised through both engines.
$scenarios = [
	[ 'id' => 'flat_gift',   'qty' => 1, 'choice' => 'plain',  'engrave' => '',      'copies' => '',  'note' => '',        'weightkg' => '',  'service' => '' ],
	[ 'id' => 'qt_gift',     'qty' => 3, 'choice' => 'gift',   'engrave' => 'Hi!',   'copies' => '4', 'note' => 'keep',    'weightkg' => '2', 'service' => 'x' ],
	[ 'id' => 'p_silk',      'qty' => 2, 'choice' => 'silk',   'engrave' => 'élève', 'copies' => '2', 'note' => 'a longer note', 'weightkg' => '1.5', 'service' => 'x' ],
	[ 'id' => 'percent_gold','qty' => 3, 'choice' => 'gold',   'engrave' => 'ok',    'copies' => '1', 'note' => 'n',       'weightkg' => '3', 'service' => 'x' ],
	[ 'id' => 'fx_custom',   'qty' => 4, 'choice' => 'custom', 'engrave' => 'AB',    'copies' => '2', 'note' => 'go',      'weightkg' => '0', 'service' => 'x' ],
];

$results = [];
$owned_products = [];
$owned_groups = [];
$post_before    = $_POST;
$request_before = $_REQUEST;
$admin_only_before = get_option( 'opf_admin_only', null );

$lines_snapshot = static function ( \WC_Cart $wc_cart, int $product_id ): array {
	$lines = [];
	foreach ( $wc_cart->get_cart() as $key => $item ) {
		if ( (int) ( $item['product_id'] ?? 0 ) !== $product_id ) {
			continue;
		}
		$lines[] = [
			'quantity'   => (int) $item['quantity'],
			'unit_price' => round( (float) $item['data']->get_price(), 4 ),
			'line_total' => round( (float) $item['line_total'], 4 ),
		];
	}
	usort( $lines, static fn( $a, $b ) => [ $a['quantity'], $a['unit_price'] ] <=> [ $b['quantity'], $b['unit_price'] ] );
	return $lines;
};

$reset = static function () use ( $cart ): void {
	$cart->empty_cart( true );
	$_POST = [];
	$_REQUEST = [];
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	wc_clear_notices();
};

try {
	update_option( 'opf_admin_only', 'no' );
	update_option( 'woocommerce_calc_taxes', 'no' );

	$wapf_product = new WC_Product_Simple();
	$wapf_product->set_name( 'PriceB import WAPF' );
	$wapf_product->set_regular_price( '10.00' );
	$wapf_product->set_status( 'publish' );
	$wapf_product->set_tax_status( 'none' );
	$wapf_pid = (int) $wapf_product->save();
	$owned_products[] = $wapf_pid;

	$opf_product = new WC_Product_Simple();
	$opf_product->set_name( 'PriceB import OPF' );
	$opf_product->set_regular_price( '10.00' );
	$opf_product->set_status( 'publish' );
	$opf_product->set_tax_status( 'none' );
	$opf_pid = (int) $opf_product->save();
	$owned_products[] = $opf_pid;

	// Native WAPF group on the WAPF product.
	$wapf_model = Field_Groups::raw_json_to_field_group( [
		'id' => 'p_' . $wapf_pid, 'type' => 'wapf_product',
		'fields' => array_map( static function ( array $field ): array {
			$field['conditionals'] = [];
			return $field;
		}, $wapf_export['fields'] ),
		'conditions' => [], 'layout' => $wapf_export['layout'], 'variables' => [],
	] );
	$assert( $wapf_model && 6 === count( $wapf_model->fields ), 'WAPF export model did not parse.' );
	update_post_meta( $wapf_pid, '_wapf_fieldgroup', $wapf_model->to_array() );

	// --- The real import path: WapfMapper::map() on the WAPF-shaped export.
	// Feed the mapper WAPF's own SERIALIZED shape (raw_json_to_field_group
	// ->to_array() — the same payload _wapf_fieldgroup post meta and the WAPF
	// group export carry), where choices live under options.choices.
	$serialized = $wapf_model->to_array();
	$mapped = WapfMapper::map( $serialized, [ 'attach_product_ids' => [ $opf_pid ] ] );
	$assert( is_array( $mapped ) && ! empty( $mapped['group']['fields'] ), 'WapfMapper returned no fields: ' . wp_json_encode( $mapped ) );
	$mapped_pricing = [];
	foreach ( $mapped['group']['fields'] as $field ) {
		$mapped_pricing[ (string) $field['id'] ] = [
			'field_pricing' => $field['pricing'],
			'choices'       => array_map( static fn( $c ) => [ 'slug' => $c['slug'] ?? '', 'pricing' => $c['pricing'] ?? null ], (array) ( $field['choices'] ?? [] ) ),
		];
	}
	// The mapper generates OPF ids (label-slugified: engrave→engraving,
	// weightkg→weight); resolve submissions via the preserved field label.
	$id_of = static function ( string $label ) use ( $mapped ): string {
		foreach ( $mapped['group']['fields'] as $field ) {
			if ( (string) ( $field['label'] ?? '' ) === $label ) {
				return (string) $field['id'];
			}
		}
		return $label;
	};

	$group_id = FieldGroups::save( 0, $mapped['group'], [ 'title' => 'PriceB imported group', 'status' => 'publish' ] );
	$assert( $group_id > 0, 'Could not save the imported OPF group.' );
	$owned_groups[] = $group_id;
	FieldGroups::flush_cache();

	foreach ( $scenarios as $scenario ) {
		$qty    = (int) $scenario['qty'];
		$choice = (string) $scenario['choice'];

		// --- WAPF leg ---
		$reset();
		$_REQUEST['wapf_field_groups'] = 'p_' . $wapf_pid;
		$_REQUEST['wapf'] = [ 'field_wrap' => $choice ];
		foreach ( [ 'engrave', 'copies', 'note', 'weightkg', 'service' ] as $fid ) {
			if ( '' !== (string) $scenario[ $fid ] ) {
				$_REQUEST['wapf'][ 'field_' . $fid ] = (string) $scenario[ $fid ];
			}
		}
		$key = $cart->add_to_cart( $wapf_pid, $qty );
		unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'] );
		$assert( false !== $key, 'WAPF add_to_cart rejected scenario ' . $scenario['id'] );
		$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
		$cart->calculate_totals();
		$wapf_lines = $lines_snapshot( $cart, $wapf_pid );

		// --- OPF leg on the imported group ---
		$reset();
		$values = [ $id_of( 'Wrap' ) => $choice ];
		foreach ( [ 'engrave' => 'Engraving', 'copies' => 'Copies', 'note' => 'Note', 'weightkg' => 'Weight', 'service' => 'Service fee' ] as $fid => $label ) {
			if ( '' !== (string) $scenario[ $fid ] ) {
				$values[ $id_of( $label ) ] = (string) $scenario[ $fid ];
			}
		}
		$_POST['opf'] = [ (string) $group_id => $values ];
		$_REQUEST['opf'] = $_POST['opf'];
		$key = $cart->add_to_cart( $opf_pid, $qty );
		unset( $_POST['opf'], $_REQUEST['opf'] );
		$assert( false !== $key, 'OPF add_to_cart rejected scenario ' . $scenario['id'] );
		$cart->calculate_totals();
		$opf_lines = $lines_snapshot( $cart, $opf_pid );

		$results[] = [
			'scenario' => $scenario['id'],
			'qty'      => $qty,
			'wapf'     => $wapf_lines,
			'opf'      => $opf_lines,
			'match'    => $wapf_lines === $opf_lines,
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
	$out . '/priceb-import-parity.json',
	wp_json_encode(
		[
			'generated'     => gmdate( 'c' ),
			'wapf_version'  => $wapf_version,
			'woocommerce'   => WC_VERSION,
			'php'           => PHP_VERSION,
			'mapper_notes'  => $mapped['notes'] ?? [],
			'needs_review'  => $mapped['needs_review'] ?? null,
			'mapped_pricing' => $mapped_pricing ?? null,
			'scenarios'     => $results,
			'mismatches'    => $mismatches,
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	)
);

$assert( $cart->is_empty(), 'Cleanup left cart fixtures behind.' );
foreach ( array_merge( $owned_products, $owned_groups ) as $owned_id ) {
	$assert( ! get_post( $owned_id ), 'Cleanup left fixtures behind: ' . $owned_id );
}
$assert( [] === $mismatches, 'Import parity mismatches: ' . implode( ', ', $mismatches ) );

WP_CLI::success( sprintf( 'Import parity passed: %d scenarios through WapfMapper::map() + live cart; imported OPF group matches native WAPF Extended 3.1.5 exactly. Evidence: %s/priceb-import-parity.json', count( $results ), $out ) );
