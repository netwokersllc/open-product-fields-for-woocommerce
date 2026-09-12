<?php
/**
 * Calculator unit tests — pricing semantics and formula safety.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\Calculator;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase {

	public function test_fixed_pricing_flat_per_line_wapf_parity(): void {
		$pricing = [ 'type' => 'fixed', 'amount' => 5.0, 'formula' => '', 'per_unit' => false ];
		// Flat fee: per-unit contribution shrinks with qty so the LINE total
		// adds exactly the fee (WAPF parity).
		$this->assertSame( 5.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertEqualsWithDelta( 5.0 / 3, Calculator::choice_addon( $pricing, 100.0, 3, 0.0 ), 0.000001 );
	}

	public function test_fixed_pricing_per_unit_opt_in(): void {
		$pricing = [ 'type' => 'fixed', 'amount' => 5.0, 'formula' => '', 'per_unit' => true ];
		$this->assertSame( 5.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertSame( 5.0, Calculator::choice_addon( $pricing, 100.0, 3, 0.0 ), 'per-unit fixed does not shrink' );
	}

	public function test_percent_of_unit_price(): void {
		$pricing = [ 'type' => 'percent', 'amount' => 20.0, 'formula' => '' ];
		$this->assertSame( 20.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertSame( 2.0, Calculator::choice_addon( $pricing, 10.0, 5, 0.0 ) );
	}

	public function test_formula_from_production_data(): void {
		// Real WAPF formula from production (after qty-compensation strip):
		// "(([price] + [options_total]) * 0.2) * [qty]" → "(([price] + [addons]) * 0.2)"
		$formula = '(([price] + [addons]) * 0.2)';
		$this->assertSame( 22.0, Calculator::evaluate_formula( $formula, 100.0, 3, 10.0 ) );
	}

	public function test_formula_arithmetic(): void {
		$this->assertSame( 20.0, Calculator::evaluate_formula( '[price] * 0.1 + [qty] * 2', 100.0, 5, 0.0 ) );
		$this->assertSame( 6.0, Calculator::evaluate_formula( '(2 + 4) * (3 / 3)', 0.0, 1, 0.0 ) );
		$this->assertSame( -3.0, Calculator::evaluate_formula( '-3', 0.0, 1, 0.0 ) );
	}

	public function test_formula_safety_garbage_yields_zero(): void {
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'system("rm -rf /")', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '[price] *', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '2 + unknown_var', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '1 / 0', 10.0, 1, 0.0 ) );
	}

	public function test_field_addon_choice_fields(): void {
		$field = [
			'type'    => 'swatch',
			'choices' => [
				[ 'slug' => 'a', 'label' => 'A', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 2.0, 'formula' => '' ] ],
				[ 'slug' => 'b', 'label' => 'B', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'percent', 'amount' => 50.0, 'formula' => '' ] ],
				[ 'slug' => 'x', 'label' => 'X', 'selected' => false, 'disabled' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 999.0, 'formula' => '' ] ],
			],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
		];
		$this->assertSame( 2.0, Calculator::field_addon( $field, 'a', [ 'price' => 100.0, 'qty' => 1 ] ) );
		$this->assertSame( 50.0, Calculator::field_addon( $field, 'b', [ 'price' => 100.0, 'qty' => 1 ] ) );
		$this->assertSame( 0.0, Calculator::field_addon( $field, 'x', [ 'price' => 100.0, 'qty' => 1 ] ), 'disabled choices never price' );
		$this->assertSame( 0.0, Calculator::field_addon( $field, 'zzz', [ 'price' => 100.0, 'qty' => 1 ] ), 'unknown slugs never price' );
	}

	public function test_field_addon_text_fields_use_field_pricing(): void {
		$field = [
			'type'    => 'textarea',
			'choices' => [],
			'pricing' => [ 'type' => 'fixed', 'amount' => 1.5, 'formula' => '' ],
		];
		$this->assertSame( 1.5, Calculator::field_addon( $field, 'hello', [ 'price' => 0.0, 'qty' => 1 ] ) );
		$this->assertSame( 0.0, Calculator::field_addon( $field, '   ', [ 'price' => 0.0, 'qty' => 1 ] ), 'blank text never prices' );
		$this->assertSame( 0.0, Calculator::field_addon( $field, '', [ 'price' => 0.0, 'qty' => 1 ] ) );
	}

	public function test_addons_never_negative(): void {
		$field = [
			'type'    => 'text',
			'choices' => [],
			'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '0 - 100' ],
		];
		$this->assertSame( 0.0, Calculator::field_addon( $field, 'x', [ 'price' => 10.0, 'qty' => 1 ] ) );
	}
}
