<?php
/**
 * Past and future date-selection policy tests.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class DateRangePolicyTest extends TestCase {
	public function test_date_policy_defaults_to_allowing_past_and_future(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'event-date', 'type' => 'date', 'label' => 'Event date' ] );

		$this->assertTrue( $field['allow_past'] );
		$this->assertTrue( $field['allow_future'] );
	}

	public function test_past_and_future_toggles_reject_only_dates_on_the_disallowed_side(): void {
		$today = new \DateTimeImmutable( '2026-09-30 12:00:00', new \DateTimeZone( 'UTC' ) );
		$dates = [ 'past' => '2026-09-29', 'today' => '2026-09-30', 'future' => '2026-10-01' ];

		foreach ( [ [ false, true ], [ true, false ], [ false, false ] ] as [ $allow_past, $allow_future ] ) {
			$field = FieldGroup::normalize_field( [
				'id' => 'event-date',
				'type' => 'date',
				'label' => 'Event date',
				'allow_past' => $allow_past,
				'allow_future' => $allow_future,
			] );
			foreach ( $dates as $kind => $date ) {
				$blocked = ( 'past' === $kind && ! $allow_past ) || ( 'future' === $kind && ! $allow_future );
				$expected = $blocked ? [ sprintf( '"Event date" cannot be in the %s.', $kind ) ] : [];
				$this->assertSame( $expected, FieldValue::validate( $field, $date, true, $today ) );
			}
		}
	}

	public function test_date_policy_requires_boolean_values(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'event-date', 'type' => 'date', 'allow_past' => 'sometimes' ] );
	}
}
