<?php
/**
 * FOX/WOOCS currency support for live field totals and variable products.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service\Integrations;

defined( 'ABSPATH' ) || exit;

final class Woocs {

	/** Register the variation payload used by the live total preview. */
	public static function init(): void {
		add_filter( 'woocommerce_available_variation', [ __CLASS__, 'variation_currency_data' ], 10, 3 );
	}

	/**
	 * Rate from the shop's base currency into the selected currency.
	 *
	 * @param string              $current    Selected currency.
	 * @param string              $default    Shop currency.
	 * @param array<string,mixed> $currencies WOOCS currency table.
	 */
	public static function rate_from_currencies( string $current, string $default, array $currencies ): float {
		if ( '' === $current || '' === $default || 0 === strcasecmp( $current, $default ) ) {
			return 1.0;
		}
		$rate = $currencies[ $current ]['rate'] ?? null;
		return is_numeric( $rate ) && is_finite( (float) $rate ) && (float) $rate > 0 ? (float) $rate : 1.0;
	}

	/**
	 * Resolve current selected-currency rate, or one when WOOCS is inactive.
	 */
	public static function rate(): float {
		$woocs = $GLOBALS['WOOCS'] ?? null;
		if ( ! is_object( $woocs ) || ! isset( $woocs->current_currency, $woocs->default_currency ) || ! is_callable( [ $woocs, 'get_currencies' ] ) ) {
			return 1.0;
		}
		$currencies = $woocs->get_currencies();
		return is_array( $currencies ) ? self::rate_from_currencies( (string) $woocs->current_currency, (string) $woocs->default_currency, $currencies ) : 1.0;
	}

	/** Whether WOOCS has selected a currency other than the store currency. */
	public static function is_converted_currency(): bool {
		$woocs = $GLOBALS['WOOCS'] ?? null;
		return is_object( $woocs )
			&& isset( $woocs->current_currency, $woocs->default_currency )
			&& 0 !== strcasecmp( (string) $woocs->current_currency, (string) $woocs->default_currency );
	}

	/** Selected currency formatting not included in WooCommerce's base settings. */
	public static function display_options(): array {
		$woocs = $GLOBALS['WOOCS'] ?? null;
		if ( ! is_object( $woocs ) || ! isset( $woocs->current_currency ) || ! is_callable( [ $woocs, 'get_currencies' ] ) ) {
			return [];
		}
		$currencies = $woocs->get_currencies();
		$currency   = is_array( $currencies ) ? ( $currencies[ (string) $woocs->current_currency ] ?? null ) : null;
		if ( ! is_array( $currency ) ) {
			return [];
		}
		$options = [];
		if ( isset( $currency['symbol'] ) && is_scalar( $currency['symbol'] ) ) {
			$options['symbol'] = (string) $currency['symbol'];
		}
		if ( isset( $currency['decimals'] ) && is_numeric( $currency['decimals'] ) ) {
			$options['decimals'] = max( 0, min( 8, (int) $currency['decimals'] ) );
		}
		if ( ! empty( $currency['hide_cents'] ) ) {
			$options['decimals'] = 0;
		}
		if ( isset( $currency['position'] ) ) {
			switch ( (string) $currency['position'] ) {
				case 'right':
					$options['format'] = '{price}{symbol}';
					break;
				case 'left_space':
					$options['format'] = '{symbol} {price}';
					break;
				case 'right_space':
					$options['format'] = '{price} {symbol}';
					break;
				default:
					$options['format'] = '{symbol}{price}';
					break;
			}
		}
		$separators = (string) ( $currency['separators'] ?? '' );
		if ( in_array( $separators, [ '1', '2', '3', '4', '5' ], true ) ) {
			$thousand = [ '1' => '.', '2' => ' ', '3' => ' ', '4' => '', '5' => '' ];
			$decimal  = [ '1' => ',', '2' => '.', '3' => ',', '4' => '.', '5' => ',' ];
			$options['thousand'] = $thousand[ $separators ];
			$options['decimal']  = $decimal[ $separators ];
		}
		return $options;
	}

	/**
	 * Compute the base-currency totals and display conversion used in markup.
	 * Kept pure for deterministic unit coverage.
	 *
	 * @return array{base:float,rate:float}
	 */
	public static function display_context( float $base, float $rate ): array {
		return [ 'base' => $base, 'rate' => is_finite( $rate ) && $rate > 0 ? $rate : 1.0 ];
	}

	/** Match WOOCS' fixed-price gate for the selected currency. */
	public static function has_fixed_price( bool $enabled, string $current, string $default, $regular_price, $sale_price ): bool {
		if ( ! $enabled || '' === $current || '' === $default || 0 === strcasecmp( $current, $default ) ) {
			return false;
		}
		foreach ( [ $regular_price, $sale_price ] as $price ) {
			if ( is_numeric( $price ) && is_finite( (float) $price ) && (float) $price > 0 ) {
				return true;
			}
		}
		return false;
	}

	/** Keep a WOOCS fixed price in its selected currency instead of multiplying it by the rate. */
	public static function selected_price_context( float $store_price, float $selected_price, float $rate, bool $fixed_price ): array {
		return $fixed_price ? self::display_context( $selected_price, 1.0 ) : self::display_context( $store_price, $rate );
	}

	/** Resolve whether the selected product has a configured WOOCS fixed price. */
	public static function product_has_fixed_price( $product ): bool {
		$woocs = $GLOBALS['WOOCS'] ?? null;
		if ( ! $product instanceof \WC_Product || ! is_object( $woocs ) || ! isset( $woocs->current_currency, $woocs->default_currency ) || ! function_exists( 'get_option' ) || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}
		$enabled = 1 === (int) get_option( 'woocs_is_fixed_enabled', 0 );
		$currency = (string) $woocs->current_currency;
		$product_id = (int) $product->get_id();
		return self::has_fixed_price(
			$enabled,
			$currency,
			(string) $woocs->default_currency,
			get_post_meta( $product_id, '_woocs_regular_price_' . $currency, true ),
			get_post_meta( $product_id, '_woocs_sale_price_' . $currency, true )
		);
	}

	/** Product price context used by formula previews and live totals. */
	public static function product_context( $product ): array {
		if ( ! $product instanceof \WC_Product ) {
			return self::display_context( 0.0, 1.0 );
		}
		$store_price = (float) $product->get_price( 'edit' );
		$rate = self::rate();
		$fixed_price = self::product_has_fixed_price( $product );
		$selected_price = $fixed_price ? (float) $product->get_price() : $store_price;
		return self::selected_price_context( $store_price, $selected_price, $rate, $fixed_price );
	}

	/**
	 * Add the selected-currency base and rate to Woo's variation response.
	 *
	 * @param array<string,mixed> $data Variation response.
	 * @param \WC_Product         $product Variable parent.
	 * @param \WC_Product         $variation Selected variation.
	 * @return array<string,mixed>
	 */
	public static function variation_currency_data( array $data, $product, $variation ): array {
		$rate = self::rate();
		if ( ! self::is_converted_currency() || ! $variation instanceof \WC_Product ) {
			return $data;
		}
		$fixed_price = self::product_has_fixed_price( $product );
		$store_price = (float) $variation->get_price( 'edit' );
		$selected_price = isset( $data['display_price'] ) && is_numeric( $data['display_price'] )
			? (float) $data['display_price']
			: (float) $variation->get_price();
		$context = self::selected_price_context( $store_price, $selected_price, $rate, $fixed_price );
		$data['opf_currency_base'] = $context['base'];
		$data['opf_currency_rate'] = $context['rate'];
		return $data;
	}
}
