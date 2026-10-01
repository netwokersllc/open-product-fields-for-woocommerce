<?php
/** Isolated PHP 7.1 upload smoke checks; adapters below are not commerce proof. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'assets' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );
define( 'OPF_UPLOAD_PRIVATE_DIR', '/tmp/opf-upload-php71-private' );
class WP_Error {
	public $code;
	public function __construct( $code, $message, $data = [] ) { $this->code = $code; }
}
function __( $message, $domain ) { return $message; }
function wp_max_upload_size() { return 2 * MB_IN_BYTES; }
function sanitize_file_name( $name ) { return preg_replace( '/[^a-zA-Z0-9._-]/', '', $name ); }
function get_allowed_mime_types() { return [ 'png' => 'image/png', 'pdf' => 'application/pdf' ]; }
function wp_check_filetype_and_ext( $path, $name, $mimes ) {
	$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	return [ 'ext' => isset( $mimes[ $ext ] ) ? $ext : false, 'type' => $mimes[ $ext ] ?? false ];
}
require ABSPATH . 'includes/Service/Uploads.php';
$checks = [];
$check = static function ( $name, $passed ) use ( &$checks ) {
	$checks[] = [ 'name' => $name, 'passed' => (bool) $passed ];
	if ( ! $passed ) throw new RuntimeException( $name );
};
$check( 'upload class loads on this PHP runtime', class_exists( OPF\Service\Uploads::class ) );
$check( 'single upload limit', OPF\Service\Uploads::max_files( [] ) === 1 );
$check( 'multiple limit follows PHP configuration', OPF\Service\Uploads::max_files( [ 'multiple' => true ] ) === max( 1, (int) ini_get( 'max_file_uploads' ) ) );
$token = str_repeat( 'a', 64 );
$check( 'duplicate opaque tokens normalize', OPF\Service\Uploads::tokens( [ $token, $token ] ) === [ $token ] );
$check( 'malformed optional token remains invalid', OPF\Service\Uploads::tokens( [ [ 'path' => '/etc/passwd' ] ] ) === [ 'invalid' ] );
$root_method = new ReflectionMethod( OPF\Service\Uploads::class, 'root' );
$root_method->setAccessible( true );
$root = $root_method->invoke( null );
$check( 'private storage root permissions', ( fileperms( $root ) & 0777 ) === 0700 );
$path = $root . '/probe.bin';
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1cAAAAASUVORK5CYII=' );
file_put_contents( $path, $png );
$field = [ 'max_size' => 1, 'accepted_types' => [ 'png' ] ];
$file = [ 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => 'proof.png' ];
$checked = OPF\Service\Uploads::validate_file( $file, $field );
$check( 'actual finfo accepts PNG bytes with MIME adapters', is_array( $checked ) && $checked['mime'] === 'image/png' );
file_put_contents( $path, '<?php echo 1;' ); clearstatcache( true, $path );
$check( 'actual finfo rejects spoofed PNG bytes', OPF\Service\Uploads::validate_file( $file, $field ) instanceof WP_Error );
file_put_contents( $path, str_repeat( 'x', 2 * MB_IN_BYTES ) ); clearstatcache( true, $path );
$error = OPF\Service\Uploads::validate_file( $file, $field );
$check( 'size limit rejects oversized bytes', $error instanceof WP_Error && 'opf_upload_size' === $error->code );
unlink( $path ); rmdir( $root );
echo json_encode( [ 'php' => PHP_VERSION, 'adapters' => true, 'checks' => $checks ], JSON_PRETTY_PRINT ), "\n";
