<?php
namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class FormulaCartPricingTest extends TestCase {
	protected function setUp(): void {
		eval( 'namespace OPF\\Service; class FieldGroups {
			public static function for_product($product) { return [["id" => "g", "group" => $GLOBALS["opf_formula_group"]]]; }
		}' );
		eval( 'function apply_filters($tag, $value, ...$args) { return $value; }
		class WC_Product {
			public $price = 10.0;
			public function get_id() { return 42; }
			public function get_price($context = "view") { return $this->price; }
			public function set_price($price) { $this->price = (float) $price; }
		}
		class WC_Cart { public $items = []; public function get_cart() { return $this->items; } }' );
	}

	public function test_cart_retains_discount_context_and_floors_only_final_product_price(): void {
		foreach ( [ [ 'min(-5;0)', 5.0 ], [ 'min(-20;0)', 0.0 ] ] as [ $formula, $expected ] ) {
			$GLOBALS['opf_formula_group'] = new FieldGroup( [ 'schema' => FieldGroup::SCHEMA, 'fields' => [ [
				'id' => 'discount', 'type' => 'text', 'pricing' => [ 'type' => 'formula', 'formula' => $formula, 'per_unit' => true ],
			] ] ] );
			$product = new \WC_Product();
			$plain = new \WC_Product();
			$cart = new \WC_Cart();
			$cart->items = [ [ 'data' => $product, 'quantity' => 3, 'opf_base_price' => 10, CartIntegration::ITEM_KEY => [ 'g' => [ 'discount' => 'yes' ] ] ], [ 'data' => $plain ] ];
			CartIntegration::apply_prices( $cart );
			$this->assertSame( $expected, $product->get_price() );
			$this->assertSame( 10.0, $plain->get_price() );
			CartIntegration::apply_prices( $cart );
			$this->assertSame( $expected, $product->get_price(), 'Repricing must not compound the discount.' );
		}
	}

	public function test_later_formula_receives_signed_addons_and_field_prices(): void {
		foreach ( [ '[addons]+7', '[price.discount]+7' ] as $formula ) {
			$GLOBALS['opf_formula_group'] = new FieldGroup( [ 'schema' => FieldGroup::SCHEMA, 'fields' => [
				[ 'id' => 'discount', 'type' => 'text', 'pricing' => [ 'type' => 'formula', 'formula' => 'min(-5;0)', 'per_unit' => true ] ],
				[ 'id' => 'fee', 'type' => 'text', 'pricing' => [ 'type' => 'formula', 'formula' => $formula, 'per_unit' => true ] ],
			] ] );
			$this->assertSame( -3.0, CartIntegration::addons_per_unit( new \WC_Product(), [ 'g' => [ 'discount' => 'yes', 'fee' => 'yes' ] ], 10, 3 ) );
		}
	}
}
