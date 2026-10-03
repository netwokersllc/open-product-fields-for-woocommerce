<?php
namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\Calculator;
use OPF\Engine\WapfMapper;
use OPF\Service\WapfExporter;
use PHPUnit\Framework\TestCase;

final class ToggleFieldTest extends TestCase {
	public function test_unchecked_toggle_never_charges_field_pricing(): void {
		foreach ( [ 'fixed', 'percent', 'formula' ] as $type ) {
			$field = FieldGroup::normalize_field( [ 'type' => 'toggle', 'pricing' => [ 'type' => $type, 'amount' => 2, 'formula' => '2' ] ] );
			$this->assertSame( 0.0, Calculator::field_addon( $field, '0', [ 'price' => 10, 'qty' => 1 ] ) );
			$this->assertGreaterThan( 0, Calculator::field_addon( $field, '1', [ 'price' => 10, 'qty' => 1 ] ) );
		}
	}
	public function test_toggle_settings_roundtrip_and_false_condition_are_canonical(): void {
		$source = [ 'fields' => [
			[ 'id' => 'wrap', 'type' => 'true-false', 'label' => 'Gift', 'required' => true, 'options' => [ 'message' => 'Wrap & ribbon', 'default' => 'checked' ] ],
			[ 'id' => 'other', 'type' => 'true-false', 'conditionals' => [ [ 'rules' => [ [ 'field' => 'wrap', 'condition' => '!check' ] ] ] ] ],
		] ];
		$mapped = WapfMapper::map( $source );
		$this->assertFalse( $mapped['needs_review'] );
		$fields = $mapped['group']['fields'];
		$this->assertSame( 'Wrap & ribbon', $fields[0]['message'] );
		$this->assertSame( '1', $fields[0]['default'] );
		$this->assertSame( '0', $fields[1]['default'] );
		$this->assertSame( 'is_not', $fields[1]['conditionals'][0]['rules'][0]['operator'] );
		$this->assertSame( '1', $fields[1]['conditionals'][0]['rules'][0]['value'] );
		$payload = WapfExporter::build_payload( $mapped['group'] );
		$this->assertSame( 'checked', $payload['fields'][0]['default'] );
		$this->assertSame( 'Wrap & ribbon', $payload['fields'][0]['message'] );
		$this->assertSame( '!check', $payload['fields'][1]['conditionals'][0]['rules'][0]['condition'] );
	}

	public function test_toggle_settings_do_not_extend_other_types(): void {
		// `message` stays toggle-only; `default` is a WAPF parity setting
		// shared by every scalar input type, so email now retains it.
		$field = FieldGroup::normalize_field( [ 'type' => 'email', 'message' => 'Ignored', 'default' => '1' ] );
		$this->assertArrayNotHasKey( 'message', $field );
		$this->assertSame( '1', $field['default'] );
		$this->assertSame( 'Safe & sound', FieldGroup::normalize_field( [ 'type' => 'toggle', 'message' => '<b>Safe & sound</b>', 'default' => false ] )['message'] );
	}

	public function test_toggle_default_rejects_malformed_editor_data(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'type' => 'toggle', 'default' => [ '1' ] ] );
	}
}
