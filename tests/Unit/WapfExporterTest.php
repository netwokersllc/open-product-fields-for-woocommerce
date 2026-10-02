<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\WapfMapper;
use OPF\Service\WapfExporter;
use PHPUnit\Framework\TestCase;

final class WapfExporterTest extends TestCase {

	public function test_exports_supported_tools_json_and_preserves_field_and_placement_rules(): void {
		$group = FieldGroup::normalize( [
			'labels_position' => 'below',
			'mark_required' => false,
			'fields' => [
				[ 'id' => 'source', 'label' => 'Source', 'type' => 'text' ],
				[
					'id' => 'finish', 'label' => 'Finish', 'type' => 'radio',
					'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'source', 'operator' => 'is', 'value' => 'ready' ] ] ] ],
					'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen', 'selected' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 4, 'per_unit' => true ] ] ],
				],
			],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'not_in', 'terms' => [ '42', '43' ] ] ] ] ],
		] );

		$payload = WapfExporter::build_payload( $group );

		$this->assertSame( [ 'fields', 'conditions', 'layout', 'variables' ], array_keys( $payload ) );
		$this->assertSame( 'radio', $payload['fields'][1]['type'] );
		$this->assertSame( 'qt', $payload['fields'][1]['choices'][0]['pricing_type'] );
		$this->assertSame( [ 'field' => 'source', 'condition' => '==', 'value' => 'ready' ], $payload['fields'][1]['conditionals'][0]['rules'][0] );
		$this->assertSame( '!products', $payload['conditions'][0]['rules'][0]['condition'] );
		$this->assertSame( 'product', $payload['conditions'][0]['rules'][0]['subject'] );
		$this->assertSame( [ [ 'id' => '42', 'text' => '42' ], [ 'id' => '43', 'text' => '43' ] ], $payload['conditions'][0]['rules'][0]['value'] );
		$this->assertSame( [ 'labels_position' => 'below', 'instructions_position' => 'field', 'mark_required' => false ], $payload['layout'] );
		$this->assertSame( [], $payload['variables'] );

		$wapf_fields = array_map( static function ( array $field ): array {
			$field['options'] = array_intersect_key( $field, array_flip( [ 'choices', 'placeholder', 'default', 'p_content', 'minimum', 'maximum' ] ) );
			return $field;
		}, $payload['fields'] );
		$round_trip = WapfMapper::map( [ 'fields' => $wapf_fields, 'rule_groups' => $payload['conditions'] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( 'source', $round_trip['group']['fields'][1]['conditionals'][0]['rules'][0]['field'] );
		$this->assertTrue( $round_trip['group']['fields'][1]['choices'][0]['pricing']['per_unit'] );
		$this->assertSame( [ '42', '43' ], $round_trip['group']['rule_groups'][0]['rules'][0]['terms'] );
	}

	public function test_maps_product_category_and_tag_placement_condition_ids(): void {
		$group = FieldGroup::normalize( [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'category', 'operator' => 'in', 'terms' => [ '12' ] ],
			[ 'subject' => 'tag', 'operator' => 'not_in', 'terms' => [ '34' ] ],
		] ] ] ] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( [ 'product_cats', 'product' ], [ $payload['conditions'][0]['rules'][0]['condition'], $payload['conditions'][0]['rules'][0]['subject'] ] );
		$this->assertSame( '!p_tags', $payload['conditions'][0]['rules'][1]['condition'] );
	}

	public function test_disabled_choices_round_trip_through_wapf_tools_payload(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'finish', 'label' => 'Finish', 'type' => 'select',
			'choices' => [
				[ 'slug' => 'oak', 'label' => 'Oak', 'disabled' => true ],
				[ 'slug' => 'ash', 'label' => 'Ash', 'disabled' => false ],
			],
		] ] ] ) );

		$this->assertTrue( $payload['fields'][0]['choices'][0]['disabled'] );
		$source_field = $payload['fields'][0];
		$source_field['options'] = [ 'choices' => $source_field['choices'] ];
		$round_trip = WapfMapper::map( [ 'fields' => [ $source_field ] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertTrue( $round_trip['group']['fields'][0]['choices'][0]['disabled'] );
	}

	public function test_sumqty_image_quantity_formula_round_trips_with_remapped_field_id_and_raw_expression(): void {
		$source = [ 'fields' => [
			[ 'id' => 'wapf-image-id-91', 'label' => 'Prints', 'type' => 'image-swatch-qty', 'options' => [ 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'options' => [ 'min' => 0, 'max' => 8, 'default' => 0 ] ] ] ] ],
			[ 'id' => 'wapf-fee-id-92', 'label' => 'Fee', 'type' => 'select', 'options' => [ 'choices' => [ [ 'slug' => 'selected', 'label' => 'Selected', 'pricing_type' => 'fx', 'pricing_amount' => 'sumQty(wapf-image-id-91)*[qty]' ] ] ] ],
		] ];
		$imported = WapfMapper::map( $source );
		$payload = WapfExporter::build_payload( $imported['group'] );
		$round_trip_fields = array_map( static function ( array $field ): array {
			$field['options'] = array_intersect_key( $field, array_flip( [ 'choices', 'min_choices', 'max_choices', 'large_image', 'label_pos', 'items_per_row', 'items_per_row_tablet', 'items_per_row_mobile' ] ) );
			return $field;
		}, $payload['fields'] );
		$round_trip = WapfMapper::map( [ 'fields' => $round_trip_fields ] );

		$this->assertSame( 'image-swatch-qty', $payload['fields'][0]['type'] );
		$this->assertSame( [ 'min' => 0, 'max' => 8, 'default' => 0 ], $payload['fields'][0]['choices'][0]['options'] );
		$this->assertSame( [ 'type' => 'fx', 'amount' => 'sumQty(prints)*[qty]' ], [ 'type' => $payload['fields'][1]['choices'][0]['pricing_type'], 'amount' => $payload['fields'][1]['choices'][0]['pricing_amount'] ] );
		$this->assertSame( 'sumQty(prints)*[qty]', $round_trip['group']['fields'][1]['choices'][0]['pricing']['formula_raw'] );
		$this->assertSame( 'sumQty(prints)', $round_trip['group']['fields'][1]['choices'][0]['pricing']['formula'] );
		$this->assertTrue( $round_trip['needs_review'], 'The formula pricing review flag must survive export/import.' );
	}

	public function test_exports_paragraph_as_wapf_content_with_plain_p_content(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'care-note', 'label' => '', 'type' => 'paragraph', 'content' => "Wash cold.\nDo not bleach." ] ],
		] ) );

		$this->assertSame( 'content', $payload['fields'][0]['type'] );
		$this->assertSame( "Wash cold.\nDo not bleach.", $payload['fields'][0]['p_content'] );
		$this->assertFalse( $payload['fields'][0]['required'] );
		$this->assertFalse( $payload['fields'][0]['pricing']['enabled'] );
	}

	public function test_refuses_lossy_html_in_free_wapf_paragraph_export(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'sanitizes paragraph content' );
		WapfExporter::build_payload( FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'care-note', 'type' => 'paragraph', 'content' => '<strong>Wash cold</strong>' ] ],
		] ) );
	}

	public function test_exports_extended_html_paragraph_as_p_with_shortcode_processing(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'offer', 'label' => 'Offer', 'type' => 'paragraph',
			'content_format' => 'html', 'process_shortcodes' => true,
			'content' => '<strong>Special</strong> [site_name]',
		] ] ] ) );

		$this->assertSame( 'p', $payload['fields'][0]['type'] );
		$this->assertSame( '<strong>Special</strong> [site_name]', $payload['fields'][0]['p_content'] );
		$round_trip = WapfMapper::map( [ 'fields' => $payload['fields'] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( 'html', $round_trip['group']['fields'][0]['content_format'] );
		$this->assertTrue( $round_trip['group']['fields'][0]['process_shortcodes'] );
	}

	public function test_refuses_extended_paragraph_export_when_shortcode_policy_would_change(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'always processes shortcodes' );
		WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'offer', 'type' => 'paragraph', 'content_format' => 'html',
			'process_shortcodes' => false, 'content' => '[site_name]',
		] ] ] ) );
	}

	public function test_exports_informative_image_as_wapf_img_with_media_references(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'fabric-guide', 'label' => 'Fabric guide', 'type' => 'content_image',
			'image_url' => 'https://example.test/fabric.jpg', 'image_id' => 481,
		] ] ] ) );

		$this->assertSame( 'img', $payload['fields'][0]['type'] );
		$this->assertSame( 'https://example.test/fabric.jpg', $payload['fields'][0]['image'] );
		$this->assertSame( 481, $payload['fields'][0]['attachment'] );
		$this->assertFalse( $payload['fields'][0]['required'] );
	}

	public function test_exports_nested_sections_and_rejects_unbalanced_markers(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'details', 'type' => 'section' ],
			[ 'id' => 'details-inner', 'type' => 'section' ],
			[ 'id' => 'inner-end', 'type' => 'section_end' ],
			[ 'id' => 'outer-end', 'type' => 'section_end' ],
		] ] ) );
		$this->assertSame( [ 'section', 'section', 'sectionend', 'sectionend' ], array_column( $payload['fields'], 'type' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'section-end marker without an open section' );
		WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [ 'id' => 'orphan', 'type' => 'section_end' ] ] ] ) );
	}

	public function test_rejects_unclosed_section_on_export(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'section without a matching section-end marker' );
		WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [ 'id' => 'open', 'type' => 'section' ] ] ] ) );
	}

	public function test_exports_logged_in_and_logged_out_placement(): void {
		$group = FieldGroup::normalize( [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'user_auth', 'operator' => 'not_in', 'terms' => [ 'logged_in' ] ] ] ] ] ] );
		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( [ 'subject' => 'user', 'condition' => '!auth', 'value' => [] ], $payload['conditions'][0]['rules'][0] );
		$round_trip = WapfMapper::map( [ 'fields' => [], 'rule_groups' => $payload['conditions'] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( [ 'operator' => 'not_in', 'terms' => [ 'logged_in' ] ], array_intersect_key( $round_trip['group']['rule_groups'][0]['rules'][0], array_flip( [ 'operator', 'terms' ] ) ) );
	}

	public function test_exports_role_and_language_rules_as_wapf_user_and_system_conditions(): void {
		$group = FieldGroup::normalize( [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'user_role', 'operator' => 'in', 'terms' => [ 'wholesale' ] ],
			[ 'subject' => 'user_role', 'operator' => 'not_in', 'terms' => [ 'suspended' ] ],
			[ 'subject' => 'user_language', 'operator' => 'in', 'terms' => [ 'nl_NL' ] ],
		] ] ] ] );
		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( [ 'user', 'role' ], [ $payload['conditions'][0]['rules'][0]['subject'], $payload['conditions'][0]['rules'][0]['condition'] ] );
		$this->assertSame( '!role', $payload['conditions'][0]['rules'][1]['condition'] );
		$this->assertSame( [ 'system', 'lang' ], [ $payload['conditions'][0]['rules'][2]['subject'], $payload['conditions'][0]['rules'][2]['condition'] ] );
		$round_trip = WapfMapper::map( [ 'fields' => [], 'rule_groups' => $payload['conditions'] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( [ 'user_role', 'user_role', 'user_language' ], array_column( $round_trip['group']['rule_groups'][0]['rules'], 'subject' ) );
	}

	public function test_expands_any_rules_into_or_conditionals_and_maps_wapf_pro_operators(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'source', 'label' => 'Source', 'type' => 'text' ],
				[ 'id' => 'other', 'label' => 'Other', 'type' => 'text' ],
				[ 'id' => 'target', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'action' => 'show', 'logic' => 'any', 'rules' => [
					[ 'field' => 'source', 'operator' => 'not_contains', 'value' => 'bad' ],
					[ 'field' => 'other', 'operator' => 'greater', 'value' => '3' ],
				] ] ] ],
			],
		] );

		$payload = WapfExporter::build_payload( $group );

		$this->assertCount( 2, $payload['fields'][2]['conditionals'] );
		$this->assertSame( '!=contains', $payload['fields'][2]['conditionals'][0]['rules'][0]['condition'] );
		$this->assertSame( 'gt', $payload['fields'][2]['conditionals'][1]['rules'][0]['condition'] );
	}

	public function test_refuses_settings_that_wapf_tools_import_would_not_preserve(): void {
		$group = FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'target', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'action' => 'hide', 'logic' => 'all', 'rules' => [ [ 'field' => 'source', 'operator' => 'is', 'value' => 'bad' ] ] ] ] ] ],
		] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'hide conditionals' );
		WapfExporter::build_payload( $group );
	}

	public function test_maps_toggle_condition_truth_values(): void {
		$group = FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'enabled', 'label' => 'Enabled', 'type' => 'toggle' ],
			[ 'id' => 'target', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [
				[ 'field' => 'enabled', 'operator' => 'is', 'value' => '0' ],
			] ] ] ],
		] ] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( 'true-false', $payload['fields'][0]['type'] );
		$this->assertSame( '!check', $payload['fields'][1]['conditionals'][0]['rules'][0]['condition'] );
	}

	public function test_refuses_unknown_group_and_field_data_instead_of_silently_dropping_it(): void {
		$group = FieldGroup::normalize( [ 'fields' => [ [ 'id' => 'target', 'label' => 'Target', 'type' => 'text' ] ] ] );
		$group['fields'][0]['unknown'] = 'loss';

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot preserve' );
		WapfExporter::build_payload( $group );
	}

	public function test_exports_image_swatches_and_media_references(): void {
		$group = FieldGroup::normalize( [
			'fields' => [ [
				'id' => 'finish',
				'label' => 'Finish',
				'type' => 'swatch',
				'swatch_style' => 'image',
				'image_zoom' => true,
				'label_pos' => 'tooltip',
				'grid_layout' => 'flexible',
				'items_per_row' => 4,
				'items_per_row_tablet' => 2,
				'items_per_row_mobile' => 1,
				'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image' => 'https://example.test/oak.jpg', 'image_id' => 481 ] ],
			] ],
		] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( 'image-swatch', $payload['fields'][0]['type'] );
		$this->assertTrue( $payload['fields'][0]['large_image'] );
		$this->assertSame( 'tooltip', $payload['fields'][0]['label_pos'] );
		$this->assertSame( [ 4, 2, 1 ], [ $payload['fields'][0]['items_per_row'], $payload['fields'][0]['items_per_row_tablet'], $payload['fields'][0]['items_per_row_mobile'] ] );
		$this->assertSame( 'https://example.test/oak.jpg', $payload['fields'][0]['choices'][0]['image'] );
		$this->assertSame( 481, $payload['fields'][0]['choices'][0]['attachment'] );
	}

	public function test_exports_multi_color_swatches_and_selection_limits(): void {
		$group = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'palette', 'label' => 'Palette', 'type' => 'swatch', 'swatch_style' => 'color',
			'multiple' => true, 'min_choices' => 1, 'max_choices' => 2,
			'color_layout' => 'rounded', 'color_size' => 36, 'color_label_pos' => 'default',
			'choices' => [ [ 'slug' => 'navy', 'label' => 'Navy', 'color' => '#123456' ] ],
		] ] ] );

		$field = WapfExporter::build_payload( $group )['fields'][0];
		$this->assertSame( 'multi-color-swatch', $field['type'] );
		$this->assertSame( [ 1, 2 ], [ $field['min_choices'], $field['max_choices'] ] );
		$this->assertSame( [ 'rounded', 36, 'default' ], [ $field['layout'], $field['size'], $field['label_pos'] ] );
		$this->assertSame( '#123456', $field['choices'][0]['color'] );
	}

	public function test_exports_formula_field_references_as_resolvable_wapf_ids(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'fee', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
				[ 'id' => 'weight', 'label' => 'Weight', 'type' => 'number',
					'pricing' => [ 'type' => 'formula', 'formula' => '[field.plan] + [price.fee]', 'per_unit' => false ] ],
				[ 'id' => 'plan', 'label' => 'Plan', 'type' => 'select',
					'choices' => [
						[ 'slug' => 'custom', 'label' => 'Custom', 'pricing' => [ 'type' => 'formula', 'formula' => '[field.weight] + checked(plan)', 'per_unit' => true ] ],
						[ 'slug' => 'prior', 'label' => 'Prior', 'pricing' => [ 'type' => 'formula', 'formula' => '[price.fee] * 2', 'per_unit' => false ] ],
					] ],
			],
		] );

		$payload = WapfExporter::build_payload( $group );

		$this->assertSame( 'fx', $payload['fields'][1]['pricing']['type'] );
		$this->assertSame( '[field.plan] + [price.fee]', $payload['fields'][1]['pricing']['amount'] );
		$this->assertSame( 'fx', $payload['fields'][2]['choices'][0]['pricing_type'] );
		$this->assertSame( '([field.weight] + checked(plan)) * [qty]', $payload['fields'][2]['choices'][0]['pricing_amount'] );
		$this->assertSame( '[price.fee] * 2', $payload['fields'][2]['choices'][1]['pricing_amount'] );

		$wapf_fields = array_map( static function ( array $field ): array {
			$field['options'] = array_intersect_key( $field, array_flip( [ 'choices', 'placeholder', 'default', 'p_content', 'minimum', 'maximum' ] ) );
			return $field;
		}, $payload['fields'] );
		$round_trip = WapfMapper::map( [ 'fields' => $wapf_fields ] );

		$this->assertFalse( $round_trip['needs_review'] );
		$weight = $round_trip['group']['fields'][1]['pricing'];
		$this->assertSame( 'formula', $weight['type'] );
		$this->assertSame( '[field.plan] + [price.fee]', $weight['formula'] );
		$this->assertFalse( $weight['per_unit'] );
		$custom = $round_trip['group']['fields'][2]['choices'][0]['pricing'];
		$this->assertSame( '([field.weight] + checked(plan)) * [qty]', $custom['formula_raw'] );
		$this->assertSame( '([field.weight] + checked(plan))', $custom['formula'] );
		$this->assertTrue( $custom['per_unit'] );
		$prior = $round_trip['group']['fields'][2]['choices'][1]['pricing'];
		$this->assertSame( '[price.fee] * 2', $prior['formula'] );
		$this->assertSame( 6.0, \OPF\Engine\Calculator::evaluate_formula( $weight['formula'], 100.0, 1, 0.0, '', null, [ 'plan' => '1' ], 0, [ 'fee' => 5.0 ] ) );
	}

	public function test_exported_formula_references_keep_importer_review_flags(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'plan', 'label' => 'Plan', 'type' => 'select',
					'choices' => [
						[ 'slug' => 'bulk', 'label' => 'Bulk', 'pricing' => [ 'type' => 'formula', 'formula' => 'sumQty(plan) + files(plan) + [price.fee]', 'per_unit' => false ] ],
					] ],
				[ 'id' => 'fee', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
			],
		] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( 'sumQty(plan) + files(plan) + [price.fee]', $payload['fields'][0]['choices'][0]['pricing_amount'] );

		$wapf_fields = array_map( static function ( array $field ): array {
			$field['options'] = array_intersect_key( $field, array_flip( [ 'choices' ] ) );
			return $field;
		}, $payload['fields'] );
		$round_trip = WapfMapper::map( [ 'fields' => $wapf_fields ] );

		// sumQty() on a non-quantity target, files(), and forward [price.*]
		// references need runtime review; IDs resolve and the formula survives.
		$this->assertTrue( $round_trip['needs_review'] );
		$this->assertContains( 'choice "Bulk" formula contains references that require runtime review ([price.fee], sumqty(plan), files(plan)); pricing needs review.', $round_trip['notes'] );
		$pricing = $round_trip['group']['fields'][0]['choices'][0]['pricing'];
		$this->assertSame( 'formula', $pricing['type'] );
		$this->assertSame( 'sumQty(plan) + files(plan) + [price.fee]', $pricing['formula'] );
	}

	public function test_normalizes_formula_reference_case_to_exported_field_ids(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'fee', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
				[ 'id' => 'weight', 'label' => 'Weight', 'type' => 'number',
					'pricing' => [ 'type' => 'formula', 'formula' => '[FIELD.Plan] + [PRICE.Fee] + CHECKED(Plan)', 'per_unit' => false ] ],
				[ 'id' => 'plan', 'label' => 'Plan', 'type' => 'select',
					'choices' => [ [ 'slug' => 'a', 'label' => 'A' ] ] ],
			],
		] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( '[field.plan] + [price.fee] + CHECKED(plan)', $payload['fields'][1]['pricing']['amount'] );

		$wapf_fields = array_map( static function ( array $field ): array {
			$field['options'] = array_intersect_key( $field, array_flip( [ 'choices' ] ) );
			return $field;
		}, $payload['fields'] );
		$round_trip = WapfMapper::map( [ 'fields' => $wapf_fields ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( '[field.plan] + [price.fee] + CHECKED(plan)', $round_trip['group']['fields'][1]['pricing']['formula'] );
	}

	public function test_rejects_formula_references_absent_from_export(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unknown field IDs: ghost' );
		WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'weight', 'label' => 'Weight', 'type' => 'number',
				'pricing' => [ 'type' => 'formula', 'formula' => '[field.weight] + sumQty(ghost)', 'per_unit' => false ] ],
		] ] ) );
	}

	public function test_rejects_choice_formula_references_absent_from_export(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot preserve formula references' );
		WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'plan', 'label' => 'Plan', 'type' => 'select',
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing' => [ 'type' => 'formula', 'formula' => '[price.missing] * 2', 'per_unit' => false ] ] ] ],
		] ] ) );
	}
}
