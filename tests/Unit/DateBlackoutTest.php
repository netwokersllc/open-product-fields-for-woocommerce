<?php
/**
 * Disabled dates and weekdays use WAPF's date-range behavior.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class DateBlackoutTest extends TestCase {
	public function test_exact_recurring_and_inclusive_date_ranges_match(): void {
		$this->assertTrue( FieldValue::matches_disabled_date( '2026-12-25', [ '2026-12-25' ] ) );
		$this->assertTrue( FieldValue::matches_disabled_date( '2028-02-29', [ '02-29' ] ) );
		$this->assertTrue( FieldValue::matches_disabled_date( '2026-12-25', [ '2026-12-24 2026-12-26' ] ) );
		$this->assertFalse( FieldValue::matches_disabled_date( '2026-12-27', [ '2026-12-24 2026-12-26' ] ) );
		$this->assertTrue( FieldValue::matches_disabled_date( '2027-01-02', [ '12-24 01-03' ] ) );
		$this->assertFalse( FieldValue::matches_disabled_date( '2026-06-15', [ '12-24 01-03' ] ) );
	}

	public function test_date_rules_are_normalized_and_server_validated(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'delivery-date',
			'type' => 'date',
			'label' => 'Delivery date',
			'disabled_weekdays' => [ '0', 6, 6 ],
			'disabled_dates' => [ '2026-12-24 2026-12-26', '02-29' ],
		] );
		$this->assertSame( [ 0, 6 ], $field['disabled_weekdays'] );
		$this->assertSame( [ '2026-12-24 2026-12-26', '02-29' ], $field['disabled_dates'] );
		$this->assertSame( [ '"Delivery date" is unavailable on this weekday.' ], FieldValue::validate( $field, '2026-06-21', true ) );
		$this->assertSame( [ '"Delivery date" contains a disallowed date.' ], FieldValue::validate( $field, '2026-12-25', true ) );
		$this->assertSame( [ '"Delivery date" contains a disallowed date.' ], FieldValue::validate( $field, '2028-02-29', true ) );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-15', true ) );
	}

	public function test_invalid_blackout_ranges_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'date', 'type' => 'date', 'disabled_dates' => [ '2026-12-31 2026-02-01' ] ] );
	}
}
