<?php
/** Public PHP API normalization contracts. */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class PublicApiTest extends TestCase {

	public function test_field_group_array_helpers_use_the_canonical_normalized_shape(): void {
		$group = opf_array_to_fieldgroup(
			[
				'fields' => [
					[ 'id' => 'name', 'type' => 'text', 'label' => 'Name', 'pricing' => [ 'type' => 'none' ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertInstanceOf( FieldGroup::class, $group );
		$this->assertSame( FieldGroup::SCHEMA, opf_fieldgroup_to_array( $group )['schema'] );
		$this->assertSame( 'name', opf_fieldgroup_to_array( $group )['fields'][0]['id'] );
		$this->assertSame( 'none', opf_fieldgroup_to_array( $group )['fields'][0]['pricing']['type'] );
	}

	public function test_setting_helpers_expose_supported_defaults_and_fallbacks(): void {
		$this->assertTrue( opf_has_setting( 'opf_date_format' ) );
		$this->assertTrue( opf_has_setting( 'version' ) );
		$this->assertSame( OPF_VERSION, opf_get_setting( 'version' ) );
		$this->assertSame( [ 'opf_field_group' ], opf_get_setting( 'cpts' ) );
		$this->assertFalse( opf_has_setting( 'unknown_setting' ) );
		$this->assertSame( 'no', opf_get_setting( 'opf_show_totals' ) );
		$this->assertSame( 'fallback', opf_get_setting( 'unknown_setting', 'fallback' ) );

		$GLOBALS['opf_test_options']['opf_show_totals'] = 'yes';
		try {
			$this->assertSame( 'yes', opf_get_setting( 'opf_show_totals' ) );
		} finally {
			unset( $GLOBALS['opf_test_options']['opf_show_totals'] );
		}
	}

	public function test_cart_and_order_helpers_fail_closed_when_woocommerce_is_unavailable(): void {
		$this->assertSame( [], opf_get_custom_fields_in_cart() );
		$this->assertSame( [], opf_get_options_from_order( 12345 ) );
		$this->assertSame( [], opf_get_options_from_order( new \stdClass() ) );
	}
}
