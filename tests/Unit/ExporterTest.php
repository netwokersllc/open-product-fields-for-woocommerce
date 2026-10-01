<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\ArchiveImporter;
use OPF\Service\Exporter;
use PHPUnit\Framework\TestCase;

final class ExporterTest extends TestCase {

	public function test_exported_group_data_survives_archive_validation(): void {
		$data = FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'engraving', 'label' => 'Engraving', 'type' => 'text', 'placeholder' => 'Name' ] ],
			'rule_groups' => [],
		] );
		$package = Exporter::build_package( [ [
			'id' => 44,
			'title' => 'Custom fields',
			'status' => 'publish',
			'menu_order' => 2,
			'language' => 'en',
			'data' => $data,
		] ], [ 'type' => 'group', 'id' => 44 ] );

		$decoded = ArchiveImporter::decode( (string) json_encode( $package ) );

		$this->assertSame( 'opf-field-groups', $decoded['format'] );
		$this->assertSame( $data, $decoded['groups'][0]['data'] );
		$this->assertSame( 'publish', $decoded['groups'][0]['status'] );
		$this->assertSame( 'en', $decoded['groups'][0]['language'] );
	}

	public function test_export_marks_site_local_targets_and_media_as_portability_warnings(): void {
		$package = Exporter::build_package( [ [
			'id' => 45,
			'data' => [
				'fields' => [ [ 'choices' => [ [ 'image_url' => 'https://example.test/image.png', 'image_id' => 481 ] ] ] ],
				'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product_cat', 'terms' => [ '8' ] ] ] ] ],
			],
		] ], [ 'type' => 'all' ] );

		$this->assertContains( 'media_files_not_included', $package['groups'][0]['warnings'] );
		$this->assertContains( 'product_target_ids_may_not_match', $package['groups'][0]['warnings'] );
		$this->assertSame( 2, count( $package['warnings'] ) );
	}
}
