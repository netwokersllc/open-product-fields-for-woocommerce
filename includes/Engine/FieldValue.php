<?php
/**
 * Canonical submitted-value behavior for fields with non-text semantics.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class FieldValue {
	// WordPress's default safe protocols, without accepting executable schemes.
	private const URL_PROTOCOLS = [ 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn' ];

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
		// Keep malformed URL payloads distinguishable from an empty optional URL.
		// The NUL marker cannot be a valid URL and is rejected before attachment.
		if ( 'url' === ( $field['type'] ?? '' ) && ! is_scalar( $value ) && null !== $value ) {
			return "\0";
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		if ( 'toggle' === ( $field['type'] ?? '' ) ) {
			return in_array( $value, [ true, 1, '1', 'true', 'on', 'yes' ], true ) ? '1' : '0';
		}

		$text = trim( (string) $value );
		return '' === $text ? null : $text;
	}

	/** Reject submitted values that select choices disabled by the editor. */
	public static function validate_choices( array $field, $value ): array {
		if ( ! in_array( $field['type'] ?? '', [ 'select', 'radio', 'checkbox', 'swatch' ], true ) ) {
			return [];
		}
		$selected = array_map( 'strval', is_array( $value ) ? $value : ( null === $value ? [] : [ $value ] ) );
		$errors = [];
		foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
			if ( ! empty( $choice['disabled'] ) && in_array( (string) ( $choice['slug'] ?? '' ), $selected, true ) ) {
				$errors[] = sprintf( '"%s" includes unavailable choice "%s".', (string) ( $field['label'] ?? '' ), (string) ( $choice['label'] ?? '' ) );
			}
		}
		return $errors;
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

	/** Validate a WAPF disabled date, recurring month/day, or inclusive range. */
	public static function is_disabled_date( string $rule ): bool {
		$parts = preg_split( '/\s+/', trim( $rule ) );
		if ( ! is_array( $parts ) || ! in_array( count( $parts ), [ 1, 2 ], true ) ) {
			return false;
		}
		foreach ( $parts as $part ) {
			if ( preg_match( '/^\d{2}-\d{2}$/', $part ) ) {
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', '2000-' . $part, new \DateTimeZone( 'UTC' ) );
				$errors = \DateTimeImmutable::getLastErrors();
				if ( ! $date instanceof \DateTimeImmutable || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'm-d' ) !== $part ) {
					return false;
				}
			} elseif ( ! self::is_iso_date( $part ) ) {
				return false;
			}
		}
		if ( 2 === count( $parts ) ) {
			$first_is_month_day = (bool) preg_match( '/^\d{2}-\d{2}$/', $parts[0] );
			$second_is_month_day = (bool) preg_match( '/^\d{2}-\d{2}$/', $parts[1] );
			if ( $first_is_month_day !== $second_is_month_day || ( ! $first_is_month_day && $parts[0] > $parts[1] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Check a date against exact, recurring, and inclusive date-range rules. */
	public static function matches_disabled_date( string $value, array $rules ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $date instanceof \DateTimeImmutable || ! self::is_iso_date( $value ) ) {
			return false;
		}
		$month_day = $date->format( 'm-d' );
		foreach ( $rules as $rule ) {
			if ( ! is_string( $rule ) || ! self::is_disabled_date( $rule ) ) {
				continue;
			}
			$parts = preg_split( '/\s+/', trim( $rule ) );
			if ( 1 === count( $parts ) ) {
				if ( $parts[0] === $value || $parts[0] === $month_day ) {
					return true;
				}
				continue;
			}
			$start = $parts[0];
			$end = $parts[1];
			if ( preg_match( '/^\d{2}-\d{2}$/', $start ) && preg_match( '/^\d{2}-\d{2}$/', $end ) ) {
				if ( ( $start <= $end && $month_day >= $start && $month_day <= $end ) || ( $start > $end && ( $month_day >= $start || $month_day <= $end ) ) ) {
					return true;
				}
			} else {
				if ( preg_match( '/^\d{2}-\d{2}$/', $start ) ) {
					$start = $date->format( 'Y' ) . '-' . $start;
				}
				if ( preg_match( '/^\d{2}-\d{2}$/', $end ) ) {
					$end = $date->format( 'Y' ) . '-' . $end;
				}
				if ( $start <= $value && $value <= $end ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function is_iso_date( string $value ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		return $date instanceof \DateTimeImmutable && ( ! is_array( $errors ) || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d' ) === $value;
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

		if ( 'url' === $type && ( false === filter_var( $value, FILTER_VALIDATE_URL )
			|| ! in_array( strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) ), self::URL_PROTOCOLS, true )
			|| preg_match( '/[\x00-\x20\x7f<>"`]/', $value ) ) ) {
			return [ sprintf( '"%s" must be a valid URL.', $label ) ];
		}

		if ( 'date' === $type ) {
			$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( ! $date instanceof \DateTimeImmutable || ( is_array( $errors ) && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) ) || $date->format( 'Y-m-d' ) !== $value ) {
				return [ sprintf( '"%s" must be a valid date.', $label ) ];
			}
			$current = $today ?? ( function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
			$site_today = $current->format( 'Y-m-d' );
			if ( false === ( $field['allow_past'] ?? true ) && $value < $site_today ) {
				return [ sprintf( '"%s" cannot be in the past.', $label ) ];
			}
			if ( false === ( $field['allow_future'] ?? true ) && $value > $site_today ) {
				return [ sprintf( '"%s" cannot be in the future.', $label ) ];
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
			if ( in_array( (int) $date->format( 'w' ), $field['disabled_weekdays'] ?? [], true ) ) {
				return [ sprintf( '"%s" is unavailable on this weekday.', $label ) ];
			}
			if ( self::matches_disabled_date( $value, $field['disabled_dates'] ?? [] ) ) {
				return [ sprintf( '"%s" contains a disallowed date.', $label ) ];
			}
			if ( isset( $field['cutoff_time'] ) && $value === $current->format( 'Y-m-d' ) && $current->format( 'H:i:s' ) > $field['cutoff_time'] . ':00' ) {
				return [ sprintf( '"%s" is no longer available for today.', $label ) ];
			}
		}

		return [];
	}
}
