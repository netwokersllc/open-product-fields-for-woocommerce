<?php
/**
 * Repeater value sanitation and validation tests.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\RepeaterField;
use PHPUnit\Framework\TestCase;

final class RepeaterFieldTest extends TestCase {

	public function test_sanitize_preserves_row_order_and_empty_rows(): void {
		$field = [ 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ] ];

		$this->assertSame(
			[ 'Second', null, 'First' ],
			RepeaterField::sanitize( $field, [ '2' => ' Second ', '5' => '', '9' => 'First' ], static fn( $row ) => '' === trim( (string) $row ) ? null : trim( (string) $row ) )
		);
	}

	public function test_validate_requires_each_nonempty_row(): void {
		$field = [
			'label' => 'Name',
			'type' => 'text',
			'required' => true,
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ],
		];

		$this->assertSame( [ '"Name" is required in repeated row 2.' ], RepeaterField::validate( $field, [ 'Ada', null ], true ) );
	}

	public function test_validate_enforces_button_row_maximum(): void {
		$field = [
			'label' => 'Guest',
			'type' => 'text',
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 2 ],
		];

		$this->assertSame( [ '"Guest" allows at most 2 repeated rows.' ], RepeaterField::validate( $field, [ 'Ada', 'Grace', 'Linus' ], true ) );
	}

	public function test_validate_checks_each_email_instance(): void {
		$field = [
			'label' => 'Email',
			'type' => 'email',
			'required' => false,
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ],
		];

		$this->assertSame( [ '"Email" must be a valid email address in repeated row 2.' ], RepeaterField::validate( $field, [ 'ada@example.com', 'not-an-email' ], true ) );
	}

	public function test_validate_fails_closed_for_quantity_mode_until_product_quantity_is_checked(): void {
		$field = [
			'label' => 'Name',
			'type' => 'text',
			'required' => true,
			'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ],
		];

		$this->assertSame( [ '"Name" uses quantity-based repeated rows that are not available yet.' ], RepeaterField::validate( $field, [ 'Ada' ], true ) );
	}
}
