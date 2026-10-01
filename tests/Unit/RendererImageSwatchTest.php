<?php

namespace {
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $value ): string {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $value ): string {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $value ): string {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'selected' ) ) {
		function selected( $selected, $current = true, bool $echo = true ): string {
			return (string) $selected === (string) $current ? ' selected="selected"' : '';
		}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Engine\FieldGroup;
	use OPF\Service\Renderer;
	use PHPUnit\Framework\TestCase;

	final class RendererImageSwatchTest extends TestCase {
		public function test_image_choice_renders_an_escaped_accessible_image_and_input(): void {
			$group = new FieldGroup( [ 'fields' => [
				[
					'id' => 'finish',
					'label' => 'Finish',
					'type' => 'swatch',
					'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak & Ash', 'image' => 'https://example.test/oak.jpg', 'selected' => true ] ],
				],
			] ] );
			ob_start();
			Renderer::render_group( '17', 'Finish', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'class="opf-swatch-image"', $html );
			$this->assertStringContainsString( 'src="https://example.test/oak.jpg"', $html );
			$this->assertStringContainsString( 'alt="Oak &amp; Ash"', $html );
			$this->assertStringContainsString( 'value="oak"', $html );
			$this->assertStringContainsString( 'checked', $html );
		}
	}
}
