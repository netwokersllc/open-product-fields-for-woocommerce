<?php
/**
 * Field-group copy and ID/reference remapping tests.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroupDuplicator;
use PHPUnit\Framework\TestCase;

final class FieldGroupDuplicatorTest extends TestCase {

	public function test_duplicate_remaps_ids_conditions_and_formula_tokens(): void {
		$source = [
			'fields' => [
				[
					'id' => 'material', 'label' => 'Material', 'type' => 'checkbox', 'choices' => [],
					'pricing' => [ 'type' => 'fixed', 'amount' => 2 ], 'conditionals' => [],
				],
				[
					'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'choices' => [
						[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'formula', 'formula' => 'checked(material) + [price.material] + [field.material2]', 'formula_raw' => '[field.material]' ] ],
					],
					'pricing' => [ 'type' => 'formula', 'formula' => 'sumQty(material) + [field.finish]' ],
					'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'material', 'operator' => 'is', 'value' => 'wood' ] ] ] ],
				],
			],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '42' ] ] ] ] ],
		];
		$ids = [ 'material', 'copy-material', 'finish', 'copy-finish' ];
		$copy = FieldGroupDuplicator::duplicate( $source, static function () use ( &$ids ): string {
			return array_shift( $ids );
		} );

		$this->assertSame( [ 'copy-material', 'copy-finish' ], array_column( $copy['fields'], 'id' ) );
		$this->assertSame( 'copy-material', $copy['fields'][1]['conditionals'][0]['rules'][0]['field'] );
		$this->assertSame( 'checked(copy-material) + [price.copy-material] + [field.material2]', $copy['fields'][1]['choices'][0]['pricing']['formula'] );
		$this->assertSame( '[field.copy-material]', $copy['fields'][1]['choices'][0]['pricing']['formula_raw'] );
		$this->assertSame( 'sumQty(copy-material) + [field.copy-finish]', $copy['fields'][1]['pricing']['formula'] );
		$this->assertSame( [ 'material', 'finish' ], array_column( $source['fields'], 'id' ), 'Source data must remain unchanged.' );
		$this->assertSame( 'product', $copy['rule_groups'][0]['rules'][0]['subject'] );
	}

	public function test_generated_ids_skip_original_and_repeat_ids(): void {
		$source = [
			'fields' => [
				[ 'id' => 'a', 'label' => 'A' ],
				[ 'id' => 'b', 'label' => 'B' ],
			],
		];
		$ids = [ 'a', 'copy-a', 'copy-a', 'copy-b' ];
		$copy = FieldGroupDuplicator::duplicate( $source, static function () use ( &$ids ): string {
			return array_shift( $ids );
		} );

		$this->assertSame( [ 'copy-a', 'copy-b' ], array_column( $copy['fields'], 'id' ) );
	}

	public function test_rejects_repeated_source_field_ids(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroupDuplicator::duplicate( [ 'fields' => [ [ 'id' => 'same' ], [ 'id' => 'same' ] ] ], static fn(): string => 'new-id' );
	}

	public function test_default_id_factory_generates_unique_safe_ids(): void {
		$copy = FieldGroupDuplicator::duplicate( [ 'fields' => [ [ 'id' => 'a' ], [ 'id' => 'b' ] ] ] );
		$ids  = array_column( $copy['fields'], 'id' );

		$this->assertCount( 2, array_unique( $ids ) );
		$this->assertNotContains( 'a', $ids );
		$this->assertNotContains( 'b', $ids );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $ids[0] );
	}
}
