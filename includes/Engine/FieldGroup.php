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
	public const FIELD_TYPES = [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch', 'paragraph' ];

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
	 * Upgrade a persisted group to the current schema before normalization.
	 *
	 * Schema 0 represents groups written before OPF stored an explicit schema
	 * number. Keeping this migration separate from normalize() gives later
	 * schema changes one deterministic place to preserve old records.
	 *
	 * @param array<string,mixed> $data Persisted group data.
	 * @return array<string,mixed>
	 */
	public static function migrate( array $data ): array {
		$raw_schema = $data['schema'] ?? 0;
		if ( ! is_int( $raw_schema ) && ! ( is_string( $raw_schema ) && ctype_digit( $raw_schema ) ) ) {
			throw new \InvalidArgumentException( 'OPF field group schema must be a non-negative integer.' );
		}

		$schema = (int) $raw_schema;
		if ( $schema > self::SCHEMA ) {
			throw new \InvalidArgumentException( 'OPF field group schema is newer than this plugin version.' );
		}

		while ( $schema < self::SCHEMA ) {
			switch ( $schema ) {
				case 0:
					// Legacy OPF groups had no explicit schema value.
					$data['schema'] = 1;
					$schema         = 1;
					break;

				default:
					throw new \InvalidArgumentException( 'No OPF field group migration exists for schema ' . $schema . '.' );
			}
		}

		return $data;
	}

	/**
	 * Normalize raw data to the canonical shape.
	 *
	 * @param array<string,mixed> $data Raw data.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $data ): array {
		$data = self::migrate( $data );

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
			$normalized_choice = [
				'slug'     => (string) ( $choice['slug'] ?? '' ),
				'label'    => (string) $choice['label'],
				'selected' => (bool) ( $choice['selected'] ?? false ),
				'disabled' => (bool) ( $choice['disabled'] ?? false ),
				'pricing'  => self::normalize_pricing( $pricing ),
			];
			$image = $choice['image'] ?? null;
			if ( is_string( $image ) ) {
				$image = trim( $image );
				if ( strlen( $image ) <= 2048 && ! preg_match( '/[\x00-\x20\x7F]/', $image ) && preg_match( '#^(?:https?://[^/\s]+|/(?!/))#i', $image ) ) {
					$normalized_choice['image'] = $image;
				}
			}
			$image_id = $choice['image_id'] ?? null;
			if ( ( is_int( $image_id ) || ( is_string( $image_id ) && ctype_digit( $image_id ) ) ) && (int) $image_id > 0 ) {
				$normalized_choice['image_id'] = (int) $image_id;
			}
			$choices[] = $normalized_choice;
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

		$normalized = [
			'id'           => $field_id,
			'label'        => (string) ( $field['label'] ?? '' ),
			'description'  => (string) ( $field['description'] ?? '' ),
			'type'         => $type,
			'required'     => (bool) ( $field['required'] ?? false ),
			'width'        => max( 25, min( 100, (int) ( $field['width'] ?? 100 ) ) ),
			'css_class'    => (string) ( $field['css_class'] ?? '' ),
			'placeholder'  => (string) ( $field['placeholder'] ?? '' ),
			'choices'      => $choices,
			'pricing'      => $pricing,
			'conditionals' => $conditionals,
		];
		if ( 'paragraph' === $type ) {
			$normalized['content'] = is_scalar( $field['content'] ?? null ) ? (string) $field['content'] : '';
			$normalized['required'] = false;
			$normalized['choices'] = [];
			$normalized['pricing'] = self::normalize_pricing( [] );
		}
		if ( 'swatch' === $type ) {
			$has_image_choice = (bool) array_filter( $choices, static function ( array $choice ): bool {
				return ! empty( $choice['image'] ) || ! empty( $choice['image_id'] );
			} );
			if ( 'image' === ( $field['swatch_style'] ?? '' ) || $has_image_choice ) {
				$normalized['swatch_style'] = 'image';
			}
		}

		if ( 'date' === $type ) {
			foreach ( [ 'allow_past', 'allow_future' ] as $key ) {
				$value = $field[ $key ] ?? true;
				if ( ! in_array( $value, [ true, false, 0, 1, '0', '1' ], true ) ) {
					throw new \InvalidArgumentException( sprintf( 'Date field %s must be a boolean.', $key ) );
				}
				$normalized[ $key ] = in_array( $value, [ true, 1, '1' ], true );
			}
			foreach ( [ 'min_date', 'max_date' ] as $key ) {
				if ( ! array_key_exists( $key, $field ) ) {
					continue;
				}
				if ( ! is_string( $field[ $key ] ) ) {
					throw new \InvalidArgumentException( sprintf( 'Date field %s must be a string.', $key ) );
				}
				$boundary = trim( $field[ $key ] );
				if ( '' === $boundary ) {
					continue;
				}
				if ( ! FieldValue::is_date_boundary( $boundary ) ) {
					throw new \InvalidArgumentException( sprintf( 'Date field %s must be YYYY-MM-DD or a WAPF relative period such as 7d or 1y 9m 3d.', $key ) );
				}
				$normalized[ $key ] = $boundary;
			}
			$min_date = isset( $normalized['min_date'] ) ? FieldValue::resolve_date_boundary( $normalized['min_date'] ) : null;
			$max_date = isset( $normalized['max_date'] ) ? FieldValue::resolve_date_boundary( $normalized['max_date'] ) : null;
			if ( null !== $min_date && null !== $max_date && $min_date > $max_date ) {
				throw new \InvalidArgumentException( 'Date minimum cannot exceed its maximum.' );
			}
			if ( array_key_exists( 'disabled_weekdays', $field ) ) {
				if ( ! is_array( $field['disabled_weekdays'] ) ) {
					throw new \InvalidArgumentException( 'Disabled weekdays must be a list of weekday numbers from 0 to 6.' );
				}
				$weekdays = [];
				foreach ( $field['disabled_weekdays'] as $weekday ) {
					if ( ! is_scalar( $weekday ) || ! preg_match( '/^[0-6]$/', (string) $weekday ) ) {
						throw new \InvalidArgumentException( 'Disabled weekdays must be a list of weekday numbers from 0 to 6.' );
					}
					$weekdays[] = (int) $weekday;
				}
				$normalized['disabled_weekdays'] = array_values( array_unique( $weekdays ) );
			}
			if ( array_key_exists( 'disabled_dates', $field ) ) {
				if ( ! is_array( $field['disabled_dates'] ) || count( $field['disabled_dates'] ) > 512 ) {
					throw new \InvalidArgumentException( 'Disabled dates must be a list of at most 512 rules.' );
				}
				$disabled_dates = [];
				foreach ( $field['disabled_dates'] as $disabled_date ) {
					if ( ! is_string( $disabled_date ) || ! FieldValue::is_disabled_date( $disabled_date ) ) {
						throw new \InvalidArgumentException( 'Disabled dates must be YYYY-MM-DD, MM-DD, or an inclusive range using two dates.' );
					}
					$disabled_dates[] = trim( $disabled_date );
				}
				$normalized['disabled_dates'] = array_values( array_unique( $disabled_dates ) );
			}
			if ( array_key_exists( 'cutoff_time', $field ) && '' !== $field['cutoff_time'] ) {
				if ( ! is_string( $field['cutoff_time'] ) ) {
					throw new \InvalidArgumentException( 'Date cutoff time must be a string in 24-hour HH:MM format.' );
				}
				$cutoff = trim( $field['cutoff_time'] );
				if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cutoff ) ) {
					throw new \InvalidArgumentException( 'Date cutoff time must use 24-hour HH:MM format.' );
				}
				$normalized['cutoff_time'] = $cutoff;
			}
		}
		return $normalized;
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
			'type'        => $type,
			'amount'      => $amount,
			'formula'     => (string) ( $pricing['formula'] ?? '' ),
			'formula_raw' => (string) ( $pricing['formula_raw'] ?? '' ),
			'per_unit'    => $per_unit,
		];
	}

	/**
	 * Clone normalized group data and remap references between its fields.
	 *
	 * @param array<string,mixed> $data Group data.
	 * @return array{group:array<string,mixed>,field_id_map:array<string,string>}
	 */
	public static function duplicate( array $data ): array {
		$group = self::normalize( $data );
		$ids = array_column( $group['fields'], 'id' );
		if ( count( array_unique( $ids ) ) !== count( $ids ) ) {
			throw new \InvalidArgumentException( 'Cannot duplicate a field group with repeated field IDs.' );
		}

		$used_ids = array_fill_keys( $ids, true );
		$id_map = [];
		foreach ( $ids as $field_id ) {
			$base = substr( $field_id, 0, 48 ) . '-copy';
			$new_id = $base;
			$suffix = 2;
			while ( isset( $used_ids[ $new_id ] ) ) {
				$new_id = $base . '-' . $suffix;
				$suffix++;
			}
			$used_ids[ $new_id ] = true;
			$id_map[ $field_id ] = $new_id;
		}

		foreach ( $group['fields'] as &$field ) {
			$field['id'] = $id_map[ $field['id'] ];
			foreach ( $field['conditionals'] as &$conditional ) {
				foreach ( $conditional['rules'] as &$rule ) {
					if ( isset( $id_map[ $rule['field'] ] ) ) {
						$rule['field'] = $id_map[ $rule['field'] ];
					}
				}
				unset( $rule );
			}
			unset( $conditional );

			$field['pricing'] = self::duplicate_pricing_references( $field['pricing'], $id_map );
			foreach ( $field['choices'] as &$choice ) {
				$choice['pricing'] = self::duplicate_pricing_references( $choice['pricing'], $id_map );
			}
			unset( $choice );
		}
		unset( $field );

		return [ 'group' => $group, 'field_id_map' => $id_map ];
	}

	/**
	 * Remap recognized field tokens in a pricing block.
	 *
	 * @param array<string,mixed> $pricing Pricing data.
	 * @param array<string,string> $id_map Field ID map.
	 * @return array<string,mixed>
	 */
	private static function duplicate_pricing_references( array $pricing, array $id_map ): array {
		foreach ( [ 'formula', 'formula_raw' ] as $key ) {
			if ( ! isset( $pricing[ $key ] ) || ! is_string( $pricing[ $key ] ) ) {
				continue;
			}
			$pricing[ $key ] = (string) preg_replace_callback(
				'~\\[(field|price)\\.([A-Za-z0-9_-]+)\\]~',
				static function ( array $match ) use ( $id_map ): string {
					return isset( $id_map[ $match[2] ] ) ? '[' . $match[1] . '.' . $id_map[ $match[2] ] . ']' : $match[0];
				},
				$pricing[ $key ]
			);
		}
		return $pricing;
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
