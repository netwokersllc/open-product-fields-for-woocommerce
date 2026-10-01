<?php
/** Disposable fixtures for the native WooCommerce coupon editor browser proof. */

defined( 'ABSPATH' ) || exit;

if ( '1' !== getenv( 'OPF_COUPON_E2E_ALLOW' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || '127.0.0.1' !== wp_parse_url( home_url(), PHP_URL_HOST ) || ! defined( 'SQLITE_DB_DROPIN_VERSION' ) ) {
	throw new RuntimeException( 'Coupon admin proof requires explicit opt-in and disposable loopback SQLite WordPress.' );
}

$mode = getenv( 'OPF_COUPON_ADMIN_MODE' );
if ( 'create' === $mode ) {
	$suffix = strtolower( wp_generate_password( 10, false, false ) );
	$password = wp_generate_password( 32, false, false );
	$username = 'opf-coupon-' . $suffix;
	$user_id = wp_insert_user( [ 'user_login' => $username, 'user_pass' => $password, 'user_email' => $username . '@example.test', 'role' => 'administrator' ] );
	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( 'Could not create coupon editor fixture user.' );
	}
	$coupon = new WC_Coupon();
	$coupon->set_code( 'opf-admin-' . $suffix );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( 10 );
	$coupon->save();
	WP_CLI::log( wp_json_encode( [ 'url' => home_url(), 'user_id' => $user_id, 'username' => $username, 'password' => $password, 'coupon_id' => $coupon->get_id() ] ) );
} elseif ( 'inspect' === $mode ) {
	$coupon = new WC_Coupon( (int) getenv( 'OPF_COUPON_ADMIN_ID' ) );
	WP_CLI::log( wp_json_encode( [ 'type' => $coupon->get_discount_type(), 'meta' => $coupon->get_meta( 'wapf_excl_addons' ) ] ) );
} elseif ( 'cleanup' === $mode ) {
	$coupon = new WC_Coupon( (int) getenv( 'OPF_COUPON_ADMIN_ID' ) );
	if ( $coupon->get_id() && 0 === strpos( $coupon->get_code(), 'opf-admin-' ) ) {
		$coupon->delete( true );
	}
	$user = get_user_by( 'id', (int) getenv( 'OPF_COUPON_ADMIN_USER_ID' ) );
	if ( $user && 0 === strpos( $user->user_login, 'opf-coupon-' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user->ID );
	}
	WP_CLI::success( 'Coupon editor fixtures removed.' );
} else {
	throw new RuntimeException( 'Select create, inspect, or cleanup mode.' );
}
