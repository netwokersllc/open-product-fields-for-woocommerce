<?php
/**
 * OPF end-to-end integration test.
 *
 * Run inside a disposable WordPress + WooCommerce install:
 *   wp eval-file bin/e2e-test.php
 *
 * Exercises the full lifecycle: group creation → placement matching →
 * classic add-to-cart capture → Store API add-to-cart capture → cart
 * pricing → display data → order persistence → order-again restore →
 * validation failures. Exits non-zero on any failure.
 */

defined( 'ABSPATH' ) || exit;

global $failures;
$failures = 0;

function check( $label, $condition ): void {
	global $failures;
	if ( $condition ) {
		WP_CLI::log( "  ok    $label" );
	} else {
		$failures++;
		WP_CLI::log( "  FAIL  $label" );
	}
}

WP_CLI::log( '== OPF E2E ==' );

// The transition gate (admin/e2e-only) must be off for the behavioral suite.
update_option( 'opf_admin_only', 'no' );

// Enable a payment gateway for the Store API checkout leg.
update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
WC()->payment_gateways()->init();

// ---------------------------------------------------------------- fixtures.
$existing_cat = get_term_by( 'name', 'E2E Premium', 'product_cat' );
$cat_id       = $existing_cat ? (int) $existing_cat->term_id : (int) ( wp_insert_term( 'E2E Premium', 'product_cat' )['term_id'] ?? 0 );
$existing_tag = get_term_by( 'name', 'e2e-fast', 'product_tag' );
$tag_id       = $existing_tag ? (int) $existing_tag->term_id : (int) ( wp_insert_term( 'e2e-fast', 'product_tag' )['term_id'] ?? 0 );

// Reuse or create products (idempotent across runs).
$matched_id = 0;
foreach ( wc_get_products( [ 'limit' => 50, 'return' => 'objects' ] ) as $p ) {
	if ( 'E2E Matched Product' === $p->get_name() ) {
		$matched_id = $p->get_id();
		$p->set_regular_price( '100.00' );
		$p->set_status( 'publish' );
		$p->set_tag_ids( [ (int) $tag_id ] );
		$p->save();
	}
}
if ( ! $matched_id ) {
	$matched = new WC_Product_Simple();
	$matched->set_name( 'E2E Matched Product' );
	$matched->set_regular_price( '100.00' );
	$matched->set_status( 'publish' );
	$matched->set_tag_ids( [ (int) $tag_id ] );
	$matched->set_category_ids( [ (int) $cat_id ] );
	$matched_id = $matched->save();
}

$unmatched_id = 0;
foreach ( wc_get_products( [ 'limit' => 50, 'return' => 'objects' ] ) as $p ) {
	if ( 'E2E Unmatched Product' === $p->get_name() ) {
		$unmatched_id = $p->get_id();
		$p->set_regular_price( '50.00' );
		$p->set_status( 'publish' );
		$p->set_tag_ids( [] );
		$p->save();
	}
}
if ( ! $unmatched_id ) {
	$unmatched = new WC_Product_Simple();
	$unmatched->set_name( 'E2E Unmatched Product' );
	$unmatched->set_regular_price( '50.00' );
	$unmatched->set_status( 'publish' );
	$unmatched_id = $unmatched->save();
}

// Remove stale types groups from previous runs BEFORE placement assertions.
foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => 'E2E Types Group' ] ) as $stale ) {
	wp_delete_post( (int) $stale->ID, true );
}
OPF\Service\FieldGroups::flush_cache();

check( 'fixtures: products created', $matched_id > 0 && $unmatched_id > 0 );

// ------------------------------------------------------------ field group.
$group_data = [
	'fields'  => [
		[
			'id'          => 'delivery',
			'label'       => 'Delivery speed',
			'description' => 'How fast',
			'type'        => 'swatch',
			'required'    => true,
			'width'       => 100,
			'choices'     => [
				[ 'slug' => 'normal', 'label' => 'Normal', 'selected' => true, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ] ],
				[ 'slug' => 'plus', 'label' => 'Plus', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'percent', 'amount' => 20.0, 'formula' => '' ] ],
				[ 'slug' => 'boost', 'label' => 'Boost', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 5.0, 'formula' => '' ] ],
				[ 'slug' => 'formula', 'label' => 'Formula', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '([price] + [addons]) * 0.1' ] ],
			],
			'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals' => [],
		],
		[
			'id'          => 'boost_note',
			'label'       => 'Boost instructions',
			'description' => '',
			'type'        => 'textarea',
			'required'    => false,
			'width'       => 100,
			'choices'     => [],
			'pricing'     => [ 'type' => 'fixed', 'amount' => 2.0, 'formula' => '' ],
			'conditionals'=> [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'delivery', 'operator' => 'is', 'value' => 'boost' ] ] ] ],
		],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product_tag', 'operator' => 'in', 'terms' => [ (string) $tag_id ] ] ] ] ],
	'mark_required' => true,
	'labels_position' => 'above',
];

// Reuse the E2E group if a previous run created it.
$existing_group = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => 1, 'title' => 'E2E Group' ] );
$gid            = $existing_group ? (int) $existing_group[0]->ID : 0;
$gid            = OPF\Service\FieldGroups::save( $gid, new OPF\Engine\FieldGroup( $group_data ), [ 'title' => 'E2E Group' ] );
check( 'field group saved', $gid > 0 );
check( 'group data round-trips', OPF\Service\FieldGroups::group_from_post( get_post( $gid ) )->data['fields'][0]['id'] === 'delivery' );

// ------------------------------------------------------ placement matching.
$matched_product   = wc_get_product( $matched_id );
$unmatched_product = wc_get_product( $unmatched_id );
$matched_titles    = wp_list_pluck( OPF\Service\FieldGroups::for_product( $matched_product ), 'title' );
$unmatched_titles  = wp_list_pluck( OPF\Service\FieldGroups::for_product( $unmatched_product ), 'title' );
// The types group is created later in this script; at this point only the
// tag-scoped E2E Group should match.
check( 'placement: tagged product matches the E2E group', in_array( 'E2E Group', $matched_titles, true ) );
check( 'placement: untagged product matches nothing yet', empty( $unmatched_titles ) );

// ------------------------------------------------- classic add-to-cart path.
$_POST['opf'] = [
	(string) $gid => [
		'delivery'   => 'plus',
		'boost_note' => '', // hidden under delivery=plus → stripped.
	],
];
$passed  = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
$cart    = WC()->cart;
$cart->empty_cart();
$item_key = $cart->add_to_cart( $matched_id, 2 );
unset( $_POST['opf'] );

check( 'classic: validation passes', $passed );
check( 'classic: item added', false !== $item_key );
$cart_item = $cart->get_cart_item( $item_key );
check( 'classic: values attached', ( $cart_item['opf_fields'][ (string) $gid ]['delivery'] ?? '' ) === 'plus' );
check( 'classic: hidden field stripped', ! isset( $cart_item['opf_fields'][ (string) $gid ]['boost_note'] ) );
check( 'classic: base price stored', abs( (float) $cart_item['opf_base_price'] - 100.0 ) < 0.001 );

$cart->calculate_totals();
$line = $cart->get_cart_item( $item_key );
$expected_unit = 100.0 + ( 100.0 * 0.20 ); // plus: 20% of unit price.
check( 'classic: price = base + percent addon', abs( (float) $line['data']->get_price() - $expected_unit ) < 0.001 );
check( 'classic: cart total = unit * qty', abs( (float) $cart->get_total( 'edit' ) - ( $expected_unit * 2 ) ) < 0.001 );

$display = apply_filters( 'woocommerce_get_item_data', [], $line );
check( 'classic: display shows label', ( $display[0]['name'] ?? '' ) === 'Delivery speed' && ( $display[0]['value'] ?? '' ) === 'Plus' );

// --------------------------------------- formula + conditional visibility.
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'boost', 'boost_note' => 'rush it' ] ];
$cart->empty_cart();
$key2 = $cart->add_to_cart( $matched_id, 1 );
unset( $_POST['opf'] );
$cart->calculate_totals();
$line2 = $cart->get_cart_item( $key2 );
// boost: fixed 5 + visible boost_note fixed 2 → per-unit 7 over base 100.
check( 'formula-mode: conditional visible → both priced', abs( (float) $line2['data']->get_price() - 107.0 ) < 0.001 );
$display2 = apply_filters( 'woocommerce_get_item_data', [], $line2 );
$names    = wp_list_pluck( $display2, 'name' );
check( 'formula-mode: both selections displayed', in_array( 'Boost instructions', $names, true ) );

// ---------------------------------------------- required-field validation.
$_POST['opf'] = [ (string) $gid => [ 'boost_note' => 'no delivery chosen' ] ];
wc_clear_notices();
$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
unset( $_POST['opf'] );
check( 'validation: missing required choice rejected', false === $ok );
check( 'validation: error notice queued', wc_notice_count( 'error' ) > 0 );

// --------------------------------------------------- Store API add-to-cart.
$cart->empty_cart();
wc_clear_notices();
$rest_server = rest_get_server();
$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$request->set_param( 'id', $matched_id );
$request->set_param( 'quantity', 1 );
$request->set_param( 'opf_fields', [ (string) $gid => [ 'delivery' => 'formula' ] ] );
$response = $rest_server->dispatch( $request );
check( 'store api: add-item accepted (status 201/200)', in_array( $response->get_status(), [ 200, 201 ], true ) );
if ( ! in_array( $response->get_status(), [ 200, 201 ], true ) ) {
	WP_CLI::log( '        store api error: ' . wp_json_encode( $response->get_data() ) );
}
$cart_after = $cart->get_cart();
check( 'store api: item in cart', count( $cart_after ) === 1 );
if ( count( $cart_after ) === 1 ) {
	$rest_item = reset( $cart_after );
	check( 'store api: values captured', ( $rest_item['opf_fields'][ (string) $gid ]['delivery'] ?? '' ) === 'formula' );
	$cart->calculate_totals();
	$rest_line = $cart->get_cart_item( $rest_item['key'] );
	// formula: ([price] + [addons]) * 0.1 → (100 + 0) * 0.1 = 10 per unit.
	check( 'store api: formula priced', isset( $rest_line ) && abs( (float) $rest_line['data']->get_price() - 110.0 ) < 0.001 );
} else {
	$rest_item = [ 'key' => '' ];
	check( 'store api: values captured', false );
	check( 'store api: formula priced', false );
}
$rest_data = $response->get_data();
$items     = $rest_data['items'] ?? [];
$has_label  = false;
foreach ( $items as $api_item ) {
	foreach ( ( $api_item['item_data'] ?? [] ) as $entry ) {
		if ( ( $entry['name'] ?? '' ) === 'Delivery speed' && ( $entry['value'] ?? '' ) === 'Formula' ) {
			$has_label = true;
		}
	}
}
check( 'store api: block-checkout display includes selection', $has_label );

// ------------------------------------------------------ order persistence.
WC()->session->set( 'cart', $cart->get_cart_for_session() );
$order = wc_create_order();
$order_items = [];
foreach ( $cart->get_cart() as $ci_key => $ci ) {
	$item_id = $order->add_product( $ci['data'], $ci['quantity'] );
	$order_items[ $ci_key ] = $order->get_item( $item_id );
	do_action( 'woocommerce_checkout_create_order_line_item', $order_items[ $ci_key ], $ci_key, $ci, $order );
}
// Real checkout saves items after the hook fires; mirror that.
foreach ( $order_items as $oi ) {
	$oi->save();
}
$order->update_status( 'processing' );
$order->save();

$first_item = array_values( $order->get_items() )[0];
$stored = $first_item->get_meta( '_opf_fields', true );
check( 'order: structured meta persisted', is_string( $stored ) && false !== strpos( (string) $stored, 'formula' ) );
check( 'order: display meta persisted', '' !== $first_item->get_meta( 'Delivery speed', true ) );

// -------------------------------------------------------- order-again flow.
$again = apply_filters( 'woocommerce_order_again_cart_item_data', [], $first_item, $order );
check( 'order again: selections restored', ( $again['opf_fields'][ (string) $gid ]['delivery'] ?? '' ) === 'formula' );

// ------------------------------------------------------ theme compat layer.
$GLOBALS['product'] = $matched_product;
ob_start();
do_action( 'woocommerce_before_add_to_cart_button' );
$compat_html = ob_get_clean();
check( 'compat: opf container classes rendered', false !== strpos( $compat_html, 'opf-field-container' ) && false !== strpos( $compat_html, 'opf-field-text-swatch' ) );
check( 'compat: data-opf-price attributes rendered', false !== strpos( $compat_html, 'data-opf-price' ) );
check( 'compat: selected swatch has opf-checked', false !== strpos( $compat_html, 'opf-checked' ) );
$GLOBALS['product'] = null;

// ------------------------------------- extra field types + multi-checkbox.
$type_group_data = [
	'fields'  => [
		[
			'id' => 'addons', 'label' => 'Extras', 'description' => '', 'type' => 'checkbox', 'required' => false,
			'width' => 100,
			'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 3.0, 'formula' => '' ] ],
				[ 'slug' => 'priority', 'label' => 'Priority queue', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 4.0, 'formula' => '' ] ],
			],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals' => [],
		],
		[
			'id' => 'quantity_extra', 'label' => 'Extra units', 'description' => '', 'type' => 'number', 'required' => false,
			'width' => 100, 'choices' => [],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals' => [],
		],
		[
			'id' => 'source_url', 'label' => 'Source link', 'description' => '', 'type' => 'url', 'required' => false,
			'width' => 100, 'choices' => [],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals' => [],
		],
	],
	'rule_groups' => [],
	'mark_required' => false,
	'labels_position' => 'above',
];
$type_gid = OPF\Service\FieldGroups::save( 0, new OPF\Engine\FieldGroup( $type_group_data ), [ 'title' => 'E2E Types Group' ] );
check( 'types: group saved', $type_gid > 0 );

// Global group (empty placement) now matches BOTH products.
check( 'types: global placement matches tagged product', count( OPF\Service\FieldGroups::for_product( $matched_product ) ) >= 1 );
check( 'types: global placement matches untagged product', count( OPF\Service\FieldGroups::for_product( $unmatched_product ) ) === 1 );

// Checkbox: both choices selected → both priced (3 + 4 = 7 per unit).
// The required `delivery` field from the first group must be satisfied too.
$_POST['opf'] = [
	(string) $gid => [ 'delivery' => 'normal' ],
	(string) $type_gid => [
		'addons'         => [ 'gift', 'priority' ],
		'quantity_extra' => '2.5',
		'source_url'     => 'https://example.com/page?x=1',
	],
];
$cart->empty_cart();
$key3 = $cart->add_to_cart( $matched_id, 1 );
unset( $_POST['opf'] );
$cart->calculate_totals();
$line3 = $cart->get_cart_item( $key3 );
check( 'types: multi-checkbox both priced (3+4)', isset( $line3 ) && abs( (float) $line3['data']->get_price() - 107.0 ) < 0.001 );
$display3 = apply_filters( 'woocommerce_get_item_data', [], $line3 );
$labels3  = wp_list_pluck( $display3, 'value' );
check( 'types: checkbox display lists both labels', in_array( 'Gift wrap, Priority queue', $labels3, true ) );
check( 'types: number value sanitized', '2.5' === ( $line3['opf_fields'][ (string) $type_gid ]['quantity_extra'] ?? '' ) );
check( 'types: url value sanitized', 'https://example.com/page?x=1' === ( $line3['opf_fields'][ (string) $type_gid ]['source_url'] ?? '' ) );

// Number: non-numeric input sanitizes to empty (never prices, never stores garbage).
$_POST['opf'] = [
	(string) $gid => [ 'delivery' => 'normal' ],
	(string) $type_gid => [ 'addons' => [ 'gift' ], 'quantity_extra' => 'abc' ],
];
$cart->empty_cart();
$key4 = $cart->add_to_cart( $matched_id, 1 );
unset( $_POST['opf'] );
$line4 = $cart->get_cart_item( $key4 );
check( 'types: non-numeric number input dropped', ! isset( $line4['opf_fields'][ (string) $type_gid ]['quantity_extra'] ) );
check( 'types: price reflects remaining checkbox only', isset( $line4 ) && abs( (float) $line4['data']->get_price() - 103.0 ) < 0.001 );

// -------------------------------------- flat-fixed fee does not scale by qty.
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'boost' ] ];
$cart->empty_cart();
$key5 = $cart->add_to_cart( $matched_id, 3 );
unset( $_POST['opf'] );
$cart->calculate_totals();
$line5 = $cart->get_cart_item( $key5 );
// boost fixed 5 is a flat fee: (100 + 5/3) per unit × 3 = 305 total.
check( 'flat-fee: fixed addon does not multiply by qty (line = 305)', isset( $line5 ) && abs( (float) $line5['data']->get_price() * 3 - 305.0 ) < 0.001 );

// --------------------------------------- Store API checkout → real order.
$cart->empty_cart();
wc_clear_notices();
$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$request->set_param( 'id', $matched_id );
$request->set_param( 'quantity', 2 );
$request->set_param( 'opf_fields', [ (string) $gid => [ 'delivery' => 'plus' ] ] );
$response = rest_get_server()->dispatch( $request );
check( 'checkout: item in cart', in_array( $response->get_status(), [ 200, 201 ], true ) );

$gateway = WC()->payment_gateways()->get_available_payment_gateways()['bacs'] ?? null;
if ( ! $gateway ) {
	check( 'checkout: bacs gateway available', false );
} else {
	$checkout_request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$checkout_request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$checkout_request->set_param( 'payment_method', 'bacs' );
	$checkout_request->set_param( 'billing_address', [
		'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'buyer@example.com',
		'address_1'  => '1 Test St', 'city' => 'Testville', 'postcode' => '12345',
		'country'    => 'US', 'state'    => 'CA',
	] );
	$checkout_request->set_param( 'customer_note', 'E2E order' );
	$checkout_response = rest_get_server()->dispatch( $checkout_request );
	check( 'checkout: Store API checkout accepted', in_array( $checkout_response->get_status(), [ 200, 201 ], true ) );
	if ( ! in_array( $checkout_response->get_status(), [ 200, 201 ], true ) ) {
		WP_CLI::log( '        checkout error: ' . wp_json_encode( $checkout_response->get_data() ) );
	} else {
		$order_id = $checkout_response->get_data()['order_id'] ?? 0;
		$checkout_order = wc_get_order( $order_id );
		check( 'checkout: order created', $checkout_order instanceof WC_Order );
		$co_item = array_values( $checkout_order->get_items() )[0] ?? null;
		$co_meta = $co_item ? $co_item->get_meta( '_opf_fields', true ) : '';
		check( 'checkout: order item carries structured fields', is_string( $co_meta ) && false !== strpos( (string) $co_meta, 'plus' ) );
		check( 'checkout: order item display meta', '' !== ( $co_item ? $co_item->get_meta( 'Delivery speed', true ) : '' ) );
		$expected_line = 2 * ( 100.0 + 20.0 ); // plus: 20% per unit, qty 2.
		check( 'checkout: order line totals priced server-side', abs( (float) $co_item->get_total() - $expected_line ) < 0.001 );
	}
}

// ------------------------------------------------- meta display prettifier.
$probe_item = array_values( $order->get_items() )[0] ?? null;
if ( $probe_item ) {
	$pretty_key = apply_filters( 'woocommerce_order_item_display_meta_key', 'duracion', null, $probe_item );
	check( 'prettifier: legacy key humanized with accents', 'Duración' === $pretty_key );
	$pretty_key2 = apply_filters( 'woocommerce_order_item_display_meta_key', 'source_link', null, $probe_item );
	check( 'prettifier: lowercase key humanized', 'Source link' === $pretty_key2 );
	$pretty_key3 = apply_filters( 'woocommerce_order_item_display_meta_key', 'Delivery speed', null, $probe_item );
	check( 'prettifier: already-clean key untouched', 'Delivery speed' === $pretty_key3 );
	$pretty_val = apply_filters( 'woocommerce_order_item_display_meta_value', 'https://example.com/target', null, $probe_item );
	check( 'prettifier: URL value rendered as safe link', is_string( $pretty_val ) && false !== strpos( $pretty_val, '<a href="https://example.com/target"' ) && false !== strpos( $pretty_val, 'rel="noopener noreferrer"' ) );
	$pretty_val2 = apply_filters( 'woocommerce_order_item_display_meta_value', 'Boost', null, $probe_item );
	check( 'prettifier: plain value untouched', 'Boost' === $pretty_val2 );
	$pretty_val3 = apply_filters( 'woocommerce_order_item_display_meta_value', 'javascript:alert(1)', null, $probe_item );
	check( 'prettifier: javascript: URL not linked', 'javascript:alert(1)' === $pretty_val3 );
}

// ------------------------------------------------------------------ wrapup.
$cart->empty_cart();
wc_clear_notices();

WP_CLI::log( '' );
if ( $failures > 0 ) {
	WP_CLI::error( "$failures check(s) failed." );
} else {
	WP_CLI::success( 'All E2E checks passed.' );
}
