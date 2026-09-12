<?php
/**
 * Uninstall routine. Only runs when the plugin is deleted via WP admin.
 *
 * @package open-product-fields-for-woocommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin options. Field group and value data lives in its own
// tables/post types and is intentionally preserved unless OPF_KEEP_DATA
// is not set — revisit once the data model exists.
delete_option( 'opf_version' );
