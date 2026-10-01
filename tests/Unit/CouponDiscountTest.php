<?php
/** Percentage coupon discount scope calculations. */

use OPF\Service\CartIntegration;
use PHPUnit\Framework\TestCase;

// The shared phpunit executable may have the followersya mirror in its
// Composer classmap; pin this contract test to the isolated public worktree.
require_once dirname( __DIR__, 2 ) . '/includes/Service/CartIntegration.php';

final class CouponDiscountTest extends TestCase {
	public function test_sequential_percent_coupons_apply_to_the_remaining_base_share(): void {
		$first = CartIntegration::base_only_percent_discount( 120.0, 100.0, 120.0, 1, 10.0 );
		$second = CartIntegration::base_only_percent_discount( 110.0, 100.0, 120.0, 1, 10.0 );

		$this->assertSame( 10.0, $first );
		$this->assertSame( 9.0, $second );
		$this->assertSame( 27.0, CartIntegration::base_only_percent_discount( 330.0, 100.0, 120.0, 3, 10.0 ) );
	}

	public function test_tax_inclusive_unit_prices_keep_coupon_discount_on_the_base_share(): void {
		$this->assertSame( 11.0, CartIntegration::base_only_percent_discount( 132.0, 110.0, 132.0, 1, 10.0 ) );
	}

	public function test_usage_limit_caps_eligible_quantity_before_the_base_discount(): void {
		$this->assertSame( 10.0, CartIntegration::base_only_percent_discount( 120.0, 100.0, 120.0, 1, 10.0 ) );
	}

	public function test_discount_does_not_exceed_eligible_amount_and_leaves_rounding_to_woo(): void {
		$this->assertSame( 120.0, CartIntegration::base_only_percent_discount( 120.0, 200.0, 100.0, 1, 100.0 ) );
		$this->assertEqualsWithDelta( 3.337, CartIntegration::base_only_percent_discount( 40.044, 33.37, 40.044, 1, 10.0 ), 0.0000001 );
		$this->assertSame( 10.0, CartIntegration::base_only_percent_discount( 80.0, 100.0, 80.0, 1, 10.0 ) );
	}

	public function test_invalid_inputs_return_no_discount(): void {
		$this->assertSame( 0.0, CartIntegration::base_only_percent_discount( 100.0, 100.0, 0.0, 1, 10.0 ) );
		$this->assertSame( 0.0, CartIntegration::base_only_percent_discount( 100.0, -5.0, 100.0, 1, 10.0 ) );
	}
}
