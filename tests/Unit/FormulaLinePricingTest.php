<?php
/**
 * WAPF 3.1.5 formula quantity semantics — regression tests.
 *
 * Verified against installed Extended 3.1.5
 * includes/classes/class-fields.php::do_pricing():
 *   normal field : fx → x/qty  (flat per line), percent → per-unit,
 *                  p → percent/qty (flat), qt → amount (per unit), fixed → amount/qty.
 *   qty_based    : fx → x, percent → x*qty, p → percent, qt → amount*qty, fixed → amount.
 *
 * OPF models both rows of the truth table through {type, per_unit}:
 *   normal field   : per_unit ? result : result/qty
 *   qty_based field: per_unit ? result*qty : result
 */

namespace OPF\Tests\Unit;

use OPF\Engine\Calculator;
use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class FormulaLinePricingTest extends TestCase {

	// ---- Normal fields: WAPF fx is flat per line ---------------------------

	public function test_formula_choice_is_flat_per_line_by_default(): void {
		$pricing = [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '[price] * 0.2' ];
		// WAPF fx: do_pricing returns x/qty → the LINE adds exactly x.
		$this->assertSame( 20.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertEqualsWithDelta( 5.0, Calculator::choice_addon( $pricing, 100.0, 4, 0.0 ), 0.000001 );
	}

	public function test_formula_choice_explicit_per_unit_scales_with_quantity(): void {
		$pricing = [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '[price] * 0.2', 'per_unit' => true ];
		$this->assertSame( 20.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertSame( 20.0, Calculator::choice_addon( $pricing, 100.0, 4, 0.0 ) );
	}

	public function test_formula_qty_token_stays_available_in_flat_formulas(): void {
		$pricing = [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '2 * [qty] + 5' ];
		$this->assertSame( 13.0, Calculator::choice_addon( $pricing, 100.0, 4, 0.0, [], 0, [], true ) );
		$this->assertEqualsWithDelta( 13.0 / 4, Calculator::choice_addon( $pricing, 100.0, 4, 0.0 ), 0.000001 );
	}

	public function test_flat_percent_divides_by_qty_wapf_p_parity(): void {
		$pricing = [ 'type' => 'percent', 'amount' => 10.0, 'per_unit' => false ];
		$this->assertSame( 10.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertEqualsWithDelta( 2.0, Calculator::choice_addon( $pricing, 100.0, 5, 0.0 ), 0.000001 );
	}

	public function test_field_level_formula_is_flat_per_line_by_default(): void {
		$field = [
			'type' => 'text', 'choices' => [],
			'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '[price] + 10' ],
		];
		$this->assertSame( 110.0, Calculator::field_addon( $field, 'x', [ 'price' => 100.0, 'qty' => 1 ] ) );
		$this->assertEqualsWithDelta( 27.5, Calculator::field_addon( $field, 'x', [ 'price' => 100.0, 'qty' => 4 ] ), 0.000001 );
	}

	// ---- Quantity-clone fields (WAPF clone_type=qty → repeat.mode=quantity) -

	public function test_qty_based_flat_formula_keeps_full_result_per_unit(): void {
		$pricing = [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '[price] * 0.2' ];
		$this->assertSame( 20.0, Calculator::choice_addon( $pricing, 100.0, 4, 0.0, [], 0, [], true ) );
	}

	public function test_qty_based_qt_choice_scales_result_by_qty(): void {
		$qt = [ 'type' => 'fixed', 'amount' => 5.0, 'per_unit' => true ];
		$this->assertSame( 20.0, Calculator::choice_addon( $qt, 100.0, 4, 0.0, [], 0, [], true ) );
	}

	public function test_qty_based_flat_fixed_is_amount_once_per_unit(): void {
		$pricing = [ 'type' => 'fixed', 'amount' => 5.0 ];
		$this->assertSame( 5.0, Calculator::choice_addon( $pricing, 100.0, 4, 0.0, [], 0, [], true ) );
	}

	public function test_qty_based_percent_scales_like_wapf(): void {
		$percent = [ 'type' => 'percent', 'amount' => 10.0, 'per_unit' => true ];
		$this->assertSame( 40.0, Calculator::choice_addon( $percent, 100.0, 4, 0.0, [], 0, [], true ) );
		$p = [ 'type' => 'percent', 'amount' => 10.0, 'per_unit' => false ];
		$this->assertSame( 10.0, Calculator::choice_addon( $p, 100.0, 4, 0.0, [], 0, [], true ) );
	}

	public function test_quantity_repeat_field_addon_uses_qty_based_semantics(): void {
		$field = [
			'type' => 'text', 'choices' => [],
			'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ],
			'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '5' ],
		];
		// Merged cart line (one stored row, qty = 3 identical units):
		// WAPF qty-based fx returns x per unit → pu 5 → line 15.
		$this->assertSame( 5.0, Calculator::field_addon( $field, [ 'x' ], [ 'price' => 10.0, 'qty' => 3 ] ) );
	}

	public function test_button_repeat_keeps_normal_flat_semantics(): void {
		$field = [
			'type' => 'text', 'choices' => [],
			'repeat' => [ 'enabled' => true, 'mode' => 'button' ],
			'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '5' ],
		];
		$this->assertEqualsWithDelta( 5.0 / 3 + 5.0 / 3, Calculator::field_addon( $field, [ 'a', 'b' ], [ 'price' => 10.0, 'qty' => 3 ] ), 0.000001 );
	}

	// ---- Schema migration: legacy records keep forced per-unit -------------

	public function test_schema1_records_preserve_forced_per_unit_defaults(): void {
		$group = FieldGroup::normalize( [
			'schema' => 1,
			'fields' => [ [
				'id' => 'plan', 'type' => 'select',
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing' => [ 'type' => 'formula', 'formula' => '[price] * 0.2' ] ] ],
				'pricing' => [ 'type' => 'percent', 'amount' => 10 ],
			] ],
		] );
		$this->assertSame( FieldGroup::SCHEMA, $group['schema'] );
		$this->assertTrue( $group['fields'][0]['choices'][0]['pricing']['per_unit'] );
		$this->assertTrue( $group['fields'][0]['pricing']['per_unit'] );
	}

	public function test_explicit_per_unit_is_preserved_on_schema1_records(): void {
		$group = FieldGroup::normalize( [
			'schema' => 1,
			'fields' => [ [
				'id' => 'plan', 'type' => 'select',
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing' => [ 'type' => 'formula', 'formula' => '2', 'per_unit' => false ] ] ],
			] ],
		] );
		$this->assertFalse( $group['fields'][0]['choices'][0]['pricing']['per_unit'] );
	}

	public function test_new_payloads_default_formula_to_flat_wapf_fx_parity(): void {
		$group = FieldGroup::normalize( [
			'schema' => FieldGroup::SCHEMA,
			'fields' => [ [
				'id' => 'plan', 'type' => 'select',
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing' => [ 'type' => 'formula', 'formula' => '[price] * 0.2' ] ] ],
			] ],
		] );
		$this->assertFalse( $group['fields'][0]['choices'][0]['pricing']['per_unit'] );
	}

	public function test_schema_absent_legacy_records_keep_their_forced_per_unit_defaults(): void {
		$group = FieldGroup::normalize( [
			'fields' => [ [
				'id' => 'plan', 'type' => 'select',
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing' => [ 'type' => 'formula', 'formula' => '[price] * 0.2' ] ] ],
				'pricing' => [ 'type' => 'percent', 'amount' => 10 ],
			] ],
		] );
		$this->assertSame( FieldGroup::SCHEMA, $group['schema'] );
		$this->assertTrue( $group['fields'][0]['choices'][0]['pricing']['per_unit'] );
		$this->assertTrue( $group['fields'][0]['pricing']['per_unit'] );
	}
}
