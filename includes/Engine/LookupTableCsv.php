<?php
/**
 * Parse spreadsheet CSV lookup tables in the documented WAPF formats.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class LookupTableCsv {
	private const MAX_BYTES = 10485760;
	private const MAX_CELLS = 100000;

	/**
	 * Parse a 2D grid or a list of combinations into a nested numeric map.
	 *
	 * @return array{name:string,dimensions:int,table:array<mixed>}
	 */
	public static function parse( string $contents, string $filename, string $format = 'auto' ): array {
		if ( ! in_array( $format, [ 'auto', 'grid', 'list' ], true ) ) {
			throw new \InvalidArgumentException( 'Choose a grid or list CSV format.' );
		}
		if ( '' === $contents || strlen( $contents ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'CSV must contain between 1 byte and 10 MiB.' );
		}
		$stream = fopen( 'php://temp', 'r+' );
		if ( false === $stream ) {
			throw new \RuntimeException( 'Could not read the CSV data.' );
		}
		fwrite( $stream, $contents );
		rewind( $stream );
		$rows = [];
		$cell_count = 0;
		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
			if ( [ null ] === $row ) {
				continue;
			}
			$row = array_map( static fn( $cell ): string => is_string( $cell ) ? $cell : '', $row );
			if ( ! $rows && isset( $row[0] ) ) {
				$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $row[0] );
			}
			$cell_count += count( $row );
			if ( $cell_count > self::MAX_CELLS ) {
				throw new \InvalidArgumentException( 'CSV exceeds the 100,000-cell limit.' );
			}
			$rows[] = $row;
		}
		fclose( $stream );
		if ( count( $rows ) < 2 ) {
			throw new \InvalidArgumentException( 'CSV needs at least two rows.' );
		}
		$width = count( $rows[0] );
		if ( $width < 2 ) {
			throw new \InvalidArgumentException( 'CSV needs at least two columns.' );
		}
		foreach ( $rows as $row_index => $row ) {
			if ( count( $row ) !== $width ) {
				throw new \InvalidArgumentException( 'Every CSV row must have the same number of columns.' );
			}
			foreach ( $row as $index => $cell ) {
				$empty_grid_name = 0 === $row_index && 0 === $index && '' === $cell;
				if ( ( '' === $cell && ! $empty_grid_name ) || trim( $cell ) !== $cell || 1 !== preg_match( '//u', $cell ) ) {
					throw new \InvalidArgumentException( 'CSV cells must be nonblank valid UTF-8 without surrounding whitespace.' );
				}
			}
		}

		$first = $rows[0][0];
		$last_header = strtolower( (string) end( $rows[0] ) );
		$is_list_header = 'price' === $last_header;
		$is_grid = 'grid' === $format || ( 'auto' === $format && ! $is_list_header && ( '' === $first || ( preg_match( '/^[a-z0-9_]+$/i', $first ) && ! is_numeric( $first ) ) ) );
		$name = self::table_name( $is_grid && '' !== $first ? $first : pathinfo( $filename, PATHINFO_FILENAME ) );
		if ( $is_grid ) {
			return self::parse_grid( $rows, $name );
		}
		return self::parse_list( $rows, $name );
	}

	/** @return array{name:string,dimensions:int,table:array<mixed>} */
	private static function parse_grid( array $rows, string $name ): array {
		$headers = array_slice( $rows[0], 1 );
		$labels = array_column( array_slice( $rows, 1 ), 0 );
		self::unique_keys( $headers );
		self::unique_keys( $labels );
		$table = [];
		foreach ( $headers as $column => $header ) {
			foreach ( $labels as $row_index => $label ) {
				$price = $rows[ $row_index + 1 ][ $column + 1 ];
				if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) ) {
					throw new \InvalidArgumentException( 'Every grid price must be a finite number using a dot decimal separator.' );
				}
				$table[ $header ][ $label ] = (float) $price;
			}
		}
		return [ 'name' => $name, 'dimensions' => 2, 'table' => $table ];
	}

	/** @return array{name:string,dimensions:int,table:array<mixed>} */
	private static function parse_list( array $rows, string $name ): array {
		if ( 'price' === strtolower( (string) end( $rows[0] ) ) ) {
			array_shift( $rows );
		}
		if ( ! $rows ) {
			throw new \InvalidArgumentException( 'A combination list needs at least one data row.' );
		}
		$dimensions = count( $rows[0] ) - 1;
		if ( $dimensions < 1 ) {
			throw new \InvalidArgumentException( 'A list table needs at least one field and a price column.' );
		}
		$table = [];
		foreach ( $rows as $row ) {
			$price = array_pop( $row );
			if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) ) {
				throw new \InvalidArgumentException( 'Every list price must be a finite number using a dot decimal separator.' );
			}
			$node =& $table;
			foreach ( $row as $index => $key ) {
				if ( $index === $dimensions - 1 ) {
					if ( array_key_exists( $key, $node ) ) {
						throw new \InvalidArgumentException( 'CSV contains a duplicate field combination.' );
					}
					$node[ $key ] = (float) $price;
				} else {
					if ( isset( $node[ $key ] ) && ! is_array( $node[ $key ] ) ) {
						throw new \InvalidArgumentException( 'CSV contains conflicting field combinations.' );
					}
					$node[ $key ] ??= [];
					$node =& $node[ $key ];
				}
			}
			unset( $node );
		}
		return [ 'name' => $name, 'dimensions' => $dimensions, 'table' => $table ];
	}

	private static function table_name( string $name ): string {
		if ( ! preg_match( '/^[a-z0-9_]+$/i', $name ) ) {
			throw new \InvalidArgumentException( 'Table names may contain only letters, numbers, and underscores.' );
		}
		return strtolower( $name );
	}

	private static function unique_keys( array $keys ): void {
		if ( count( $keys ) !== count( array_unique( $keys, SORT_STRING ) ) ) {
			throw new \InvalidArgumentException( 'Grid field values must be unique.' );
		}
	}
}
