<?php
/** Install only in the guarded disposable qflat clone. */
if ( ! defined( 'ABSPATH' ) || '/tmp/opf-quantity-fee-woo-20261002' !== realpath( ABSPATH ) ) { return; }

$GLOBALS['opf_qfl_suppressed_mail_count'] = 0;
add_filter( 'pre_wp_mail', static function ( $pre, $atts ) {
	$GLOBALS['opf_qfl_suppressed_mail_count']++;
	$state = get_option( 'opf_qfl_order_again_state' );
	if ( is_array( $state ) && ! empty( $state['run_id'] ) ) {
		$state['suppressed_mail_calls_browser'] = (int) ( $state['suppressed_mail_calls_browser'] ?? 0 ) + 1;
		update_option( 'opf_qfl_order_again_state', $state );
	}
	return true;
}, PHP_INT_MAX, 2 );

add_action( 'init', static function () {
	if ( '127.0.0.1' !== ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) { return; }
	if ( isset( $_GET['qfl_order_again_login'], $_GET['engine'], $_GET['token'] ) ) {
		$state = get_option( 'opf_qfl_order_again_state' );
		$engine = sanitize_key( wp_unslash( $_GET['engine'] ) );
		$token = (string) wp_unslash( $_GET['token'] );
		if ( empty( $state['user'] ) || time() - strtotime( $state['created_at_utc'] ?? '1970-01-01' ) > 900 || ! in_array( $engine, [ 'wapf', 'opf' ], true ) || empty( $state['login_tokens'][ $engine ] ) || ! hash_equals( $state['login_tokens'][ $engine ], $token ) ) { return; }
		unset( $state['login_tokens'][ $engine ] );
		update_option( 'opf_qfl_order_again_state', $state );
		wp_set_auth_cookie( (int) $state['user'] );
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}
} );

add_action( 'template_redirect', static function () {
	if ( ! isset( $_GET['qfl_cart_probe'], $_GET['engine'], $_GET['token'] ) || '127.0.0.1' !== ( $_SERVER['REMOTE_ADDR'] ?? '' ) || ! is_user_logged_in() ) { return; }
	$state = get_option( 'opf_qfl_order_again_state' );
	$engine = sanitize_key( wp_unslash( $_GET['engine'] ) );
	$token = (string) wp_unslash( $_GET['token'] );
	if ( get_current_user_id() !== (int) ( $state['user'] ?? 0 ) || time() - strtotime( $state['created_at_utc'] ?? '1970-01-01' ) > 900 || ! in_array( $engine, [ 'wapf', 'opf' ], true ) || empty( $state['probe_tokens'][ $engine ] ) || ! hash_equals( $state['probe_tokens'][ $engine ], $token ) ) { return; }
	unset( $state['probe_tokens'][ $engine ] );
	update_option( 'opf_qfl_order_again_state', $state );
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
