<?php
/**
 * Duplicate a field group with unique field IDs and remapped intra-group references.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class FieldGroupDuplicator {

	/**
	 * Copy a group and remap references to fields within the copied group.
	 *
	 * @param array<string,mixed> $data       Source group.
	 * @param callable|null       $id_factory Optional deterministic ID factory for tests.
	 * @return array<string,mixed>
	 */
	public static function duplicate( array $data, ?callable $id_factory = null ): array {
		$result = self::duplicate_with_map( $data, $id_factory );
		return $result['group'];
	}

	/**
	 * Copy a group and return the old-to-new field ID map for extension hooks.
	 *
	 * @param array<string,mixed> $data       Source group.
	 * @param callable|null       $id_factory Optional deterministic ID factory for tests.
	 * @return array{group:array<string,mixed>,id_map:array<string,string>}
	 * @internal
	 */
	public static function duplicate_with_map( array $data, ?callable $id_factory = null ): array {
		$copy = FieldGroup::normalize( $data );
		$used = [];
		foreach ( $copy['fields'] as $field ) {
			$id = $field['id'];
			if ( isset( $used[ $id ] ) ) {
				throw new \InvalidArgumentException( 'Cannot duplicate a field group with repeated field IDs.' );
			}
			$used[ $id ] = true;
		}

		$factory = $id_factory ?? static fn(): string => 'field-' . bin2hex( random_bytes( 8 ) );
		$id_map  = [];
		foreach ( $copy['fields'] as $field ) {
			$id_map[ $field['id'] ] = self::unique_id( $used, $factory );
		}

		foreach ( $copy['fields'] as &$field ) {
			$old_id     = $field['id'];
			$field['id'] = $id_map[ $old_id ];

			foreach ( $field['conditionals'] as &$conditional ) {
				foreach ( $conditional['rules'] as &$rule ) {
					if ( isset( $id_map[ $rule['field'] ] ) ) {
						$rule['field'] = $id_map[ $rule['field'] ];
					}
				}
				unset( $rule );
			}
			unset( $conditional );

			$field['pricing'] = self::remap_pricing( $field['pricing'], $id_map );
			foreach ( $field['choices'] as &$choice ) {
				$choice['pricing'] = self::remap_pricing( $choice['pricing'], $id_map );
			}
			unset( $choice );
		}
		unset( $field );

		return [
			'group'  => $copy,
			'id_map' => $id_map,
		];
	}

	/**
	 * Return an unused conservative field ID.
	 *
	 * @param array<string,bool> $used IDs already present or generated.
	 */
	private static function unique_id( array &$used, callable $factory ): string {
		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			$id = strtolower( (string) $factory() );
			$id = (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $id );
			if ( '' !== $id && ! isset( $used[ $id ] ) ) {
				$used[ $id ] = true;
				return $id;
			}
		}
		throw new \RuntimeException( 'Could not generate unique field IDs for the duplicated group.' );
	}

	/**
	 * Remap only recognized field-reference tokens, avoiding partial text replacement.
	 *
	 * @param array<string,mixed>      $pricing Pricing configuration.
	 * @param array<string,string> $id_map  Old to new field IDs.
	 * @return array<string,mixed>
	 */
	private static function remap_pricing( array $pricing, array $id_map ): array {
		foreach ( [ 'formula', 'formula_raw' ] as $key ) {
			if ( isset( $pricing[ $key ] ) && is_string( $pricing[ $key ] ) ) {
				$pricing[ $key ] = self::remap_formula( $pricing[ $key ], $id_map );
			}
		}
		return $pricing;
	}

	/**
	 * Remap OPF field formula references.
	 *
	 * @param array<string,string> $id_map Old to new field IDs.
	 */
	private static function remap_formula( string $formula, array $id_map ): string {
		$formula = (string) preg_replace_callback(
			'/\[(field|price)\.([a-zA-Z0-9_\-]+)\]/',
			static function ( array $match ) use ( $id_map ): string {
				$id = $id_map[ $match[2] ] ?? $match[2];
				return '[' . $match[1] . '.' . $id . ']';
			},
			$formula
		);
		return (string) preg_replace_callback(
			'/\b(checked|sumQty|files)\(\s*([a-zA-Z0-9_\-]+)\s*\)/i',
			static function ( array $match ) use ( $id_map ): string {
				$id = $id_map[ $match[2] ] ?? $match[2];
				return $match[1] . '(' . $id . ')';
			},
			$formula
		);
	}
}
