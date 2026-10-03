<?php
/**
 * Disposable OPF ↔ WAPF Extended 3.1.5 price-mode lifecycle matrix.
 *
 * Closes the residual gates on the priceb rows: real cart pricing with tax
 * and fractional rounding at q=1/q=3, classic + Store API add paths, checkout
 * order persistence (totals + engine meta), partial refund math, and real
 * order-again repopulation — compared verbatim between the native WAPF
 * Extended 3.1.5 engine and OPF.
 *
 * Each case builds TWO products (production shape — never both engines on one
 * product): one carrying a native WAPF `_wapf_fieldgroup`, one targeted by an
 * OPF field group. The Store API path exercises woocommerce_add_to_cart_
 * validation; WAPF rejects a shared product whose request lacks
 * `wapf_field_groups`, so engine isolation is required anyway.
 *
 * Covers WAPF-PRICE-FLAT, -PERCENT, -QUANTITY-PERCENT, -VALUE,
 * -QUANTITY-VALUE, -CHARACTERS, -QUANTITY-CHARACTERS, and -FORMULA residuals.
 *
 * Run with:
 *   OPF_PRICEB_E2E_ALLOW=1 OPF_PRICEB_OUT=/tmp/opf-lane-priceb-evidence \
 *   wp eval-file bin/e2e-priceb-lifecycle.php --path=/tmp/opf-image-priceb-wp
 *
 * Never run outside the guarded disposable clone.
 */

defined( 'ABSPATH' ) || exit;

use OPF\Service\FieldGroups;
use SW_WAPF_PRO\Includes\Classes\Field_Groups;

if ( '1' !== getenv( 'OPF_PRICEB_E2E_ALLOW' ) || realpath( ABSPATH ) !== '/tmp/opf-image-priceb-wp' ) {
	throw new RuntimeException( 'Set OPF_PRICEB_E2E_ALLOW=1 only on the disposable /tmp/opf-image-priceb-wp clone.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( FieldGroups::class ) || ! class_exists( Field_Groups::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce, OPF, and WAPF Extended before running this proof.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
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
$assert( null !== $cart && $cart->is_empty(), 'Disposable clone must have an empty cart before this proof.' );

// ---------------------------------------------------------------- case set --
$plain = [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
$cases = [
	// fixed $5 choice — flat per line (WAPF-PRICE-FLAT).
	[ 'id' => 'flat', 'wapf' => 'fixed', 'amount' => 5, 'opf' => [ 'type' => 'fixed', 'amount' => 5, 'per_unit' => false ] ],
	// fractional flat fee — cent rounding edge (0.335 × qty).
	[ 'id' => 'flat_frac', 'wapf' => 'fixed', 'amount' => 0.335, 'opf' => [ 'type' => 'fixed', 'amount' => 0.335, 'per_unit' => false ] ],
	// p = percent of base once per line (WAPF-PRICE-PERCENT).
	[ 'id' => 'p', 'wapf' => 'p', 'amount' => 20, 'opf' => [ 'type' => 'percent', 'amount' => 20, 'per_unit' => false ] ],
	// percent = percent of base per unit (WAPF-PRICE-QUANTITY-PERCENT).
	[ 'id' => 'percent', 'wapf' => 'percent', 'amount' => 20, 'opf' => [ 'type' => 'percent', 'amount' => 20, 'per_unit' => true ] ],
	// percent of the SALE base — sale-price edge.
	[ 'id' => 'p_sale', 'wapf' => 'p', 'amount' => 20, 'sale_price' => '8.00', 'opf' => [ 'type' => 'percent', 'amount' => 20, 'per_unit' => false ] ],
	[ 'id' => 'percent_sale', 'wapf' => 'percent', 'amount' => 33.33, 'sale_price' => '8.00', 'opf' => [ 'type' => 'percent', 'amount' => 33.33, 'per_unit' => true ] ],
	// nr = amount × numeric field value, flat (WAPF-PRICE-VALUE).
	[ 'id' => 'nr', 'wapf' => 'nr', 'amount' => 2, 'field' => 'number', 'value' => '4', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '[x]*(2)', 'per_unit' => false ] ],
	// nrq = amount × value × qty (WAPF-PRICE-QUANTITY-VALUE).
	[ 'id' => 'nrq', 'wapf' => 'nrq', 'amount' => 2, 'field' => 'number', 'value' => '4', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '[x]*(2)', 'per_unit' => true ] ],
	// numeric edges: fraction, negative, non-numeric (floatval semantics).
	[ 'id' => 'nr_dec', 'wapf' => 'nr', 'amount' => 1.5, 'field' => 'number', 'value' => '2.5', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '[x]*(1.5)', 'per_unit' => false ] ],
	[ 'id' => 'nr_neg', 'wapf' => 'nr', 'amount' => 2, 'field' => 'number', 'value' => '-3', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '[x]*(2)', 'per_unit' => false ] ],
	[ 'id' => 'nr_nan', 'wapf' => 'nr', 'amount' => 2, 'field' => 'text', 'value' => 'abc', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '[x]*(2)', 'per_unit' => false ] ],
	// char = amount × mb_strlen(value), flat (WAPF-PRICE-CHARACTERS).
	[ 'id' => 'char', 'wapf' => 'char', 'amount' => 0.5, 'field' => 'text', 'value' => 'héllo€', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => 'len([x])*(0.5)', 'per_unit' => false ] ],
	// charq = amount × mb_strlen × qty (WAPF-PRICE-QUANTITY-CHARACTERS).
	[ 'id' => 'charq', 'wapf' => 'charq', 'amount' => 0.5, 'field' => 'text', 'value' => 'héllo€', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => 'len([x])*(0.5)', 'per_unit' => true ] ],
	// Unicode edges: combining mark (NFD é = 2 code points), CJK.
	[ 'id' => 'char_combining', 'wapf' => 'char', 'amount' => 0.75, 'field' => 'text', 'value' => "e\u{0301}x\u{0308}你好", 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => 'len([x])*(0.75)', 'per_unit' => false ] ],
	// fx residuals: flat formula and [qty]-scaled formula in mapped +
	// verbatim forms (WAPF-PRICE-FORMULA).
	[ 'id' => 'fx_flat', 'wapf' => 'fx', 'amount' => '7', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '7', 'per_unit' => false ] ],
	[ 'id' => 'fx_qty', 'wapf' => 'fx', 'amount' => '(2*[qty])', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '2*[qty]', 'per_unit' => true ] ],
	[ 'id' => 'fx_mapped', 'wapf' => 'fx', 'amount' => '(5*[qty])', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '5', 'formula_raw' => '5*[qty]', 'per_unit' => true ] ],
	// signed formula discount — signed field_addon contract (1e74aa0).
	[ 'id' => 'fx_neg', 'wapf' => 'fx', 'amount' => '(-4)', 'opf' => [ 'type' => 'formula', 'amount' => 0, 'formula' => '-4', 'per_unit' => false ] ],
];

// ------------------------------------------------------------- assertions --
$results      = [];
$owned_products = [];
$owned_groups = [];
$owned_orders = [];
$tax_rate_id  = 0;
$created_user_id = 0;
$admin_only_before = get_option( 'opf_admin_only', null );
$option_names = [ 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart', 'woocommerce_tax_based_on' ];
$old_options  = [];
foreach ( $option_names as $name ) {
	$old_options[ $name ] = get_option( $name, '__missing__' );
}
$post_before    = $_POST;
$request_before = $_REQUEST;
$get_before     = $_GET;
$current_user   = wp_get_current_user();

/** Capture sorted per-line pricing tuples for one product. */
$lines_snapshot = static function ( \WC_Cart $wc_cart, int $product_id ): array {
	$lines = [];
	foreach ( $wc_cart->get_cart() as $key => $item ) {
		if ( (int) ( $item['product_id'] ?? 0 ) !== $product_id ) {
			continue;
		}
		$lines[] = [
			'key'              => (string) $key,
			'quantity'         => (int) $item['quantity'],
			'unit_price'       => round( (float) $item['data']->get_price(), 4 ),
			'line_subtotal'    => round( (float) $item['line_subtotal'], 4 ),
			'line_subtotal_tax' => round( (float) $item['line_subtotal_tax'], 4 ),
			'line_total'       => round( (float) $item['line_total'], 4 ),
			'line_tax'         => round( (float) $item['line_tax'], 4 ),
			'item'             => $item,
		];
	}
	usort( $lines, static fn( $a, $b ) => [ $a['quantity'], $a['unit_price'], $a['line_total'] ] <=> [ $b['quantity'], $b['unit_price'], $b['line_total'] ] );
	return $lines;
};

$public_line = static fn( array $line ): array => [
	'quantity'         => $line['quantity'],
	'unit_price'       => $line['unit_price'],
	'line_subtotal'    => $line['line_subtotal'],
	'line_subtotal_tax' => $line['line_subtotal_tax'],
	'line_total'       => $line['line_total'],
	'line_tax'         => $line['line_tax'],
];

$reset = static function () use ( $cart ): void {
	$cart->empty_cart( true );
	if ( WC()->session ) {
		WC()->session->set( 'cart', [] );
		WC()->session->set( 'cart_totals', null );
	}
	$_POST = [];
	$_REQUEST = [];
	// WAPF reprices only on the first before_calculate_totals firing.
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	wc_clear_notices();
};

/** Run one WAPF leg through the native classic path. */
$wapf_classic = static function ( int $product_id, array $values, int $quantity ) use ( $cart, $assert, $reset, $lines_snapshot ): array {
	$reset();
	$_REQUEST['wapf_field_groups'] = 'p_' . $product_id;
	$_REQUEST['wapf'] = $values;
	$key = $cart->add_to_cart( $product_id, $quantity );
	unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'] );
	$assert( false !== $key, 'WAPF add_to_cart rejected the values.' );
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	$cart->calculate_totals();
	return $lines_snapshot( $cart, $product_id );
};

/** Run one OPF leg through the native classic path. */
$opf_classic = static function ( int $product_id, int $group_id, array $values, int $quantity ) use ( $cart, $assert, $reset, $lines_snapshot ): array {
	$reset();
	$_POST['opf'] = [ (string) $group_id => $values ];
	$_REQUEST['opf'] = $_POST['opf'];
	$key = $cart->add_to_cart( $product_id, $quantity );
	unset( $_POST['opf'], $_REQUEST['opf'] );
	$assert( false !== $key, 'OPF add_to_cart rejected the values.' );
	$cart->calculate_totals();
	return $lines_snapshot( $cart, $product_id );
};

/** Run one leg through the Store API (exercises validation). */
$store_api = static function ( string $engine, int $product_id, array $extra, int $quantity ) use ( $cart, $assert, $reset, $lines_snapshot ): array {
	$reset();
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$request->set_param( 'id', $product_id );
	$request->set_param( 'quantity', $quantity );
	foreach ( $extra as $key => $value ) {
		$request->set_param( $key, $value );
		$_REQUEST[ $key ] = $value;
	}
	$response = rest_get_server()->dispatch( $request );
	foreach ( array_keys( $extra ) as $key ) {
		unset( $_REQUEST[ $key ] );
	}
	$assert( in_array( $response->get_status(), [ 200, 201 ], true ), "$engine Store API add failed at qty=$quantity: " . wp_json_encode( $response->get_data() ) );
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	$cart->calculate_totals();
	return $lines_snapshot( $cart, $product_id );
};

/** Checkout the current cart into a real order; snapshot its persisted state. */
$checkout_order = static function ( int $user_id ) use ( $assert, &$owned_orders ): array {
	$order_id = WC()->checkout()->create_order( [
		'billing_email'      => 'priceb-e2e@example.test',
		'billing_first_name' => 'PriceB',
		'billing_last_name'  => 'E2E',
		'payment_method'     => 'bacs',
	] );
	$assert( ! is_wp_error( $order_id ) && $order_id > 0, 'Checkout order creation failed: ' . ( is_wp_error( $order_id ) ? $order_id->get_error_message() : 'no id' ) );
	$owned_orders[] = (int) $order_id;
	$order = wc_get_order( $order_id );
	$order->set_customer_id( $user_id );
	$order->save();
	$items = [];
	foreach ( $order->get_items() as $item_id => $item ) {
		$items[] = [
			'item_id'   => (int) $item_id,
			'quantity'  => (int) $item->get_quantity(),
			'total'     => round( (float) $item->get_total(), 4 ),
			'total_tax' => round( (float) $item->get_total_tax(), 4 ),
			'opf_meta'  => $item->get_meta( '_opf_fields', true ),
			'wapf_meta' => $item->get_meta( '_wapf_meta', true ),
		];
	}
	return [ 'order' => $order, 'items' => $items, 'total' => round( (float) $order->get_total(), 4 ) ];
};

/** Partial refund: refund one unit of the first line; snapshot refund math. */
$partial_refund = static function ( \WC_Order $order ) use ( $assert ): array {
	$line_items = [];
	$unit_share = 0.0;
	$unit_tax   = 0.0;
	foreach ( $order->get_items() as $item_id => $item ) {
		$qty = (int) $item->get_quantity();
		if ( $qty < 1 ) {
			continue;
		}
		$unit_share = round( (float) $item->get_total() / $qty, 4 );
		$unit_tax   = round( (float) $item->get_total_tax() / $qty, 4 );
		$line_items[ $item_id ] = [
			'qty'          => 1,
			'refund_total' => $unit_share,
			'refund_tax'   => [],
		];
		foreach ( (array) ( $item->get_taxes()['total'] ?? [] ) as $tax_id => $tax_amount ) {
			$line_items[ $item_id ]['refund_tax'][ $tax_id ] = round( (float) $tax_amount / $qty, 4 );
		}
		break;
	}
	$assert( [] !== $line_items, 'No refundable line on the order.' );
	$refund_amount = round( $unit_share + $unit_tax, 2 );
	$refund = wc_create_refund( [
		'order_id'   => $order->get_id(),
		'amount'     => $refund_amount,
		'line_items' => $line_items,
	] );
	$assert( ! is_wp_error( $refund ), 'Refund creation failed: ' . ( is_wp_error( $refund ) ? $refund->get_error_message() : 'unknown' ) );
	$refunded_total = 0.0;
	foreach ( $order->get_refunds() as $order_refund ) {
		$refunded_total += (float) $order_refund->get_amount();
	}
	return [
		'refund_id'      => (int) $refund->get_id(),
		'refund_amount'  => $refund_amount,
		'refunded_total' => round( $refunded_total, 4 ),
		'remaining'      => round( (float) wc_get_order( $order->get_id() )->get_total() - $refunded_total, 4 ),
	];
};

/** Real order-again repopulation via WC_Cart_Session::populate_cart_from_order. */
$order_again = static function ( \WC_Order $order, int $product_id ) use ( $cart, $assert, $reset, $lines_snapshot ): array {
	$reset();
	$order->set_status( 'completed' );
	$order->save();
	// WC_Cart::$session is protected; reach the live WC_Cart_Session by
	// reflection so populate_cart_from_order runs on the same instance Woo
	// uses on a real wp_loaded order-again request.
	$prop = new ReflectionProperty( $cart, 'session' );
	$prop->setAccessible( true );
	$session = $prop->getValue( $cart );
	$assert( $session instanceof \WC_Cart_Session, 'No WC_Cart_Session for order-again.' );
	$method = new ReflectionMethod( $session, 'populate_cart_from_order' );
	$method->setAccessible( true );
	$rebuilt = $method->invoke( $session, $order->get_id(), [] );
	$assert( is_array( $rebuilt ) && count( $rebuilt ) > 0, 'Order-again repopulated an empty cart.' );
	$cart->set_cart_contents( $rebuilt );
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	$cart->calculate_totals();
	return $lines_snapshot( $cart, $product_id );
};

$make_product = static function ( string $name, ?string $sale_price ) use ( &$owned_products ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( '10.00' );
	if ( null !== $sale_price ) {
		$product->set_sale_price( $sale_price );
	}
	$product->set_status( 'publish' );
	$product->set_tax_status( 'taxable' );
	$product_id = (int) $product->save();
	$owned_products[] = $product_id;
	return $product_id;
};

try {
	update_option( 'opf_admin_only', 'no' );
	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	update_option( 'woocommerce_tax_display_shop', 'excl' );
	update_option( 'woocommerce_tax_display_cart', 'excl' );
	update_option( 'woocommerce_tax_based_on', 'base' );
	$tax_rate_id = WC_Tax::_insert_tax_rate( [
		'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '8.25',
		'tax_rate_name' => 'PriceB temporary 8.25%', 'tax_rate_priority' => 99,
		'tax_rate_compound' => 0, 'tax_rate_shipping' => 0,
		'tax_rate_order' => 1, 'tax_rate_class' => '',
	] );
	$assert( $tax_rate_id > 0, 'Could not create the temporary tax rate.' );

	$created_user_id = wp_create_user( 'priceb_fixture_admin', wp_generate_password( 24, true, true ), 'priceb-fixture@example.test' );
	$assert( ! is_wp_error( $created_user_id ), 'Could not create the fixture admin user.' );
	$created_user_id = (int) $created_user_id;
	( new WP_User( $created_user_id ) )->set_role( 'administrator' );
	wp_set_current_user( $created_user_id );

	foreach ( $cases as $case ) {
		$wapf_product = $make_product( 'PriceB WAPF ' . $case['id'], $case['sale_price'] ?? null );
		$opf_product  = $make_product( 'PriceB OPF ' . $case['id'], $case['sale_price'] ?? null );

		$field_type = $case['field'] ?? 'select';
		if ( 'select' === $field_type ) {
			$opf_fields = [ [
				'id' => 'opt', 'label' => 'Opt', 'type' => 'select',
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing' => $case['opf'] ] ],
			] ];
			$wapf_fields = [ [
				'id' => 'opt', 'label' => 'opt', 'type' => 'select', 'required' => false,
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing_type' => $case['wapf'], 'pricing_amount' => $case['amount'] ] ],
			] ];
			$opf_values  = [ 'opt' => 'a' ];
			$wapf_values = [ 'field_opt' => 'a' ];
		} else {
			$opf_fields = [ [
				'id' => 'x', 'label' => 'X', 'type' => $field_type, 'pricing' => $case['opf'],
			] ];
			$wapf_fields = [ [
				'id' => 'x', 'label' => 'x', 'type' => $field_type, 'required' => false,
				'pricing' => [ 'enabled' => 'true', 'type' => $case['wapf'], 'amount' => (string) $case['amount'] ],
			] ];
			$opf_values  = [ 'x' => $case['value'] ];
			$wapf_values = [ 'field_x' => $case['value'] ];
		}

		$group_id = FieldGroups::save( 0, [
			'fields'      => $opf_fields,
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $opf_product ] ] ] ] ],
		], [ 'title' => 'PriceB OPF ' . $case['id'], 'status' => 'publish' ] );
		$assert( $group_id > 0, 'Could not create the disposable OPF group.' );
		$owned_groups[] = $group_id;
		FieldGroups::flush_cache();

		foreach ( $wapf_fields as &$wapf_field ) {
			$wapf_field['conditionals'] = $wapf_field['conditionals'] ?? [];
		}
		unset( $wapf_field );
		$wapf_model = Field_Groups::raw_json_to_field_group( [
			'id' => 'p_' . $wapf_product, 'type' => 'wapf_product',
			'fields' => $wapf_fields,
			'conditions' => [], 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ], 'variables' => [],
		] );
		$assert( $wapf_model && isset( $wapf_model->fields[0] ), 'WAPF fixture model did not parse for ' . $case['id'] );
		update_post_meta( $wapf_product, '_wapf_fieldgroup', $wapf_model->to_array() );

		$case_rows = [];
		// --- classic cart legs at q=1/q=3 ---
		foreach ( [ 1, 3 ] as $qty ) {
			$wapf_lines = $wapf_classic( $wapf_product, $wapf_values, $qty );
			$opf_lines  = $opf_classic( $opf_product, $group_id, $opf_values, $qty );
			$match = array_map( $public_line, $wapf_lines ) === array_map( $public_line, $opf_lines );
			$case_rows[] = [
				'path' => 'classic', 'qty' => $qty,
				'wapf' => array_map( $public_line, $wapf_lines ),
				'opf'  => array_map( $public_line, $opf_lines ),
				'match' => $match,
			];
		}
		// --- Store API legs at q=3 ---
		$wapf_store = $store_api( 'wapf', $wapf_product, [
			'wapf_field_groups' => 'p_' . $wapf_product,
			'wapf'              => $wapf_values,
		], 3 );
		$opf_store = $store_api( 'opf', $opf_product, [
			'opf_fields' => [ (string) $group_id => $opf_values ],
		], 3 );
		$match = array_map( $public_line, $wapf_store ) === array_map( $public_line, $opf_store );
		$case_rows[] = [
			'path' => 'store_api', 'qty' => 3,
			'wapf' => array_map( $public_line, $wapf_store ),
			'opf'  => array_map( $public_line, $opf_store ),
			'match' => $match,
		];
		// --- order persistence + refund + order-again per engine ---
		foreach ( [ 'wapf', 'opf' ] as $engine ) {
			if ( 'wapf' === $engine ) {
				$lines = $wapf_classic( $wapf_product, $wapf_values, 3 );
				$product_id = $wapf_product;
			} else {
				$lines = $opf_classic( $opf_product, $group_id, $opf_values, 3 );
				$product_id = $opf_product;
			}
			$order_data = $checkout_order( $created_user_id );
			$order      = $order_data['order'];
			$meta_key   = 'wapf' === $engine ? 'wapf_meta' : 'opf_meta';
			$meta_ok    = true;
			foreach ( $order_data['items'] as $item ) {
				if ( empty( $item[ $meta_key ] ) ) {
					$meta_ok = false;
				}
			}
			// Partial refund of one unit.
			$refund = $partial_refund( $order );
			// Real order-again repopulation.
			$order_again_lines = $order_again( $order, $product_id );
			$repopulated_match = array_map( $public_line, $lines ) === array_map( $public_line, $order_again_lines );
			$case_rows[] = [
				'path' => 'order', 'engine' => $engine, 'qty' => 3,
				'order_id'          => (int) $order->get_id(),
				'items'             => $order_data['items'],
				'order_total'       => $order_data['total'],
				'meta_persisted'    => $meta_ok,
				'refund'            => $refund,
				'order_again_lines' => array_map( $public_line, $order_again_lines ),
				'repopulated_match' => $repopulated_match,
				'cart_lines'        => array_map( $public_line, $lines ),
			];
			$assert( $repopulated_match, "$engine order-again cart diverged for {$case['id']}: " . wp_json_encode( [ 'cart' => array_map( $public_line, $lines ), 'again' => array_map( $public_line, $order_again_lines ) ] ) );
			$assert( $meta_ok, "$engine order meta missing for {$case['id']}: " . wp_json_encode( $order_data['items'] ) );
		}
		// Order-total equality between engines for the same case.
		$order_rows = array_values( array_filter( $case_rows, static fn( $row ) => 'order' === $row['path'] ) );
		$order_match = 2 === count( $order_rows )
			&& abs( $order_rows[0]['order_total'] - $order_rows[1]['order_total'] ) < 0.005
			&& array_map( static fn( $i ) => [ 'quantity' => $i['quantity'], 'total' => $i['total'], 'total_tax' => $i['total_tax'] ], $order_rows[0]['items'] )
				=== array_map( static fn( $i ) => [ 'quantity' => $i['quantity'], 'total' => $i['total'], 'total_tax' => $i['total_tax'] ], $order_rows[1]['items'] );
		$results[] = [ 'case' => $case['id'], 'wapf_type' => $case['wapf'], 'rows' => $case_rows, 'order_match' => $order_match ];
	}
} finally {
	$_POST = $post_before;
	$_REQUEST = $request_before;
	$_GET = $get_before;
	wp_set_current_user( (int) $current_user->ID );
	$cart->empty_cart( true );
	wc_clear_notices();
	foreach ( array_reverse( $owned_orders ) as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	if ( $created_user_id > 0 ) {
		wp_delete_user( $created_user_id );
	}
	foreach ( array_reverse( $owned_groups ) as $group_id ) {
		wp_delete_post( $group_id, true );
	}
	foreach ( array_reverse( $owned_products ) as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$product->delete( true );
		}
	}
	if ( $tax_rate_id > 0 ) {
		WC_Tax::_delete_tax_rate( $tax_rate_id );
	}
	foreach ( $old_options as $name => $value ) {
		if ( '__missing__' === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}
	if ( null === $admin_only_before ) {
		delete_option( 'opf_admin_only' );
	} else {
		update_option( 'opf_admin_only', $admin_only_before );
	}
	FieldGroups::flush_cache();
}

$mismatches = [];
foreach ( $results as $case_result ) {
	foreach ( $case_result['rows'] as $row ) {
		if ( isset( $row['match'] ) && ! $row['match'] ) {
			$mismatches[] = $case_result['case'] . ' @ ' . $row['path'] . ' qty ' . $row['qty'];
		}
	}
	if ( ! $case_result['order_match'] ) {
		$mismatches[] = $case_result['case'] . ' order totals';
	}
}

file_put_contents(
	$out . '/priceb-lifecycle-matrix.json',
	wp_json_encode(
		[
			'generated'   => gmdate( 'c' ),
			'wapf_version' => $wapf_version,
			'woocommerce' => WC_VERSION,
			'php'         => PHP_VERSION,
			'tax_rate'    => '8.25% exclusive, base location',
			'cases'       => $results,
			'mismatches'  => $mismatches,
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	)
);

$assert( $cart->is_empty(), 'Cleanup left cart fixtures behind.' );
foreach ( array_merge( $owned_products, $owned_groups, $owned_orders ) as $owned_id ) {
	$assert( ! get_post( $owned_id ), 'Cleanup left fixtures behind: ' . $owned_id );
}
$assert( [] === $mismatches, 'OPF/WAPF lifecycle mismatches: ' . implode( ', ', $mismatches ) );

WP_CLI::success( sprintf( 'PriceB lifecycle matrix passed: %d cases; classic q=1/q=3 + Store API cart, checkout orders, partial refunds and order-again identical between OPF and WAPF Extended 3.1.5; fixtures cleaned. Evidence: %s/priceb-lifecycle-matrix.json', count( $results ), $out ) );
