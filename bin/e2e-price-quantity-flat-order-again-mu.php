<?php
/** Install only in the guarded disposable qflat clone. */
if ( ! defined( 'ABSPATH' ) || '/tmp/opf-quantity-fee-woo-20261002' !== realpath( ABSPATH ) ) { return; }

$GLOBALS['opf_qfl_suppressed_mail_count'] = 0;
add_filter( 'pre_wp_mail', static function ( $pre, $atts ) {
	$GLOBALS['opf_qfl_suppressed_mail_count']++;
	return true;
}, PHP_INT_MAX, 2 );

add_action( 'init', static function () {
	if ( '127.0.0.1' !== ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) { return; }
	if ( isset( $_GET['qfl_order_again_login'] ) ) {
		$state = get_option( 'opf_qfl_order_again_state' );
		if ( empty( $state['user'] ) ) { return; }
		wp_set_auth_cookie( (int) $state['user'] );
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}
} );

add_action( 'template_redirect', static function () {
	if ( ! isset( $_GET['qfl_cart_probe'] ) || '127.0.0.1' !== ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) { return; }
	if ( ! WC()->cart ) { wc_load_cart(); }
	$lines = [];
	foreach ( WC()->cart->get_cart() as $item ) {
		$lines[] = [
			'product_id' => (int) $item['product_id'],
			'quantity' => (int) $item['quantity'],
			'unit_price_raw' => round( (float) $item['data']->get_price( 'edit' ), 4 ),
			'line_subtotal' => round( (float) ( $item['line_subtotal'] ?? 0 ), 4 ),
			'line_subtotal_tax' => round( (float) ( $item['line_subtotal_tax'] ?? 0 ), 4 ),
			'line_total' => round( (float) ( $item['line_total'] ?? 0 ), 4 ),
			'line_tax' => round( (float) ( $item['line_tax'] ?? 0 ), 4 ),
			'opf_fields' => $item['opf_fields'] ?? null,
			'wapf' => $item['wapf'] ?? null,
		];
	}
	wp_send_json( [ 'authenticated' => is_user_logged_in(), 'user_id' => get_current_user_id(), 'lines' => $lines ] );
} );
