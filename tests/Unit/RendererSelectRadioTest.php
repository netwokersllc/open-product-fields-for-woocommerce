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

	final class RendererSelectRadioTest extends TestCase {
		private function markup( string $type, bool $required, bool $default, bool $disabled_default = false ): string {
			$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'finish', 'type' => $type, 'label' => 'Finish', 'required' => $required, 'choices' => [
				[ 'slug' => 'matte', 'label' => 'Matte', 'selected' => $default, 'disabled' => $disabled_default ],
				[ 'slug' => 'gloss', 'label' => 'Gloss' ],
			] ] ] ] );
			if ( $default && $disabled_default ) {
				// Exercise Renderer defensively with malformed/stale in-memory data,
				// after schema normalization correctly cleared this disabled default.
				$group->data['fields'][0]['choices'][0]['selected'] = true;
			}
			ob_start();
			Renderer::render_group( '17', 'Choices', $group, 10 );
			return (string) ob_get_clean();
		}

		public function test_required_select_without_default_starts_empty_and_has_linked_label(): void {
			$html = $this->markup( 'select', true, false );
			$this->assertStringContainsString( 'for="opf-17-finish"', $html );
			$this->assertStringContainsString( 'autocomplete="off" required>', $html );
			$this->assertStringContainsString( '<option value="">Choose an option</option>', $html );
		}

		public function test_optional_select_can_clear_a_default_and_required_default_stays_selected(): void {
			$this->assertStringContainsString( '<option value="">', $this->markup( 'select', false, true ) );
			$html = $this->markup( 'select', true, true );
			$this->assertStringNotContainsString( '<option value="">', $html );
			$this->assertStringContainsString( 'value="matte" selected="selected"', $html );
		}

		public function test_disabled_selected_default_does_not_bypass_required_select_prompt(): void {
			$html = $this->markup( 'select', true, true, true );
			$this->assertStringContainsString( '<option value="">Choose an option</option>', $html );
			$this->assertMatchesRegularExpression( '/<option value="matte"[^>]* disabled/', $html );
			$this->assertDoesNotMatchRegularExpression( '/<option value="matte"[^>]*selected/', $html );
		}

		public function test_disabled_selected_default_does_not_check_or_satisfy_required_radio(): void {
			$html = $this->markup( 'radio', true, true, true );
			$this->assertMatchesRegularExpression( '/value="matte"[^>]* disabled/', $html );
			$this->assertDoesNotMatchRegularExpression( '/value="matte"[^>]* checked/', $html );
			$this->assertStringContainsString( 'aria-required="true"', $html );
		}

		public function test_radio_group_has_a_name_and_native_required_exclusive_controls(): void {
			$html = $this->markup( 'radio', true, false );
			$this->assertStringContainsString( 'id="opf-label-17-finish"', $html );
			$this->assertStringContainsString( 'role="radiogroup" aria-labelledby="opf-label-17-finish" aria-required="true"', $html );
			$this->assertSame( 2, substr_count( $html, '<input type="radio"' ) );
			$this->assertSame( 3, substr_count( $html, 'name="opf[17][finish]"' ) );
			$this->assertSame( 2, substr_count( $html, ' required' ) );
		}
	}
}
