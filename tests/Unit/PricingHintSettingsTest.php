<?php
/** Pricing-hint settings validation. */

namespace OPF\Tests\Unit;

use OPF\Service\Admin\Settings;
use PHPUnit\Framework\TestCase;

final class PricingHintSettingsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['opf_test_options'] = [];
	}

	public function test_accepts_one_amount_placeholder_and_strips_markup(): void {
		$this->assertSame( '(Add {x})', Settings::sanitize_pricing_hint_format( '<b>(Add {x})</b>' ) );
	}

	public function test_invalid_format_keeps_previous_valid_value_or_uses_default(): void {
		$this->assertSame( '(+{x})', Settings::sanitize_pricing_hint_format( 'invalid' ) );
		$GLOBALS['opf_test_options']['opf_pricing_hint_format'] = '[{x}]';
		$this->assertSame( '[{x}]', Settings::sanitize_pricing_hint_format( '{x} {x}' ) );
		$GLOBALS['opf_test_options']['opf_pricing_hint_format'] = 'invalid';
		$this->assertSame( '(+{x})', Settings::sanitize_pricing_hint_format( null ) );
	}
}
