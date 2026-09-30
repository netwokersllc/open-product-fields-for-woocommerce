<?php

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

	public function test_formats_iso_dates_with_the_published_placeholders(): void {
		$this->assertSame( '06-15-2026', DateFormat::format( '2026-06-15', 'mm-dd-yyyy' ) );
		$this->assertSame( '6/15/26', DateFormat::format( '2026-06-15', 'm/d/yy' ) );
		$this->assertSame( '2026.6.15', DateFormat::format( '2026-06-15', 'yyyy.m.d' ) );
		$this->assertSame( '15/6/26', DateFormat::format( '2026-06-15', 'd/m/yy' ) );
		$this->assertSame( '2026.06.15', DateFormat::format( '2026-06-15', 'yyyy.mm.dd' ) );
		$stored_value = '2026-06-15';
		DateFormat::format( $stored_value, 'd/m/yy' );
		$this->assertSame( '2026-06-15', $stored_value );
	}

	public function test_invalid_format_uses_safe_default_and_malformed_iso_date_fails_closed(): void {
		$this->assertSame( '06-15-2026', DateFormat::format( '2026-06-15', 'yyyy-mm' ) );
		$this->assertSame( '', DateFormat::format( '2026-02-30', 'mm-dd-yyyy' ) );
		$this->assertSame( 'mm-dd-yyyy', DateFormat::normalize( 'DD/MM' ) );
		$this->assertSame( DateFormat::DEFAULT_FORMAT, DateFormat::normalize( null ) );
	}
}
