<?php
/** Native installed Free source reference fixture, disposable /tmp WordPress only. */
if ( '1' !== getenv( 'OPF_TOGGLE_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Requires an authorized disposable /tmp WordPress.' );
}
if ( 'cleanup' === getenv( 'OPF_TOGGLE_E2E_PHASE' ) ) {
	$pid = (int) get_option( 'opf_toggle_wapf_reference', 0 );
	if ( $pid ) { wp_delete_post( $pid, true ); }
	delete_option( 'opf_toggle_wapf_reference' );
	echo "ok installed Free reference fixture cleanup\n";
	return;
}
$product = new WC_Product_Simple();
$product->set_name( 'WAPF true false reference' );
$product->set_slug( 'wapf-toggle-reference' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product->set_virtual( true );
$pid = $product->save();
$fields = [];
foreach ( [ 'accept' => 'checked', 'wrap' => 'unchecked' ] as $id => $default ) {
	$fields[] = [ 'id' => $id, 'type' => 'true-false', 'label' => ucfirst( $id ), 'required' => 'accept' === $id, 'default' => $default, 'message' => 'accept' === $id ? 'Accept & continue' : 'Wrap & ribbon', 'width' => 100, 'class' => '', 'description' => '', 'pricing' => [ 'enabled' => 'wrap' === $id, 'type' => 'qt', 'amount' => 2 ], 'conditionals' => [] ];
}
$model = SW_WAPF\Includes\Classes\Field_Groups::raw_json_to_field_group( [ 'id' => 'p_' . $pid, 'type' => 'wapf_product', 'fields' => $fields, 'conditions' => [], 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ], 'variables' => [] ] );
update_post_meta( $pid, '_wapf_fieldgroup', $model->to_array() );
update_option( 'opf_toggle_wapf_reference', $pid );
file_put_contents( '/tmp/opf-toggle-wapf-state.json', wp_json_encode( [ 'product' => $pid ] ) );
echo "ok installed Free reference fixture persisted\n";
