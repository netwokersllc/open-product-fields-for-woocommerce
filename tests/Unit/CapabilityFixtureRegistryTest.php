<?php
/**
 * Capability fixture registry tests.
 */

namespace OPF\Tests\Unit;

use InvalidArgumentException;
use OPF\Engine\CapabilityFixtureRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityFixtureRegistryTest extends TestCase {

	public function test_registry_seeds_the_current_text_and_priced_swatch_capabilities(): void {
		$fixtures = CapabilityFixtureRegistry::all();

		$this->assertArrayHasKey( 'WAPF-FIELD-TEXT', $fixtures );
		$this->assertArrayHasKey( 'WAPF-FIELD-SWATCH-TEXT', $fixtures );
		$this->assertSame( 'Text field lifecycle', $fixtures['WAPF-FIELD-TEXT']['title'] );
		$this->assertSame( 3.0, $fixtures['WAPF-FIELD-SWATCH-TEXT']['expected_result']['addon_per_unit'] );
	}

	public function test_registry_fixture_has_the_required_declarative_contract(): void {
		$fixture = CapabilityFixtureRegistry::all()['WAPF-FIELD-SWATCH-TEXT'];
		$validated = CapabilityFixtureRegistry::validate( $fixture );

		$this->assertSame( $fixture, $validated );
		$this->assertContains( 'server_pricing', $validated['supported_flows'] );
		$this->assertSame( 'swatch', $validated['expected_normalization']['fields'][0]['type'] );
	}

	/**
	 * @dataProvider incompleteFixtureProvider
	 *
	 * @param array<string,mixed> $fixture Fixture missing a required contract member.
	 */
	public function test_validator_rejects_a_fixture_missing_a_required_contract_member( array $fixture, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		CapabilityFixtureRegistry::validate( $fixture );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function incompleteFixtureProvider(): array {
		$valid = CapabilityFixtureRegistry::all()['WAPF-FIELD-TEXT'];

		$without_id = $valid;
		unset( $without_id['ledger_id'] );
		$without_title = $valid;
		unset( $without_title['title'] );
		$without_group = $valid;
		unset( $without_group['field_group'] );
		$without_normalization = $valid;
		unset( $without_normalization['expected_normalization'] );
		$without_result = $valid;
		unset( $without_result['expected_result'] );
		$without_flows = $valid;
		unset( $without_flows['supported_flows'] );

		return [
			'ledger id' => [ $without_id, 'ledger_id' ],
			'title' => [ $without_title, 'title' ],
			'field group data' => [ $without_group, 'field_group' ],
			'expected normalization' => [ $without_normalization, 'expected_normalization' ],
			'expected result' => [ $without_result, 'expected_result' ],
			'supported flows' => [ $without_flows, 'supported_flows' ],
		];
	}

	public function test_validator_rejects_an_unknown_flow_and_a_stale_normalization(): void {
		$unknown_flow = CapabilityFixtureRegistry::all()['WAPF-FIELD-TEXT'];
		$unknown_flow['supported_flows'][] = 'made_up_flow';
		try {
			CapabilityFixtureRegistry::validate( $unknown_flow );
			$this->fail( 'An unknown flow must fail validation.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertStringContainsString( 'supported_flows', $exception->getMessage() );
		}

		$stale = CapabilityFixtureRegistry::all()['WAPF-FIELD-TEXT'];
		$stale['expected_normalization']['fields'][0]['label'] = 'Wrong';
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'expected_normalization' );
		CapabilityFixtureRegistry::validate( $stale );
	}
}
