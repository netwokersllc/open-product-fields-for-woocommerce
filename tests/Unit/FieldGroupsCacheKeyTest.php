<?php
/**
 * User-context product-group cache isolation tests.
 */

namespace OPF\Tests\Unit;

use OPF\Service\FieldGroups;
use PHPUnit\Framework\TestCase;

final class FieldGroupsCacheKeyTest extends TestCase {

	private function cache_key( int $product_id, array $context, string $group_language ): string {
		$invoke = \Closure::bind(
			static fn ( int $id, array $viewer, string $language ): string => FieldGroups::cache_key_for_viewer( $id, $viewer, $language ),
			null,
			FieldGroups::class
		);
		return $invoke( $product_id, $context, $group_language );
	}

	public function test_viewer_context_changes_product_group_cache_key(): void {
		$base = [ 'logged_in' => true, 'roles' => [ 'wholesale', 'customer' ], 'language' => 'fr_FR' ];
		$key = $this->cache_key( 42, $base, 'fr' );

		$this->assertSame( $key, $this->cache_key( 42, [ 'logged_in' => true, 'roles' => [ 'customer', 'wholesale' ], 'language' => 'fr_FR' ], 'fr' ) );
		$this->assertNotSame( $key, $this->cache_key( 42, [ 'logged_in' => false, 'roles' => [], 'language' => 'fr_FR' ], 'fr' ) );
		$this->assertNotSame( $key, $this->cache_key( 42, [ 'logged_in' => true, 'roles' => [ 'customer' ], 'language' => 'fr_FR' ], 'fr' ) );
		$this->assertNotSame( $key, $this->cache_key( 42, [ 'logged_in' => true, 'roles' => [ 'wholesale', 'customer' ], 'language' => 'nl_NL' ], 'fr' ) );
		$this->assertNotSame( $key, $this->cache_key( 42, $base, 'nl' ) );
	}
}
