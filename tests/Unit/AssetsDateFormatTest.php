<?php

namespace {
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $name, $value, ...$args ) { return $value; }
	}
}

namespace OPF\Service {
	function wp_enqueue_script( ...$args ): void {}
	function wp_enqueue_style( ...$args ): void {}
	function wp_print_inline_script_tag( $javascript, $attributes = [] ): void {
		$GLOBALS['opf_inline_scripts'][] = $javascript;
	}
}

namespace OPF\Tests\Unit {
	require_once OPF_DIR . 'includes/Service/Assets.php';

	use OPF\Service\Assets;
	use PHPUnit\Framework\TestCase;

	final class AssetsDateFormatTest extends TestCase {
		private $previous_options;
		private $previous_filters;
		private $previous_woocs;

		protected function setUp(): void {
			$this->previous_options = $GLOBALS['opf_test_options'] ?? [];
			$this->previous_filters = $GLOBALS['opf_wpml_filters'] ?? [];
			$this->previous_woocs   = $GLOBALS['WOOCS'] ?? null;
			$GLOBALS['opf_test_options'] = [ 'opf_theme_compat' => 'no' ];
			$GLOBALS['opf_wpml_filters'] = [];
			unset( $GLOBALS['WOOCS'] );
		}

		protected function tearDown(): void {
			$GLOBALS['opf_test_options'] = $this->previous_options;
			$GLOBALS['opf_wpml_filters'] = $this->previous_filters;
			if ( null !== $this->previous_woocs ) {
				$GLOBALS['WOOCS'] = $this->previous_woocs;
			}
			unset( $GLOBALS['opf_inline_scripts'] );
		}

		private function printed_date_format(): ?string {
			$GLOBALS['opf_inline_scripts'] = [];
			Assets::enqueue_frontend( [ 'group' => [ [ 'id' => 'dob', 'type' => 'date' ] ] ] );
			foreach ( $GLOBALS['opf_inline_scripts'] as $script ) {
				if ( preg_match( '/window\.OPF_DATE_FORMAT = (".*?"|[^;]+);/', $script, $match ) ) {
					return json_decode( $match[1], true );
				}
			}
			return null;
		}

		public function test_valid_opf_option_takes_precedence_over_valid_wapf_option(): void {
			$GLOBALS['opf_test_options']['opf_date_format']  = 'dd/mm/yy';
			$GLOBALS['opf_test_options']['wapf_date_format'] = 'yyyy.mm.dd';
			$this->assertSame( 'dd/mm/yy', $this->printed_date_format() );
		}

		public function test_valid_wapf_option_is_the_fallback_when_opf_is_absent_or_invalid(): void {
			$GLOBALS['opf_test_options']['wapf_date_format'] = 'DD/MM/YY';
			$this->assertSame( 'dd/mm/yy', $this->printed_date_format() );

			$GLOBALS['opf_test_options']['opf_date_format'] = 'd/m/yyyy<script>';
			$this->assertSame( 'dd/mm/yy', $this->printed_date_format() );

			$GLOBALS['opf_test_options']['opf_date_format'] = [ 'array' ];
			$this->assertSame( 'dd/mm/yy', $this->printed_date_format() );
		}

		public function test_default_format_when_both_options_are_absent_or_invalid(): void {
			$this->assertSame( 'mm-dd-yyyy', $this->printed_date_format() );

			$GLOBALS['opf_test_options']['opf_date_format']  = 'garbage';
			$GLOBALS['opf_test_options']['wapf_date_format'] = 'not-a-format';
			$this->assertSame( 'mm-dd-yyyy', $this->printed_date_format() );
		}

		public function test_valid_opf_option_alone_is_normalized_and_emitted(): void {
			$GLOBALS['opf_test_options']['opf_date_format'] = 'YYYY-MM-DD';
			$this->assertSame( 'yyyy-mm-dd', $this->printed_date_format() );
		}
	}
}
