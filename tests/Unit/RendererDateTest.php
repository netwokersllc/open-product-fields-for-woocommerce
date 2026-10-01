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
}

namespace OPF\Tests\Unit {
	use OPF\Engine\FieldGroup;
	use OPF\Service\Renderer;
	use PHPUnit\Framework\TestCase;

	final class RendererDateTest extends TestCase {
		public function test_date_picker_receives_the_configured_week_start(): void {
			$GLOBALS['opf_test_options']['start_of_week'] = 1;
			$group = new FieldGroup( [ 'fields' => [ [
				'id' => 'delivery_date',
				'label' => 'Delivery date',
				'type' => 'date',
			] ] ] );

			ob_start();
			Renderer::render_group( '17', 'Delivery', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'data-opf-week-start="1"', $html );
		}
	}
}
