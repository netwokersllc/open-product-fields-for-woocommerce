<?php
use OPF\API;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversMethod( API::class, 'get_options_from_order' )]
final class ApiOrderOptionsTest extends TestCase {
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_order_snapshot_preserves_public_option_shape(): void {
		eval( 'class WC_Order { public function get_items($type) { return array(new OPF_Test_Order_Item()); } }
			class OPF_Test_Order_Item {
				public function get_meta($key, $single) {
					if ( "_opf_fields" === $key ) return array("details" => array("engraving" => "Ada"));
					if ( "_opf_fields_snapshot" === $key ) return array(
						array("id" => "engraving", "label" => "Engraving", "value" => "Ada", "type" => "text"),
						array("id" => "note", "value" => null),
						"ignored"
					);
					return null;
				}
				public function get_product() { return null; }
				public function get_id() { return 42; }
				public function get_quantity() { return 3; }
			}' );

		$this->assertSame(
			[
				[
					'product_id' => false,
					'item_id' => 42,
					'quantity' => 3,
					'options' => [
						[ 'field_id' => 'engraving', 'label' => 'Engraving', 'value' => 'Ada', 'type' => 'text' ],
						[ 'field_id' => 'note', 'label' => 'note', 'value' => null, 'type' => '' ],
					],
				],
			],
			API::get_options_from_order( new \WC_Order() )
		);
	}
}
