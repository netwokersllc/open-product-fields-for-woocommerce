<?php
/**
 * Submitted-value normalization and validation tests.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class FieldValueTest extends TestCase {

	public function test_email_matches_native_single_address_grammar_and_rejects_forged_shapes(): void {
		$field = [ 'type' => 'email', 'label' => 'Contact email', 'required' => false ];
		foreach ( [ 'a@localhost', 'a..b@example.test', '.a@example.test', 'a.@example.test', 'a+b@example.test', 'a&b@example.test', 'a@xn--bcher-kva.test' ] as $email ) {
			$this->assertSame( [], FieldValue::validate( $field, $email, true ), $email );
		}
		foreach ( [ '"a"@example.test', 'a@-example.test', 'a@example-.test', 'a@ex_ample.test', 'a@' . str_repeat( 'b', 64 ) . '.test', 'a<b>@example.test', "a\0@example.test", 'a@example.test,b@example.test', 'a@bücher.test' ] as $email ) {
			$this->assertNotEmpty( FieldValue::validate( $field, $email, true ), $email );
		}
		foreach ( [ [], [ 'a@example.test' ], (object) [ 'email' => 'a@example.test' ] ] as $payload ) {
			$this->assertNotEmpty( FieldValue::validate( $field, FieldValue::sanitize( $field, $payload ), true ) );
		}
		$this->assertNotEmpty( FieldValue::validate( $field, FieldValue::sanitize( $field, "a@example.test\0" ), true ) );
	}

	public function test_email_keeps_a_nonempty_value_for_server_side_format_validation(): void {
		$field = [ 'type' => 'email', 'label' => 'Contact email', 'required' => false ];

		$this->assertSame( 'not-an-email', FieldValue::sanitize( $field, ' not-an-email ' ) );
		$this->assertSame( [ '"Contact email" must be a valid email address.' ], FieldValue::validate( $field, 'not-an-email', true ) );
	}

	public function test_email_accepts_valid_nonempty_value_and_allows_an_empty_optional_value(): void {
		$field = [ 'type' => 'email', 'label' => 'Contact email', 'required' => false ];

		$this->assertSame( 'ada@example.test', FieldValue::sanitize( $field, ' ada@example.test ' ) );
		$this->assertSame( [], FieldValue::validate( $field, 'ada@example.test', true ) );
		$this->assertNull( FieldValue::sanitize( $field, '   ' ) );
		$this->assertSame( [], FieldValue::validate( $field, null, false ) );
	}

	public function test_date_accepts_only_real_iso_calendar_dates(): void {
		$field = [ 'type' => 'date', 'label' => 'Delivery date', 'required' => false ];

		$this->assertSame( '2026-09-30', FieldValue::sanitize( $field, ' 2026-09-30 ' ) );
		$this->assertSame( [], FieldValue::validate( $field, '2026-09-30', true ) );
		$this->assertSame( [ '"Delivery date" must be a valid date.' ], FieldValue::validate( $field, '2026-02-30', true ) );
		$this->assertSame( [ '"Delivery date" must be a valid date.' ], FieldValue::validate( $field, '30-09-2026', true ) );
	}

	public function test_toggle_normalizes_checked_and_unchecked_values_and_required_means_checked(): void {
		$field = [ 'type' => 'toggle', 'label' => 'Gift wrap', 'required' => true ];

		$this->assertSame( '1', FieldValue::sanitize( $field, 'on' ) );
		$this->assertSame( '1', FieldValue::sanitize( $field, true ) );
		$this->assertSame( '0', FieldValue::sanitize( $field, '0' ) );
		$this->assertSame( '0', FieldValue::sanitize( $field, 'anything-else' ) );
		$this->assertSame( [], FieldValue::validate( $field, '1', true ) );
		$this->assertSame( [ '"Gift wrap" is a required field.' ], FieldValue::validate( $field, '0', true ) );
	}

	public function test_disabled_choices_are_rejected_for_single_and_multiple_fields(): void {
		$single = [
			'type' => 'select', 'label' => 'Finish', 'choices' => [
				[ 'slug' => 'oak', 'label' => 'Oak', 'disabled' => true ],
				[ 'slug' => 'ash', 'label' => 'Ash', 'disabled' => false ],
			],
		];
		$multiple = [
			'type' => 'checkbox', 'label' => 'Extras', 'choices' => [
				[ 'slug' => 'rush', 'label' => 'Rush service', 'disabled' => true ],
				[ 'slug' => 'gift', 'label' => 'Gift wrap', 'disabled' => false ],
			],
		];

		$this->assertSame( [ '"Finish" includes unavailable choice "Oak".' ], FieldValue::validate_choices( $single, 'oak' ) );
		$this->assertSame( [], FieldValue::validate_choices( $single, 'ash' ) );
		$this->assertSame( [ '"Extras" includes unavailable choice "Rush service".' ], FieldValue::validate_choices( $multiple, [ 'gift', 'rush' ] ) );
	}
}
