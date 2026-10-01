<?php

namespace {
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = null ): string {
			return (string) $text;
		}
	}
	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( $text, $domain = null ): string {
			return esc_html( $text );
		}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Engine\FieldGroup;
	use OPF\Service\Renderer;
	use PHPUnit\Framework\TestCase;

	final class RendererRepeaterTest extends TestCase {
		public function test_button_repeater_renders_indexed_names_and_a_single_field_hook(): void {
			$group = new FieldGroup( [ 'fields' => [ [
				'id' => 'guest_name',
				'label' => 'Guest name',
				'type' => 'text',
				'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3, 'add' => 'Add guest' ],
			] ] ] );

			ob_start();
			Renderer::render_group( '17', 'Guests', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'data-opf-repeat="button" data-opf-repeat-max="3"', $html );
			$this->assertStringContainsString( 'data-opf-field="guest_name"', $html );
			$this->assertStringContainsString( 'data-opf-repeat-instance="1"', $html );
			$this->assertStringContainsString( 'name="opf[17][guest_name][0]"', $html );
			$this->assertStringContainsString( 'id="opf-17-guest_name-repeat-0"', $html );
			$this->assertStringContainsString( 'class="opf-field-repeat__add"', $html );
			$this->assertStringContainsString( '>Add guest</button>', $html );
		}

		public function test_checkbox_repeater_names_keep_the_per_row_array_level(): void {
			$group = new FieldGroup( [ 'fields' => [ [
				'id' => 'toppings',
				'label' => 'Toppings',
				'type' => 'checkbox',
				'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 2 ],
				'choices' => [ [ 'slug' => 'olive', 'label' => 'Olive' ] ],
			] ] ] );

			ob_start();
			Renderer::render_group( '17', 'Pizza', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'name="opf[17][toppings][0][]"', $html );
		}

		public function test_quantity_repeater_renders_without_button_controls(): void {
			$group = new FieldGroup( [ 'fields' => [ [
				'id' => 'ticket_name',
				'label' => 'Ticket name',
				'type' => 'text',
				'repeat' => [ 'enabled' => true, 'mode' => 'quantity', 'label' => 'Ticket {n}' ],
			] ] ] );

			ob_start();
			Renderer::render_group( '17', 'Tickets', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'data-opf-repeat="quantity"', $html );
			$this->assertStringContainsString( 'name="opf[17][ticket_name][0]"', $html );
			$this->assertStringNotContainsString( 'class="opf-field-repeat__add"', $html );
			$this->assertStringNotContainsString( 'quantity-based repeated field is not available yet', $html );
		}
	}
}
