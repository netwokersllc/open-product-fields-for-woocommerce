<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CartIntegrationImageQuantityTest extends TestCase {
	public function test_aggregate_quantity_limits_use_the_sum_without_clamping_each_choice(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'prints', 'label' => 'Prints', 'type' => 'image_quantity',
			'min_choices' => 3, 'max_choices' => 8,
			'choices' => [
				[ 'slug' => 'oak', 'label' => 'Oak', 'quantity' => [ 'min' => 0, 'max' => 12 ] ],
				[ 'slug' => 'ash', 'label' => 'Ash', 'quantity' => [ 'min' => 0, 'max' => 12 ] ],
			],
		] );
		$method = new ReflectionMethod( CartIntegration::class, 'validate_image_quantity' );

		$this->assertSame( [], $method->invoke( null, $field, [
			'_opf_type' => 'image_quantity', 'quantities' => [ 'oak' => 8, 'ash' => 0 ], 'invalid' => [],
		] ) );
		$this->assertSame( [ '"Prints" requires at least 3 total items.' ], $method->invoke( null, $field, [
			'_opf_type' => 'image_quantity', 'quantities' => [ 'oak' => 1, 'ash' => 1 ], 'invalid' => [],
		] ) );
		$this->assertSame( [ '"Prints" allows at most 8 total items.' ], $method->invoke( null, $field, [
			'_opf_type' => 'image_quantity', 'quantities' => [ 'oak' => 5, 'ash' => 4 ], 'invalid' => [],
		] ) );
	}
}
