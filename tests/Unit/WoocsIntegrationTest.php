<?php
namespace OPF\Tests\Unit;

use OPF\Service\WoocsIntegration;
use PHPUnit\Framework\TestCase;

final class WoocsIntegrationTest extends TestCase {

	public function test_active_currency_maps_rate_symbol_format_and_separators(): void {
		$woocs = new WoocsContractStub();
		$woocs->current_currency = 'EUR';
		$woocs->default_currency = 'USD';
		$woocs->currencies = [
			'EUR' => [ 'rate' => 0.9, 'position' => 'right_space', 'symbol' => '€', 'decimals' => 2, 'separators' => '1' ],
		];

		$this->assertSame(
			[
				'currency_rate' => 0.9,
				'display_options' => [ 'symbol' => '€', 'thousand' => '.', 'decimal' => ',', 'decimals' => 2, 'format' => '%2$s&nbsp;%1$s' ],
			],
			WoocsIntegration::frontend_config( $woocs )
		);
	}

	public function test_default_currency_uses_one_and_hide_cents_overrides_decimals(): void {
		$woocs = new WoocsContractStub();
		$woocs->current_currency = 'usd';
		$woocs->default_currency = 'USD';
		$woocs->currencies = [ 'usd' => [ 'rate' => 3.5, 'position' => 'left', 'symbol' => '$', 'decimals' => 2, 'hide_cents' => true ] ];

		$config = WoocsIntegration::frontend_config( $woocs );
		$this->assertSame( 1.0, $config['currency_rate'] );
		$this->assertSame( 0, $config['display_options']['decimals'] );
	}

	public function test_invalid_or_absent_woocs_data_does_not_change_opf_config(): void {
		$this->assertNull( WoocsIntegration::frontend_config( null ) );
		$woocs = new WoocsContractStub();
		$woocs->current_currency = 'EUR';
		$woocs->default_currency = 'USD';
		$woocs->currencies = [ 'EUR' => [ 'rate' => 'not-a-rate', 'symbol' => '€', 'separators' => '5' ] ];

		$config = WoocsIntegration::frontend_config( $woocs );
		$this->assertSame( 1.0, $config['currency_rate'] );
		$this->assertSame( '', $config['display_options']['thousand'] );
		$this->assertSame( ',', $config['display_options']['decimal'] );
	}
}

final class WoocsContractStub {
	public string $current_currency = 'EUR';
	public string $default_currency = 'USD';
	public array $currencies = [];

	public function get_currencies(): array {
		return $this->currencies;
	}
}
