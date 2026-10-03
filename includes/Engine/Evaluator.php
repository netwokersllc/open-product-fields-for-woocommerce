<?php
/**
 * Conditional logic evaluator (server-side source of truth).
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class Evaluator {

	/**
	 * Should a field be shown, given current values?
	 *
	 * "show" conditionals: field shows if (logic applied over rules) is true.
	 * "hide" conditionals: field hides if it is true. A field with both kinds
	 * shows only when at least one show-conditional passes and no hide passes.
	 *
	 * @param array<string,mixed>          $field  Normalized field.
	 * @param array<string,string|array>   $values field_id => submitted value.
	 */
	public static function is_visible( array $field, array $values ): bool {
		if ( empty( $field['conditionals'] ) ) {
			return true;
		}

		$has_show  = false;
		$show_pass = false;
		$hide_pass = false;

		foreach ( $field['conditionals'] as $conditional ) {
			$passed = self::conditional_passes( $conditional, $values );
			if ( 'hide' === $conditional['action'] ) {
				$hide_pass = $hide_pass || $passed;
			} else {
				$has_show  = true;
				$show_pass = $show_pass || $passed;
			}
		}

		if ( $hide_pass ) {
			return false;
		}
		return $has_show ? $show_pass : true;
	}

	/**
	 * Evaluate one conditional block.
	 *
	 * @param array<string,mixed>        $conditional Normalized conditional.
	 * @param array<string,string|array> $values      Current values.
	 */
	public static function conditional_passes( array $conditional, array $values ): bool {
		$results = [];
		foreach ( $conditional['rules'] as $rule ) {
			$results[] = self::rule_passes( $rule, $values[ $rule['field'] ] ?? '' );
		}
		if ( empty( $results ) ) {
			return false;
		}
		return 'any' === $conditional['logic'] ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	/**
	 * Evaluate a single rule against a value.
	 *
	 * Quantity maps (`{slug: qty}` assoc arrays, or structured
	 * `{_opf_type, quantities}` product/image-quantity values) get WAPF
	 * qty-selector semantics instead of the generic string comparisons:
	 * zero/negative quantities are invisible, `empty` means "no positive
	 * quantity", `is`/`contains` mean "a positive quantity equals N" (WAPF's
	 * `in_array`/`indexOf` against the submitted quantity list — its admin
	 * forces a number input for these) with a documented OPF superset that
	 * also accepts "choice slug has a positive quantity", and `greater`/`less`
	 * compare the total of positive quantities. Sequential arrays keep their
	 * existing slug-list semantics, so non-qty multi-choice fields are
	 * unaffected.
	 *
	 * @param array<string,mixed> $rule  Normalized rule.
	 * @param string|array        $value Current value.
	 */
	public static function rule_passes( array $rule, $value ): bool {
		$qty_map = self::qty_map( $value );
		if ( null !== $qty_map ) {
			return self::qty_rule_passes( $rule, $qty_map );
		}
		$actual = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		$expect = (string) ( $rule['value'] ?? '' );

		switch ( $rule['operator'] ) {
			case 'is':
				return is_array( $value ) ? in_array( $expect, array_map( 'strval', $value ), true ) : $actual === $expect;
			case 'is_not':
				return ! self::rule_passes( [ 'field' => $rule['field'], 'operator' => 'is', 'value' => $expect ], $value );
			case 'contains':
				return false !== strpos( strtolower( $actual ), strtolower( $expect ) );
			case 'not_contains':
				return false === strpos( strtolower( $actual ), strtolower( $expect ) );
			case 'greater':
				return is_numeric( $actual ) && is_numeric( $expect ) && (float) $actual > (float) $expect;
			case 'less':
				return is_numeric( $actual ) && is_numeric( $expect ) && (float) $actual < (float) $expect;
			case 'empty':
				return '' === trim( $actual );
			case 'not_empty':
				return '' !== trim( $actual );
			default:
				return false;
		}
	}

	/**
	 * Is a submitted value a quantity map? Accepts both the raw posted
	 * `{slug: qty}` assoc array (request space) and the structured
	 * `{_opf_type: 'products'|'image_quantity'|'quantity', quantities: {...}}`
	 * value carried by the client registry and stored cart items.
	 *
	 * Sequential arrays are regular slug lists and return null; empty arrays
	 * are treated as qty maps of nothing (a qty field with all-zero values is
	 * indistinguishable from an untouched one, matching WAPF's zero-filtering).
	 *
	 * @param mixed $value Submitted value.
	 * @return array<string,mixed>|null
	 */
	public static function qty_map( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		if ( isset( $value['quantities'] ) && is_array( $value['quantities'] ) && in_array( (string) ( $value['_opf_type'] ?? '' ), [ 'products', 'image_quantity', 'quantity' ], true ) ) {
			return $value['quantities'];
		}
		if ( [] === $value ) {
			return null;
		}
		$keys = array_keys( $value );
		if ( $keys === range( 0, count( $value ) - 1 ) ) {
			return null; // Sequential slug list, not a qty map.
		}
		foreach ( $value as $slug => $qty ) {
			if ( ! is_scalar( $qty ) ) {
				return null;
			}
		}
		return $value;
	}

	/**
	 * WAPF qty-selector rule semantics over a `{slug: qty}` map.
	 *
	 * @param array<string,mixed>  $rule    Normalized rule.
	 * @param array<string,mixed>  $qty_map slug => submitted quantity.
	 */
	private static function qty_rule_passes( array $rule, array $qty_map ): bool {
		$expect   = (string) ( $rule['value'] ?? '' );
		$positive = [];
		foreach ( $qty_map as $slug => $qty ) {
			if ( is_numeric( $qty ) && (float) $qty > 0 ) {
				$positive[ (string) $slug ] = (float) $qty;
			}
		}
		$total = array_sum( $positive );

		switch ( $rule['operator'] ) {
			case 'empty':
				return [] === $positive;
			case 'not_empty':
				return [] !== $positive;
			case 'is':
			case 'contains':
				// WAPF `==`/`==contains` on qty fields matches a submitted
				// quantity (its rule value is a number input); OPF superset
				// also accepts a choice slug carrying a positive quantity.
				if ( is_numeric( $expect ) && in_array( (float) $expect, $positive, true ) ) {
					return true;
				}
				return '' !== $expect && isset( $positive[ $expect ] );
			case 'is_not':
			case 'not_contains':
				return ! self::qty_rule_passes( [ 'field' => $rule['field'], 'operator' => 'contains', 'value' => $expect ], $qty_map );
			case 'greater':
				return is_numeric( $expect ) && $total > (float) $expect;
			case 'less':
				return is_numeric( $expect ) && $total < (float) $expect;
			default:
				return false;
		}
	}

	/**
	 * Does a field group's placement rules match a product?
	 *
	 * Empty rule_groups means "everywhere" (explicit OPF semantics — WAPF's
	 * empty-condition fall-through-to-false footgun is deliberately not replicated).
	 *
	 * @param array<string,mixed> $group       Normalized group data.
	 * @param array<string, array<int|string>> $has_terms subject => term ids the product belongs to, e.g. ['product_cat' => [1,2]]. Subjects besides 'product'/'user_*' may be 'product_cat', 'product_tag', 'product_type' (type slugs), or any 'pa_*' attribute taxonomy (term ids).
	 * @param int                 $product_id  Current product id.
	 */
	public static function group_matches( array $group, array $has_terms, int $product_id, array $user_context = [] ): bool {
		$rule_groups = $group['rule_groups'] ?? [];
		if ( empty( $rule_groups ) ) {
			return true;
		}
		foreach ( $rule_groups as $rule_group ) {
			$group_ok = true;
			foreach ( $rule_group['rules'] as $rule ) {
				if ( ! self::placement_rule_passes( $rule, $has_terms, $product_id, $user_context ) ) {
					$group_ok = false;
					break;
				}
			}
			if ( $group_ok ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluate one placement rule.
	 *
	 * @param array<string,mixed> $rule       Normalized placement rule.
	 * @param array<string,array> $has_terms  subject => term ids.
	 */
	private static function placement_rule_passes( array $rule, array $has_terms, int $product_id, array $user_context ): bool {
		$subject = $rule['subject'];

		if ( 'product' === $subject ) {
			$in = in_array( (string) $product_id, $rule['terms'], true );
			return 'not_in' === $rule['operator'] ? ! $in : $in;
		}

		if ( 'user_auth' === $subject ) {
			$in = ! empty( $user_context['logged_in'] );
			if ( [ 'logged_in' ] === $rule['terms'] && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
				return 'not_in' === $rule['operator'] ? ! $in : $in;
			}
			if ( [] === $rule['terms'] && in_array( $rule['operator'], [ 'logged_in', 'logged_out' ], true ) ) {
				return 'logged_in' === $rule['operator'] ? $in : ! $in;
			}
			return false;
		}

		if ( 'user_role' === $subject ) {
			$roles = array_map( 'strval', (array) ( $user_context['roles'] ?? [] ) );
			$in    = ! empty( array_intersect( $rule['terms'], $roles ) );
			return 'not_in' === $rule['operator'] ? ! $in : $in;
		}

		if ( 'user_language' === $subject ) {
			$language = (string) ( $user_context['language'] ?? 'default' );
			$in       = in_array( $language, $rule['terms'], true );
			return 'not_in' === $rule['operator'] ? ! $in : $in;
		}

		if ( ! in_array( $subject, [ 'product_cat', 'product_tag', 'product_type' ], true ) && 0 !== strpos( $subject, 'pa_' ) ) {
			return false;
		}

		$terms = array_map( 'strval', $has_terms[ $subject ] ?? [] );
		$in    = ! empty( array_intersect( $rule['terms'], $terms ) );
		return 'not_in' === $rule['operator'] ? ! $in : $in;
	}
}
