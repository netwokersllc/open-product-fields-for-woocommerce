<?php
/**
 * Minimal autoloader for the OPF\ namespace (no composer needed at runtime).
 *
 * @package open-product-fields-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'OPF\\' ) ) {
			return;
		}
		$rel  = str_replace( '\\', '/', substr( $class, 4 ) );
		$path = OPF_DIR . 'includes/' . $rel . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
