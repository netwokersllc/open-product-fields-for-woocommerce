<?php
/**
 * Canonical configuration for repeated field and section instances.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class RepeaterField {
	/** WAPF's repeater-button template falls back to this value when max is blank. */
	public const DEFAULT_BUTTON_ROWS = 10000;

	/** @return array<string,mixed> */
	public static function normalize( $raw ): array {
		if ( null === $raw || [] === $raw ) {
			return [];
		}
		if ( ! is_array( $raw ) ) {
			throw new \InvalidArgumentException( 'Repeater settings must be an object.' );
		}
		$unknown = array_diff( array_keys( $raw ), [ 'enabled', 'mode', 'max', 'add', 'del', 'label' ] );
		if ( $unknown ) {
			throw new \InvalidArgumentException( 'Repeater settings contain unsupported keys.' );
		}
		$enabled = $raw['enabled'] ?? false;
		if ( ! in_array( $enabled, [ true, false, 1, 0, '1', '0' ], true ) ) {
			throw new \InvalidArgumentException( 'Repeater enabled setting must be boolean.' );
		}
		if ( ! in_array( $enabled, [ true, 1, '1' ], true ) ) {
			return [];
		}
		$mode = (string) ( $raw['mode'] ?? 'button' );
		if ( 'qty' === $mode ) {
			$mode = 'quantity';
		}
		if ( ! in_array( $mode, [ 'button', 'quantity' ], true ) ) {
			throw new \InvalidArgumentException( 'Repeater mode must be button or quantity.' );
		}
		$labels = [];
		$label_keys = 'quantity' === $mode ? [ 'label' ] : [ 'add', 'del', 'label' ];
		foreach ( $label_keys as $key ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			if ( ! is_string( $raw[ $key ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Repeater %s label must be text.', $key ) );
			}
			$value = trim( strip_tags( $raw[ $key ] ) );
			$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
			if ( strlen( $value ) > 200 ) {
				throw new \InvalidArgumentException( sprintf( 'Repeater %s label is too long.', $key ) );
			}
			if ( '' !== $value ) {
				$labels[ $key ] = $value;
			}
		}
		if ( 'quantity' === $mode ) {
			return array_merge( [ 'enabled' => true, 'mode' => 'quantity' ], $labels );
		}
		$max = $raw['max'] ?? self::DEFAULT_BUTTON_ROWS;
		if ( ! is_int( $max ) && ! ( is_string( $max ) && preg_match( '/^[0-9]+$/', $max ) ) ) {
			throw new \InvalidArgumentException( 'Button repeater maximum must be an integer.' );
		}
		$max_string = ltrim( (string) $max, '0' );
		if ( '' === $max_string ) {
			$max_string = '0';
		}
		$platform_max = (string) PHP_INT_MAX;
		if ( strlen( $max_string ) > strlen( $platform_max ) || ( strlen( $max_string ) === strlen( $platform_max ) && strcmp( $max_string, $platform_max ) > 0 ) ) {
			throw new \InvalidArgumentException( 'Button repeater maximum exceeds the integer range on this platform.' );
		}
		$max = (int) $max;
		if ( $max < 1 ) {
			throw new \InvalidArgumentException( 'Button repeater maximum must be a positive integer.' );
		}
		return array_merge( [ 'enabled' => true, 'mode' => 'button', 'max' => $max ], $labels );
	}

	/** Sanitize each submitted row while preserving row order and empty rows. */
	public static function sanitize( array $field, $raw, callable $sanitize_row ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$rows = [];
		if ( 'quantity' === ( $field['repeat']['mode'] ?? 'button' ) ) {
			foreach ( $raw as $index => $row ) {
				if ( ! is_int( $index ) || $index < 0 ) {
					continue;
				}
				$rows[ $index ] = $sanitize_row( $row );
			}
			ksort( $rows, SORT_NUMERIC );
		} else {
			foreach ( array_values( $raw ) as $row ) {
				$rows[] = $sanitize_row( $row );
			}
		}
		return $rows ?: null;
	}

	/** Validate repeater row count and every non-empty row. */
	public static function validate( array $field, array $rows, bool $provided, int $product_quantity = 1 ): array {
		$label = (string) ( $field['label'] ?? '' );
		$repeat = $field['repeat'] ?? [];
		$errors = [];
		$type = (string) ( $field['type'] ?? '' );
		$quantity_mode = 'quantity' === ( $repeat['mode'] ?? 'button' );

		if ( ! in_array( $type, [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch' ], true ) ) {
			return [ sprintf( '"%s" uses a field type that cannot be repeated yet.', $label ) ];
		}

		if ( ! $provided || ! $rows ) {
			return ! empty( $field['required'] ) ? [ sprintf( '"%s" is required in repeated row 1.', $label ) ] : [];
		}

		if ( $quantity_mode ) {
			$expected_rows = max( 1, $product_quantity );
			$last_index = max( array_map( 'intval', array_keys( $rows ) ) );
			if ( $last_index + 1 !== $expected_rows || count( $rows ) > $expected_rows ) {
				return [ sprintf( '"%s" must have exactly %d rows to match product quantity.', $label, $expected_rows ) ];
			}
		}

		if ( ! $quantity_mode ) {
			$max = (int) ( $repeat['max'] ?? self::DEFAULT_BUTTON_ROWS );
			if ( count( $rows ) > $max ) {
				return [ sprintf( '"%s" allows at most %d repeated rows.', $label, $max ) ];
			}
		}

		$row_indexes = $quantity_mode ? range( 0, max( 1, $product_quantity ) - 1 ) : array_keys( $rows );
		foreach ( $row_indexes as $index ) {
			$value = $rows[ $index ] ?? null;
			$empty = null === $value || '' === $value || [] === $value || ( 'toggle' === $type && '0' === $value );
			if ( $empty ) {
				if ( ! empty( $field['required'] ) ) {
					$errors[] = sprintf( '"%s" is required in repeated row %d.', $label, $index + 1 );
				}
				continue;
			}

			if ( in_array( $type, [ 'email', 'date', 'toggle' ], true ) ) {
				$instance = array_merge( $field, [ 'required' => false ] );
				$instance_errors = FieldValue::validate( $instance, is_scalar( $value ) ? (string) $value : null, true );
				foreach ( $instance_errors as $error ) {
					$errors[] = preg_replace( '/\\.$/', sprintf( ' in repeated row %d.', $index + 1 ), $error );
				}
			}

			if ( in_array( $type, [ 'checkbox', 'swatch' ], true ) && ( 'checkbox' === $type || ! empty( $field['multiple'] ) ) ) {
				$count = is_array( $value ) ? count( $value ) : 1;
				foreach ( [ 'min_choices' => 'at least', 'max_choices' => 'at most' ] as $key => $description ) {
					if ( isset( $field[ $key ] ) && ( 'min_choices' === $key ? $count < $field[ $key ] : $count > $field[ $key ] ) ) {
						$errors[] = sprintf( '"%s" requires %s %d choices in repeated row %d.', $label, $description, $field[ $key ], $index + 1 );
					}
				}
			}
		}

		return $errors;
	}
}
