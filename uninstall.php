<?php
/**
 * Uninstall routine. Only runs when the plugin is deleted via WP admin.
 *
 * Removes plugin data. Field group posts are deleted; order item meta
 * (`_opf_fields` and label metas) is intentionally preserved — historical
 * order data must survive plugin removal.
 *
 * @package open-product-fields-for-woocommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'opf_version' );

$groups = get_posts(
	[
		'post_type'      => 'opf_field_group',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	]
);

foreach ( $groups as $group_id ) {
	wp_delete_post( $group_id, true );
}
