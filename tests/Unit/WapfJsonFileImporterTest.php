<?php
/**
 * WAPF Tools JSON payload normalization tests.
 */

namespace OPF\Tests\Unit;

use OPF\Service\WapfJsonFileImporter;
use OPF\Engine\WapfMapper;
use PHPUnit\Framework\TestCase;

final class WapfJsonFileImporterTest extends TestCase {

	public function test_normalizes_flat_field_options_and_marks_source_identity_for_review(): void {
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
		$this->assertTrue( (bool) array_filter( $prepared['notes'], static fn ( $note ) => false !== strpos( $note, 'source group ID and title' ) ) );
		$this->assertTrue( (bool) array_filter( $prepared['notes'], static fn ( $note ) => false !== strpos( $note, 'unknown_option' ) ) );
	}

	public function test_maps_supported_conditions_and_flags_unsupported_conditions_and_variables(): void {
		$prepared = WapfJsonFileImporter::prepare_payload( [
			'fields' => [ [ 'id' => 'f1', 'label' => 'Field', 'type' => 'text' ] ],
			'conditions' => [ [ 'rules' => [
				[ 'condition' => 'products', 'subject' => 'product', 'value' => [ [ 'id' => '42', 'text' => 'Product' ] ] ],
				[ 'condition' => 'auth', 'subject' => 'user', 'value' => [] ],
			] ] ],
			'variables' => [ 'discount' => [ 'value' => 10 ] ],
		] );

		$mapped = WapfMapper::map( $prepared['payload'] );
		$this->assertSame( [ 'product' => [ '42' ] ], [ $mapped['group']['rule_groups'][0]['rules'][0]['subject'] => $mapped['group']['rule_groups'][0]['rules'][0]['terms'] ] );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertTrue( (bool) array_filter( $mapped['notes'], static fn ( $note ) => false !== strpos( $note, 'placement condition "auth"' ) ) );
		$this->assertSame( [ 'discount' => [ 'value' => 10 ] ], $prepared['payload']['variables'] );
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

	public function test_rejects_malformed_group_conditions_instead_of_importing_a_broader_group(): void {
		$this->expectException( \InvalidArgumentException::class );
		WapfJsonFileImporter::prepare_payload( [
			'fields' => [ [ 'id' => 'f1', 'label' => 'Field', 'type' => 'text' ] ],
			'conditions' => [ [ 'rules' => 'not-a-rule-list' ] ],
		] );
	}

	public function test_inspects_json_file_and_keeps_supported_group_conditions(): void {
		$path = tempnam( sys_get_temp_dir(), 'opf-wapf-json-' );
		$this->assertNotFalse( $path );
		try {
			file_put_contents( $path, (string) json_encode( [
				'fields' => [ [ 'id' => 'f1', 'label' => 'Field', 'type' => 'text' ] ],
				'conditions' => [ [ 'rules' => [ [ 'condition' => 'products', 'subject' => 'product', 'value' => [ [ 'id' => '42', 'text' => 'Product' ] ] ] ] ] ],
				'layout' => [ 'mark_required' => false ],
				'variables' => [],
			] ) );
			$prepared = WapfJsonFileImporter::inspect_file( $path );
			$mapped = WapfMapper::map( $prepared['payload'] );
			$this->assertSame( 'product', $mapped['group']['rule_groups'][0]['rules'][0]['subject'] );
			$this->assertSame( [ '42' ], $mapped['group']['rule_groups'][0]['rules'][0]['terms'] );
			$this->assertFalse( $mapped['needs_review'] );
		} finally {
			unlink( $path );
		}
	}
}
