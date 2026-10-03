<?php

namespace OPF\Tests\Unit;

use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

/**
 * Cards + zoom cluster: quantity-enabled child-product card conditionals
 * (WAPF `products-card-qty`/`products-vcard-qty` `empty`/`!empty` rules) and
 * the image-quantity / gallery-image schema that backs the zoom + main-image
 * rows.
 */
final class CardsConditionalsSchemaTest extends TestCase {

	/* --------------------------------------------------------------
	 * Evaluator: qty-selector conditional semantics
	 * -------------------------------------------------------------- */

	public function test_qty_map_accepts_structured_and_raw_maps(): void {
		$this->assertSame(
			[ 'a' => 2, 'b' => 0 ],
			Evaluator::qty_map( [ '_opf_type' => 'products', 'quantities' => [ 'a' => 2, 'b' => 0 ] ] )
		);
		$this->assertSame(
			[ 'a' => 2, 'b' => 0 ],
			Evaluator::qty_map( [ 'a' => 2, 'b' => 0 ] )
		);
	}

	public function test_qty_map_rejects_sequential_lists_and_scalars(): void {
		$this->assertNull( Evaluator::qty_map( [ 'a', 'b' ] ) );
		$this->assertNull( Evaluator::qty_map( [] ) );
		$this->assertNull( Evaluator::qty_map( 'a' ) );
	}

	public function test_empty_and_not_empty_ignore_zero_quantities(): void {
		$zeroed = [ '_opf_type' => 'products', 'quantities' => [ 'a' => 0, 'b' => 0 ] ];
		$some   = [ '_opf_type' => 'products', 'quantities' => [ 'a' => 0, 'b' => 3 ] ];
		$this->assertTrue( Evaluator::rule_passes( [ 'field' => 'f', 'operator' => 'empty', 'value' => '' ], $zeroed ) );
		$this->assertFalse( Evaluator::rule_passes( [ 'field' => 'f', 'operator' => 'not_empty', 'value' => '' ], $zeroed ) );
		$this->assertFalse( Evaluator::rule_passes( [ 'field' => 'f', 'operator' => 'empty', 'value' => '' ], $some ) );
		$this->assertTrue( Evaluator::rule_passes( [ 'field' => 'f', 'operator' => 'not_empty', 'value' => '' ], $some ) );
	}

	public function test_is_visible_with_qty_subject_matches_wapf_card_qty_rules(): void {
		$field = [
			'type'         => 'text',
			'conditionals' => [
				[ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'extras', 'operator' => 'not_empty', 'value' => '' ] ] ],
			],
		];
		$values = [ 'extras' => [ '_opf_type' => 'products', 'quantities' => [ 'p1' => 0, 'p2' => 0 ] ] ];
		$this->assertFalse( Evaluator::is_visible( $field, $values ) );
		$values['extras']['quantities']['p2'] = 4;
		$this->assertTrue( Evaluator::is_visible( $field, $values ) );
	}

	public function test_contains_matches_a_submitted_quantity_but_not_a_zero_total(): void {
		$rule = [ 'field' => 'extras', 'operator' => 'contains', 'value' => '2' ];
		$this->assertTrue( Evaluator::rule_passes( $rule, [ 'p1' => 2 ] ) );
		$this->assertFalse( Evaluator::rule_passes( $rule, [ 'p1' => 0 ] ) );
	}

	public function test_greater_and_less_compare_positive_quantity_totals(): void {
		$map = [ 'p1' => 2, 'p2' => 3 ];
		$this->assertTrue( Evaluator::rule_passes( [ 'field' => 'f', 'operator' => 'greater', 'value' => '4' ], $map ) );
		$this->assertFalse( Evaluator::rule_passes( [ 'field' => 'f', 'operator' => 'less', 'value' => '4' ], $map ) );
		$this->assertTrue( Evaluator::rule_passes( [ 'field' => 'f', 'operator' => 'less', 'value' => '6' ], $map ) );
	}

	/* --------------------------------------------------------------
	 * FieldGroup: image_quantity large_image zoom schema
	 * -------------------------------------------------------------- */

	public function test_image_quantity_large_image_normalizes_to_image_zoom(): void {
		foreach ( [ 'image_zoom', 'large_image' ] as $key ) {
			$field = FieldGroup::normalize_field( [
				'id' => 'shirts', 'type' => 'image_quantity',
				$key   => true,
				'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ],
			] );
			$this->assertTrue( $field['image_zoom'], "large_image via {$key} spelling" );
		}
	}

	public function test_image_quantity_reads_large_image_from_options(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'shirts', 'type' => 'image_quantity',
			'options' => [ 'large_image' => '1' ],
			'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ],
		] );
		$this->assertTrue( $field['image_zoom'] );
	}

	public function test_image_quantity_zoom_rejects_non_boolean(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [
			'id' => 'shirts', 'type' => 'image_quantity',
			'large_image' => 'maybe',
			'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ],
		] );
	}

	/* --------------------------------------------------------------
	 * FieldGroup: WAPF group-level gallery-image rules
	 * -------------------------------------------------------------- */

	public function test_group_layout_gallery_rules_normalize(): void {
		$group = new FieldGroup( [
			'fields' => [
				[ 'id' => 'color', 'type' => 'select', 'label' => 'Color', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ] ],
			],
			'layout' => [
				'enable_gallery_images' => '1',
				'swap_type'             => 'last',
				'gallery_images'        => [ [
					'source' => 'upload',
					'url'    => 'https://example.test/full.jpg',
					'id'     => 321,
					'values' => [ [ 'field' => 'color', 'value' => 'red' ] ],
				] ],
			],
		] );
		$layout = $group->data['layout'];
		$this->assertTrue( $layout['enable_gallery_images'] );
		$this->assertSame( 'last', $layout['swap_type'] );
		$this->assertSame( '321', $layout['gallery_images'][0]['id'] );
		$this->assertSame( [ [ 'field' => 'color', 'value' => 'red' ] ], $layout['gallery_images'][0]['values'] );
	}

	public function test_group_layout_defaults_swap_type_to_rules_and_wildcard(): void {
		$group = new FieldGroup( [
			'fields' => [ [ 'id' => 'color', 'type' => 'select', 'label' => 'Color', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ] ] ],
			'layout' => [
				'enable_gallery_images' => true,
				'gallery_images'        => [ [ 'id' => 9, 'values' => [ [ 'field' => 'color' ] ] ] ],
			],
		] );
		$this->assertSame( 'rules', $group->data['layout']['swap_type'] );
		$this->assertSame( '*', $group->data['layout']['gallery_images'][0]['values'][0]['value'] );
	}

	public function test_flat_gallery_alias_is_accepted(): void {
		$group = new FieldGroup( [
			'fields' => [ [ 'id' => 'color', 'type' => 'select', 'label' => 'Color', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ] ] ],
			'gallery' => [
				'enabled' => true,
				'images'  => [ [ 'id' => 5, 'values' => [ [ 'field' => 'color', 'value' => 'red' ] ] ] ],
			],
		] );
		$this->assertTrue( $group->data['layout']['enable_gallery_images'] );
		$this->assertSame( '5', $group->data['layout']['gallery_images'][0]['id'] );
	}

	public function test_layout_key_is_absent_when_no_gallery_config(): void {
		$group = new FieldGroup( [
			'fields' => [ [ 'id' => 'color', 'type' => 'select', 'label' => 'Color', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ] ] ],
		] );
		$this->assertArrayNotHasKey( 'layout', $group->data );
	}
}
