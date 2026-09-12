<?php
/**
 * Evaluator unit tests — field conditionals and placement rules.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\Evaluator;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase {

	private function field( array $conditionals ): array {
		return [ 'id' => 'f', 'type' => 'text', 'conditionals' => $conditionals ];
	}

	public function test_no_conditionals_is_visible(): void {
		$this->assertTrue( Evaluator::is_visible( $this->field( [] ), [] ) );
	}

	public function test_show_conditional_logic_all(): void {
		$field = $this->field(
			[ [ 'action' => 'show', 'logic' => 'all', 'rules' => [
				[ 'field' => 'a', 'operator' => 'is', 'value' => 'yes' ],
				[ 'field' => 'b', 'operator' => 'is', 'value' => 'yes' ],
			] ] ]
		);
		$this->assertFalse( Evaluator::is_visible( $field, [ 'a' => 'yes', 'b' => 'no' ] ) );
		$this->assertTrue( Evaluator::is_visible( $field, [ 'a' => 'yes', 'b' => 'yes' ] ) );
	}

	public function test_show_conditional_logic_any(): void {
		$field = $this->field(
			[ [ 'action' => 'show', 'logic' => 'any', 'rules' => [
				[ 'field' => 'a', 'operator' => 'is', 'value' => 'yes' ],
				[ 'field' => 'b', 'operator' => 'is', 'value' => 'yes' ],
			] ] ]
		);
		$this->assertTrue( Evaluator::is_visible( $field, [ 'a' => 'yes', 'b' => 'no' ] ) );
		$this->assertFalse( Evaluator::is_visible( $field, [ 'a' => 'no', 'b' => 'no' ] ) );
	}

	public function test_hide_conditional_wins(): void {
		$field = $this->field(
			[ [ 'action' => 'hide', 'logic' => 'all', 'rules' => [
				[ 'field' => 'a', 'operator' => 'is', 'value' => 'hide_me' ],
			] ] ]
		);
		$this->assertFalse( Evaluator::is_visible( $field, [ 'a' => 'hide_me' ] ) );
		$this->assertTrue( Evaluator::is_visible( $field, [ 'a' => 'keep' ] ) );
	}

	public function test_operators(): void {
		$check = fn ( string $operator, $value, string $expect = '5' ) => Evaluator::rule_passes(
			[ 'field' => 'f', 'operator' => $operator, 'value' => $expect ],
			$value
		);
		$this->assertTrue( $check( 'is', '5' ) );
		$this->assertTrue( $check( 'is', [ '4', '5' ] ) );
		$this->assertTrue( $check( 'is_not', '6' ) );
		$this->assertTrue( $check( 'contains', 'hello 5 world', '5' ) );
		$this->assertTrue( $check( 'greater', '7' ) );
		$this->assertFalse( $check( 'greater', '3' ) );
		$this->assertTrue( $check( 'less', '3' ) );
		$this->assertTrue( $check( 'empty', '' ) );
		$this->assertTrue( $check( 'not_empty', 'x' ) );
		$this->assertTrue( $check( 'not_empty', [ 'a', 'b' ] ) );
	}

	public function test_placement_empty_rules_match_everything(): void {
		$this->assertTrue( Evaluator::group_matches( [ 'rule_groups' => [] ], [], 42 ) );
	}

	public function test_placement_product_in(): void {
		$group = [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '42' ] ] ] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $group, [], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [], 43 ) );
	}

	public function test_placement_tag_not_in(): void {
		$group = [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product_tag', 'operator' => 'not_in', 'terms' => [ '9' ] ] ] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_tag' => [ 1, 2 ] ], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_tag' => [ 9 ] ], 42 ) );
	}

	public function test_placement_rules_within_group_are_and(): void {
		$group = [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ '1' ] ],
			[ 'subject' => 'product_tag', 'operator' => 'in', 'terms' => [ '2' ] ],
		] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_cat' => [ 1 ], 'product_tag' => [ 2 ] ], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_cat' => [ 1 ], 'product_tag' => [ 3 ] ], 42 ) );
	}

	public function test_placement_rule_groups_are_or(): void {
		$group = [ 'rule_groups' => [
			[ 'rules' => [ [ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ '1' ] ] ] ],
			[ 'rules' => [ [ 'subject' => 'product_tag', 'operator' => 'in', 'terms' => [ '2' ] ] ] ],
		] ];
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_cat' => [ 9 ], 'product_tag' => [ 2 ] ], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_cat' => [ 9 ], 'product_tag' => [ 3 ] ], 42 ) );
	}
}
