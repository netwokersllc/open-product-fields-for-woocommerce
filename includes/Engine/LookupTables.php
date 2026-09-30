<?php
/**
 * Request-local lookup tables for WAPF-compatible pricing formulas.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class LookupTables {

	/** @var array<string,array<mixed>> */
	private static array $tables = [];

	/** Register a numeric nested map under a public formula table name. */
	public static function register( string $name, array $table ): bool {
		$name = strtolower( trim( $name ) );
		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ) {
			return false;
		}
		$normalized = self::normalize( $table, 0 );
		if ( null === $normalized ) {
			return false;
		}
		self::$tables[ $name ] = $normalized;
		return true;
	}

	/** Return registered tables referenced by formulas in this page registry. */
	public static function for_registry( array $registry ): array {
		$used = [];
		self::collect_references( $registry, $used );
		return array_intersect_key( self::$tables, $used );
	}

	/** Resolve table dimensions in the same order as the formula's field IDs. */
	public static function lookup( string $name, array $field_ids, array $field_values ): ?float {
		$name = strtolower( trim( $name ) );
		if ( ! isset( self::$tables[ $name ] ) || ! $field_ids || count( $field_ids ) > 64 ) {
			return null;
		}

		$node = self::$tables[ $name ];
		$last_dimension = count( $field_ids ) - 1;
		foreach ( $field_ids as $index => $field_id ) {
			$field_id = trim( (string) $field_id );
			$value    = $field_values[ $field_id ] ?? $field_values[ strtolower( $field_id ) ] ?? null;
			if ( ! is_scalar( $value ) ) {
				return null;
			}
			$key = self::select_key( $node, (string) $value );
			if ( null === $key ) {
				return null;
			}
			$node = $node[ $key ];
			if ( ! is_array( $node ) && $index < $last_dimension ) {
				return null;
			}
		}

		return is_numeric( $node ) && is_finite( (float) $node ) ? (float) $node : null;
	}

	/** Validate and copy a nested map; only finite numeric leaves are accepted. */
	private static function normalize( array $node, int $depth ): ?array {
		if ( ! $node || $depth >= 64 ) {
			return null;
		}
		$normalized = [];
		foreach ( $node as $key => $value ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) {
				return null;
			}
			if ( is_array( $value ) ) {
				$child = self::normalize( $value, $depth + 1 );
				if ( null === $child ) {
					return null;
				}
				$normalized[ $key ] = $child;
			} elseif ( is_numeric( $value ) && is_finite( (float) $value ) ) {
				$normalized[ $key ] = (float) $value;
			} else {
				return null;
			}
		}
		return $normalized;
	}

	/** Exact-match categorical dimensions; round numeric dimensions upward. */
	private static function select_key( array $node, string $value ) {
		if ( array_key_exists( $value, $node ) ) {
			return $value;
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$candidates = [];
		foreach ( array_keys( $node ) as $key ) {
			if ( is_numeric( $key ) && (float) $key >= (float) $value ) {
				$candidates[] = $key;
			}
		}
		if ( ! $candidates ) {
			return null;
		}
		usort( $candidates, static fn( $left, $right ): int => (float) $left <=> (float) $right );
		return $candidates[0];
	}

	/** Find table names referenced by formula strings, without exposing other tables. */
	private static function collect_references( array $value, array &$used ): void {
		foreach ( $value as $key => $item ) {
			if ( is_string( $item ) && in_array( $key, [ 'formula', 'formula_raw' ], true ) && preg_match_all( '/\blookuptable\s*\(\s*([a-z][a-z0-9_]*)\s*[;,]/i', $item, $matches ) ) {
				foreach ( $matches[1] as $name ) {
					$used[ strtolower( $name ) ] = true;
				}
			} elseif ( is_array( $item ) ) {
				self::collect_references( $item, $used );
			}
		}
	}
}
