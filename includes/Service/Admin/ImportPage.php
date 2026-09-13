<?php
/**
 * "Import WAPF Fields" screen is registered by Importer; this class exists to
 * keep that coupling explicit and to add a dashboard link on the plugin row.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service\Admin;

defined( 'ABSPATH' ) || exit;

final class ImportPage {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( OPF_FILE ), [ __CLASS__, 'action_links' ] );
	}

	/**
	 * Add an Import link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		$url = admin_url( 'tools.php?page=opf-import' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Import WAPF', 'open-product-fields-for-woocommerce' ) . '</a>' );
		return $links;
	}
}
