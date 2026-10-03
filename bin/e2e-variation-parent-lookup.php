<?php
/**
 * Gift-card row proof: OPF resolves field groups against the PARENT product
 * when a variation is added to the cart (WAPF 1.6.22 parity behavior).
 *
 * @package open-product-fields-for-woocommerce
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'OPF_LANE_E2E' ) ) {
	WP_CLI::error( 'Run only on the disposable clone with OPF_LANE_E2E=1.' );
}
if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce required.' );
}

$created = [];
try {
	// variable parent product + one variation
	$parent = new WC_Product_Variable();
	$parent->set_name( 'LANE VAR PARENT' );
	$parent->set_status( 'publish' );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'size' );
	$attr->set_options( [ 'a', 'b' ] );
	$attr->set_variation( true );
	$parent->set_attributes( [ $attr ] );
	$parent_id = (int) $parent->save();
	$created[] = $parent_id;

	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_attributes( [ 'size' => 'a' ] );
	$variation->set_regular_price( '10' );
	$variation->set_status( 'publish' );
	$variation_id = (int) $variation->save();
	$created[] = $variation_id;

	// group targeted ONLY at the parent product id
	$data = [
		'schema' => 2,
		'fields' => [ [
			'id' => 'msg', 'label' => 'Msg', 'type' => 'text', 'required' => true,
			'width' => 100, 'choices' => [],
			'pricing' => [ 'type' => 'none', 'amount' => 0, 'formula' => '', 'per_unit' => false ],
			'conditionals' => [],
		] ],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ $parent_id ] ] ] ] ],
		'mark_required' => true,
		'labels_position' => 'above',
	];
	$group_id = \OPF\Service\FieldGroups::save( 0, $data, [ 'title' => 'LANE VAR GROUP', 'status' => 'publish' ] );
	$created[] = $group_id;

	// resolve via the VARIATION product object — the 1.6.22 behavior under test
	$var_product = wc_get_product( $variation_id );
	\OPF\Service\FieldGroups::flush_cache();
	$matched = array_map( fn( $e ) => $e['id'], \OPF\Service\FieldGroups::for_product( $var_product ) );
	WP_CLI::line( 'variation=' . $variation_id . ' parent=' . $parent_id . ' matched=' . json_encode( $matched ) );
	if ( ! in_array( $group_id, $matched, true ) ) {
		WP_CLI::error( 'Variation did not resolve parent-targeted group.' );
	}

	// and validation path: validate_add_to_cart with required field missing
	// must fail against the parent group when adding the variation.
	WC()->cart->empty_cart();
	$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $parent_id, 1, $variation_id, [ 'attribute_size' => 'a' ] );
	WP_CLI::line( 'validation_result=' . var_export( $passed, true ) );
	if ( false !== $passed ) {
		WP_CLI::error( 'Required parent-group field was not enforced for the variation.' );
	}
	WP_CLI::success( 'Variation resolves parent field groups; required-field validation enforced via parent lookup.' );
} finally {
	foreach ( $created as $id ) {
		wp_delete_post( $id, true );
	}
}
