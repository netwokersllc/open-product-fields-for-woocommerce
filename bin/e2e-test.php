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
check( 'placement: tag product matches', count( OPF\Service\FieldGroups::for_product( $matched_product ) ) === 1 );
check( 'placement: untagged product does not match', count( OPF\Service\FieldGroups::for_product( $unmatched_product ) ) === 0 );

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
check( 'compat: wapf container classes rendered', false !== strpos( $compat_html, 'wapf-field-container' ) && false !== strpos( $compat_html, 'wapf-field-text-swatch' ) );
check( 'compat: data-wapf-price attributes rendered', false !== strpos( $compat_html, 'data-wapf-price' ) );
check( 'compat: selected swatch has wapf-checked', false !== strpos( $compat_html, 'wapf-checked' ) );
$GLOBALS['product'] = null;

// ------------------------------------------------------------------ wrapup.
$cart->empty_cart();
wc_clear_notices();

WP_CLI::log( '' );
if ( $failures > 0 ) {
	WP_CLI::error( "$failures check(s) failed." );
} else {
	WP_CLI::success( 'All E2E checks passed.' );
}
