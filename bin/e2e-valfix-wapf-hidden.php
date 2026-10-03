<?php
/**
 * Focused WAPF Extended 3.1.5 reference probe: how does WAPF treat forged
 * values inside conditionally hidden fields/sections?
 *
 * OPF_VALFIX_ALLOW=1 wp eval-file bin/e2e-valfix-wapf-hidden.php --path=<clone>
 *
 * Disposable /tmp site only. WAPF Extended must be active.
 */

use SW_WAPF_PRO\Includes\Classes\Field_Groups;
use SW_WAPF_PRO\Includes\Classes\Cart;
use SW_WAPF_PRO\Includes\Controllers\Product_Controller;

if ( '1' !== getenv( 'OPF_VALFIX_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Disposable /tmp WordPress site only.' );
}
if ( ! class_exists( 'SW_WAPF_PRO\\Includes\\Classes\\Field_Groups' ) ) {
	throw new RuntimeException( 'WAPF Extended must be active.' );
}

$out = getenv( 'OPF_VALFIX_OUT' ) ?: sys_get_temp_dir();
$result = [ 'validate' => [], 'attached' => [], 'stored_model' => null ];

$product = new WC_Product_Simple();
$product->set_name( 'valfix hidden probe' );
$product->set_slug( 'valfix-hidden-probe' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product->set_virtual( true );
$pid = $product->save();

$raw = [
	'id'     => 'valfix_' . $pid,
	'type'   => 'wapf_product',
	'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => 'false' ],
	'conditions' => [],
	'fields' => [
		[ 'id' => 'gate', 'type' => 'true-false', 'label' => 'Gate', 'required' => false, 'width' => 100 ],
		[
			'id' => 'sec', 'type' => 'section', 'label' => 'Conditional section', 'required' => false, 'width' => 100,
			'conditionals' => [ [ 'rules' => [ [ 'field' => 'gate', 'condition' => 'check', 'value' => '1' ] ] ] ],
		],
		[ 'id' => 'sectext', 'type' => 'text', 'label' => 'Section text', 'required' => true, 'width' => 100 ],
		[ 'id' => 'seccb', 'type' => 'checkboxes', 'label' => 'Section checkboxes', 'required' => false, 'width' => 100, 'max_choices' => 2,
			'choices' => [
				[ 'slug' => 'a', 'label' => 'A', 'pricing_type' => 'fixed', 'pricing_amount' => 1 ],
				[ 'slug' => 'b', 'label' => 'B', 'pricing_type' => 'none', 'pricing_amount' => 0 ],
				[ 'slug' => 'c', 'label' => 'C', 'pricing_type' => 'none', 'pricing_amount' => 0 ],
			],
		],
		[ 'id' => 'secend', 'type' => 'sectionend', 'label' => '', 'required' => false, 'width' => 100 ],
		[
			'id' => 'own', 'type' => 'text', 'label' => 'Own conditional', 'required' => false, 'width' => 100,
			'conditionals' => [ [ 'rules' => [ [ 'field' => 'gate', 'condition' => 'check', 'value' => '1' ] ] ] ],
		],
		[ 'id' => 'always', 'type' => 'text', 'label' => 'Always', 'required' => false, 'width' => 100 ],
	],
];

$model  = Field_Groups::raw_json_to_field_group( $raw );
$stored = $model->to_array();
update_post_meta( $pid, '_wapf_fieldgroup', $stored );
$result['stored_model'] = $stored;

$groups = Field_Groups::get_field_groups_of_product( wc_get_product( $pid ) );

$validate = static function ( array $fields ) use ( $groups, $pid ) {
	$prev_req  = $_REQUEST;
	$prev_post = $_POST;
	$_REQUEST['wapf'] = $fields;
	$_POST['wapf']    = $fields;
	$_REQUEST['wapf_field_groups'] = 'p_' . $pid;
	$res = Cart::validate_cart_data( $groups, true, $pid, 1 );
	$_REQUEST = $prev_req;
	$_POST    = $prev_post;
	return $res;
};

$result['validate']['gate_off_required_in_hidden_section_empty'] = $validate( [ 'field_gate' => '0', 'field_sectext' => '' ] );
$result['validate']['gate_off_forged_text_in_hidden_section']    = $validate( [ 'field_gate' => '0', 'field_sectext' => 'forged' ] );
$result['validate']['gate_off_forged_own_hidden_text']           = $validate( [ 'field_gate' => '0', 'field_own' => 'forged' ] );
$result['validate']['gate_off_forged_checkboxes_over_max_hidden']= $validate( [ 'field_gate' => '0', 'field_seccb' => [ 'a', 'b', 'c' ] ] );
$result['validate']['gate_off_forged_checkboxes_under_min_hidden']= $validate( [ 'field_gate' => '0', 'field_seccb' => [ 'a' ] ] );
$result['validate']['gate_on_empty_required_visible_section']    = $validate( [ 'field_gate' => '1', 'field_sectext' => '' ] );
$result['validate']['gate_on_ok']                                = $validate( [ 'field_gate' => '1', 'field_sectext' => 'ok', 'field_seccb' => [ 'a' ] ] );

// Persistence path: what does add_fields_to_cart_item keep?
$controller = new Product_Controller();
$attach = static function ( array $fields ) use ( $controller, $pid ) {
	$prev_req = $_REQUEST;
	$_REQUEST['wapf'] = $fields;
	$_REQUEST['wapf_field_groups'] = 'p_' . $pid;
	$data = $controller->add_fields_to_cart_item( [], $pid, 0, 1 );
	$_REQUEST = $prev_req;
	$kept = [];
	foreach ( ( $data['wapf'] ?? [] ) as $cart_field ) {
		$kept[ $cart_field['id'] ] = $cart_field['raw'];
	}
	return $kept;
};
$result['attached']['gate_off_forged_text_in_hidden_section'] = $attach( [ 'field_gate' => '0', 'field_sectext' => 'forged', 'field_own' => 'forged-own' ] );
$result['attached']['gate_off_forged_checkboxes_hidden']      = $attach( [ 'field_gate' => '0', 'field_seccb' => [ 'a', 'b', 'c' ] ] );

// Real classic add-to-cart: does a forged hidden priced checkbox land in the cart?
if ( ! WC()->cart ) {
	wc_load_cart();
}
$cart = WC()->cart;
$cart->empty_cart();
$prev_req = $_REQUEST;
$_REQUEST['wapf'] = [ 'field_gate' => '0', 'field_sectext' => 'forged', 'field_seccb' => [ 'a' ] ];
$_REQUEST['wapf_field_groups'] = 'p_' . $pid;
wc_clear_notices();
$key = $cart->add_to_cart( $pid, 1 );
$result['cart']['add_forged_hidden_status'] = (bool) $key;
if ( $key ) {
	$item = $cart->get_cart_item( $key );
	$result['cart']['stored_wapf'] = [];
	foreach ( ( $item['wapf'] ?? [] ) as $cf ) {
		$result['cart']['stored_wapf'][ $cf['id'] ] = $cf['raw'];
	}
	$cart->calculate_totals();
	$result['cart']['total'] = (float) $cart->get_total( 'edit' );
}
$cart->empty_cart();
$_REQUEST = $prev_req;

// Cleanup fixture.
delete_post_meta( $pid, '_wapf_fieldgroup' );
wp_delete_post( $pid, true );

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
file_put_contents( rtrim( $out, '/' ) . '/wapf-hidden-probe.json', wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
