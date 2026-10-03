<?php
/**
 * Disposable OPF ↔ WAPF Extended 3.1.5 quantity-semantics parity matrix.
 *
 * Run with:
 *   OPF_QTYSEM_E2E_ALLOW=1 OPF_QTYSEM_OUT=/tmp/opf-lane-qtysem-evidence \
 *   wp eval-file bin/e2e-qtysem-parity.php --path=/tmp/opf-image-qtysem-wp
 *
 * For every matrix case the script creates one disposable product carrying
 * BOTH an OPF field group (rules target the product) and a native WAPF
 * `_wapf_fieldgroup` post meta group. It then submits equivalent values
 * through each engine's real add-to-cart path (OPF: $_POST['opf']; WAPF:
 * $_REQUEST['wapf'] + wapf_field_groups), lets each engine split/price cart
 * lines natively, builds an order through woocommerce_checkout_create_order_
 * line_item, and compares cart unit prices, per-line totals and order totals
 * at line quantities 1 and 3.
 *
 * Never run outside the guarded disposable clone.
 */

defined( 'ABSPATH' ) || exit;

use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;
use SW_WAPF_PRO\Includes\Classes\Field_Groups;

if ( '1' !== getenv( 'OPF_QTYSEM_E2E_ALLOW' ) || realpath( ABSPATH ) !== '/tmp/opf-image-qtysem-wp' ) {
	throw new RuntimeException( 'Set OPF_QTYSEM_E2E_ALLOW=1 only on the disposable /tmp/opf-image-qtysem-wp clone.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( CartIntegration::class ) || ! class_exists( Field_Groups::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce, OPF, and WAPF Extended before running this proof.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$wapf_version = (string) ( get_plugin_data( WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' )['Version'] ?? '' );
if ( '3.1.5' !== $wapf_version ) {
	throw new RuntimeException( 'WAPF Extended 3.1.5 required, found ' . $wapf_version );
}
$out = getenv( 'OPF_QTYSEM_OUT' );
if ( ! $out || ! is_dir( $out ) || ! is_writable( $out ) ) {
	throw new RuntimeException( 'Set OPF_QTYSEM_OUT to a writable evidence directory.' );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$cart = WC()->cart;
$assert( null !== $cart && $cart->is_empty(), 'Disposable clone must have an empty cart before this proof.' );

// ---------------------------------------------------------------- case set --
// OPF normalized pricing: fixed/percent/formula + per_unit. WAPF pricing_type:
// fixed (flat/line), qt (per-unit flat), p (flat %/line), percent (%/unit),
// fx (line-space formula), nr/nrq/char/charq (value-driven; image-swatch-qty
// and text/number only). Choice-level pricing lives on each choice; nr/char
// live at field level. qty_based = WAPF clone type qty = OPF repeat.mode
// quantity (both engines split the line into identical-unit lines).
$plain = [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
$cases = [
	// --- normal field, choice-level pricing ---
	[ 'id' => 'fixed', 'wapf' => 'fixed', 'amount' => 5, 'opf' => [ 'type' => 'fixed', 'amount' => 5, 'per_unit' => false ] ],
	[ 'id' => 'qt', 'wapf' => 'qt', 'amount' => 5, 'opf' => [ 'type' => 'fixed', 'amount' => 5, 'per_unit' => true ] ],
	[ 'id' => 'p', 'wapf' => 'p', 'amount' => 20, 'opf' => [ 'type' => 'percent', 'amount' => 20, 'per_unit' => false ] ],
	[ 'id' => 'percent', 'wapf' => 'percent', 'amount' => 20, 'opf' => [ 'type' => 'percent', 'amount' => 20, 'per_unit' => true ] ],
	// fx with an author-visible [qty] factor: WAPF evaluates at line qty and
	// divides for the per-unit price; OPF must NOT re-apply per_unit (this
	// was the pre-fix qty>1 divergence).
	[ 'id' => 'fx_qty', 'wapf' => 'fx', 'amount' => '(2*[qty])', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '2*[qty]', 'per_unit' => true ] ],
	// fx as the importer stores it: outer *[qty] stripped, per_unit=true,
	// formula_raw preserved — the normalized form of '(5*[qty])'.
	[ 'id' => 'fx_mapped', 'wapf' => 'fx', 'amount' => '(5*[qty])', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '5', 'formula_raw' => '5*[qty]', 'per_unit' => true ] ],
	// fx without a qty factor: flat per line on both engines.
	[ 'id' => 'fx_flat', 'wapf' => 'fx', 'amount' => '7', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '7', 'per_unit' => false ] ],
	// --- value-driven field-level pricing (nr/char consume the submitted
	// value; WAPF passes it to do_pricing as $v). OPF equivalent: [x] formula.
	[ 'id' => 'nr', 'wapf' => 'nr', 'amount' => 2, 'field' => 'text', 'value' => '4', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '[x]*2', 'per_unit' => false ] ],
	[ 'id' => 'nrq', 'wapf' => 'nrq', 'amount' => 2, 'field' => 'text', 'value' => '4', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '[x]*2', 'per_unit' => true ] ],
	[ 'id' => 'char', 'wapf' => 'char', 'amount' => 0.5, 'field' => 'text', 'value' => 'abcd', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => 'len([x])*0.5', 'per_unit' => false ] ],
	[ 'id' => 'charq', 'wapf' => 'charq', 'amount' => 0.5, 'field' => 'text', 'value' => 'abcd', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => 'len([x])*0.5', 'per_unit' => true ] ],
	// --- image-swatch-qty: the entered per-choice count is $v. fixed/qt ignore
	// it; nr/nrq consume it; OPF [x] formulas consume it identically.
	[ 'id' => 'img_fixed', 'kind' => 'image', 'wapf' => 'fixed', 'amount' => 3, 'opf' => [ 'type' => 'fixed', 'amount' => 3, 'per_unit' => false ] ],
	[ 'id' => 'img_qt', 'kind' => 'image', 'wapf' => 'qt', 'amount' => 3, 'opf' => [ 'type' => 'fixed', 'amount' => 3, 'per_unit' => true ] ],
	[ 'id' => 'img_nr', 'kind' => 'image', 'wapf' => 'nr', 'amount' => 2, 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '[x]*2', 'per_unit' => false ] ],
	[ 'id' => 'img_nrq', 'kind' => 'image', 'wapf' => 'nrq', 'amount' => 2, 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '[x]*2', 'per_unit' => true ] ],
	// --- quantity-based clones (WAPF clone type qty / OPF repeat.mode
	// quantity): per-unit values split the cart line.
	[ 'id' => 'qb_fixed', 'kind' => 'qb', 'wapf' => 'fixed', 'amount' => 5, 'opf' => [ 'type' => 'fixed', 'amount' => 5, 'per_unit' => false ], 'rows' => [ 'a', 'a', 'a' ] ],
	[ 'id' => 'qb_qt', 'kind' => 'qb', 'wapf' => 'qt', 'amount' => 5, 'opf' => [ 'type' => 'fixed', 'amount' => 5, 'per_unit' => true ], 'rows' => [ 'a', 'a', 'a' ] ],
	[ 'id' => 'qb_p', 'kind' => 'qb', 'wapf' => 'p', 'amount' => 20, 'opf' => [ 'type' => 'percent', 'amount' => 20, 'per_unit' => false ], 'rows' => [ 'a', 'a', 'a' ] ],
	[ 'id' => 'qb_percent', 'kind' => 'qb', 'wapf' => 'percent', 'amount' => 20, 'opf' => [ 'type' => 'percent', 'amount' => 20, 'per_unit' => true ], 'rows' => [ 'a', 'a', 'a' ] ],
	// Mixed clone values: units group by identical value (a,a,b → 2-line cart).
	[ 'id' => 'qb_mixed_fixed', 'kind' => 'qb', 'wapf' => 'fixed', 'amount' => 5, 'amount_b' => 8, 'opf' => [ 'type' => 'fixed', 'amount' => 5, 'per_unit' => false ], 'opf_b' => [ 'type' => 'fixed', 'amount' => 8, 'per_unit' => false ], 'rows' => [ 'a', 'a', 'b' ] ],
	[ 'id' => 'qb_mixed_qt', 'kind' => 'qb', 'wapf' => 'qt', 'amount' => 5, 'amount_b' => 8, 'opf' => [ 'type' => 'fixed', 'amount' => 5, 'per_unit' => true ], 'opf_b' => [ 'type' => 'fixed', 'amount' => 8, 'per_unit' => true ], 'rows' => [ 'a', 'a', 'b' ] ],
	// qty-based fx: verbatim [qty] expression → result, never re-scaled.
	[ 'id' => 'qb_fx', 'kind' => 'qb_field', 'wapf' => 'fx', 'amount' => '(2*[qty])', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '2*[qty]', 'per_unit' => true ], 'rows' => [ 'x', 'x', 'x' ] ],
	// fx on a normal field consuming an image quantity via sumQty.
	[ 'id' => 'img_sumqty', 'kind' => 'image_sumqty' ],
];

// ------------------------------------------------------------- assertions --
$results = [];
$owned_products = [];
$owned_groups = [];
$admin_only_before = get_option( 'opf_admin_only', null );
$taxes_before = get_option( 'woocommerce_calc_taxes', null );
$post_before = $_POST;
$request_before = $_REQUEST;

/** Capture sorted per-line (quantity, unit price, line total) tuples. */
$lines_snapshot = static function ( \WC_Cart $wc_cart, int $product_id ): array {
	$lines = [];
	foreach ( $wc_cart->get_cart() as $key => $item ) {
		if ( (int) ( $item['product_id'] ?? 0 ) !== $product_id ) {
			continue;
		}
		$lines[] = [
			'key'        => (string) $key,
			'quantity'   => (int) $item['quantity'],
			'unit_price' => round( (float) $item['data']->get_price(), 4 ),
			'line_total' => round( (float) $item['line_total'], 4 ),
			'item'       => $item,
		];
	}
	usort( $lines, static fn( $a, $b ) => [ $a['quantity'], $a['unit_price'], $a['line_total'] ] <=> [ $b['quantity'], $b['unit_price'], $b['line_total'] ] );
	return $lines;
};

/** Turn captured cart lines into an order and snapshot its persisted state. */
$order_snapshot = static function ( \WC_Cart $wc_cart, array $lines ) use ( $assert ): array {
	$order = wc_create_order();
	$assert( ! is_wp_error( $order ), 'Could not create the disposable order.' );
	$order_lines = [];
	foreach ( $lines as $line ) {
		$cart_item = $line['item'];
		$line_id   = $order->add_product( $cart_item['data'], (int) $line['quantity'] );
		$order_line = $order->get_item( $line_id );
		do_action( 'woocommerce_checkout_create_order_line_item', $order_line, (string) $line['key'], $cart_item, $order );
		$order_line->save();
	}
	$order->calculate_totals();
	$order->save();
	foreach ( $order->get_items() as $order_line ) {
		$fresh = new WC_Order_Item_Product( $order_line->get_id() );
		$meta = [];
		foreach ( [ 'opt', 'prints', 'x', 'fee', 'Prints', 'Opt', 'X' ] as $label ) {
			$visible = $fresh->get_meta( $label, true );
			if ( '' !== $visible && null !== $visible ) {
				$meta[ $label ] = $visible;
			}
		}
		$order_lines[] = [
			'quantity' => (int) $fresh->get_quantity(),
			'total'    => round( (float) $fresh->get_total(), 4 ),
			'meta'     => $meta,
			'has_opf'  => '' !== (string) $fresh->get_meta( '_opf_fields', true ),
			'has_wapf' => ! empty( $fresh->get_meta( '_wapf_meta', true ) ),
		];
	}
	$total = (float) $order->get_total();
	$order_id = $order->get_id();
	$order->delete( true );
	$assert( ! wc_get_order( $order_id ), 'Order cleanup failed.' );
	usort( $order_lines, static fn( $a, $b ) => [ $a['quantity'], $a['total'] ] <=> [ $b['quantity'], $b['total'] ] );
	return [ 'total' => round( $total, 4 ), 'lines' => $order_lines ];
};

/** Run one WAPF leg: native request shape, native splitting/pricing. */
$wapf_leg = static function ( int $product_id, array $values, int $quantity ) use ( $cart, $lines_snapshot, $order_snapshot, $assert ): array {
	$cart->empty_cart( true );
	$_REQUEST['wapf_field_groups'] = 'p_' . $product_id;
	$_REQUEST['wapf'] = $values;
	$key = $cart->add_to_cart( $product_id, $quantity );
	unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'] );
	$assert( false !== $key, 'WAPF add_to_cart rejected the values.' );
	// WAPF only reprices on the first woocommerce_before_calculate_totals.
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	$cart->calculate_totals();
	$lines = $lines_snapshot( $cart, $product_id );
	$calc_prices = [];
	foreach ( $cart->get_cart() as $item ) {
		foreach ( (array) ( $item['wapf'] ?? [] ) as $wapf_field ) {
			foreach ( (array) ( $wapf_field['values'] ?? [] ) as $value ) {
				if ( isset( $value['calc_price'] ) ) {
					$calc_prices[] = round( (float) $value['calc_price'], 4 );
				}
			}
		}
	}
	$order = $order_snapshot( $cart, $lines );
	$cart->empty_cart( true );
	return [
		'lines'       => array_map( static fn( $l ) => [ 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'line_total' => $l['line_total'] ], $lines ),
		'calc_prices' => $calc_prices,
		'cart_total'  => round( array_sum( array_column( $lines, 'line_total' ) ), 4 ),
		'order'       => $order,
	];
};

/** Run one OPF leg: $_POST['opf'], native splitting/pricing. */
$opf_leg = static function ( int $product_id, int $group_id, array $values, int $quantity ) use ( $cart, $lines_snapshot, $order_snapshot, $assert ): array {
	$cart->empty_cart( true );
	$_POST['opf'] = [ (string) $group_id => $values ];
	$key = $cart->add_to_cart( $product_id, $quantity );
	unset( $_POST['opf'] );
	$assert( false !== $key, 'OPF add_to_cart rejected the values.' );
	$cart->calculate_totals();
	$lines = $lines_snapshot( $cart, $product_id );
	$order = $order_snapshot( $cart, $lines );
	$cart->empty_cart( true );
	return [
		'lines'      => array_map( static fn( $l ) => [ 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'line_total' => $l['line_total'] ], $lines ),
		'cart_total' => round( array_sum( array_column( $lines, 'line_total' ) ), 4 ),
		'order'      => $order,
	];
};

try {
	update_option( 'opf_admin_only', 'no' );
	update_option( 'woocommerce_calc_taxes', 'no' );

	foreach ( $cases as $case ) {
		$kind = $case['kind'] ?? 'choice';
		$product = new WC_Product_Simple();
		$product->set_name( 'OPF qtysem parity ' . $case['id'] );
		$product->set_regular_price( '10.00' );
		$product->set_status( 'publish' );
		$product_id = (int) $product->save();
		$assert( $product_id > 0, 'Could not create the disposable product.' );
		$owned_products[] = $product_id;

		$opf_fields = [];
		$wapf_fields = [];
		$opf_values = [];
		$wapf_values = [];

		if ( 'image' === $kind || 'image_sumqty' === $kind ) {
			$opf_pricing_a = $case['opf'] ?? $plain;
			$opf_pricing_b = $case['opf_b'] ?? $opf_pricing_a;
			$wapf_ptype_a = $case['wapf'] ?? 'none';
			$opf_fields[] = [
				'id' => 'prints', 'label' => 'Prints', 'type' => 'image_quantity',
				'min_choices' => 0, 'max_choices' => 99,
				'choices' => [
					[ 'slug' => 'oak', 'label' => 'Oak', 'quantity' => [ 'min' => 0, 'max' => 12 ], 'pricing' => $opf_pricing_a ],
					[ 'slug' => 'ash', 'label' => 'Ash', 'quantity' => [ 'min' => 0, 'max' => 12 ], 'pricing' => $opf_pricing_b ],
				],
			];
			$wapf_fields[] = [
				'id' => 'prints', 'label' => 'prints', 'type' => 'image-swatch-qty', 'required' => false,
				'choices' => [
					[ 'slug' => 'oak', 'label' => 'Oak', 'pricing_type' => $wapf_ptype_a, 'pricing_amount' => $case['amount'] ?? 0, 'options' => [ 'min' => '0', 'max' => '12' ] ],
					[ 'slug' => 'ash', 'label' => 'Ash', 'pricing_type' => $wapf_ptype_a, 'pricing_amount' => $case['amount_b'] ?? $case['amount'] ?? 0, 'options' => [ 'min' => '0', 'max' => '12' ] ],
				],
			];
			$opf_values['prints'] = [ 'oak' => '2', 'ash' => '3' ];
			$wapf_values = [ 'field_prints' => '1', 'field_prints_oak' => '2', 'field_prints_ash' => '3' ];
			if ( 'image_sumqty' === $kind ) {
				$opf_fields[] = [ 'id' => 'fee', 'label' => 'Fee', 'type' => 'select', 'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing' => [ 'type' => 'formula', 'amount' => 0, 'formula' => 'sumQty(prints)*[qty]', 'per_unit' => true ] ] ] ];
				$wapf_fields[] = [ 'id' => 'fee', 'label' => 'fee', 'type' => 'select', 'required' => true, 'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing_type' => 'fx', 'pricing_amount' => '(sumQty(prints)*[qty])' ] ] ];
				$opf_values['fee'] = 'a';
				$wapf_values['field_fee'] = 'a';
			}
		} elseif ( 'qb' === $kind || 'qb_field' === $kind ) {
			// Quantity-based clone field: per-unit row values.
			$opf_b = $case['opf_b'] ?? $case['opf'];
			$is_field_level = 'qb_field' === $kind;
			$opf_field = [
				'id' => 'x', 'label' => 'X', 'type' => 'select',
				'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ],
				'choices' => [
					[ 'slug' => 'a', 'label' => 'A', 'pricing' => $case['opf'] ],
					[ 'slug' => 'b', 'label' => 'B', 'pricing' => $opf_b ],
				],
			];
			$wapf_field = [
				'id' => 'x', 'label' => 'x', 'type' => 'select', 'required' => false,
				'clone' => [ 'enabled' => 'true', 'type' => 'qty' ],
				'choices' => [
					[ 'slug' => 'a', 'label' => 'A', 'pricing_type' => $case['wapf'], 'pricing_amount' => $case['amount'] ],
					[ 'slug' => 'b', 'label' => 'B', 'pricing_type' => $case['wapf'], 'pricing_amount' => $case['amount_b'] ?? $case['amount'] ],
				],
			];
			if ( $is_field_level ) {
				$opf_field['type'] = 'text';
				unset( $opf_field['choices'] );
				$opf_field['pricing'] = $case['opf'];
				$wapf_field['type'] = 'text';
				unset( $wapf_field['choices'] );
				$wapf_field['pricing'] = [ 'enabled' => 'true', 'type' => $case['wapf'], 'amount' => $case['amount'] ];
			}
			$opf_fields[] = $opf_field;
			$wapf_fields[] = $wapf_field;
		} elseif ( 'text' === ( $case['field'] ?? '' ) ) {
			// Field-level nr/char-style pricing on a text field.
			$opf_fields[] = [ 'id' => 'x', 'label' => 'X', 'type' => 'text', 'pricing' => $case['opf'] ];
			$wapf_fields[] = [ 'id' => 'x', 'label' => 'x', 'type' => 'text', 'required' => false, 'pricing' => [ 'enabled' => 'true', 'type' => $case['wapf'], 'amount' => (string) $case['amount'] ] ];
			$opf_values['x'] = $case['value'];
			$wapf_values['field_x'] = $case['value'];
		} else {
			// Normal select field with choice-level pricing.
			$opf_fields[] = [
				'id' => 'opt', 'label' => 'Opt', 'type' => 'select',
				'choices' => [
					[ 'slug' => 'a', 'label' => 'A', 'pricing' => $case['opf'] ],
				],
			];
			$wapf_fields[] = [
				'id' => 'opt', 'label' => 'opt', 'type' => 'select', 'required' => false,
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing_type' => $case['wapf'], 'pricing_amount' => $case['amount'] ] ],
			];
			$opf_values['opt'] = 'a';
			$wapf_values['field_opt'] = 'a';
		}

		$group_id = FieldGroups::save( 0, [
			'fields' => $opf_fields,
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
		], [ 'title' => 'OPF qtysem parity ' . $case['id'], 'status' => 'publish' ] );
		$assert( $group_id > 0, 'Could not create the disposable OPF group.' );
		$owned_groups[] = $group_id;
		FieldGroups::flush_cache();

		foreach ( $wapf_fields as &$wapf_field ) {
			$wapf_field['conditionals'] = $wapf_field['conditionals'] ?? [];
		}
		unset( $wapf_field );
		$wapf_model = Field_Groups::raw_json_to_field_group( [
			'id' => 'p_' . $product_id, 'type' => 'wapf_product',
			'fields' => $wapf_fields,
			'conditions' => [], 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ], 'variables' => [],
		] );
		update_post_meta( $product_id, '_wapf_fieldgroup', $wapf_model->to_array() );

		$case_rows = [];
		foreach ( [ 1, 3 ] as $qty ) {
			if ( 'qb' === $kind || 'qb_field' === $kind ) {
				$rows = array_slice( $case['rows'], 0, $qty );
				$opf_submit = [ 'x' => array_values( $rows ) ];
				$wapf_submit = [];
				foreach ( $rows as $i => $row ) {
					$wapf_submit[ 0 === $i ? 'field_x' : 'field_x_clone_' . ( $i + 1 ) ] = $row;
				}
			} else {
				$opf_submit = $opf_values;
				$wapf_submit = $wapf_values;
			}
			$wapf_observed = $wapf_leg( $product_id, $wapf_submit, $qty );
			$opf_observed = $opf_leg( $product_id, $group_id, $opf_submit, $qty );
			$match = $wapf_observed['lines'] === $opf_observed['lines']
				&& abs( $wapf_observed['cart_total'] - $opf_observed['cart_total'] ) < 0.005
				&& abs( $wapf_observed['order']['total'] - $opf_observed['order']['total'] ) < 0.005
				&& array_map( static fn( $l ) => [ 'quantity' => $l['quantity'], 'total' => $l['total'] ], $wapf_observed['order']['lines'] )
					=== array_map( static fn( $l ) => [ 'quantity' => $l['quantity'], 'total' => $l['total'] ], $opf_observed['order']['lines'] );
			$case_rows[] = [
				'qty'        => $qty,
				'wapf'       => $wapf_observed,
				'opf'        => $opf_observed,
				'match'      => $match,
			];
		}
		$results[] = [ 'case' => $case['id'], 'wapf_type' => $case['wapf'] ?? 'sumQty', 'rows' => $case_rows ];
	}
} finally {
	$_POST = $post_before;
	$_REQUEST = $request_before;
	$cart->empty_cart( true );
	wc_clear_notices();
	foreach ( array_reverse( $owned_groups ) as $group_id ) {
		wp_delete_post( $group_id, true );
	}
	foreach ( array_reverse( $owned_products ) as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$product->delete( true );
		}
	}
	if ( null === $admin_only_before ) {
		delete_option( 'opf_admin_only' );
	} else {
		update_option( 'opf_admin_only', $admin_only_before );
	}
	if ( null === $taxes_before ) {
		delete_option( 'woocommerce_calc_taxes' );
	} else {
		update_option( 'woocommerce_calc_taxes', $taxes_before );
	}
	FieldGroups::flush_cache();
}

$mismatches = [];
foreach ( $results as $case_result ) {
	foreach ( $case_result['rows'] as $row ) {
		if ( ! $row['match'] ) {
			$mismatches[] = $case_result['case'] . ' @ qty ' . $row['qty'];
		}
	}
}

file_put_contents(
	$out . '/qtysem-parity-matrix.json',
	wp_json_encode(
		[
			'generated'    => gmdate( 'c' ),
			'wapf_version' => $wapf_version,
			'woocommerce'  => WC_VERSION,
			'php'          => PHP_VERSION,
			'base_price'   => 10.0,
			'cases'        => $results,
			'mismatches'   => $mismatches,
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	)
);

$assert( $cart->is_empty(), 'Cleanup left cart fixtures behind.' );
foreach ( array_merge( $owned_products, $owned_groups ) as $owned_id ) {
	$assert( ! get_post( $owned_id ), 'Cleanup left product/group fixtures behind: ' . $owned_id );
}
$assert( [] === $mismatches, 'OPF/WAPF parity mismatches: ' . implode( ', ', $mismatches ) );

WP_CLI::success( sprintf( 'Quantity-semantics parity matrix passed: %d cases x 2 quantities; cart unit prices, line totals and order totals identical between OPF and WAPF Extended 3.1.5; fixtures cleaned. Evidence: %s/qtysem-parity-matrix.json', count( $results ), $out ) );
