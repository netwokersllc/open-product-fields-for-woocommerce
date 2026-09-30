<?php
/**
 * WAPF Tools JSON payload normalization tests.
 */

namespace OPF\Tests\Unit;

use OPF\Service\WapfJsonFileImporter;
use PHPUnit\Framework\TestCase;

final class WapfJsonFileImporterTest extends TestCase {

	public function test_normalizes_flat_field_options_and_marks_missing_placement_for_review(): void {
		$prepared = WapfJsonFileImporter::prepare_payload( [
			'fields' => [
				[ 'id' => 'engraving', 'label' => 'Engraving', 'type' => 'text', 'placeholder' => 'Name', 'choices' => [], 'unknown_option' => 'keep in source' ],
			],
			'conditions' => [],
			'layout' => [ 'labels_position' => 'above' ],
			'variables' => [],
		] );

		$this->assertSame( 'keep in source', $prepared['payload']['fields'][0]['options']['unknown_option'] );
		$this->assertSame( 'Name', $prepared['payload']['fields'][0]['options']['placeholder'] );
		$this->assertSame( [], $prepared['payload']['rule_groups'] );
		$this->assertSame( [ 'labels_position' => 'above' ], $prepared['payload']['layout'] );
		$this->assertTrue( (bool) array_filter( $prepared['notes'], static fn ( $note ) => false !== strpos( $note, 'product placement' ) ) );
		$this->assertTrue( (bool) array_filter( $prepared['notes'], static fn ( $note ) => false !== strpos( $note, 'unknown_option' ) ) );
	}

	public function test_flags_conditions_and_variables_for_review_without_silently_mapping_them(): void {
		$prepared = WapfJsonFileImporter::prepare_payload( [
			'fields' => [ [ 'id' => 'f1', 'label' => 'Field', 'type' => 'text' ] ],
			'conditions' => [ [ 'id' => 'condition-1' ] ],
			'variables' => [ 'discount' => [ 'value' => 10 ] ],
		] );

		$this->assertSame( [], $prepared['payload']['rule_groups'] );
		$this->assertSame( [ 'discount' => [ 'value' => 10 ] ], $prepared['payload']['variables'] );
		$this->assertNotFalse( strpos( implode( ' ', $prepared['notes'] ), 'conditions were detected but not imported' ) );
		$this->assertNotFalse( strpos( implode( ' ', $prepared['notes'] ), 'variable definitions need review' ) );
	}

	public function test_rejects_missing_or_oversized_field_list(): void {
		$this->expectException( \InvalidArgumentException::class );
		WapfJsonFileImporter::prepare_payload( [ 'fields' => [] ] );
	}

	public function test_rejects_more_than_five_hundred_fields(): void {
		$this->expectException( \InvalidArgumentException::class );
		WapfJsonFileImporter::prepare_payload( [ 'fields' => array_fill( 0, 501, [ 'id' => 'f', 'type' => 'text' ] ) ] );
	}
}
