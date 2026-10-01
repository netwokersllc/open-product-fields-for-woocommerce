<?php
/** Coupon scope setting defaults. */

use OPF\Service\Admin\CouponSettings;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}
if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
	function woocommerce_wp_checkbox( array $args ): void {
		$GLOBALS['opf_coupon_checkbox_args'] = $args;
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/Service/Admin/CouponSettings.php';

final class CouponSettingsTest extends TestCase {
	public function test_coupon_exclusion_defaults_off_when_wapf_meta_is_absent(): void {
		$this->assertFalse( CouponSettings::coupon_excludes_addons( new CouponSettingsTestCoupon( [] ) ) );
	}

	public function test_imported_wapf_coupon_meta_preserves_opt_in(): void {
		$this->assertTrue( CouponSettings::coupon_excludes_addons( new CouponSettingsTestCoupon( [ 'wapf_excl_addons' => 'yes' ] ) ) );
	}

	public function test_native_coupon_checkbox_uses_a_distinct_form_key_and_wapf_meta_value(): void {
		ob_start();
		CouponSettings::render_field( 0, new CouponSettingsTestCoupon( [] ) );
		$html = (string) ob_get_clean();

		$this->assertSame( 'opf_excl_addons', $GLOBALS['opf_coupon_checkbox_args']['id'] );
		$this->assertSame( 'yes', $GLOBALS['opf_coupon_checkbox_args']['cbvalue'] );
		$this->assertSame( 'no', $GLOBALS['opf_coupon_checkbox_args']['value'] );
		$this->assertStringContainsString( 'type.value==="percent"', $html );
	}
}

final class CouponSettingsTestCoupon {
	private $meta;

	public function __construct( array $meta ) {
		$this->meta = $meta;
	}

	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}
}
