<?php

namespace OPF\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FieldGroupsCacheInvalidationTest extends TestCase {

	public static function unsupported_caches(): array {
		return [
			'WordPress 6.0 APIs absent' => [ 'missing' ],
			'drop-in without group-flush support' => [ 'unsupported' ],
			'legacy drop-in without capability API' => [ 'legacy' ],
		];
	}

	#[DataProvider( 'unsupported_caches' )]
	public function test_unsupported_cache_ignores_stale_entries_after_save_and_duplicate( string $mode ): void {
		$result = $this->probe( $mode );
		$this->assertSame( [ 'Current group' ], $result['before'] );
		$this->assertSame( [ 'Saved group' ], $result['saved'] );
		$this->assertSame( [ 'Saved group', 'Copied group' ], $result['copied'] );
		$this->assertSame( 'untouched', $result['foreign'] );
		$this->assertTrue( $result['legacy_entry_retained'] );
		$this->assertSame( 1, $result['reads_before_save'] );
		$this->assertSame( 3, $result['post_reads'] );
		$this->assertSame( 0, $result['cache_reads'] );
		$this->assertSame( 0, $result['cache_writes'] );
		$this->assertSame( [], $result['flushed_groups'] );
		if ( 'missing' === $mode ) {
			$this->assertFalse( $result['group_flush_api'] );
			$this->assertFalse( $result['supports_api'] );
		}
	}

	public function test_supported_cache_keeps_persistent_reads_and_flushes_only_opf_results(): void {
		$result = $this->probe( 'supported' );
		$this->assertSame( [ 'Legacy stale group' ], $result['before'] );
		$this->assertSame( [ 'Saved group' ], $result['saved'] );
		$this->assertSame( [ 'Saved group', 'Copied group' ], $result['copied'] );
		$this->assertSame( 'untouched', $result['foreign'] );
		$this->assertFalse( $result['legacy_entry_retained'] );
		$this->assertSame( 2, $result['post_reads'] );
		$this->assertSame( 4, $result['cache_reads'] );
		$this->assertSame( 2, $result['cache_writes'] );
		$this->assertSame( [ 'opf_groups_for_product', 'opf_groups_for_product' ], $result['flushed_groups'] );
	}

	private function probe( string $mode ): array {
		$process = proc_open(
			[ PHP_BINARY, OPF_DIR . 'tests/fixtures/group-cache-floor.php', $mode ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes
		);
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$this->assertSame( '', $error );
		$result = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		$this->assertIsArray( $result );
		return $result;
	}
}
