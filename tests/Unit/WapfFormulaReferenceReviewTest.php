<?php
namespace OPF\Tests\Unit;

use OPF\Engine\WapfMapper;
use PHPUnit\Framework\TestCase;

final class WapfFormulaReferenceReviewTest extends TestCase {
	private function group( array $target, string $formula, bool $scalar = false ): array {
		$consumer = [ 'id' => 'fee-source', 'label' => 'Fee', 'type' => $scalar ? 'text' : 'select' ];
		if ( $scalar ) {
			$consumer['pricing'] = [ 'enabled' => true, 'type' => 'fx', 'amount' => $formula ];
		} else {
			$consumer['options'] = [ 'choices' => [ [ 'slug' => 'selected', 'label' => 'Selected', 'pricing_type' => 'fx', 'pricing_amount' => $formula ] ] ];
		}
		return [ 'fields' => [ $consumer, $target + [ 'id' => 'prints-source', 'label' => 'Prints' ] ] ];
	}

	public function test_sumqty_over_mapped_image_quantities_has_no_formula_review_for_choice_or_scalar(): void {
		foreach ( [ false, true ] as $scalar ) {
			$mapped = WapfMapper::map( $this->group( [ 'type' => 'image-swatch-qty' ], 'sumQty(prints-source)*[qty]', $scalar ) );
			$pricing = $scalar ? $mapped['group']['fields'][0]['pricing'] : $mapped['group']['fields'][0]['choices'][0]['pricing'];
			$this->assertSame( 'image_quantity', $mapped['group']['fields'][1]['type'] );
			$this->assertSame( 'sumQty(prints)', $pricing['formula'] );
			$this->assertSame( 'sumQty(prints)*[qty]', $pricing['formula_raw'] );
			$this->assertTrue( $pricing['per_unit'] );
			$this->assertCount( 1, $mapped['notes'] );
			$this->assertStringContainsString( 'choice media references are imported', $mapped['notes'][0] );
			$this->assertTrue( $mapped['needs_review'], 'The independent image-media review must remain.' );
		}
	}

	public function test_sumqty_over_other_mapped_types_stays_review_required_with_correct_consumer_kind(): void {
		foreach ( [ 'number', 'text', 'checkboxes', 'multi-image-swatch' ] as $type ) {
			foreach ( [ false, true ] as $scalar ) {
				$mapped = WapfMapper::map( $this->group( [ 'type' => $type ], 'sumQty(prints-source)', $scalar ) );
				$kind = $scalar ? 'field "Fee"' : 'choice "Selected"';
				$this->assertTrue( $mapped['needs_review'] );
				$this->assertContains( $kind . ' formula contains references that require runtime review (sumqty(prints)); pricing needs review.', $mapped['notes'] );
			}
		}
	}

	public function test_files_stays_review_required_even_when_target_is_image_quantity(): void {
		$mapped = WapfMapper::map( $this->group( [ 'type' => 'image-swatch-qty' ], 'files(prints-source)' ) );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( 'files(prints)', $mapped['group']['fields'][0]['choices'][0]['pricing']['formula'] );
		$this->assertContains( 'choice "Selected" formula contains references that require runtime review (files(prints)); pricing needs review.', $mapped['notes'] );
	}

	public function test_cloned_quantity_target_is_rejected_by_existing_schema(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Image quantity fields cannot repeat.' );
		WapfMapper::map( $this->group( [ 'type' => 'image-swatch-qty', 'clone' => [ 'enabled' => true, 'type' => 'qty' ] ], 'sumQty(prints-source)' ) );
	}

	public function test_sumqty_inside_repeated_section_keeps_review(): void {
		$group = $this->group( [ 'type' => 'image-swatch-qty' ], 'sumQty(prints-source)' );
		array_splice( $group['fields'], 1, 0, [ [ 'id' => 'section', 'label' => 'Section', 'type' => 'section', 'clone' => [ 'enabled' => true, 'type' => 'qty' ] ], [ 'id' => 'nested', 'type' => 'section' ] ] );
		$group['fields'][] = [ 'id' => 'nested-end', 'type' => 'sectionend' ];
		$group['fields'][] = [ 'id' => 'end', 'type' => 'sectionend' ];
		$mapped = WapfMapper::map( $group );
		$this->assertContains( 'choice "Selected" formula contains references that require runtime review (sumqty(prints)); pricing needs review.', $mapped['notes'] );
	}

	public function test_repeated_consumer_of_plain_quantity_target_keeps_formula_review(): void {
		foreach ( [ 'field', 'section' ] as $scope ) {
			$group = $this->group( [ 'type' => 'image-swatch-qty' ], 'sumQty(prints-source)' );
			if ( 'field' === $scope ) {
				$group['fields'][0]['clone'] = [ 'enabled' => true, 'type' => 'qty' ];
			} else {
				array_unshift( $group['fields'], [ 'id' => 'section', 'type' => 'section', 'clone' => [ 'enabled' => true, 'type' => 'qty' ] ] );
				array_splice( $group['fields'], 2, 0, [ [ 'id' => 'end', 'type' => 'sectionend' ] ] );
			}
			$mapped = WapfMapper::map( $group );
			$this->assertContains( 'choice "Selected" formula contains references that require runtime review (sumqty(prints)); pricing needs review.', $mapped['notes'] );
		}
	}

	public function test_missing_and_duplicate_targets_drop_choice_pricing_with_choice_diagnostic(): void {
		foreach ( [ false, true ] as $duplicate ) {
			$group = $this->group( [ 'type' => 'image-swatch-qty' ], 'sumQty(' . ( $duplicate ? 'prints-source' : 'missing' ) . ')' );
			if ( $duplicate ) { $group['fields'][] = $group['fields'][1]; }
			$mapped = WapfMapper::map( $group );
			$this->assertSame( 'none', $mapped['group']['fields'][0]['choices'][0]['pricing']['type'] );
			$this->assertContains( 'choice "Selected" formula references unavailable or ambiguous WAPF field IDs (' . ( $duplicate ? 'prints-source' : 'missing' ) . '); pricing needs review.', $mapped['notes'] );
		}
	}

	public function test_supported_then_unsupported_duplicate_ids_make_references_ambiguous(): void {
		$this->assert_unsupported_duplicate_is_ambiguous( false );
	}

	public function test_unsupported_then_supported_duplicate_ids_make_references_ambiguous(): void {
		$this->assert_unsupported_duplicate_is_ambiguous( true );
	}

	private function assert_unsupported_duplicate_is_ambiguous( bool $unsupported_first ): void {
		foreach ( [ false, true ] as $scalar ) {
			foreach ( [ 'sumQty(prints-source)', 'files(prints-source)', 'checked(prints-source)', '[field.prints-source]', '[price.prints-source]' ] as $formula ) {
				$group = $this->group( [ 'type' => 'image-swatch-qty' ], $formula, $scalar );
				array_splice( $group['fields'], $unsupported_first ? 1 : 2, 0, [ [ 'id' => 'prints-source', 'type' => 'unsupported-type', 'label' => 'Unsupported duplicate' ] ] );
				$mapped = WapfMapper::map( $group );
				$pricing = $scalar ? $mapped['group']['fields'][0]['pricing'] : $mapped['group']['fields'][0]['choices'][0]['pricing'];
				$this->assertTrue( $mapped['needs_review'] );
				$this->assertSame( 'none', $pricing['type'], $formula );
				$this->assertCount( 2, $mapped['group']['fields'] );
				$this->assertContains( 'WAPF field ID "prints-source" is duplicated; conditions referencing it need review.', $mapped['notes'] );
				$this->assertContains( ( $scalar ? 'field "Fee"' : 'choice "Selected"' ) . ' formula references unavailable or ambiguous WAPF field IDs (prints-source); pricing needs review.', $mapped['notes'] );
			}
		}
	}
}
