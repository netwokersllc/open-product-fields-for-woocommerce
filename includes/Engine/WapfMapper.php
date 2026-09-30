<?php
/**
 * Maps legacy WAPF field group data to OPF group data.
 *
 * Behavioral notes preserved from production data analysis (Sept 2026):
 *  - WAPF choice pricing types in the wild: none, fixed, percent, fx (formula).
 *  - WAPF formula amounts end in "* [qty]" because WAPF normalized per-unit
 *    results by dividing by quantity; OPF pricing is already per-unit, so the
 *    mapper strips a trailing quantity multiplication.
 *  - WAPF groups whose placement rule had an empty condition evaluated FALSE
 *    in WAPF (dead groups). The mapper flags those rather than silently
 *    turning them into "show everywhere".
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class WapfMapper {

	/**
	 * WAPF field type → OPF field type.
	 */
	private const TYPE_MAP = [
		'text'          => 'text',
		'textarea'      => 'textarea',
		'url'           => 'url',
		'number'        => 'number',
		'toggle'        => 'toggle',
		'true-false'    => 'toggle',
		'select'        => 'select',
		'radio'         => 'radio',
		'checkbox'      => 'checkbox',
		'text-swatch'   => 'swatch',
		'image-swatch'  => 'swatch',
	];

	/**
	 * WAPF condition → OPF operator.
	 */
	private const CONDITION_MAP = [
		'is'        => 'is',
		'is_not'    => 'is_not',
		'not_is'    => 'is_not',
		'contains'  => 'contains',
		'gt'        => 'greater',
		'lt'        => 'less',
		'greater'   => 'greater',
		'less'      => 'less',
		'empty'     => 'empty',
		'not_empty' => 'not_empty',
	];

	/**
	 * Map a parsed WAPF group array to an OPF group data array.
	 *
	 * @param array<string,mixed> $wapf      Parsed WAPF group payload.
	 * @param array<string,mixed> $overrides Optional overrides: ['attach_product_ids' => int[]].
	 * @return array{group:array<string,mixed>,notes:string[],needs_review:bool}
	 */
	public static function map( array $wapf, array $overrides = [] ): array {
		$notes        = [];
		$needs_review = false;

		$fields        = [];
		$unsupported   = [];
		$seen_ids      = [];
		$legacy_ids    = [];

		foreach ( ( $wapf['fields'] ?? [] ) as $wapf_field ) {
			if ( ! is_array( $wapf_field ) ) {
				continue;
			}
			$wapf_type = (string) ( $wapf_field['type'] ?? 'text' );

			if ( ! isset( self::TYPE_MAP[ $wapf_type ] ) ) {
				$unsupported[] = $wapf_type . ':' . ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
				continue;
			}

			$field_id = self::field_id( (string) ( $wapf_field['label'] ?? '' ), (string) ( $wapf_field['id'] ?? '' ), $seen_ids );
			$seen_ids[ $field_id ] = true;
			$legacy_id = (string) ( $wapf_field['id'] ?? '' );
			if ( '' !== $legacy_id ) {
				$legacy_ids[ $legacy_id ] = $field_id;
			}

			$has_choices = in_array( self::TYPE_MAP[ $wapf_type ], [ 'swatch', 'select', 'radio', 'checkbox' ], true );

			$field = FieldGroup::normalize_field(
				[
					'id'           => $field_id,
					'label'        => (string) ( $wapf_field['label'] ?? '' ),
					'description'  => (string) ( $wapf_field['description'] ?? '' ),
					'type'         => self::TYPE_MAP[ $wapf_type ],
					'required'     => (bool) ( $wapf_field['required'] ?? false ),
					'width'        => (int) ( $wapf_field['width'] ?? 100 ),
					'css_class'    => (string) ( $wapf_field['class'] ?? '' ),
					'placeholder'  => (string) ( $wapf_field['options']['placeholder'] ?? '' ),
					'choices'      => $has_choices ? self::map_choices( $wapf_field, $notes ) : [],
					'pricing'      => self::map_field_pricing( $wapf_field ),
					'conditionals' => self::map_conditionals( $wapf_field, $notes, $seen_ids ),
				]
			);

			if ( ! empty( $wapf_field['clone']['enabled'] ) ) {
				$notes[]      = sprintf( 'field "%s" uses WAPF clone (repeatable fields) which OPF does not support yet.', $field['label'] );
				$needs_review = true;
			}

			$fields[] = $field;
		}

		if ( $unsupported ) {
			$notes[]      = 'unsupported field types dropped: ' . implode( ', ', $unsupported );
			$needs_review = true;
		}

		$placement = self::map_placement( $wapf, $overrides, $notes, $needs_review );
		$image_rule_mode = 'rules';
		$image_rules = self::map_gallery_images( $wapf, $fields, $legacy_ids, $notes, $needs_review, $image_rule_mode );

		$group = FieldGroup::normalize(
			[
				'fields'          => $fields,
				'rule_groups'     => $placement,
				'mark_required'   => (bool) ( $wapf['layout']['mark_required'] ?? true ),
				'labels_position' => ( $wapf['layout']['labels_position'] ?? 'above' ),
				'image_rules'     => $image_rules,
				'image_rule_mode' => $image_rule_mode,
			]
		);

		return [
			'group'        => $group,
			'notes'        => $notes,
			'needs_review' => $needs_review,
		];
	}

	/** Map WAPF's rules and last-changed-field gallery image modes. */
	private static function map_gallery_images( array $wapf, array $fields, array $legacy_ids, array &$notes, bool &$needs_review, string &$mode ): array {
		$layout = is_array( $wapf['layout'] ?? null ) ? $wapf['layout'] : [];
		if ( empty( $layout['enable_gallery_images'] ) ) {
			return [];
		}
		$mode = 'last' === ( $layout['swap_type'] ?? 'rules' ) ? 'last' : 'rules';
		if ( ! in_array( $layout['swap_type'] ?? 'rules', [ 'rules', 'last' ], true ) ) {
			$notes[] = 'WAPF gallery images use an unsupported mode and were not imported.';
			$needs_review = true;
			return [];
		}
		$source_images = $layout['gallery_images'] ?? [];
		if ( ! is_array( $source_images ) ) {
			$notes[] = 'WAPF gallery image rules are malformed and were not imported.';
			$needs_review = true;
			return [];
		}
		if ( count( $source_images ) > 64 ) {
			$notes[] = 'WAPF gallery image rules exceed OPF\'s 64-rule import limit; excess rules were not imported.';
			$needs_review = true;
		}
		$field_by_id = [];
		foreach ( $fields as $field ) {
			$field_by_id[ (string) $field['id'] ] = $field;
		}
		$rules = [];
		foreach ( array_slice( $source_images, 0, 64 ) as $source_image ) {
			if ( ! is_array( $source_image ) || ! is_scalar( $source_image['url'] ?? null ) || ! is_array( $source_image['values'] ?? null ) ) {
				$notes[] = 'A malformed WAPF gallery image rule was skipped.';
				$needs_review = true;
				continue;
			}
			$url = trim( (string) $source_image['url'] );
			if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7F]/', $url ) || ! preg_match( '#^(?:https?://[^/\s]+|/(?!/))#i', $url ) ) {
				$notes[] = 'A WAPF gallery image URL was invalid and its rule was skipped.';
				$needs_review = true;
				continue;
			}
			$conditions = [];
			if ( count( $source_image['values'] ) > 32 ) {
				$notes[] = 'A WAPF gallery image rule exceeds OPF\'s 32-condition import limit and was skipped.';
				$needs_review = true;
				continue;
			}
			$malformed = false;
			foreach ( array_slice( $source_image['values'], 0, 32 ) as $source_value ) {
				if ( ! is_array( $source_value ) || ! is_scalar( $source_value['field'] ?? null ) || ! is_scalar( $source_value['value'] ?? null ) ) {
					$malformed = true;
					break;
				}
				$field_id = $legacy_ids[ (string) $source_value['field'] ] ?? '';
				$value = trim( (string) $source_value['value'] );
				$field = $field_by_id[ $field_id ] ?? null;
				$choice_slugs = is_array( $field ) ? array_map( 'strval', array_column( $field['choices'] ?? [], 'slug' ) ) : [];
				$toggle_value = is_array( $field ) && 'toggle' === $field['type'] && in_array( $value, [ '0', '1' ], true );
				if ( ! $field || ! in_array( $field['type'], [ 'select', 'radio', 'checkbox', 'swatch', 'toggle' ], true ) || '' === $value || ( '*' !== $value && ! $toggle_value && ! in_array( $value, $choice_slugs, true ) ) ) {
					$conditions = [];
					break;
				}
				$conditions[] = [ 'field' => $field_id, 'value' => $value ];
			}
			if ( $malformed ) {
				$notes[] = 'A malformed WAPF gallery image condition caused its entire rule to be skipped.';
				$needs_review = true;
				continue;
			}
			if ( ! $conditions ) {
				$notes[] = 'A WAPF gallery image rule had no usable field values and was skipped.';
				$needs_review = true;
				continue;
			}
			$rules[] = [ 'target_url' => $url, 'conditions' => $conditions ];
		}
		return $rules;
	}

	/**
	 * Map choices with pricing.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @param string[]            $notes      Collector.
	 * @return array<int,array>
	 */
	private static function map_choices( array $wapf_field, array &$notes ): array {
		$choices = [];
		foreach ( ( $wapf_field['options']['choices'] ?? [] ) as $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}
			$slug  = (string) ( $choice['slug'] ?? '' );
			$ptype = (string) ( $choice['pricing_type'] ?? 'none' );
			$amt   = $choice['pricing_amount'] ?? 0;

			$pricing = [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];

			switch ( $ptype ) {
				case 'fixed':
					// WAPF fixed = flat fee per line (qty_based is an opt-in).
					$pricing = [ 'type' => 'fixed', 'amount' => (float) $amt, 'formula' => '', 'per_unit' => false ];
					break;
				case 'qt':
					// qt: amount*qty total → per-unit fixed.
					$pricing = [ 'type' => 'fixed', 'amount' => (float) $amt, 'formula' => '', 'per_unit' => true ];
					break;
				case 'percent':
				case 'p':
					$pricing = [ 'type' => 'percent', 'amount' => (float) $amt, 'formula' => '' ];
					break;
				case 'fx':
					$formula = self::normalize_formula( (string) $amt );
					if ( null === $formula ) {
						$notes[] = sprintf( 'choice "%s" formula could not be translated: %s', $choice['label'] ?? $slug, (string) $amt );
						$pricing = [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
					} else {
						// formula_raw keeps the legacy expression (incl. its qty
						// factor) for the theme's live-total display math.
						$pricing = [ 'type' => 'formula', 'amount' => 0.0, 'formula' => $formula, 'formula_raw' => (string) $amt, 'per_unit' => true ];
					}
					break;
				case 'none':
					break;
				default:
					$notes[] = sprintf( 'choice "%s" uses pricing type "%s" which is not supported; imported without pricing.', $choice['label'] ?? $slug, $ptype );
					break;
			}

			$choices[] = [
				'slug'     => $slug,
				'label'    => (string) ( $choice['label'] ?? '' ),
				'selected' => (bool) ( $choice['selected'] ?? false ),
				'disabled' => (bool) ( $choice['disabled'] ?? false ),
				'pricing'  => $pricing,
			];
		}
		return $choices;
	}

	/**
	 * WAPF formula → OPF formula.
	 *
	 * WAPF normalized per-unit results by dividing by quantity, so formulas
	 * in the wild end with "* [qty]" to compensate. OPF is per-unit, so a
	 * trailing quantity multiplication is stripped. Variables map 1:1
	 * ([price], [options_total]→[addons], [qty], [val]).
	 */
	public static function normalize_formula( string $formula ): ?string {
		$formula = trim( $formula );
		if ( '' === $formula ) {
			return null;
		}
		$formula = str_replace( '[options_total]', '[addons]', $formula );
		// Strip compensating quantity factor (repeat, e.g. "* [qty]" or "[qty]*").
		$changed = true;
		while ( $changed ) {
			$changed = false;
			if ( preg_match( '/\*\s*\[\s*qty\s*\]\s*$/i', $formula ) ) {
				$formula = substr( $formula, 0, strrpos( $formula, '*' ) );
				$formula = trim( $formula );
				$changed = true;
			} elseif ( preg_match( '/^\[\s*qty\s*\]\s*\*\s*/i', $formula ) ) {
				$formula = preg_replace( '/^\[\s*qty\s*\]\s*\*\s*/i', '', $formula );
				$formula = trim( (string) $formula );
				$changed = true;
			}
		}
		if ( '' === $formula ) {
			return null;
		}
		// Validate by round-tripping through the safe evaluator with sample vars.
		$probe = str_replace( [ '[price]', '[qty]', '[addons]', '[val]' ], '1', $formula );
		if ( preg_match( '/[^0-9+\-*\/().\s]/', $probe ) ) {
			return null;
		}
		return $formula;
	}

	/**
	 * Field-level pricing (text-like fields).
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @return array<string,mixed>
	 */
	private static function map_field_pricing( array $wapf_field ): array {
		$pricing = $wapf_field['pricing'] ?? [];
		if ( ! is_array( $pricing ) || empty( $pricing['enabled'] ) ) {
			return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
		}
		$type = (string) ( $pricing['type'] ?? 'none' );
		$amt  = (float) ( $pricing['amount'] ?? 0 );
		switch ( $type ) {
			case 'fixed':
			case 'qt':
				return [ 'type' => 'fixed', 'amount' => $amt, 'formula' => '' ];
			case 'percent':
			case 'p':
				return [ 'type' => 'percent', 'amount' => $amt, 'formula' => '' ];
			case 'fx':
				$formula = self::normalize_formula( (string) ( $pricing['amount'] ?? '' ) );
				if ( null !== $formula ) {
					return [ 'type' => 'formula', 'amount' => 0.0, 'formula' => $formula ];
				}
				break;
		}
		return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
	}

	/**
	 * Field conditionals.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @param string[]            $notes      Collector.
	 * @param array<string,bool>  $seen_ids   Known field ids (incl. later ones skipped below).
	 * @return array<int,array>
	 */
	private static function map_conditionals( array $wapf_field, array &$notes, array $seen_ids ): array {
		$out = [];
		foreach ( ( $wapf_field['conditionals'] ?? [] ) as $conditional ) {
			if ( ! is_array( $conditional ) ) {
				continue;
			}
			$rules = [];
			foreach ( ( $conditional['rules'] ?? [] ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$condition = (string) ( $rule['condition'] ?? '' );
				$operator  = self::CONDITION_MAP[ $condition ] ?? null;
				$subject   = (string) ( $rule['subject'] ?? '' );
				if ( null === $operator || '' === $subject ) {
					$notes[] = sprintf( 'conditional rule with condition "%s" dropped.', $condition );
					continue;
				}
				$value = $rule['value'] ?? '';
				if ( is_array( $value ) ) {
					$value = implode( ', ', array_map( 'strval', $value ) );
				}
				$rules[] = [
					'field'    => $subject,
					'operator' => $operator,
					'value'    => (string) $value,
				];
			}
			if ( $rules ) {
				$out[] = [
					'action' => 'show',
					'logic'  => 'all',
					'rules'  => $rules,
				];
			}
		}
		return $out;
	}

	/**
	 * Placement rule groups.
	 *
	 * @param array<string,mixed> $wapf      WAPF group.
	 * @param array<string,mixed> $overrides attach_product_ids.
	 * @param string[]            $notes     Collector.
	 * @param bool                $needs_review Flag ref.
	 * @return array<int,array>
	 */
	private static function map_placement( array $wapf, array $overrides, array &$notes, bool &$needs_review ): array {
		if ( ! empty( $overrides['attach_product_ids'] ) ) {
			return [
				[ 'rules' => [
					[
						'subject'  => 'product',
						'operator' => 'in',
						'terms'    => array_map( 'strval', (array) $overrides['attach_product_ids'] ),
					],
				] ],
			];
		}

		$out = [];
		foreach ( ( $wapf['rule_groups'] ?? [] ) as $rule_group ) {
			$rules = [];
			foreach ( ( $rule_group['rules'] ?? [] ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$condition = (string) ( $rule['condition'] ?? '' );
				$subject   = (string) ( $rule['subject'] ?? '' );
				$value     = $rule['value'] ?? null;

				// WAPF evaluated empty conditions as FALSE (dead rule). Flag,
				// don't silently broaden scope.
				if ( '' === $condition ) {
					$notes[]      = 'group had a WAPF rule with empty condition which WAPF evaluated as never-matching; imported as review-needed.';
					$needs_review = true;
					continue;
				}

				$negate = isset( $condition[0] ) && '!' === $condition[0];
				$cond   = ltrim( $condition, '!' );

				$map = [
					'product'      => 'product',
					'products'     => 'product',
					'product_cat'  => 'product_cat',
					'product_cats' => 'product_cat',
					'p_tags'       => 'product_tag',
					'product_tag'  => 'product_tag',
				];
				if ( ! isset( $map[ $cond ] ) ) {
					$notes[]      = sprintf( 'placement condition "%s" has no OPF equivalent; rule dropped.', $condition );
					$needs_review = true;
					continue;
				}

				$terms = [];
				if ( is_array( $value ) ) {
					foreach ( $value as $v ) {
						if ( is_array( $v ) && isset( $v['id'] ) ) {
							$terms[] = (string) $v['id'];
						} elseif ( is_scalar( $v ) ) {
							$terms[] = (string) $v;
						}
					}
				} elseif ( is_scalar( $value ) && '' !== (string) $value ) {
					$terms[] = (string) $value;
				}

				$rules[] = [
					'subject'  => $map[ $cond ],
					'operator' => ( $negate ? 'not_in' : 'in' ),
					'terms'    => $terms,
				];
			}
			if ( $rules ) {
				$out[] = [ 'rules' => $rules ];
			}
		}
		return $out;
	}

	/**
	 * Derive a stable, human-readable field id.
	 *
	 * @param string             $label    Field label.
	 * @param string             $fallback WAPF hex id.
	 * @param array<string,bool> $seen     Already-used ids.
	 */
	private static function field_id( string $label, string $fallback, array $seen ): string {
		$slug = self::slugify( $label );
		if ( '' === $slug ) {
			$slug = 'field';
		}
		$candidate = $slug;
		$i         = 2;
		while ( isset( $seen[ $candidate ] ) ) {
			$candidate = $slug . '-' . $i;
			$i++;
		}
		return $candidate;
	}

	/**
	 * WP-independent slugify (mirrors sanitize_title for latin/extended-latin).
	 *
	 * @param string $text Text to slugify.
	 */
	public static function slugify( string $text ): string {
		$text = mb_strtolower( $text, 'UTF-8' );
		$translit = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
		if ( false !== $translit && '' !== trim( $translit ) ) {
			$text = strtolower( $translit );
		}
		$text = preg_replace( '/[^a-z0-9]+/', '-', $text );
		$text = trim( (string) $text, '-' );
		return substr( $text, 0, 40 );
	}
}
