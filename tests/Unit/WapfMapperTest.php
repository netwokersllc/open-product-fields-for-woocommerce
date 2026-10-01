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

	public function test_image_swatches_preserve_media_and_map_display_settings_for_review(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[
					'id' => 'finish',
					'label' => 'Finish',
					'type' => 'image-swatch',
					'options' => [
						'large_image' => true,
						'label_pos' => 'tooltip',
						'grid_layout' => 'flexible',
						'items_per_row' => 4,
						'items_per_row_tablet' => 2,
						'items_per_row_mobile' => 1,
						'choices' => [
							[ 'slug' => 'oak', 'label' => 'Oak', 'attachment' => 481, 'image' => 'https://example.test/oak.jpg', 'pricing_type' => 'none' ],
						],
					],
				],
			],
		] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'image swatch', implode( ' ', $mapped['notes'] ) );
		$this->assertSame( 'image', $mapped['group']['fields'][0]['swatch_style'] );
		$this->assertTrue( $mapped['group']['fields'][0]['image_zoom'] );
		$this->assertSame( 'tooltip', $mapped['group']['fields'][0]['label_pos'] );
		$this->assertSame( 'flexible', $mapped['group']['fields'][0]['grid_layout'] );
		$this->assertSame( [ 4, 2, 1 ], [ $mapped['group']['fields'][0]['items_per_row'], $mapped['group']['fields'][0]['items_per_row_tablet'], $mapped['group']['fields'][0]['items_per_row_mobile'] ] );
		$this->assertSame( 'oak', $mapped['group']['fields'][0]['choices'][0]['slug'] );
		$this->assertSame( 'https://example.test/oak.jpg', $mapped['group']['fields'][0]['choices'][0]['image'] );
		$this->assertSame( 481, $mapped['group']['fields'][0]['choices'][0]['image_id'] );
	}

	public function test_maps_multi_color_swatches_selection_limits_and_color_choices(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[
					'id' => 'palette',
					'label' => 'Palette',
					'type' => 'multi-color-swatch',
					'options' => [
						'min_choices' => 1,
						'max_choices' => 2,
						'layout' => 'rounded',
						'size' => 36,
						'label_pos' => 'default',
						'choices' => [
							[ 'slug' => 'navy', 'label' => 'Navy', 'color' => '#123456', 'pricing_type' => 'none' ],
							[ 'slug' => 'gold', 'label' => 'Gold', 'color' => '#D4AF37', 'pricing_type' => 'fixed', 'pricing_amount' => 4 ],
						],
					],
				],
			],
		] );

		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'swatch', $field['type'] );
		$this->assertSame( 'color', $field['swatch_style'] );
		$this->assertTrue( $field['multiple'] );
		$this->assertSame( 1, $field['min_choices'] );
		$this->assertSame( 2, $field['max_choices'] );
		$this->assertSame( 'rounded', $field['color_layout'] );
		$this->assertSame( 36, $field['color_size'] );
		$this->assertSame( '#123456', $field['choices'][0]['color'] );
		$this->assertSame( '#D4AF37', $field['choices'][1]['color'] );
		$this->assertFalse( $mapped['needs_review'] );
	}

	public function test_maps_the_other_multi_and_single_swatch_variants_without_loss(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[ 'id' => 'text', 'label' => 'Text', 'type' => 'multi-text-swatch', 'options' => [
				'min_choices' => 1, 'max_choices' => 2,
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing_type' => 'none' ] ],
			] ],
			[ 'id' => 'image', 'label' => 'Image', 'type' => 'multi-image-swatch', 'options' => [
				'min_choices' => 2, 'max_choices' => 3, 'label_pos' => 'out', 'grid_layout' => 'flexible',
				'items_per_row' => 4, 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image' => '/oak.jpg', 'pricing_type' => 'none' ] ],
			] ],
			[ 'id' => 'color', 'label' => 'Color', 'type' => 'color-swatch', 'options' => [
				'layout' => 'square', 'size' => 24, 'label_pos' => 'hide',
				'choices' => [ [ 'slug' => 'black', 'label' => 'Black', 'color' => '#000', 'pricing_type' => 'none' ] ],
			] ],
		] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'image swatch', implode( ' ', $mapped['notes'] ) );
		$this->assertSame( [ true, true, false ], array_column( $mapped['group']['fields'], 'multiple' ) );
		$this->assertSame( [ 'text', 'image', 'color' ], array_column( $mapped['group']['fields'], 'swatch_style' ) );
		$this->assertSame( [ 1, 2 ], [ $mapped['group']['fields'][0]['min_choices'], $mapped['group']['fields'][0]['max_choices'] ] );
		$this->assertSame( [ 2, 3 ], [ $mapped['group']['fields'][1]['min_choices'], $mapped['group']['fields'][1]['max_choices'] ] );
		$this->assertSame( 'flexible', $mapped['group']['fields'][1]['grid_layout'] );
		$this->assertSame( '#000', $mapped['group']['fields'][2]['choices'][0]['color'] );
	}

	public function test_maps_free_content_and_legacy_paragraph_fields_as_static_text(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[ 'id' => 'intro', 'label' => '', 'type' => 'content', 'options' => [ 'p_content' => "First line\nSecond line" ], 'pricing' => [ 'enabled' => false ] ],
				[ 'id' => 'legacy-intro', 'label' => '', 'type' => 'paragraph', 'p_content' => 'Legacy plain text', 'pricing' => [ 'enabled' => false ] ],
			],
		] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'paragraph', 'paragraph' ], array_column( $mapped['group']['fields'], 'type' ) );
		$this->assertSame( "First line\nSecond line", $mapped['group']['fields'][0]['content'] );
		$this->assertSame( 'Legacy plain text', $mapped['group']['fields'][1]['content'] );
	}

	public function test_maps_extended_paragraph_html_and_shortcodes_without_flattening(): void {
		$content = '<strong>Special offer</strong><br>[site_name]<img src="/badge.png" alt="Badge">';
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'offer', 'label' => 'Offer', 'type' => 'p',
			'options' => [ 'p_content' => $content ],
		] ] ] );
		$field = $mapped['group']['fields'][0];

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'paragraph', $field['type'] );
		$this->assertSame( $content, $field['content'] );
		$this->assertSame( 'html', $field['content_format'] );
		$this->assertTrue( $field['process_shortcodes'] );
	}

	public function test_maps_wapf_informative_image_url_and_attachment(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'img-1', 'label' => 'Fabric guide', 'type' => 'img',
			'options' => [ 'image' => 'https://example.test/fabric.jpg', 'attachment' => 481 ],
		] ] ] );
		$field = $mapped['group']['fields'][0];

		$this->assertSame( 'content_image', $field['type'] );
		$this->assertSame( 'https://example.test/fabric.jpg', $field['image_url'] );
		$this->assertSame( 481, $field['image_id'] );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'remap the attachment', implode( ' ', $mapped['notes'] ) );
	}

	public function test_maps_nested_wapf_sections_and_preserves_conditional_class_data(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'options' => [ 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak' ] ] ] ],
			[ 'id' => 'outer', 'label' => 'Outer', 'type' => 'section', 'class' => 'outer-style', 'conditionals' => [ [ 'rules' => [ [ 'field' => 'finish', 'condition' => '==', 'value' => 'oak' ] ] ] ] ],
			[ 'id' => 'inner', 'label' => 'Inner', 'type' => 'section' ],
			[ 'id' => 'end-inner', 'type' => 'sectionend' ],
			[ 'id' => 'end-outer', 'type' => 'sectionend' ],
		] ] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'select', 'section', 'section', 'section_end', 'section_end' ], array_column( $mapped['group']['fields'], 'type' ) );
		$this->assertSame( 'outer-style', $mapped['group']['fields'][1]['css_class'] );
		$this->assertSame( 'finish', $mapped['group']['fields'][1]['conditionals'][0]['rules'][0]['field'] );
	}

	public function test_flags_unclosed_wapf_sections_for_review(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [ 'id' => 'open', 'type' => 'section' ] ] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'no matching section-end marker', implode( ' ', $mapped['notes'] ) );
	}

	public function test_preserves_button_and_quantity_clone_modes_for_review(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[ 'id' => 'name', 'label' => 'Name', 'type' => 'text', 'clone' => [ 'enabled' => true, 'type' => 'button', 'max' => 8 ] ],
			[ 'id' => 'attendees', 'label' => 'Attendees', 'type' => 'section', 'clone' => [ 'enabled' => true, 'type' => 'qty' ] ],
			[ 'id' => 'attendees-end', 'type' => 'sectionend' ],
			[ 'id' => 'unlimited', 'label' => 'Unlimited', 'type' => 'text', 'clone' => [ 'enabled' => true, 'type' => 'button' ] ],
		] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 8 ], $mapped['group']['fields'][0]['repeat'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'quantity' ], $mapped['group']['fields'][1]['repeat'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 10000 ], $mapped['group']['fields'][3]['repeat'] );
		$this->assertStringContainsString( 'quantity or section repeat behavior', implode( ' ', $mapped['notes'] ) );
	}

	public function test_flags_custom_clone_settings_while_preserving_button_maximum(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'name', 'label' => 'Name', 'type' => 'text',
			'clone' => [ 'enabled' => true, 'type' => 'button', 'max' => 2000, 'add' => 'Add attendee', 'del' => 'Remove attendee', 'label' => 'Attendee {n}', 'vendor_option' => 'unknown' ],
		] ] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 2000, 'add' => 'Add attendee', 'del' => 'Remove attendee', 'label' => 'Attendee {n}' ], $mapped['group']['fields'][0]['repeat'] );
		$this->assertStringContainsString( 'unsupported WAPF clone settings (vendor_option)', implode( ' ', $mapped['notes'] ) );
	}

	public function test_maps_supported_button_repeat_labels_without_review(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'guest', 'label' => 'Guest', 'type' => 'text',
			'clone' => [ 'enabled' => true, 'type' => 'button', 'max' => 4, 'add' => 'Add guest', 'del' => 'Remove guest', 'label' => 'Guest {n}' ],
		] ] ] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 4, 'add' => 'Add guest', 'del' => 'Remove guest', 'label' => 'Guest {n}' ], $mapped['group']['fields'][0]['repeat'] );
	}

	public function test_flags_wapf_button_maxima_outside_integer_range(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'name', 'label' => 'Name', 'type' => 'text',
			'clone' => [ 'enabled' => true, 'type' => 'button', 'max' => '999999999999999999999999999999' ],
		] ] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertArrayNotHasKey( 'repeat', $mapped['group']['fields'][0] );
		$this->assertStringContainsString( 'invalid or unrepresentable repeater settings', implode( ' ', $mapped['notes'] ) );
	}

	public function test_flags_clone_settings_on_a_section_end_without_throwing(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [ 'id' => 'end', 'type' => 'sectionend', 'clone' => [ 'enabled' => true, 'type' => 'button' ] ] ] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( 'section_end', $mapped['group']['fields'][0]['type'] );
		$this->assertArrayNotHasKey( 'repeat', $mapped['group']['fields'][0] );
		$this->assertStringContainsString( 'section-end marker with clone settings', implode( ' ', $mapped['notes'] ) );
	}

	public function test_html_in_plain_wapf_content_is_preserved_as_text_and_flagged_for_review(): void {
		$mapped = WapfMapper::map( [
			'fields' => [ [ 'id' => 'intro', 'label' => '', 'type' => 'content', 'options' => [ 'p_content' => '<strong>Care</strong>' ] ] ],
		] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'contains HTML', $mapped['notes'][0] );
		$this->assertSame( 'Care', $mapped['group']['fields'][0]['content'] );
	}

	public function test_maps_product_tag_placement(): void {
		$mapped = WapfMapper::map( $this->swatch_group() );
		$this->assertSame( 'product_tag', $mapped['group']['rule_groups'][0]['rules'][0]['subject'] );
		$this->assertSame( 'in', $mapped['group']['rule_groups'][0]['rules'][0]['operator'] );
		$this->assertSame( [ '8768' ], $mapped['group']['rule_groups'][0]['rules'][0]['terms'] );
	}

	public function test_maps_user_and_language_placement(): void {
		$wapf = [
			'fields' => [],
			'rule_groups' => [ [ 'rules' => [
				[ 'condition' => 'auth', 'subject' => 'user', 'value' => [] ],
				[ 'condition' => 'role', 'subject' => 'user', 'value' => [ [ 'id' => 'wholesale', 'text' => 'Wholesale' ] ] ],
				[ 'condition' => '!role', 'subject' => 'user', 'value' => [ [ 'id' => 'suspended', 'text' => 'Suspended' ] ] ],
				[ 'condition' => 'lang', 'subject' => 'system', 'value' => [ [ 'id' => 'nl_NL', 'text' => 'Nederlands' ] ] ],
				[ 'condition' => '!lang', 'subject' => 'system', 'value' => [ [ 'id' => 'fr_FR', 'text' => 'Français' ] ] ],
			] ] ],
		];

		$mapped = WapfMapper::map( $wapf );
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame(
			[
				[ 'subject' => 'user_auth', 'operator' => 'in', 'terms' => [ 'logged_in' ] ],
				[ 'subject' => 'user_role', 'operator' => 'in', 'terms' => [ 'wholesale' ] ],
				[ 'subject' => 'user_role', 'operator' => 'not_in', 'terms' => [ 'suspended' ] ],
				[ 'subject' => 'user_language', 'operator' => 'in', 'terms' => [ 'nl_NL' ] ],
				[ 'subject' => 'user_language', 'operator' => 'not_in', 'terms' => [ 'fr_FR' ] ],
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

	public function test_local_product_import_keeps_user_context_conditions(): void {
		$source = $this->swatch_group();
		$source['rule_groups'] = [ [ 'rules' => [
			[ 'condition' => 'auth', 'subject' => 'user', 'value' => [] ],
			[ 'condition' => 'role', 'subject' => 'user', 'value' => [ [ 'id' => 'wholesale', 'text' => 'Wholesale' ] ] ],
		] ] ];

		$mapped = WapfMapper::map( $source, [ 'attach_product_ids' => [ 199813 ] ] );
		$rules = $mapped['group']['rule_groups'][0]['rules'];
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'product', 'user_auth', 'user_role' ], array_column( $rules, 'subject' ) );
		$this->assertSame( [ 'in', 'in', 'in' ], array_column( $rules, 'operator' ) );
		$this->assertSame( [ 'wholesale' ], $rules[2]['terms'] );
	}

	public function test_maps_wapf_negative_contains_to_runtime_supported_operator(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'source', 'label' => 'Source', 'type' => 'text', 'conditionals' => [], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
				[ 'id' => 'target', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'rules' => [ [ 'field' => 'source', 'condition' => '!=contains', 'value' => 'blocked' ] ] ] ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
			],
			'rule_groups' => [],
		];

		$mapped = WapfMapper::map( $wapf );
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'not_contains', $mapped['group']['fields'][1]['conditionals'][0]['rules'][0]['operator'] );
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
