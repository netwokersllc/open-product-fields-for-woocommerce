<?php
/** Native reference and OPF LEN findings. Never runs on a production database. */
use OPF\Engine\Calculator;
use OPF\Engine\WapfMapper;
use OPF\Service\FieldGroups;
use OPF\Service\WapfExporter;
use OPF\Service\Exporter;
use OPF\Service\ArchiveImporter;
use SW_WAPF_PRO\Includes\Classes\Field_Groups;
use SW_WAPF_PRO\Includes\Classes\Helper;

if ( '1' !== getenv( 'OPF_LEN_ALLOW' ) || realpath( ABSPATH ) !== '/tmp/opf-image-formula-len-wp' || ! defined( 'FQDB' ) || realpath( FQDB ) !== realpath( ABSPATH ) . '/wp-content/database/.ht.sqlite' ) {
	throw new RuntimeException( 'Owned LEN SQLite clone and explicit opt-in required.' );
}
$key = 'opf_len_lifecycle_fixture';
$state = get_option( $key, [] );
$mode = getenv( 'OPF_LEN_MODE' );
$out = getenv( 'OPF_LEN_OUT' );
if ( ! $out || ! is_dir( $out ) ) { throw new RuntimeException( 'Existing artifact directory required.' ); }
$write = static function ( $name, $data ) use ( $out ) { file_put_contents( $out . '/' . $name, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); };
if ( 'cleanup' === $mode ) {
	if ( ! $state ) { throw new RuntimeException( 'Missing owned fixture.' ); }
	$orders = wc_get_orders( [ 'limit' => -1, 'return' => 'objects' ] );
	foreach ( $orders as $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_product_id() === $state['product'] ) { $order->delete( true ); break; }
		}
	}
	foreach ( array_reverse( $state['owned'] ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( $state['statuses'] as $id => $status ) { wp_update_post( [ 'ID' => $id, 'post_status' => $status ] ); }
	foreach ( $state['options'] as $name => $entry ) {
		if ( $entry['exists'] ) { update_option( $name, $entry['value'] ); } else { delete_option( $name ); }
	}
	delete_option( $key );
	FieldGroups::flush_cache();
	$write( 'cleanup.json', [ 'owned' => $state['owned'], 'remaining' => array_values( array_filter( $state['owned'], 'get_post' ) ), 'option' => get_option( $key, false ), 'active_plugins' => get_option( 'active_plugins' ) ] );
	echo 'Cleaned owned product/pages/groups/orders and restored clone options.';
	return;
}
if ( 'setup' !== $mode || $state ) { throw new RuntimeException( 'Setup only once.' ); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$reference = WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/';
if ( '3.1.5' !== get_plugin_data( $reference . 'advanced-product-fields-for-woocommerce-extended.php' )['Version'] ) { throw new RuntimeException( 'Actual Extended 3.1.5 required.' ); }
$state = [ 'owned' => [], 'options' => [], 'statuses' => [] ];
foreach ( [ 'active_plugins', 'opf_admin_only', 'opf_show_totals', 'woocommerce_calc_taxes', 'woocommerce_enable_guest_checkout', 'woocommerce_checkout_page_id', 'woocommerce_cart_page_id', 'woocommerce_cod_settings', 'woocommerce_default_country', 'woocommerce_currency', 'wapf_settings' ] as $name ) {
	$value = get_option( $name, '__opf_len_missing__' );
	$state['options'][$name] = [ 'exists' => '__opf_len_missing__' !== $value, 'value' => $value ];
}
update_option( $key, $state );
foreach ( get_posts( [ 'post_type' => [ 'opf_field_group', 'wapf_product' ], 'post_status' => 'publish', 'numberposts' => -1 ] ) as $post ) {
	$state['statuses'][$post->ID] = $post->post_status;
	update_option( $key, $state );
	wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'draft' ] );
}
update_option( 'opf_admin_only', 'no' ); update_option( 'opf_show_totals', 'yes' );
update_option( 'woocommerce_calc_taxes', 'no' ); update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_default_country', 'US:CA' ); update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_cod_settings', [ 'enabled' => 'yes', 'title' => 'Proof cash on delivery', 'enable_for_virtual' => 'yes' ] );
$own = static function ( $id ) use ( &$state, $key ) { if ( ! $id || is_wp_error( $id ) ) { throw new RuntimeException( 'Fixture insert failed.' ); } $state['owned'][] = (int) $id; update_option( $key, $state ); return (int) $id; };
$product = new WC_Product_Simple();
$product->set_name( 'Disposable LEN lifecycle proof' ); $product->set_regular_price( '10' ); $product->set_virtual( true ); $product->set_status( 'publish' );
$state['product'] = $own( $product->save() ); update_option( $key, $state );
$fields = [
	[ 'id' => 'TextID', 'label' => 'Text source', 'type' => 'text', 'required' => false, 'options' => [] ],
	[ 'id' => 'SourceID', 'label' => 'Choice source', 'type' => 'select', 'required' => false, 'choices' => [ [ 'slug' => 'zz', 'label' => 'a quick brown fox', 'pricing_type' => 'none', 'pricing_amount' => 0 ] ] ],
	[ 'id' => 'FeeID', 'label' => 'Length fee', 'type' => 'select', 'required' => true, 'choices' => [] ],
];
$formulas = [ 'text' => 'len([field.TextID])', 'strip' => 'len([field.TextID];true)', 'choice' => 'len([field.SourceID];true)', 'upper' => 'len([field.TextID];TRUE)', 'literal' => 'len(a quick brown fox;true)' ];
foreach ( $formulas as $slug => $formula ) { $fields[2]['choices'][] = [ 'slug' => $slug, 'label' => $slug, 'pricing_type' => 'fx', 'pricing_amount' => '(' . $formula . ') * [qty]' ]; }
foreach ( $fields as &$field ) { $field += [ 'description' => '', 'class' => '', 'width' => 100, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'parent_clone' => [], 'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ] ]; } unset( $field );
$raw = [ 'id' => 'p_' . $state['product'], 'type' => 'wapf_product', 'fields' => $fields, 'conditions' => [], 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ], 'variables' => [] ];
$model = Field_Groups::raw_json_to_field_group( $raw );
update_post_meta( $state['product'], '_wapf_fieldgroup', $model->to_array() );
$native_export = [ 'fields' => Field_Groups::field_group_to_raw_fields_json( $model ), 'conditions' => $model->rules_groups, 'layout' => $model->layout, 'variables' => $model->variables ];
$mapped = WapfMapper::map( $model->to_array(), [ 'attach_product_ids' => [ $state['product'] ] ] );
$write( 'wapf-native-model-export.json', $native_export ); $write( 'wapf-mapped-findings.json', $mapped );
// The importer drops LEN formulas. Create an explicitly authored OPF group for
// runtime investigation; this is not evidence that WAPF migration succeeded.
$plain = [ 'type' => 'none', 'amount' => 0, 'formula' => '' ];
$opf = [ 'fields' => [
	[ 'id' => 'text', 'label' => 'Text source', 'type' => 'text', 'pricing' => $plain ],
	[ 'id' => 'source', 'label' => 'Choice source', 'type' => 'select', 'choices' => [ [ 'slug' => 'zz', 'label' => 'a quick brown fox', 'pricing' => $plain ] ], 'pricing' => $plain ],
	[ 'id' => 'fee', 'label' => 'Length fee', 'type' => 'select', 'required' => true, 'choices' => [], 'pricing' => $plain ],
], 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $state['product'] ] ] ] ] ] ];
foreach ( $formulas as $slug => $formula ) {
	$formula = str_replace( [ 'TextID', 'SourceID' ], [ 'text', 'source' ], $formula );
	$opf['fields'][2]['choices'][] = [ 'slug' => $slug, 'label' => $slug, 'pricing' => [ 'type' => 'formula', 'amount' => 0, 'formula' => $formula, 'per_unit' => true ] ];
}
$state['group'] = $own( FieldGroups::save( 0, $opf, [ 'title' => 'Disposable LEN authored OPF', 'status' => 'publish' ] ) ); update_option( $key, $state );
$stored = FieldGroups::group_from_post( get_post( $state['group'] ) )->data;
$package = Exporter::build_package( [ [ 'id' => $state['group'], 'title' => 'LEN archive proof', 'status' => 'publish', 'data' => $stored ] ], [] );
$decoded = ArchiveImporter::decode( wp_json_encode( $package ) );
$archive = ArchiveImporter::import( $decoded, true );
foreach ( $archive['groups'] as $entry ) { if ( ! empty( $entry['opf_id'] ) ) { $own( $entry['opf_id'] ); wp_update_post( [ 'ID' => $entry['opf_id'], 'post_status' => 'draft' ] ); } }
$tools = WapfExporter::build_payload( $stored );
$tools_model = Field_Groups::raw_json_to_field_group( $tools + [ 'id' => 'p_' . $state['product'], 'type' => 'wapf_product' ] );
$write( 'opf-tools-export.json', $tools );
$write( 'roundtrip-findings.json', [ 'archive' => $archive, 'archive_decoded_data_identical' => $decoded['groups'][0]['data'] === $stored, 'tools_reimport' => WapfMapper::map( $tools_model->to_array() ) ] );
$probes = [];
foreach ( [ 'ascii' => 'a quick brown fox', 'emoji' => 'A😀B', 'combining' => "Ae\u{0301}B", 'nbsp' => "A\u{00A0}B", 'emspace' => "A\u{2003}B", 'bom' => "A\u{FEFF}B", 'nel' => "A\u{0085}B", 'ascii-whitespace' => "A \t\n\r\v\fB", 'zero' => '0' ] as $name => $value ) {
	foreach ( [ 'plain' => '', 'strip' => ';true', 'upper' => ';TRUE' ] as $variant => $suffix ) {
		$formula = 'len(' . $value . $suffix . ')';
		$probes[] = [ 'name' => $name, 'variant' => $variant, 'text' => $value, 'formula' => $formula, 'wapf_php' => Helper::parse_math_string( $formula ), 'opf_php' => Calculator::evaluate_formula( $formula, 10, 1, 0 ) ];
	}
}
$write( 'php-length-probes.json', $probes );
foreach ( [ 'cart' => '[woocommerce_cart]', 'checkout' => '[woocommerce_checkout]', 'host' => '[product_page id="' . $state['product'] . '"]' ] as $name => $content ) {
	$state[$name] = $own( wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Disposable LEN ' . $name, 'post_content' => $content ] ) ); update_option( $key, $state );
	if ( 'host' !== $name ) { update_option( 'woocommerce_' . $name . '_page_id', $state[$name] ); }
}
$state['runtime'] = [ 'utc' => gmdate( 'c' ), 'base' => home_url(), 'database' => FQDB, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'extended' => '3.1.5', 'reference_php_sha256' => hash_file( 'sha256', $reference . 'includes/controllers/class-public-controller.php' ), 'reference_js_sha256' => hash_file( 'sha256', $reference . 'assets/js/frontend.min.js' ), 'opf_php_sha256' => hash_file( 'sha256', OPF_DIR . 'includes/Engine/Calculator.php' ), 'opf_js_sha256' => hash_file( 'sha256', OPF_DIR . 'assets/js/opf-frontend.js' ) ];
update_option( $key, $state ); FieldGroups::flush_cache(); $write( 'state.json', $state ); echo wp_json_encode( $state );
