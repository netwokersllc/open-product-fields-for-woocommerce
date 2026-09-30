<?php
/**
 * WAPF compatible static and relative date boundary tests.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class DateBoundaryTest extends TestCase {
	public function test_wapf_periods_support_signed_year_month_and_day_components(): void {
		$today = new \DateTimeImmutable( '2026-06-15', new \DateTimeZone( 'UTC' ) );

		$this->assertTrue( FieldValue::is_date_boundary( '1y 9m 3d' ) );
		$this->assertSame( '2028-03-18', FieldValue::resolve_date_boundary( '1y 9m 3d', $today ) );
		$this->assertSame( '2026-05-08', FieldValue::resolve_date_boundary( '-1m -7d', $today ) );
		$this->assertFalse( FieldValue::is_date_boundary( '7d tomorrow' ) );
	}

	public function test_static_and_relative_boundaries_are_preserved_and_enforced(): void {
		$relative = FieldGroup::normalize_field( [
			'id' => 'delivery-date',
			'type' => 'date',
			'label' => 'Delivery date',
			'min_date' => '7d',
			'max_date' => '1y 2m',
		] );
		$today = new \DateTimeImmutable( '2026-06-15', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( '7d', $relative['min_date'] );
		$this->assertSame( '1y 2m', $relative['max_date'] );
		$this->assertSame( [], FieldValue::validate( $relative, '2026-06-22', true, $today ) );
		$this->assertSame( [ '"Delivery date" must be on or after 2026-06-22.' ], FieldValue::validate( $relative, '2026-06-21', true, $today ) );

		$static = FieldGroup::normalize_field( [ 'id' => 'event-date', 'type' => 'date', 'min_date' => '2026-01-01', 'max_date' => '2026-12-31' ] );
		$this->assertSame( [ '"" must be on or before 2026-12-31.' ], FieldValue::validate( $static, '2027-01-01', true, $today ) );
	}

	public function test_date_bounds_must_be_ordered(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'delivery-date', 'type' => 'date', 'min_date' => '30d', 'max_date' => '7d' ] );
	}
}
