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
}
