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
		'true-false'   => 'toggle',
		'select'        => 'select',
		'radio'         => 'radio',
		'checkbox'      => 'checkbox',
		'text-swatch'   => 'swatch',
		'image-swatch'  => 'swatch',
		'content'       => 'paragraph',
		'paragraph'     => 'paragraph',
		'p'             => 'paragraph',
	];

	/**
	 * WAPF condition → OPF operator.
	 */
	private const CONDITION_MAP = [
		'is'        => 'is',
		'=='        => 'is',
		'is_not'    => 'is_not',
		'not_is'    => 'is_not',
		'!='        => 'is_not',
		'contains'  => 'contains',
		'==contains' => 'contains',
		'!=contains' => 'not_contains',
		'gt'        => 'greater',
		'lt'        => 'less',
		'greater'   => 'greater',
		'less'      => 'less',
		'empty'     => 'empty',
		'not_empty' => 'not_empty',
		'!empty'    => 'not_empty',
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
		$opf_ids_by_index = [];
		$opf_ids_by_wapf_id = [];
		$source_fields = is_array( $wapf['fields'] ?? null ) ? $wapf['fields'] : [];

		// Generate every destination ID first so conditional references can point
		// forward or backward in the source field order.
		foreach ( $source_fields as $index => $wapf_field ) {
			if ( ! is_array( $wapf_field ) ) {
				$notes[] = sprintf( 'field at index %s is malformed and was skipped.', (string) $index );
				$needs_review = true;
				continue;
			}
			$wapf_type = (string) ( $wapf_field['type'] ?? 'text' );
			if ( ! isset( self::TYPE_MAP[ $wapf_type ] ) ) {
				$unsupported[] = $wapf_type . ':' . ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
				continue;
			}
			$field_id = self::field_id( (string) ( $wapf_field['label'] ?? '' ), (string) ( $wapf_field['id'] ?? '' ), $seen_ids );
			$seen_ids[ $field_id ] = true;
			$opf_ids_by_index[ $index ] = $field_id;
			$source_id = is_scalar( $wapf_field['id'] ?? null ) ? (string) $wapf_field['id'] : '';
			if ( '' !== $source_id ) {
				if ( array_key_exists( $source_id, $opf_ids_by_wapf_id ) ) {
					$opf_ids_by_wapf_id[ $source_id ] = null;
					$notes[] = sprintf( 'WAPF field ID "%s" is duplicated; conditions referencing it need review.', $source_id );
					$needs_review = true;
				} else {
					$opf_ids_by_wapf_id[ $source_id ] = $field_id;
				}
			}
		}

		foreach ( $source_fields as $index => $wapf_field ) {
			if ( ! is_array( $wapf_field ) || ! isset( $opf_ids_by_index[ $index ] ) ) {
				continue;
			}
			$wapf_type = (string) ( $wapf_field['type'] ?? 'text' );
			$field_id = $opf_ids_by_index[ $index ];

			$has_choices = in_array( self::TYPE_MAP[ $wapf_type ], [ 'swatch', 'select', 'radio', 'checkbox' ], true );
			$content = '';
			if ( 'paragraph' === self::TYPE_MAP[ $wapf_type ] ) {
				$content = (string) ( $wapf_field['options']['p_content'] ?? $wapf_field['p_content'] ?? '' );
				if ( preg_match( '/<\/?[a-z][^>]*>/i', $content ) ) {
					$notes[] = sprintf( 'field "%s" contains HTML; the plain-text paragraph was imported with markup removed.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
					$content = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $content ) : strip_tags( $content );
				}
			}

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
					'choices'      => $has_choices ? self::map_choices( $wapf_field, $notes, $needs_review ) : [],
					'pricing'      => self::map_field_pricing( $wapf_field, $notes, $needs_review ),
					'conditionals' => self::map_conditionals( $wapf_field, $notes, $opf_ids_by_wapf_id, $needs_review ),
					'content'      => $content,
				]
			);
			if ( 'paragraph' === $field['type'] ) {
				if ( ! empty( $wapf_field['required'] ) ) {
					$notes[] = sprintf( 'field "%s" is static content; its required setting was removed.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
				}
				if ( ! empty( $wapf_field['pricing']['enabled'] ) ) {
					$notes[] = sprintf( 'field "%s" is static content; its field pricing was removed.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
				}
			}

			if ( 'image-swatch' === $wapf_type ) {
				$notes[] = sprintf( 'field "%s" is an image swatch; its choice images are not available in OPF and were imported as text choices.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
				$needs_review = true;
			}

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

		$group = FieldGroup::normalize(
			[
				'fields'          => $fields,
				'rule_groups'     => $placement,
				'mark_required'   => (bool) ( $wapf['layout']['mark_required'] ?? true ),
				'labels_position' => ( $wapf['layout']['labels_position'] ?? 'above' ),
			]
		);

		return [
			'group'        => $group,
			'notes'        => $notes,
			'needs_review' => $needs_review,
		];
	}

	/**
	 * Map choices with pricing.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @param string[]            $notes      Collector.
	 * @return array<int,array>
	 */
	private static function map_choices( array $wapf_field, array &$notes, bool &$needs_review ): array {
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
						$needs_review = true;
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
					$needs_review = true;
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
	private static function map_field_pricing( array $wapf_field, array &$notes, bool &$needs_review ): array {
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
				$notes[] = sprintf( 'field "%s" formula could not be translated: %s', (string) ( $wapf_field['label'] ?? '?' ), (string) ( $pricing['amount'] ?? '' ) );
				$needs_review = true;
				break;
		}
		$notes[] = sprintf( 'field "%s" uses pricing type "%s" which is not supported.', (string) ( $wapf_field['label'] ?? '?' ), $type );
		$needs_review = true;
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
	private static function map_conditionals( array $wapf_field, array &$notes, array $opf_ids_by_wapf_id, bool &$needs_review ): array {
		$out = [];
		$conditionals = $wapf_field['conditionals'] ?? [];
		if ( ! is_array( $conditionals ) ) {
			$notes[] = sprintf( 'field "%s" has malformed conditional data.', (string) ( $wapf_field['label'] ?? '?' ) );
			$needs_review = true;
			return $out;
		}
		foreach ( $conditionals as $conditional ) {
			if ( ! is_array( $conditional ) ) {
				$notes[] = sprintf( 'field "%s" has a malformed conditional block.', (string) ( $wapf_field['label'] ?? '?' ) );
				$needs_review = true;
				continue;
			}
			$rules = [];
			$source_rules = $conditional['rules'] ?? [];
			if ( ! is_array( $source_rules ) ) {
				$notes[] = sprintf( 'field "%s" has a malformed conditional rule list.', (string) ( $wapf_field['label'] ?? '?' ) );
				$needs_review = true;
				continue;
			}
			foreach ( $source_rules as $rule ) {
				if ( ! is_array( $rule ) ) {
					$notes[] = sprintf( 'field "%s" has a malformed conditional rule.', (string) ( $wapf_field['label'] ?? '?' ) );
					$needs_review = true;
					continue;
				}
				$condition = (string) ( $rule['condition'] ?? '' );
				$operator  = self::CONDITION_MAP[ $condition ] ?? null;
				$source_field_id = is_scalar( $rule['field'] ?? $rule['subject'] ?? null ) ? (string) ( $rule['field'] ?? $rule['subject'] ) : '';
				$subject = isset( $opf_ids_by_wapf_id[ $source_field_id ] ) && is_string( $opf_ids_by_wapf_id[ $source_field_id ] )
					? $opf_ids_by_wapf_id[ $source_field_id ]
					: '';
				if ( 'check' === $condition ) {
					$operator = 'is';
				} elseif ( '!check' === $condition ) {
					$operator = 'is_not';
				}
				if ( null === $operator || '' === $subject ) {
					$notes[] = '' === $subject
						? sprintf( 'conditional rule references unavailable or ambiguous field ID "%s".', $source_field_id )
						: sprintf( 'conditional rule with condition "%s" dropped.', $condition );
					$needs_review = true;
					continue;
				}
				$value = in_array( $condition, [ 'check', '!check' ], true ) ? '1' : ( $rule['value'] ?? '' );
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
			$product_rule = [
				'subject'  => 'product',
				'operator' => 'in',
				'terms'    => array_map( 'strval', (array) $overrides['attach_product_ids'] ),
			];
			$source_groups = $wapf['rule_groups'] ?? [];
			if ( ! $source_groups ) {
				return [ [ 'rules' => [ $product_rule ] ] ];
			}
			$attached_groups = [];
			foreach ( $source_groups as $source_group ) {
				$has_user_condition = false;
				$user_rules = [];
				foreach ( ( $source_group['rules'] ?? [] ) as $source_rule ) {
					if ( ! is_array( $source_rule ) ) {
						continue;
					}
					$condition = ltrim( (string) ( $source_rule['condition'] ?? '' ), '!' );
					if ( ! in_array( $condition, [ 'auth', 'role', 'lang' ], true ) ) {
						continue;
					}
					$has_user_condition = true;
					$mapped = self::map_user_placement_rule( $source_rule, $notes, $needs_review );
					if ( null !== $mapped ) {
						$user_rules[] = $mapped;
					}
				}
				// A source OR group with no user restriction makes the local group
				// available to every visitor; preserve that by returning host-only.
				if ( ! $has_user_condition ) {
					return [ [ 'rules' => [ $product_rule ] ] ];
				}
				$attached_groups[] = [ 'rules' => array_merge( [ $product_rule ], $user_rules ) ];
			}
			return $attached_groups ?: [ [ 'rules' => [ $product_rule ] ] ];
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
				$cond = ltrim( $condition, '!' );
				if ( in_array( $cond, [ 'auth', 'role', 'lang' ], true ) ) {
					$mapped = self::map_user_placement_rule( $rule, $notes, $needs_review );
					if ( null !== $mapped ) {
						$rules[] = $mapped;
					}
					continue;
				}
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
	 * Convert one WAPF user-context group rule without losing its target.
	 *
	 * @param array<string,mixed> $rule WAPF placement rule.
	 * @param string[]            $notes Collector.
	 */
	private static function map_user_placement_rule( array $rule, array &$notes, bool &$needs_review ): ?array {
		$condition = (string) ( $rule['condition'] ?? '' );
		$negate = isset( $condition[0] ) && '!' === $condition[0];
		$cond = ltrim( $condition, '!' );
		$value = $rule['value'] ?? null;
		if ( 'auth' === $cond ) {
			if ( ! empty( $value ) ) {
				$notes[] = sprintf( 'login visibility rule "%s" unexpectedly has a value and needs review.', $condition );
				$needs_review = true;
				return null;
			}
			return [ 'subject' => 'user_auth', 'operator' => $negate ? 'not_in' : 'in', 'terms' => [ 'logged_in' ] ];
		}
		$terms = [];
		if ( is_array( $value ) ) {
			foreach ( $value as $entry ) {
				if ( is_array( $entry ) && isset( $entry['id'] ) ) {
					$terms[] = (string) $entry['id'];
				} elseif ( is_scalar( $entry ) ) {
					$terms[] = (string) $entry;
				}
			}
		} elseif ( is_scalar( $value ) && '' !== (string) $value ) {
			$terms[] = (string) $value;
		}
		if ( ! in_array( $cond, [ 'role', 'lang' ], true ) || 1 !== count( $terms ) ) {
			$notes[] = sprintf( 'placement condition "%s" must have exactly one selected value; rule dropped.', $condition );
			$needs_review = true;
			return null;
		}
		return [
			'subject'  => 'role' === $cond ? 'user_role' : 'user_language',
			'operator' => $negate ? 'not_in' : 'in',
			'terms'    => $terms,
		];
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
