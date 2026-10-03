<?php
/** Loopback login for disposable URL cart fixture only. */
if (0 !== strpos(realpath(ABSPATH), '/tmp/opf-url-cart-') || !defined('FQDB') || 0 !== strpos(realpath(FQDB), realpath(ABSPATH) . '/')) { return; }
add_filter('pre_wp_mail', '__return_true');
add_action('init', static function () {
    $state = get_option('opf_url_cart_visual_state');
    if (isset($_GET['opf_url_cart_login']) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) && !empty($state['user'])) {
        wp_set_auth_cookie($state['user']);
        wp_safe_redirect(get_permalink($state['product']));
        exit;
    }
});
