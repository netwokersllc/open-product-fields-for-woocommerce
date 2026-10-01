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

	public function test_paragraph_html_mode_and_shortcode_policy_are_normalized(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'offer', 'type' => 'paragraph', 'content' => '<strong>Offer</strong> [tag]',
			'content_format' => 'html', 'process_shortcodes' => true,
		] );

		$this->assertSame( 'html', $field['content_format'] );
		$this->assertTrue( $field['process_shortcodes'] );

		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'offer', 'type' => 'paragraph', 'content_format' => 'script' ] );
	}

	public function test_content_image_is_static_and_rejects_unsafe_image_references(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'fabric-guide', 'type' => 'content_image', 'image_url' => 'https://example.test/fabric.jpg',
			'image_id' => '481', 'required' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ],
		] );
		$this->assertSame( 'content_image', $field['type'] );
		$this->assertFalse( $field['required'] );
		$this->assertSame( 'none', $field['pricing']['type'] );
		$this->assertSame( 'https://example.test/fabric.jpg', $field['image_url'] );
		$this->assertSame( 481, $field['image_id'] );

		$unsafe = FieldGroup::normalize_field( [ 'id' => 'bad', 'type' => 'content_image', 'image_url' => 'javascript:alert(1)' ] );
		$this->assertSame( '', $unsafe['image_url'] );
	}

	public function test_section_markers_are_non_submittable_and_unpriced(): void {
		$group = FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'details', 'type' => 'section', 'required' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 4 ] ],
			[ 'id' => 'details-end', 'type' => 'section_end' ],
		] ] );
		$this->assertSame( [ 'section', 'section_end' ], array_column( $group['fields'], 'type' ) );
		$this->assertFalse( $group['fields'][0]['required'] );
		$this->assertSame( 'none', $group['fields'][0]['pricing']['type'] );
	}

	public function test_repeater_settings_normalize_button_and_quantity_modes(): void {
		$button = FieldGroup::normalize_field( [ 'id' => 'name', 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 12 ] ] );
		$quantity = FieldGroup::normalize_field( [ 'id' => 'ticket-name', 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'qty' ] ] );
		$section = FieldGroup::normalize_field( [ 'id' => 'attendees', 'type' => 'section', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ] );

		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 12 ], $button['repeat'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'quantity' ], $quantity['repeat'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'quantity' ], $section['repeat'] );
	}

	public function test_disabled_repeaters_are_omitted_and_button_max_is_bounded(): void {
		$disabled = FieldGroup::normalize_field( [ 'id' => 'name', 'type' => 'text', 'repeat' => [ 'enabled' => false, 'mode' => 'button' ] ] );
		$this->assertArrayNotHasKey( 'repeat', $disabled );

		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'name', 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 1001 ] ] );
	}

	public function test_invalid_repeater_modes_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'name', 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'clone' ] ] );
	}

	public function test_section_end_cannot_be_repeated(): void {
		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'end', 'type' => 'section_end', 'repeat' => [ 'enabled' => true ] ] );
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

	public function test_image_swatch_layout_settings_are_bounded_and_normalized(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'finish',
			'type' => 'swatch',
			'swatch_style' => 'image',
			'image_zoom' => true,
			'label_pos' => 'tooltip',
			'grid_layout' => 'flexible',
			'items_per_row' => 4,
			'items_per_row_tablet' => 2,
			'items_per_row_mobile' => 1,
			'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image' => '/oak.jpg' ] ],
		] );

		$this->assertSame( 'image', $field['swatch_style'] );
		$this->assertTrue( $field['image_zoom'] );
		$this->assertSame( 'tooltip', $field['label_pos'] );
		$this->assertSame( 'flexible', $field['grid_layout'] );
		$this->assertSame( [ 4, 2, 1 ], [ $field['items_per_row'], $field['items_per_row_tablet'], $field['items_per_row_mobile'] ] );
	}

	public function test_multi_color_swatch_options_and_colors_are_normalized_safely(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'palette',
			'type' => 'swatch',
			'swatch_style' => 'color',
			'multiple' => true,
			'min_choices' => 1,
			'max_choices' => 2,
			'color_layout' => 'rounded',
			'color_size' => 36,
			'color_label_pos' => 'hide',
			'choices' => [
				[ 'slug' => 'navy', 'label' => 'Navy', 'color' => '#123abc' ],
				[ 'slug' => 'bad', 'label' => 'Bad color', 'color' => 'url(javascript:bad)' ],
			],
		] );

		$this->assertTrue( $field['multiple'] );
		$this->assertSame( [ 1, 2 ], [ $field['min_choices'], $field['max_choices'] ] );
		$this->assertSame( [ 'rounded', 36, 'hide' ], [ $field['color_layout'], $field['color_size'], $field['color_label_pos'] ] );
		$this->assertSame( '#123ABC', $field['choices'][0]['color'] );
		$this->assertArrayNotHasKey( 'color', $field['choices'][1] );
	}

	public function test_multi_swatch_selection_limits_must_be_consistent(): void {
		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [
			'id' => 'options', 'type' => 'swatch', 'multiple' => true,
			'min_choices' => 3, 'max_choices' => 2,
		] );
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
