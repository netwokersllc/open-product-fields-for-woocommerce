<?php
/**
 * Real WAPF Tools round-trip proof for the impexp lane.
 *
 * Covers the three lane rows against the installed WAPF 3.1.5 reference:
 *  1. WAPF-ADMIN-IMPORT-EXPORT — a real WAPF Tools export fixture is produced
 *     by WAPF's own `field_group_to_raw_fields_json()`, imported through
 *     `WapfMapper`, re-exported by `WapfExporter`, and re-imported by WAPF's
 *     own `raw_json_to_field_group()`. Variation (`product_var`), attribute
 *     (`patts`/`var_att`), and product-type placement survive, and the pair
 *     reaches a fixed point.
 *  2. WAPF-FIELD-UPLOAD — `file` field mapping (multiple/maxsize/accept,
 *     including WAPF's alternate-extension group `jpg|jpeg|jpe`) round-trips
 *     through the native parser and WXR.
 *  3. WAPF-FIELD-CONTENT-IMAGE — OPF `content_image` → WAPF `img` export with
 *     `image`/`attachment` survives the native reimport and WXR reparse.
 *
 * Run only on the dedicated disposable clone with WAPF Extended active:
 *   OPF_IMPEXP_E2E_ALLOW=1 OPF_IMPEXP_E2E_WP=/tmp/opf-image-valfix-wp/ \
 *   OPF_IMPEXP_E2E_ARTIFACTS=/tmp/opf-lane-impexp-evidence \
 *   wp --path=/tmp/opf-image-valfix-wp eval-file bin/e2e-impexp-tools-roundtrip.php
 *
 * @package open-product-fields-for-woocommerce
 */

use OPF\Engine\WapfMapper;
use OPF\Service\WapfExporter;
use OPF\Service\WapfWxrExporter;
use SW_WAPF_PRO\Includes\Classes\Field_Groups as Native_Field_Groups;

if ( '1' !== getenv( 'OPF_IMPEXP_E2E_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_IMPEXP_E2E_ALLOW=1 only on the dedicated disposable clone.' );
}
$expected_wp = (string) getenv( 'OPF_IMPEXP_E2E_WP' );
if ( '' === $expected_wp || rtrim( $expected_wp, '/' ) . '/' !== ABSPATH ) {
	throw new RuntimeException( 'Set OPF_IMPEXP_E2E_WP to the disposable clone ABSPATH.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( WapfExporter::class ) || ! class_exists( WapfWxrExporter::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce and OPF before running this proof.' );
}
if ( ! class_exists( Native_Field_Groups::class ) || ! function_exists( 'wapf_pro' ) ) {
	throw new RuntimeException( 'Activate WAPF Extended (reference plugin) before running this proof.' );
}

$artifacts = rtrim( (string) ( getenv( 'OPF_IMPEXP_E2E_ARTIFACTS' ) ?: '/tmp/opf-lane-impexp-evidence' ), '/' );
if ( ! is_dir( $artifacts ) ) {
	throw new RuntimeException( 'Artifact directory does not exist: ' . $artifacts );
}

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

$result = [
	'environment' => [
		'php'         => PHP_VERSION,
		'wordpress'   => get_bloginfo( 'version' ),
		'woocommerce' => WC_VERSION,
		'opf'         => defined( 'OPF_VERSION' ) ? OPF_VERSION : 'unknown',
		'wapf'        => wapf_pro()->get_setting( 'version' ),
		'home_url'    => home_url(),
	],
	'assertions' => [],
];
$record = static function ( string $id, bool $ok, string $detail = '' ) use ( &$result, $assert ): void {
	$result['assertions'][] = [ 'id' => $id, 'ok' => $ok, 'detail' => $detail ];
	$assert( $ok, $id . ( '' !== $detail ? ': ' . $detail : '' ) );
};

/* ------------------------------------------------------------------ fixture */

// A realistic WAPF Tools payload as an operator would paste from a real site:
// a file upload, an informative image, a text field, a select with a field
// condition, and group placement targeting products/variations/attributes/type.
$attachment_ids = get_posts( [ 'post_type' => 'attachment', 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'inherit' ] );
$attachment_id  = (int) ( $attachment_ids[0] ?? 0 );

$raw_fixture = [
	'id'          => 910001,
	'type'        => 'wapf_product',
	'fields'      => [
		[
			'id'          => 'artwork',
			'label'       => 'Artwork',
			'description' => '',
			'type'        => 'file',
			'required'    => true,
			'class'       => '',
			'width'       => 100,
			'conditionals' => [],
			'multiple'    => true,
			'maxsize'     => 4.5,
			'accept'      => 'jpg|jpeg|jpe,pdf',
			'hide_order'  => 'true',
			'pricing'     => [ 'type' => 'fixed', 'amount' => 0, 'enabled' => false ],
		],
		[
			'id'          => 'fabric-guide',
			'label'       => 'Fabric guide',
			'description' => '',
			'type'        => 'img',
			'required'    => false,
			'class'       => '',
			'width'       => 100,
			'conditionals' => [],
			'image'       => 'https://example.test/fabric.jpg',
			'attachment'  => $attachment_id,
			'pricing'     => [ 'type' => 'fixed', 'amount' => 0, 'enabled' => false ],
		],
		[
			'id'          => 'width',
			'label'       => 'Width',
			'description' => '',
			'type'        => 'number',
			'required'    => false,
			'class'       => '',
			'width'       => 100,
			'conditionals' => [],
			'pricing'     => [ 'type' => 'fixed', 'amount' => 0, 'enabled' => false ],
		],
		[
			'id'          => 'finish',
			'label'       => 'Finish',
			'description' => '',
			'type'        => 'select',
			'required'    => false,
			'class'       => '',
			'width'       => 100,
			'conditionals' => [ [ 'rules' => [ [ 'field' => 'width', 'condition' => 'gt', 'value' => '3' ] ] ] ],
			'choices'     => [
				[ 'slug' => 'linen', 'label' => 'Linen', 'selected' => true, 'disabled' => false, 'pricing_type' => 'none', 'pricing_amount' => 0 ],
				[ 'slug' => 'satin', 'label' => 'Satin', 'selected' => false, 'disabled' => false, 'pricing_type' => 'fixed', 'pricing_amount' => 2 ],
			],
			'pricing'     => [ 'type' => 'fixed', 'amount' => 0, 'enabled' => false ],
		],
	],
	'conditions'  => [
		[ 'rules' => [
			[ 'condition' => 'products', 'subject' => 'product', 'value' => [ [ 'id' => '12', 'text' => 'Product 12' ] ] ],
			[ 'condition' => 'product_var', 'subject' => 'product_variation', 'value' => [ [ 'id' => '55', 'text' => 'Variation #55' ] ] ],
			[ 'condition' => 'patts', 'subject' => 'var_att', 'value' => [ [ 'id' => 'color|red', 'text' => 'Red' ] ] ],
			[ 'condition' => 'product_type', 'subject' => 'product_type', 'value' => [ [ 'id' => 'variable', 'text' => 'Variable' ] ] ],
		] ],
		[ 'rules' => [
			[ 'condition' => '!patts', 'subject' => 'var_att', 'value' => [ [ 'id' => 'size|xl', 'text' => 'XL' ] ] ],
		] ],
	],
	'layout'      => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ],
	'variables'   => [],
];

/* ------------------------------------ produce the real WAPF Tools export file */
$native_model = Native_Field_Groups::raw_json_to_field_group( $raw_fixture );
$assert( $native_model instanceof \SW_WAPF_PRO\Includes\Models\FieldGroup, 'WAPF raw_json_to_field_group returned no model.' );

$tools_export = [
	'fields'      => Native_Field_Groups::field_group_to_raw_fields_json( $native_model ),
	'conditions'  => array_map( static function ( $group ) {
		return [ 'rules' => array_map( static function ( $rule ) {
			return [ 'condition' => $rule->condition, 'subject' => $rule->subject, 'value' => $rule->value ];
		}, $group->rules ) ];
	}, $native_model->rules_groups ),
	'layout'      => $native_model->layout,
	'variables'   => $native_model->variables,
];
$write( 'wapf-tools-export-fixture.json', $tools_export );
$write( 'wapf-native-model.json', $native_model->to_array() );

/* ------------------------------------------- OPF import of the WAPF fixture */
$map1 = WapfMapper::map( $native_model->to_array() );
$write( 'opf-imported-group-1.json', [ 'needs_review' => $map1['needs_review'], 'notes' => $map1['notes'], 'group' => $map1['group'] ] );
$fields1 = [];
foreach ( $map1['group']['fields'] as $field ) {
	$fields1[ $field['id'] ] = $field;
}

/* ------------------------------------------- row 1: placement migration */
$rules1 = $map1['group']['rule_groups'][0]['rules'] ?? [];
$by_subject = [];
foreach ( $rules1 as $rule ) {
	$by_subject[ $rule['subject'] ] = $rule;
}
$record( 'placement_product', ( $by_subject['product']['operator'] ?? '' ) === 'in' && ( $by_subject['product']['terms'] ?? [] ) === [ '12' ], wp_json_encode( $rules1 ) );
$record( 'placement_product_var', ( $by_subject['product_var']['operator'] ?? '' ) === 'in' && ( $by_subject['product_var']['terms'] ?? [] ) === [ '55' ], wp_json_encode( $rules1 ) );
$record( 'placement_var_att', ( $by_subject['var_att']['operator'] ?? '' ) === 'in' && ( $by_subject['var_att']['terms'] ?? [] ) === [ 'color|red' ], wp_json_encode( $rules1 ) );
$record( 'placement_product_type', ( $by_subject['product_type']['operator'] ?? '' ) === 'in' && ( $by_subject['product_type']['terms'] ?? [] ) === [ 'variable' ], wp_json_encode( $rules1 ) );
$negated = $map1['group']['rule_groups'][1]['rules'][0] ?? null;
$record( 'placement_negated_var_att', ( $negated['subject'] ?? '' ) === 'var_att' && ( $negated['operator'] ?? '' ) === 'not_in' && ( $negated['terms'] ?? [] ) === [ 'size|xl' ], wp_json_encode( $negated ) );
$record( 'placement_no_drops', false === strpos( implode( ' | ', $map1['notes'] ), 'placement condition' ), implode( ' | ', $map1['notes'] ) );

/* ------------------------------------------- row 2: upload mapping */
$artwork = $fields1['artwork'] ?? null;
$record( 'upload_type', is_array( $artwork ) && 'upload' === ( $artwork['type'] ?? '' ) );
$record( 'upload_multiple', true === ( $artwork['multiple'] ?? null ) );
$record( 'upload_max_size', is_array( $artwork ) && 4.5 === (float) ( $artwork['max_size'] ?? 0 ), wp_json_encode( $artwork['max_size'] ?? null ) );
$record( 'upload_accept_alt_group', ( $artwork['accepted_types'] ?? [] ) === [ 'jpg', 'jpeg', 'jpe', 'pdf' ], wp_json_encode( $artwork['accepted_types'] ?? null ) );
$record( 'upload_hide_order', true === ( $artwork['hide_order'] ?? null ) );

/* ------------------------------------------- row 3: content image mapping */
$guide = $fields1['fabric-guide'] ?? null;
$record( 'content_image_type', is_array( $guide ) && 'content_image' === ( $guide['type'] ?? '' ) );
$record( 'content_image_url', ( $guide['image_url'] ?? '' ) === 'https://example.test/fabric.jpg', wp_json_encode( $guide['image_url'] ?? null ) );
$record( 'content_image_attachment', (int) ( $guide['image_id'] ?? 0 ) === $attachment_id, 'expected attachment ' . $attachment_id . ' got ' . ( $guide['image_id'] ?? 'null' ) );

/* ------------------------------------------- plain field condition still maps */
$finish = $fields1['finish'] ?? null;
$record( 'field_conditional', ( $finish['conditionals'][0]['rules'][0]['field'] ?? '' ) === 'width' && ( $finish['conditionals'][0]['rules'][0]['operator'] ?? '' ) === 'greater' && ( $finish['conditionals'][0]['rules'][0]['value'] ?? '' ) === '3', wp_json_encode( $finish['conditionals'] ?? null ) );

/* ------------------------------------------- OPF export → WAPF reimport */
$payload = WapfExporter::build_payload( $map1['group'] );
$write( 'opf-exported-wapf-payload.json', $payload );

$exported_conditions = [];
foreach ( $payload['conditions'][0]['rules'] ?? [] as $rule ) {
	$exported_conditions[ $rule['subject'] ] = $rule;
}
$record( 'export_product_var', ( $exported_conditions['product_variation']['condition'] ?? '' ) === 'product_var', wp_json_encode( $payload['conditions'] ?? null ) );
$record( 'export_var_att', ( $exported_conditions['var_att']['condition'] ?? '' ) === 'patts' && ( $exported_conditions['var_att']['value'][0]['id'] ?? '' ) === 'color|red', wp_json_encode( $exported_conditions['var_att'] ?? null ) );
$record( 'export_product_type', ( $exported_conditions['product_type']['condition'] ?? '' ) === 'product_type', wp_json_encode( $exported_conditions['product_type'] ?? null ) );
$record( 'export_negated_patts', ( $payload['conditions'][1]['rules'][0]['subject'] ?? '' ) === 'var_att' && ( $payload['conditions'][1]['rules'][0]['condition'] ?? '' ) === '!patts', wp_json_encode( $payload['conditions'][1] ?? null ) );
$exported_upload = null;
foreach ( $payload['fields'] as $field ) {
	if ( 'artwork' === $field['id'] ) {
		$exported_upload = $field;
	}
}
$record( 'export_upload_file', ( $exported_upload['type'] ?? '' ) === 'file' && true === ( $exported_upload['multiple'] ?? null ) && 4.5 === (float) ( $exported_upload['maxsize'] ?? 0 ) && ( $exported_upload['accept'] ?? '' ) === 'jpg,jpeg,jpe,pdf', wp_json_encode( $exported_upload ) );
$exported_guide = null;
foreach ( $payload['fields'] as $field ) {
	if ( 'fabric-guide' === $field['id'] ) {
		$exported_guide = $field;
	}
}
$record( 'export_content_image', ( $exported_guide['type'] ?? '' ) === 'img' && ( $exported_guide['image'] ?? '' ) === 'https://example.test/fabric.jpg' && (int) ( $exported_guide['attachment'] ?? 0 ) === $attachment_id, wp_json_encode( $exported_guide ) );

$native_reimport = Native_Field_Groups::raw_json_to_field_group( $payload + [ 'id' => 910002, 'type' => 'wapf_product' ] );
$assert( $native_reimport instanceof \SW_WAPF_PRO\Includes\Models\FieldGroup, 'WAPF could not reimport the OPF-exported Tools payload.' );
$map2 = WapfMapper::map( $native_reimport->to_array() );
$write( 'opf-imported-group-2.json', [ 'needs_review' => $map2['needs_review'], 'notes' => $map2['notes'], 'group' => $map2['group'] ] );
$record( 'tools_roundtrip_fixed_point', $map1['group'] === $map2['group'], 'second-generation OPF group differs after WAPF Tools reimport' );

/* ------------------------------------------- WXR native path (rows 2 & 3) */
$author = get_userdata( (int) get_current_user_id() );
$wxr = WapfWxrExporter::build_document( [ [
	'id' => 910003,
	'title' => 'OPF impexp round-trip fixture',
	'status' => 'publish',
	'menu_order' => 0,
	'language' => '',
	'data' => $map1['group'],
] ], [ 'site_url' => home_url(), 'site_title' => get_bloginfo( 'name' ), 'language' => get_bloginfo( 'language' ) ] );
file_put_contents( $artifacts . '/impexp-wxr.xml', $wxr );
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
	$wxr_fields[ $field['id'] ] = $field;
}
$record( 'wxr_upload', ( $wxr_fields['artwork']['type'] ?? '' ) === 'upload' && true === ( $wxr_fields['artwork']['multiple'] ?? null ) && 4.5 === (float) ( $wxr_fields['artwork']['max_size'] ?? 0 ) && ( $wxr_fields['artwork']['accepted_types'] ?? [] ) === [ 'jpg', 'jpeg', 'jpe', 'pdf' ], wp_json_encode( $wxr_fields['artwork'] ?? null ) );
$record( 'wxr_content_image', ( $wxr_fields['fabric-guide']['type'] ?? '' ) === 'content_image' && ( $wxr_fields['fabric-guide']['image_url'] ?? '' ) === 'https://example.test/fabric.jpg' && (int) ( $wxr_fields['fabric-guide']['image_id'] ?? 0 ) === $attachment_id, wp_json_encode( $wxr_fields['fabric-guide'] ?? null ) );
$wxr_rules = [];
foreach ( ( $wxr_map['group']['rule_groups'][0]['rules'] ?? [] ) as $rule ) {
	$wxr_rules[ $rule['subject'] ] = $rule;
}
$record( 'wxr_placement', ( $wxr_rules['product_var']['terms'] ?? [] ) === [ '55' ] && ( $wxr_rules['var_att']['terms'] ?? [] ) === [ 'color|red' ] && ( $wxr_rules['product_type']['terms'] ?? [] ) === [ 'variable' ], wp_json_encode( $wxr_rules ) );

/* ------------------------------------------------------------- summary */
$result['fields'] = [ 'artwork' => $artwork, 'fabric-guide' => $guide, 'finish' => $finish ];
$result['passed'] = count( array_filter( $result['assertions'], static fn( $a ) => $a['ok'] ) );
$result['total'] = count( $result['assertions'] );
$write( 'impexp-results.json', $result );
echo wp_json_encode( [ 'passed' => $result['passed'], 'total' => $result['total'], 'needs_review' => $map1['needs_review'], 'notes' => $map1['notes'] ] ), "\n";
echo sprintf( "ok impexp WAPF Tools round-trip: %d/%d checks\n", $result['passed'], $result['total'] );
