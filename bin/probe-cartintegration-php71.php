<?php
/** Isolated callback compatibility checks; adapters are not commerce proof. */
namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	class WC_Product {
		public function get_price( $context = 'view' ) { return '20'; }
	}
	class WC_Order {}
	class WC_Order_Item_Product {
		private $fields;
		public function __construct( array $fields ) { $this->fields = $fields; }
		public function get_meta( $key, $single ) { return json_encode( $this->fields ); }
		public function get_quantity() { return 2; }
		public function get_product() { return new WC_Product(); }
	}
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
	function wp_list_pluck( $items, $key ) { return array_column( $items, $key ); }
}
namespace OPF\Service {
	class FieldGroups {
		public static function for_product( $product ) { return $GLOBALS['opf_probe_groups']; }
	}
}
namespace {
	require ABSPATH . 'includes/Engine/FieldValue.php';
	require ABSPATH . 'includes/Engine/RepeaterField.php';
	require ABSPATH . 'includes/Service/Uploads.php';
	require ABSPATH . 'includes/Service/CartIntegration.php';
	$repeat = [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ];
	$fields = [
		[ 'id' => 'email', 'type' => 'email', 'repeat' => $repeat ],
		[ 'id' => 'text', 'type' => 'text', 'repeat' => $repeat ],
		[ 'id' => 'section', 'type' => 'section', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ],
		[ 'id' => 'quantity_email', 'type' => 'email' ],
		[ 'id' => 'end', 'type' => 'section_end' ],
		[ 'id' => 'upload', 'type' => 'upload' ],
		[ 'id' => 'image', 'type' => 'image_quantity', 'choices' => [ [ 'slug' => 'oak', 'quantity' => [ 'min' => 0, 'max' => 3 ] ] ] ],
	];
	$group = new stdClass();
	$group->data = [ 'fields' => $fields ];
	$GLOBALS['opf_probe_groups'] = [ [ 'id' => 'g', 'group' => $group ] ];
	$method = new ReflectionMethod( OPF\Service\CartIntegration::class, 'sanitize_submitted' );
	$method->setAccessible( true );
	$token = str_repeat( 'a', 64 );
	$raw = [ 'g' => [
		'email' => [ 2 => ' Ada@example.com ', 5 => [ 'nested' => 'invalid' ], 9 => "a\0@example.com" ],
		'text' => [ ' <b>Name</b> ', '' ],
		'quantity_email' => [ 3 => ' third@example.com ', 1 => ' first@example.com ', -1 => 'dropped' ],
		'upload' => [ $token, $token ],
		'image' => [ 'oak' => '2' ],
	] ];
	$checks = [];
	$check = static function ( $name, $passed ) use ( &$checks ) {
		$checks[] = [ 'name' => $name, 'passed' => (bool) $passed ];
		if ( ! $passed ) { throw new RuntimeException( $name ); }
	};
	$fresh = $method->invoke( null, new WC_Product(), $raw, false );
	$check( 'repeated email keeps invalid scalar markers and NUL bytes', $fresh['g']['email'] === [ 'Ada@example.com', "\0", "a\0@example.com" ] );
	$check( 'callback captures each field independently', $fresh['g']['text'] === [ 'Name', null ] );
	$check( 'inherited quantity callback preserves sorted sparse indexes', $fresh['g']['quantity_email'] === [ 1 => 'first@example.com', 3 => 'third@example.com' ] );
	$check( 'upload tokens remain opaque and deduplicated', $fresh['g']['upload'] === [ $token ] );
	$check( 'fresh image quantities retain canonical structure', $fresh['g']['image'] === [ '_opf_type' => 'image_quantity', 'quantities' => [ 'oak' => 2 ], 'invalid' => [] ] );
	$stored = $method->invoke( null, new WC_Product(), $fresh, true );
	$check( 'stored values round trip through structured sanitizer', $stored === $fresh );
	$bad_upload = $raw;
	$bad_upload['g']['upload'] = [ [ 'path' => '/etc/passwd' ] ];
	$check( 'malformed uploads stay invalid', $method->invoke( null, new WC_Product(), $bad_upload, false )['g']['upload'] === [ 'invalid' ] );
	$non_array = $raw;
	$non_array['g']['email'] = 'ada@example.com';
	$check( 'repeater rejects scalar payload', ! isset( $method->invoke( null, new WC_Product(), $non_array, false )['g']['email'] ) );
	// Exercise $structured capture inside the callback, independent of schema
	// support: image_quantity repeaters remain unsupported by field validation.
	$group->data['fields'][6]['repeat'] = $repeat;
	$image_rows = [ 'g' => [ 'image' => [ $fresh['g']['image'], [ '_opf_type' => 'image_quantity', 'quantities' => [ 'oak' => 3 ] ] ] ] ];
	$structured = $method->invoke( null, new WC_Product(), $image_rows, true );
	$check( 'callback captures structured flag for each row', $structured['g']['image'][0] === $fresh['g']['image'] && $structured['g']['image'][1]['quantities'] === [ 'oak' => 3 ] );
	unset( $group->data['fields'][6]['repeat'] );
	$order_fields = $fresh;
	$order_fields['g']['quantity_email'] = [ 'single@example.com' ];
	$order_fields['g']['removed'] = 'obsolete';
	$restored = OPF\Service\CartIntegration::restore_order_again( [ 'other' => 'kept' ], new WC_Order_Item_Product( $order_fields ), new WC_Order() );
	$check( 'order again expands inherited quantity rows', $restored['opf_fields']['g']['quantity_email'] === [ 'single@example.com', 'single@example.com' ] );
	$check( 'order again preserves upload and malformed email values', $restored['opf_fields']['g']['upload'] === [ $token ] && $restored['opf_fields']['g']['email'] === $fresh['g']['email'] );
	$check( 'order again drops removed fields and uses current price', ! isset( $restored['opf_fields']['g']['removed'] ) && 20.0 === $restored['opf_base_price'] && 'kept' === $restored['other'] );
	echo json_encode( [ 'adapters' => true, 'checks' => $checks ], JSON_PRETTY_PRINT ), "\n";
}
