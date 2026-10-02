<?php
/**
 * Disposable runtime only: wp eval-file bin/e2e-upload-order-rebind.php
 *
 * Proves the checkout-retry binding contract for private uploads:
 * a token bound to a retryable order (checkout-draft / needs_payment) must
 * remain attachable by its owner, while a token bound to a live order stays
 * claimed. Run on the isolated clone only.
 */
use OPF\Engine\FieldGroup;
use OPF\Service\FieldGroups;
use OPF\Service\Uploads;

if ( ! defined( 'OPF_UPLOAD_PRIVATE_DIR' ) || '/tmp/opf-upload-security-private' !== OPF_UPLOAD_PRIVATE_DIR ) {
	throw new RuntimeException( 'Disposable upload runtime required.' );
}

$results = [];
$check   = static function ( string $name, bool $passed ) use ( &$results ): void {
	$results[] = [ 'check' => $name, 'passed' => $passed ];
	if ( ! $passed ) {
		echo wp_json_encode( $results, JSON_PRETTY_PRINT ) . "\n";
		throw new RuntimeException( $name );
	}
};

$png   = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1cAAAAASUVORK5CYII=' );
$token = bin2hex( random_bytes( 32 ) );
$path  = OPF_UPLOAD_PRIVATE_DIR . '/' . $token . '.bin';

$product = new WC_Product_Simple();
$product->set_name( 'Upload rebind proof' );
$product->set_slug( 'upload-rebind-proof' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product->set_virtual( true );
$product->save();
$product_id = $product->get_id();

$group = new FieldGroup(
	[
		'fields'      => [
			[ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'required' => true, 'accepted_types' => 'png', 'max_size' => 1 ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	]
);
$group_id = FieldGroups::save( 0, $group, [ 'title' => 'Upload rebind fields' ] );
$field    = null;
foreach ( FieldGroups::for_product( $product ) as $entry ) {
	if ( (int) $entry['id'] === (int) $group_id ) {
		foreach ( $entry['group']->data['fields'] as $f ) {
			if ( 'art' === $f['id'] ) {
				$field = $f;
			}
		}
	}
}
$check( 'upload field resolves for product', null !== $field );

try {
	wc_load_cart();
	WC()->session->set_customer_session_cookie( true );
	$owner = Uploads::owner();
	$check( 'session owner derived', '' !== $owner );

	file_put_contents( $path, $png );
	chmod( $path, 0600 );
	add_option(
		'opf_upload_' . $token,
		[
			'name'       => 'art.png',
			'mime'       => 'image/png',
			'size'       => strlen( $png ),
			'owner'      => $owner,
			'product_id' => $product_id,
			'group_id'   => (string) $group_id,
			'field_id'   => 'art',
			'created'    => time(),
			'order_id'   => 0,
			'cart'       => true,
		],
		'',
		false
	);

	$check( 'fresh token validates', [] === Uploads::validate_tokens( $field, [ $token ], $product_id, (string) $group_id ) );

	// Simulate the real Store API draft build: line items created against an
	// unsaved checkout-draft order, exactly like OrderController::create_order_from_cart.
	$order = new WC_Order();
	$order->set_status( 'checkout-draft' );
	WC()->cart->empty_cart();
	$item_key = WC()->cart->add_to_cart(
		$product_id,
		1,
		0,
		[],
		[ 'opf_fields' => [ (string) $group_id => [ 'art' => [ $token ] ] ] ]
	);
	$check( 'token attaches to cart item', is_string( $item_key ) && '' !== $item_key );

	WC()->checkout()->create_order_line_items( $order, WC()->cart );
	$order->save();
	$order_id = $order->get_id();
	$check( 'draft order created with line items', $order_id > 0 && count( $order->get_items() ) === 1 );

	$record = Uploads::record( $token );
	$check( 'draft bind recorded on token', (int) $record['order_id'] === $order_id );

	$order_item = reset( $order->get_items() );
	$meta       = (array) $order_item->get_meta( '_opf_uploads', true );
	$check( 'order item keeps hidden upload reference', is_array( $meta ) && $meta[0]['token'] === $token );

	// The draft flips to pending before payment (Checkout.php process_order),
	// after totals are calculated — needs_payment() only holds above $0.
	$order->calculate_totals();
	$order->set_status( 'pending' );
	$order->save();
	$check( 'pending order still needs payment', $order->needs_payment() );

	// Retry surface 1: checkout revalidation over the same cart line.
	wc_clear_notices();
	do_action( 'woocommerce_check_cart_items' );
	$notices = wc_get_notices( 'error' );
	$check( 'checkout retry accepts token bound to retryable order', [] === $notices );
	wc_clear_notices();

	// Retry surface 2: the same token validates for a rebuilt draft item.
	$check( 'token bound to pending order revalidates', [] === Uploads::validate_tokens( $field, [ $token ], $product_id, (string) $group_id ) );

	// Retry surface 3: draft item resync keeps the hidden reference (same order).
	$replacement = new WC_Order_Item_Product();
	$replacement->set_product( $product );
	$replacement->set_quantity( 1 );
	Uploads::persist( [ (string) $group_id => [ 'art' => [ $token ] ] ], $replacement, $order );
	$check( 'draft resync preserves hidden upload reference', (bool) $replacement->get_meta( '_opf_uploads', true ) );

	// Rebind to a fresh draft after the old attempt is abandoned.
	$order->delete( true );
	$next = new WC_Order();
	$next->set_status( 'checkout-draft' );
	$next->save();
	$next_item = new WC_Order_Item_Product();
	$next_item->set_product( $product );
	$next_item->set_quantity( 1 );
	Uploads::persist( [ (string) $group_id => [ 'art' => [ $token ] ] ], $next_item, $next );
	$record = Uploads::record( $token );
	$check( 'token rebinds to replacement draft', (int) $record['order_id'] === $next->get_id() );

	// A live order still claims the file: it cannot seed a different order.
	$next->set_status( 'processing' );
	$next->save();
	$check( 'processing-order token stays claimed', [] !== Uploads::validate_tokens( $field, [ $token ], $product_id, (string) $group_id ) );

	$third_item = new WC_Order_Item_Product();
	$third      = new WC_Order();
	$third->set_status( 'checkout-draft' );
	$third->save();
	$third_item->set_product( $product );
	$third_item->set_quantity( 1 );
	Uploads::persist( [ (string) $group_id => [ 'art' => [ $token ] ] ], $third_item, $third );
	$record = Uploads::record( $token );
	$check( 'live-order token is not rebound to another order', (int) $record['order_id'] === $next->get_id() && ! $third_item->get_meta( '_opf_uploads', true ) );

	// Order-claim ACL: only the session owner, the bound-order customer, or a
	// manager may download; an unrelated session + user must not.
	$created_users = [];
	$created_users[] = $customer_id = wp_create_user( 'rebind_customer_' . wp_generate_password( 6, false ), 'x' . wp_generate_password( 10 ), 'rebind-' . wp_generate_password( 6, false ) . '@example.test' );
	$next->set_customer_id( $customer_id );
	$next->save();
	$session_prop = new ReflectionProperty( WC()->session, '_customer_id' );
	$session_prop->setAccessible( true );
	$session_prop->setValue( WC()->session, 'other-session-' . wp_generate_password( 8, false ) );
	$created_users[] = wp_create_user( 'rebind_other_' . wp_generate_password( 6, false ), 'x' . wp_generate_password( 10 ), 'other-' . wp_generate_password( 6, false ) . '@example.test' );
	wp_set_current_user( end( $created_users ) );
	$request = new WP_REST_Request( 'GET', '/opf/v1/uploads/' . $token );
	$request->set_param( 'token', $token );
	$response = Uploads::download( $request );
	$check( 'unrelated customer cannot download order file', is_wp_error( $response ) && 404 === $response->get_error_data()['status'] );
	wp_set_current_user( $customer_id );
	$response = Uploads::download( $request );
	$check( 'order customer can download bound file', ! is_wp_error( $response ) && 200 === $response->get_status() );
	wp_set_current_user( 0 );

	echo wp_json_encode( [ 'checks' => $results ], JSON_PRETTY_PRINT ) . "\n";
} finally {
	if ( isset( $order_id ) ) {
		foreach ( [ $order_id, isset( $next ) ? $next->get_id() : 0, isset( $third ) ? $third->get_id() : 0 ] as $oid ) {
			$o = $oid ? wc_get_order( $oid ) : null;
			if ( $o ) {
				$o->delete( true );
			}
		}
	}
	if ( ! empty( $created_users ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $created_users as $uid ) {
			wp_delete_user( $uid );
		}
	}
	if ( isset( $group_id ) ) {
		wp_delete_post( $group_id, true );
	}
	if ( isset( $product_id ) ) {
		$p = wc_get_product( $product_id );
		if ( $p ) {
			$p->delete( true );
		}
	}
	if ( is_file( $path ) || is_link( $path ) ) {
		unlink( $path );
	}
	delete_option( 'opf_upload_' . $token );
}
