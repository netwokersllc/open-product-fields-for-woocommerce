<?php
/**
 * WAPF-compatible date output format tests.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\DateFormat;
use PHPUnit\Framework\TestCase;

final class DateFormatTest extends TestCase {
	public function test_supported_formats_require_one_year_month_and_day(): void {
		$this->assertTrue( DateFormat::is_valid( 'mm-dd-yyyy' ) );
		$this->assertTrue( DateFormat::is_valid( 'd/m/yy' ) );
		$this->assertTrue( DateFormat::is_valid( 'yyyy.mm.dd' ) );
		$this->assertFalse( DateFormat::is_valid( 'mm-mm-yyyy' ) );
		$this->assertFalse( DateFormat::is_valid( 'yyyy-mm' ) );
		$this->assertFalse( DateFormat::is_valid( 'yyyy-mm-dd extra' ) );
	}

	public function test_iso_dates_format_without_changing_canonical_storage(): void {
		$this->assertSame( '06-15-2026', DateFormat::format( '2026-06-15', 'mm-dd-yyyy' ) );
		$this->assertSame( '15/6/26', DateFormat::format( '2026-06-15', 'd/m/yy' ) );
		$this->assertSame( '2026.06.15', DateFormat::format( '2026-06-15', 'yyyy.mm.dd' ) );
		$this->assertSame( '', DateFormat::format( '2026-02-30', 'd/m/yy' ) );
		$stored_value = '2026-06-15';
		DateFormat::format( $stored_value, 'd/m/yy' );
		$this->assertSame( '2026-06-15', $stored_value );
	}

	public function test_invalid_formats_fall_back_to_the_default(): void {
		$this->assertSame( DateFormat::DEFAULT_FORMAT, DateFormat::normalize( 'mm-mm-yyyy' ) );
		$this->assertSame( DateFormat::DEFAULT_FORMAT, DateFormat::normalize( null ) );
	}
}
