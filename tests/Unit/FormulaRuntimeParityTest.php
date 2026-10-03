<?php
namespace OPF\Tests\Unit;

use OPF\Engine\Calculator;
use PHPUnit\Framework\TestCase;

final class FormulaRuntimeParityTest extends TestCase {
	public function test_function_arguments_use_wapf_semicolons_and_keep_literal_commas(): void {
		$this->assertSame( 8.0, Calculator::evaluate_formula( 'pow(2;3)', 10, 1, 0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'pow(2,3)', 10, 1, 0 ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( 'len(a,b)', 10, 1, 0 ) );
	}

	public function test_scientific_function_results_survive_nested_and_following_arithmetic(): void {
		foreach ( [ 'min(0.00001;1)*100000', 'pow(2;-24)*16777216', 'max(pow(2;-24);0)*16777216', 'pow(10;22)/10000000000000000000000' ] as $formula ) {
			$this->assertEqualsWithDelta( 1.0, Calculator::evaluate_formula( $formula, 10, 1, 0 ), 0.000000001, $formula );
		}
	}

	public function test_decimal_and_exponent_literals_consume_the_whole_expression(): void {
		$this->assertSame( 1.0, Calculator::evaluate_formula( '.5 + 5e-1', 10, 1, 0 ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( '1E+3 / 1000', 10, 1, 0 ) );
		foreach ( [ '2 junk', '2 3', '2e', '2e+', '(2+3', '2+3)' ] as $formula ) {
			$this->assertSame( 0.0, Calculator::evaluate_formula( $formula, 10, 1, 0 ), $formula );
		}
	}

	public function test_choice_image_and_repeat_fields_keep_signed_formula_contributions(): void {
		$pricing = [ 'type' => 'formula', 'formula' => 'min(-5;0)', 'per_unit' => true ];
		$field = [ 'type' => 'select', 'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'disabled' => false, 'pricing' => $pricing ] ] ];
		$this->assertSame( -5.0, Calculator::field_addon( $field, 'a', [ 'price' => 10, 'qty' => 3 ] ) );
		$field['type'] = 'image_quantity';
		$this->assertSame( -5.0, Calculator::field_addon( $field, [ '_opf_type' => 'image_quantity', 'quantities' => [ 'a' => 2 ] ], [ 'price' => 10, 'qty' => 3 ] ) );
		$field['type'] = 'select';
		$field['repeat'] = [ 'enabled' => true, 'mode' => 'button' ];
		$this->assertSame( -10.0, Calculator::field_addon( $field, [ 'a', 'a' ], [ 'price' => 10, 'qty' => 3 ] ) );
	}
}
