<?php
/** Remove only the upload lane's fixtures from its disposable runtime. */
if ( ! defined( 'OPF_UPLOAD_PRIVATE_DIR' ) || OPF_UPLOAD_PRIVATE_DIR !== '/tmp/opf-upload-foundation-private' || realpath( ABSPATH ) !== '/tmp/opf-upload-runtime-20261001' ) throw new RuntimeException( 'Disposable upload runtime required.' );
$product = get_page_by_path( 'upload-proof', OBJECT, 'product' );
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'title' => 'Upload proof fields', 'posts_per_page' => -1 ] );
if ( ! $product || 'Upload proof product' !== $product->post_title || count( $groups ) !== 1 ) throw new RuntimeException( 'Exact upload fixtures required.' );
$pid = $product->ID; $gid = $groups[0]->ID;
$active = get_option( 'active_plugins' );
$deleted_orders = [];
foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $order ) {
	$items = $order->get_items();
	if ( ! $items ) continue;
	foreach ( $items as $item ) if ( $item->get_product_id() !== $pid ) continue 2;
	$deleted_orders[] = $order->get_id(); $order->delete( true );
}
global $wpdb;
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'opf_upload_' ) . '%' ), ARRAY_A );
$deleted_uploads = 0;
foreach ( $rows as $row ) {
	$token = substr( $row['option_name'], strlen( 'opf_upload_' ) );
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) continue;
	$record = OPF\Service\Uploads::record( $token );
	if ( ! $record || $record['product_id'] !== $pid || $record['group_id'] !== (string) $gid ) continue;
	$path = OPF_UPLOAD_PRIVATE_DIR . '/' . $token . '.bin';
	if ( is_link( $path ) ) throw new RuntimeException( 'Unexpected symlink; preserving fixture record.' );
	if ( is_file( $path ) && ! unlink( $path ) ) throw new RuntimeException( 'Could not remove fixture bytes.' );
	delete_option( $row['option_name'] ); $deleted_uploads++;
}
wp_delete_post( $gid, true ); wp_delete_post( $pid, true ); OPF\Service\FieldGroups::flush_cache();
$report = [
	'deleted_product' => $pid, 'deleted_group' => $gid,
	'deleted_orders' => $deleted_orders, 'deleted_uploads' => $deleted_uploads,
	'product_absent' => null === get_post( $pid ), 'group_absent' => null === get_post( $gid ),
	'remaining_orders' => count( wc_get_orders( [ 'limit' => -1 ] ) ),
	'remaining_private_files' => count( glob( OPF_UPLOAD_PRIVATE_DIR . '/*.bin' ) ?: [] ),
	'active_plugins_unchanged' => $active === get_option( 'active_plugins' ),
	'active_plugins' => get_option( 'active_plugins' ),
	'wapf_installed' => is_dir( WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended' ),
];
echo wp_json_encode( $report, JSON_PRETTY_PRINT ) . "\n";
