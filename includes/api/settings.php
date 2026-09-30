<?php
/** Public OPF setting helpers. */

defined( 'ABSPATH' ) || exit;

use OPF\Engine\DateFormat;

if ( ! function_exists( 'opf_has_setting' ) ) {
	/** Whether a setting belongs to OPF's public runtime/settings contract. */
	function opf_has_setting( string $name = '' ): bool {
		return array_key_exists( $name, opf_api_settings() );
	}
}

if ( ! function_exists( 'opf_get_setting' ) ) {
	/** Read an OPF setting or runtime value, using fallback when the key is unknown. */
	function opf_get_setting( string $name, $fallback = null ) {
		$settings = opf_api_settings();
		$value    = array_key_exists( $name, $settings ) ? $settings[ $name ] : $fallback;
		if ( in_array( $name, [ 'opf_date_format', 'opf_theme_compat', 'opf_admin_only', 'opf_show_totals', 'opf_compat_i18n' ], true ) ) {
			$value = get_option( $name, $value );
		}
		return function_exists( 'apply_filters' ) ? apply_filters( 'opf/setting/' . $name, $value ) : $value;
	}
}

if ( ! function_exists( 'opf_api_settings' ) ) {
	/** @return array<string,mixed> */
	function opf_api_settings(): array {
		$basename = defined( 'OPF_FILE' )
			? ( function_exists( 'plugin_basename' ) ? plugin_basename( OPF_FILE ) : basename( dirname( OPF_FILE ) ) . '/' . basename( OPF_FILE ) )
			: '';
		return [
			'name'             => 'Open Product Fields for WooCommerce',
			'version'          => defined( 'OPF_VERSION' ) ? OPF_VERSION : '',
			'slug'             => 'open-product-fields-for-woocommerce',
			'basename'         => $basename,
			'path'              => defined( 'OPF_DIR' ) ? OPF_DIR : '',
			'url'               => defined( 'OPF_URL' ) ? OPF_URL : '',
			'capability'        => 'manage_woocommerce',
			'cpts'              => [ 'opf_field_group' ],
			'opf_date_format'   => DateFormat::DEFAULT_FORMAT,
			'opf_theme_compat'  => 'yes',
			'opf_admin_only'    => 'no',
			'opf_show_totals'   => 'no',
			'opf_compat_i18n'   => [],
		];
	}
}
