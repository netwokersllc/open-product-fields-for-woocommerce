<?php
/** Upload UI lane fixtures; run only on the disposable clone. */
if ( realpath( ABSPATH ) !== '/tmp/opf-image-uploadui-wp' ) {
	throw new RuntimeException( 'Disposable upload UI clone required.' );
}
// Snapshot every option this lane mutates so cleanup restores the exact
// clone baseline (the missing sentinel captures "option did not exist").
$tracked = [ 'opf_upload_ajax', 'opf_admin_only', 'woocommerce_coming_soon', 'woocommerce_calc_taxes', 'woocommerce_enable_guest_checkout', 'woocommerce_cod_settings', 'woocommerce_bacs_settings' ];
$before  = [];
foreach ( $tracked as $key ) {
	$before[ $key ] = get_option( $key, '__opf_missing__' );
}

update_option( 'opf_upload_ajax', 'yes' );
update_option( 'opf_admin_only', 'no' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_cod_settings', [ 'enabled' => 'yes', 'title' => 'Cash on delivery', 'enable_for_virtual' => 'yes' ] );
update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
WC_Install::create_pages();

// The clone is installed at the filesystem root level, so OPF's default
// out-of-webroot private directory would resolve to `//opf-private-...`.
// Pin a disposable, out-of-webroot path through a lane-only mu-plugin.
$private = '/tmp/opf-upload-ui-private';
$mu      = WP_CONTENT_DIR . '/mu-plugins';
if ( ! is_dir( $mu ) ) {
	mkdir( $mu, 0755, true );
}
file_put_contents( $mu . '/opf-upload-ui-defines.php', "<?php\nif ( ! defined( 'OPF_UPLOAD_PRIVATE_DIR' ) ) define( 'OPF_UPLOAD_PRIVATE_DIR', '" . $private . "' );\n" );
if ( ! is_dir( $private ) ) {
	mkdir( $private, 0700 );
}

$existing = get_page_by_path( 'opf-upload-ui-product', OBJECT, 'product' );
$product  = $existing ? wc_get_product( $existing->ID ) : new WC_Product_Simple();
$product->set_name( 'OPF Upload UI Product' );
$product->set_slug( 'opf-upload-ui-product' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product->set_virtual( true );
$pid = $product->save();

$group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'required' => true, 'multiple' => true, 'accepted_types' => [ 'png', 'pdf' ], 'max_size' => 1 ],
		[ 'id' => 'single', 'label' => 'Single file', 'type' => 'upload', 'accepted_types' => [ 'png' ], 'max_size' => 1 ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
] );
$existing_groups = get_posts( [ 'post_type' => 'opf_field_group', 'title' => 'OPF Upload UI Fields', 'posts_per_page' => 1 ] );
$gid             = OPF\Service\FieldGroups::save( $existing_groups ? $existing_groups[0]->ID : 0, $group, [ 'title' => 'OPF Upload UI Fields' ] );
update_option( 'opf_upload_ui_fixture', [ 'product_id' => $pid, 'group_id' => $gid, 'before' => $before ] );

echo wp_json_encode( [
	'product_id' => $pid,
	'group_id'   => $gid,
	'url'        => home_url( '/?product=opf-upload-ui-product' ),
	'wordpress'  => get_bloginfo( 'version' ),
	'woocommerce' => WC_VERSION,
] ) . "\n";
