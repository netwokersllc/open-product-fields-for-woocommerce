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
	 * ACF references used by every field and choice formula in these groups.
	 *
	 * @param array<int,array<string,mixed>> $groups FieldGroups repository entries.
	 * @return array<string,array{type:string,name:string}>
	 */
	public static function references( array $groups ): array {
		$references = [];
		foreach ( $groups as $entry ) {
			$fields = $entry['group']->data['fields'] ?? [];
			foreach ( $fields as $field ) {
				$blocks = [ $field['pricing'] ?? [] ];
				foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
					$blocks[] = $choice['pricing'] ?? [];
				}
				foreach ( $blocks as $pricing ) {
					foreach ( [ $pricing['formula'] ?? '', $pricing['formula_raw'] ?? '' ] as $formula ) {
						if ( preg_match_all( '/\b(acf_option|acf)\s*\(\s*([a-zA-Z0-9_-]+)\s*\)/i', (string) $formula, $matches, PREG_SET_ORDER ) ) {
							foreach ( $matches as $match ) {
								$type = strtolower( $match[1] );
								$name = strtolower( $match[2] );
								$references[ $type . ':' . $name ] = [ 'type' => $type, 'name' => $match[2] ];
							}
						}
					}
				}
			}
		}
		return $references;
	}

	/** Resolve only ACF references used by the supplied product groups. */
	public static function values_for_groups( array $groups, int $product_id ): array {
		if ( ! function_exists( 'get_field' ) ) {
			return [];
		}

		$values = [];
		foreach ( self::references( $groups ) as $key => $reference ) {
			$target = 'acf_option' === $reference['type'] ? 'option' : $product_id;
			$value  = get_field( $reference['name'], $target, false );
			if ( is_bool( $value ) ) {
				$value = $value ? 1 : 0;
			}
			$values[ $key ] = is_scalar( $value ) && is_numeric( $value ) && is_finite( (float) $value )
				? (float) $value
				: 0.0;
		}
		return $values;
	}

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

				return self::literal( (float) $value );
			},
			$formula
		);
	}

	/** Format a finite float using the formula parser's decimal grammar. */
	private static function literal( float $value ): string {
		$literal = rtrim( rtrim( number_format( $value, 14, '.', '' ), '0' ), '.' );
		return '' === $literal || '-0' === $literal ? '0' : $literal;
	}
}
