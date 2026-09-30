<?php
/**
 * WOOCS currency conversion policy tests.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Tests\Unit;

use OPF\Service\Integrations\Woocs;
use PHPUnit\Framework\TestCase;

final class WoocsIntegrationTest extends TestCase {

	public function test_rate_is_one_for_default_currency_and_configured_rate_for_selected_currency(): void {
		$this->assertSame( 1.0, Woocs::rate_from_currencies( 'USD', 'USD', [ 'USD' => [ 'rate' => 1 ], 'EUR' => [ 'rate' => 0.92 ] ] ) );
		$this->assertSame( 0.92, Woocs::rate_from_currencies( 'EUR', 'USD', [ 'USD' => [ 'rate' => 1 ], 'EUR' => [ 'rate' => 0.92 ] ] ) );
	}

	public function test_unknown_or_invalid_current_currency_rate_fails_back_to_one(): void {
		$this->assertSame( 1.0, Woocs::rate_from_currencies( 'CAD', 'USD', [ 'USD' => [ 'rate' => 1 ] ] ) );
		$this->assertSame( 1.0, Woocs::rate_from_currencies( 'EUR', 'USD', [ 'EUR' => [ 'rate' => 'nan' ] ] ) );
		$this->assertSame( 1.0, Woocs::rate_from_currencies( 'EUR', 'USD', [ 'EUR' => [ 'rate' => 0 ] ] ) );
	}

	public function test_display_context_keeps_base_currency_calculation_and_valid_rate(): void {
		$this->assertSame( [ 'base' => 100.0, 'rate' => 2.5 ], Woocs::display_context( 100.0, 2.5 ) );
		$this->assertSame( [ 'base' => 100.0, 'rate' => 1.0 ], Woocs::display_context( 100.0, NAN ) );
	}

	public function test_fixed_currency_prices_are_used_only_when_enabled_for_a_non_default_currency(): void {
		$this->assertTrue( Woocs::has_fixed_price( true, 'EUR', 'USD', '49.00', '' ) );
		$this->assertTrue( Woocs::has_fixed_price( true, 'EUR', 'USD', '', '39.00' ) );
		$this->assertFalse( Woocs::has_fixed_price( false, 'EUR', 'USD', '49.00', '' ) );
		$this->assertFalse( Woocs::has_fixed_price( true, 'USD', 'USD', '49.00', '' ) );
		$this->assertFalse( Woocs::has_fixed_price( true, 'EUR', 'USD', '0', '-1' ) );
	}

	public function test_fixed_currency_price_is_not_converted_twice(): void {
		$this->assertSame( [ 'base' => 49.0, 'rate' => 1.0 ], Woocs::selected_price_context( 100.0, 49.0, 0.92, true ) );
		$this->assertSame( [ 'base' => 100.0, 'rate' => 0.92 ], Woocs::selected_price_context( 100.0, 49.0, 0.92, false ) );
	}
}
