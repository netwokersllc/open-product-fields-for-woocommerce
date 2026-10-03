<?php
/**
 * CartEdit (WAPF-INTERACTION-CART-EDIT) unit tests: opt-in gate, cart-line
 * eligibility, edit-request resolution, classic link markup, Store API
 * extension, replace semantics, and Renderer-level prefill overlays.
 *
 * All WP/WC stubs are eval'd in setUp (separate processes) so this file
 * cannot poison the shared suite process — file-level globals would win
 * the load race against other test files' own stubs.
 */

namespace OPF\Tests\Unit {

use OPF\Engine\FieldGroup;
use OPF\Service\CartEdit;
use OPF\Service\CartIntegration;
use OPF\Service\Renderer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

final class FakeCart {
	public $cart_contents = [];
	public $removed = [];
	public function get_cart_item( $key ) { return $this->cart_contents[ $key ] ?? null; }
	public function remove_cart_item( $key ) { $this->removed[] = $key; unset( $this->cart_contents[ $key ] ); }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CartEditTest extends TestCase {
	protected function setUp(): void {
		eval( '
		if ( ! defined( "ARRAY_A" ) ) { define( "ARRAY_A", "ARRAY_A" ); }
		if ( ! class_exists( "WC_Product" ) ) {
			class WC_Product {
				public $pid;
				public function __construct( $id = null ) { $this->pid = $id; }
				public function get_id(): int { return (int) ( $this->pid ?? 42 ); }
				public function get_parent_id(): int { return 0; }
				public function is_visible(): bool { return true; }
				public function get_permalink( $item = null ): string { return "https://shop.test/product/p" . $this->get_id() . "/"; }
			}
		}
		if ( ! function_exists( "get_option" ) ) {
			function get_option( string $option, $default = false ) { return $GLOBALS["opf_test_options"][ $option ] ?? $default; }
		}
		if ( ! function_exists( "apply_filters" ) ) {
			function apply_filters( $tag, $value, ...$args ) { return $value; }
		}
		if ( ! function_exists( "add_filter" ) ) {
			function add_filter( ...$args ) {}
		}
		if ( ! function_exists( "add_action" ) ) {
			function add_action( ...$args ) {}
		}
		if ( ! function_exists( "did_action" ) ) {
			function did_action( $tag ) { return 1; }
		}
		if ( ! function_exists( "WC" ) ) {
			function WC() { return (object) [ "cart" => $GLOBALS["opf_edit_test_cart"] ?? null ]; }
		}
		if ( ! function_exists( "sanitize_text_field" ) ) {
			function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
		}
		if ( ! function_exists( "wp_unslash" ) ) {
			function wp_unslash( $v ) { return $v; }
		}
		if ( ! function_exists( "add_query_arg" ) ) {
			function add_query_arg( $key, $value, $url ) { return $url . ( str_contains( $url, "?" ) ? "&" : "?" ) . $key . "=" . $value; }
		}
		if ( ! function_exists( "esc_url" ) ) {
			function esc_url( $url ) { return (string) $url; }
		}
		if ( ! function_exists( "esc_attr" ) ) {
			function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, "UTF-8" ); }
		}
		if ( ! function_exists( "esc_html" ) ) {
			function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, "UTF-8" ); }
		}
		if ( ! function_exists( "esc_html__" ) ) {
			function esc_html__( $t, $d = null ) { return esc_html( $t ); }
		}
		if ( ! function_exists( "__" ) ) {
			function __( $t, $d = null ) { return (string) $t; }
		}
		if ( ! function_exists( "wc_get_cart_url" ) ) {
			function wc_get_cart_url() { return "https://shop.test/cart/"; }
		}
		if ( ! function_exists( "wp_json_encode" ) ) {
			function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
		}
		if ( ! function_exists( "checked" ) ) {
			function checked( $checked, $current = true, bool $echo = true ): string {
				$result = (string) $checked === (string) $current ? " checked=\"checked\"" : "";
				if ( $echo ) { echo $result; }
				return $result;
			}
		}
		if ( ! function_exists( "selected" ) ) {
			function selected( $selected, $current = true, bool $echo = true ): string {
				$result = (string) $selected === (string) $current ? " selected=\"selected\"" : "";
				if ( $echo ) { echo $result; }
				return $result;
			}
		}
		if ( ! function_exists( "wc_price" ) ) {
			function wc_price( $amount ): string { return "<span>$" . number_format( (float) $amount, 2 ) . "</span>"; }
		}
		if ( ! function_exists( "woocommerce_store_api_register_endpoint_data" ) ) {
			function woocommerce_store_api_register_endpoint_data( $args ) { $GLOBALS["opf_edit_test_store_api"] = $args; }
		}
		' );
		eval( 'namespace Automattic\WooCommerce\StoreApi\Schemas\V1;
		if ( ! class_exists( CartItemSchema::class ) ) {
			class CartItemSchema { public const IDENTIFIER = "cart-item"; }
		}' );

		$GLOBALS['opf_test_options'] = [];
		$GLOBALS['opf_edit_test_cart'] = new FakeCart();
		$_GET = [];
		$_POST = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['opf_test_options'], $GLOBALS['opf_edit_test_cart'], $GLOBALS['opf_edit_test_store_api'] );
		$_GET = [];
		$_POST = [];
	}

	private function opf_item( string $key = 'abc123', int $qty = 2, int $pid = 42 ): array {
		return [
			'key' => $key,
			'product_id' => $pid,
			'variation_id' => 0,
			'quantity' => $qty,
			'data' => new \WC_Product( $pid ),
			CartIntegration::ITEM_KEY => [ 'g' => [ 'engraving' => 'Hi' ] ],
		];
	}

	public function test_enabled_requires_opt_in_option(): void {
		$this->assertFalse( CartEdit::enabled() );
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$this->assertTrue( CartEdit::enabled() );
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'no';
		$this->assertFalse( CartEdit::enabled() );
	}

	public function test_can_edit_cart_item_mirrors_wapf_gate(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$this->assertTrue( CartEdit::can_edit_cart_item( $this->opf_item() ) );
		// Non-OPF lines are never editable.
		$this->assertFalse( CartEdit::can_edit_cart_item( [ 'product_id' => 42, 'data' => new \WC_Product( 42 ) ] ) );
		// OPF linked child lines carry `_opf_child`, not `opf_fields`.
		$this->assertFalse( CartEdit::can_edit_cart_item( [ 'product_id' => 7, '_opf_child' => [ 'parent' => 'abc123' ], 'data' => new \WC_Product( 7 ) ] ) );
		$this->assertFalse( CartEdit::can_edit_cart_item( null ) );
		// Setting off → gate closed even with OPF data.
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'no';
		$this->assertFalse( CartEdit::can_edit_cart_item( $this->opf_item() ) );
	}

	public function test_request_resolves_session_cart_key(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$item = $this->opf_item( 'k1', 3 );
		$GLOBALS['opf_edit_test_cart']->cart_contents['k1'] = $item;
		$_GET['opf_edit'] = 'k1';
		$request = CartEdit::request();
		$this->assertSame( 'k1', $request['key'] );
		$this->assertSame( 3, $request['item']['quantity'] );
	}

	public function test_request_rejects_foreign_and_missing_keys(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		// A key that is not in this session's cart cannot be edited.
		$_GET['opf_edit'] = 'someone_elses_key';
		$this->assertNull( CartEdit::request() );
	}

	public function test_request_rejects_non_opf_line_key(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		// A key that resolves but points at a NON-OPF line is not editable.
		$GLOBALS['opf_edit_test_cart']->cart_contents['plain'] = [ 'product_id' => 42 ];
		$_GET['opf_edit'] = 'plain';
		$this->assertNull( CartEdit::request() );
	}

	public function test_for_product_matches_cart_line_product(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$GLOBALS['opf_edit_test_cart']->cart_contents['k1'] = $this->opf_item( 'k1', 4, 42 );
		$_GET['opf_edit'] = 'k1';
		$context = CartEdit::for_product( new \WC_Product( 42 ) );
		$this->assertSame( 'k1', $context['key'] );
		$this->assertSame( [ 'g' => [ 'engraving' => 'Hi' ] ], $context['values'] );
		$this->assertNull( CartEdit::for_product( new \WC_Product( 99 ) ) );
	}

	public function test_prefill_quantity_uses_cart_line_quantity(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$GLOBALS['opf_edit_test_cart']->cart_contents['k1'] = $this->opf_item( 'k1', 5, 42 );
		$_GET['opf_edit'] = 'k1';
		$args = CartEdit::prefill_quantity( [ 'input_value' => 1 ], new \WC_Product( 42 ) );
		$this->assertSame( 5, $args['input_value'] );
		// A non-matching product's quantity is untouched.
		$args = CartEdit::prefill_quantity( [ 'input_value' => 1 ], new \WC_Product( 99 ) );
		$this->assertSame( 1, $args['input_value'] );
	}

	public function test_render_edit_state_emits_hidden_key_for_matching_product(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$GLOBALS['opf_edit_test_cart']->cart_contents['k1'] = $this->opf_item( 'k1', 1, 42 );
		$_GET['opf_edit'] = 'k1';
		$GLOBALS['product'] = new \WC_Product( 42 );
		ob_start();
		CartEdit::render_edit_state();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="_opf_edit"', $html );
		$this->assertStringContainsString( 'value="k1"', $html );
		unset( $GLOBALS['product'] );
	}

	public function test_replace_edited_item_removes_only_editable_target(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$GLOBALS['opf_edit_test_cart']->cart_contents['k1'] = $this->opf_item( 'k1', 1, 42 );
		$GLOBALS['opf_edit_test_cart']->cart_contents['plain'] = [ 'product_id' => 9, 'data' => new \WC_Product( 9 ) ];
		// Legit edit: OPF line removed.
		$_POST['_opf_edit'] = 'k1';
		$data = CartEdit::replace_edited_item( [ 'x' => 1 ], 42, 0 );
		$this->assertSame( [ 'x' => 1 ], $data );
		$this->assertSame( [ 'k1' ], $GLOBALS['opf_edit_test_cart']->removed );
		// Forged key pointing at a NON-OPF line is ignored, never destructive.
		$GLOBALS['opf_edit_test_cart']->removed = [];
		$_POST['_opf_edit'] = 'plain';
		CartEdit::replace_edited_item( [], 42, 0 );
		$this->assertSame( [], $GLOBALS['opf_edit_test_cart']->removed );
		$this->assertArrayHasKey( 'plain', $GLOBALS['opf_edit_test_cart']->cart_contents );
		// Missing key: nothing removed.
		$_POST['_opf_edit'] = 'ghost';
		CartEdit::replace_edited_item( [], 42, 0 );
		$this->assertSame( [], $GLOBALS['opf_edit_test_cart']->removed );
	}

	public function test_replace_edited_item_noop_when_setting_off(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'no';
		$GLOBALS['opf_edit_test_cart']->cart_contents['k2'] = $this->opf_item( 'k2' );
		$_POST['_opf_edit'] = 'k2';
		CartEdit::replace_edited_item( [], 42, 0 );
		$this->assertSame( [], $GLOBALS['opf_edit_test_cart']->removed );
		unset( $_POST['_opf_edit'] );
	}

	public function test_redirect_message_and_button_text(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$GLOBALS['opf_edit_test_cart']->cart_contents['k1'] = $this->opf_item( 'k1', 1, 42 );
		$_POST['_opf_edit'] = 'k1';
		$this->assertSame( 'https://shop.test/cart/', CartEdit::redirect_to_cart( 'https://shop.test/product/p42/', true ) );
		$this->assertSame( 'Cart updated.', CartEdit::updated_message( 'added', [], false ) );
		$_GET['opf_edit'] = 'k1';
		$this->assertSame( 'Update cart', CartEdit::update_button_text( 'Add to cart' ) );
		unset( $_POST['_opf_edit'], $_GET['opf_edit'] );
	}

	public function test_classic_cart_edit_link_markup(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$item = $this->opf_item( 'k1' );
		ob_start();
		CartEdit::add_edit_link( $item, 'k1' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'class="opf-edit-cartitem"', $html );
		$this->assertStringContainsString( 'opf_edit=k1', $html );
		$this->assertStringContainsString( '(edit)', $html );
		// Non-OPF line: no output.
		ob_start();
		CartEdit::add_edit_link( [ 'product_id' => 9, 'data' => new \WC_Product( 9 ) ], 'p' );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_store_api_extension_registers_opf_edit_link(): void {
		CartEdit::register_store_api();
		$args = $GLOBALS['opf_edit_test_store_api'] ?? null;
		$this->assertIsArray( $args );
		$this->assertSame( 'opf_edit', $args['namespace'] );
		$schema = ( $args['schema_callback'] )();
		$this->assertArrayHasKey( 'editLink', $schema );
		// Setting off → no link data.
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'no';
		$data = ( $args['data_callback'] )( $this->opf_item( 'k1' ) );
		$this->assertArrayNotHasKey( 'editLink', $data );
		// Setting on + OPF data → link rendered.
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$data = ( $args['data_callback'] )( $this->opf_item( 'k1' ) );
		$this->assertStringContainsString( 'opf-edit-cartitem', (string) $data['editLink'] );
	}

	/* -------- Renderer-level prefill (additive param only) -------- */

	public function test_render_group_prefills_text_select_and_checkbox(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'note', 'label' => 'Note', 'type' => 'text' ],
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'choices' => [
				[ 'slug' => 'matte', 'label' => 'Matte' ],
				[ 'slug' => 'gloss', 'label' => 'Gloss' ],
			] ],
			[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift' ],
				[ 'slug' => 'rush', 'label' => 'Rush' ],
			] ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Opts', $group, 10.0, null, [ 'note' => 'PREFILLED', 'finish' => 'gloss', 'extras' => [ 'rush' ] ] );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'value="PREFILLED"', $html );
		$this->assertMatchesRegularExpression( '/<option value="gloss"[^>]*selected/', $html );
		$this->assertMatchesRegularExpression( '/value="rush"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="gift"[^>]*checked/', $html );
	}

	public function test_render_group_repeat_rows_render_server_side(): void {
		$group = new FieldGroup( [ 'fields' => [ [
			'id' => 'names', 'label' => 'Names', 'type' => 'text',
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 5 ],
		] ] ] );
		ob_start();
		Renderer::render_group( '17', 'Guests', $group, 10.0, null, [ 'names' => [ 'Ada', 'Grace' ] ] );
		$html = (string) ob_get_clean();
		$this->assertSame( 2, substr_count( $html, 'data-opf-repeat-instance="1"' ) );
		$this->assertStringContainsString( 'name="opf[17][names][0]"', $html );
		$this->assertStringContainsString( 'name="opf[17][names][1]"', $html );
		$this->assertStringContainsString( 'value="Ada"', $html );
		$this->assertStringContainsString( 'value="Grace"', $html );
		// No edit rows → single default instance (unchanged behavior).
		ob_start();
		Renderer::render_group( '17', 'Guests', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $html, 'data-opf-repeat-instance="1"' ) );
	}

	public function test_quantity_repeat_rows_pad_to_cart_quantity(): void {
		$group = new FieldGroup( [ 'fields' => [ [
			'id' => 'ticket', 'label' => 'Ticket', 'type' => 'text',
			'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ],
		] ] ] );
		// A split-merged cart line stores one row; edit qty 3 pads it out.
		ob_start();
		Renderer::render_group( '17', 'T', $group, 10.0, null, [ 'ticket' => [ 'Ada' ] ], 3 );
		$html = (string) ob_get_clean();
		$this->assertSame( 3, substr_count( $html, 'data-opf-repeat-instance="1"' ) );
		$this->assertSame( 3, substr_count( $html, 'value="Ada"' ) );
	}

	public function test_section_repeat_emits_edit_rows_payload(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'sec', 'label' => 'Extra', 'type' => 'section', 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 5 ] ],
			[ 'id' => 'seat', 'label' => 'Seat', 'type' => 'radio', 'choices' => [
				[ 'slug' => 'front', 'label' => 'Front' ],
				[ 'slug' => 'back', 'label' => 'Back' ],
			] ],
			[ 'id' => 'note', 'label' => 'Note', 'type' => 'text' ],
			[ 'id' => 'secend', 'label' => '', 'type' => 'section_end' ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Sec', $group, 10.0, null, [
			'seat' => [ 'front', 'back' ],
			'note' => [ 'n1', 'n2' ],
		] );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-opf-section-repeat="1"', $html );
		$this->assertStringContainsString( 'data-opf-edit-rows=', $html );
		preg_match( '/data-opf-edit-rows="([^"]+)"/', $html, $m );
		$rows = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		$this->assertSame( [ [ 'seat' => 'back', 'note' => 'n2' ] ], $rows );
		// Instance 0 renders with row 0 values server-side.
		$this->assertMatchesRegularExpression( '/value="front"[^>]*checked/', $html );
		$this->assertStringContainsString( 'value="n1"', $html );
	}

	public function test_toggle_and_date_fields_prefill(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'optin', 'label' => 'Opt in', 'type' => 'toggle' ],
			[ 'id' => 'when', 'label' => 'When', 'type' => 'date' ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Misc', $group, 10.0, null, [ 'optin' => '1', 'when' => '2030-05-04' ] );
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/type="checkbox" value="1"[^>]*checked/', $html );
		$this->assertStringContainsString( 'value="2030-05-04"', $html );
	}

	public function test_stored_values_seed_conditional_visibility(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'kind', 'label' => 'Kind', 'type' => 'radio', 'choices' => [
				[ 'slug' => 'a', 'label' => 'A' ],
				[ 'slug' => 'b', 'label' => 'B' ],
			] ],
			[ 'id' => 'why', 'label' => 'Why', 'type' => 'text',
				'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'kind', 'operator' => 'is', 'value' => 'b' ] ] ] ],
			],
		] ] );
		// With stored 'b', the conditional field renders visible (no opf-hide).
		ob_start();
		Renderer::render_group( '17', 'C', $group, 10.0, null, [ 'kind' => 'b', 'why' => 'x' ] );
		$html = (string) ob_get_clean();
		preg_match( '/<div class="([^"]*field-why[^"]*)"/', $html, $m );
		$this->assertStringNotContainsString( 'opf-hide', $m[1] ?? '' );
		// With stored 'a' the same field starts hidden.
		ob_start();
		Renderer::render_group( '17', 'C', $group, 10.0, null, [ 'kind' => 'a', 'why' => 'x' ] );
		$html = (string) ob_get_clean();
		preg_match( '/<div class="([^"]*field-why[^"]*)"/', $html, $m );
		$this->assertStringContainsString( 'opf-hide', $m[1] ?? '' );
	}
}
}
