<?php
/**
 * Prove OPF WXR export through WordPress Importer and WAPF's field-group reader.
 * Run only on a disposable WordPress clone with WooCommerce, OPF, and WAPF active:
 * OPF_WXR_E2E_ALLOW=1 wp eval-file bin/e2e-wapf-wxr.php
 *
 * @package open-product-fields-for-woocommerce
 */

if ( '1' !== getenv( 'OPF_WXR_E2E_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_WXR_E2E_ALLOW=1 only on a disposable WordPress clone.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( OPF\Service\WapfWxrExporter::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce and OPF before running this proof.' );
}
if ( ! function_exists( 'wapf' ) || ! class_exists( 'SW_WAPF\Includes\Classes\Field_Groups' ) ) {
	throw new RuntimeException( 'Activate WAPF before running this proof.' );
}

$importer_file = WP_PLUGIN_DIR . '/wordpress-importer/wordpress-importer.php';
if ( ! class_exists( 'WP_Import' ) && is_readable( $importer_file ) ) {
	$importer_dir = dirname( $importer_file );
	require_once ABSPATH . 'wp-admin/includes/import.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	require_once $importer_dir . '/compat.php';
	require_once $importer_dir . '/php-toolkit/load.php';
	require_once $importer_dir . '/parsers/class-wxr-parser.php';
	require_once $importer_dir . '/parsers/class-wxr-parser-simplexml.php';
	require_once $importer_dir . '/parsers/class-wxr-parser-xml.php';
	require_once $importer_dir . '/parsers/class-wxr-parser-regex.php';
	require_once $importer_dir . '/parsers/class-wxr-parser-xml-processor.php';
	require_once $importer_dir . '/class-wp-import.php';
}
if ( ! class_exists( 'WP_Import' ) ) {
	throw new RuntimeException( 'Install WordPress Importer before running this proof.' );
}

$title = 'OPF WXR round-trip fixture';
if ( get_page_by_title( $title, OBJECT, 'opf_field_group' ) ) {
	throw new RuntimeException( 'A fixture with this title already exists; refusing to modify it.' );
}

$source_id = 0;
$wxr_path = '';
try {
	$data = OPF\Engine\FieldGroup::normalize( [
		'fields' => [
			[
				'id' => 'wxr-finish',
				'label' => 'Finish',
				'type' => 'select',
				'choices' => [
					[ 'slug' => 'linen', 'label' => 'Linen', 'pricing' => [ 'type' => 'fixed', 'amount' => 2.5 ] ],
				],
			],
			[
				'id' => 'wxr-note',
				'label' => 'Personalization',
				'type' => 'text',
				'conditionals' => [
					[ 'rules' => [ [ 'field' => 'wxr-finish', 'operator' => 'is', 'value' => 'linen' ] ] ],
				],
			],
		],
	] );
	$source_id = OPF\Service\FieldGroups::save( 0, $data, [ 'title' => $title, 'status' => 'publish' ] );
	if ( ! $source_id ) {
		throw new RuntimeException( 'Could not create the OPF WXR source fixture.' );
	}
	$source = get_post( $source_id );
	$site_users = get_users( [ 'number' => 1 ] );
	$author = get_userdata( (int) $source->post_author );
	if ( ! $author && $site_users ) {
		$author = reset( $site_users );
	}
	$group_data = json_decode( (string) $source->post_content, true );
	$xml = OPF\Service\WapfWxrExporter::build_document( [ [
		'id' => (int) $source->ID,
		'title' => (string) $source->post_title,
		'status' => (string) $source->post_status,
		'menu_order' => (int) $source->menu_order,
		'date' => (string) $source->post_date,
		'date_gmt' => (string) $source->post_date_gmt,
		'slug' => (string) $source->post_name,
		'author' => $author ? (string) $author->user_login : '',
		'data' => $group_data,
	] ], [
		'site_url' => home_url(),
		'site_title' => get_bloginfo( 'name' ),
		'language' => get_bloginfo( 'language' ),
	] );
	$wxr_path = tempnam( get_temp_dir(), 'opf-wxr-e2e-' );
	if ( false === $wxr_path || false === file_put_contents( $wxr_path, $xml, LOCK_EX ) ) {
		throw new RuntimeException( 'Could not write the temporary WXR fixture.' );
	}

	$importer = new WP_Import();
	$importer->fetch_attachments = false;
	$importer->import( $wxr_path );

	$matches = get_posts( [
		'post_type' => 'wapf_product',
		'post_status' => 'any',
		'posts_per_page' => -1,
		'fields' => 'ids',
		'title' => $title,
	] );
	if ( 1 !== count( $matches ) ) {
		throw new RuntimeException( 'WordPress Importer did not create exactly one WAPF global group.' );
	}
	$target_id = (int) $matches[0];
	$target = SW_WAPF\Includes\Classes\Field_Groups::get_by_id( $target_id );
	if ( ! $target || 'wapf_product' !== $target->type || 2 !== count( $target->fields ) ) {
		throw new RuntimeException( 'WAPF could not parse the imported group and both fields.' );
	}
	$finish = $target->fields[0];
	$personalization = $target->fields[1];
	if ( 'select' !== $finish->type || 'fixed' !== ( $finish->options['choices'][0]['pricing_type'] ?? '' ) || 2.5 !== (float) ( $finish->options['choices'][0]['pricing_amount'] ?? 0 ) ) {
		throw new RuntimeException( 'WAPF did not preserve the select choice and its fixed pricing.' );
	}
	if ( 'text' !== $personalization->type || 1 !== count( $personalization->conditionals ) ) {
		throw new RuntimeException( 'WAPF did not preserve the conditional text field.' );
	}
	echo "ok WXR export, WordPress Importer, WAPF group parsing, choice pricing, and field conditional\n";
} finally {
	$matches = get_posts( [
		'post_type' => 'wapf_product',
		'post_status' => 'any',
		'posts_per_page' => -1,
		'fields' => 'ids',
		'title' => $title,
	] );
	foreach ( array_unique( array_merge( [ $source_id ], array_map( 'intval', $matches ) ) ) as $post_id ) {
		if ( $post_id > 0 ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
	if ( $wxr_path && is_file( $wxr_path ) ) {
		unlink( $wxr_path );
	}
}
