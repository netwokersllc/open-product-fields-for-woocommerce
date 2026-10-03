<?php

namespace {
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public function get_parent_id(): int { return 0; }
			public function get_id(): int { return (int) ( $GLOBALS['opf_hide_test_product_id'] ?? 42 ); }
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value, ...$args ) {
			return 'opf_groups_for_product' === $tag ? ( $GLOBALS['opf_hide_test_groups'] ?? $value ) : $value;
		}
	}
	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( $args = [] ): array { return []; }
	}
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in(): bool { return false; }
	}
	if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
		function wc_get_product_term_ids( $product_id, $taxonomy ): array { return []; }
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		function wc_get_product( $product_id ) { return $GLOBALS['opf_hide_test_product'] ?? null; }
	}
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = null ): string { return (string) $text; }
	}
	if ( ! function_exists( 'is_cart' ) ) {
		function is_cart(): bool { return (bool) ( $GLOBALS['opf_hide_test_is_cart'] ?? false ); }
	}
	if ( ! function_exists( 'is_checkout' ) ) {
		function is_checkout(): bool { return (bool) ( $GLOBALS['opf_hide_test_is_checkout'] ?? false ); }
	}
	if ( ! function_exists( 'wp_doing_ajax' ) ) {
		function wp_doing_ajax(): bool { return defined( 'DOING_AJAX' ) && DOING_AJAX; }
	}
	if ( ! function_exists( 'wp_get_referer' ) ) {
		function wp_get_referer() { return $GLOBALS['opf_hide_test_referer'] ?? false; }
	}
	if ( ! function_exists( 'url_to_postid' ) ) {
		function url_to_postid( $url ): int { return (int) ( $GLOBALS['opf_hide_test_referer_id'] ?? 0 ); }
	}
	if ( ! function_exists( 'wc_get_page_id' ) ) {
		function wc_get_page_id( $page ): int {
			return [ 'cart' => 6, 'checkout' => 7 ][ $page ] ?? 0;
		}
	}
	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $key, $group = '' ) { return false; }
	}
	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $key, $data, $group = '', $expire = 0 ) { return true; }
	}
	if ( ! function_exists( 'wp_cache_delete' ) ) {
		function wp_cache_delete( $key, $group = '' ) { return true; }
	}
}

namespace OPF\Tests\Unit {
	use OPF\Engine\FieldGroup;
	use OPF\Service\CartIntegration;
	use OPF\Service\FieldGroups;
	use PHPUnit\Framework\TestCase;

	/**
	 * hide_cart / hide_checkout / hide_order suppress only the customer-facing
	 * display on the matching surface; stored values and pricing are intact.
	 * Context detection mirrors WAPF Extended 3.1.5
	 * (class-product-controller.php::display_fields_on_cart_and_checkout).
	 */
	final class CartIntegrationHideValuesTest extends TestCase {
		protected function setUp(): void {
			FieldGroups::flush_cache();
			$GLOBALS['opf_hide_test_is_cart'] = false;
			$GLOBALS['opf_hide_test_is_checkout'] = false;
			$GLOBALS['opf_hide_test_referer'] = false;
			$GLOBALS['opf_hide_test_referer_id'] = 0;
			unset( $GLOBALS['wp'] );

			$group = new FieldGroup( [
				'fields' => [
					[ 'id' => 'shown', 'type' => 'text', 'label' => 'Shown' ],
					[ 'id' => 'internal', 'type' => 'text', 'label' => 'Internal note',
						'hide_cart' => true, 'hide_checkout' => true, 'hide_order' => true ],
					[ 'id' => 'checkout_only', 'type' => 'text', 'label' => 'Checkout only', 'hide_checkout' => true ],
				],
			] );
			$GLOBALS['opf_hide_test_groups'] = [ [ 'id' => 9, 'group' => $group ] ];
			// OPF\Service\apply_filters (test-harness override) honors this key.
			$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = static function () {
				return $GLOBALS['opf_hide_test_groups'];
			};
			$GLOBALS['opf_hide_test_product'] = new \WC_Product();
		}

		protected function tearDown(): void {
			FieldGroups::flush_cache();
			unset(
				$GLOBALS['opf_hide_test_groups'], $GLOBALS['opf_hide_test_product'],
				$GLOBALS['opf_hide_test_is_cart'], $GLOBALS['opf_hide_test_is_checkout'],
				$GLOBALS['opf_hide_test_referer'], $GLOBALS['opf_hide_test_referer_id'],
				$GLOBALS['opf_wpml_filters']['opf_groups_for_product'],
				$GLOBALS['wp']
			);
		}

		private function values(): array {
			return [ '9' => [ 'shown' => 'visible-value', 'internal' => 'secret-ref', 'checkout_only' => 'co-value' ] ];
		}

		private function product(): \WC_Product {
			return $GLOBALS['opf_hide_test_product'];
		}

		private function labels( array $selections ): array {
			return array_map( static function ( $s ) { return $s['label']; }, $selections );
		}

		public function test_unknown_context_shows_everything(): void {
			$out = CartIntegration::visible_selections( $this->product(), $this->values(), null );
			$this->assertSame( [ 'Shown', 'Internal note', 'Checkout only' ], $this->labels( $out ) );
		}

		public function test_cart_and_mini_cart_hide_hide_cart(): void {
			foreach ( [ 'cart', 'mini_cart' ] as $context ) {
				$out = CartIntegration::visible_selections( $this->product(), $this->values(), $context );
				$this->assertContains( 'Shown', $this->labels( $out ) );
				$this->assertContains( 'Checkout only', $this->labels( $out ) );
				$this->assertNotContains( 'Internal note', $this->labels( $out ), "context $context must hide hide_cart fields" );
			}
		}

		public function test_checkout_context_hides_hide_checkout(): void {
			$out = CartIntegration::visible_selections( $this->product(), $this->values(), 'checkout' );
			$this->assertContains( 'Shown', $this->labels( $out ) );
			$this->assertNotContains( 'Internal note', $this->labels( $out ) );
			$this->assertNotContains( 'Checkout only', $this->labels( $out ) );
		}

		public function test_order_context_hides_hide_order_only(): void {
			$out = CartIntegration::visible_selections( $this->product(), $this->values(), 'order' );
			$this->assertContains( 'Shown', $this->labels( $out ) );
			$this->assertContains( 'Checkout only', $this->labels( $out ) );
			$this->assertNotContains( 'Internal note', $this->labels( $out ) );
		}

		public function test_display_item_data_detects_classic_cart_surface(): void {
			$GLOBALS['opf_hide_test_is_cart'] = true;
			$cart_item = [ 'data' => $this->product(), CartIntegration::ITEM_KEY => $this->values() ];
			$out = CartIntegration::display_item_data( [], $cart_item );
			$names = array_map( static function ( $r ) { return $r['name']; }, $out );
			$this->assertNotContains( 'Internal note', $names );
			$this->assertContains( 'Shown', $names );
		}

		public function test_display_item_data_detects_classic_checkout_surface(): void {
			$GLOBALS['opf_hide_test_is_checkout'] = true;
			$cart_item = [ 'data' => $this->product(), CartIntegration::ITEM_KEY => $this->values() ];
			$names = array_map( static function ( $r ) { return $r['name']; }, CartIntegration::display_item_data( [], $cart_item ) );
			$this->assertNotContains( 'Internal note', $names );
			$this->assertNotContains( 'Checkout only', $names );
		}

		public function test_display_item_data_detects_store_api_cart_route(): void {
			if ( ! defined( 'REST_REQUEST' ) ) {
				define( 'REST_REQUEST', true );
			}
			$GLOBALS['wp'] = (object) [ 'query_vars' => [ 'rest_route' => '/wc/store/v1/cart/items' ] ];
			$cart_item = [ 'data' => $this->product(), CartIntegration::ITEM_KEY => $this->values() ];
			$names = array_map( static function ( $r ) { return $r['name']; }, CartIntegration::display_item_data( [], $cart_item ) );
			$this->assertNotContains( 'Internal note', $names );
		}

		public function test_display_item_data_detects_store_api_checkout_route(): void {
			if ( ! defined( 'REST_REQUEST' ) ) {
				define( 'REST_REQUEST', true );
			}
			$GLOBALS['wp'] = (object) [ 'query_vars' => [ 'rest_route' => '/wc/store/v1/checkout' ] ];
			$cart_item = [ 'data' => $this->product(), CartIntegration::ITEM_KEY => $this->values() ];
			$names = array_map( static function ( $r ) { return $r['name']; }, CartIntegration::display_item_data( [], $cart_item ) );
			$this->assertNotContains( 'Internal note', $names );
			$this->assertNotContains( 'Checkout only', $names );
		}

		public function test_display_item_data_referer_fallback(): void {
			if ( ! defined( 'REST_REQUEST' ) ) {
				define( 'REST_REQUEST', true );
			}
			$GLOBALS['wp'] = (object) [ 'query_vars' => [ 'rest_route' => '/wc/store/v1/batch' ] ];
			$GLOBALS['opf_hide_test_referer'] = 'http://example.test/checkout/';
			$GLOBALS['opf_hide_test_referer_id'] = 7;
			$cart_item = [ 'data' => $this->product(), CartIntegration::ITEM_KEY => $this->values() ];
			$names = array_map( static function ( $r ) { return $r['name']; }, CartIntegration::display_item_data( [], $cart_item ) );
			$this->assertNotContains( 'Checkout only', $names );
		}
	}
}
