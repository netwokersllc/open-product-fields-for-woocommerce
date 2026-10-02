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
	if ( ! function_exists( 'selected' ) ) {
		function selected( $selected, $current = true, bool $echo = true ): string {
			$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
			if ( $echo ) {
				echo $result;
			}
			return $result;
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

		public function test_quantity_repeat_pricing_uses_qty_based_theme_attributes(): void {
			$group = new FieldGroup( [ 'schema' => FieldGroup::SCHEMA, 'fields' => [ [
				'id' => 'ticket_type',
				'label' => 'Ticket type',
				'type' => 'select',
				'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ],
				'choices' => [
					[ 'slug' => 'standard', 'label' => 'Standard', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
					[ 'slug' => 'vip', 'label' => 'VIP', 'pricing' => [ 'type' => 'fixed', 'amount' => 5, 'per_unit' => true ] ],
					[ 'slug' => 'percent', 'label' => 'Percent', 'pricing' => [ 'type' => 'percent', 'amount' => 10, 'per_unit' => false ] ],
					[ 'slug' => 'percent_per_unit', 'label' => 'Per unit percent', 'pricing' => [ 'type' => 'percent', 'amount' => 10, 'per_unit' => true ] ],
					[ 'slug' => 'formula', 'label' => 'Formula', 'pricing' => [ 'type' => 'formula', 'formula' => '[price] * 0.2', 'per_unit' => false ] ],
				],
			], [
				'id' => 'ticket_note',
				'label' => 'Ticket note',
				'type' => 'text',
				'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ],
				'pricing' => [ 'type' => 'formula', 'formula' => '7', 'per_unit' => false ],
			] ] ] );

			ob_start();
			Renderer::render_group( '17', 'Tickets', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'data-opf-pricetype="qt" data-opf-price="5"', $html );
			$this->assertStringContainsString( 'data-opf-pricetype="fx" data-opf-price="(5) * [qty]"', $html );
			$this->assertStringContainsString( 'data-opf-pricetype="percent" data-opf-price="10"', $html );
			$this->assertStringContainsString( 'data-opf-pricetype="fx" data-opf-price="([price] * 0.1) * [qty]"', $html );
			$this->assertStringContainsString( 'data-opf-pricetype="fx" data-opf-price="([price] * 0.2)"', $html );
			$this->assertStringContainsString( 'data-opf-pricetype="fx" data-opf-price="(7)"', $html );
		}

		public function test_quantity_section_repeater_wraps_child_fields_and_indexes_names(): void {
			$group = new FieldGroup( [ 'fields' => [
				[ 'id' => 'attendees', 'label' => 'Attendee', 'type' => 'section', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity', 'label' => 'Guest {n}' ] ],
				[ 'id' => 'guest_name', 'label' => 'Name', 'type' => 'text' ],
				[ 'id' => 'guest_meal', 'label' => 'Meal', 'type' => 'select', 'choices' => [ [ 'slug' => 'soup', 'label' => 'Soup', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ] ] ],
				[ 'id' => 'attendees-end', 'type' => 'section_end' ],
				[ 'id' => 'delivery_note', 'label' => 'Delivery note', 'type' => 'text' ],
			] ] );

			ob_start();
			Renderer::render_group( '17', 'Guests', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'data-opf-field="attendees" data-opf-repeat="quantity" data-opf-section-repeat="1"', $html );
			$this->assertStringContainsString( 'class="opf-section-repeat__label"><span>Attendee</span>', $html );
			$this->assertStringContainsString( 'name="opf[17][guest_name][0]"', $html );
			$this->assertStringContainsString( 'name="opf[17][guest_meal][0]"', $html );
			$this->assertStringContainsString( 'data-opf-pricetype="qt" data-opf-price="5"', $html );
			$this->assertStringNotContainsString( 'class="opf-field-repeat__add"', $html );
			$this->assertStringContainsString( 'name="opf[17][delivery_note]"', $html );
		}
	}
}
