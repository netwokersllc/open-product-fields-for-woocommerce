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

	public function test_quantity_sanitize_preserves_sparse_row_indexes(): void {
		$field = [ 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ];

		$this->assertSame(
			[ 1 => 'Grace', 3 => null ],
			RepeaterField::sanitize( $field, [ 1 => ' Grace ', 3 => '' ], static fn( $row ) => '' === trim( (string) $row ) ? null : trim( (string) $row ) )
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

	public function test_quantity_validation_matches_product_quantity_and_checks_sparse_required_rows(): void {
		$field = [
			'label' => 'Name',
			'type' => 'text',
			'required' => true,
			'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ],
		];

		$this->assertSame( [], RepeaterField::validate( $field, [ 'Ada', 'Grace' ], true, 2 ) );
		$this->assertSame( [ '"Name" must have exactly 2 rows to match product quantity.' ], RepeaterField::validate( $field, [ 'Ada' ], true, 2 ) );
		$this->assertSame( [ '"Name" is required in repeated row 1.' ], RepeaterField::validate( $field, [ 1 => 'Grace' ], true, 2 ) );
	}
}
