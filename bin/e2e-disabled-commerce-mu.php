<?php
/** Copy to the isolated clone's wp-content/mu-plugins only. */
if ( 0 !== strpos( realpath( ABSPATH ), '/tmp/opf-disabled-roundtrip-wp' ) ) { return; }
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_action( 'init', static function () {
	if ( isset( $_GET['disabled_proof_login'] ) && '127.0.0.1' === ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) {
		$state = get_option( 'opf_disabled_lifecycle_state' );
		wp_set_auth_cookie( $state['user'] );
		wp_safe_redirect( admin_url( 'post.php?post=' . $state['wapf'] . '&action=edit' ) );
		exit;
	}
} );
