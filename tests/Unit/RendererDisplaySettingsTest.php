<?php

namespace {
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $text ): string {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
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
	if ( ! function_exists( 'selected' ) ) {
		function selected( $selected, $current = true, bool $echo = true ): string {
			$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
			if ( $echo ) {
				echo $result;
			}
			return $result;
		}
	}
	if ( ! function_exists( 'wc_price' ) ) {
		function wc_price( $amount ): string {
			return '<span class="woocommerce-Price-amount amount"><bdi>$' . number_format( (float) $amount, 2 ) . '</bdi></span>';
		}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Engine\FieldGroup;
	use OPF\Service\Renderer;
	use PHPUnit\Framework\TestCase;

	final class RendererDisplaySettingsTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['opf_test_options'] = [];
		}

		private function group( array $fields, array $extra = [] ): FieldGroup {
			return new FieldGroup( array_merge( [ 'fields' => $fields ], $extra ) );
		}

		private function html( FieldGroup $group ): string {
			ob_start();
			Renderer::render_group( '17', 'Group', $group, 10.0 );
			return (string) ob_get_clean();
		}

		public function test_summary_mode_defaults_and_legacy_mapping(): void {
			$this->assertSame( 'three', Renderer::summary_mode() );
			$GLOBALS['opf_test_options'] = [ 'opf_show_totals' => 'yes' ];
			$this->assertSame( 'three', Renderer::summary_mode() );
			$GLOBALS['opf_test_options'] = [ 'opf_show_totals' => 'no' ];
			$this->assertSame( 'hidden', Renderer::summary_mode() );
			$GLOBALS['opf_test_options'] = [ 'opf_price_summary_mode' => 'grand' ];
			$this->assertSame( 'grand', Renderer::summary_mode() );
			$GLOBALS['opf_test_options'] = [ 'opf_price_summary_mode' => 'bogus' ];
			$this->assertSame( 'three', Renderer::summary_mode() );
		}

		public function test_price_hints_render_and_respect_global_toggle(): void {
			$group = $this->group( [
				[ 'id' => 'units', 'type' => 'text', 'label' => 'Units', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
				[ 'id' => 'size', 'type' => 'select', 'label' => 'Size', 'choices' => [
					[ 'slug' => 'large', 'label' => 'Large', 'pricing' => [ 'type' => 'fixed', 'amount' => 10 ] ],
				] ],
				[ 'id' => 'extras', 'type' => 'checkbox', 'label' => 'Extras', 'choices' => [
					[ 'slug' => 'wrap', 'label' => 'Wrap', 'pricing' => [ 'type' => 'percent', 'amount' => 50 ] ],
				] ],
			] );

			$html = $this->html( $group );
			$this->assertStringContainsString( 'opf-pricing-hint', $html );
			// wc_price stub format varies by suite load order ('$5' vs '$5.00').
			$this->assertMatchesRegularExpression( '/\$5(\.\d+)?/', $html );
			// Percent hint is computed against the $10 base price.
			$this->assertMatchesRegularExpression( '/\$10(\.\d+)?/', $html );
			// Select option hint is plain text inside the option element.
			$this->assertMatchesRegularExpression( '/<option[^>]*>[^<]*\+[^<]*\$10(\.\d+)?<\/option>/', $html );

			$GLOBALS['opf_test_options'] = [ 'opf_show_price_hints' => 'no' ];
			$html = $this->html( $group );
			$this->assertStringNotContainsString( 'opf-pricing-hint', $html );
		}

		public function test_tooltip_instructions_render_inside_label_with_a11y_wiring(): void {
			$group = $this->group( [
				[ 'id' => 'engraving', 'type' => 'text', 'label' => 'Engraving', 'description' => 'Keep it short', 'description_presentation' => 'tooltip' ],
			] );

			$html = $this->html( $group );
			// Trigger sits inside .opf-field-label right after </label>.
			$this->assertStringContainsString( '</label><button type="button" class="opf-tooltip-trigger"', $html );
			$this->assertStringContainsString( 'aria-describedby="opf-tt-17-engraving"', $html );
			$this->assertStringContainsString( 'aria-expanded="false"', $html );
			$this->assertStringContainsString( 'role="tooltip" id="opf-tt-17-engraving"', $html );
			$this->assertStringContainsString( '>Keep it short</span>', $html );
			// Not rendered inline.
			$this->assertStringNotContainsString( 'opf-field-description', $html );
		}

		public function test_inline_instructions_render_between_label_and_input(): void {
			$group = $this->group( [
				[ 'id' => 'note', 'type' => 'text', 'label' => 'Note', 'description' => 'Inline note' ],
			] );

			$html = $this->html( $group );
			$this->assertStringContainsString( '<div class="opf-field-description">Inline note</div>', $html );
			$this->assertStringNotContainsString( 'opf-tooltip-trigger', $html );
			$this->assertLessThan( strpos( $html, 'opf-field-input' ), strpos( $html, 'opf-field-description' ) );
		}

		public function test_description_presentation_normalizes_to_inline_or_tooltip(): void {
			$this->assertSame( 'inline', FieldGroup::normalize_field( [ 'type' => 'text' ] )['description_presentation'] );
			$this->assertSame( 'tooltip', FieldGroup::normalize_field( [ 'type' => 'text', 'description_presentation' => 'tooltip' ] )['description_presentation'] );
			$this->assertSame( 'inline', FieldGroup::normalize_field( [ 'type' => 'text', 'description_presentation' => 'bogus' ] )['description_presentation'] );
		}

		public function test_scalar_defaults_and_placeholders_render(): void {
			$group = $this->group( [
				[ 'id' => 'a_text', 'type' => 'text', 'label' => 'T', 'default' => 'Ada', 'placeholder' => 'Name' ],
				[ 'id' => 'a_email', 'type' => 'email', 'label' => 'E', 'default' => 'a@b.c', 'placeholder' => 'Email' ],
				[ 'id' => 'a_num', 'type' => 'number', 'label' => 'N', 'default' => '0', 'placeholder' => 'Qty' ],
				[ 'id' => 'a_url', 'type' => 'url', 'label' => 'U', 'default' => 'https://x.test' ],
				[ 'id' => 'a_area', 'type' => 'textarea', 'label' => 'TA', 'default' => "line one\nline two" ],
				[ 'id' => 'a_tgl', 'type' => 'toggle', 'label' => 'G', 'default' => '1' ],
				[ 'id' => 'a_sel', 'type' => 'select', 'label' => 'S', 'choices' => [ [ 'slug' => 'b', 'label' => 'B', 'selected' => true ] ] ],
			] );

			$html = $this->html( $group );
			$this->assertStringContainsString( 'value="Ada"', $html );
			$this->assertStringContainsString( 'value="a@b.c"', $html );
			$this->assertStringContainsString( 'value="0"', $html );
			$this->assertStringContainsString( 'value="https://x.test"', $html );
			$this->assertStringContainsString( ">line one\nline two</textarea>", $html );
			$this->assertStringContainsString( 'placeholder="Name"', $html );
			$this->assertStringContainsString( 'placeholder="Email"', $html );
			$this->assertMatchesRegularExpression( '/<input[^>]+checked[^>]+class="opf-input input-a_tgl"|<input[^>]+class="opf-input input-a_tgl"[^>]+checked/', $html );
			$this->assertStringContainsString( 'selected="selected"', $html );
		}

		public function test_field_layout_width_and_label_position_classes(): void {
			$above = $this->html( $this->group( [ [ 'id' => 'w', 'type' => 'text', 'label' => 'W', 'width' => 50 ] ] ) );
			$this->assertStringContainsString( 'label-above', $above );
			$this->assertStringContainsString( 'style="width:50%;"', $above );

			$below = $this->html( $this->group( [ [ 'id' => 'w', 'type' => 'text', 'label' => 'W' ] ], [ 'labels_position' => 'below' ] ) );
			$this->assertStringContainsString( 'label-below', $below );
		}

		public function test_mark_required_group_flag_controls_the_asterisk(): void {
			$required_field = [ 'id' => 'w', 'type' => 'text', 'label' => 'W', 'required' => true ];

			$on = $this->html( $this->group( [ $required_field ] ) );
			$this->assertStringContainsString( '<abbr class="required"', $on );

			$off = $this->html( $this->group( [ $required_field ], [ 'mark_required' => false ] ) );
			$this->assertStringNotContainsString( '<abbr class="required"', $off );
		}

		public function test_hide_flags_normalize_from_top_level_and_wapf_options(): void {
			$plain = FieldGroup::normalize_field( [ 'type' => 'text' ] );
			$this->assertSame( [ false, false, false ], [ $plain['hide_cart'], $plain['hide_checkout'], $plain['hide_order'] ] );

			$top = FieldGroup::normalize_field( [ 'type' => 'text', 'hide_cart' => true, 'hide_order' => 1 ] );
			$this->assertSame( [ true, false, true ], [ $top['hide_cart'], $top['hide_checkout'], $top['hide_order'] ] );

			// WAPF raw schema keeps the flags inside `options`.
			$wapf = FieldGroup::normalize_field( [ 'type' => 'text', 'options' => [ 'hide_checkout' => true, 'hide_order' => true ] ] );
			$this->assertSame( [ false, true, true ], [ $wapf['hide_cart'], $wapf['hide_checkout'], $wapf['hide_order'] ] );
		}

		public function test_calc_field_renders_raw_input_and_display_hooks(): void {
			$group = $this->group( [ [
				'id'            => 'total',
				'type'          => 'calc',
				'label'         => 'Total',
				'calc_type'     => 'cost',
				'formula'       => '[field.rate] * 2',
				'result_text'   => 'Total: {result}',
			] ] );

			$html = $this->html( $group );
			$this->assertStringContainsString( 'data-opf-calc="1"', $html );
			$this->assertStringContainsString( 'data-opf-calc-type="cost"', $html );
			$this->assertStringContainsString( 'data-opf-calc-text="Total: {result}"', $html );
			$this->assertStringContainsString( 'class="opf-calc-text"', $html );
			$this->assertStringContainsString( 'class="opf-input opf-calc-raw input-total"', $html );
			$this->assertStringContainsString( 'name="opf[17][total]"', $html );
		}

		// Note: render_totals() DOM-per-mode coverage lives in the disposable
		// browser proof (bin/e2e-display-storefront.mjs) — the shared unit
		// suite cannot guarantee a WC_Product stub exposing get_type().
	}
}
