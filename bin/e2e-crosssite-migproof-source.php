<?php
/**
 * Cross-site migration proof — SOURCE site (site A).
 *
 * Creates a WAPF-shaped OPF field group that carries every site-local
 * reference class the MIGRATION rows care about:
 *   - products field manual choices (product IDs)
 *   - products field category query (product_cat term ID)
 *   - placement rules: product, product_cat, product_var (variation ID), var_att
 *   - image swatch choice attachments (attachment IDs)
 *   - content image URL + attachment ID
 *
 * Then exports the group as an OPF archive, a WAPF Tools JSON payload and a
 * WAPF WXR document, writes a natural-key manifest and removes all fixtures.
 *
 * Run only on the disposable source clone:
 *   OPF_XSITE_ALLOW=1 OPF_XSITE_ARTIFACTS=/tmp/opf-lane-migproof-evidence \
 *   wp eval-file bin/e2e-crosssite-migproof-source.php
 *
 * @package open-product-fields-for-woocommerce
 */

use OPF\Engine\FieldGroup;
use OPF\Service\Exporter;
use OPF\Service\FieldGroups;
use OPF\Service\WapfExporter;
use OPF\Service\WapfWxrExporter;

if ( '1' !== getenv( 'OPF_XSITE_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_XSITE_ALLOW=1 only on a disposable clone.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( WapfExporter::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce and OPF before running this proof.' );
}
$artifacts = rtrim( (string) ( getenv( 'OPF_XSITE_ARTIFACTS' ) ?: '' ), '/' );
if ( '' === $artifacts || ! is_dir( $artifacts ) ) {
	throw new RuntimeException( 'Set OPF_XSITE_ARTIFACTS to an existing directory.' );
}

$write = static function ( string $file, $value ) use ( $artifacts ): void {
	$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === file_put_contents( $artifacts . '/' . $file, $json ) ) {
		throw new RuntimeException( 'Artifact write failed: ' . $file );
	}
};

/** @return array<string,mixed> */
$snapshot = static function (): array {
	global $wpdb;
	$by_type = [];
	foreach ( [ 'product', 'product_variation', 'product_cat', 'product_tag', 'attachment', 'opf_field_group', 'wapf_product' ] as $type ) {
		$by_type[ $type ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $type ) );
	}
	return [
		'posts_total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
		'postmeta_total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
		'attachment_total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ),
		'by_type'        => $by_type,
	];
};

$baseline = $snapshot();
$write( 'source-baseline.json', [ 'site_url' => home_url(), 'abspath' => ABSPATH, 'baseline' => $baseline ] );

$created_posts = [];
$created_terms = [];
$created_files = [];

$make_product = static function ( string $name, string $slug, string $sku, string $price ) use ( &$created_posts ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_slug( $slug );
	$product->set_sku( $sku );
	$product->set_regular_price( $price );
	$product->set_status( 'publish' );
	$product_id = $product->save();
	if ( ! $product_id ) {
		throw new RuntimeException( 'Could not create product ' . $sku );
	}
	$created_posts[] = $product_id;
	return (int) $product_id;
};

try {
	$product_a = $make_product( 'Migproof Alpha', 'migproof-alpha', 'MIGPROOF-A', '5.00' );
	$product_b = $make_product( 'Migproof Beta', 'migproof-beta', 'MIGPROOF-B', '7.00' );
	$product_c = $make_product( 'Migproof Gamma', 'migproof-gamma', 'MIGPROOF-C', '9.00' );

	/* Variable product + one variation so `product_var` targets a real ID. */
	$variable = new WC_Product_Variable();
	$variable->set_name( 'Migproof Variable' );
	$variable->set_slug( 'migproof-variable' );
	$variable->set_sku( 'MIGPROOF-V' );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'migsize' );
	$attribute->set_options( [ 's', 'm' ] );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$variable->set_attributes( [ $attribute ] );
	$variable_id = (int) $variable->save();
	$created_posts[] = $variable_id;

	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable_id );
	$variation->set_attributes( [ 'migsize' => 's' ] );
	$variation->set_regular_price( '11.00' );
	$variation->set_sku( 'MIGPROOF-V-S' );
	$variation_id = (int) $variation->save();
	$created_posts[] = $variation_id;

	/* Product category + tag (term IDs are site-local). */
	$cat = wp_insert_term( 'Migproof Cat', 'product_cat', [ 'slug' => 'migproof-cat' ] );
	if ( is_wp_error( $cat ) ) {
		throw new RuntimeException( 'Could not create product_cat: ' . $cat->get_error_message() );
	}
	$cat_id = (int) $cat['term_id'];
	$created_terms[] = [ $cat_id, 'product_cat' ];
	wp_set_object_terms( $product_a, [ $cat_id ], 'product_cat' );

	$tag = wp_insert_term( 'Migproof Tag', 'product_tag', [ 'slug' => 'migproof-tag' ] );
	if ( is_wp_error( $tag ) ) {
		throw new RuntimeException( 'Could not create product_tag: ' . $tag->get_error_message() );
	}
	$tag_id = (int) $tag['term_id'];
	$created_terms[] = [ $tag_id, 'product_tag' ];
	wp_set_object_terms( $product_a, [ $tag_id ], 'product_tag' );

	/* Attachments (same bytes so a destination can recreate the same file). */
	$attachment = static function ( string $filename, string $color ) use ( &$created_posts, &$created_files ): array {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
		$upload = wp_upload_bits( $filename, null, $png );
		if ( ! empty( $upload['error'] ) ) {
			throw new RuntimeException( 'Could not write ' . $filename . ': ' . $upload['error'] );
		}
		$created_files[] = $upload['file'];
		$attachment_id = wp_insert_attachment( [
			'post_mime_type' => 'image/png',
			'post_title'     => 'Migproof ' . $color,
			'post_status'    => 'inherit',
		], $upload['file'] );
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			throw new RuntimeException( 'Could not create attachment ' . $filename );
		}
		wp_update_attachment_metadata( (int) $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] ) );
		$created_posts[] = (int) $attachment_id;
		return [ 'id' => (int) $attachment_id, 'url' => $upload['url'], 'file' => $upload['file'], 'basename' => wp_basename( $upload['file'] ) ];
	};
	$att_red  = $attachment( 'migproof-red.png', 'red' );
	$att_blue = $attachment( 'migproof-blue.png', 'blue' );

	/* The field group under test. */
	$title = 'MIGPROOF cross-site source group';
	if ( get_page_by_title( $title, OBJECT, 'opf_field_group' ) ) {
		throw new RuntimeException( 'Source fixture already exists; refusing to modify it.' );
	}
	$data = FieldGroup::normalize( [
		'fields' => [
			[
				'id' => 'linked', 'label' => 'Linked products', 'type' => 'products', 'subtype' => 'checkbox',
				'product_selection' => 'manual',
				'choices' => [
					[ 'slug' => 'p' . $product_a, 'label' => 'Alpha', 'product_id' => $product_a, 'pricing_type' => 'fixed' ],
					[ 'slug' => 'p' . $product_b, 'label' => 'Beta', 'product_id' => $product_b, 'pricing_type' => 'none' ],
				],
			],
			[
				'id' => 'related', 'label' => 'Related products', 'type' => 'products', 'subtype' => 'card',
				'product_selection' => 'category',
				'product_query' => [ 'query_id' => $cat_id, 'query_label' => 'Migproof Cat', 'limit' => 5, 'sort' => 'date_desc', 'pricing_type' => 'fixed' ],
			],
			[
				'id' => 'swatch', 'label' => 'Image swatch', 'type' => 'swatch',
				'choices' => [
					[ 'slug' => 'red', 'label' => 'Red', 'image_id' => $att_red['id'] ],
					[ 'slug' => 'blue', 'label' => 'Blue', 'image_id' => $att_blue['id'] ],
				],
			],
			[
				'id' => 'guide', 'label' => 'Guide image', 'type' => 'content_image',
				'image_url' => $att_blue['url'], 'image_id' => $att_blue['id'],
			],
			[ 'id' => 'note', 'label' => 'Note', 'type' => 'text' ],
		],
		'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_a ] ],
			[ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ (string) $cat_id ] ],
			[ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ (string) $variation_id ] ],
			[ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'migsize|s' ] ],
		] ] ],
	] );
	$group_id = (int) FieldGroups::save( 0, $data, [ 'title' => $title, 'status' => 'publish' ] );
	if ( ! $group_id ) {
		throw new RuntimeException( 'Could not create the OPF source group.' );
	}
	$created_posts[] = $group_id;
	$stored = json_decode( (string) get_post( $group_id )->post_content, true );

	/* ---- OPF archive --------------------------------------------------- */
	$package = Exporter::build_package( [ [
		'id' => $group_id,
		'title' => $title,
		'status' => 'publish',
		'menu_order' => 0,
		'language' => '',
		'data' => $stored,
	] ], [ 'type' => 'group', 'id' => $group_id ] );
	file_put_contents( $artifacts . '/crosssite-source-opf-archive.json', wp_json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	$archive_warnings = $package['groups'][0]['warnings'] ?? [];
	if ( ! in_array( 'product_target_ids_may_not_match', $archive_warnings, true ) ) {
		throw new RuntimeException( 'OPF archive export missed the site-local target warning.' );
	}
	if ( ! in_array( 'media_files_not_included', $archive_warnings, true ) ) {
		throw new RuntimeException( 'OPF archive export missed the media warning.' );
	}

	/* ---- WAPF Tools JSON + WXR ---------------------------------------- */
	$wapf_export_ok = false;
	$wapf_error = '';
	$wapf_payload = null;
	try {
		$wapf_payload = WapfExporter::build_payload( $stored );
		$wapf_export_ok = true;
		file_put_contents( $artifacts . '/crosssite-source-wapf-tools.json', wp_json_encode( $wapf_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	} catch ( \Throwable $e ) {
		$wapf_error = $e->getMessage();
	}

	$wxr_ok = false;
	try {
		$author = get_userdata( (int) get_post( $group_id )->post_author );
		$wxr = WapfWxrExporter::build_document( [ [
			'id' => $group_id,
			'title' => $title,
			'status' => 'publish',
			'menu_order' => 0,
			'date' => (string) get_post( $group_id )->post_date,
			'date_gmt' => (string) get_post( $group_id )->post_date_gmt,
			'slug' => (string) get_post( $group_id )->post_name,
			'author' => $author ? (string) $author->user_login : '',
			'data' => $stored,
		] ], [ 'site_url' => home_url(), 'site_title' => get_bloginfo( 'name' ), 'language' => get_bloginfo( 'language' ) ] );
		file_put_contents( $artifacts . '/crosssite-source-wapf-wxr.xml', $wxr );
		$wxr_ok = true;
	} catch ( \Throwable $e ) {
		$wxr_ok = false;
	}

	$manifest = [
		'generated_at' => gmdate( 'c' ),
		'site_url' => home_url(),
		'abspath' => ABSPATH,
		'group' => [ 'id' => $group_id, 'title' => $title ],
		'products' => [
			[ 'role' => 'alpha', 'id' => $product_a, 'sku' => 'MIGPROOF-A', 'slug' => 'migproof-alpha' ],
			[ 'role' => 'beta', 'id' => $product_b, 'sku' => 'MIGPROOF-B', 'slug' => 'migproof-beta' ],
			[ 'role' => 'gamma', 'id' => $product_c, 'sku' => 'MIGPROOF-C', 'slug' => 'migproof-gamma' ],
		],
		'variable' => [ 'id' => $variable_id, 'sku' => 'MIGPROOF-V', 'slug' => 'migproof-variable' ],
		'variation' => [ 'id' => $variation_id, 'parent_id' => $variable_id, 'attribute' => 'migsize', 'value' => 's' ],
		'category' => [ 'id' => $cat_id, 'slug' => 'migproof-cat', 'name' => 'Migproof Cat' ],
		'tag' => [ 'id' => $tag_id, 'slug' => 'migproof-tag', 'name' => 'Migproof Tag' ],
		'attachments' => [
			[ 'role' => 'red', 'id' => $att_red['id'], 'url' => $att_red['url'], 'basename' => $att_red['basename'] ],
			[ 'role' => 'blue', 'id' => $att_blue['id'], 'url' => $att_blue['url'], 'basename' => $att_blue['basename'] ],
		],
		'exports' => [
			'opf_archive' => [ 'file' => 'crosssite-source-opf-archive.json', 'warnings' => $archive_warnings ],
			'wapf_tools' => [ 'file' => 'crosssite-source-wapf-tools.json', 'ok' => $wapf_export_ok, 'error' => $wapf_error ],
			'wapf_wxr' => [ 'file' => 'crosssite-source-wapf-wxr.xml', 'ok' => $wxr_ok ],
		],
		'group_data' => $stored,
	];
	$write( 'source-manifest.json', $manifest );

	echo wp_json_encode( [
		'ok' => true,
		'group_id' => $group_id,
		'source_products' => [ 'alpha' => $product_a, 'beta' => $product_b, 'gamma' => $product_c ],
		'variation_id' => $variation_id,
		'cat_id' => $cat_id,
		'tag_id' => $tag_id,
		'attachments' => [ 'red' => $att_red['id'], 'blue' => $att_blue['id'] ],
		'archive_warnings' => $archive_warnings,
		'wapf_export_ok' => $wapf_export_ok,
		'wapf_export_error' => $wapf_error,
		'wxr_ok' => $wxr_ok,
	] ), "\n";
} finally {
	foreach ( array_unique( $created_posts ) as $post_id ) {
		if ( $post_id > 0 ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
	foreach ( $created_terms as [ $term_id, $taxonomy ] ) {
		wp_delete_term( (int) $term_id, $taxonomy );
	}
	foreach ( array_unique( $created_files ) as $file ) {
		if ( $file && file_exists( $file ) ) {
			@unlink( $file );
		}
	}
	$after = $snapshot();
	$write( 'source-post-cleanup.json', [ 'before' => $baseline, 'after' => $after, 'equal' => $baseline === $after ] );
	if ( $baseline !== $after ) {
		echo "CLEANUP MISMATCH\n" . wp_json_encode( [ 'before' => $baseline, 'after' => $after ], JSON_PRETTY_PRINT ), "\n";
	}
}
