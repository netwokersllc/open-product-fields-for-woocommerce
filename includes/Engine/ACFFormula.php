<?php
/**
 * Resolve ACF-backed values embedded in pricing formulas.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class ACFFormula {

	/**
	 * Replace WAPF ACF formula calls with numeric literals.
	 *
	 * The ACF plugin remains optional. Missing, non-numeric, and unsupported
	 * values resolve to zero so the formula parser never receives ACF data as
	 * executable syntax.
	 */
	public static function resolve( string $formula, int $product_id ): string {
		return preg_replace_callback(
			'/\b(acf_option|acf)\s*\(\s*([a-zA-Z0-9_-]+)\s*\)/i',
			static function ( array $match ) use ( $product_id ): string {
				if ( ! function_exists( 'get_field' ) ) {
					return '0';
				}

				$value = 'acf_option' === strtolower( $match[1] )
					? get_field( $match[2], 'option', false )
					: get_field( $match[2], $product_id, false );

				if ( is_bool( $value ) ) {
					$value = $value ? 1 : 0;
				}
				if ( ! is_scalar( $value ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
					return '0';
				}

				$literal = rtrim( rtrim( number_format( (float) $value, 14, '.', '' ), '0' ), '.' );
				return '' === $literal || '-0' === $literal ? '0' : $literal;
			},
			$formula
		);
	}
}
