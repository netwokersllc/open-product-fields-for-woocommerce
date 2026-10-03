<?php
/**
 * WAPF Extended `calc` field parity — schema, pricing, import/export.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Tests\Unit;

use OPF\Engine\Calculator;
use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;
use OPF\Engine\WapfMapper;
use OPF\Service\WapfExporter;
use PHPUnit\Framework\TestCase;

final class CalcFieldTest extends TestCase {

	public function test_default_calc_normalizes_without_pricing(): void {
		$field = FieldGroup::normalize_field( [
			'id'            => 'total',
			'type'          => 'calc',
			'label'         => 'Total',
			'calc_type'     => 'default',
			'formula'       => '[field.qty] * [field.rate]',
			'result_format' => 'none',
			'result_text'   => 'Total: {result}',
		] );

		$this->assertSame( 'calc', $field['type'] );
		$this->assertSame( 'default', $field['calc_type'] );
		$this->assertSame( '[field.qty] * [field.rate]', $field['formula'] );
		$this->assertSame( 'none', $field['result_format'] );
		$this->assertSame( 'Total: {result}', $field['result_text'] );
		$this->assertSame( 'none', $field['pricing']['type'] );
		$this->assertFalse( $field['required'] );
		$this->assertSame( [], $field['choices'] );
	}

	public function test_cost_calc_derives_signed_formula_pricing(): void {
		$field = FieldGroup::normalize_field( [
			'id'        => 'cost',
			'type'      => 'calc',
			'calc_type' => 'cost',
			'formula'   => '[field.qty] * 2',
		] );

		$this->assertSame( 'cost', $field['calc_type'] );
		$this->assertSame( 'formula', $field['pricing']['type'] );
		$this->assertSame( '[field.qty] * 2', $field['pricing']['formula'] );
		$this->assertFalse( $field['pricing']['per_unit'] );
	}

	public function test_options_total_token_is_normalized_to_addons(): void {
		$field = FieldGroup::normalize_field( [
			'id'      => 'fee',
			'type'    => 'calc',
			'calc_type' => 'cost',
			'formula' => '[price] + [options_total]',
		] );
		$this->assertSame( '[price] + [addons]', $field['formula'] );
	}

	public function test_cost_calc_enters_pricing_pipeline_as_signed_addon(): void {
		$field = FieldGroup::normalize_field( [
			'id'        => 'markup',
			'type'      => 'calc',
			'calc_type' => 'cost',
			'formula'   => '[field.rate] * 2',
		] );
		$context = [ 'price' => 100.0, 'qty' => 1, 'addons' => 0.0, 'field_values' => [ 'rate' => '5' ] ];
		$this->assertSame( 10.0, Calculator::field_addon( $field, '10', $context ) );

		$discount = FieldGroup::normalize_field( [
			'id'        => 'discount',
			'type'      => 'calc',
			'calc_type' => 'cost',
			'formula'   => '-8',
		] );
		$this->assertSame( -8.0, Calculator::field_addon( $discount, '-8', $context ) );
	}

	public function test_calc_value_helper_evaluates_formula(): void {
		$field = FieldGroup::normalize_field( [
			'id'      => 'total',
			'type'    => 'calc',
			'formula' => '[field.a] + [field.b]',
		] );
		$this->assertSame( 7.0, Calculator::calc_value( $field, [ 'field_values' => [ 'a' => '3', 'b' => '4' ] ] ) );
	}

	public function test_calc_can_act_as_conditional_subject(): void {
		$dependent = FieldGroup::normalize_field( [
			'id'           => 'note',
			'type'         => 'text',
			'conditionals' => [ [
				'action' => 'show',
				'logic'  => 'all',
				'rules'  => [ [ 'field' => 'total', 'operator' => 'greater', 'value' => '10' ] ],
			] ],
		] );

		$this->assertTrue( Evaluator::is_visible( $dependent, [ 'total' => '12' ] ) );
		$this->assertFalse( Evaluator::is_visible( $dependent, [ 'total' => '4' ] ) );
		$this->assertFalse( Evaluator::is_visible( $dependent, [] ) );
	}

	public function test_wapf_mapper_imports_default_and_cost_calc_and_remaps_formula_ids(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[ 'id' => 'qty', 'type' => 'number', 'label' => 'Qty' ],
			[ 'id' => 'rate', 'type' => 'number', 'label' => 'Rate' ],
			[
				'id'      => 'total',
				'type'    => 'calc',
				'label'   => 'Total',
				'options' => [ 'calc_type' => 'default', 'formula' => '[field.qty] * [field.rate]', 'result_format' => 'number', 'result_text' => 'Total: {result}' ],
			],
			[
				'id'      => 'fee',
				'type'    => 'calc',
				'label'   => 'Fee',
				'options' => [ 'calc_type' => 'cost', 'formula' => '[field.total] * 0.1' ],
			],
		] ] );

		$this->assertFalse( $mapped['needs_review'], implode( ' | ', $mapped['notes'] ) );
		$fields = [];
		foreach ( $mapped['group']['fields'] as $field ) {
			$fields[ $field['id'] ] = $field;
		}
		$this->assertSame( 'Total: {result}', $fields['total']['result_text'] );
		$this->assertSame( 'none', $fields['total']['pricing']['type'] );
		// Field ids are slugified from labels; references resolve to the new ids.
		$this->assertSame( 'formula', $fields['fee']['pricing']['type'] );
		$this->assertStringContainsString( '[field.' . $fields['total']['id'] . ']', $fields['fee']['pricing']['formula'] );
	}

	public function test_wapf_mapper_flags_unportable_calc_formula_and_fails_closed(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[
				'id'      => 'bad',
				'type'    => 'calc',
				'label'   => 'Bad',
				'options' => [ 'calc_type' => 'cost', 'formula' => '[field.ghost] + 1' ],
			],
		] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertNotEmpty( $mapped['notes'] );
		$field = $mapped['group']['fields'][0];
		$this->assertSame( '0', $field['formula'] );
		$this->assertSame( 'formula', $field['pricing']['type'] );
		$this->assertSame( '0', $field['pricing']['formula'] );
	}

	public function test_wapf_export_round_trips_calc_fields(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'rate', 'label' => 'Rate', 'type' => 'number' ],
				[ 'id' => 'total', 'label' => 'Total', 'type' => 'calc', 'calc_type' => 'default', 'formula' => '[field.rate] * 2', 'result_format' => 'none', 'result_text' => 'Sum {result}' ],
				[ 'id' => 'fee', 'label' => 'Fee', 'type' => 'calc', 'calc_type' => 'cost', 'formula' => '[field.total] + [addons]' ],
			],
		] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( 'calc', $payload['fields'][1]['type'] );
		$this->assertSame( 'default', $payload['fields'][1]['calc_type'] );
		$this->assertSame( '[field.rate] * 2', $payload['fields'][1]['formula'] );
		$this->assertSame( 'none', $payload['fields'][1]['result_format'] );
		$this->assertSame( 'Sum {result}', $payload['fields'][1]['result_text'] );
		$this->assertSame( 'cost', $payload['fields'][2]['calc_type'] );
		$this->assertSame( '[field.total] + [options_total]', $payload['fields'][2]['formula'] );

		// WAPF import shape: flatten the exported calc options back into
		// `options` exactly like Field_Groups::raw_json_to_field_group does.
		$wapf_fields = array_map( static function ( array $field ): array {
			$options = array_intersect_key( $field, array_flip( [ 'calc_type', 'formula', 'result_format', 'result_text' ] ) );
			$field['options'] = $options ?: [];
			return $field;
		}, $payload['fields'] );
		$round_trip = WapfMapper::map( [ 'fields' => $wapf_fields ] );

		$this->assertFalse( $round_trip['needs_review'], implode( ' | ', $round_trip['notes'] ) );
		$fields = [];
		foreach ( $round_trip['group']['fields'] as $field ) {
			$fields[ $field['id'] ] = $field;
		}
		$this->assertSame( 'none', $fields['total']['result_format'] );
		$this->assertSame( 'formula', $fields['fee']['pricing']['type'] );
	}

	public function test_wxr_export_serializes_calc_options_into_the_options_bucket(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'rate', 'label' => 'Rate', 'type' => 'number' ],
				[ 'id' => 'total', 'label' => 'Total', 'type' => 'calc', 'calc_type' => 'cost', 'formula' => '[field.rate] + [addons]', 'result_text' => 'Total {result}' ],
			],
		] );

		$xml = \OPF\Service\WapfWxrExporter::build_document(
			[ [ 'id' => 7, 'title' => 'Calc group', 'status' => 'publish', 'data' => $group ] ],
			[ 'site_url' => 'https://example.test', 'site_title' => 'Example' ]
		);

		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item[1]/content:encoded' )->item( 0 )->textContent;
		$serialized = unserialize( $content, [ 'allowed_classes' => false ] );

		$calc = null;
		foreach ( $serialized['fields'] as $field ) {
			if ( 'calc' === ( $field['type'] ?? '' ) ) {
				$calc = $field;
			}
		}
		$this->assertNotNull( $calc );
		$this->assertSame( 'cost', $calc['options']['calc_type'] );
		$this->assertSame( '[field.rate] + [options_total]', $calc['options']['formula'] );
		$this->assertSame( 'Total {result}', $calc['options']['result_text'] );
		$this->assertFalse( $calc['pricing']['enabled'] );

		// The serialized native WAPF model imports back as a cost calc.
		$raw = [ 'fields' => $serialized['fields'] ];
		$round_trip = WapfMapper::map( $raw );
		$this->assertFalse( $round_trip['needs_review'], implode( ' | ', $round_trip['notes'] ) );
		$imported = null;
		foreach ( $round_trip['group']['fields'] as $field ) {
			if ( 'calc' === $field['type'] ) {
				$imported = $field;
			}
		}
		$this->assertNotNull( $imported );
		$this->assertSame( 'cost', $imported['calc_type'] );
		$this->assertSame( 'formula', $imported['pricing']['type'] );
	}
}
