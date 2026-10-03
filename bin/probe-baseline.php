<?php
defined( 'ABSPATH' ) || exit;
$b = [
	'products'    => [],
	'groups'      => [],
	'users'       => count( get_users( [ 'fields' => 'ID' ] ) ),
	'orders'      => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ),
	'attachments' => (int) wp_count_posts( 'attachment' )->inherit,
	'tax_rates'   => (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->prefix . 'woocommerce_tax_rates' ),
	'plugin_list' => array_keys( get_plugins() ),
	'active'      => get_option( 'active_plugins', [] ),
];
foreach ( array_keys( get_post_stati() ) as $s ) {
	$b['products'][ $s ] = (int) wp_count_posts( 'product' )->$s;
	$b['groups'][ $s ]   = (int) wp_count_posts( 'opf_field_group' )->$s;
}
unset( $b['products']['auto-draft'], $b['groups']['auto-draft'] );
$path = getenv( 'OPF_PRICEB_BASELINE' ) ?: '/tmp/opf-lane-priceb-evidence/baseline.json';
file_put_contents( $path, wp_json_encode( $b, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
echo wp_json_encode( $b, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
