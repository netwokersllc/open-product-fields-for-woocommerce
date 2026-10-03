<?php
/**
 * Product-level WooCommerce price display (WAPF `_wapf_price_display` parity).
 *
 * WAPF Extended lets a simple product keep, hide, replace, or prefix/suffix its
 * WooCommerce price. The setting lives in Product Data → General (pricing) and
 * is stored under WAPF's own meta keys so products migrated from WAPF keep
 * working unchanged.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class ProductPriceDisplay {

	/** WAPF-compatible meta keys (migrated products Just Work). */
	public const META_DISPLAY = '_wapf_price_display';
	public const META_LABEL   = '_wapf_price_label';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_product_options_pricing', [ __CLASS__, 'render_product_fields' ] );
		add_action( 'woocommerce_admin_process_product_object', [ __CLASS__, 'save_product_fields' ] );
		// Runs after WAPF Extended's own `woocommerce_get_price_html` filter
		// (priority 100) so an active WAPF keeps ownership and OPF never wraps
		// the same label twice.
		add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'filter_price_html' ], 101, 2 );
	}

	/**
	 * WAPF mode => label map. Keys are the stored `_wapf_price_display` values.
	 *
	 * @return array<string,string>
	 */
	public static function modes(): array {
		return [
			''        => __( 'Default', 'open-product-fields-for-woocommerce' ),
			'hide'    => __( 'Hide the price', 'open-product-fields-for-woocommerce' ),
			'before'  => __( 'Add a label before the price', 'open-product-fields-for-woocommerce' ),
			'after'   => __( 'Add a label after the price', 'open-product-fields-for-woocommerce' ),
			'replace' => __( 'Replace WooCommerce price with text', 'open-product-fields-for-woocommerce' ),
		];
	}

	/**
	 * Product Data → General → pricing controls (same ids/labels as WAPF).
	 */
	public static function render_product_fields(): void {
		woocommerce_wp_select(
			[
				'id'          => self::META_DISPLAY,
				'label'       => __( 'Price display', 'open-product-fields-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'How to display the price? You can display an extra label before/after the price. You can also hide the price completely, or replace it with your own text.', 'open-product-fields-for-woocommerce' ),
				'options'     => self::modes(),
			]
		);

		woocommerce_wp_text_input(
			[
				'id'          => self::META_LABEL,
				'label'       => __( 'Extra price label', 'open-product-fields-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Additional or replacement text for the price display. If or how this is displayed depends on the setting above.', 'open-product-fields-for-woocommerce' ),
			]
		);
	}

	/**
	 * Persist the two WAPF-compatible meta values. "Default" (empty) deletes
	 * both, exactly like the WAPF admin controller.
	 *
	 * @param mixed $product Product object being saved.
	 */
	public static function save_product_fields( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product nonce first.
		$label = isset( $_POST[ self::META_LABEL ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_LABEL ] ) ) : '';
		if ( '' === $label ) {
			$product->delete_meta_data( self::META_LABEL );
		} else {
			$product->update_meta_data( self::META_LABEL, $label );
		}

		$mode = isset( $_POST[ self::META_DISPLAY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_DISPLAY ] ) ) : '';
		if ( ! array_key_exists( $mode, self::modes() ) ) {
			$mode = '';
		}
		if ( '' === $mode ) {
			$product->delete_meta_data( self::META_DISPLAY );
		} else {
			$product->update_meta_data( self::META_DISPLAY, $mode );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Apply the configured placement to `woocommerce_get_price_html`.
	 *
	 * WAPF places the label on every context it renders in (single, archive,
	 * related), for simple/subscription products only. Variable products and
	 * variations are never touched, so their min/max ranges stay intact.
	 *
	 * @param string $price_html Native WooCommerce price markup.
	 * @param mixed  $product    Product being rendered.
	 * @return string
	 */
	public static function filter_price_html( $price_html, $product ): string {
		if ( ! $product instanceof \WC_Product ) {
			return (string) $price_html;
		}

		// WAPF already applied the same meta -> leave its markup untouched.
		if ( false !== strpos( (string) $price_html, 'wapf-price-' ) ) {
			return (string) $price_html;
		}

		if ( ! in_array( $product->get_type(), [ 'simple', 'subscription' ], true ) ) {
			return (string) $price_html;
		}

		$mode = (string) $product->get_meta( self::META_DISPLAY, true );
		if ( '' === $mode || ! array_key_exists( $mode, self::modes() ) ) {
			return (string) $price_html;
		}
		$label = $product->get_meta( self::META_LABEL, true );

		return self::format( (string) $price_html, $mode, is_scalar( $label ) ? (string) $label : '' );
	}

	/**
	 * Pure placement formatter (WAPF `change_price_html` parity).
	 *
	 * @param string $price_html Native price markup.
	 * @param string $mode       One of hide|before|after|replace.
	 * @param string $label      Configured label text.
	 * @return string
	 */
	public static function format( string $price_html, string $mode, string $label ): string {
		switch ( $mode ) {
			case 'hide':
				return '';
			case 'before':
				return '<span class="wapf-price-before">' . esc_html( $label ) . '</span> ' . $price_html;
			case 'after':
				return $price_html . ' <span class="wapf-price-after">' . esc_html( $label ) . '</span>';
			case 'replace':
				return '<span class="wapf-price-replace">' . esc_html( $label ) . '</span>';
		}
		return $price_html;
	}
}
