<?php
/**
 * WAPF parity: variation-scoped placement/conditional rules
 * (`product_var` / `var_att`) and generated frontend gates.
 *
 * Reference: WAPF Extended 3.1.5
 *  - includes/classes/class-conditions.php (check / product_has_attribute_values)
 *  - includes/classes/class-fields.php::is_valid_rule
 *  - assets/js/frontend.min.js::isValidRule
 */

namespace OPF\Tests\Unit;

use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class VariationRulesTest extends TestCase {

	protected function tearDown(): void {
		Evaluator::set_variation_context( null );
		Evaluator::set_context_product( null );
	}

	private function ctx( int $id, array $attributes = [] ): array {
		return [ 'variable' => true, 'id' => $id, 'attributes' => $attributes ];
	}

	public function test_non_variable_context_passes_every_variation_rule(): void {
		$rule = [ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '55' ] ];
		$this->assertTrue( Evaluator::variation_rule_passes( $rule, [ 'variable' => false, 'id' => 0, 'attributes' => [] ] ) );
		$negated = [ 'subject' => 'product_var', 'operator' => 'not_in', 'terms' => [ '55' ] ];
		$this->assertTrue( Evaluator::variation_rule_passes( $negated, [ 'variable' => false, 'id' => 0, 'attributes' => [] ] ) );
	}

	public function test_no_selected_variation_fails_positive_and_negated_rules(): void {
		$this->assertFalse( Evaluator::variation_rule_passes( [ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '55' ] ], $this->ctx( 0 ) ) );
		$this->assertFalse( Evaluator::variation_rule_passes( [ 'subject' => 'product_var', 'operator' => 'not_in', 'terms' => [ '55' ] ], $this->ctx( 0 ) ) );
		$this->assertFalse( Evaluator::variation_rule_passes( [ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|red' ] ], $this->ctx( 0 ) ) );
	}

	public function test_product_var_positive_and_negated(): void {
		$in = [ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '55', '56' ] ];
		$this->assertTrue( Evaluator::variation_rule_passes( $in, $this->ctx( 55 ) ) );
		$this->assertFalse( Evaluator::variation_rule_passes( $in, $this->ctx( 57 ) ) );

		$not_in = [ 'subject' => 'product_var', 'operator' => 'not_in', 'terms' => [ '55' ] ];
		$this->assertFalse( Evaluator::variation_rule_passes( $not_in, $this->ctx( 55 ) ) );
		$this->assertTrue( Evaluator::variation_rule_passes( $not_in, $this->ctx( 57 ) ) );
	}

	public function test_var_att_matches_taxonomy_attribute(): void {
		$rule = [ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|red' ] ];
		$this->assertTrue( Evaluator::variation_rule_passes( $rule, $this->ctx( 55, [ 'attribute_pa_color' => 'red' ] ) ) );
		$this->assertFalse( Evaluator::variation_rule_passes( $rule, $this->ctx( 55, [ 'attribute_pa_color' => 'blue' ] ) ) );
		$this->assertFalse( Evaluator::variation_rule_passes( $rule, $this->ctx( 55, [] ) ) );
	}

	public function test_var_att_matches_custom_attribute_fallback(): void {
		$rule = [ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'size|large' ] ];
		$this->assertTrue( Evaluator::variation_rule_passes( $rule, $this->ctx( 55, [ 'attribute_size' => 'large' ] ) ) );
	}

	public function test_var_att_wildcard_requires_non_empty_value(): void {
		$rule = [ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|*' ] ];
		$this->assertTrue( Evaluator::variation_rule_passes( $rule, $this->ctx( 55, [ 'attribute_pa_color' => 'red' ] ) ) );
		$this->assertFalse( Evaluator::variation_rule_passes( $rule, $this->ctx( 55, [ 'attribute_pa_color' => '' ] ) ) );
	}

	public function test_var_att_any_term_wins(): void {
		$rule = [ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|red', 'size|large' ] ];
		$this->assertTrue( Evaluator::variation_rule_passes( $rule, $this->ctx( 55, [ 'attribute_pa_size' => 'large' ] ) ) );
	}

	public function test_var_att_negated(): void {
		$rule = [ 'subject' => 'var_att', 'operator' => 'not_in', 'terms' => [ 'color|red' ] ];
		$this->assertFalse( Evaluator::variation_rule_passes( $rule, $this->ctx( 55, [ 'attribute_pa_color' => 'red' ] ) ) );
		$this->assertTrue( Evaluator::variation_rule_passes( $rule, $this->ctx( 55, [ 'attribute_pa_color' => 'blue' ] ) ) );
	}

	public function test_injected_variation_gate_controls_visibility(): void {
		$field = [
			'id'           => 'f',
			'type'         => 'text',
			'conditionals' => [
				[
					'action'    => 'var',
					'logic'     => 'all',
					'generated' => true,
					'rules'     => [ [ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '55' ] ] ],
				],
			],
		];

		$this->assertTrue( Evaluator::is_visible( $field + [ '_var_ctx' => $this->ctx( 55 ) ], [] ) );
		$this->assertFalse( Evaluator::is_visible( $field + [ '_var_ctx' => $this->ctx( 56 ) ], [] ) );
		$this->assertFalse( Evaluator::is_visible( $field + [ '_var_ctx' => $this->ctx( 0 ) ], [] ) );
		$this->assertTrue( Evaluator::is_visible( $field + [ '_var_ctx' => [ 'variable' => false, 'id' => 0, 'attributes' => [] ] ], [] ) );
	}

	public function test_variation_gate_ands_with_show_conditionals(): void {
		$field = [
			'id'           => 'f',
			'type'         => 'text',
			'conditionals' => [
				[ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'a', 'operator' => 'is', 'value' => 'yes' ] ] ],
				[ 'action' => 'var', 'logic' => 'all', 'generated' => true, 'rules' => [ [ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '55' ] ] ] ],
			],
		];
		$this->assertTrue( Evaluator::is_visible( $field + [ '_var_ctx' => $this->ctx( 55 ) ], [ 'a' => 'yes' ] ) );
		$this->assertFalse( Evaluator::is_visible( $field + [ '_var_ctx' => $this->ctx( 55 ) ], [ 'a' => 'no' ] ) );
		$this->assertFalse( Evaluator::is_visible( $field + [ '_var_ctx' => $this->ctx( 56 ) ], [ 'a' => 'yes' ] ) );
	}

	public function test_group_matches_product_var_and_reports_variation_rules(): void {
		$group = [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '55' ] ],
		] ] ] ];

		$variation_rules = [];
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_var' => [ '55', '56' ] ], 10, [], $variation_rules ) );
		$this->assertCount( 1, $variation_rules );
		$this->assertSame( 'product_var', $variation_rules[0]['subject'] );
		$this->assertSame( 'in', $variation_rules[0]['operator'] );
		$this->assertSame( [ '55' ], $variation_rules[0]['terms'] );

		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_var' => [] ], 10 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [], 10 ) );
	}

	/**
	 * WAPF 3.1.5 class-conditions.php `check` defect: both `product_var` and
	 * `!product_var` share the positive `is_product_variation === true` branch.
	 * The merged frontend rule performs the real negation.
	 */
	public function test_group_product_var_negation_is_positive_like_wapf(): void {
		$group = [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product_var', 'operator' => 'not_in', 'terms' => [ '55' ] ],
		] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_var' => [ '55' ] ], 10 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_var' => [] ], 10 ) );

		$variation_rules = [];
		Evaluator::group_matches( $group, [ 'product_var' => [ '55' ] ], 10, [], $variation_rules );
		$this->assertSame( 'not_in', $variation_rules[0]['operator'], 'Merged rule keeps the real negation.' );
	}

	public function test_group_matches_var_att_positive_and_negated(): void {
		$positive = [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|red' ] ],
		] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $positive, [ 'var_att' => [ 'color|red', 'color|*' ] ], 10 ) );
		$this->assertFalse( Evaluator::group_matches( $positive, [ 'var_att' => [ 'color|blue' ] ], 10 ) );

		$negated = [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'var_att', 'operator' => 'not_in', 'terms' => [ 'color|red' ] ],
		] ] ] ];
		$this->assertFalse( Evaluator::group_matches( $negated, [ 'var_att' => [ 'color|red' ] ], 10 ) );
		$this->assertTrue( Evaluator::group_matches( $negated, [ 'var_att' => [ 'color|blue' ] ], 10 ) );
	}

	public function test_inject_variation_rules_is_idempotent_and_sets_context(): void {
		$group = new FieldGroup( [
			'fields' => [
				[ 'id' => 'a', 'type' => 'text' ],
				[ 'id' => 'b', 'type' => 'text', 'conditionals' => [
					[ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'a', 'operator' => 'not_empty', 'value' => '' ] ] ],
				] ],
			],
		] );
		$rules   = [ [ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '55' ] ] ];
		$context = $this->ctx( 55, [ 'attribute_pa_color' => 'red' ] );

		$group->inject_variation_rules( $rules, $context );
		$group->inject_variation_rules( $rules, $context );

		foreach ( $group->data['fields'] as $field ) {
			$variation_gates = array_values( array_filter(
				$field['conditionals'],
				static fn ( $c ) => is_array( $c ) && 'var' === ( $c['action'] ?? '' )
			) );
			$this->assertCount( 1, $variation_gates, 'Re-injection must not stack generated gates.' );
			$this->assertSame( 'product_var', $variation_gates[0]['rules'][0]['subject'] );
			$this->assertSame( $context, $field['_var_ctx'] );
		}
	}

	public function test_inject_variation_rules_empty_clears_context_and_gate(): void {
		$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'a', 'type' => 'text' ] ] ] );
		$group->inject_variation_rules( [ [ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|red' ] ] ], $this->ctx( 55 ) );
		$group->inject_variation_rules( [] );

		$field = $group->data['fields'][0];
		$this->assertArrayNotHasKey( '_var_ctx', $field );
		$this->assertSame( [], $field['conditionals'] );
	}

	public function test_field_level_variation_conditionals_are_normalized(): void {
		$group = new FieldGroup( [
			'fields' => [
				[
					'id'           => 'f',
					'type'         => 'text',
					'conditionals' => [
						[
							'action' => 'show',
							'logic'  => 'all',
							'rules'  => [
								[ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ [ 'id' => '55', 'text' => '#55' ], [ 'id' => '56', 'text' => '#56' ] ] ],
								[ 'subject' => 'var_att', 'operator' => 'not_in', 'terms' => [ [ 'id' => 'color|red', 'text' => 'Red' ] ] ],
							],
						],
					],
				],
			],
		] );

		$conditionals = $group->data['fields'][0]['conditionals'];
		$this->assertCount( 1, $conditionals );
		$this->assertSame( 'product_var', $conditionals[0]['rules'][0]['subject'] );
		$this->assertSame( [ '55', '56' ], $conditionals[0]['rules'][0]['terms'] );
		$this->assertSame( 'var_att', $conditionals[0]['rules'][1]['subject'] );
		$this->assertSame( 'not_in', $conditionals[0]['rules'][1]['operator'] );
		$this->assertSame( [ 'color|red' ], $conditionals[0]['rules'][1]['terms'] );
	}

	public function test_product_variation_context_for_variation_product(): void {
		$variation = new class {
			public function get_type(): string {
				return 'variation';
			}
			public function is_type( string $type ): bool {
				return 'variation' === $type;
			}
			public function get_id(): int {
				return 55;
			}
			public function get_variation_attributes(): array {
				return [ 'attribute_pa_color' => 'red' ];
			}
		};

		$ctx = Evaluator::product_variation_context( $variation );
		$this->assertTrue( $ctx['variable'] );
		$this->assertSame( 55, $ctx['id'] );
		$this->assertSame( [ 'attribute_pa_color' => 'red' ], $ctx['attributes'] );
	}

	public function test_product_variation_context_false_for_simple_product(): void {
		$simple = new class {
			public function get_type(): string {
				return 'simple';
			}
		};
		$this->assertSame( [ 'variable' => false, 'id' => 0, 'attributes' => [] ], Evaluator::product_variation_context( $simple ) );
	}

	public function test_request_variation_context_reads_posted_variation(): void {
		$parent             = new class {
			public function get_type(): string {
				return 'variable';
			}
		};
		$_POST['variation_id']    = '56';
		$_POST['attribute_pa_color'] = 'blue';
		try {
			$ctx = Evaluator::product_variation_context( $parent );
		} finally {
			unset( $_POST['variation_id'], $_POST['attribute_pa_color'] );
		}
		$this->assertTrue( $ctx['variable'] );
		$this->assertSame( 56, $ctx['id'] );
		$this->assertSame( 'blue', $ctx['attributes']['attribute_pa_color'] );
	}
}
