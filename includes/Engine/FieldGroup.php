<?php
/**
 * OPF field group data model (array-based, schema-versioned).
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Field group value object. Pure PHP — no WordPress dependencies.
 */
final class FieldGroup {

	/**
	 * Current schema version.
	 */
	public const SCHEMA = 1;

	/**
	 * Supported field types.
	 */
	public const FIELD_TYPES = [ 'text', 'textarea', 'url', 'number', 'select', 'radio', 'checkbox', 'swatch' ];

	/**
	 * Supported pricing types.
	 */
	public const PRICING_TYPES = [ 'none', 'fixed', 'percent', 'formula' ];

	/**
	 * @var array<string,mixed>
	 */
	public array $data;

	/**
	 * Build from an associative array, normalizing missing keys.
	 *
	 * @param array<string,mixed> $data Raw group data.
	 */
	public function __construct( array $data ) {
		$this->data = self::normalize( $data );
	}

	/**
	 * Normalize raw data to the canonical shape.
	 *
	 * @param array<string,mixed> $data Raw data.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $data ): array {
		$fields = [];
		foreach ( ( $data['fields'] ?? [] ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$fields[] = self::normalize_field( $field );
		}

		$rule_groups = [];
		foreach ( ( $data['rule_groups'] ?? [] ) as $group ) {
			$rules = [];
			foreach ( ( is_array( $group ) ? ( $group['rules'] ?? [] ) : [] ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$rules[] = [
					'subject'  => (string) ( $rule['subject'] ?? 'product' ),
					'operator' => (string) ( $rule['operator'] ?? 'in' ),
					'terms'    => array_map( 'strval', (array) ( $rule['terms'] ?? [] ) ),
				];
			}
			if ( $rules ) {
				$rule_groups[] = [ 'rules' => $rules ];
			}
		}

		return [
			'schema'       => self::SCHEMA,
			'fields'       => $fields,
			'rule_groups'  => $rule_groups,
			'mark_required' => (bool) ( $data['mark_required'] ?? true ),
			'labels_position' => ( $data['labels_position'] ?? 'above' ) === 'below' ? 'below' : 'above',
		];
	}

	/**
	 * Normalize a single field.
	 *
	 * @param array<string,mixed> $field Raw field.
	 * @return array<string,mixed>
	 */
	public static function normalize_field( array $field ): array {
		$type = (string) ( $field['type'] ?? 'text' );
		if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
			$type = 'text';
		}

		$choices = [];
		foreach ( ( $field['choices'] ?? [] ) as $choice ) {
			if ( ! is_array( $choice ) || ( $choice['label'] ?? '' ) === '' ) {
				continue;
			}
			$pricing   = is_array( $choice['pricing'] ?? null ) ? $choice['pricing'] : [];
			$choices[] = [
				'slug'     => (string) ( $choice['slug'] ?? '' ),
				'label'    => (string) $choice['label'],
				'selected' => (bool) ( $choice['selected'] ?? false ),
				'disabled' => (bool) ( $choice['disabled'] ?? false ),
				'pricing'  => self::normalize_pricing( $pricing ),
			];
		}

		$conditionals = [];
		foreach ( ( $field['conditionals'] ?? [] ) as $conditional ) {
			if ( ! is_array( $conditional ) ) {
				continue;
			}
			$rules = [];
			foreach ( ( $conditional['rules'] ?? [] ) as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
					continue;
				}
				$rules[] = [
					'field'    => (string) $rule['field'],
					'operator' => (string) ( $rule['operator'] ?? 'is' ),
					'value'    => (string) ( $rule['value'] ?? '' ),
				];
			}
			if ( $rules ) {
				$conditionals[] = [
					'action' => ( $conditional['action'] ?? 'show' ) === 'hide' ? 'hide' : 'show',
					'logic'  => ( $conditional['logic'] ?? 'all' ) === 'any' ? 'any' : 'all',
					'rules'  => $rules,
				];
			}
		}

		$pricing = self::normalize_pricing( is_array( $field['pricing'] ?? null ) ? $field['pricing'] : [] );

		// Field ids become input name fragments and DOM hooks: restrict to a
		// conservative slug charset regardless of the source.
		$field_id = (string) ( $field['id'] ?? '' );
		$field_id = strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $field_id ) );
		if ( '' === $field_id ) {
			$field_id = 'field';
		}

		return [
			'id'           => $field_id,
			'label'        => (string) ( $field['label'] ?? '' ),
			'description'  => (string) ( $field['description'] ?? '' ),
			'type'         => $type,
			'required'     => (bool) ( $field['required'] ?? false ),
			'width'        => max( 25, min( 100, (int) ( $field['width'] ?? 100 ) ) ),
			'choices'      => $choices,
			'pricing'      => $pricing,
			'conditionals' => $conditionals,
		];
	}

	/**
	 * Normalize a pricing block.
	 *
	 * Semantics (WAPF-parity, verified against the legacy engine and theme):
	 *  - percent : per-unit — scales with line quantity.
	 *  - formula : per-unit — scales with line quantity (imported WAPF
	 *              formulas have their qty-compensation factor stripped).
	 *  - fixed   : FLAT per line by default (`per_unit` opt-in to scale).
	 *
	 * @param array<string,mixed> $pricing Raw pricing.
	 * @return array<string,mixed>
	 */
	public static function normalize_pricing( array $pricing ): array {
		$type   = (string) ( $pricing['type'] ?? 'none' );
		$amount = is_numeric( $pricing['amount'] ?? null ) ? (float) $pricing['amount'] : 0.0;
		if ( 'formula' === $type ) {
			$amount = 0.0;
		}
		if ( ! in_array( $type, self::PRICING_TYPES, true ) ) {
			$type   = 'none';
			$amount = 0.0;
		}
		$per_unit = 'fixed' === $type
			? (bool) ( $pricing['per_unit'] ?? false )
			: true;

		return [
			'type'     => $type,
			'amount'   => $amount,
			'formula'  => (string) ( $pricing['formula'] ?? '' ),
			'per_unit' => $per_unit,
		];
	}

	/**
	 * Does any field in this group carry pricing?
	 */
	public function is_priced(): bool {
		foreach ( $this->data['fields'] as $field ) {
			if ( 'none' !== $field['pricing']['type'] ) {
				return true;
			}
			foreach ( $field['choices'] as $choice ) {
				if ( 'none' !== $choice['pricing']['type'] ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Find a field by id.
	 */
	public function field( string $id ): ?array {
		foreach ( $this->data['fields'] as $field ) {
			if ( $field['id'] === $id ) {
				return $field;
			}
		}
		return null;
	}
}
