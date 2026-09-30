<?php
/** WooCommerce settings for OPF-wide presentation behavior. */

namespace OPF\Service\Admin;

use OPF\Engine\DateFormat;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public static function init(): void {
		add_filter( 'woocommerce_get_sections_products', [ __CLASS__, 'add_product_fields_section' ] );
		add_filter( 'woocommerce_get_settings_products', [ __CLASS__, 'product_fields_settings' ], 10, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_opf_date_format', [ __CLASS__, 'sanitize_date_format' ], 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_opf_pricing_hint_format', [ __CLASS__, 'sanitize_pricing_hint_format' ], 10, 3 );
	}

	/** @param array<string,string> $sections Product settings sections. */
	public static function add_product_fields_section( array $sections ): array {
		$sections['opf_product_fields'] = __( 'Product fields', 'open-product-fields-for-woocommerce' );
		return $sections;
	}

	/** @return array<int,array<string,mixed>> */
	public static function product_fields_settings( array $settings, string $current_section ): array {
		if ( 'opf_product_fields' !== $current_section ) {
			return $settings;
		}
		return [
			[
				'title' => __( 'Open Product Fields', 'open-product-fields-for-woocommerce' ),
				'type' => 'title',
				'desc' => __( 'Configure how product field dates and price hints appear.', 'open-product-fields-for-woocommerce' ),
				'id' => 'opf_product_fields',
			],
			[
				'title' => __( 'Show price hints', 'open-product-fields-for-woocommerce' ),
				'desc' => __( 'Show the price added by each priced field or choice beside its label.', 'open-product-fields-for-woocommerce' ),
				'id' => 'opf_show_pricing_hints',
				'type' => 'checkbox',
				'default' => 'yes',
				'autoload' => false,
			],
			[
				'title' => __( 'Price hint format', 'open-product-fields-for-woocommerce' ),
				'desc' => __( 'Use {x} where the amount should appear. Example: (+{x}).', 'open-product-fields-for-woocommerce' ),
				'id' => 'opf_pricing_hint_format',
				'type' => 'text',
				'default' => '(+{x})',
				'autoload' => false,
			],
			[
				'title' => __( 'Date format', 'open-product-fields-for-woocommerce' ),
				'desc' => __( 'Use mm, m, dd, d, yyyy, and yy once each by component. Examples: mm-dd-yyyy, d/m/yy, or yyyy.mm.dd.', 'open-product-fields-for-woocommerce' ),
				'id' => 'opf_date_format',
				'type' => 'text',
				'default' => DateFormat::DEFAULT_FORMAT,
				'autoload' => false,
			],
			[ 'type' => 'sectionend', 'id' => 'opf_product_fields' ],
		];
	}

	/** Keep the last valid display format if the settings form posts an invalid one. */
	public static function sanitize_date_format( $value, array $option = [], $raw_value = null ) {
		if ( is_string( $value ) && DateFormat::is_valid( $value ) ) {
			return DateFormat::normalize( $value );
		}
		return DateFormat::normalize( get_option( 'opf_date_format', get_option( 'wapf_date_format', DateFormat::DEFAULT_FORMAT ) ) );
	}

	/** Preserve a valid custom format and require a single amount placeholder. */
	public static function sanitize_pricing_hint_format( $value, array $option = [], $raw_value = null ) {
		$format = is_scalar( $value ) ? ( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) ) ) : '';
		if ( '' !== $format && strlen( $format ) <= 100 && 1 === substr_count( $format, '{x}' ) ) {
			return $format;
		}
		$previous = get_option( 'opf_pricing_hint_format', get_option( 'wapf_hint_format', '(+{x})' ) );
		return is_string( $previous ) && strlen( $previous ) <= 100 && 1 === substr_count( $previous, '{x}' ) ? $previous : '(+{x})';
	}
}
