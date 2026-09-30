<?php
/**
 * Asset loading. Zero bytes on any page that doesn't render fields.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_frontend' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'register_admin' ] );
	}

	/**
	 * Register (not enqueue) frontend assets; Renderer enqueues on render.
	 */
	public static function register_frontend(): void {
		$ver = OPF_VERSION;

		if ( function_exists( 'wp_register_script_module' ) ) {
			wp_register_script_module(
				'opf-frontend',
				OPF_URL . 'assets/js/opf-frontend.js',
				[
					[
						'id'     => '@wordpress/interactivity',
						'import' => 'static',
					],
				],
				$ver
			);
		} else {
			wp_register_script( 'opf-frontend', OPF_URL . 'assets/js/opf-frontend.js', [], $ver, true );
		}

		wp_register_style( 'opf-frontend', OPF_URL . 'assets/css/opf-frontend.css', [], $ver );
	}

	/**
	 * Enqueue what the renderer needs. Only called when fields exist on page.
	 *
	 * @param array<string,mixed> $registry Field metadata for the client.
	 */
	public static function enqueue_frontend( array $registry = [] ): void {
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( 'opf-frontend' );
		} else {
			wp_enqueue_script( 'opf-frontend' );
		}
		// Script modules bypass wp_scripts, so wp_add_inline_script() is a
		// no-op here. Classic inline scripts execute immediately — before the
		// deferred module — which is exactly the ordering the registry needs.
		if ( $registry ) {
			$date_format = function_exists( 'get_option' ) ? get_option( 'wapf_date_format', 'mm-dd-yyyy' ) : 'mm-dd-yyyy';
			$today       = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
			$display_options = self::compat_config()['display_options'];
			$hints       = [
				'show'   => 'yes' === get_option( 'opf_show_pricing_hints', get_option( 'wapf_show_pricing_hints', 'yes' ) ),
				'format' => self::pricing_hint_format(),
			];
			wp_print_inline_script_tag(
				'window.OPF_FIELDS = ' . wp_json_encode( $registry, JSON_UNESCAPED_UNICODE ) . ';'
				. 'window.OPF_DATE_FORMAT = ' . wp_json_encode( $date_format ) . ';'
				. 'window.OPF_TODAY = ' . wp_json_encode( $today ) . ';'
				. 'window.OPF_PRICE_DISPLAY = ' . wp_json_encode( $display_options, JSON_UNESCAPED_UNICODE ) . ';'
				. 'window.OPF_PRICE_HINTS = ' . wp_json_encode( $hints, JSON_UNESCAPED_UNICODE ) . ';'
			);
		}
		if ( Renderer::compat() ) {
			// Theme integration reads this global for price formatting (opf_config; wapf_config fallback lives in the theme JS).
			wp_print_inline_script_tag(
				'window.opf_config = ' . wp_json_encode( self::compat_config() ) . ';'
			);
		}
		wp_enqueue_style( 'opf-frontend' );
	}

	/** Read the sanitized, bounded storefront price-hint format. */
	private static function pricing_hint_format(): string {
		$format = get_option( 'opf_pricing_hint_format', get_option( 'wapf_hint_format', '(+{x})' ) );
		if ( ! is_string( $format ) || strlen( $format ) > 100 || 1 !== substr_count( $format, '{x}' ) ) {
			return '(+{x})';
		}
		return $format;
	}

	/**
	 * Subset of the legacy pricing-format config the theme integration reads.
	 */
	private static function compat_config(): array {
		$display_options = [
			'symbol'      => get_woocommerce_currency_symbol(),
			'thousand'    => wc_get_price_thousand_separator(),
			'decimal'     => wc_get_price_decimal_separator(),
			'decimals'    => wc_get_price_decimals(),
			'price_format' => str_replace( array( '%1$s', '%2$s' ), array( 'symbol', 'price' ), get_woocommerce_price_format() ),
			'format'      => str_replace( array( '%1$s', '%2$s' ), array( '{symbol}', '{price}' ), get_woocommerce_price_format() ),
			'trim_zeroes' => function_exists( 'apply_filters' ) ? (bool) apply_filters( 'woocommerce_price_trim_zeros', false ) : false,
		];
		$display_options = array_replace( $display_options, Integrations\Woocs::display_options() );
		return [
			'ajax'            => admin_url( 'admin-ajax.php' ),
			'currency'        => get_woocommerce_currency(),
			'display_options' => $display_options,
		];
	}

	/**
	 * Admin assets for the builder screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function register_admin( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, [ 'opf_field_group' ], true ) || ! str_contains( $hook, 'post.php' ) && ! str_contains( $hook, 'post-new.php' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'opf-builder', OPF_URL . 'assets/css/opf-builder.css', [], OPF_VERSION );
		wp_enqueue_script( 'opf-builder', OPF_URL . 'assets/js/opf-builder.js', [ 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n' ], OPF_VERSION, true );
	}
}
