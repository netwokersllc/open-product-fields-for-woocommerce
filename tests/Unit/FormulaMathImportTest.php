<?php

namespace OPF\Tests\Unit;

use OPF\Engine\Calculator;
use OPF\Engine\WapfMapper;
use PHPUnit\Framework\TestCase;

final class FormulaMathImportTest extends TestCase {

	public function test_imports_documented_math_functions_and_nested_numeric_arguments(): void {
		$cases = [
			'min(5; 1; 3)' => 1.0,
			'max(5; 8; 3)' => 8.0,
			'round(2.546; 2)' => 2.55,
			'abs(-4)' => 4.0,
			'floor(2.9)' => 2.0,
			'ceil(2.1)' => 3.0,
			'sqrt(9)' => 3.0,
			'pow(2; 3)' => 8.0,
			'sin(0)' => 0.0,
			'cos(0)' => 1.0,
			'tan(0)' => 0.0,
			'ROUND(max(abs(-4); pow(2; 3)) / (2 + 2); 2)' => 2.0,
		];
		foreach ( $cases as $formula => $expected ) {
			$normalized = WapfMapper::normalize_formula( $formula . ' * [qty]' );
			$this->assertSame( $formula, $normalized, $formula );
			$this->assertEqualsWithDelta( $expected, Calculator::evaluate_formula( $normalized, 10, 3, 0 ), 0.000001, $formula );
		}
	}

	public function test_imported_field_and_choice_math_formulas_remap_ids_and_preserve_raw_choice_formula(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[ 'id' => 'count-src', 'label' => 'Count', 'type' => 'number' ],
			[ 'id' => 'fee-src', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'enabled' => true, 'type' => 'fx', 'amount' => 'round(pow([field.count-src]; 2) / 3; 2) * [qty]' ] ],
			[ 'id' => 'plan-src', 'label' => 'Plan', 'type' => 'select', 'options' => [ 'choices' => [ [ 'slug' => 'math', 'label' => 'Math', 'pricing_type' => 'fx', 'pricing_amount' => 'max(abs(-4); [field.count-src]) * [qty]' ] ] ] ],
		] ] );
		$this->assertFalse( $mapped['needs_review'], implode( ' ', $mapped['notes'] ) );
		$fee = $mapped['group']['fields'][1]['pricing'];
		$choice = $mapped['group']['fields'][2]['choices'][0]['pricing'];
		$this->assertSame( 'round(pow([field.count]; 2) / 3; 2)', $fee['formula'] );
		$this->assertSame( 'max(abs(-4); [field.count]) * [qty]', $choice['formula_raw'] );
		$this->assertSame( 'max(abs(-4); [field.count])', $choice['formula'] );
		$this->assertSame( 3.0, Calculator::evaluate_formula( $fee['formula'], 10, 3, 0, '', null, [ 'count' => '3' ] ) );
		$this->assertSame( 4.0, Calculator::evaluate_formula( $choice['formula'], 10, 3, 0, '', null, [ 'count' => '3' ] ) );
	}

	public function test_math_allowlist_does_not_accept_arbitrary_calls_strings_or_free_argument_separators(): void {
		foreach ( [ 'system(1)', 'myround(1)', 'roundtrip(1)', '1round(2)', 'round(unknown)', 'abs("text")', '1;2', '(1;2)', 'min((1;2);3)', 'lookuptable(1;2)', 'round(1);2' ] as $formula ) {
			$this->assertNull( WapfMapper::normalize_formula( $formula ), $formula );
		}
	}

	public function test_imported_negative_square_root_fails_closed_like_browser_and_wapf_numeric_domain(): void {
		$formula = WapfMapper::normalize_formula( '(sqrt(-1) + 7) * [qty]' );
		$this->assertSame( '(sqrt(-1) + 7)', $formula );
		$this->assertSame( 0.0, Calculator::evaluate_formula( $formula, 10, 1, 0 ) );
	}
}
