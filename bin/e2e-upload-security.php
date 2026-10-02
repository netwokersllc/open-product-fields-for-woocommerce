<?php
/** Disposable runtime only. wp eval-file this.php <order-id>. */
use OPF\Service\Uploads;
if ( ! defined( 'OPF_UPLOAD_PRIVATE_DIR' ) || OPF_UPLOAD_PRIVATE_DIR !== '/tmp/opf-upload-foundation-private' ) throw new RuntimeException( 'Disposable upload runtime required.' );
$checks = [];
$check = static function ( string $name, bool $passed ) use ( &$checks ): void {
	$checks[] = [ 'name' => $name, 'passed' => $passed ];
	if ( ! $passed ) throw new RuntimeException( $name );
};
$owner = Uploads::owner();
$token = bin2hex( random_bytes( 32 ) );
$path = OPF_UPLOAD_PRIVATE_DIR . '/' . $token . '.bin';
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1cAAAAASUVORK5CYII=' );
$record = [ 'owner' => $owner, 'product_id' => 10, 'group_id' => '11', 'field_id' => 'art', 'name' => 'security.png', 'mime' => 'image/png', 'size' => strlen( $png ), 'created' => time(), 'order_id' => 0, 'cart' => false ];
$field = OPF\Engine\FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'label' => 'Artwork', 'multiple' => true ] );
$claiming = null;
$retained_order = null;
file_put_contents( $path, $png ); chmod( $path, 0600 ); add_option( 'opf_upload_' . $token, $record, '', false );
try {
	$checked = Uploads::validate_file( [ 'name' => 'evil"<img>.png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK ], $field );
	$check( 'customer filename removes HTML and header delimiters', ! is_wp_error( $checked ) && ! preg_match( '/["<>\r\n]/', $checked['name'] ) );
	$check( 'malformed multipart file shape rejected', is_wp_error( Uploads::validate_file( [ 'name' => [ 'x.png' ], 'tmp_name' => [ $path ], 'error' => UPLOAD_ERR_OK ], $field ) ) );
	$check( 'file content disguised as pdf rejected', is_wp_error( Uploads::validate_file( [ 'name' => 'fake.pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK ], $field ) ) );
	file_put_contents( $path, str_repeat( 'x', 2 * MB_IN_BYTES ) );
	$check( 'file size limit enforced before type acceptance', is_wp_error( Uploads::validate_file( [ 'name' => 'large.png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK ], $field ) ) );
	file_put_contents( $path, $png ); clearstatcache( true, $path );
	$check( 'owned product/group/field token validates', [] === Uploads::validate_tokens( $field, [ $token ], 10, '11' ) );
	$check( 'cross-product replay rejected', [] !== Uploads::validate_tokens( $field, [ $token ], 99, '11' ) );
	$check( 'cross-group replay rejected', [] !== Uploads::validate_tokens( $field, [ $token ], 10, '99' ) );
	$other = $field; $other['id'] = 'single';
	$check( 'cross-field replay rejected', [] !== Uploads::validate_tokens( $other, [ $token ], 10, '11' ) );
	$record['owner'] = str_repeat( '0', 64 ); update_option( 'opf_upload_' . $token, $record, false );
	$check( 'cross-owner token rejected', [] !== Uploads::validate_tokens( $field, [ $token ], 10, '11' ) );
	$record['owner'] = $owner; $record['created'] = time() - DAY_IN_SECONDS - 1; update_option( 'opf_upload_' . $token, $record, false );
	$check( 'expired token rejected', [] !== Uploads::validate_tokens( $field, [ $token ], 10, '11' ) );
	$record['cart'] = true; update_option( 'opf_upload_' . $token, $record, false );
	$check( 'expired cart token rejected before cron cleanup', [] !== Uploads::validate_tokens( $field, [ $token ], 10, '11' ) );
	$request = new WP_REST_Request( 'GET', '/opf/v1/uploads/' . $token ); $request->set_param( 'token', $token );
	$expired_download = Uploads::download( $request );
	$check( 'expired cart bytes cannot be downloaded before cron cleanup', is_wp_error( $expired_download ) && $expired_download->get_error_data()['status'] === 404 );
	$record['cart'] = false;
	$record['created'] = time();
	$claiming = wc_create_order();
	$claiming->set_status( 'processing' ); $claiming->save();
	$record['order_id'] = $claiming->get_id(); update_option( 'opf_upload_' . $token, $record, false );
	$check( 'live order-bound token replay rejected', [] !== Uploads::validate_tokens( $field, [ $token ], 10, '11' ) );
	$record['order_id'] = 0; update_option( 'opf_upload_' . $token, $record, false );
	$check( 'duplicate token normalizes to one reference', [ $token ] === Uploads::tokens( [ $token, $token ] ) );
	rename( $path, $path . '.saved' ); symlink( '/etc/passwd', $path );
	$check( 'symlink private byte path rejected', [] !== Uploads::validate_tokens( $field, [ $token ], 10, '11' ) );
	unlink( $path ); rename( $path . '.saved', $path );
	$record['created'] = time() - DAY_IN_SECONDS - 1; update_option( 'opf_upload_' . $token, $record, false );
	Uploads::cleanup();
	$check( 'expired temporary bytes and metadata removed', ! is_file( $path ) && null === Uploads::record( $token ) );
	$order_token = bin2hex( random_bytes( 32 ) ); $order_path = OPF_UPLOAD_PRIVATE_DIR . '/' . $order_token . '.bin';
	$retained_order = wc_create_order(); $retained_order->set_status( 'processing' ); $retained_order->save();
	$record['order_id'] = $retained_order->get_id(); file_put_contents( $order_path, 'proof' ); add_option( 'opf_upload_' . $order_token, $record, '', false );
	Uploads::cleanup();
	$check( 'order files retained by scheduled temporary cleanup', is_file( $order_path ) && null !== Uploads::record( $order_token ) );
	unlink( $order_path ); delete_option( 'opf_upload_' . $order_token );
	$root = OPF_UPLOAD_PRIVATE_DIR;
	rename( $root, $root . '-saved' ); symlink( ABSPATH . 'wp-content', $root );
	$rejected = false;
	try { ( new ReflectionMethod( Uploads::class, 'root' ) )->invoke( null ); } catch ( Throwable $error ) { $rejected = true; }
	unlink( $root ); rename( $root . '-saved', $root );
	$check( 'symlink configured root rejected', $rejected );
	$check( 'storage directory private permissions', ( fileperms( $root ) & 0777 ) === 0700 );
	$orphan = $root . '/' . bin2hex( random_bytes( 32 ) ) . '.bin';
	file_put_contents( $orphan, 'orphan' ); touch( $orphan, time() - DAY_IN_SECONDS - 1 );
	Uploads::cleanup();
	$check( 'expired interrupted-move orphan bytes removed', ! is_file( $orphan ) );
	$lock = fopen( $root . '/.upload.lock', 'c' ); flock( $lock, LOCK_EX );
	$pid = pcntl_fork();
	if ( 0 === $pid ) { fclose( $lock ); $other_lock = fopen( $root . '/.upload.lock', 'c' ); $acquired = flock( $other_lock, LOCK_EX | LOCK_NB ); fclose( $other_lock ); exit( $acquired ? 1 : 0 ); }
	pcntl_waitpid( $pid, $status ); flock( $lock, LOCK_UN ); fclose( $lock );
	$check( 'global storage lock excludes competing process', pcntl_wexitstatus( $status ) === 0 );
	echo wp_json_encode( [ 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
} finally {
	foreach ( [ $claiming, $retained_order ] as $order ) {
		if ( $order instanceof WC_Order && $order->get_id() && wc_get_order( $order->get_id() ) ) {
			$order->delete( true );
		}
	}
	if ( isset( $order_path ) && ( is_link( $order_path ) || is_file( $order_path ) ) ) unlink( $order_path );
	if ( isset( $order_token ) ) delete_option( 'opf_upload_' . $order_token );
	if ( is_link( $path ) || is_file( $path ) ) unlink( $path );
	if ( is_file( $path . '.saved' ) ) unlink( $path . '.saved' );
	delete_option( 'opf_upload_' . $token );
}
