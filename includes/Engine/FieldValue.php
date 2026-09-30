<?php
/**
 * Canonical submitted-value behavior for fields with non-text semantics.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class FieldValue {

	/**
	 * Normalize scalar values after the transport layer has sanitized them.
	 *
	 * A malformed, non-empty email remains a value here so validation can
	 * distinguish it from an omitted optional field and return the right error.
	 *
	 * @param array<string,mixed> $field Field definition.
	 * @param mixed               $value Submitted scalar value.
	 * @return string|null
	 */
	public static function sanitize( array $field, $value ): ?string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		if ( 'toggle' === ( $field['type'] ?? '' ) ) {
			return in_array( $value, [ true, 1, '1', 'true', 'on', 'yes' ], true ) ? '1' : '0';
		}

		$text = trim( (string) $value );
		return '' === $text ? null : $text;
	}

	/** Return whether a date bound is canonical ISO or a WAPF relative period. */
	public static function is_date_boundary( string $boundary ): bool {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $boundary ) ) {
			$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $boundary, new \DateTimeZone( 'UTC' ) );
			$errors = \DateTimeImmutable::getLastErrors();
			return $date instanceof \DateTimeImmutable && ( ! is_array( $errors ) || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d' ) === $boundary;
		}

		if ( '' === trim( $boundary ) || strlen( $boundary ) > 128 ) {
			return false;
		}
		$matched = preg_match_all( '/[+-]?\d{1,5}[ymd]/i', trim( $boundary ), $tokens );
		if ( false === $matched || 0 === $matched || $matched > 12 ) {
			return false;
		}
		$remainder = preg_replace( '/[+-]?\d{1,5}[ymd]/i', '', trim( $boundary ) );
		if ( null === $remainder || '' !== trim( $remainder ) ) {
			return false;
		}
		foreach ( $tokens[0] as $token ) {
			if ( abs( (int) substr( $token, 0, -1 ) ) > 36500 ) {
				return false;
			}
		}
		return true;
	}

	/** Resolve WAPF periods such as `1y 9m 3d` in the WordPress site timezone. */
	public static function resolve_date_boundary( string $boundary, ?\DateTimeImmutable $today = null ): ?string {
		if ( ! self::is_date_boundary( $boundary ) ) {
			return null;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $boundary ) ) {
			return $boundary;
		}
		if ( null === $today ) {
			$today = function_exists( 'current_datetime' )
				? current_datetime()
				: new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		}

		$totals = [ 'y' => 0, 'm' => 0, 'd' => 0 ];
		preg_match_all( '/([+-]?\d{1,5})([ymd])/i', trim( $boundary ), $parts, PREG_SET_ORDER );
		foreach ( $parts as $part ) {
			$unit = strtolower( $part[2] );
			$totals[ $unit ] += (int) $part[1];
		}

		try {
			$resolved = $today->setTime( 0, 0 );
			foreach ( [ 'y' => 'Y', 'm' => 'M', 'd' => 'D' ] as $unit => $interval_unit ) {
				$amount = $totals[ $unit ];
				if ( 0 === $amount ) {
					continue;
				}
				$interval = new \DateInterval( 'P' . abs( $amount ) . $interval_unit );
				if ( $amount < 0 ) {
					$interval->invert = 1;
				}
				$resolved = $resolved->add( $interval );
			}
		} catch ( \Exception $exception ) {
			return null;
		}

		return $resolved->format( 'Y-m-d' );
	}

	/**
	 * Return server-side validation messages for a submitted field value.
	 *
	 * @param array<string,mixed> $field    Field definition.
	 * @param string|null         $value    Sanitized value.
	 * @param bool                $provided Whether this input appeared in the payload.
	 * @return string[]
	 */
	public static function validate( array $field, ?string $value, bool $provided, ?\DateTimeImmutable $today = null ): array {
		$label = (string) ( $field['label'] ?? '' );
		$type  = (string) ( $field['type'] ?? '' );

		if ( 'toggle' === $type ) {
			if ( ! empty( $field['required'] ) && ( ! $provided || '1' !== $value ) ) {
				return [ sprintf( '"%s" is a required field.', $label ) ];
			}
			return [];
		}

		if ( ! $provided || null === $value ) {
			return ! empty( $field['required'] ) ? [ sprintf( '"%s" is a required field.', $label ) ] : [];
		}

		if ( 'email' === $type && false === filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			return [ sprintf( '"%s" must be a valid email address.', $label ) ];
		}

		if ( 'date' === $type ) {
			$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( ! $date instanceof \DateTimeImmutable || ( is_array( $errors ) && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) ) || $date->format( 'Y-m-d' ) !== $value ) {
				return [ sprintf( '"%s" must be a valid date.', $label ) ];
			}
			foreach ( [ 'min_date' => 'on or after', 'max_date' => 'on or before' ] as $key => $comparison ) {
				if ( ! isset( $field[ $key ] ) ) {
					continue;
				}
				$boundary = self::resolve_date_boundary( (string) $field[ $key ], $today );
				if ( null !== $boundary && ( 'min_date' === $key ? $value < $boundary : $value > $boundary ) ) {
					return [ sprintf( '"%s" must be %s %s.', $label, $comparison, $boundary ) ];
				}
			}
		}

		return [];
	}
}
