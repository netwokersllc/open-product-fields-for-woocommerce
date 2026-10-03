<?php
/**
 * Minimum-platform declaration contract.
 *
 * WAPF (`advanced-product-fields-for-woocommerce-extended.php`) declares its
 * floor in plugin headers and guards behavior accordingly. OPF mirrors that
 * contract: the entry-point header, readme, runtime constants, activation
 * guard and admin notices must all agree. This test fails on drift.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MinimumPlatformTest extends TestCase {

	private function entry_point(): string {
		return (string) file_get_contents( OPF_DIR . 'open-product-fields-for-woocommerce.php' );
	}

	private function readme(): string {
		return (string) file_get_contents( OPF_DIR . 'readme.txt' );
	}

	/**
	 * @return array<string,string>
	 */
	private function headers( string $source ): array {
		$headers = [];
		foreach ( [ 'Requires at least', 'Requires PHP', 'WC requires at least' ] as $label ) {
			if ( preg_match( '/^[ \t*#]*' . preg_quote( $label, '/' ) . ':\s*([0-9][0-9.]*)\s*$/mi', $source, $m ) ) {
				$headers[ $label ] = $m[1];
			}
		}
		return $headers;
	}

	public function test_entry_point_declares_platform_floor(): void {
		$headers = $this->headers( $this->entry_point() );
		$this->assertArrayHasKey( 'Requires at least', $headers );
		$this->assertArrayHasKey( 'Requires PHP', $headers );
		$this->assertArrayHasKey( 'WC requires at least', $headers );
		$this->assertNotSame( '', $headers['Requires at least'] );
		$this->assertNotSame( '', $headers['Requires PHP'] );
		$this->assertNotSame( '', $headers['WC requires at least'] );
	}

	public function test_readme_matches_entry_point_floor(): void {
		$this->assertSame( $this->headers( $this->entry_point() ), $this->headers( $this->readme() ) );
	}

	public function test_runtime_constants_match_declared_headers(): void {
		$source  = $this->entry_point();
		$headers = $this->headers( $source );
		foreach ( [ 'OPF_MIN_WP' => 'Requires at least', 'OPF_MIN_PHP' => 'Requires PHP', 'OPF_MIN_WC' => 'WC requires at least' ] as $constant => $label ) {
			$this->assertMatchesRegularExpression(
				'/define\(\s*\'' . $constant . '\'\s*,\s*\'' . preg_quote( $headers[ $label ], '/' ) . '\'\s*\)/',
				$source,
				$constant . ' must equal the "' . $label . '" header.'
			);
		}
	}

	public function test_runtime_guards_and_notices_are_present(): void {
		$source = $this->entry_point();
		$this->assertStringContainsString( "version_compare( PHP_VERSION, OPF_MIN_PHP, '<' )", $source );
		$this->assertStringContainsString( "version_compare( \$opf_wp_version, OPF_MIN_WP, '<' )", $source );
		$this->assertStringContainsString( "version_compare( (string) WC_VERSION, OPF_MIN_WC, '<' )", $source );
		$this->assertStringContainsString( "'admin_notices', 'opf_php_version_notice'", $source );
		$this->assertStringContainsString( "'admin_notices', 'opf_wp_version_notice'", $source );
		$this->assertStringContainsString( "'admin_notices', 'opf_wc_version_notice'", $source );
	}

	/**
	 * Unsupported platforms must neither fatal nor activate: the entry point
	 * returns before requiring plugin classes, and activation calls wp_die().
	 */
	public function test_unsupported_platform_fails_gracefully_not_fatally(): void {
		$source = $this->entry_point();
		$this->assertMatchesRegularExpression(
			'/version_compare\( PHP_VERSION, OPF_MIN_PHP, \'<\' \).*?return;/s',
			$source
		);
		$this->assertMatchesRegularExpression(
			'/function opf_activate\(\).*?wp_die\(/s',
			$source
		);
	}
}
