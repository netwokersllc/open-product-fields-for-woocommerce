<?php

namespace {
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public function get_parent_id(): int { return 0; }
			public function get_id(): int { return (int) ( $GLOBALS['opf_repeat_test_product_id'] ?? 42 ); }
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value, ...$args ) {
			return 'opf_groups_for_product' === $tag ? ( $GLOBALS['opf_repeat_test_groups'] ?? $value ) : $value;
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
		function wc_get_product( $product_id ) { return $GLOBALS['opf_repeat_test_product'] ?? null; }
	}
	if ( ! function_exists( 'WC' ) ) {
		function WC() { return (object) [ 'cart' => $GLOBALS['opf_repeat_test_cart'] ?? null ]; }
	}
}

namespace OPF\Tests\Unit {

use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CartIntegrationRepeatTest extends TestCase {
	protected function setUp(): void {
		FieldGroups::flush_cache();
	}

	protected function tearDown(): void {
		FieldGroups::flush_cache();
		unset( $GLOBALS['opf_repeat_test_groups'], $GLOBALS['opf_repeat_test_product'], $GLOBALS['opf_repeat_test_cart'], $GLOBALS['opf_repeat_test_product_id'] );
		unset( $GLOBALS['opf_woocs_products'][321] );
		if ( isset( $GLOBALS['opf_wpml_filters']['opf_groups_for_product'] ) ) {
			unset( $GLOBALS['opf_wpml_filters']['opf_groups_for_product'] );
		}
	}

	public function test_quantity_repeat_label_without_placeholder_does_not_rename_cart_field(): void {
		$this->assertSame(
			'Ticket name',
			$this->repeat_label( [
				'label' => 'Ticket name',
				'repeat' => [ 'enabled' => true, 'mode' => 'quantity', 'label' => 'Ticket' ],
			], false, 1 )
		);
	}

	public function test_quantity_repeat_label_placeholder_does_not_rename_cart_field(): void {
		$this->assertSame(
			'Ticket name',
			$this->repeat_label( [
				'label' => 'Ticket name',
				'repeat' => [ 'enabled' => true, 'mode' => 'quantity', 'label' => 'Ticket {n}' ],
			], false, 2 )
		);
	}

	public function test_button_repeat_label_still_substitutes_row_number(): void {
		$this->assertSame(
			'Ticket 2',
			$this->repeat_label( [
				'label' => 'Ticket name',
				'repeat' => [ 'enabled' => true, 'mode' => 'button', 'label' => 'Ticket {n}' ],
			], false, 1 )
		);
	}

	public function test_numbered_quantity_clone_labels_do_not_split_identical_cart_units(): void {
		$group = new FieldGroup( [ 'schema' => FieldGroup::SCHEMA, 'fields' => [ [
			'id' => 'ticket_name',
			'label' => 'Ticket name',
			'type' => 'text',
			'repeat' => [ 'enabled' => true, 'mode' => 'quantity', 'label' => 'Ticket {n}' ],
		] ] ] );
		$GLOBALS['opf_repeat_test_groups'] = [ [ 'id' => 17, 'lang' => '', 'group' => $group ] ];
		$GLOBALS['opf_repeat_test_product_id'] = 321;
		$GLOBALS['opf_repeat_test_product'] = new \WC_Product();
		$GLOBALS['opf_woocs_products'][321] = $GLOBALS['opf_repeat_test_product'];
		$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = static function ( $entries ) {
			return $GLOBALS['opf_repeat_test_groups'];
		};
		$cart = new RepeatTestCart();
		$cart->cart_contents = [ 'original' => [
			'quantity' => 2,
			CartIntegration::ITEM_KEY => [ '17' => [ 'ticket_name' => [ 'Grace', 'Grace' ] ] ],
		] ];
		$GLOBALS['opf_repeat_test_cart'] = $cart;
		$this->assertCount( 1, FieldGroups::for_product( $GLOBALS['opf_repeat_test_product'] ) );

		CartIntegration::split_quantity_repeat_cart_item(
			'original',
			321,
			2,
			0,
			[],
			[ CartIntegration::ITEM_KEY => [ '17' => [ 'ticket_name' => [ 'Grace', 'Grace' ] ] ] ]
		);

		$this->assertCount( 1, $cart->cart_contents );
		$this->assertSame( 2, $cart->cart_contents['original']['quantity'] );
		$this->assertSame( [ 0 => 'Grace' ], $cart->cart_contents['original'][ CartIntegration::ITEM_KEY ]['17']['ticket_name'] );
	}

	private function repeat_label( array $field, bool $section_repeat, int $index ): string {
		$method = new ReflectionMethod( CartIntegration::class, 'repeated_selection_label' );
		return $method->invoke( null, $field, $section_repeat, $index );
	}
}

final class RepeatTestCart {
	public $cart_contents = [];

	public function get_cart_item( string $key ): array { return $this->cart_contents[ $key ] ?? []; }
	public function set_quantity( string $key, int $quantity, bool $refresh_totals = true ): void {
		$this->cart_contents[ $key ]['quantity'] = $quantity;
	}
	public function get_cart(): array { return $this->cart_contents; }
	public function add_to_cart( $product_id, $quantity, $variation_id, $variation, $data ) {
		$key = 'clone-' . count( $this->cart_contents );
		$this->cart_contents[ $key ] = [ 'quantity' => $quantity ] + $data;
		return $key;
	}
	public function remove_cart_item( string $key ): void { unset( $this->cart_contents[ $key ] ); }
}
}
