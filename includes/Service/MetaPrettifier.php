<?php
/**
 * Prettifies order line-item meta display.
 *
 * Legacy WAPF orders (and OPF URL fields) store machine-ish meta: lowercase
 * keys like "duracion" and raw URL strings. WooCommerce renders that as-is
 * in the admin order screen, My Account, and emails. This service hooks the
 * two display filters WooCommerce core applies everywhere item meta is
 * shown, so the data reads cleanly without touching stored data.
 *
 *  - Keys: filterable label map (opf_pretty_meta_labels), then a light
 *    humanize pass for lowercase keys.
 *  - Values: whole-value URLs become clickable, safely escaped links.
 *
 * Storage is never modified — display only.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class MetaPrettifier {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_order_item_display_meta_key', [ __CLASS__, 'pretty_key' ], 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_value', [ __CLASS__, 'pretty_value' ], 10, 3 );
	}

	/**
	 * Humanize the meta key shown to admins and customers.
	 *
	 * @param string               $display_key Key WC is about to display.
	 * @param object               $meta        Meta object.
	 * @param \WC_Order_Item|null  $item        Order item.
	 * @return string
	 */
	public static function pretty_key( $display_key, $meta = null, $item = null ) {
		if ( $item instanceof \WC_Order_Item_Product ) {
			$key   = (string) $display_key;
			$map   = apply_filters(
				'opf_pretty_meta_labels',
				[
					'duracion'  => 'Duración',
					'duración'  => 'Duración',
					'url'       => 'URL',
					'origen'    => 'Origen',
					'velocidad' => 'Velocidad',
					'pais'      => 'País',
					'país'      => 'País',
				]
			);
			$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $key, 'UTF-8' ) : strtolower( $key );

			if ( isset( $map[ $lower ] ) ) {
				return $map[ $lower ];
			}

			// "some_field" / "some-field" → "Some field".
			if ( $lower === $key ) {
				return ucfirst( str_replace( [ '_', '-' ], ' ', $key ) );
			}
		}
		return $display_key;
	}

	/**
	 * Prettify the meta value: whole-value URLs become links.
	 *
	 * WC core escapes display values with wp_kses_post, so a safe anchor
	 * survives everywhere item meta is rendered, including HTML emails.
	 *
	 * @param string|array        $display_value Value WC is about to display.
	 * @param object              $meta          Meta object.
	 * @param \WC_Order_Item|null $item          Order item.
	 * @return string
	 */
	public static function pretty_value( $display_value, $meta = null, $item = null ) {
		if ( ! is_string( $display_value ) || ! $item instanceof \WC_Order_Item_Product ) {
			return $display_value;
		}

		$value   = $display_value;
		$trimmed = trim( $value );
		if ( preg_match( '#^https?://\S+$#i', $trimmed ) ) {
			$url = esc_url_raw( $trimmed );
			if ( '' === $url ) {
				return $value;
			}
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>';
		}

		return $value;
	}
}
