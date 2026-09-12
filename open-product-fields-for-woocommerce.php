<?php
/**
 * Plugin Name: Open Product Fields for WooCommerce
 * Plugin URI: https://github.com/netwokersllc/open-product-fields-for-woocommerce
 * Description: Build custom product fields and add-ons for WooCommerce — conditional logic, server-side pricing, and first-class block checkout support. Free and open source.
 * Version: 0.1.0
 * Author: ssthormess
 * Author URI: https://github.com/ssthormess
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: opf
 * Domain Path: /languages
 * Requires at least: 6.5
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 11.1
 *
 * Copyright (C) 2026 ssthormess
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

defined( 'ABSPATH' ) || exit;

define( 'OPF_VERSION', '0.1.0' );
define( 'OPF_FILE', __FILE__ );
define( 'OPF_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPF_URL', plugin_dir_url( __FILE__ ) );

require_once OPF_DIR . 'includes/Autoloader.php';

use OPF\Service\Admin\Builder;
use OPF\Service\Admin\ImportPage;
use OPF\Service\Assets;
use OPF\Service\CartIntegration;
use OPF\Service\Cli;
use OPF\Service\FieldGroups;
use OPF\Service\Importer;
use OPF\Service\Renderer;
use OPF\Service\Rest;

/**
 * Wire the plugin up. Rendering and cart logic are only hooked when
 * WooCommerce is active.
 */
function opf_boot(): void {
	FieldGroups::init();

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'opf_wc_missing_notice' );
		return;
	}

	Renderer::init();
	CartIntegration::init();
	Assets::init();
	Rest::init();
	Importer::init();
	Builder::init();
	ImportPage::init();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		Cli::init();
	}

	// Declare compatibility with WooCommerce feature sets.
	add_action(
		'before_woocommerce_init',
		static function () {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
			}
		}
	);
}
opf_boot();

/**
 * WooCommerce missing notice.
 */
function opf_wc_missing_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Open Product Fields requires WooCommerce to be installed and active.', 'opf' );
	echo '</p></div>';
}
