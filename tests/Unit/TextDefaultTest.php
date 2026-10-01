<?php
namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class TextDefaultTest extends TestCase {
	public function test_default_preserves_zero_and_single_line_unicode(): void {
		$this->assertSame( '0', FieldGroup::normalize_field( [ 'type' => 'text', 'default' => 0 ] )['default'] );
		$this->assertSame( 'Hola Ada — 你好', FieldGroup::normalize_field( [ 'type' => 'text', 'default' => "  Hola <b>Ada</b>\n— 你好  " ] )['default'] );
	}

	public function test_missing_default_does_not_change_legacy_shape(): void {
		$this->assertArrayNotHasKey( 'default', FieldGroup::normalize_field( [ 'type' => 'text' ] ) );
	}

	public function test_complex_default_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'type' => 'text', 'default' => [ 'bad' ] ] );
	}
}
