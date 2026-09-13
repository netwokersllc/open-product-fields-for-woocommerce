<?php
/**
 * PHPUnit bootstrap. Defines the minimal WordPress constants the engine
 * files expect and registers the autoloader. The engine is pure PHP, so no
 * WordPress installation is needed for unit tests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'OPF_DIR' ) ) {
	define( 'OPF_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'OPF_URL' ) ) {
	define( 'OPF_URL', 'http://example.test/wp-content/plugins/open-product-fields-for-woocommerce/' );
}
if ( ! defined( 'OPF_VERSION' ) ) {
	define( 'OPF_VERSION', '0.1.0' );
}
if ( ! defined( 'OPF_FILE' ) ) {
	define( 'OPF_FILE', OPF_DIR . 'open-product-fields-for-woocommerce.php' );
}

require_once OPF_DIR . 'includes/Autoloader.php';
