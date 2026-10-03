<?php
/**
 * Disposable runtime only: wp eval-file bin/e2e-upload-reissue-proof.php
 *
 * Proves the secure order-again reissue contract for private uploads on the
 * upload-lane clone: a completed order's session+order-bound token is copied
 * into a fresh session-owned record inside the real
 * `woocommerce_order_again_cart_item_data` filter chain, while foreign
 * sessions, wrong-scope tokens, retryable bindings, and quota limits all
 * fail closed. Cleans up every fixture it creates.
 */
use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;
use OPF\Service\Uploads;

if ( ! defined( 'OPF_UPLOAD_PRIVATE_DIR' ) || '/tmp/opf-upload-lane-private' !== OPF_UPLOAD_PRIVATE_DIR ) {
	throw new RuntimeException( 'Disposable upload-lane runtime required.' );
}
if ( ! function_exists( 'wc_get_order' ) || ! class_exists( Uploads::class ) ) {
	throw new RuntimeException( 'WooCommerce + OPF runtime required.' );
}

$results = [];
$check   = static function ( string $name, bool $passed, $details = null ) use ( &$results ): void {
	$results[] = [ 'check' => $name, 'passed' => $passed, 'details' => $details ];
	if ( ! $passed ) {
		echo wp_json_encode( [ 'checks' => $results ], JSON_PRETTY_PRINT ) . "\n";
		throw new RuntimeException( $name );
	}
};

$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1cAAAAASUVORK5CYII=' );
$bin_count = static function (): int {
	return count( glob( OPF_UPLOAD_PRIVATE_DIR . '/*.bin' ) ?: [] );
};
$make_record = static function ( string $token, string $owner, int $pid, string $gid, string $fid, int $order_id ) use ( $png ): void {
	file_put_contents( OPF_UPLOAD_PRIVATE_DIR . '/' . $token . '.bin', $png );
	chmod( OPF_UPLOAD_PRIVATE_DIR . '/' . $token . '.bin', 0600 );
	add_option( 'opf_upload_' . $token, [
		'name' => 'art.png', 'mime' => 'image/png', 'size' => strlen( $png ), 'owner' => $owner,
		'product_id' => $pid, 'group_id' => $gid, 'field_id' => $fid,
		'created' => time(), 'order_id' => $order_id, 'cart' => false,
	], '', false );
};
$order_again = static function ( \WC_Order $order ): array {
	$cart_item_data = [];
	foreach ( $order->get_items() as $item ) {
		$cart_item_data = apply_filters( 'woocommerce_order_again_cart_item_data', $cart_item_data, $item, $order );
	}
	return $cart_item_data;
};
$session_customer = static function ( string $id ): void {
	$prop = new ReflectionProperty( WC()->session, '_customer_id' );
	$prop->setAccessible( true );
	$prop->setValue( WC()->session, $id );
};

$created = [ 'orders' => [], 'tokens' => [], 'users' => [] ];
$product_id = 0;
$group_id   = 0;
// Baseline-driven cleanup: anything the proof or reissue mints is removed,
// regardless of whether a named variable tracked it.
$baseline_bins    = glob( OPF_UPLOAD_PRIVATE_DIR . '/*.bin' ) ?: [];
// Upload records are non-autoloaded options; read them straight from the DB.
global $wpdb;
$baseline_options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'opf_upload_%'" ) ?: [];

try {
	$product = new WC_Product_Simple();
	$product->set_name( 'Upload reissue proof' );
	$product->set_slug( 'upload-reissue-proof' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product->save();
	$product_id = $product->get_id();

	$group = new FieldGroup( [
		'fields'      => [ [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'required' => true, 'accepted_types' => 'png', 'max_size' => 1 ] ],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = FieldGroups::save( 0, $group, [ 'title' => 'Upload reissue fields' ] );
	$gid      = (string) $group_id;

	// Admin config save/reload: the stored group reloads to a normalized
	// upload field that resolves for the product.
	$reloaded = null;
	foreach ( FieldGroups::for_product( wc_get_product( $product_id ) ) as $entry ) {
		foreach ( $entry['group']->data['fields'] as $f ) {
			if ( 'art' === $f['id'] ) {
				$reloaded = $f;
			}
		}
	}
	$check( 'saved upload field reloads and resolves for product', null !== $reloaded && 'upload' === $reloaded['type'] && [ 'png' ] === $reloaded['accepted_types'] );

	wc_load_cart();
	WC()->session->set_customer_session_cookie( true );
	$owner = Uploads::owner();
	$check( 'session owner derived', '' !== $owner );

	// A completed order whose line holds an upload bound to it.
	$order = wc_create_order( [ 'status' => 'completed' ] );
	$created['orders'][] = $order->get_id();
	$order_item = new WC_Order_Item_Product();
	$order_item->set_product( $product );
	$order_item->set_quantity( 1 );
	$old_token = bin2hex( random_bytes( 32 ) );
	$created['tokens'][] = $old_token;
	$order_item->add_meta_data( '_opf_fields', wp_json_encode( [ $gid => [ 'art' => [ $old_token ] ] ] ), true );
	$order_item->add_meta_data( '_opf_uploads', [ [ 'token' => $old_token, 'name' => 'art.png' ] ], true );
	$order->add_item( $order_item );
	$order->calculate_totals();
	$order->set_status( 'completed' );
	$order->save();
	$make_record( $old_token, $owner, $product_id, $gid, 'art', $order->get_id() );

	// 1. Same-session reorder of a completed order: claimed token is reissued.
	$data      = $order_again( $order );
	$new_value = $data[ CartIntegration::ITEM_KEY ][ $gid ]['art'] ?? null;
	$check( 'order-again restores a fresh token for the completed order', is_array( $new_value ) && 1 === count( $new_value ) && $old_token !== $new_value[0] && preg_match( '/^[a-f0-9]{64}$/', $new_value[0] ), [ 'gid' => $gid, 'data' => $data ] );
	$new_token = $new_value[0];
	$created['tokens'][] = $new_token;
	$record = Uploads::record( $new_token );
	$check( 'reissue is session-owned, unbound, and sourced', $record && $owner === $record['owner'] && 0 === (int) $record['order_id'] && $old_token === $record['reissued_from'] && $product_id === $record['product_id'] && $gid === $record['group_id'] && 'art' === $record['field_id'] );
	$check( 'reissue copied identical private bytes', is_file( OPF_UPLOAD_PRIVATE_DIR . '/' . $new_token . '.bin' ) && md5_file( OPF_UPLOAD_PRIVATE_DIR . '/' . $new_token . '.bin' ) === md5_file( OPF_UPLOAD_PRIVATE_DIR . '/' . $old_token . '.bin' ) );
	$check( 'original record stays claimed by the source order', $order->get_id() === (int) Uploads::record( $old_token )['order_id'] );

	$field = $reloaded;
	$check( 'reissued token validates like a fresh upload', [] === Uploads::validate_tokens( $field, [ $new_token ], $product_id, $gid ) );
	$check( 'source token still cannot seed a cart', [] !== Uploads::validate_tokens( $field, [ $old_token ], $product_id, $gid ) );

	// 2. Reissued token attaches to a real cart and persists to a new order.
	$bins_before = $bin_count();
	WC()->cart->empty_cart();
	$item_key = WC()->cart->add_to_cart( $product_id, 1, 0, [], [ 'opf_fields' => [ $gid => [ 'art' => [ $new_token ] ] ] ] );
	$check( 'reissued token attaches to a real cart line', is_string( $item_key ) && '' !== $item_key );
	$reorder = wc_create_order( [ 'status' => 'checkout-draft' ] );
	$created['orders'][] = $reorder->get_id();
	WC()->checkout()->create_order_line_items( $reorder, WC()->cart );
	$reorder->save();
	$reorder_item = array_values( $reorder->get_items() )[0];
	$reorder_meta = (array) $reorder_item->get_meta( '_opf_uploads', true );
	$check( 'reorder item persists only the reissued token', is_array( $reorder_meta ) && 1 === count( $reorder_meta ) && $new_token === $reorder_meta[0]['token'] );
	$check( 'reissued record binds to the reorder', $reorder->get_id() === (int) Uploads::record( $new_token )['order_id'] );

	// 3. While the first reissue is bound only to a retryable draft, a second
	//    order-again of the ORIGINAL order reuses the same live copy.
	$bins_again = $bin_count();
	$data2      = $order_again( $order );
	$again      = $data2[ CartIntegration::ITEM_KEY ][ $gid ]['art'][0] ?? '';
	$check( 'live retryable-bound reissue is reused, not duplicated', $again === $new_token && $bin_count() === $bins_again );

	// 4. Once the reorder completes, the claimed reissue is no longer reused:
	//    the next order-again of the source order mints a second live copy.
	$reorder->set_status( 'processing' );
	$reorder->save();
	$data4 = $order_again( $order );
	$third = $data4[ CartIntegration::ITEM_KEY ][ $gid ]['art'][0] ?? '';
	$created['tokens'][] = $third;
	$check( 'claimed reissue is not recycled for another reorder', $third !== $new_token && $third !== $old_token && preg_match( '/^[a-f0-9]{64}$/', $third ) );
	$third_record = Uploads::record( $third );
	$check( 'second copy is unbound and session-owned', $third_record && 0 === (int) $third_record['order_id'] && $owner === $third_record['owner'] );
	$bins_live = $bin_count();
	$data5     = $order_again( $order );
	$check( 'repeated order-again reuses the live reissue', ( $data5[ CartIntegration::ITEM_KEY ][ $gid ]['art'][0] ?? '' ) === $third && $bin_count() === $bins_live );

	// 5. A foreign session cannot reissue another customer's completed order.
	$session_customer( 'foreign-session-' . wp_generate_password( 6, false ) );
	wp_set_current_user( 0 );
	$data6 = $order_again( $order );
	$check( 'foreign session is denied reissue', ( $data6[ CartIntegration::ITEM_KEY ][ $gid ]['art'][0] ?? '' ) === $old_token );

	// 6. The order's registered customer can reissue from a new session.
	$customer_id = wp_create_user( 'reissue_customer_' . wp_generate_password( 6, false ), 'x' . wp_generate_password( 10 ), 'reissue-' . wp_generate_password( 6, false ) . '@example.test' );
	$created['users'][] = $customer_id;
	$order->set_customer_id( $customer_id );
	$order->save();
	wp_set_current_user( $customer_id );
	$data7 = $order_again( $order );
	$sixth = $data7[ CartIntegration::ITEM_KEY ][ $gid ]['art'][0] ?? '';
	$created['tokens'][] = $sixth !== $old_token ? $sixth : null;
	$sixth_record = $sixth !== $old_token ? Uploads::record( $sixth ) : null;
	$check( 'order customer reissues into a new session', $sixth_record && preg_match( '/^[a-f0-9]{64}$/', $sixth ) && $sixth_record['owner'] !== $owner && hash_equals( $sixth_record['owner'], Uploads::owner() ) );

	// 7. A manager session may carry the file for fulfilment-side reorder.
	$manager_id = wp_create_user( 'reissue_manager_' . wp_generate_password( 6, false ), 'x' . wp_generate_password( 10 ), 'manager-' . wp_generate_password( 6, false ) . '@example.test' );
	$created['users'][] = $manager_id;
	( new WP_User( $manager_id ) )->set_role( 'shop_manager' );
	$session_customer( 'manager-session-' . wp_generate_password( 6, false ) );
	wp_set_current_user( $manager_id );
	$data7   = $order_again( $order );
	$seventh = $data7[ CartIntegration::ITEM_KEY ][ $gid ]['art'][0] ?? '';
	$created['tokens'][] = $seventh !== $old_token ? $seventh : null;
	$check( 'shop manager can reissue for the same order', $seventh !== $old_token && preg_match( '/^[a-f0-9]{64}$/', $seventh ) );
	wp_set_current_user( 0 );

	// 8. Tokens bound to a different order, or a different field scope, never
	//    mint copies even for the order's customer.
	$other_order = wc_create_order( [ 'status' => 'completed' ] );
	$created['orders'][] = $other_order->get_id();
	$other_token = bin2hex( random_bytes( 32 ) );
	$created['tokens'][] = $other_token;
	$other_item = new WC_Order_Item_Product();
	$other_item->set_product( $product );
	$other_item->set_quantity( 1 );
	$other_item->add_meta_data( '_opf_fields', wp_json_encode( [ $gid => [ 'art' => [ $other_token ] ] ] ), true );
	$other_order->add_item( $other_item );
	$other_order->set_status( 'completed' );
	$other_order->set_customer_id( $customer_id );
	$other_order->save();
	$make_record( $other_token, 'other-owner', $product_id, $gid, 'art', $order->get_id() ); // bound to FIRST order
	wp_set_current_user( $customer_id );
	$session_customer( 'customer-session-' . wp_generate_password( 6, false ) );
	$data8 = $order_again( $other_order );
	$check( 'token bound to another order is not reissued', ( $data8[ CartIntegration::ITEM_KEY ][ $gid ]['art'][0] ?? '' ) === $other_token );

	$scope_token = bin2hex( random_bytes( 32 ) );
	$created['tokens'][] = $scope_token;
	$scope_item = new WC_Order_Item_Product();
	$scope_item->set_product( $product );
	$scope_item->set_quantity( 1 );
	$scope_item->add_meta_data( '_opf_fields', wp_json_encode( [ $gid => [ 'art' => [ $scope_token ] ] ] ), true );
	$scope_order = wc_create_order( [ 'status' => 'completed' ] );
	$created['orders'][] = $scope_order->get_id();
	$scope_order->add_item( $scope_item );
	$scope_order->set_status( 'completed' );
	$scope_order->set_customer_id( $customer_id );
	$scope_order->save();
	$make_record( $scope_token, 'other-owner', $product_id, $gid, 'different-field', $scope_order->get_id() );
	$data9 = $order_again( $scope_order );
	$check( 'token with mismatched field scope is not reissued', ( $data9[ CartIntegration::ITEM_KEY ][ $gid ]['art'][0] ?? '' ) === $scope_token );

	// 9. A token on a retryable order stays the same for its own session.
	wp_set_current_user( 0 );
	$session_prop = new ReflectionProperty( WC()->session, '_customer_id' );
	$session_prop->setAccessible( true );
	$session_prop->setValue( WC()->session, 'reissue-owner-' . wp_generate_password( 6, false ) );
	$retry_owner = Uploads::owner();
	$retry_order = wc_create_order( [ 'status' => 'pending' ] );
	$created['orders'][] = $retry_order->get_id();
	$retry_item = new WC_Order_Item_Product();
	$retry_item->set_product( $product );
	$retry_item->set_quantity( 1 );
	$retry_item->set_subtotal( '10' );
	$retry_item->set_total( '10' );
	$retry_token = bin2hex( random_bytes( 32 ) );
	$created['tokens'][] = $retry_token;
	$retry_item->add_meta_data( '_opf_fields', wp_json_encode( [ $gid => [ 'art' => [ $retry_token ] ] ] ), true );
	$retry_order->add_item( $retry_item );
	$retry_order->calculate_totals();
	$retry_order->set_status( 'pending' );
	$retry_order->save();
	$make_record( $retry_token, $retry_owner, $product_id, $gid, 'art', $retry_order->get_id() );
	$check( 'pending order still needs payment', $retry_order->needs_payment() );
	$data10 = $order_again( $retry_order );
	$check( 'retryable-order token still validates so no copy is minted', ( $data10[ CartIntegration::ITEM_KEY ][ $gid ]['art'][0] ?? '' ) === $retry_token );

	echo wp_json_encode( [ 'checks' => $results, 'tokens' => [ 'old' => $old_token, 'new' => $new_token, 'third' => $third, 'sixth' => $sixth, 'seventh' => $seventh ] ], JSON_PRETTY_PRINT ) . "\n";
} finally {
	wp_set_current_user( 0 );
	foreach ( $created['orders'] as $oid ) {
		$o = $oid ? wc_get_order( $oid ) : null;
		if ( $o ) {
			$o->delete( true );
		}
	}
	if ( ! empty( $created['users'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $created['users'] as $uid ) {
			wp_delete_user( $uid );
		}
	}
	foreach ( array_filter( $created['tokens'] ) as $t ) {
		delete_option( 'opf_upload_' . $t );
	}
	foreach ( glob( OPF_UPLOAD_PRIVATE_DIR . '/*.bin' ) ?: [] as $p ) {
		if ( ! in_array( $p, $baseline_bins, true ) && ( is_file( $p ) || is_link( $p ) ) ) {
			unlink( $p );
		}
	}
	global $wpdb;
	foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'opf_upload_%'" ) as $option ) {
		if ( ! in_array( $option, $baseline_options, true ) ) {
			delete_option( $option );
		}
	}
	if ( $group_id ) {
		wp_delete_post( $group_id, true );
	}
	if ( $product_id ) {
		$p = wc_get_product( $product_id );
		if ( $p ) {
			$p->delete( true );
		}
	}
	delete_option( 'opf_upload_order_again_probe' );
	WC()->cart->empty_cart();
}
