<?php
/**
 * Keep the public capability matrix aligned with the canonical field registry.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class DocumentationCapabilitiesTest extends TestCase {

	public function test_capability_matrix_lists_only_registered_field_types(): void {
		$path = OPF_DIR . 'docs/CAPABILITIES.md';
		$this->assertFileExists( $path );

		$contents = (string) file_get_contents( $path );
		$this->assertMatchesRegularExpression(
			'/\| Field types \| (?<types>.+) \|/',
			$contents
		);

		preg_match( '/\| Field types \| (?<types>.+) \|/', $contents, $matches );
		preg_match_all( '/`([^`]+)`/', $matches['types'], $documented );

		$this->assertSame( FieldGroup::FIELD_TYPES, $documented[1] );
	}
}
