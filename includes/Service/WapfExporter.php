<?php
/**
 * Converts supported OPF groups to WAPF Tools JSON.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\FieldGroup;

defined( 'ABSPATH' ) || exit;

final class WapfExporter {

	/**
	 * Convert a normalized OPF group to WAPF's four-section Tools payload.
	 * Unsupported or lossy data fails closed.
	 *
	 * @param array<string,mixed> $group Normalized OPF group.
	 * @return array<string,mixed>
	 */
	public static function build_payload( array $group ): array {
		self::assert_keys( $group, [ 'schema', 'fields', 'rule_groups', 'mark_required', 'labels_position' ], 'group' );
		foreach ( ( $group['fields'] ?? [] ) as $field ) {
			if ( is_array( $field ) ) {
				self::assert_keys( $field, [ 'id', 'label', 'description', 'type', 'required', 'width', 'css_class', 'placeholder', 'choices', 'pricing', 'conditionals', 'content', 'content_format', 'process_shortcodes', 'image_url', 'image_id', 'swatch_style', 'multiple', 'min_choices', 'max_choices', 'color_layout', 'color_size', 'color_label_pos', 'image_zoom', 'label_pos', 'grid_layout', 'item_width', 'items_per_row', 'items_per_row_tablet', 'items_per_row_mobile', 'allow_past', 'allow_future', 'min_date', 'max_date', 'disabled_weekdays', 'disabled_dates', 'cutoff_time' ], 'field' );
			}
		}
		foreach ( ( $group['rule_groups'] ?? [] ) as $rule_group ) {
			if ( is_array( $rule_group ) ) {
				self::assert_keys( $rule_group, [ 'rules' ], 'placement group' );
				foreach ( ( $rule_group['rules'] ?? [] ) as $rule ) {
					if ( is_array( $rule ) ) {
						self::assert_keys( $rule, [ 'subject', 'operator', 'terms' ], 'placement rule' );
					}
				}
			}
		}
		$group = FieldGroup::normalize( $group );
		if ( FieldGroup::SCHEMA !== (int) ( $group['schema'] ?? 0 ) ) {
			throw new \InvalidArgumentException( 'WAPF export cannot preserve this OPF schema.' );
		}
		$fields = [];
		$section_depth = 0;
		foreach ( $group['fields'] as $field ) {
			if ( 'section' === $field['type'] ) {
				$section_depth++;
			} elseif ( 'section_end' === $field['type'] ) {
				if ( 0 === $section_depth ) {
					throw new \InvalidArgumentException( 'WAPF export cannot preserve a section-end marker without an open section.' );
				}
				$section_depth--;
			}
		}
		if ( $section_depth > 0 ) {
			throw new \InvalidArgumentException( 'WAPF export cannot preserve a section without a matching section-end marker.' );
		}
		$field_ids = array_column( $group['fields'], 'id' );
		$field_types = array_column( $group['fields'], 'type', 'id' );
		if ( count( array_unique( $field_ids ) ) !== count( $field_ids ) ) {
			throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve duplicate field IDs.' );
		}
		foreach ( $group['fields'] as $field ) {
			$fields[] = self::map_field( $field, $field_ids, $field_types );
		}
		$conditions = [];
		foreach ( $group['rule_groups'] as $rule_group ) {
			$rules = [];
			foreach ( $rule_group['rules'] as $rule ) {
				$rules[] = self::map_placement_rule( $rule );
			}
			if ( $rules ) {
				$conditions[] = [ 'rules' => $rules ];
			}
		}
		return [
			'fields'    => $fields,
			'conditions' => $conditions,
			'layout'    => [
				'labels_position'       => $group['labels_position'],
				'instructions_position' => 'field',
				'mark_required'         => $group['mark_required'],
			],
			'variables' => [],
		];
	}

	/** @param array<string,mixed> $field */
	private static function map_field( array $field, array $field_ids, array $field_types ): array {
		self::assert_keys( $field, [ 'id', 'label', 'description', 'type', 'required', 'width', 'css_class', 'placeholder', 'choices', 'pricing', 'conditionals', 'content', 'content_format', 'process_shortcodes', 'image_url', 'image_id', 'swatch_style', 'multiple', 'min_choices', 'max_choices', 'color_layout', 'color_size', 'color_label_pos', 'image_zoom', 'label_pos', 'grid_layout', 'item_width', 'items_per_row', 'items_per_row_tablet', 'items_per_row_mobile', 'allow_past', 'allow_future', 'min_date', 'max_date', 'disabled_weekdays', 'disabled_dates', 'cutoff_time' ], 'field' );
		$type_map = [
			'text' => 'text', 'textarea' => 'textarea', 'email' => 'email', 'url' => 'url',
			'number' => 'number', 'toggle' => 'true-false', 'select' => 'select',
			'radio' => 'radio', 'checkbox' => 'checkboxes', 'swatch' => 'text-swatch', 'paragraph' => 'content', 'content_image' => 'img', 'section' => 'section', 'section_end' => 'sectionend',
		];
		$type = $field['type'];
		if ( 'paragraph' === $type && 'html' === ( $field['content_format'] ?? 'plain' ) ) {
			$type_map['paragraph'] = 'p';
		}
		if ( 'swatch' === $type ) {
			$multi = ! empty( $field['multiple'] );
			$style = $field['swatch_style'] ?? 'text';
			$type_map['swatch'] = [
				'text' => $multi ? 'multi-text-swatch' : 'text-swatch',
				'image' => $multi ? 'multi-image-swatch' : 'image-swatch',
				'color' => $multi ? 'multi-color-swatch' : 'color-swatch',
			][ $style ] ?? null;
		}
		if ( ! isset( $type_map[ $type ] ) || null === $type_map[ $type ] ) {
			throw new \InvalidArgumentException( sprintf( 'WAPF Tools export cannot preserve OPF field type "%s".', $type ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]*$/', $field['css_class'] ) ) {
			throw new \InvalidArgumentException( 'WAPF Tools import only preserves one sanitized CSS class per field.' );
		}
		foreach ( [ $field['label'], $field['description'] ] as $text ) {
			if ( false !== strpos( $text, '<' ) ) {
				throw new \InvalidArgumentException( 'WAPF Tools import sanitizes field labels and descriptions; HTML is not exported.' );
			}
		}
		$out = [
			'id'          => $field['id'],
			'label'       => $field['label'],
			'description' => $field['description'],
			'type'        => $type_map[ $type ],
			'required'    => $field['required'],
			'width'       => $field['width'],
			'class'       => $field['css_class'],
			'conditionals' => self::map_conditionals( $field, $field_ids, $field_types ),
			'pricing'     => self::map_field_pricing( $field['pricing'] ),
		];
		if ( '' !== $field['placeholder'] ) {
			if ( preg_match( '/[<>\r\n]/', $field['placeholder'] ) ) {
				throw new \InvalidArgumentException( 'WAPF Tools import sanitizes placeholders; this placeholder cannot be preserved.' );
			}
			$out['placeholder'] = $field['placeholder'];
		}
		if ( 'date' === $type ) {
			throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve OPF date-field settings.' );
		}
		if ( 'paragraph' === $type ) {
			if ( 'p' === $out['type'] && empty( $field['process_shortcodes'] ) ) {
				throw new \InvalidArgumentException( 'WAPF paragraph export always processes shortcodes; disablement cannot be preserved.' );
			}
			if ( 'content' === $out['type'] && preg_match( '/<\/?[a-z][^>]*>/i', $field['content'] ) ) {
				throw new \InvalidArgumentException( 'WAPF Free sanitizes paragraph content; HTML cannot be exported without loss.' );
			}
			$out['p_content'] = $field['content'];
		}
		if ( 'content_image' === $type ) {
			if ( '' !== (string) ( $field['image_url'] ?? '' ) ) {
				$out['image'] = $field['image_url'];
			}
			if ( ! empty( $field['image_id'] ) ) {
				$out['attachment'] = (int) $field['image_id'];
			}
		}
		if ( 'swatch' === $type && in_array( $out['type'], [ 'image-swatch', 'multi-image-swatch' ], true ) ) {
			$out['large_image'] = ! empty( $field['image_zoom'] );
			$out['label_pos'] = $field['label_pos'];
			$out['grid_layout'] = $field['grid_layout'];
			$out['item_width'] = $field['item_width'];
			$out['items_per_row'] = $field['items_per_row'];
			$out['items_per_row_tablet'] = $field['items_per_row_tablet'];
			$out['items_per_row_mobile'] = $field['items_per_row_mobile'];
		}
		if ( 'swatch' === $type && 'color' === ( $field['swatch_style'] ?? '' ) ) {
			$out['layout'] = $field['color_layout'];
			$out['size'] = $field['color_size'];
			$out['label_pos'] = $field['color_label_pos'];
		}
		if ( 'swatch' === $type && ! empty( $field['multiple'] ) ) {
			if ( isset( $field['min_choices'] ) ) {
				$out['min_choices'] = $field['min_choices'];
			}
			if ( isset( $field['max_choices'] ) ) {
				$out['max_choices'] = $field['max_choices'];
			}
		}
		if ( in_array( $type, [ 'select', 'radio', 'checkbox', 'swatch' ], true ) ) {
			$out['choices'] = [];
			foreach ( $field['choices'] as $choice ) {
				if ( 'swatch' === $type && ! in_array( $out['type'], [ 'image-swatch', 'multi-image-swatch' ], true ) && ( ! empty( $choice['image'] ) || ! empty( $choice['image_id'] ) ) ) {
					throw new \InvalidArgumentException( 'WAPF swatch export cannot preserve image media on a non-image swatch.' );
				}
				if ( false !== strpos( $choice['label'], '<' ) ) {
					throw new \InvalidArgumentException( 'WAPF Tools import sanitizes choice labels; HTML is not exported.' );
				}
				$pricing = $choice['pricing'];
				$pricing_type = self::map_choice_pricing( $pricing );
				$wapf_choice = [
					'slug' => $choice['slug'], 'label' => $choice['label'], 'selected' => $choice['selected'],
					'disabled' => $choice['disabled'],
					'pricing_type' => $pricing_type['type'], 'pricing_amount' => $pricing_type['amount'],
				];
				if ( in_array( $out['type'], [ 'image-swatch', 'multi-image-swatch' ], true ) ) {
					if ( ! empty( $choice['image'] ) ) {
						$wapf_choice['image'] = $choice['image'];
					}
					if ( ! empty( $choice['image_id'] ) ) {
						$wapf_choice['attachment'] = $choice['image_id'];
					}
				}
				if ( in_array( $out['type'], [ 'color-swatch', 'multi-color-swatch' ], true ) ) {
					if ( empty( $choice['color'] ) ) {
						throw new \InvalidArgumentException( 'WAPF color swatch export requires a color for every choice.' );
					}
					$wapf_choice['color'] = $choice['color'];
				}
				$out['choices'][] = $wapf_choice;
			}
		}
		return $out;
	}

	/** @param array<string,mixed> $pricing */
	private static function map_field_pricing( array $pricing ): array {
		if ( 'none' === $pricing['type'] ) {
			return [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ];
		}
		if ( 'fixed' !== $pricing['type'] || $pricing['per_unit'] ) {
			throw new \InvalidArgumentException( 'WAPF Tools import cannot preserve this field-level pricing mode.' );
		}
		return [ 'enabled' => true, 'type' => 'fixed', 'amount' => $pricing['amount'] ];
	}

	/** @param array<string,mixed> $pricing @return array{type:string,amount:float} */
	private static function map_choice_pricing( array $pricing ): array {
		if ( 'none' === $pricing['type'] ) {
			return [ 'type' => 'none', 'amount' => 0.0 ];
		}
		if ( 'fixed' === $pricing['type'] ) {
			return [ 'type' => $pricing['per_unit'] ? 'qt' : 'fixed', 'amount' => $pricing['amount'] ];
		}
		if ( 'percent' === $pricing['type'] ) {
			return [ 'type' => 'percent', 'amount' => $pricing['amount'] ];
		}
		throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve formula choice pricing.' );
	}

	/** @param array<string,mixed> $field @return array<int,array<string,mixed>> */
	private static function map_conditionals( array $field, array $field_ids, array $field_types ): array {
		$out = [];
		foreach ( $field['conditionals'] as $conditional ) {
			if ( 'show' !== $conditional['action'] ) {
				throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve hide conditionals.' );
			}
			$blocks = 'any' === $conditional['logic'] ? array_map( static function ( $rule ) {
				return [ $rule ];
			}, $conditional['rules'] ) : [ $conditional['rules'] ];
			foreach ( $blocks as $rules ) {
				$mapped = [];
				foreach ( $rules as $rule ) {
					if ( ! in_array( $rule['field'], $field_ids, true ) ) {
						throw new \InvalidArgumentException( sprintf( 'WAPF Tools export cannot preserve unresolved field reference "%s".', $rule['field'] ) );
					}
					$operator = [
						'is' => '==', 'is_not' => '!=', 'contains' => '==contains',
						'not_contains' => '!=contains', 'greater' => 'gt', 'less' => 'lt',
						'empty' => 'empty', 'not_empty' => '!empty',
					][ $rule['operator'] ] ?? null;
					$value = $rule['value'];
					if ( 'toggle' === ( $field_types[ $rule['field'] ] ?? '' ) && in_array( $rule['operator'], [ 'is', 'is_not' ], true ) ) {
						if ( ! in_array( $value, [ '0', '1' ], true ) ) {
							throw new \InvalidArgumentException( 'WAPF toggle conditionals only preserve values 1 and 0.' );
						}
						$checked = '1' === $value;
						$operator = ( 'is' === $rule['operator'] ) === $checked ? 'check' : '!check';
						$value = '';
					}
					if ( null === $operator ) {
						throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve conditional operator.' );
					}
					$mapped[] = [ 'field' => $rule['field'], 'condition' => $operator, 'value' => $value ];
				}
				$out[] = [ 'rules' => $mapped ];
			}
		}
		return $out;
	}

	/** @param array<string,mixed> $rule */
	private static function map_placement_rule( array $rule ): array {
		if ( 'user_auth' === $rule['subject'] ) {
			if ( [ 'logged_in' ] === $rule['terms'] && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
				$logged_in = 'in' === $rule['operator'];
			} elseif ( [] === $rule['terms'] && in_array( $rule['operator'], [ 'logged_in', 'logged_out' ], true ) ) {
				$logged_in = 'logged_in' === $rule['operator'];
			} else {
				throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve this visitor access rule.' );
			}
			return [
				'subject' => 'user',
				'condition' => $logged_in ? 'auth' : '!auth',
				'value' => [],
			];
		}
		if ( in_array( $rule['subject'], [ 'user_role', 'user_language' ], true ) ) {
			if ( ! in_array( $rule['operator'], [ 'in', 'not_in' ], true ) || 1 !== count( $rule['terms'] ) ) {
				throw new \InvalidArgumentException( 'WAPF Tools export requires exactly one role or language per placement rule.' );
			}
			$is_role = 'user_role' === $rule['subject'];
			return [
				'subject' => $is_role ? 'user' : 'system',
				'condition' => ( 'not_in' === $rule['operator'] ? '!' : '' ) . ( $is_role ? 'role' : 'lang' ),
				'value' => [ [ 'id' => (string) $rule['terms'][0], 'text' => (string) $rule['terms'][0] ] ],
			];
		}
		$subject_map = [ 'product' => 'products', 'category' => 'product_cats', 'tag' => 'p_tags' ];
		if ( ! isset( $subject_map[ $rule['subject'] ] ) || ! in_array( $rule['operator'], [ 'in', 'not_in' ], true ) || ! $rule['terms'] ) {
			throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve a group placement rule.' );
		}
		$subject = $subject_map[ $rule['subject'] ];
		if ( 'not_in' === $rule['operator'] ) {
			$subject = '!' . $subject;
		}
		return [
			'subject' => 'product',
			'condition' => $subject,
			'value' => array_map( static function ( $id ) {
				return [ 'id' => (string) $id, 'text' => (string) $id ];
			}, $rule['terms'] ),
		];
	}

	/** @param array<string,mixed> $data @param string[] $allowed */
	private static function assert_keys( array $data, array $allowed, string $label ): void {
		$unknown = array_diff( array_keys( $data ), $allowed );
		if ( $unknown ) {
			throw new \InvalidArgumentException( sprintf( 'WAPF Tools export cannot preserve unknown %s data: %s.', $label, implode( ', ', $unknown ) ) );
		}
	}
}
