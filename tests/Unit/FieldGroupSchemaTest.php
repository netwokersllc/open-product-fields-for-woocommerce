<?php
/**
 * Field group schema-versioning tests.
 */

namespace OPF\Tests\Unit;

use InvalidArgumentException;
use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class FieldGroupSchemaTest extends TestCase {

	public function test_legacy_group_without_schema_is_upgraded_to_current_schema(): void {
		$group = FieldGroup::normalize(
			[
				'fields' => [ [ 'id' => 'note', 'label' => 'Note', 'type' => 'text' ] ],
			]
		);

		$this->assertSame( FieldGroup::SCHEMA, $group['schema'] );
		$this->assertSame( 'note', $group['fields'][0]['id'] );
	}

	public function test_unknown_future_schema_is_rejected_instead_of_silently_downgraded(): void {
		$this->expectException( InvalidArgumentException::class );

		FieldGroup::normalize( [ 'schema' => FieldGroup::SCHEMA + 1, 'fields' => [] ] );
	}

	public function test_email_and_toggle_are_canonical_field_types(): void {
		$group = FieldGroup::normalize(
			[
				'fields' => [
					[ 'id' => 'contact', 'label' => 'Contact email', 'type' => 'email' ],
					[ 'id' => 'gift-wrap', 'label' => 'Gift wrap', 'type' => 'toggle' ],
				],
			]
		);

		$this->assertSame( 'email', $group['fields'][0]['type'] );
		$this->assertSame( 'toggle', $group['fields'][1]['type'] );
	}

	public function test_date_is_a_canonical_field_type(): void {
		$group = FieldGroup::normalize( [ 'fields' => [ [ 'id' => 'delivery-date', 'label' => 'Delivery date', 'type' => 'date' ] ] ] );

		$this->assertSame( 'date', $group['fields'][0]['type'] );
	}

	public function test_paragraph_is_static_non_required_non_priced_text(): void {
		$group = FieldGroup::normalize(
			[
				'fields' => [
					[ 'id' => 'notice', 'label' => 'Notice', 'type' => 'paragraph', 'content' => "First line\nSecond line<script>alert(1)</script>", 'required' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 9 ] ],
				],
			]
		);
		$field = $group['fields'][0];

		$this->assertSame( 'paragraph', $field['type'] );
		$this->assertSame( "First line\nSecond linealert(1)", $field['content'] );
		$this->assertFalse( $field['required'] );
		$this->assertSame( 'none', $field['pricing']['type'] );
	}
}
