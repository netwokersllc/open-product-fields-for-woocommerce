<?php
/**
 * Cross-site migration proof — DESTINATION site (site B).
 *
 * Recreates the source's products/categories/attachments with the same
 * natural keys (SKU/slug/basename) but different auto-increment IDs, then:
 *   1. imports the OPF archive and records warnings/draft/IDs,
 *   2. imports the same WAPF Tools payload through WAPF 3.1.5's native
 *      importer as the reference contract,
 *   3. classifies every site-local reference: preserved / wrong-target / missing.
 *
 * Run only on the disposable destination clone (WAPF Extended active):
 *   OPF_XSITE_ALLOW=1 OPF_XSITE_ARTIFACTS=... \
 *   OPF_XSITE_MANIFEST=.../source-manifest.json \
 *   wp eval-file bin/e2e-crosssite-migproof-destination.php
 *
 * @package open-product-fields-for-woocommerce
 */

use OPF\Engine\FieldGroup;
use OPF\Service\ArchiveImporter;
use OPF\Service\FieldGroups;

if ( '1' !== getenv( 'OPF_XSITE_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_XSITE_ALLOW=1 only on a disposable clone.' );
}
$artifacts = rtrim( (string) ( getenv( 'OPF_XSITE_ARTIFACTS' ) ?: '' ), '/' );
$manifest_path = (string) ( getenv( 'OPF_XSITE_MANIFEST' ) ?: ( $artifacts . '/source-manifest.json' ) );
if ( '' === $artifacts || ! is_dir( $artifacts ) ) {
	throw new RuntimeException( 'Set OPF_XSITE_ARTIFACTS to an existing directory.' );
}
if ( ! is_file( $manifest_path ) ) {
	throw new RuntimeException( 'Source manifest not found: ' . $manifest_path );
}
$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
if ( ! is_array( $manifest ) ) {
	throw new RuntimeException( 'Source manifest is not valid JSON.' );
}

$write = static function ( string $file, $value ) use ( $artifacts ): void {
	$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === file_put_contents( $artifacts . '/' . $file, $json ) ) {
		throw new RuntimeException( 'Artifact write failed: ' . $file );
	}
};

$snapshot = static function (): array {
	global $wpdb;
	$by_type = [];
	foreach ( [ 'post', 'product', 'product_variation', 'attachment', 'opf_field_group', 'wapf_product' ] as $type ) {
		$by_type[ $type ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $type ) );
	}
	return [
		'posts_total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
		'postmeta_total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
		'by_type'        => $by_type,
	];
};

$baseline = $snapshot();
$baseline_groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] );
$baseline_wapf   = get_posts( [ 'post_type' => 'wapf_product', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] );
$created_posts = [];
$created_terms = [];
$created_files = [];
$imported_opf = [];
$imported_wapf = [];

$find_product_id_by_sku = static function ( string $sku ): int {
	$id = wc_get_product_id_by_sku( $sku );
	return $id ? (int) $id : 0;
};

try {
	/* Shift auto-increment so destination IDs cannot collide with source IDs. */
	for ( $i = 0; $i < 5; $i++ ) {
		$throwaway = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'MIGPROOF throwaway ' . $i ] );
		if ( $throwaway ) {
			$created_posts[] = (int) $throwaway;
		}
	}

	$make_product = static function ( array $spec ) use ( &$created_posts ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $spec['name'] );
		$product->set_slug( $spec['slug'] );
		$product->set_sku( $spec['sku'] );
		$product->set_regular_price( $spec['price'] );
		$product->set_status( 'publish' );
		$id = (int) $product->save();
		if ( ! $id ) {
			throw new RuntimeException( 'Could not create destination product ' . $spec['sku'] );
		}
		$created_posts[] = $id;
		return $id;
	};

	$dest = [ 'products' => [] ];
	foreach ( $manifest['products'] as $source_product ) {
		$id = $make_product( [
			'name' => 'Dest ' . $source_product['role'],
			'slug' => $source_product['slug'],
			'sku' => $source_product['sku'],
			'price' => '5.00',
		] );
		$dest['products'][ $source_product['role'] ] = [ 'id' => $id, 'sku' => $source_product['sku'], 'slug' => $source_product['slug'] ];
	}

	$variable = new WC_Product_Variable();
	$variable->set_name( 'Dest Variable' );
	$variable->set_slug( $manifest['variable']['slug'] );
	$variable->set_sku( $manifest['variable']['sku'] );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( $manifest['variation']['attribute'] );
	$attribute->set_options( [ 's', 'm' ] );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$variable->set_attributes( [ $attribute ] );
	$variable_id = (int) $variable->save();
	$created_posts[] = $variable_id;
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable_id );
	$variation->set_attributes( [ $manifest['variation']['attribute'] => $manifest['variation']['value'] ] );
	$variation->set_regular_price( '11.00' );
	$variation->set_sku( 'MIGPROOF-V-S' );
	$variation_id = (int) $variation->save();
	$created_posts[] = $variation_id;
	$dest['variation'] = [ 'id' => $variation_id, 'parent_id' => $variable_id ];

	/* Shift term IDs too, so category/tag IDs differ from the source site. */
	for ( $i = 0; $i < 3; $i++ ) {
		$throwaway_cat = wp_insert_term( 'MIGPROOF dest cat ' . $i, 'product_cat', [ 'slug' => 'migproof-dest-cat-' . $i ] );
		if ( ! is_wp_error( $throwaway_cat ) ) {
			$created_terms[] = [ (int) $throwaway_cat['term_id'], 'product_cat' ];
		}
	}
	for ( $i = 0; $i < 3; $i++ ) {
		$throwaway_tag = wp_insert_term( 'MIGPROOF dest tag ' . $i, 'product_tag', [ 'slug' => 'migproof-dest-tag-' . $i ] );
		if ( ! is_wp_error( $throwaway_tag ) ) {
			$created_terms[] = [ (int) $throwaway_tag['term_id'], 'product_tag' ];
		}
	}

	$cat = wp_insert_term( $manifest['category']['name'], 'product_cat', [ 'slug' => $manifest['category']['slug'] ] );
	if ( is_wp_error( $cat ) ) {
		throw new RuntimeException( 'Could not create destination product_cat: ' . $cat->get_error_message() );
	}
	$dest['category'] = [ 'id' => (int) $cat['term_id'], 'slug' => $manifest['category']['slug'] ];
	$created_terms[] = [ (int) $cat['term_id'], 'product_cat' ];

	$tag = wp_insert_term( $manifest['tag']['name'], 'product_tag', [ 'slug' => $manifest['tag']['slug'] ] );
	if ( is_wp_error( $tag ) ) {
		throw new RuntimeException( 'Could not create destination product_tag: ' . $tag->get_error_message() );
	}
	$dest['tag'] = [ 'id' => (int) $tag['term_id'], 'slug' => $manifest['tag']['slug'] ];
	$created_terms[] = [ (int) $tag['term_id'], 'product_tag' ];

	$dest['attachments'] = [];
	foreach ( $manifest['attachments'] as $source_attachment ) {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
		$upload = wp_upload_bits( $source_attachment['basename'], null, $png );
		if ( ! empty( $upload['error'] ) ) {
			throw new RuntimeException( 'Could not write destination attachment: ' . $upload['error'] );
		}
		$created_files[] = $upload['file'];
		$attachment_id = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'Dest ' . $source_attachment['role'], 'post_status' => 'inherit' ], $upload['file'] );
		wp_update_attachment_metadata( (int) $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] ) );
		$created_posts[] = (int) $attachment_id;
		$dest['attachments'][ $source_attachment['role'] ] = [ 'id' => (int) $attachment_id, 'url' => $upload['url'], 'basename' => wp_basename( $upload['file'] ) ];
	}

	/* Decoy: occupy the source site's product ID with an unrelated product so
	 * the preserved literal ID demonstrably resolves to the wrong target. */
	$decoy_source_id = (int) $manifest['products'][0]['id'];
	$decoy_id = wp_insert_post( [
		'import_id'   => $decoy_source_id,
		'post_type'   => 'product',
		'post_status' => 'publish',
		'post_title'  => 'DECOY unrelated product',
	] );
	if ( $decoy_id ) {
		update_post_meta( (int) $decoy_id, '_sku', 'DECOY-ALPHA' );
		update_post_meta( (int) $decoy_id, '_price', '1' );
		update_post_meta( (int) $decoy_id, '_regular_price', '1' );
		$created_posts[] = (int) $decoy_id;
	}

	/* ----------------------- 1. OPF archive import ----------------------- */
	$archive_file = $artifacts . '/crosssite-source-opf-archive.json';
	$package = ArchiveImporter::decode( (string) file_get_contents( $archive_file ) );
	$dry = ArchiveImporter::import( $package, false );
	$commit = ArchiveImporter::import( $package, true );
	$opf_id = (int) ( $commit['groups'][0]['opf_id'] ?? 0 );
	if ( $opf_id > 0 ) {
		$imported_opf[] = $opf_id;
	}
	$opf_post = $opf_id ? get_post( $opf_id ) : null;
	$opf_notes = $opf_id ? get_post_meta( $opf_id, '_opf_needs_review', true ) : [];
	$opf_data = $opf_post ? json_decode( (string) $opf_post->post_content, true ) : null;

	/* ----------------------- 2. WAPF native import ------------------------ */
	$native_field_groups = 'SW_WAPF_PRO\\Includes\\Classes\\Field_Groups';
	if ( ! class_exists( $native_field_groups ) ) {
		throw new RuntimeException( 'WAPF Extended must be active for the reference-contract import.' );
	}
	$wapf_payload = json_decode( (string) file_get_contents( $artifacts . '/crosssite-source-wapf-tools.json' ), true );
	$native_model = $native_field_groups::raw_json_to_field_group( $wapf_payload + [ 'id' => 900001, 'type' => 'wapf_product' ] );
	if ( ! $native_model ) {
		throw new RuntimeException( 'WAPF native importer rejected the OPF WAPF Tools payload.' );
	}
	$wapf_native_id = (int) $native_field_groups::save( $native_model, 'wapf_product', null, 'MIGPROOF WAPF native import', 'publish' );
	if ( ! $wapf_native_id ) {
		throw new RuntimeException( 'WAPF native save failed.' );
	}
	$imported_wapf[] = $wapf_native_id;
	$wapf_native_post = get_post( $wapf_native_id );
	$wapf_native_array = @unserialize( (string) $wapf_native_post->post_content, [ 'allowed_classes' => false ] );

	/* WXR reference contract: WAPF parses the serialized group identically. */
	$wxr_contract = [ 'parsed' => false ];
	$wxr_file = $artifacts . '/crosssite-source-wapf-wxr.xml';
	if ( is_file( $wxr_file ) ) {
		$document = new DOMDocument();
		if ( $document->loadXML( (string) file_get_contents( $wxr_file ) ) ) {
			$xpath = new DOMXPath( $document );
			$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
			$node = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 );
			if ( $node ) {
				$wxr_group = $native_field_groups::process_data( $node->textContent );
				if ( $wxr_group ) {
					$wxr_array = $wxr_group->to_array();
					$wxr_choice_ids = [];
					foreach ( (array) ( $wxr_array['fields'] ?? [] ) as $field ) {
						foreach ( (array) ( $field['options']['choices'] ?? $field['choices'] ?? [] ) as $choice ) {
							if ( isset( $choice['id'] ) ) {
								$wxr_choice_ids[] = (int) $choice['id'];
							}
						}
					}
					$wxr_contract = [ 'parsed' => true, 'product_choice_ids' => $wxr_choice_ids, 'conditions' => $wxr_array['rule_groups'] ?? null ];
				}
			}
		}
	}

	/* ----------------------- 3. Reference resolution ---------------------- */
	$resolution = [
		'products' => [],
		'variation' => null,
		'category' => null,
		'var_att' => [ 'source' => 'migsize|s', 'portable' => true, 'note' => 'attribute|slug pair; no site-local ID' ],
		'attachments' => [],
	];
	foreach ( $manifest['products'] as $source_product ) {
		$source_id = (int) $source_product['id'];
		$resolved  = $find_product_id_by_sku( (string) $source_product['sku'] );
		$target    = get_post( $source_id );
		$resolution['products'][] = [
			'role'          => $source_product['role'],
			'source_id'     => $source_id,
			'sku'           => $source_product['sku'],
			'dest_id_by_sku'=> $resolved,
			'auto_remapped' => $resolved > 0 && $resolved !== $source_id,
			'preserved_id_resolves_to' => $target ? [ 'id' => $target->ID, 'type' => $target->post_type, 'title' => $target->post_title ] : null,
			'preserved_id_is_intended_target' => $target && 'product' === $target->post_type && (int) $target->ID === $resolved,
		];
	}
	$resolution['variation'] = [
		'source_id'      => (int) $manifest['variation']['id'],
		'dest_id'        => $variation_id,
		'auto_remapped'  => $variation_id !== (int) $manifest['variation']['id'],
	];
	$resolution['category'] = [
		'source_id'     => (int) $manifest['category']['id'],
		'dest_id_by_slug' => (int) $dest['category']['id'],
		'auto_remapped' => (int) $dest['category']['id'] !== (int) $manifest['category']['id'],
	];
	foreach ( $manifest['attachments'] as $source_attachment ) {
		$dest_attach = $dest['attachments'][ $source_attachment['role'] ] ?? [ 'id' => 0 ];
		$resolution['attachments'][] = [
			'role'           => $source_attachment['role'],
			'source_id'      => (int) $source_attachment['id'],
			'dest_id_by_name'=> (int) $dest_attach['id'],
			'auto_remapped'  => (int) $dest_attach['id'] !== (int) $source_attachment['id'],
			'source_url'     => (string) $source_attachment['url'],
		];
	}

	/* Placement behaviour: does the imported OPF group reach the intended product? */
	$intended_alpha = $dest['products']['alpha']['id'];
	$reached_by_intended = FieldGroups::for_product( wc_get_product( $intended_alpha ) );
	$reached_titles = array_map( static function ( $entry ) {
		return get_the_title( (int) $entry['id'] );
	}, $reached_by_intended );

	$result = [
		'generated_at' => gmdate( 'c' ),
		'site_url' => home_url(),
		'abspath' => ABSPATH,
		'source_manifest' => $manifest_path,
		'destination_natural_keys' => $dest,
		'opf_archive_import' => [
			'dry_run' => $dry,
			'commit' => $commit,
			'opf_id' => $opf_id,
			'status' => $opf_post ? $opf_post->post_status : null,
			'needs_review' => $opf_notes,
			'data_preserved_verbatim' => is_array( $opf_data ) && $opf_data === $manifest['group_data'],
			'placement_terms_preserved' => $opf_data['rule_groups'] ?? null,
		],
		'wapf_native_import' => [
			'wapf_post_id' => $wapf_native_id,
			'status' => $wapf_native_post ? $wapf_native_post->post_status : null,
			'product_choice_ids' => array_values( array_map( static function ( $choice ) {
				return (int) ( $choice['id'] ?? 0 );
			}, (array) ( $wapf_native_array['fields'][0]['options']['choices'] ?? $wapf_native_array['fields'][0]['choices'] ?? [] ) ) ),
			'attachment_ids' => array_values( array_filter( [
				(int) ( $wapf_native_array['fields'][2]['options']['choices'][0]['attachment'] ?? $wapf_native_array['fields'][2]['choices'][0]['attachment'] ?? 0 ),
				(int) ( $wapf_native_array['fields'][2]['options']['choices'][1]['attachment'] ?? $wapf_native_array['fields'][2]['choices'][1]['attachment'] ?? 0 ),
			] ) ),
			'conditions' => $wapf_native_array['rule_groups'] ?? null,
			'needs_review' => null,
			'silent' => true,
		],
		'wxr_reference_contract' => $wxr_contract,
		'reference_resolution' => $resolution,
		'placement_behavior' => [
			'intended_destination_product_id' => $intended_alpha,
			'groups_reaching_intended_product' => $reached_titles,
			'imported_group_reaches_intended' => in_array( 'MIGPROOF cross-site source group', $reached_titles, true ),
		],
	];
	$write( 'crosssite-destination-results.json', $result );

	echo wp_json_encode( [
		'ok' => true,
		'opf' => [
			'ids' => $opf_id,
			'status' => $opf_post ? $opf_post->post_status : null,
			'needs_review' => $opf_notes,
			'data_preserved_verbatim' => $result['opf_archive_import']['data_preserved_verbatim'],
		],
		'wapf_native' => [
			'id' => $wapf_native_id,
			'product_choice_ids' => $result['wapf_native_import']['product_choice_ids'],
			'needs_review' => 'none (silent)',
		],
		'reference_resolution' => $resolution,
		'placement_behavior' => $result['placement_behavior'],
	] ), "\n";
} finally {
	foreach ( array_unique( array_merge( $created_posts, $imported_opf, $imported_wapf ) ) as $post_id ) {
		if ( $post_id > 0 ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
	/* Imported groups can also be found by their markers if a save used another id. */
	foreach ( [ 'opf_field_group' => $baseline_groups, 'wapf_product' => $baseline_wapf ] as $post_type => $baseline_ids ) {
		$current = get_posts( [ 'post_type' => $post_type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] );
		foreach ( array_diff( array_map( 'intval', $current ), array_map( 'intval', $baseline_ids ) ) as $post_id ) {
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
	$write( 'crosssite-destination-post-cleanup.json', [ 'before' => $baseline, 'after' => $after, 'equal' => $baseline === $after ] );
	if ( $baseline !== $after ) {
		echo "CLEANUP MISMATCH\n" . wp_json_encode( [ 'before' => $baseline, 'after' => $after ], JSON_PRETTY_PRINT ), "\n";
	}
}
