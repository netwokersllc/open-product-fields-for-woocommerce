<?php
namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Uploads;
use PHPUnit\Framework\TestCase;

final class UploadFieldTest extends TestCase {
	public function test_single_upload_defaults_and_php_multiple_limit(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload' ] );
		self::assertSame( 'upload', $field['type'] );
		self::assertFalse( $field['multiple'] );
		self::assertSame( 1.0, $field['max_size'] );
		self::assertSame( [], $field['accepted_types'] );
		self::assertSame( 1, Uploads::max_files( $field ) );
		$field['multiple'] = true;
		self::assertSame( (int) ini_get( 'max_file_uploads' ), Uploads::max_files( $field ) );
	}

	public function test_wapf_extension_groups_and_decimal_size_survive_normalization(): void {
		$field = FieldGroup::normalize_field( [ 'type' => 'upload', 'multiple' => true, 'max_size' => '0.5', 'accepted_types' => 'jpg|jpeg|jpe,png' ] );
		self::assertSame( [ 'jpg', 'jpeg', 'jpe', 'png' ], $field['accepted_types'] );
		self::assertSame( .5, $field['max_size'] );
		self::assertTrue( $field['multiple'] );
	}

	public function test_malformed_optional_tokens_remain_invalid(): void {
		self::assertSame( [ 'invalid' ], Uploads::tokens( [ [ 'path' => '/tmp/file' ] ] ) );
		self::assertSame( [ 'invalid' ], Uploads::tokens( '../../etc/passwd' ) );
		self::assertSame( [ 'invalid' ], Uploads::tokens( new \stdClass() ) );
		self::assertSame( [], Uploads::tokens( '' ) );
		$token = str_repeat( 'a', 64 );
		self::assertSame( [ $token ], Uploads::tokens( [ $token, $token ] ) );
	}

	public function test_upload_repetition_is_explicitly_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'type' => 'upload', 'repeat' => [ 'enabled' => true, 'mode' => 'button' ] ] );
	}

	public function test_active_path_fragments_cannot_be_extensions(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'type' => 'upload', 'accepted_types' => [ '../php' ] ] );
	}

	public function test_uploads_inside_repeated_sections_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new FieldGroup( [ 'fields' => [
			[ 'id' => 'section', 'type' => 'section', 'repeat' => [ 'enabled' => true, 'mode' => 'button' ] ],
			[ 'id' => 'art', 'type' => 'upload' ],
		] ] );
	}

	public function test_priced_uploads_cannot_create_inconsistent_totals(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'type' => 'upload', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ] );
	}

	public function test_unknown_upload_price_types_cannot_silently_become_free(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'type' => 'upload', 'pricing' => [ 'type' => 'per_file', 'amount' => 5 ] ] );
	}

	public function test_malformed_upload_pricing_cannot_silently_become_free(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'type' => 'upload', 'pricing' => 'fixed' ] );
	}

	public function test_malformed_upload_price_type_is_rejected_before_casting(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'type' => 'upload', 'pricing' => [ 'type' => [] ] ] );
	}

	public function test_nested_section_cannot_hide_inherited_upload_repetition(): void {
		$this->expectException( \InvalidArgumentException::class );
		new FieldGroup( [ 'fields' => [
			[ 'id' => 'outer', 'type' => 'section', 'repeat' => [ 'enabled' => true, 'mode' => 'button' ] ],
			[ 'id' => 'inner', 'type' => 'section' ],
			[ 'id' => 'art', 'type' => 'upload' ],
		] ] );
	}
}
