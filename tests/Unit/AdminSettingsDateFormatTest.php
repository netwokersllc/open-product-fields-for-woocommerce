<?php

namespace OPF\Tests\Unit;

use OPF\Service\Admin\Settings;
use PHPUnit\Framework\TestCase;

final class AdminSettingsDateFormatTest extends TestCase {
	private $previous_options;

	protected function setUp(): void {
		$this->previous_options = $GLOBALS['opf_test_options'] ?? [];
		$GLOBALS['opf_test_options'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['opf_test_options'] = $this->previous_options;
	}

	public function test_invalid_submission_preserves_valid_wapf_when_stored_opf_is_invalid(): void {
		$GLOBALS['opf_test_options'] = [ 'opf_date_format' => 'garbage', 'wapf_date_format' => 'DD/MM/YY' ];
		$this->assertSame( 'dd/mm/yy', Settings::sanitize_date_format( 'invalid-posted-format' ) );
	}

	public function test_invalid_submission_uses_default_when_both_stored_formats_are_invalid(): void {
		$GLOBALS['opf_test_options'] = [ 'opf_date_format' => 'garbage', 'wapf_date_format' => 'not-a-format' ];
		$this->assertSame( 'mm-dd-yyyy', Settings::sanitize_date_format( 'invalid-posted-format' ) );
	}

	public function test_valid_submission_wins_over_stored_formats(): void {
		$GLOBALS['opf_test_options'] = [ 'opf_date_format' => 'mm-dd-yyyy', 'wapf_date_format' => 'dd/mm/yy' ];
		$this->assertSame( 'yyyy.mm.dd', Settings::sanitize_date_format( 'YYYY.MM.DD' ) );
	}

	public function test_invalid_submission_preserves_valid_stored_opf_precedence(): void {
		$GLOBALS['opf_test_options'] = [ 'opf_date_format' => 'YYYY.MM.DD', 'wapf_date_format' => 'dd/mm/yy' ];
		$this->assertSame( 'yyyy.mm.dd', Settings::sanitize_date_format( [ 'invalid' ] ) );
	}
}
