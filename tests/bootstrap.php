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

require_once OPF_DIR . 'includes/Autoloader.php';
