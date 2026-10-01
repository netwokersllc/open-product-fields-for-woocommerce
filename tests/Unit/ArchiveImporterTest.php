<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\ArchiveImporter;
use PHPUnit\Framework\TestCase;

final class ArchiveImporterTest extends TestCase {

	public function test_decodes_a_valid_export_package_without_changing_group_data(): void {
		$data = FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'engraving', 'label' => 'Engraving', 'type' => 'text', 'placeholder' => 'Name' ] ],
			'rule_groups' => [],
		] );
		$package = $this->package( [ $this->entry( 14, $data ) ] );

		$decoded = ArchiveImporter::decode( (string) json_encode( $package ) );

		$this->assertSame( $data, $decoded['groups'][0]['data'] );
		$this->assertSame( 'draft', $decoded['groups'][0]['status'] );
		$this->assertSame( [], $decoded['groups'][0]['warnings'] );
	}

	public function test_rejects_unknown_field_data_instead_of_silently_dropping_it(): void {
		$data = FieldGroup::normalize( [ 'fields' => [ [ 'id' => 'finish', 'label' => 'Finish', 'type' => 'text' ] ] ] );
		$data['fields'][0]['unknown_setting'] = 'must survive';

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot preserve' );
		ArchiveImporter::decode( (string) json_encode( $this->package( [ $this->entry( 1, $data ) ] ) ) );
	}

	public function test_rejects_duplicate_source_ids(): void {
		$data = FieldGroup::normalize( [ 'fields' => [] ] );
		$entries = [ $this->entry( 5, $data ), $this->entry( 5, $data ) ];

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate source group ID' );
		ArchiveImporter::decode( (string) json_encode( $this->package( $entries ) ) );
	}

	public function test_rejects_object_shaped_group_collections(): void {
		$data = FieldGroup::normalize( [ 'fields' => [] ] );
		$package = $this->package( [ 'first' => $this->entry( 1, $data ) ] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'at most 500 field groups' );
		ArchiveImporter::decode( (string) json_encode( $package ) );
	}

	public function test_rejects_unsupported_format_version_and_oversized_archives(): void {
		$package = $this->package( [] );
		$package['format_version'] = 2;
		try {
			ArchiveImporter::decode( (string) json_encode( $package ) );
			$this->fail( 'Unsupported versions must fail closed.' );
		} catch ( \InvalidArgumentException $exception ) {
			$this->assertStringContainsString( 'unsupported', $exception->getMessage() );
		}

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '5 MiB' );
		ArchiveImporter::decode( str_repeat( ' ', ArchiveImporter::MAX_BYTES + 1 ) );
	}

	private function package( array $groups ): array {
		return [ 'format' => 'opf-field-groups', 'format_version' => 1, 'scope' => [ 'type' => 'all' ], 'groups' => $groups ];
	}

	private function entry( int $source_id, array $data ): array {
		return [ 'source_id' => $source_id, 'title' => 'Imported group', 'status' => 'draft', 'menu_order' => 0, 'language' => '', 'data' => $data, 'warnings' => [] ];
	}
}
