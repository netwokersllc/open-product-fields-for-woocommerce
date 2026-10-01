<?php
/**
 * WapfMapper unit tests — production-shaped WAPF payloads map to OPF.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\WapfMapper;
use PHPUnit\Framework\TestCase;

final class WapfMapperTest extends TestCase {

	/**
	 * Shaped after production group 607379 (swatch with fx formulas).
	 */
	private function swatch_group(): array {
		return [
			'id'     => 'p_607379',
			'type'   => 'wapf_product',
			'layout' => [ 'mark_required' => true, 'labels_position' => 'above' ],
			'fields' => [
				[
					'id'          => 'produration',
					'label'       => 'Duración',
					'description' => '',
					'type'        => 'text-swatch',
					'required'    => true,
					'conditionals'=> [],
					'clone'       => [ 'enabled' => false ],
					'options'     => [
						'choices' => [
							[ 'slug' => 'd30', 'label' => '30 días', 'selected' => true, 'disabled' => false, 'options' => [], 'pricing_type' => 'none', 'pricing_amount' => 0 ],
							[ 'slug' => 'd365', 'label' => '1 año', 'selected' => false, 'disabled' => false, 'options' => [], 'pricing_type' => 'fixed', 'pricing_amount' => 180 ],
							[ 'slug' => 'boost', 'label' => 'Boost', 'selected' => false, 'disabled' => false, 'options' => [], 'pricing_type' => 'fx', 'pricing_amount' => '(([price] + [options_total]) * 0.2) * [qty]' ],
							[ 'slug' => 'pct', 'label' => 'Percent', 'selected' => false, 'disabled' => false, 'options' => [], 'pricing_type' => 'percent', 'pricing_amount' => 15.5 ],
						],
					],
					'pricing'     => [ 'type' => 'fixed', 'amount' => 0, 'enabled' => false ],
				],
			],
			'rule_groups' => [
				[ 'rules' => [ [ 'value' => [ [ 'id' => '8768', 'text' => 'fast' ] ], 'condition' => 'p_tags', 'subject' => 'product_tag' ] ] ],
			],
		];
	}

	public function test_maps_swatch_types_and_pricing(): void {
		$mapped = WapfMapper::map( $this->swatch_group() );
		$field  = $mapped['group']['fields'][0];

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'swatch', $field['type'] );
		$this->assertSame( 'duracion', $field['id'] );
		$this->assertCount( 4, $field['choices'] );

		$this->assertSame( 'none', $field['choices'][0]['pricing']['type'] );
		$this->assertSame( 'fixed', $field['choices'][1]['pricing']['type'] );
		$this->assertSame( 180.0, $field['choices'][1]['pricing']['amount'] );
		// WAPF fixed = flat per line; qty-scaled fixed only for qt type.
		$this->assertFalse( $field['choices'][1]['pricing']['per_unit'] );

		// fx formula: qty compensation stripped, [options_total] → [addons].
		$this->assertSame( 'formula', $field['choices'][2]['pricing']['type'] );
		$this->assertSame( '(([price] + [addons]) * 0.2)', $field['choices'][2]['pricing']['formula'] );

		$this->assertSame( 'percent', $field['choices'][3]['pricing']['type'] );
		$this->assertSame( 15.5, $field['choices'][3]['pricing']['amount'] );
	}

	public function test_maps_product_tag_placement(): void {
		$mapped = WapfMapper::map( $this->swatch_group() );
		$this->assertSame( 'product_tag', $mapped['group']['rule_groups'][0]['rules'][0]['subject'] );
		$this->assertSame( 'in', $mapped['group']['rule_groups'][0]['rules'][0]['operator'] );
		$this->assertSame( [ '8768' ], $mapped['group']['rule_groups'][0]['rules'][0]['terms'] );
	}

	public function test_maps_wapf_field_condition_shapes_and_operators(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[ 'id' => 'f-a91', 'label' => 'Source field', 'type' => 'text', 'conditionals' => [] ],
				[ 'id' => 'f-b73', 'label' => 'Target field', 'type' => 'text', 'conditionals' => [ [ 'rules' => [
					[ 'field' => 'f-a91', 'condition' => '==', 'value' => 'yes' ],
					[ 'field' => 'f-a91', 'condition' => '!=contains', 'value' => 'blocked' ],
					[ 'field' => 'f-a91', 'condition' => '!empty', 'value' => '' ],
				] ] ] ],
			],
			'rule_groups' => [],
		] );

		$rules = $mapped['group']['fields'][1]['conditionals'][0]['rules'];
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'source-field', 'source-field', 'source-field' ], array_column( $rules, 'field' ) );
		$this->assertSame( [ 'is', 'not_contains', 'not_empty' ], array_column( $rules, 'operator' ) );
		$this->assertSame( [ 'yes', 'blocked', '' ], array_column( $rules, 'value' ) );
	}

	public function test_maps_wapf_true_false_fields_and_check_conditions(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[ 'id' => 'toggle-123', 'label' => 'Enabled', 'type' => 'true-false', 'conditionals' => [] ],
				[ 'id' => 'details', 'label' => 'Details', 'type' => 'text', 'conditionals' => [ [ 'rules' => [
					[ 'field' => 'toggle-123', 'condition' => 'check', 'value' => '' ],
				] ] ] ],
			],
			'rule_groups' => [],
		] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'toggle', $mapped['group']['fields'][0]['type'] );
		$this->assertSame( 'enabled', $mapped['group']['fields'][1]['conditionals'][0]['rules'][0]['field'] );
		$this->assertSame( 'is', $mapped['group']['fields'][1]['conditionals'][0]['rules'][0]['operator'] );
		$this->assertSame( '1', $mapped['group']['fields'][1]['conditionals'][0]['rules'][0]['value'] );
	}

	public function test_marks_unsupported_field_conditionals_for_review(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[ 'id' => 'source', 'label' => 'Source', 'type' => 'text', 'conditionals' => [] ],
				[ 'id' => 'target', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'rules' => [
					[ 'field' => 'source', 'condition' => 'unsupported', 'value' => 'x' ],
				] ] ] ],
			],
			'rule_groups' => [],
		] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertNotEmpty( $mapped['notes'] );
	}

	public function test_remaps_forward_field_references(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[ 'id' => 'target-id', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'rules' => [
					[ 'field' => 'source-id', 'condition' => '==', 'value' => 'ready' ],
				] ] ] ],
				[ 'id' => 'source-id', 'label' => 'Source', 'type' => 'text', 'conditionals' => [] ],
			],
		] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'source', $mapped['group']['fields'][0]['conditionals'][0]['rules'][0]['field'] );
	}

	public function test_marks_ambiguous_duplicate_source_ids_for_review(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[ 'id' => 'same-id', 'label' => 'First', 'type' => 'text', 'conditionals' => [] ],
				[ 'id' => 'same-id', 'label' => 'Second', 'type' => 'text', 'conditionals' => [] ],
				[ 'id' => 'target-id', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'rules' => [
					[ 'field' => 'same-id', 'condition' => '==', 'value' => 'ready' ],
				] ] ] ],
			],
		] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'ambiguous field ID', implode( ' ', $mapped['notes'] ) );
		$this->assertEmpty( $mapped['group']['fields'][2]['conditionals'] );
	}

	public function test_marks_unsupported_field_and_choice_pricing_for_review(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[
					'id' => 'choice', 'label' => 'Choice', 'type' => 'radio', 'conditionals' => [],
					'pricing' => [ 'enabled' => true, 'type' => 'unknown', 'amount' => 3 ],
					'options' => [ 'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing_type' => 'unknown', 'pricing_amount' => 2 ] ] ],
				],
			],
			'rule_groups' => [],
		] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertCount( 2, $mapped['notes'] );
	}

	public function test_attaches_local_groups_to_host_product(): void {
		$mapped = WapfMapper::map( $this->swatch_group(), [ 'attach_product_ids' => [ 199813 ] ] );
		$rules  = $mapped['group']['rule_groups'][0]['rules'];
		$this->assertSame( 'product', $rules[0]['subject'] );
		$this->assertSame( [ '199813' ], $rules[0]['terms'] );
	}

	public function test_empty_condition_flags_needs_review_not_match_all(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'f1', 'label' => 'Note', 'type' => 'textarea', 'required' => false, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
			],
			'rule_groups' => [
				[ 'rules' => [ [ 'value' => null, 'condition' => '', 'subject' => 'product' ] ] ],
			],
		];
		$mapped = WapfMapper::map( $wapf );

		// WAPF evaluated empty conditions as FALSE — the group was dead. The
		// mapper must flag it, not silently make it global.
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'empty condition', implode( ' ', $mapped['notes'] ) );
	}

	public function test_unsupported_types_are_dropped_and_flagged(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'f1', 'label' => 'Upload', 'type' => 'file', 'required' => false, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
			],
			'rule_groups' => [],
		];
		$mapped = WapfMapper::map( $wapf );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertEmpty( $mapped['group']['fields'] );
	}

	public function test_formula_normalizer_stacks_qty_strip(): void {
		$this->assertSame( '[price] * 0.5', WapfMapper::normalize_formula( '[price] * 0.5 * [qty]' ) );
		$this->assertSame( '[price] * 0.5', WapfMapper::normalize_formula( '[qty] * [price] * 0.5' ) );
		$this->assertSame( '[qty] + 1', WapfMapper::normalize_formula( '[qty] + 1' ), 'interior qty references are kept' );
		$this->assertNull( WapfMapper::normalize_formula( '[eval] * 2' ) );
		$this->assertNull( WapfMapper::normalize_formula( '' ) );
	}

	public function test_field_ids_are_stable_and_unique(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'a1', 'label' => 'Target Country', 'type' => 'text', 'required' => false, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
				[ 'id' => 'a2', 'label' => 'Target Country', 'type' => 'text', 'required' => false, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
			],
			'rule_groups' => [],
		];
		$mapped = WapfMapper::map( $wapf );
		$this->assertSame( 'target-country', $mapped['group']['fields'][0]['id'] );
		$this->assertSame( 'target-country-2', $mapped['group']['fields'][1]['id'] );
	}
}
