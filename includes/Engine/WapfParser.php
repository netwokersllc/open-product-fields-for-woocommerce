<?php
/**
 * Tolerant parser for legacy WAPF field group payloads.
 *
 * WAPF stores field groups as PHP-serialized strings in `post_content`
 * (global groups) and `_wapf_fieldgroup` post meta (per-product groups).
 * On this codebase's production database, 20 of 23 stored payloads fail
 * plain unserialize() because multibyte characters broke the byte-length
 * prefixes (charset conversion damage). This parser reads the strict
 * format first, then falls back to a recovering decoder that re-derives
 * string boundaries from the `";` delimiters, resurrecting damaged data.
 *
 * Nothing in this file executes WAPF code or copies it — it only reads
 * the site's own stored data format.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class WapfParser {

	/**
	 * Parse a WAPF payload into an associative array.
	 *
	 * @param string $payload Raw stored string.
	 * @return array<string,mixed>|null Null when the payload is not recoverable.
	 */
	public static function parse( string $payload ): ?array {
		$payload = trim( $payload );
		if ( '' === $payload ) {
			return null;
		}

		// Payloads may also arrive as JSON (WAPF admin payloads).
		if ( isset( $payload[0] ) && '{' === $payload[0] ) {
			$decoded = json_decode( $payload, true );
			return is_array( $decoded ) ? $decoded : null;
		}

		if ( 'a:' !== substr( $payload, 0, 2 ) ) {
			return null;
		}

		$strict = @unserialize( $payload, [ 'allowed_classes' => false ] );
		if ( is_array( $strict ) ) {
			return $strict;
		}

		$result = self::recovering_decode( $payload );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Recovering recursive descent decoder for damaged PHP serialization.
	 *
	 * @return mixed
	 */
	private static function recovering_decode( string $payload ) {
		$value = null;
		$pos   = 0;
		try {
			$value = self::read_value( $payload, $pos );
		} catch ( \Throwable $e ) {
			return null;
		}
		return $value;
	}

	/**
	 * Read one value at $pos (by reference).
	 *
	 * @param string $s   Payload.
	 * @param int    $pos Cursor.
	 * @return mixed
	 */
	private static function read_value( string $s, int &$pos ) {
		self::skip_ws( $s, $pos );
		$type = $s[ $pos ] ?? '';

		switch ( $type ) {
			case 'a':
				$pos += 2; // "a:"
				$count = self::read_int( $s, $pos ); // count + ":"
				$pos++; // "{"
				$array     = [];
				$has_string_key = false;
				for ( $i = 0; $i < $count; $i++ ) {
					$key = self::read_value( $s, $pos );
					$val = self::read_value( $s, $pos );
					if ( ! is_int( $key ) ) {
						$has_string_key = true;
					}
					$array[ $key ] = $val;
				}
				$pos++; // "}"
				if ( ! $has_string_key ) {
					$array = array_values( $array );
				}
				return $array;

			case 's':
				return self::read_string( $s, $pos );

			case 'i':
				$pos += 2;
				$int  = self::read_int( $s, $pos );
				// Integers are terminated by ';' (read_int only consumes the
				// ':' used by the s:/a: length contexts).
				if ( ';' === ( $s[ $pos ] ?? '' ) ) {
					$pos++;
				}
				return $int;

			case 'd':
				$pos += 2;
				$num  = '';
				while ( isset( $s[ $pos ] ) && false === strpos( ';}', $s[ $pos ] ) ) {
					$num .= $s[ $pos ];
					$pos++;
				}
				$pos++;
				return is_numeric( $num ) ? (float) $num : 0.0;

			case 'b':
				$pos += 2;
				$bool = '1' === ( $s[ $pos ] ?? '0' );
				$pos += 2;
				return $bool;

			case 'N':
				$pos += 2;
				return null;

			default:
				throw new \RuntimeException( 'Unknown type at ' . $pos );
		}
	}

	/**
	 * Read a string, tolerating wrong length prefixes by scanning to the `";`
	 * delimiter (bounded). Correctly handles escaped-backslash endings.
	 *
	 * @param string $s   Payload.
	 * @param int    $pos Cursor.
	 */
	private static function read_string( string $s, int &$pos ): string {
		$pos += 2; // "s:"
		$len  = self::read_int( $s, $pos );
		$pos++; // opening quote

		if ( isset( $s[ $pos + $len ] ) && '";' === substr( $s, $pos + $len, 2 ) ) {
			$value = substr( $s, $pos, $len );
			$pos  += $len + 2;
			return $value;
		}

		// Damaged length: scan forward for a real `";` terminator. A `"` preceded
		// by an odd number of backslashes is escaped, not terminating.
		$search = $pos;
		$limit  = min( strlen( $s ), $pos + 100000 );
		while ( $search < $limit ) {
			$idx = strpos( $s, '";', $search );
			if ( false === $idx ) {
				break;
			}
			$slashes = 0;
			$k       = $idx - 1;
			while ( $k >= $pos && '\\' === $s[ $k ] ) {
				$slashes++;
				$k--;
			}
			if ( 0 === $slashes % 2 ) {
				$value = substr( $s, $pos, $idx - $pos );
				$pos   = $idx + 2;
				// The strict decoder would have unescaped \" and \\; mirror
				// that for repaired strings.
				return preg_replace( '/\\\\(.)/s', '$1', $value );
			}
			$search = $idx + 2;
		}

		throw new \RuntimeException( 'Unterminated string at ' . $pos );
	}

	/**
	 * Read digits followed by ':'.
	 *
	 * @param string $s   Payload.
	 * @param int    $pos Cursor.
	 */
	private static function read_int( string $s, int &$pos ): int {
		$num = '';
		while ( isset( $s[ $pos ] ) && ctype_digit( $s[ $pos ] ) ) {
			$num .= $s[ $pos ];
			$pos++;
		}
		if ( '' === $num ) {
			throw new \RuntimeException( 'Expected int at ' . $pos );
		}
		if ( ':' === ( $s[ $pos ] ?? '' ) ) {
			$pos++;
		}
		return (int) $num;
	}

	/**
	 * Skip whitespace.
	 */
	private static function skip_ws( string $s, int &$pos ): void {
		while ( isset( $s[ $pos ] ) && ctype_space( $s[ $pos ] ) ) {
			$pos++;
		}
	}
}
