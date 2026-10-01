<?php
/**
 * Diagnose upload availability before attempting commerce proof.
 * Usage: wp --path=/path/to/disposable-wordpress eval-file bin/probe-upload-availability.php
 * Exits 1 when the prerequisite upload implementation is unavailable.
 */
defined( 'ABSPATH' ) || exit;
if ( ! is_file( ABSPATH . '.opf-disposable-e2e' ) ) {
	WP_CLI::error( 'This probe requires a disposable WordPress install.' );
}
$routes = rest_get_server()->get_routes();
$result = [
	'wordpress' => get_bloginfo( 'version' ),
	'woocommerce' => WC_VERSION,
	'active_plugins' => get_option( 'active_plugins' ),
	'upload_service_exists' => class_exists( 'OPF\\Engine\\UploadService' ),
	'upload_type_supported' => in_array( 'upload', OPF\Engine\FieldGroup::FIELD_TYPES, true ),
	'normalized_upload_type' => OPF\Engine\FieldGroup::normalize_field( [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload' ] )['type'],
	'upload_routes' => array_values( array_filter( array_keys( $routes ), static function ( $route ) {
		return 0 === strpos( $route, '/opf/' ) && false !== strpos( $route, 'upload' );
	} ) ),
];
WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
if ( ! $result['upload_service_exists'] || ! $result['upload_type_supported'] || empty( $result['upload_routes'] ) ) {
	WP_CLI::halt( 1 );
}
