<?php
/**
 * Plugin Name: Open Product Fields for WooCommerce
 * Plugin URI: https://github.com/ssthormess/open-product-fields-for-woocommerce
 * Description: Build custom product fields and add-ons for WooCommerce products — conditional logic, pricing, and a modern form builder. Free and open source.
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

require_once OPF_DIR . 'includes/class-opf-plugin.php';

/**
 * Global plugin instance accessor.
 *
 * @return OPF_Plugin
 */
function opf() {
	return OPF_Plugin::instance();
}

opf();
