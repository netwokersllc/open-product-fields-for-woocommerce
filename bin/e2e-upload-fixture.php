<?php
/** Run only on a disposable WordPress installation: wp eval-file this.php. */
if ( ! defined( 'OPF_UPLOAD_PRIVATE_DIR' ) || 0 !== strpos( OPF_UPLOAD_PRIVATE_DIR, '/tmp/opf-upload-' ) ) throw new RuntimeException( 'Disposable upload runtime required.' );
update_option( 'opf_upload_ajax', 'yes' );
update_option( 'opf_admin_only', 'no' );
update_option( 'woocommerce_coming_soon', 'no' );
// Test controls exist only in this disposable installation, never in OPF runtime code.
$mu = WP_CONTENT_DIR . '/mu-plugins';
if ( ! is_dir( $mu ) ) mkdir( $mu, 0755, true );
file_put_contents( $mu . '/opf-upload-proof.php', <<<'PHP'
<?php
add_filter( 'pre_wp_mail', '__return_true' );
if ( isset( $_SERVER['HTTP_X_OPF_TEST_FILE_CAP'] ) && ! defined( 'OPF_UPLOAD_MAX_FILES' ) ) define( 'OPF_UPLOAD_MAX_FILES', (int) $_SERVER['HTTP_X_OPF_TEST_FILE_CAP'] );
if ( isset( $_SERVER['HTTP_X_OPF_TEST_BYTE_CAP'] ) && ! defined( 'OPF_UPLOAD_MAX_BYTES' ) ) define( 'OPF_UPLOAD_MAX_BYTES', (int) $_SERVER['HTTP_X_OPF_TEST_BYTE_CAP'] );
PHP
);
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_cod_settings', [ 'enabled' => 'yes', 'title' => 'Cash on delivery', 'enable_for_virtual' => 'yes' ] );
update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
WC_Install::create_pages();
$existing = get_page_by_path( 'upload-proof', OBJECT, 'product' );
$product = $existing ? wc_get_product( $existing->ID ) : new WC_Product_Simple();
$product->set_name( 'Upload proof product' );
$product->set_slug( 'upload-proof' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product->set_virtual( true );
$product->save();
$group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'required' => true, 'multiple' => true, 'accepted_types' => 'png,pdf', 'max_size' => 1 ],
		[ 'id' => 'single', 'label' => 'Single file', 'type' => 'upload', 'accepted_types' => 'png', 'max_size' => 1 ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product->get_id() ] ] ] ] ],
] );
$existing_groups = get_posts( [ 'post_type' => 'opf_field_group', 'title' => 'Upload proof fields', 'posts_per_page' => 1 ] );
$gid = OPF\Service\FieldGroups::save( $existing_groups ? $existing_groups[0]->ID : 0, $group, [ 'title' => 'Upload proof fields' ] );
echo wp_json_encode( [ 'product_id' => $product->get_id(), 'group_id' => $gid, 'url' => home_url( '/?product=upload-proof' ), 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION ] ) . "\n";
