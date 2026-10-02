<?php

namespace {
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	}
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = null ): string { return (string) $text; }
	}
}

namespace OPF\Tests\Unit {
	use OPF\Engine\Calculator;
	use OPF\Engine\FieldGroup;
	use OPF\Service\Assets;
	use OPF\Service\CartIntegration;
	use OPF\Service\Renderer;
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;

	final class DateFormatFallbackTest extends TestCase {
		#[DataProvider( 'formats' )]
		public function test_all_surfaces_resolve_the_same_valid_format( array $options, string $format, string $display ): void {
			$previous_options = $GLOBALS['opf_test_options'] ?? [];
			$GLOBALS['opf_test_options'] = $options;
			try {
				$assets = new \ReflectionMethod( Assets::class, 'frontend_date_format' );
				$this->assertSame( $format, $assets->invoke( null ) );

				$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'date', 'label' => 'Date', 'type' => 'date' ] ] ] );
				ob_start();
				try {
					Renderer::render_group( '17', 'Date', $group, 10.0 );
					$html = (string) ob_get_contents();
				} finally {
					ob_end_clean();
				}
				$this->assertStringContainsString( 'data-opf-date-format="' . $format . '"', $html );

				$cart = new \ReflectionMethod( CartIntegration::class, 'display_value' );
				$this->assertSame( $display, $cart->invoke( null, [ 'type' => 'date' ], '2026-10-15' ) );
				$this->assertSame( 4.0, Calculator::evaluate_formula( 'dow([val])', 10.0, 1, 0.0, $display ) );
				$this->assertSame( 10.0, Calculator::evaluate_formula( 'month([val])', 10.0, 1, 0.0, $display ) );
				$this->assertSame( 4.0, Calculator::evaluate_formula( 'dow([val])', 10.0, 1, 0.0, '2026-10-15' ) );
				$this->assertSame( 0.0, Calculator::evaluate_formula( "month('2026-02-30')", 10.0, 1, 0.0 ) );
			} finally {
				$GLOBALS['opf_test_options'] = $previous_options;
			}
		}

		public static function formats(): array {
			return [
				'invalid OPF, valid WAPF' => [ [ 'opf_date_format' => 'garbage', 'wapf_date_format' => 'DD/MM/YY' ], 'dd/mm/yy', '15/10/26' ],
				'array OPF, valid WAPF' => [ [ 'opf_date_format' => [ 'invalid' ], 'wapf_date_format' => 'dd/mm/yy' ], 'dd/mm/yy', '15/10/26' ],
				'empty OPF, valid WAPF' => [ [ 'opf_date_format' => '', 'wapf_date_format' => 'dd/mm/yy' ], 'dd/mm/yy', '15/10/26' ],
				'absent OPF, valid WAPF' => [ [ 'wapf_date_format' => 'dd/mm/yy' ], 'dd/mm/yy', '15/10/26' ],
				'both invalid' => [ [ 'opf_date_format' => 'garbage', 'wapf_date_format' => 'not-a-format' ], 'mm-dd-yyyy', '10-15-2026' ],
				'both absent' => [ [], 'mm-dd-yyyy', '10-15-2026' ],
				'valid OPF wins' => [ [ 'opf_date_format' => 'YYYY.MM.DD', 'wapf_date_format' => 'dd/mm/yy' ], 'yyyy.mm.dd', '2026.10.15' ],
			];
		}
	}
}
