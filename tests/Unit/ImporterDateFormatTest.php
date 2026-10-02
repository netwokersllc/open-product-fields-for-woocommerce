<?php

namespace OPF\Service {
	function update_option( string $key, $value ): bool {
		$GLOBALS['opf_date_format_writes'][] = [ $key, $value ];
		$GLOBALS['opf_test_options'][ $key ] = $value;
		return true;
	}
}

namespace OPF\Tests\Unit {
	require_once OPF_DIR . 'includes/Service/Importer.php';

	use OPF\Service\Importer;
	use PHPUnit\Framework\TestCase;

	final class ImporterDateFormatTest extends TestCase {
		private $previous_options;
		private $previous_db;

		protected function setUp(): void {
			$this->previous_options = $GLOBALS['opf_test_options'] ?? [];
			$this->previous_db = $GLOBALS['wpdb'] ?? null;
			$GLOBALS['opf_test_options'] = [];
			$GLOBALS['opf_date_format_writes'] = [];
			$GLOBALS['wpdb'] = new class {
				public $posts = 'wp_posts';
				public $postmeta = 'wp_postmeta';
				public function get_results(): array { return []; }
				public function get_col(): array { return []; }
			};
		}

		protected function tearDown(): void {
			$GLOBALS['opf_test_options'] = $this->previous_options;
			$GLOBALS['wpdb'] = $this->previous_db;
			unset( $GLOBALS['opf_date_format_writes'] );
		}

		public function test_dry_run_reports_valid_wapf_format_without_writing(): void {
			$GLOBALS['opf_test_options']['wapf_date_format'] = 'DD/MM/YY';
			$this->assertSame( [ 'result' => 'would-import', 'value' => 'dd/mm/yy' ], Importer::run()['date_format'] );
			$this->assertSame( [], $GLOBALS['opf_date_format_writes'] );
		}

		public function test_commit_copies_source_once_and_preserves_explicit_opf_value(): void {
			$GLOBALS['opf_test_options']['wapf_date_format'] = 'yyyy.mm.dd';
			$this->assertSame( [ 'result' => 'imported', 'value' => 'yyyy.mm.dd' ], Importer::run( true )['date_format'] );
			$this->assertSame( [ [ 'opf_date_format', 'yyyy.mm.dd' ] ], $GLOBALS['opf_date_format_writes'] );
			$GLOBALS['opf_test_options']['wapf_date_format'] = 'd/m/yy';
			$this->assertSame( [ 'result' => 'already-configured', 'value' => 'yyyy.mm.dd' ], Importer::run( true )['date_format'] );
			$this->assertCount( 1, $GLOBALS['opf_date_format_writes'] );
		}

		public function test_absent_and_invalid_source_are_not_written(): void {
			$this->assertSame( [ 'result' => 'source-absent' ], Importer::run( true )['date_format'] );
			$GLOBALS['opf_test_options']['wapf_date_format'] = 'd/m/yyyy<script>';
			$this->assertSame( 'invalid-source', Importer::run( true )['date_format']['result'] );
			$this->assertSame( [], $GLOBALS['opf_date_format_writes'] );
		}
	}
}
