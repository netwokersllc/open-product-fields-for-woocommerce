<?php
/** Persist native WAPF image quantities, import them, and compare real carts. */

use OPF\Service\FieldGroups;
use OPF\Service\Importer;
use SW_WAPF_PRO\Includes\Classes\Field_Groups;

global $wpdb;

if ( '1' !== getenv( 'OPF_SUMQTY_IMPORT_ALLOW' ) || '/tmp/opf-sumqty-import-wp/' !== ABSPATH || FQDB !== ABSPATH . 'wp-content/database/.ht.sqlite' ) {
	throw new RuntimeException( 'Requires the dedicated disposable SQLite clone and OPF_SUMQTY_IMPORT_ALLOW=1.' );
}
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$assert( class_exists( Field_Groups::class ), 'Activate WAPF Extended before this proof.' );
$assert( [] === get_option( 'opf_sumqty_import_owned', [] ), 'Existing fixture ownership; clean it before running.' );
$assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('wapf_product','opf_field_group','product')" ), 'Requires an empty field-group/product database.' );
$owned = [];
// Record every fixture post at insertion, including partial importer failures.
$record = static function ( int $id, WP_Post $post, bool $update ) use ( &$owned ): void {
	if ( ! $update && 0 === strpos( $post->post_title, 'SumQty import proof' ) ) {
		$owned[] = $id;
		update_option( 'opf_sumqty_import_owned', $owned );
	}
};
add_action( 'wp_insert_post', $record, 10, 3 );
$before = [ 'post' => $_POST, 'request' => $_REQUEST, 'admin_only' => get_option( 'opf_admin_only', null ) ];
$results = [];
if ( ! WC()->cart ) { wc_load_cart(); }
$assert( WC()->cart->is_empty(), 'Requires an empty cart.' );
$fresh = static function (): void {
	WC()->cart->empty_cart();
	wc_clear_notices();
	$_POST = [];
	$_REQUEST = [];
	// Native WAPF skips repricing after the first totals action in a request.
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
};
$snapshot = static function (): array {
	WC()->cart->calculate_totals();
	$items = WC()->cart->get_cart();
	if ( 1 !== count( $items ) ) { throw new RuntimeException( 'Expected one cart item.' ); }
	$item = reset( $items );
	return [ 'unit' => (float) $item['data']->get_price(), 'line' => (float) $item['line_total'], 'fields' => $item['wapf'] ?? $item['opf_fields'] ?? [] ];
};
try {
	update_option( 'opf_admin_only', 'no' );
	$product = new WC_Product_Simple();
	$product->set_name( 'SumQty import proof product' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product->set_status( 'publish' );
	$pid = (int) $product->save();
	$raw = [
		'id' => 'p_' . $pid, 'type' => 'wapf_product', 'conditions' => [], 'variables' => [],
		'fields' => [
			[ 'id' => 'PrintID', 'type' => 'image-swatch-qty', 'label' => 'Prints', 'conditionals' => [], 'choices' => [
				[ 'slug' => 'oak', 'label' => 'Oak', 'pricing_type' => 'none', 'pricing_amount' => 0, 'min' => 0, 'max' => 12, 'default' => 0 ],
				[ 'slug' => 'ash', 'label' => 'Ash', 'pricing_type' => 'none', 'pricing_amount' => 0, 'min' => 0, 'max' => 12, 'default' => 0 ],
			] ],
			[ 'id' => 'FeeID', 'type' => 'select', 'label' => 'Fee', 'conditionals' => [], 'choices' => [
				[ 'slug' => 'selected', 'label' => 'Quantity fee', 'pricing_type' => 'fx', 'pricing_amount' => 'sumQty(PrintID)*[qty]' ],
			] ],
		],
	];
	$native = Field_Groups::raw_json_to_field_group( $raw );
	update_post_meta( $pid, '_wapf_fieldgroup', $native->to_array() );
	$report = Importer::run( true );
	$assert( 1 === $report['imported'], 'Expected one real persisted import.' );
	$row = $report['groups'][0];
	$gid = (int) $row['opf_id'];
	$assert( $row['needs_review'] && 'draft' === get_post_status( $gid ), 'Image media/layout review must keep the imported group draft.' );
	$assert( 1 === count( $row['notes'] ) && false !== strpos( $row['notes'][0], 'choice media references are imported' ), 'Unexpected formula review or missing media review: ' . wp_json_encode( $row['notes'] ) );
	$imported = FieldGroups::group_from_post( get_post( $gid ) );
	$assert( 'image_quantity' === $imported->field( 'prints' )['type'], 'Native quantity target did not map to image_quantity.' );
	$pricing = $imported->field( 'fee' )['choices'][0]['pricing'];
	$assert( 'sumQty(prints)' === $pricing['formula'] && 'sumQty(prints)*[qty]' === $pricing['formula_raw'] && $pricing['per_unit'], 'Choice formula reference or quantity semantics changed.' );
	$cases = [ [ 2, 3, 1 ], [ 2, 3, 3 ], [ 3, 0, 2 ], [ 0, 0, 1 ], [ 0, 4, 2 ] ];
	foreach ( $cases as [ $oak, $ash, $qty ] ) {
		$fresh();
		$_POST['wapf_field_groups'] = 'p_' . $pid;
		$_POST['wapf'] = [ 'field_PrintID' => '1', 'field_PrintID_oak' => (string) $oak, 'field_PrintID_ash' => (string) $ash, 'field_FeeID' => 'selected' ];
		$_REQUEST = $_POST;
		$assert( false !== WC()->cart->add_to_cart( $pid, $qty ), 'Native add-to-cart failed: ' . wp_json_encode( wc_get_notices( 'error' ) ) );
		$native_cart = $snapshot();
		$assert( abs( $native_cart['unit'] - ( 10 + $oak + $ash ) ) < 0.001, 'Native sumQty result differs from integer quantity sum.' );
		$results[] = [ 'oak' => $oak, 'ash' => $ash, 'quantity' => $qty, 'wapf' => $native_cart ];
	}
	$fresh();
	delete_post_meta( $pid, '_wapf_fieldgroup' );
	// Explicit fixture-only media review acceptance: no production attachments.
	wp_update_post( [ 'ID' => $gid, 'post_status' => 'publish' ] );
	FieldGroups::flush_cache();
	foreach ( $results as &$result ) {
		$fresh();
		$_POST['opf'] = [ (string) $gid => [ 'prints' => [ 'oak' => (string) $result['oak'], 'ash' => (string) $result['ash'] ], 'fee' => 'selected' ] ];
		$_REQUEST = $_POST;
		$assert( false !== WC()->cart->add_to_cart( $pid, $result['quantity'] ), 'Imported OPF add-to-cart failed: ' . wp_json_encode( wc_get_notices( 'error' ) ) );
		$result['opf'] = $snapshot();
		$assert( abs( $result['opf']['unit'] - $result['wapf']['unit'] ) < 0.001 && abs( $result['opf']['line'] - $result['wapf']['line'] ) < 0.001, 'Native/imported cart totals differ.' );
		$assert( [ 'oak' => $result['oak'], 'ash' => $result['ash'] ] === $result['opf']['fields'][ $gid ]['prints']['quantities'], 'Imported cart lost canonical quantities.' );
	}
	unset( $result );
	$artifact = [ 'scope' => 'Native WAPF classic cart and imported OPF classic cart; valid nonnegative integer quantities only; no licensed Tools UI proof.', 'import' => $row, 'raw' => $raw, 'pricing' => $pricing, 'results' => $results, 'owned_ids' => $owned ];
} finally {
	WC()->cart->empty_cart();
	foreach ( $owned as $id ) { wp_delete_post( $id, true ); }
	delete_option( 'opf_sumqty_import_owned' );
	remove_action( 'wp_insert_post', $record, 10 );
	$_POST = $before['post'];
	$_REQUEST = $before['request'];
	if ( null === $before['admin_only'] ) { delete_option( 'opf_admin_only' ); } else { update_option( 'opf_admin_only', $before['admin_only'] ); }
	FieldGroups::flush_cache();
	$assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('wapf_product','opf_field_group','product')" ), 'Fixture cleanup left posts.' );
}
echo wp_json_encode( $artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
