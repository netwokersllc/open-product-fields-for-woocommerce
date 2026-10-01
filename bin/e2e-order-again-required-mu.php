<?php
/** Install only in the guarded disposable clone used by this proof. */
if ( ! defined( 'ABSPATH' ) || '/tmp/opf-order-again-required-wp' !== realpath( ABSPATH ) ) { return; }
add_filter( 'pre_wp_mail', '__return_true' );
add_action( 'init', static function () {
	if ( isset( $_GET['opf_order_again_source'] ) && '127.0.0.1' === ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) {
		$source = ( new ReflectionClass( OPF\Service\CartIntegration::class ) )->getFileName();
		wp_send_json( [ 'utc' => gmdate( 'c' ), 'site' => site_url(), 'source' => $source, 'sha256' => hash_file( 'sha256', $source ), 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'database' => defined( 'FQDB' ) ? FQDB : null ] );
	}
	if ( isset( $_GET['opf_order_again_login'] ) && '127.0.0.1' === ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) {
		$state = get_option( 'opf_order_again_fixture' );
		if ( empty( $state['user'] ) ) { return; }
		wp_set_auth_cookie( $state['user'] );
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}
} );
