<?php
/** Remove only the upload UI lane fixtures from the disposable clone. */
if ( realpath( ABSPATH ) !== '/tmp/opf-image-uploadui-wp' ) {
	throw new RuntimeException( 'Disposable upload UI clone required.' );
}
$fixture = get_option( 'opf_upload_ui_fixture', [] );
$pid     = (int) ( $fixture['product_id'] ?? 0 );
$gid     = (int) ( $fixture['group_id'] ?? 0 );
if ( ! $pid || ! $gid ) {
	echo wp_json_encode( [ 'skipped' => true, 'reason' => 'no fixture option' ] ) . "\n";
	return;
}
$product = wc_get_product( $pid );
if ( ! $product || 'OPF Upload UI Product' !== $product->get_name() ) {
	throw new RuntimeException( 'Exact upload UI fixture required.' );
}

$deleted_orders = [];
foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $order ) {
	$match = false;
	foreach ( $order->get_items() as $item ) {
		if ( (int) $item->get_product_id() === $pid ) {
			$match = true;
			break;
		}
	}
	if ( $match ) {
		$deleted_orders[] = $order->get_id();
		$order->delete( true );
	}
}

global $wpdb;
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'opf_upload_' ) . '%' ), ARRAY_A );
$deleted_uploads = 0;
foreach ( $rows as $row ) {
	$token = substr( $row['option_name'], strlen( 'opf_upload_' ) );
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
		continue;
	}
	$record = OPF\Service\Uploads::record( $token );
	if ( ! $record || (int) $record['product_id'] !== $pid ) {
		continue;
	}
	$dir = defined( 'OPF_UPLOAD_PRIVATE_DIR' ) ? OPF_UPLOAD_PRIVATE_DIR : '/tmp/opf-upload-ui-private';
	$file = $dir . '/' . $token . '.bin';
	if ( is_link( $file ) ) {
		throw new RuntimeException( 'Unexpected symlink; preserving fixture record.' );
	}
	if ( is_file( $file ) && ! unlink( $file ) ) {
		throw new RuntimeException( 'Could not remove fixture bytes.' );
	}
	delete_option( $row['option_name'] );
	$deleted_uploads++;
}

$buyer = get_user_by( 'login', 'opfuibuyer' );
if ( $buyer ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $buyer->ID );
}

wp_delete_post( $gid, true );
wp_delete_post( $pid, true );

// Restore the exact option baseline captured by the fixture.
$restored = 0;
foreach ( (array) ( $fixture['before'] ?? [] ) as $key => $value ) {
	if ( '__opf_missing__' === $value ) {
		delete_option( $key );
	} else {
		update_option( $key, $value );
	}
	$restored++;
}
delete_option( 'opf_upload_ui_fixture' );
OPF\Service\FieldGroups::flush_cache();

$mu = WP_CONTENT_DIR . '/mu-plugins/opf-upload-ui-defines.php';
$mu_removed = ! is_file( $mu ) || unlink( $mu );
foreach ( (array) glob( '/tmp/opf-upload-ui-private/{*,.*}', GLOB_BRACE ) as $leftover ) {
	if ( in_array( basename( $leftover ), [ '.', '..' ], true ) ) {
		continue;
	}
	if ( ! is_link( $leftover ) && is_file( $leftover ) ) {
		unlink( $leftover );
	}
}
$dir_removed = ! is_dir( '/tmp/opf-upload-ui-private' ) || rmdir( '/tmp/opf-upload-ui-private' );

echo wp_json_encode( [
	'deleted_product'         => $pid,
	'deleted_group'           => $gid,
	'deleted_orders'          => $deleted_orders,
	'deleted_uploads'         => $deleted_uploads,
	'deleted_buyer'           => $buyer ? $buyer->ID : 0,
	'product_absent'          => null === get_post( $pid ),
	'group_absent'            => null === get_post( $gid ),
	'fixture_option_absent'   => false === get_option( 'opf_upload_ui_fixture', false ),
	'mu_plugin_removed'       => $mu_removed,
	'private_dir_removed'     => $dir_removed,
	'options_restored'        => $restored,
] ) . "\n";
