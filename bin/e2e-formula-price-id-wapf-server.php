<?php
/** Native WAPF controller/cart proof on a dedicated disposable SQLite clone. */
if ( realpath( ABSPATH ) !== '/tmp/opf-priceid-wapf-server-20261003' ) {
	throw new RuntimeException( 'Disposable price-ID clone required.' );
}
use SW_WAPF_PRO\Includes\Classes\Cache;
use SW_WAPF_PRO\Includes\Classes\Cart;
use SW_WAPF_PRO\Includes\Classes\Helper;
use SW_WAPF_PRO\Includes\Controllers\Product_Controller;
use SW_WAPF_PRO\Includes\Models\Field;
use SW_WAPF_PRO\Includes\Models\FieldGroup;

$product = new WC_Product_Simple();
$product->set_name( 'Disposable price-ID reference' );
$product->set_regular_price( '100' );
$product->save();
$field = static function ( $amount ): Field {
	$f = new Field(); $f->id = 'source'; $f->type = 'text'; $f->label = 'Source';
	$f->meta = SW_WAPF_PRO\Includes\Classes\Config::get_field_definition_for( 'text' );
	$f->pricing->enabled = true; $f->pricing->type = 'fixed'; $f->pricing->amount = $amount;
	return $f;
};
$early = new FieldGroup(); $early->id = 'early'; $early->fields = [ $field( 0 ) ];
$later = new FieldGroup(); $later->id = 'later'; $later->fields = [ $field( 11 ) ];
Cache::set( 'field-group-early', $early ); Cache::set( 'field-group-later', $later );
$controller = new Product_Controller();
$results = [];
try {
	foreach ( [ 'both' => 'early,later', 'first_group_absent' => 'later' ] as $name => $groups ) {
		$_REQUEST = [ 'wapf_field_groups' => $groups, 'wapf' => [ 'field_source' => 'submitted' ] ];
		$item = $controller->add_fields_to_cart_item( [], $product->get_id(), 0, 1 );
		$item += [ 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 1, 'key' => 'proof' ];
		Cart::calculate_cart_item_prices( $item, true );
		$formula = Helper::replace_in_formula( '[price.source] * 2', 1, 100, '', 0, $item['wapf'], $product->get_id() );
		$expected = 'both' === $name ? '0 * 2' : '11 * 2';
		if ( $formula !== $expected ) throw new RuntimeException( "$name: $formula != $expected" );
		$results[$name] = [ 'entries' => count( $item['wapf'] ), 'formula' => $formula ];
	}
	$_REQUEST = [ 'wapf_field_groups' => 'early,later', 'wapf' => [] ];
	$item = $controller->add_fields_to_cart_item( [], $product->get_id(), 0, 1 );
	if ( isset( $item['wapf'] ) ) throw new RuntimeException( 'Absent submitted source must not create a cart field' );
	$results['unsubmitted_fields'] = [ 'entries' => 0 ];
	// Same request key is shared by duplicate IDs: absent raw first source
	// cannot be submitted independently of the second within both groups.
	$results['helper_later_only'] = Helper::replace_in_formula( '[price.source]', 1, 100, '', 0, [ [ 'id' => 'source', 'clone_idx' => 0, 'values' => [ [ 'calc_price' => 11 ] ] ] ] );
	if ( $results['helper_later_only'] !== '11' ) throw new RuntimeException( 'Helper must use first actual cart entry' );
	fwrite( STDOUT, wp_json_encode( $results, JSON_PRETTY_PRINT ) . "\n" );
} finally { wp_delete_post( $product->get_id(), true ); $_REQUEST = []; }
