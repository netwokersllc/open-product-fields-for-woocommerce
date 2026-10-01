<?php
/**
 * Guarded WooCommerce E2E for repeated field sanitation, validation, and pricing.
 *
 * Run inside a disposable WooCommerce install after loading this checkout's
 * autoloader and calling FieldGroups::register_cpt():
 *   OPF_REPEATER_E2E_ALLOW=1 wp eval '... require "bin/e2e-repeater-values.php";'
 */

defined( 'ABSPATH' ) || exit;

if ( '1' !== getenv( 'OPF_REPEATER_E2E_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_REPEATER_E2E_ALLOW=1 only for a disposable WooCommerce database.' );
}

use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;

$product_id = 0;
$group_id = 0;
$admin_only_before = get_option( 'opf_admin_only', 'no' );
$post_before = $_POST['opf'] ?? null;
$had_post_before = array_key_exists( 'opf', $_POST );

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

try {
	update_option( 'opf_admin_only', 'no' );
	FieldGroups::register_cpt();
	CartIntegration::init();

	$product = new WC_Product_Simple();
	$product->set_name( 'OPF repeated value E2E fixture' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product_id = (int) $product->save();
	$assert( $product_id > 0, 'Could not create the repeated-value E2E product.' );

	$group_id = FieldGroups::save( 0, [
		'fields' => [ [
			'id' => 'attendee_name',
			'label' => 'Attendee name',
			'type' => 'text',
			'required' => true,
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ],
			'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => true ],
		] ],
	], [ 'title' => 'OPF repeated value E2E fixture' ] );
	$assert( $group_id > 0, 'Could not create the repeated-value E2E field group.' );

	$_POST['opf'] = [ (string) $group_id => [ 'attendee_name' => [ ' Ada ', 'Grace' ] ] ];
	$assert( CartIntegration::validate_add_to_cart( true, $product_id, 1 ), 'Valid repeated rows were rejected.' );
	$cart_data = CartIntegration::attach( [], $product_id );
	$values = $cart_data[ CartIntegration::ITEM_KEY ][ (string) $group_id ]['attendee_name'] ?? null;
	$assert( [ 'Ada', 'Grace' ] === $values, 'Repeated row sanitation or ordering failed: ' . wp_json_encode( $values ) );
	$addon = CartIntegration::addons_per_unit( wc_get_product( $product_id ), $cart_data[ CartIntegration::ITEM_KEY ], 10.0, 1 );
	$assert( 4.0 === $addon, 'Repeated row pricing was not summed: ' . (string) $addon );

	$_POST['opf'] = [ (string) $group_id => [ 'attendee_name' => [ 'Ada', '' ] ] ];
	$assert( ! CartIntegration::validate_add_to_cart( true, $product_id, 1 ), 'A required empty repeated row was accepted.' );
	wc_clear_notices();

	$_POST['opf'] = [ (string) $group_id => [ 'attendee_name' => [ 'A', 'B', 'C', 'D' ] ] ];
	$assert( ! CartIntegration::validate_add_to_cart( true, $product_id, 1 ), 'More than the configured button maximum was accepted.' );
	wc_clear_notices();

	echo "ok repeated row sanitation, required-row validation, maximum enforcement, and price summation\n";
} finally {
	if ( $had_post_before ) {
		$_POST['opf'] = $post_before;
	} else {
		unset( $_POST['opf'] );
	}
	update_option( 'opf_admin_only', $admin_only_before );
	if ( $group_id > 0 ) {
		wp_delete_post( $group_id, true );
	}
	if ( $product_id > 0 ) {
		wp_delete_post( $product_id, true );
	}
	FieldGroups::flush_cache();
	wc_clear_notices();
}
