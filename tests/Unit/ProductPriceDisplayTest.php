<?php

namespace {
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ): string {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = null ): string {
			return (string) $text;
		}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Service\ProductPriceDisplay;
	use PHPUnit\Framework\TestCase;

	final class ProductPriceDisplayTest extends TestCase {
		public function test_wapf_meta_keys_are_reused_for_migrated_products(): void {
			$this->assertSame( '_wapf_price_display', ProductPriceDisplay::META_DISPLAY );
			$this->assertSame( '_wapf_price_label', ProductPriceDisplay::META_LABEL );
		}

		public function test_modes_cover_all_five_wapf_values(): void {
			$this->assertSame( [ '', 'hide', 'before', 'after', 'replace' ], array_keys( ProductPriceDisplay::modes() ) );
		}

		public function test_default_and_unknown_modes_keep_native_price(): void {
			$html = '<span class="price">$10.00</span>';
			$this->assertSame( $html, ProductPriceDisplay::format( $html, '', 'Label' ) );
			$this->assertSame( $html, ProductPriceDisplay::format( $html, 'unexpected', 'Label' ) );
		}

		public function test_hide_mode_returns_empty_price(): void {
			$this->assertSame( '', ProductPriceDisplay::format( '<span class="price">$10.00</span>', 'hide', 'Label' ) );
		}

		public function test_replace_mode_shows_only_the_label(): void {
			$this->assertSame(
				'<span class="wapf-price-replace">Ask for a quote</span>',
				ProductPriceDisplay::format( '<span class="price">$10.00</span>', 'replace', 'Ask for a quote' )
			);
		}

		public function test_before_and_after_wrap_the_native_price_and_escape_the_label(): void {
			$html = '<span class="price">$10.00</span>';
			$this->assertSame(
				'<span class="wapf-price-before">From &lt;b&gt;</span> <span class="price">$10.00</span>',
				ProductPriceDisplay::format( $html, 'before', 'From <b>' )
			);
			$this->assertSame(
				'<span class="price">$10.00</span> <span class="wapf-price-after">each</span>',
				ProductPriceDisplay::format( $html, 'after', 'each' )
			);
		}

		public function test_variable_products_are_never_touched_by_the_filter(): void {
			$html = '$10.00 &ndash; $20.00';
			// \WC_Product is unavailable in the pure-PHP suite, so the guard
			// also exercises the non-product early return.
			$this->assertSame( $html, ProductPriceDisplay::filter_price_html( $html, new \stdClass() ) );
		}
	}
}
