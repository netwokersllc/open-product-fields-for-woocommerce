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
	 * @param array<string,mixed> $rule  Normalized rule.
	 * @param string|array        $value Current value.
	 */
	public static function rule_passes( array $rule, $value ): bool {
		$actual = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		$expect = (string) ( $rule['value'] ?? '' );

		switch ( $rule['operator'] ) {
			case 'is':
				return is_array( $value ) ? in_array( $expect, array_map( 'strval', $value ), true ) : $actual === $expect;
			case 'is_not':
				return ! self::rule_passes( [ 'field' => $rule['field'], 'operator' => 'is', 'value' => $expect ], $value );
			case 'contains':
				return false !== strpos( strtolower( $actual ), strtolower( $expect ) );
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
	 * Does a field group's placement rules match a product?
	 *
	 * Empty rule_groups means "everywhere" (explicit OPF semantics — WAPF's
	 * empty-condition fall-through-to-false footgun is deliberately not replicated).
	 *
	 * @param array<string,mixed> $group       Normalized group data.
	 * @param array<string, array<int|string>> $has_terms subject => term ids the product belongs to, e.g. ['product_cat' => [1,2]].
	 * @param int                 $product_id  Current product id.
	 */
	public static function group_matches( array $group, array $has_terms, int $product_id ): bool {
		$rule_groups = $group['rule_groups'] ?? [];
		if ( empty( $rule_groups ) ) {
			return true;
		}
		foreach ( $rule_groups as $rule_group ) {
			$group_ok = true;
			foreach ( $rule_group['rules'] as $rule ) {
				if ( ! self::placement_rule_passes( $rule, $has_terms, $product_id ) ) {
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
	private static function placement_rule_passes( array $rule, array $has_terms, int $product_id ): bool {
		$subject = $rule['subject'];

		if ( 'product' === $subject ) {
			$in = in_array( (string) $product_id, $rule['terms'], true );
			return 'not_in' === $rule['operator'] ? ! $in : $in;
		}

		$terms = array_map( 'strval', $has_terms[ $subject ] ?? [] );
		$in    = ! empty( array_intersect( $rule['terms'], $terms ) );
		return 'not_in' === $rule['operator'] ? ! $in : $in;
	}
}
