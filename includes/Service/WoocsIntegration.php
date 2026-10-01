<?php
/**
 * Narrow frontend compatibility with WOOCS/FOX, based on WAPF's installed
 * WOOCS adapter. OPF continues to calculate prices in WooCommerce shop
 * currency; only the browser preview is converted for display.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class WoocsIntegration {

	/** Register the currency display bridge. */
	public static function init(): void {
		add_action( 'wp_footer', [ __CLASS__, 'print_frontend_config' ], 100 );
	}

	/**
	 * Read only the WOOCS API used by WAPF's adapter and normalize its active
	 * currency settings for OPF's totals formatter.
	 *
	 * @param object|null $woocs WOOCS API object, injectable for tests.
	 * @return array<string,mixed>|null
	 */
	public static function frontend_config( $woocs = null ): ?array {
		if ( null === $woocs ) {
			global $WOOCS;
			$woocs = $WOOCS ?? null;
		}
		if ( ! is_object( $woocs ) || ! isset( $woocs->current_currency, $woocs->default_currency ) || ! is_callable( [ $woocs, 'get_currencies' ] ) ) {
			return null;
		}

		$currencies = $woocs->get_currencies();
		$current    = (string) $woocs->current_currency;
		if ( ! is_array( $currencies ) || ! isset( $currencies[ $current ] ) || ! is_array( $currencies[ $current ] ) ) {
			return null;
		}

		$info       = $currencies[ $current ];
		$is_default = 0 === strcasecmp( $current, (string) $woocs->default_currency );
		$rate       = isset( $info['rate'] ) && is_numeric( $info['rate'] ) && is_finite( (float) $info['rate'] ) && (float) $info['rate'] > 0
			? (float) $info['rate']
			: 1.0;
		$position   = (string) ( $info['position'] ?? 'left' );
		$format     = [
			'left'        => '%1$s%2$s',
			'right'       => '%2$s%1$s',
			'left_space'  => '%1$s&nbsp;%2$s',
			'right_space' => '%2$s&nbsp;%1$s',
		][$position] ?? '%1$s%2$s';

		$thousand = ',';
		$decimal  = '.';
		switch ( (string) ( $info['separators'] ?? '' ) ) {
			case '1':
				$thousand = '.';
				$decimal  = ',';
				break;
			case '2':
				$thousand = ' ';
				break;
			case '3':
				$thousand = ' ';
				$decimal  = ',';
				break;
			case '4':
				$thousand = '';
				break;
			case '5':
				$thousand = '';
				$decimal  = ',';
				break;
		}

		$decimals = isset( $info['decimals'] ) && is_numeric( $info['decimals'] ) ? max( 0, min( 8, (int) $info['decimals'] ) ) : 2;
		if ( ! empty( $info['hide_cents'] ) ) {
			$decimals = 0;
		}

		return [
			'currency_rate' => $is_default ? 1.0 : $rate,
			'display_options' => [
				'symbol'       => (string) ( $info['symbol'] ?? '' ),
				'thousand'     => $thousand,
				'decimal'      => $decimal,
				'decimals'     => $decimals,
				'format'        => $format,
			],
		];
	}

	/**
	 * Return the shop-currency price for the OPF preview when WOOCS has
	 * converted the public/view price. Other currency plugins retain the
	 * existing WooCommerce view-price behavior.
	 *
	 * @param \WC_Product $product Product being rendered.
	 */
	public static function preview_base_price( \WC_Product $product ): float {
		$config = self::frontend_config();
		if ( null !== $config && 1.0 !== $config['currency_rate'] ) {
			return (float) $product->get_price( 'edit' );
		}
		return (float) $product->get_price();
	}

	/** Emit a JSON-safe override after OPF's default display config. */
	public static function print_frontend_config(): void {
		$config = self::frontend_config();
		if ( null === $config || ! function_exists( 'wp_json_encode' ) ) {
			return;
		}
		$json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( ! is_string( $json ) ) {
			return;
		}
		echo '<script>if(window.opf_config){Object.assign(window.opf_config,' . $json . ');Object.assign(window.opf_config.display_options,' . $json . '.display_options);}</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is hex-escaped.
	}
}
