<?php

namespace {
	require_once __DIR__ . '/RendererImageSwatchTest.php';
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = '' ): string { return (string) $text; }
	}
}

namespace OPF\Tests\Unit {

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * WAPF text-length/regex and checkbox selection-limit markup parity.
 */
final class RendererValidationAttrsTest extends TestCase {
	private function render( array $field ): string {
		$group = new FieldGroup( [ 'fields' => [ $field ] ] );
		ob_start();
		Renderer::render_group( '17', 'Validation', $group, 10 );
		return (string) ob_get_clean();
	}

	public function test_text_field_emits_minlength_maxlength_and_pattern(): void {
		$html = $this->render( [
			'id' => 'code', 'type' => 'text', 'label' => 'Code',
			'minlength' => 3, 'maxlength' => 5, 'pattern' => '[a-z]+',
		] );
		$this->assertStringContainsString( 'minlength="3"', $html );
		$this->assertStringContainsString( 'maxlength="5"', $html );
		$this->assertStringContainsString( 'pattern="[a-z]+"', $html );
	}

	public function test_textarea_emits_length_attrs_without_pattern(): void {
		$html = $this->render( [ 'id' => 'bio', 'type' => 'textarea', 'label' => 'Bio', 'minlength' => 2, 'maxlength' => 40 ] );
		$this->assertStringContainsString( 'minlength="2"', $html );
		$this->assertStringContainsString( 'maxlength="40"', $html );
		$this->assertStringNotContainsString( 'pattern=', $html );
	}

	public function test_checkbox_wrapper_emits_selection_limit_attrs(): void {
		$html = $this->render( [
			'id' => 'extras', 'type' => 'checkbox', 'label' => 'Extras', 'min_choices' => 1, 'max_choices' => 2,
			'choices' => [
				[ 'slug' => 'a', 'label' => 'A' ],
				[ 'slug' => 'b', 'label' => 'B' ],
				[ 'slug' => 'c', 'label' => 'C' ],
			],
		] );
		$this->assertStringContainsString( 'data-min-choices="1"', $html );
		$this->assertStringContainsString( 'data-max-choices="2"', $html );
	}
}

}
