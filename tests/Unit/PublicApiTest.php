<?php
/** Public PHP API normalization contracts. */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class PublicApiTest extends TestCase {

	public function test_field_group_array_helpers_use_the_canonical_normalized_shape(): void {
		$group = opf_array_to_fieldgroup(
			[
				'fields' => [
					[ 'id' => 'name', 'type' => 'text', 'label' => 'Name', 'pricing' => [ 'type' => 'none' ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertInstanceOf( FieldGroup::class, $group );
		$this->assertSame( FieldGroup::SCHEMA, opf_fieldgroup_to_array( $group )['schema'] );
		$this->assertSame( 'name', opf_fieldgroup_to_array( $group )['fields'][0]['id'] );
		$this->assertSame( 'none', opf_fieldgroup_to_array( $group )['fields'][0]['pricing']['type'] );
	}
}
