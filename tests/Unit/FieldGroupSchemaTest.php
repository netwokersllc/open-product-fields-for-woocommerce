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

	public function test_paragraph_is_static_text_without_submission_or_pricing(): void {
		$group = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'care-note',
			'label' => 'Care note',
			'type' => 'paragraph',
			'content' => "Wash cold.\nDo not bleach.",
			'required' => true,
			'pricing' => [ 'type' => 'fixed', 'amount' => 9 ],
		] ] ] );

		$this->assertSame( 'paragraph', $group['fields'][0]['type'] );
		$this->assertSame( "Wash cold.\nDo not bleach.", $group['fields'][0]['content'] );
		$this->assertFalse( $group['fields'][0]['required'] );
		$this->assertSame( 'none', $group['fields'][0]['pricing']['type'] );
	}

	public function test_image_choices_preserve_safe_url_and_positive_attachment_id(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'finish',
			'type' => 'swatch',
			'choices' => [
				[ 'slug' => 'oak', 'label' => 'Oak', 'image' => 'https://example.test/oak.jpg', 'image_id' => 481 ],
				[ 'slug' => 'bad-url', 'label' => 'Unsafe', 'image' => 'javascript:alert(1)', 'image_id' => -4 ],
			],
		] );

		$this->assertSame( 'https://example.test/oak.jpg', $field['choices'][0]['image'] );
		$this->assertSame( 481, $field['choices'][0]['image_id'] );
		$this->assertArrayNotHasKey( 'image', $field['choices'][1] );
		$this->assertArrayNotHasKey( 'image_id', $field['choices'][1] );
	}

	public function test_duplicate_remaps_internal_field_references_and_formula_tokens(): void {
		$result = FieldGroup::duplicate(
			[
				'fields' => [
					[
						'id' => 'length',
						'label' => 'Length',
						'type' => 'number',
						'pricing' => [ 'type' => 'formula', 'formula' => '[field.length] + [field.length-extra] + [price.width]', 'formula_raw' => '[field.length] + [price.width]' ],
						'choices' => [ [ 'slug' => 'custom', 'label' => 'Custom', 'pricing' => [ 'type' => 'formula', 'formula' => '[price.width]' ] ] ],
						'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'width', 'operator' => 'is', 'value' => 'long' ] ] ] ],
					],
					[ 'id' => 'width', 'label' => 'Width', 'type' => 'select', 'choices' => [ [ 'slug' => 'long', 'label' => 'Long' ] ] ],
				],
			]
		);

		$this->assertSame( [ 'length' => 'length-copy', 'width' => 'width-copy' ], $result['field_id_map'] );
		$this->assertSame( 'length-copy', $result['group']['fields'][0]['id'] );
		$this->assertSame( '[field.length-copy] + [field.length-extra] + [price.width-copy]', $result['group']['fields'][0]['pricing']['formula'] );
		$this->assertSame( '[field.length-copy] + [price.width-copy]', $result['group']['fields'][0]['pricing']['formula_raw'] );
		$this->assertSame( '[price.width-copy]', $result['group']['fields'][0]['choices'][0]['pricing']['formula'] );
		$this->assertSame( 'width-copy', $result['group']['fields'][0]['conditionals'][0]['rules'][0]['field'] );
		$this->assertSame( [ 'long' ], array_column( $result['group']['fields'][1]['choices'], 'slug' ) );
	}

	public function test_duplicate_ids_uses_collision_free_suffixes(): void {
		$result = FieldGroup::duplicate(
			[
				'fields' => [
					[ 'id' => 'length', 'label' => 'First', 'type' => 'text' ],
					[ 'id' => 'length-copy', 'label' => 'Existing copy', 'type' => 'text' ],
				],
			]
		);

		$this->assertSame( [ 'length' => 'length-copy-2', 'length-copy' => 'length-copy-copy' ], $result['field_id_map'] );
	}

	public function test_duplicate_rejects_repeated_source_field_ids(): void {
		$this->expectException( InvalidArgumentException::class );

		FieldGroup::duplicate( [ 'fields' => [ [ 'id' => 'same', 'label' => 'A' ], [ 'id' => 'same', 'label' => 'B' ] ] ] );
	}
}
