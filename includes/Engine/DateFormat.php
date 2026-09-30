<?php
/** Canonical ISO date presentation helpers. */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class DateFormat {
	public const DEFAULT_FORMAT = 'mm-dd-yyyy';

	/** Normalize a supported WAPF-style date format. */
	public static function normalize( $format ): string {
		return self::is_valid( $format ) ? strtolower( trim( $format ) ) : self::DEFAULT_FORMAT;
	}

	/** A format uses one year, one month, and one day token. */
	public static function is_valid( $format ): bool {
		if ( ! is_string( $format ) || ! preg_match( '/^(?:yyyy|yy|mm|m|dd|d)(?:[-\/., ](?:yyyy|yy|mm|m|dd|d)){2}$/i', $format ) ) {
			return false;
		}
		$tokens = preg_split( '/[-\/., ]+/', strtolower( $format ) );
		if ( ! is_array( $tokens ) || 3 !== count( $tokens ) ) {
			return false;
		}
		return 1 === count( array_intersect( $tokens, [ 'yyyy', 'yy' ] ) )
			&& 1 === count( array_intersect( $tokens, [ 'mm', 'm' ] ) )
			&& 1 === count( array_intersect( $tokens, [ 'dd', 'd' ] ) );
	}

	/** Format a canonical YYYY-MM-DD value without changing its stored value. */
	public static function format( $iso_date, $format = self::DEFAULT_FORMAT ): string {
		if ( ! is_string( $iso_date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $iso_date, $parts ) ) {
			return '';
		}
		$year = (int) $parts[1];
		$month = (int) $parts[2];
		$day = (int) $parts[3];
		if ( ! checkdate( $month, $day, $year ) ) {
			return '';
		}
		$values = [
			'yyyy' => sprintf( '%04d', $year ),
			'yy' => sprintf( '%02d', $year % 100 ),
			'mm' => sprintf( '%02d', $month ),
			'm' => (string) $month,
			'dd' => sprintf( '%02d', $day ),
			'd' => (string) $day,
		];
		return (string) preg_replace_callback(
			'/yyyy|yy|mm|m|dd|d/i',
			static function ( array $match ) use ( $values ): string {
				return $values[ strtolower( $match[0] ) ];
			},
			self::normalize( $format )
		);
	}
}
