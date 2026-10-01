<?php
/** Isolated real WooCommerce orders and WAPF Tools round-trip proof. */
if ( '1' !== getenv( 'OPF_DISABLED_LIFECYCLE_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/opf-disabled-roundtrip-wp' ) ) {
	throw new RuntimeException( 'Explicit isolated clone authorization required.' );
}
function dc_assert( string $label, bool $pass ): void {
	if ( ! $pass ) { throw new RuntimeException( $label ); }
	echo "ok $label\n";
}
$dir = getenv( 'OPF_DISABLED_ARTIFACT_DIR' ) ?: '/tmp/opf-disabled-commerce-artifacts';
$phase = getenv( 'OPF_DISABLED_LIFECYCLE_PHASE' ) ?: 'setup';
$state = get_option( 'opf_disabled_lifecycle_state', [] );
if ( 'setup' === $phase ) {
	dc_assert( 'fresh isolated fixtures', empty( $state ) );
	$product = new WC_Product_Simple();
	$product->set_name( 'Disabled choice commerce fixture' );
	$product->set_slug( 'disabled-choice-commerce' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$pid = $product->save();
	$fields = [];
	foreach ( [ 'finish' => 'select', 'extras' => 'checkbox' ] as $id => $type ) {
		$fields[] = [ 'id' => $id, 'label' => ucfirst( $id ), 'type' => $type, 'choices' => [
			[ 'slug' => 'unavailable-' . $id, 'label' => 'Unavailable ' . $id, 'disabled' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 99 ] ],
			[ 'slug' => 'available-' . $id, 'label' => 'Available ' . $id, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 'finish' === $id ? 2 : 1 ] ],
		] ];
	}
	$gid = OPF\Service\FieldGroups::save( 0, [ 'fields' => $fields, 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ] ], [ 'title' => 'Disabled choice commerce', 'status' => 'publish' ] );
	$group = OPF\Service\FieldGroups::group_from_post( get_post( $gid ) );
	$payload = OPF\Service\WapfExporter::build_payload( $group->data );
	file_put_contents( $dir . '/opf-tools-export.json', wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
	$wapf = wp_insert_post( [ 'post_type' => 'wapf_product', 'post_status' => 'draft', 'post_title' => 'Disabled Tools imported fixture' ] );
	$uid = wp_create_user( 'disabled_lifecycle_admin', wp_generate_password( 32 ), 'disabled-admin@example.invalid' );
	dc_assert( 'isolated administrator created', ! is_wp_error( $uid ) );
	( new WP_User( $uid ) )->set_role( 'administrator' );
	$checkout = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Disabled proof checkout', 'post_name' => 'disabled-proof-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	update_option( 'woocommerce_checkout_page_id', $checkout );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	$state = [ 'product' => $pid, 'group' => $gid, 'wapf' => $wapf, 'user' => $uid, 'checkout' => $checkout ];
	update_option( 'opf_disabled_lifecycle_state', $state );
	file_put_contents( $dir . '/state.json', wp_json_encode( $state ) );
	echo "SUCCESS fixtures and OPF Tools export\n";
	return;
}
dc_assert( 'fixture exists', ! empty( $state['group'] ) );
if ( 'comparator-model' === $phase ) {
	$raw = json_decode( file_get_contents( $dir . '/opf-tools-export.json' ), true );
	$raw['id'] = $state['wapf'];
	$raw['type'] = 'wapf_product';
	$model = SW_WAPF_PRO\Includes\Classes\Field_Groups::raw_json_to_field_group( $raw );
	dc_assert( 'installed WAPF raw converter consumes actual OPF Tools payload', $model instanceof SW_WAPF_PRO\Includes\Models\FieldGroup );
	SW_WAPF_PRO\Includes\Classes\Field_Groups::save( $model, 'wapf_product', $state['wapf'], 'Disabled Tools model fixture', 'publish' );
	$reload = SW_WAPF_PRO\Includes\Classes\Field_Groups::get_by_id( $state['wapf'] );
	file_put_contents( $dir . '/wapf-model-raw-export.json', wp_json_encode( [ 'fields' => SW_WAPF_PRO\Includes\Classes\Field_Groups::field_group_to_raw_fields_json( $reload ) ], JSON_PRETTY_PRINT ) );
	echo "SUCCESS comparator model import/save/export (not licensed Tools UI proof)\n";
	return;
}
if ( 'commerce' !== $phase ) {
$export = json_decode( file_get_contents( $dir . '/wapf-model-raw-export.json' ), true );
dc_assert( 'installed WAPF model raw export parsed', is_array( $export ) && 2 === count( $export['fields'] ?? [] ) );
foreach ( $export['fields'] as $field ) {
	dc_assert( $field['id'] . ' WAPF export retains unavailable and available flags', true === $field['choices'][0]['disabled'] && false === $field['choices'][1]['disabled'] );
	dc_assert( $field['id'] . ' WAPF export retains choice prices', 99.0 === (float) $field['choices'][0]['pricing_amount'] && ( 'finish' === $field['id'] ? 2.0 : 1.0 ) === (float) $field['choices'][1]['pricing_amount'] );
}
$wapf_group = SW_WAPF_PRO\Includes\Classes\Field_Groups::get_by_id( $state['wapf'] );
dc_assert( 'WAPF saved/reloaded model retains disabled flag', true === $wapf_group->fields[0]->options['choices'][0]['disabled'] && false === $wapf_group->fields[0]->options['choices'][1]['disabled'] );
$source = get_post( $state['wapf'] );
dc_assert( 'WAPF group persisted by installed model save', 'publish' === $source->post_status );
$report = OPF\Service\Importer::run( true );
$back = current( array_filter( $report['groups'], static function ( $row ) use ( $state ) { return (string) $row['source'] === (string) $state['wapf']; } ) );
dc_assert( 'real persisted WAPF source imports back to OPF', ! empty( $back['opf_id'] ) );
$back_group = OPF\Service\FieldGroups::group_from_post( get_post( $back['opf_id'] ) );
file_put_contents( $dir . '/opf-import-back.json', wp_json_encode( $back_group->data, JSON_PRETTY_PRINT ) );
if ( 2 !== count( $back_group->data['fields'] ) ) { echo "GAP OPF persisted-source importer dropped the WAPF checkboxes field\n"; }
foreach ( $back_group->data['fields'] as $field ) {
	dc_assert( $field['id'] . ' OPF import back retains disabled flags and pricing', true === $field['choices'][0]['disabled'] && false === $field['choices'][1]['disabled'] && 99.0 === (float) $field['choices'][0]['pricing']['amount'] );
}
// Prevent the comparator and imported copy from contributing to commerce pricing.
wp_update_post( [ 'ID' => $state['wapf'], 'post_status' => 'draft' ] );
wp_update_post( [ 'ID' => $back['opf_id'], 'post_status' => 'draft' ] );
}
$orders = json_decode( file_get_contents( $dir . '/commerce-order-ids.json' ), true );
$again_results = [];
foreach ( $orders as $path => $id ) {
	$order = wc_get_order( $id );
	dc_assert( "$path checkout persists real order", $order instanceof WC_Order && 'on-hold' === $order->get_status() );
	$item = array_values( $order->get_items() )[0];
	$expected = [ (string) $state['group'] => [ 'finish' => 'available-finish', 'extras' => [ 'available-extras' ] ] ];
	$stored = json_decode( $item->get_meta( '_opf_fields', true ), true );
	dc_assert( "$path order retains exact available selection values", $expected === $stored );
	dc_assert( "$path order public metadata identifies available choices", 'Available finish' === $item->get_meta( 'Finish', true ) && 'Available extras' === $item->get_meta( 'Extras', true ) );
	dc_assert( "$path order price excludes unavailable choices", 23.0 === (float) $item->get_total() && 2 === $item->get_quantity() && 23.0 === (float) $order->get_total() );
	dc_assert( "$path order has durable field snapshot", is_array( json_decode( $item->get_meta( '_opf_fields_snapshot', true ), true ) ) );
	if ( ! WC()->cart ) { wc_load_cart(); }
	WC()->cart->empty_cart();
	$restored = apply_filters( 'woocommerce_order_again_cart_item_data', [], $item, $order );
	$key = WC()->cart->add_to_cart( $state['product'], 2, 0, [], $restored );
	WC()->cart->calculate_totals();
	file_put_contents( $dir . '/' . $path . '-order-again.json', wp_json_encode( [ 'key_created' => (bool) $key, 'restored' => $restored, 'cart_values' => $key ? ( WC()->cart->get_cart_item( $key )['opf_fields'] ?? null ) : null, 'total' => WC()->cart->get_total( 'edit' ) ], JSON_PRETTY_PRINT ) );
	dc_assert( "$path order-again restores cart available values", (bool) $key && $expected === WC()->cart->get_cart_item( $key )['opf_fields'] );
	$again_results[$path] = [ 'expected_total' => 23.0, 'actual_total' => (float) WC()->cart->get_total( 'edit' ), 'pass' => 23.0 === (float) WC()->cart->get_total( 'edit' ) ];
	if ( ! $again_results[$path]['pass'] ) { echo "GAP $path order-again retains selections but loses flat fees: expected 23, actual " . WC()->cart->get_total( 'edit' ) . "\n"; }
	WC()->cart->empty_cart();
}
file_put_contents( $dir . '/order-again-price-results.json', wp_json_encode( $again_results, JSON_PRETTY_PRINT ) );
echo "SUCCESS disabled choice order persistence and order-again values; price results recorded separately\n";
