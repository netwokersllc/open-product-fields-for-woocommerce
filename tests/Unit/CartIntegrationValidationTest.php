<?php

namespace {
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public function get_parent_id(): int { return 0; }
			public function get_id(): int { return (int) ( $GLOBALS['opf_val_test_product_id'] ?? 42 ); }
			public function get_type(): string { return 'simple'; }
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
		function wc_get_product( $product_id ) { return $GLOBALS['opf_val_test_product'] ?? null; }
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
use ReflectionMethod;

/**
 * Conditional validation contract: hidden fields/sections are dropped before
 * validation/persistence (WAPF merges section conditions; the browser disables
 * hidden controls) and checkbox min/max follow WAPF's optional-min semantics.
 */
final class CartIntegrationValidationTest extends TestCase {
	protected function setUp(): void {
		FieldGroups::flush_cache();
		unset( $GLOBALS['opf_wpml_filters']['opf_groups_for_product'] );
	}

	protected function tearDown(): void {
		FieldGroups::flush_cache();
		unset(
			$GLOBALS['opf_val_test_groups'], $GLOBALS['opf_val_test_product'],
			$GLOBALS['opf_val_test_product_id'],
			$GLOBALS['opf_wpml_filters']['opf_groups_for_product']
		);
	}

	private function prime( array $fields ): \WC_Product {
		$group = new FieldGroup( [ 'fields' => $fields ] );
		$GLOBALS['opf_val_test_groups'] = [ [ 'id' => 9, 'lang' => '', 'group' => $group ] ];
		$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = static function () {
			return $GLOBALS['opf_val_test_groups'];
		};
		$GLOBALS['opf_val_test_product'] = new \WC_Product();
		return $GLOBALS['opf_val_test_product'];
	}

	private function drop( array $values ): array {
		$method = new ReflectionMethod( CartIntegration::class, 'drop_hidden_values' );
		return $method->invoke( null, $GLOBALS['opf_val_test_product'], $values );
	}

	private function validate( array $values ): array {
		$method = new ReflectionMethod( CartIntegration::class, 'validate_values' );
		return $method->invoke( null, $GLOBALS['opf_val_test_product'], $values, 1 );
	}

	private function show_conditional( string $field, string $value ): array {
		return [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => $field, 'operator' => 'is', 'value' => $value ] ] ] ];
	}

	public function test_hidden_field_value_is_dropped(): void {
		$this->prime( [
			[ 'id' => 'gate', 'type' => 'toggle', 'label' => 'Gate' ],
			[ 'id' => 'note', 'type' => 'text', 'label' => 'Note', 'conditionals' => $this->show_conditional( 'gate', '1' ) ],
		] );

		$out = $this->drop( [ '9' => [ 'gate' => '0', 'note' => 'forged' ] ] );
		$this->assertSame( '0', $out['9']['gate'] );
		$this->assertArrayNotHasKey( 'note', $out['9'] );
	}

	public function test_visible_field_value_is_kept(): void {
		$this->prime( [
			[ 'id' => 'gate', 'type' => 'toggle', 'label' => 'Gate' ],
			[ 'id' => 'note', 'type' => 'text', 'label' => 'Note', 'conditionals' => $this->show_conditional( 'gate', '1' ) ],
		] );

		$out = $this->drop( [ '9' => [ 'gate' => '1', 'note' => 'hello' ] ] );
		$this->assertSame( 'hello', $out['9']['note'] );
	}

	public function test_required_child_of_hidden_section_is_not_validated(): void {
		$product = $this->prime( [
			[ 'id' => 'gate', 'type' => 'toggle', 'label' => 'Gate' ],
			[ 'id' => 'sec', 'type' => 'section', 'label' => 'Sec', 'conditionals' => $this->show_conditional( 'gate', '1' ) ],
			[ 'id' => 'sectext', 'type' => 'text', 'label' => 'Section text', 'required' => true ],
			[ 'id' => 'secend', 'type' => 'section_end', 'label' => '' ],
		] );

		$values = $this->drop( [ '9' => [ 'gate' => '0', 'sectext' => 'forged' ] ] );
		$this->assertArrayNotHasKey( 'sectext', $values['9'] );
		$this->assertSame( [], $this->validate( $values ) );
	}

	public function test_required_child_of_visible_section_is_enforced(): void {
		$product = $this->prime( [
			[ 'id' => 'gate', 'type' => 'toggle', 'label' => 'Gate' ],
			[ 'id' => 'sec', 'type' => 'section', 'label' => 'Sec', 'conditionals' => $this->show_conditional( 'gate', '1' ) ],
			[ 'id' => 'sectext', 'type' => 'text', 'label' => 'Section text', 'required' => true ],
			[ 'id' => 'secend', 'type' => 'section_end', 'label' => '' ],
		] );

		$values = $this->drop( [ '9' => [ 'gate' => '1' ] ] );
		$this->assertNotEmpty( $this->validate( $values ) );
	}

	public function test_checkbox_max_is_enforced_and_optional_under_min_is_accepted(): void {
		$this->prime( [
			[ 'id' => 'cb', 'type' => 'checkbox', 'label' => 'Extras', 'min_choices' => 1, 'max_choices' => 2,
				'choices' => [
					[ 'slug' => 'a', 'label' => 'A' ],
					[ 'slug' => 'b', 'label' => 'B' ],
					[ 'slug' => 'c', 'label' => 'C' ],
				] ],
		] );

		$this->assertNotEmpty( $this->validate( [ '9' => [ 'cb' => [ 'a', 'b', 'c' ] ] ] ), '3 of max 2 must fail' );
		$this->assertSame( [], $this->validate( [ '9' => [ 'cb' => [ 'a' ] ] ] ), '1 of min 1 passes' );
		$this->assertSame( [], $this->validate( [ '9' => [] ] ), 'empty optional below min is accepted like WAPF' );
	}

	public function test_required_checkbox_empty_is_rejected(): void {
		$this->prime( [
			[ 'id' => 'cb', 'type' => 'checkbox', 'label' => 'Extras', 'required' => true,
				'choices' => [ [ 'slug' => 'a', 'label' => 'A' ] ] ],
		] );

		$this->assertNotEmpty( $this->validate( [ '9' => [] ] ) );
	}

	public function test_repeated_field_rows_drop_per_row_visibility(): void {
		$this->prime( [
			[ 'id' => 'flag', 'type' => 'text', 'label' => 'Flag', 'repeat' => [ 'enabled' => true, 'mode' => 'button' ] ],
			[ 'id' => 'note', 'type' => 'text', 'label' => 'Note',
				'repeat' => [ 'enabled' => true, 'mode' => 'button' ],
				'conditionals' => $this->show_conditional( 'flag', 'x' ) ],
		] );

		$out = $this->drop( [ '9' => [
			'flag' => [ 'x', 'y' ],
			'note' => [ 'visible-row', 'hidden-row' ],
		] ] );

		$this->assertSame( [ 0 => 'visible-row' ], $out['9']['note'] );
	}
}

}
