<?php
/** Lookup-table CSV formats and validation. */

namespace OPF\Tests\Unit;

use OPF\Engine\LookupTableCsv;
use PHPUnit\Framework\TestCase;

final class LookupTableCsvTest extends TestCase {
	public function test_grid_uses_top_row_values_as_the_first_formula_dimension(): void {
		$table = LookupTableCsv::parse( "your_table_name,200,220\n100,56,60\n120,62,66\n", 'ignored.csv' );
		$this->assertSame( 'your_table_name', $table['name'] );
		$this->assertSame( 2, $table['dimensions'] );
		$this->assertSame( 66.0, $table['table'][220][120] );
	}

	public function test_grid_can_use_the_csv_filename_when_a1_is_empty(): void {
		$table = LookupTableCsv::parse( ",200,220\n100,56,60\n120,62,66\n", 'blind_prices.csv' );
		$this->assertSame( 'blind_prices', $table['name'] );
		$this->assertSame( 56.0, $table['table'][200][100] );
	}

	public function test_list_imports_three_or_more_dimensions_and_rejects_duplicate_tuples(): void {
		$table = LookupTableCsv::parse( "500,4x6,Glossy,30\n500,4x6,Matte,34\n1000,4x6,Glossy,35\n", 'postcards.csv' );
		$this->assertSame( 'postcards', $table['name'] );
		$this->assertSame( 3, $table['dimensions'] );
		$this->assertSame( 34.0, $table['table'][500]['4x6']['Matte'] );
		$this->expectException( \InvalidArgumentException::class );
		LookupTableCsv::parse( "500,Glossy,30\n500,Glossy,31\n", 'duplicate.csv' );
	}

	public function test_wapf_combination_list_skips_its_field_headers_and_price_header(): void {
		$csv = "Quantity,Paper size,Paper Type,Price\n500,4x6,Glossy,30\n500,4x6,Matte,34\n1000,8x10,Matte,62\n";
		$table = LookupTableCsv::parse( $csv, 'postcards.csv' );
		$this->assertSame( 3, $table['dimensions'] );
		$this->assertSame( 34.0, $table['table'][500]['4x6']['Matte'] );
		$this->assertSame( 62.0, $table['table'][1000]['8x10']['Matte'] );
	}

	public function test_explicit_list_layout_supports_text_values_that_look_like_table_names(): void {
		$table = LookupTableCsv::parse( "red,200,100,12\nblue,200,100,14\n", 'colors.csv', 'list' );
		$this->assertSame( 3, $table['dimensions'] );
		$this->assertSame( 14.0, $table['table']['blue'][200][100] );
	}

	public function test_imported_tables_persist_replace_and_delete_without_exposing_unreferenced_data(): void {
		$this->assertTrue( \OPF\Engine\LookupTables::save_imported( 'csv_prices', [ 200 => [ 100 => 42 ] ] ) );
		$this->assertSame( 42.0, \OPF\Engine\LookupTables::lookup( 'csv_prices', [ 'width', 'height' ], [ 'width' => '200', 'height' => '100' ] ) );
		$registry = [ 'field' => [ 'pricing' => [ 'formula' => 'lookuptable(csv_prices; width; height)' ] ] ];
		$this->assertArrayHasKey( 'csv_prices', \OPF\Engine\LookupTables::for_registry( $registry ) );
		$this->assertArrayNotHasKey( 'csv_prices', \OPF\Engine\LookupTables::for_registry( [] ) );
		$this->assertTrue( \OPF\Engine\LookupTables::save_imported( 'csv_prices', [ 200 => [ 100 => 55 ] ] ) );
		$this->assertSame( 55.0, \OPF\Engine\LookupTables::lookup( 'csv_prices', [ 'width', 'height' ], [ 'width' => '200', 'height' => '100' ] ) );
		$this->assertTrue( \OPF\Engine\LookupTables::delete_imported( 'csv_prices' ) );
		$this->assertNull( \OPF\Engine\LookupTables::lookup( 'csv_prices', [ 'width', 'height' ], [ 'width' => '200', 'height' => '100' ] ) );
	}

	public function test_rejects_ragged_rows_blank_cells_and_invalid_prices(): void {
		foreach ( [
			"table,200,220\n100,56\n",
			"table,200,220\n100,56,\n",
			"table,200,220\n100,56,not-a-price\n",
		] as $csv ) {
			try {
				LookupTableCsv::parse( $csv, 'prices.csv' );
				$this->fail( 'Invalid CSV should be rejected.' );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertNotSame( '', $error->getMessage() );
			}
		}
	}
}
