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

	public function test_maps_wapf_character_and_numeric_pricing_modes(): void {
		$wapf = $this->swatch_group();
		$wapf['fields'] = [
			[ 'id' => 'engraving', 'label' => 'Engraving', 'type' => 'text', 'pricing' => [ 'enabled' => true, 'type' => 'char', 'amount' => 1.5 ] ],
			[ 'id' => 'engraving-qty', 'label' => 'Engraving per unit', 'type' => 'text', 'qty_based' => true, 'pricing' => [ 'enabled' => true, 'type' => 'charq', 'amount' => 1.5 ] ],
			[ 'id' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'pricing' => [ 'enabled' => true, 'type' => 'nr', 'amount' => 4 ] ],
			[ 'id' => 'quantity-per-unit', 'label' => 'Quantity per unit', 'type' => 'number', 'pricing' => [ 'enabled' => true, 'type' => 'nrq', 'amount' => 4 ] ],
		];
		$mapped = WapfMapper::map( $wapf );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'char', 'charq', 'nr', 'nrq' ], array_column( array_column( $mapped['group']['fields'], 'pricing' ), 'type' ) );
		$this->assertSame( [ false, true, false, true ], array_column( array_column( $mapped['group']['fields'], 'pricing' ), 'per_unit' ) );
	}

	public function test_maps_current_and_legacy_wapf_plain_text_content_fields(): void {
		$wapf = $this->swatch_group();
		$wapf['fields'] = [
			[ 'id' => 'intro', 'label' => 'Intro', 'type' => 'content', 'required' => false, 'options' => [ 'p_content' => "Plain text\nsecond line" ] ],
			[ 'id' => 'legacy-note', 'label' => 'Legacy note', 'type' => 'paragraph', 'required' => false, 'options' => [ 'p_content' => 'Legacy text' ] ],
		];
		$mapped = WapfMapper::map( $wapf );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'paragraph', 'paragraph' ], array_column( $mapped['group']['fields'], 'type' ) );
		$this->assertSame( "Plain text\nsecond line", $mapped['group']['fields'][0]['content'] );
		$this->assertSame( 'Legacy text', $mapped['group']['fields'][1]['content'] );
		$this->assertFalse( $mapped['group']['fields'][0]['required'] );
		$this->assertSame( 'none', $mapped['group']['fields'][0]['pricing']['type'] );
	}

	public function test_maps_wapf_informative_image_url_and_attachment_fields(): void {
		$wapf = $this->swatch_group();
		$wapf['fields'] = [
			[ 'id' => 'image-url', 'label' => 'URL image', 'type' => 'img', 'image' => 'https://cdn.example.test/product.png' ],
			[ 'id' => 'image-attachment', 'label' => 'Media image', 'type' => 'img', 'options' => [ 'attachment' => 42 ] ],
		];
		$mapped = WapfMapper::map( $wapf );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'image', 'image' ], array_column( $mapped['group']['fields'], 'type' ) );
		$this->assertSame( 'https://cdn.example.test/product.png', $mapped['group']['fields'][0]['image_url'] );
		$this->assertSame( 0, $mapped['group']['fields'][0]['attachment_id'] );
		$this->assertSame( 42, $mapped['group']['fields'][1]['attachment_id'] );
		$this->assertFalse( $mapped['group']['fields'][0]['required'] );
		$this->assertSame( 'none', $mapped['group']['fields'][0]['pricing']['type'] );
	}

	public function test_maps_product_tag_placement(): void {
		$mapped = WapfMapper::map( $this->swatch_group() );
		$this->assertSame( 'product_tag', $mapped['group']['rule_groups'][0]['rules'][0]['subject'] );
		$this->assertSame( 'in', $mapped['group']['rule_groups'][0]['rules'][0]['operator'] );
		$this->assertSame( [ '8768' ], $mapped['group']['rule_groups'][0]['rules'][0]['terms'] );
	}

	public function test_maps_wapf_auth_and_role_placement_rules(): void {
		$wapf = $this->swatch_group();
		$wapf['rule_groups'] = [
			[ 'rules' => [
				[ 'condition' => 'auth', 'subject' => 'auth', 'value' => [] ],
				[ 'condition' => '!role', 'subject' => 'role', 'value' => [ [ 'id' => 'shop_manager', 'text' => 'Shop manager' ] ] ],
				[ 'condition' => '!lang', 'subject' => 'lang', 'value' => 'nl_NL' ],
			] ],
		];
		$mapped = WapfMapper::map( $wapf );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame(
			[
				[ 'subject' => 'auth', 'operator' => 'in', 'terms' => [] ],
				[ 'subject' => 'user_role', 'operator' => 'not_in', 'terms' => [ 'shop_manager' ] ],
				[ 'subject' => 'language', 'operator' => 'not_in', 'terms' => [ 'nl_NL' ] ],
			],
			$mapped['group']['rule_groups'][0]['rules']
		);
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
