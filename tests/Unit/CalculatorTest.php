<?php
/**
 * Calculator unit tests — pricing semantics and formula safety.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\Calculator;
use OPF\Engine\FormulaFunctions;
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

	public function test_character_and_numeric_pricing_match_wapf_quantity_modes(): void {
		$text_field = [ 'type' => 'text', 'choices' => [], 'pricing' => [ 'type' => 'char', 'amount' => 1.5, 'formula' => '', 'per_unit' => false ] ];
		$this->assertSame( 3.0, Calculator::field_addon( $text_field, 'café', [ 'qty' => 2 ] ) );
		$text_field['pricing'] = [ 'type' => 'charq', 'amount' => 1.5, 'formula' => '', 'per_unit' => true ];
		$this->assertSame( 6.0, Calculator::field_addon( $text_field, 'café', [ 'qty' => 2 ] ) );

		$number_field = [ 'type' => 'number', 'choices' => [], 'pricing' => [ 'type' => 'nr', 'amount' => 4.0, 'formula' => '', 'per_unit' => false ] ];
		$this->assertSame( 5.0, Calculator::field_addon( $number_field, '2.5', [ 'qty' => 2 ] ) );
		$number_field['pricing'] = [ 'type' => 'nrq', 'amount' => 4.0, 'formula' => '', 'per_unit' => true ];
		$this->assertSame( 10.0, Calculator::field_addon( $number_field, '2.5', [ 'qty' => 2 ] ) );
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

	public function test_wapf_numeric_formula_functions_accept_semicolon_arguments(): void {
		FormulaFunctions::init();
		$this->assertSame( 5.0, Calculator::evaluate_formula( 'abs(-2) + ceil(1.2) + floor(1.8)', 10.0, 1, 0.0 ) );
		$this->assertSame( 5.0, Calculator::evaluate_formula( 'max(1; 4; 2) + min(1; 4; 2)', 10.0, 1, 0.0 ) );
		$this->assertSame( 13.0, Calculator::evaluate_formula( 'pow(3; 2) + sqrt(16)', 10.0, 1, 0.0 ) );
		$this->assertSame( -0.7, Calculator::evaluate_formula( 'round(1.25; 1) + round(-1.5)', 10.0, 1, 0.0 ) );
		$this->assertEqualsWithDelta( 1.0, Calculator::evaluate_formula( 'sin(0) + cos(0) + tan(0)', 10.0, 1, 0.0 ), 0.000000001 );
	}

	public function test_wapf_numeric_formula_functions_reject_invalid_calls(): void {
		FormulaFunctions::init();
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'sqrt(-1)', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'pow(2)', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'round()', 10.0, 1, 0.0 ) );
	}

	public function test_formula_safety_garbage_yields_zero(): void {
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'system("rm -rf /")', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '[price] *', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '2 + unknown_var', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '1 / 0', 10.0, 1, 0.0 ) );
	}

	public function test_registered_formula_functions_receive_numeric_arguments_and_context(): void {
		$this->assertTrue( opf_add_formula_function( 'opf_test_twice', static function ( array $args, array $context ): float {
			return 2 * $args[0] + $context['quantity'];
		} ) );
		$this->assertTrue( opf_add_formula_function( 'opf_test_sum', static function ( array $args ): float {
			return array_sum( $args );
		} ) );

		$this->assertSame( 29.0, Calculator::evaluate_formula( 'opf_test_twice([price] + opf_test_sum(1, 2))', 10.0, 3, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'opf_test_twice(1 / 0)', 10.0, 3, 0.0 ) );
		$field = [ 'type' => 'text', 'choices' => [], 'pricing' => [ 'type' => 'formula', 'amount' => 0, 'formula' => 'opf_test_twice([price])' ] ];
		$this->assertSame( 22.0, Calculator::field_addon( $field, 'selected', [ 'price' => 10, 'qty' => 2 ] ) );
	}

	public function test_formula_function_registration_rejects_invalid_or_reserved_names_and_fails_closed(): void {
		$callback = static function (): float {
			return 4.0;
		};
		$this->assertFalse( opf_add_formula_function( 'bad-name', $callback ) );
		$this->assertFalse( opf_add_formula_function( 'today', $callback ) );
		$this->assertFalse( opf_add_formula_function( 'p', $callback ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'unregistered(2)', 10.0, 1, 0.0 ) );

		$this->assertTrue( opf_add_formula_function( 'opf_test_invalid_result', static function () {
			return INF;
		} ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'opf_test_invalid_result(2)', 10.0, 1, 0.0 ) );
	}

	public function test_wapf_date_formula_functions_match_weekday_month_and_today_semantics(): void {
		$this->assertSame( 2.0, Calculator::evaluate_formula( "dow('01-10-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( "month('03-01-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( "dow('2024-01-01')", 10.0, 1, 0.0 ) );
		$this->assertSame( 9.0, Calculator::evaluate_formula( 'month(today())', 10.0, 1, 0.0, '', '2026-09-30' ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( 'dow([val])', 10.0, 1, 0.0, '01-10-2023' ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( "dow('02-30-2023')", 10.0, 1, 0.0 ) );

		$previous_format = $GLOBALS['opf_test_options']['wapf_date_format'] ?? null;
		try {
			$GLOBALS['opf_test_options']['wapf_date_format'] = 'dd/mm/yyyy';
			$this->assertSame( 3.0, Calculator::evaluate_formula( "dow('01/03/2023')", 10.0, 1, 0.0 ) );
			$this->assertSame( 12.0, Calculator::evaluate_formula( "month('31/12/2023')", 10.0, 1, 0.0 ) );
		} finally {
			if ( null === $previous_format ) {
				unset( $GLOBALS['opf_test_options']['wapf_date_format'] );
			} else {
				$GLOBALS['opf_test_options']['wapf_date_format'] = $previous_format;
			}
		}
	}

	public function test_wapf_date_formula_functions_read_validated_sibling_field_values(): void {
		$field = [
			'type'    => 'text',
			'choices' => [],
			'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => 'month([field.end_date]) + dow([field.start_date])' ],
		];
		$this->assertSame( 3.0, Calculator::field_addon( $field, 'selected', [
			'price'        => 10.0,
			'qty'          => 1,
			'field_values' => [ 'end_date' => '2024-02-29', 'start_date' => '2024-01-01' ],
		] ) );
		$this->assertSame( 0.0, Calculator::field_addon( $field, 'selected', [
			'field_values' => [ 'end_date' => [ 'invalid' ], 'start_date' => [ 'invalid' ] ],
		] ) );
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
