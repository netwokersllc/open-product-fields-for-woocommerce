<?php
/**
 * Round-trip proof for the migration/import-export lane on a disposable clone.
 *
 * Covers WAPF 3.1.5 `products` (manual/category/qty) fields, the `file`→`upload`
 * field, `variables`, and `lookuptable` formula references:
 *
 *   OPF group → WAPF Tools JSON (WapfExporter) → native raw_json_to_field_group()
 *   → native model → WapfMapper::map() → OPF group (fixed point) → OPF archive
 *   → re-import. The same group is exported to WXR and parsed back through WAPF's
 *   native Field_Groups::process_data().
 *
 * Run only on the dedicated disposable clone with WAPF Extended active:
 *   OPF_WAPF_PRODUCTS_E2E_ALLOW=1 \
 *   OPF_WAPF_PRODUCTS_E2E_ARTIFACTS=/tmp/opf-lane-migration-evidence \
 *   wp --path=/tmp/opf-image-migration-wp eval-file bin/e2e-wapf-products-roundtrip.php
 *
 * @package open-product-fields-for-woocommerce
 */

use OPF\Engine\FieldGroup;
use OPF\Engine\WapfMapper;
use OPF\Service\ArchiveImporter;
use OPF\Service\Exporter;
use OPF\Service\FieldGroups;
use OPF\Service\WapfExporter;
use OPF\Service\WapfWxrExporter;
use SW_WAPF_PRO\Includes\Classes\Field_Groups as Native_Field_Groups;

if ( '1' !== getenv( 'OPF_WAPF_PRODUCTS_E2E_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_WAPF_PRODUCTS_E2E_ALLOW=1 only on the dedicated disposable clone.' );
}
if ( '/tmp/opf-image-migration-wp/' !== ABSPATH ) {
	throw new RuntimeException( 'This proof is pinned to /tmp/opf-image-migration-wp/.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( WapfExporter::class ) || ! class_exists( WapfWxrExporter::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce and OPF before running this proof.' );
}
if ( ! class_exists( Native_Field_Groups::class ) || ! function_exists( 'wapf_pro' ) ) {
	throw new RuntimeException( 'Activate WAPF Extended (reference plugin) before running this proof.' );
}

$artifacts = rtrim( (string) ( getenv( 'OPF_WAPF_PRODUCTS_E2E_ARTIFACTS' ) ?: '/tmp/opf-lane-migration-evidence' ), '/' );
if ( ! is_dir( $artifacts ) ) {
	throw new RuntimeException( 'Artifact directory does not exist: ' . $artifacts );
}

global $wpdb;
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$write = static function ( string $file, $value ) use ( $artifacts ): void {
	$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === file_put_contents( $artifacts . '/' . $file, $json ) ) {
		throw new RuntimeException( 'Artifact write failed: ' . $file );
	}
};

/** Snapshot of everything this proof could touch. */
$snapshot = static function (): array {
	global $wpdb;
	$by_type = [];
	foreach ( [ 'product', 'product_variation', 'wapf_product', 'opf_field_group', 'attachment' ] as $type ) {
		$by_type[ $type ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $type ) );
	}
	$options = [];
	foreach ( [ 'opf_date_format', 'opf_version', 'wapf_db_version', 'active_plugins' ] as $name ) {
		$options[ $name ] = get_option( $name );
	}
	return [
		'posts_total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
		'postmeta_total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
		'options_total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ),
		'users_total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
		'by_type' => $by_type,
		'options' => $options,
	];
};

$baseline = $snapshot();
$write( 'baseline.json', [
	'environment' => [
		'site_url' => home_url(),
		'php' => PHP_VERSION,
		'wordpress' => get_bloginfo( 'version' ),
		'woocommerce' => WC_VERSION,
		'opf' => defined( 'OPF_VERSION' ) ? OPF_VERSION : 'unknown',
		'wapf' => function_exists( 'wapf_pro' ) && method_exists( wapf_pro(), 'get_setting' ) ? wapf_pro()->get_setting( 'version' ) : 'unknown',
		'abspath' => ABSPATH,
	],
	'baseline' => $baseline,
] );

$title = 'OPF migration products round-trip fixture';
if ( get_page_by_title( $title, OBJECT, 'opf_field_group' ) ) {
	throw new RuntimeException( 'A fixture with this title already exists; refusing to modify it.' );
}

$owned_posts = [];
$result = [
	'environment' => [ 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'wapf' => wapf_pro()->get_setting( 'version' ) ],
	'baseline' => $baseline,
	'assertions' => [],
	'fields' => [],
];

$record = static function ( string $id, bool $ok, string $detail = '' ) use ( &$result, $assert ): void {
	$result['assertions'][] = [ 'id' => $id, 'ok' => $ok, 'detail' => $detail ];
	$assert( $ok, $id . ( '' !== $detail ? ': ' . $detail : '' ) );
};

try {
	/* ---------------------------------------------------------------- source */
	$data = FieldGroup::normalize( [
		'fields'    => [
			[
				'id' => 'linked', 'label' => 'Linked products', 'type' => 'products', 'subtype' => 'card',
				'product_selection' => 'manual', 'qty_method' => 'parent',
				'choices' => [
					[ 'slug' => 'gift', 'label' => 'Gift wrap', 'selected' => true, 'product_id' => 12, 'pricing_type' => 'fixed' ],
					[ 'slug' => 'extra', 'label' => '', 'product_id' => 34, 'pricing_type' => 'none', 'disabled' => true ],
				],
				'items_per_row' => 3, 'items_per_row_tablet' => 2, 'items_per_row_mobile' => 1,
				'incl_img' => true, 'incl_desc' => false,
				'slot_1' => 'price', 'slot_2' => 'stock', 'slot_3' => 'link',
				'hide_cart' => true,
			],
			[
				'id' => 'related', 'label' => 'Related products', 'type' => 'products', 'subtype' => 'vcard',
				'product_selection' => 'category',
				'product_query' => [ 'query_id' => 44, 'query_label' => 'Extras', 'limit' => 7, 'sort' => 'name_asc', 'pricing_type' => 'none' ],
				'img_fit' => 'contain', 'items_per_row' => 4,
			],
			[
				'id' => 'addons', 'label' => 'Addons', 'type' => 'products', 'subtype' => 'card-qty',
				'product_selection' => 'manual', 'display' => 'plus_min', 'min_choices' => 1, 'max_choices' => 9,
				'choices' => [
					[ 'slug' => 'a', 'label' => '', 'product_id' => 51, 'pricing_type' => 'fixed', 'quantity' => [ 'default' => 2, 'min' => 1, 'max' => 8 ] ],
				],
			],
			[
				'id' => 'artwork', 'label' => 'Artwork', 'type' => 'upload', 'required' => true,
				'multiple' => true, 'max_size' => 4.5, 'accepted_types' => [ 'jpg', 'jpeg', 'pdf' ],
				'hide_order' => true,
			],
			[ 'id' => 'width', 'label' => 'Width', 'type' => 'number' ],
			[
				'id' => 'fee', 'label' => 'Fee', 'type' => 'text',
				'pricing' => [ 'type' => 'formula', 'formula' => 'lookuptable(cutting;width;2)', 'per_unit' => false ],
				'conditionals' => [ [
					'action' => 'show', 'logic' => 'all',
					'rules' => [ [ 'field' => 'width', 'operator' => 'greater', 'value' => '3' ] ],
				] ],
			],
		],
		'variables' => [ [
			'name' => 'feevar', 'default' => '[field.width] * 2',
			'rules' => [
				[ 'type' => 'field', 'field' => 'width', 'condition' => '>', 'value' => '10', 'variable' => 'lookuptable(cutting;width;5)' ],
				[ 'type' => 'qty', 'field' => 'qty', 'condition' => '', 'value' => '', 'variable' => '9' ],
			],
		] ],
		'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '765432101' ] ],
		] ] ],
	] );
	$source_id    = FieldGroups::save( 0, $data, [ 'title' => $title, 'status' => 'publish' ] );
	$owned_posts[] = $source_id;
	$assert( $source_id > 0, 'Could not create the OPF source fixture.' );
	$source_stored = json_decode( (string) get_post( $source_id )->post_content, true );
	$write( 'source-opf-group.json', $source_stored );

	/* ------------------------------------------- OPF → WAPF Tools JSON export */
	$payload = WapfExporter::build_payload( $source_stored );
	$write( 'wapf-tools-payload.json', $payload );

	/* ------------------------------- WAPF native raw import → stored model */
	$native = Native_Field_Groups::raw_json_to_field_group( $payload + [ 'id' => 900001, 'type' => 'wapf_product' ] );
	$assert( $native instanceof \SW_WAPF_PRO\Includes\Models\FieldGroup, 'Native raw_json_to_field_group returned no model.' );
	$native_array = $native->to_array();
	$write( 'wapf-native-model.json', $native_array );

	/* ------------------------------- WAPF native raw export (flatten options) */
	$native_reexport = Native_Field_Groups::field_group_to_raw_fields_json( $native );
	$write( 'wapf-native-raw-export.json', [
		'fields' => $native_reexport,
		'conditions' => $native->rules_groups,
		'layout' => $native->layout,
		'variables' => $native->variables,
	] );

	/* ----------------------------------- native model → OPF mapper (first map) */
	$map1 = WapfMapper::map( $native_array );
	$write( 'mapped-opf-group-1.json', [ 'needs_review' => $map1['needs_review'], 'notes' => $map1['notes'], 'group' => $map1['group'] ] );
	$fields1 = [];
	foreach ( $map1['group']['fields'] as $field ) {
		$fields1[ $field['label'] ] = $field;
	}

	/* ------------------------------------------------- products manual card */
	$linked = $fields1['Linked products'] ?? null;
	$record( 'products_manual_type', is_array( $linked ) && 'products' === $linked['type'] );
	$record( 'products_manual_subtype', ( $linked['subtype'] ?? '' ) === 'card' );
	$record( 'products_manual_selection', ( $linked['product_selection'] ?? '' ) === 'manual' );
	$record( 'products_manual_qty_method', ( $linked['qty_method'] ?? '' ) === 'parent' );
	$record( 'products_manual_choice_ids', array_column( (array) ( $linked['choices'] ?? [] ), 'product_id' ) === [ 12, 34 ], wp_json_encode( array_column( (array) ( $linked['choices'] ?? [] ), 'product_id' ) ) );
	$record( 'products_manual_choice_pricing', ( $linked['choices'][0]['pricing_type'] ?? '' ) === 'fixed' && ( $linked['choices'][1]['pricing_type'] ?? '' ) === 'none' );
	$record( 'products_manual_selected_disabled', ! empty( $linked['choices'][0]['selected'] ) && ! empty( $linked['choices'][1]['disabled'] ) );
	$record( 'products_manual_slots', [ $linked['slot_1'] ?? '', $linked['slot_2'] ?? '', $linked['slot_3'] ?? '' ] === [ 'price', 'stock', 'link' ] );
	$record( 'products_manual_columns', [ $linked['items_per_row'] ?? 0, $linked['items_per_row_tablet'] ?? 0, $linked['items_per_row_mobile'] ?? 0 ] === [ 3, 2, 1 ] );
	$record( 'products_manual_includes', true === ( $linked['incl_img'] ?? null ) && false === ( $linked['incl_desc'] ?? null ) );
	$record( 'products_manual_hide_cart', true === ( $linked['hide_cart'] ?? null ) );

	/* ------------------------------------------------- products category */
	$related = $fields1['Related products'] ?? null;
	$record( 'products_category_selection', is_array( $related ) && 'category' === ( $related['product_selection'] ?? '' ) );
	$record( 'products_category_query', ( $related['product_query'] ?? [] ) === [ 'query_id' => 44, 'query_label' => 'Extras', 'limit' => 7, 'sort' => 'name_asc', 'pricing_type' => 'none' ], wp_json_encode( $related['product_query'] ?? null ) );
	$record( 'products_category_no_choices', [] === ( $related['choices'] ?? null ) );
	$record( 'products_category_img_fit', ( $related['img_fit'] ?? '' ) === 'contain' );
	$record( 'products_category_subtype', ( $related['subtype'] ?? '' ) === 'vcard' );

	/* ------------------------------------------------- products card-qty */
	$addons = $fields1['Addons'] ?? null;
	$record( 'products_qty_subtype', is_array( $addons ) && 'card-qty' === ( $addons['subtype'] ?? '' ) );
	$record( 'products_qty_display', ( $addons['display'] ?? '' ) === 'plus_min' );
	$record( 'products_qty_limits', [ $addons['min_choices'] ?? 0, $addons['max_choices'] ?? 0 ] === [ 1, 9 ] );
	$record( 'products_qty_choice_quantity', ( $addons['choices'][0]['quantity'] ?? [] ) === [ 'default' => 2, 'min' => 1, 'max' => 8 ], wp_json_encode( $addons['choices'][0]['quantity'] ?? null ) );
	$record( 'products_qty_choice_product', (int) ( $addons['choices'][0]['product_id'] ?? 0 ) === 51 );

	/* ------------------------------------------------------------ upload */
	$artwork = $fields1['Artwork'] ?? null;
	$record( 'upload_type', is_array( $artwork ) && 'upload' === ( $artwork['type'] ?? '' ) );
	$record( 'upload_flags', true === ( $artwork['multiple'] ?? null ) && 4.5 === (float) ( $artwork['max_size'] ?? 0 ) && ( $artwork['accepted_types'] ?? [] ) === [ 'jpg', 'jpeg', 'pdf' ], wp_json_encode( $artwork ) );
	$record( 'upload_required_hide_order', true === ( $artwork['required'] ?? null ) && true === ( $artwork['hide_order'] ?? null ) );
	$record( 'upload_pricing_none', ( $artwork['pricing']['type'] ?? '' ) === 'none' );

	/* ----------------------------------------------------------- variables */
	$record( 'variable_preserved', ( $map1['group']['variables'][0]['name'] ?? '' ) === 'feevar' && ( $map1['group']['variables'][0]['default'] ?? '' ) === '[field.width] * 2', wp_json_encode( $map1['group']['variables'] ?? null ) );
	$record( 'variable_rules_preserved', ( $map1['group']['variables'][0]['rules'][0]['variable'] ?? '' ) === 'lookuptable(cutting;width;5)' && ( $map1['group']['variables'][0]['rules'][1]['type'] ?? '' ) === 'qty' );

	/* ---------------------------------------------- formula lookuptable ref */
	$fee = $fields1['Fee'] ?? null;
	$record( 'lookuptable_formula_preserved', ( $fee['pricing']['type'] ?? '' ) === 'formula' && ( $fee['pricing']['formula'] ?? '' ) === 'lookuptable(cutting;width;2)', wp_json_encode( $fee['pricing'] ?? null ) );

	/* ------------------------------------------------------- conditions */
	$fee_conditional = $fee['conditionals'][0]['rules'][0] ?? null;
	$record( 'field_conditional_preserved', ( $fee['conditionals'][0]['action'] ?? '' ) === 'show' && ( $fee_conditional['field'] ?? '' ) === 'width' && ( $fee_conditional['operator'] ?? '' ) === 'greater' && ( $fee_conditional['value'] ?? '' ) === '3', wp_json_encode( $fee['conditionals'] ?? null ) );
	$placement = $map1['group']['rule_groups'][0]['rules'][0] ?? null;
	$record( 'placement_rule_preserved', ( $placement['subject'] ?? '' ) === 'product' && ( $placement['operator'] ?? '' ) === 'in' && ( $placement['terms'] ?? [] ) === [ '765432101' ], wp_json_encode( $map1['group']['rule_groups'] ?? null ) );

	/* --------------------------------------------- needs_review limited to formulas */
	$unexpected = array_values( array_filter( $map1['notes'], static function ( $note ) {
		return false === strpos( $note, 'lookuptable' ) && false === strpos( $note, 'runtime review' );
	} ) );
	$record( 'review_only_formula_runtime', true === $map1['needs_review'] && [] === $unexpected, wp_json_encode( $unexpected ) );

	/* --------------------------------------- fixed point: export → import → map */
	$payload2 = WapfExporter::build_payload( $map1['group'] );
	$write( 'wapf-tools-payload-2.json', $payload2 );
	$native2 = Native_Field_Groups::raw_json_to_field_group( $payload2 + [ 'id' => 900002, 'type' => 'wapf_product' ] );
	$map2 = WapfMapper::map( $native2->to_array() );
	$write( 'mapped-opf-group-2.json', [ 'needs_review' => $map2['needs_review'], 'notes' => $map2['notes'], 'group' => $map2['group'] ] );
	$record( 'roundtrip_fixed_point', $map1['group'] === $map2['group'], 'second-generation OPF group differs after WAPF raw re-export/re-import' );
	$record( 'roundtrip_notes_stable', $map1['notes'] === $map2['notes'], 'review notes changed across re-import' );

	/* --------------------------------------------------- WXR native parse */
	$site_users = get_users( [ 'number' => 1 ] );
	$author = get_userdata( (int) get_post( $source_id )->post_author );
	if ( ! $author && $site_users ) {
		$author = reset( $site_users );
	}
	$wxr = WapfWxrExporter::build_document( [ [
		'id' => (int) $source_id,
		'title' => $title,
		'status' => 'publish',
		'menu_order' => 0,
		'date' => (string) get_post( $source_id )->post_date,
		'date_gmt' => (string) get_post( $source_id )->post_date_gmt,
		'slug' => (string) get_post( $source_id )->post_name,
		'author' => $author ? (string) $author->user_login : '',
		'data' => $source_stored,
	] ], [ 'site_url' => home_url(), 'site_title' => get_bloginfo( 'name' ), 'language' => get_bloginfo( 'language' ) ] );
	file_put_contents( $artifacts . '/wxr.xml', $wxr );
	$document = new DOMDocument();
	$assert( $document->loadXML( $wxr ), 'WXR export was not well-formed XML.' );
	$xpath = new DOMXPath( $document );
	$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
	$serialized = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
	$wxr_group = Native_Field_Groups::process_data( $serialized );
	$assert( $wxr_group instanceof \SW_WAPF_PRO\Includes\Models\FieldGroup, 'WAPF could not parse the WXR serialized group.' );
	$wxr_map = WapfMapper::map( $wxr_group->to_array() );
	$write( 'wxr-native-model.json', $wxr_group->to_array() );
	$wxr_fields = [];
	foreach ( $wxr_map['group']['fields'] as $field ) {
		$wxr_fields[ $field['label'] ] = $field;
	}
	$record( 'wxr_products_manual', 'products' === ( $wxr_fields['Linked products']['type'] ?? '' ) && 'card' === ( $wxr_fields['Linked products']['subtype'] ?? '' ) && [ 12, 34 ] === array_column( (array) ( $wxr_fields['Linked products']['choices'] ?? [] ), 'product_id' ), wp_json_encode( $wxr_fields['Linked products'] ?? null ) );
	$record( 'wxr_products_category', ( $wxr_fields['Related products']['product_query']['query_id'] ?? 0 ) === 44 && ( $wxr_fields['Related products']['img_fit'] ?? '' ) === 'contain' );
	$record( 'wxr_products_qty', 'card-qty' === ( $wxr_fields['Addons']['subtype'] ?? '' ) && [ 'default' => 2, 'min' => 1, 'max' => 8 ] === ( $wxr_fields['Addons']['choices'][0]['quantity'] ?? [] ), wp_json_encode( $wxr_fields['Addons'] ?? null ) );
	$record( 'wxr_upload', 'upload' === ( $wxr_fields['Artwork']['type'] ?? '' ) && true === ( $wxr_fields['Artwork']['multiple'] ?? null ) && 4.5 === (float) ( $wxr_fields['Artwork']['max_size'] ?? 0 ) && ( $wxr_fields['Artwork']['accepted_types'] ?? [] ) === [ 'jpg', 'jpeg', 'pdf' ], wp_json_encode( $wxr_fields['Artwork'] ?? null ) );
	$record( 'wxr_variables', ( $wxr_map['group']['variables'][0]['name'] ?? '' ) === 'feevar' && ( $wxr_map['group']['variables'][0]['rules'][0]['variable'] ?? '' ) === 'lookuptable(cutting;width;5)', wp_json_encode( $wxr_map['group']['variables'] ?? null ) );

	/* ---------------------------------------------------- OPF archive round trip */
	$post = get_post( $source_id );
	$package = Exporter::build_package( [ [
		'id' => $source_id,
		'title' => $title,
		'status' => $post->post_status,
		'menu_order' => $post->menu_order,
		'language' => '',
		'data' => $source_stored,
	] ], [ 'type' => 'group', 'id' => $source_id ] );
	$write( 'opf-archive.json', $package );
	$record( 'archive_warns_linked_products', in_array( 'product_target_ids_may_not_match', $package['groups'][0]['warnings'] ?? [], true ) );
	$decoded = ArchiveImporter::decode( (string) wp_json_encode( $package ) );
	$dry = ArchiveImporter::import( $decoded, false );
	$record( 'archive_dry_run', 1 === $dry['imported'] && 0 === $dry['skipped'] && 'dry-run' === $dry['mode'] );
	$committed = ArchiveImporter::import( $decoded, true );
	$archive_id = (int) ( $committed['groups'][0]['opf_id'] ?? 0 );
	$owned_posts[] = $archive_id;
	$record( 'archive_commit', 1 === $committed['imported'] && $archive_id > 0 );
	$archive_data = json_decode( (string) get_post( $archive_id )->post_content, true );
	$record( 'archive_preserves_products', $archive_data === $source_stored, 'archive import changed products/variables data' );
	$archive_notes = get_post_meta( $archive_id, '_opf_needs_review', true );
	$record( 'archive_review_draft', 'draft' === get_post( $archive_id )->post_status && is_array( $archive_notes ) && false !== strpos( implode( ' ', $archive_notes ), 'product' ), wp_json_encode( $archive_notes ) );
	$repeat = ArchiveImporter::import( $decoded, true );
	$record( 'archive_repeat_idempotent', 0 === $repeat['imported'] && 1 === $repeat['skipped'] );
	$write( 'archive-import.json', [ 'dry' => $dry, 'committed' => $committed, 'repeat' => $repeat ] );

	/* ------------------------------------------------------------- summary */
	$result['fields'] = [
		'linked' => $linked,
		'related' => $related,
		'addons' => $addons,
		'artwork' => $artwork,
		'fee' => $fee,
	];
	$result['passed'] = count( array_filter( $result['assertions'], static fn( $a ) => $a['ok'] ) );
	$result['total'] = count( $result['assertions'] );
	$write( 'php-results.json', $result );
	echo wp_json_encode( [ 'passed' => $result['passed'], 'total' => $result['total'], 'needs_review' => $map1['needs_review'], 'notes' => $map1['notes'] ] ), "\n";
	echo sprintf( "ok WAPF products/upload/variables round-trip: %d/%d checks\n", $result['passed'], $result['total'] );
} finally {
	foreach ( array_unique( array_map( 'intval', $owned_posts ) ) as $post_id ) {
		if ( $post_id > 0 ) {
			wp_delete_post( $post_id, true );
		}
	}
	$after = $snapshot();
	$write( 'post-cleanup.json', [ 'before' => $baseline, 'after' => $after, 'equal' => $baseline === $after ] );
	if ( $baseline !== $after ) {
		echo "CLEANUP MISMATCH\n" . wp_json_encode( [ 'before' => $baseline, 'after' => $after ], JSON_PRETTY_PRINT ), "\n";
	}
}
