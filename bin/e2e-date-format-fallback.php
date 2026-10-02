<?php
/** Disposable localhost fixture and WooCommerce proof; driven by the browser script. */
defined( 'ABSPATH' ) || exit;
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ '127.0.0.1', 'localhost' ], true ) ) {
	throw new RuntimeException( 'This proof requires a disposable localhost WordPress installation.' );
}

$state_key = 'opf_date_format_fallback_proof';
$state = get_option( $state_key, null );
if ( 'cleanup' === getenv( 'OPF_DATE_PROOF_ACTION' ) ) {
	if ( is_array( $state ) ) {
		foreach ( $state['options'] as $key => $value ) {
			null === $value ? delete_option( $key ) : update_option( $key, $value );
		}
		foreach ( $state['posts'] as $id ) {
			wp_delete_post( (int) $id, true );
		}
		delete_option( $state_key );
		\OPF\Service\FieldGroups::flush_cache();
	}
	return;
}
if ( null !== $state ) {
	throw new RuntimeException( 'Clean up the previous fixture before starting another proof.' );
}
$cases = [
	'fallback' => [ 'garbage', 'dd/mm/yy', 'dd/mm/yy', '15/10/26' ],
	'default' => [ 'garbage', 'not-a-format', 'mm-dd-yyyy', '10-15-2026' ],
	'opf' => [ 'yyyy.mm.dd', 'dd/mm/yy', 'yyyy.mm.dd', '2026.10.15' ],
];
$case = getenv( 'OPF_DATE_PROOF_CASE' );
if ( ! isset( $cases[ $case ] ) ) {
	throw new RuntimeException( 'Specify OPF_DATE_PROOF_CASE=fallback|default|opf.' );
}
[ $opf, $wapf, $format, $display ] = $cases[ $case ];
$state = [ 'options' => [], 'posts' => [] ];
foreach ( [ 'opf_date_format', 'wapf_date_format' ] as $key ) {
	$state['options'][ $key ] = get_option( $key, null );
}
update_option( $state_key, $state );
update_option( 'opf_date_format', $opf );
update_option( 'wapf_date_format', $wapf );
$order = null;
try {
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF Date Format Fallback ' . $case );
	$product->set_regular_price( '10' );
	$product->set_status( 'publish' );
	$product->set_virtual( true );
	$product_id = $product->save();
	$state['posts'][] = $product_id;
	update_option( $state_key, $state );
	$group_id = \OPF\Service\FieldGroups::save( 0, new \OPF\Engine\FieldGroup( [
		'fields' => [
			[ 'id' => 'proof_date', 'type' => 'date', 'label' => 'Proof date', 'required' => true ],
			[ 'id' => 'formula_date', 'type' => 'text', 'label' => 'Formula date', 'required' => true, 'pricing' => [ 'type' => 'formula', 'formula' => 'dow([val])', 'amount' => 0 ] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] ), [ 'title' => 'OPF Date Format Fallback Proof' ] );
	if ( ! $group_id ) {
		throw new RuntimeException( 'Could not create field group.' );
	}
	$state['posts'][] = $group_id;
	update_option( $state_key, $state );
	\OPF\Service\FieldGroups::flush_cache();
	WC()->cart->empty_cart( true );
	$_POST['opf'] = [ (string) $group_id => [ 'proof_date' => '2026-10-15', 'formula_date' => $display ] ];
	$key = WC()->cart->add_to_cart( $product_id, 1 );
	unset( $_POST['opf'] );
	if ( ! $key ) {
		throw new RuntimeException( 'WooCommerce rejected the date cart item.' );
	}
	WC()->cart->calculate_totals();
	$item = WC()->cart->get_cart_item( $key );
	$labels = \OPF\Service\CartIntegration::display_item_data( [], $item );
	$values = array_column( $labels, 'value', 'name' );
	if ( $display !== ( $values['Proof date'] ?? null ) || 14.0 !== (float) $item['data']->get_price() ) {
		throw new RuntimeException( 'Cart label or formatted-date formula price is incorrect.' );
	}
	$order = wc_create_order();
	$line_id = $order->add_product( $item['data'], 1 );
	$line = $order->get_item( $line_id );
	\OPF\Service\CartIntegration::persist_order_item( $line, $key, $item, $order );
	$line->save();
	$order->calculate_totals();
	$order->save();
	$reloaded = new WC_Order_Item_Product( $line_id );
	$stored = json_decode( (string) $reloaded->get_meta( '_opf_fields', true ), true );
	if ( $display !== $reloaded->get_meta( 'Proof date', true ) || '2026-10-15' !== ( $stored[ $group_id ]['proof_date'] ?? null ) || 14.0 !== (float) $reloaded->get_total() ) {
		throw new RuntimeException( 'Reloaded order metadata, ISO value, or formula price is incorrect.' );
	}
	echo wp_json_encode( [ 'case' => $case, 'format' => $format, 'display' => $display, 'group_id' => $group_id, 'product_id' => $product_id, 'url' => get_permalink( $product_id ), 'cart_url' => wc_get_cart_url(), 'checkout_url' => wc_get_checkout_url(), 'cart_price' => (float) $item['data']->get_price(), 'order_price' => (float) $reloaded->get_total(), 'order_display' => $reloaded->get_meta( 'Proof date', true ), 'stored_iso' => $stored[ $group_id ]['proof_date'] ] ), "\n";
} finally {
	unset( $_POST['opf'] );
	WC()->cart->empty_cart( true );
	if ( $order instanceof WC_Order ) {
		$order->delete( true );
	}
}
