<?php
/**
 * Calculator unit tests — pricing semantics and formula safety.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\Calculator;
use OPF\API;
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

	public function test_multiple_swatch_pricing_adds_each_selected_choice(): void {
		$field = [
			'type' => 'swatch', 'multiple' => true,
			'choices' => [
				[ 'slug' => 'navy', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 2.0, 'per_unit' => true ] ],
				[ 'slug' => 'gold', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 3.0, 'per_unit' => true ] ],
			],
		];

		$this->assertSame( 5.0, Calculator::field_addon( $field, [ 'navy', 'gold' ], [ 'price' => 10, 'qty' => 1 ] ) );
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

	public function test_wapf_builtin_math_text_and_conditional_formula_functions(): void {
		$this->assertSame( 1.0, Calculator::evaluate_formula( 'min(5; 1; 3)', 0.0, 1, 0.0 ) );
		$this->assertSame( 8.0, Calculator::evaluate_formula( 'max(5; 8; 3)', 0.0, 1, 0.0 ) );
		$this->assertSame( 14.0, Calculator::evaluate_formula( 'len(a quick brown fox; true)', 0.0, 1, 0.0 ) );
		$this->assertSame( 17.0, Calculator::evaluate_formula( 'len(a quick brown fox)', 0.0, 1, 0.0 ) );
		$this->assertSame( 20.0, Calculator::evaluate_formula( 'abs(-4) + floor(2.9) + ceil(2.1) + sqrt(9) + pow(2; 3)', 0.0, 1, 0.0 ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( 'round(2.54)', 0.0, 1, 0.0 ) );
		$this->assertSame( 2.55, Calculator::evaluate_formula( 'round(2.546; 2)', 0.0, 1, 0.0 ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( 'if(2 < 5; 10; 20)', 0.0, 1, 0.0 ) );
		$this->assertSame( 20.0, Calculator::evaluate_formula( 'if(or(2 < 1; 3 >= 3); 20; 10)', 0.0, 1, 0.0 ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( 'if(and(2 < 1; 3 >= 3); 20; 10)', 0.0, 1, 0.0 ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( 'if(2 = 2; true; false)', 0.0, 1, 0.0 ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( 'if([field.size]=Large;10;20)', 0.0, 1, 0.0, '', null, [ 'size' => 'Large' ] ) );
		$this->assertSame( 4.0, Calculator::evaluate_formula( 'min([field.count]+2; 7)', 0.0, 1, 0.0, '', null, [ 'count' => '2' ] ) );
	}

	public function test_public_api_registers_safe_formula_functions_with_arguments_and_context(): void {
		API::add_formula_function(
			'opf_test_scale',
			static function ( array $args, array $context ) {
				return (float) $args[0] * (float) $args[1]
					+ (float) $context['price']
					+ (int) $context['qty']
					+ (float) $context['addons']
					+ (int) $context['product_id']
					+ (int) $context['field_values']['count'];
			}
		);

		$this->assertSame( 32.0, Calculator::evaluate_formula( 'opf_test_scale(2; 3)', 9.0, 2, 4.0, '', null, [ 'count' => 1 ], 10 ) );
	}

	public function test_registered_formula_functions_support_nested_calls_and_fail_closed(): void {
		API::add_formula_function(
			'opf_test_double',
			static fn( array $args ) => 1 === count( $args ) ? (float) $args[0] * 2 : 'invalid'
		);

		$this->assertSame( 8.0, Calculator::evaluate_formula( 'opf_test_double(opf_test_double(2))', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'opf_test_double(2;3)', 10.0, 1, 0.0 ) );
	}

	public function test_public_formula_api_rejects_reserved_names_and_handles_callback_failures(): void {
		$this->expectException( \InvalidArgumentException::class );
		API::add_formula_function( 'round', static fn() => 1 );
	}

	public function test_formula_callback_exception_fails_closed(): void {
		API::add_formula_function(
			'opf_test_throw',
			static function () {
				throw new \RuntimeException( 'callback failure' );
			}
		);
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'opf_test_throw(1)', 10.0, 1, 0.0 ) );
	}

	public function test_formula_safety_garbage_yields_zero(): void {
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'system("rm -rf /")', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '[price] *', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '2 + unknown_var', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '1 / 0', 10.0, 1, 0.0 ) );
	}

	public function test_wapf_date_formula_functions_match_weekday_month_and_today_semantics(): void {
		$this->assertSame( 2.0, Calculator::evaluate_formula( "dow('01-10-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( "month('03-01-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( "dow('2024-01-01')", 10.0, 1, 0.0 ) );
		$this->assertSame( 9.0, Calculator::evaluate_formula( 'month(today())', 10.0, 1, 0.0, '', '2026-09-30' ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( "datediff('01-10-2023'; '01-12-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( "datediff('01-12-2023'; '01-10-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( "datediff(today(); '06-18-2026')", 10.0, 1, 0.0, '', '2026-06-15' ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( "datediff('02-30-2023'; '03-01-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( 'dow([val])', 10.0, 1, 0.0, '01-10-2023' ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( "dow('02-30-2023')", 10.0, 1, 0.0 ) );

		$previous_format = $GLOBALS['opf_test_options']['wapf_date_format'] ?? null;
		try {
			$GLOBALS['opf_test_options']['wapf_date_format'] = 'dd/mm/yyyy';
			$this->assertSame( 3.0, Calculator::evaluate_formula( "dow('01/03/2023')", 10.0, 1, 0.0 ) );
			$this->assertSame( 12.0, Calculator::evaluate_formula( "month('31/12/2023')", 10.0, 1, 0.0 ) );
			$this->assertSame( 2.0, Calculator::evaluate_formula( "datediff('31/12/2023'; '02/01/2024')", 10.0, 1, 0.0 ) );
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

	public function test_repeated_text_field_prices_each_instance(): void {
		$field = [
			'type' => 'text',
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ],
			'choices' => [],
			'pricing' => [ 'type' => 'fixed', 'amount' => 2.0, 'formula' => '', 'per_unit' => true ],
		];

		$this->assertSame( 4.0, Calculator::field_addon( $field, [ 'First', 'Second' ], [ 'price' => 10.0, 'qty' => 1 ] ) );
	}

	public function test_repeated_checkbox_field_prices_each_instances_choices(): void {
		$field = [
			'type' => 'checkbox',
			'multiple' => true,
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ],
			'choices' => [
				[ 'slug' => 'a', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 1.0, 'per_unit' => true ] ],
				[ 'slug' => 'b', 'disabled' => false, 'pricing' => [ 'type' => 'percent', 'amount' => 10.0 ] ],
			],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
		];

		$this->assertSame( 3.0, Calculator::field_addon( $field, [ [ 'a' ], [ 'b' ] ], [ 'price' => 20.0, 'qty' => 1 ] ) );
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
