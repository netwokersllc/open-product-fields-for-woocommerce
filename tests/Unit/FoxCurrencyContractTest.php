<?php
namespace OPF\Tests\Unit;

use OPF\Service\WoocsIntegration;
use PHPUnit\Framework\TestCase;

/** FOX retains the documented WOOCS global and API; see the compatibility evidence doc. */
final class FoxCurrencyContractTest extends TestCase {

	private FoxWoocsApiFake $fox;
	private array $previous_options;
	private bool $had_woocs;
	private $previous_woocs;

	protected function setUp(): void {
		$this->previous_options = $GLOBALS['opf_test_options'] ?? [];
		$this->had_woocs = array_key_exists( 'WOOCS', $GLOBALS );
		$this->previous_woocs = $GLOBALS['WOOCS'] ?? null;
		$this->fox = new FoxWoocsApiFake();
		$GLOBALS['WOOCS'] = $this->fox;
		$GLOBALS['opf_test_options'] = [ 'woocs_is_multiple_allowed' => 1 ];
	}

	protected function tearDown(): void {
		if ( $this->had_woocs ) {
			$GLOBALS['WOOCS'] = $this->previous_woocs;
		} else {
			unset( $GLOBALS['WOOCS'] );
		}
		$GLOBALS['opf_test_options'] = $this->previous_options;
	}

	public function test_fox_documented_currency_table_uses_existing_global_bridge(): void {
		$this->assertSame(
			[
				'currency_rate' => 0.89,
				'display_options' => [ 'symbol' => '&euro;', 'thousand' => ',', 'decimal' => '.', 'decimals' => 2, 'format' => '%1$s&nbsp;%2$s' ],
			],
			WoocsIntegration::frontend_config()
		);
		// FOX documents false as the default: currency-data filters remain enabled.
		$this->assertSame( [ false ], $this->fox->currency_calls );
	}

	public function test_fox_back_conversion_passes_active_rate_and_wapf_precision(): void {
		$this->assertSame( 10.0, WoocsIntegration::back_convert( 8.9 ) );
		$this->assertSame( [ [ 8.9, 0.89, 8 ] ], $this->fox->conversion_calls );
	}

	public function test_fox_default_currency_has_unit_rate_without_back_conversion(): void {
		$this->fox->current_currency = 'USD';
		$this->assertSame( 1.0, WoocsIntegration::frontend_config()['currency_rate'] );
		$this->assertSame( 8.9, WoocsIntegration::back_convert( 8.9 ) );
		$this->assertSame( [], $this->fox->conversion_calls );
	}

	public function test_fox_multiple_currency_gate_preserves_already_base_amount(): void {
		$GLOBALS['opf_test_options']['woocs_is_multiple_allowed'] = 0;
		$this->assertSame( 8.9, WoocsIntegration::back_convert( 8.9 ) );
		$this->assertSame( [], $this->fox->conversion_calls );
	}
}

/** Shape from FOX's official current/default/get_currencies/back_convert references. */
final class FoxWoocsApiFake {
	public string $current_currency = 'EUR';
	public string $default_currency = 'USD';
	public array $currency_calls = [];
	public array $conversion_calls = [];

	public function get_currencies( bool $suppress_filters = false ): array {
		$this->currency_calls[] = $suppress_filters;
		return [
			'USD' => [ 'name' => 'USD', 'rate' => 1, 'symbol' => '&#36;', 'position' => 'right', 'is_etalon' => 1, 'description' => 'USA dollar', 'hide_cents' => 0, 'flag' => '' ],
			'EUR' => [ 'name' => 'EUR', 'rate' => 0.89, 'symbol' => '&euro;', 'position' => 'left_space', 'is_etalon' => 0, 'description' => 'Euro', 'hide_cents' => 0, 'flag' => '' ],
		];
	}

	public function back_convert( float $amount, float $rate, int $decimals ): float {
		$this->conversion_calls[] = [ $amount, $rate, $decimals ];
		return round( $amount / $rate, $decimals );
	}
}
