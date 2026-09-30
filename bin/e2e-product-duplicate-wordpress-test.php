<?php
/**
 * Integration test for OPF product-local field groups during WooCommerce product duplication.
 * Run: wp --user=1 eval-file bin/e2e-product-duplicate-wordpress-test.php
 */

require_once __DIR__ . '/../includes/Engine/FieldGroupDuplicator.php';
require_once __DIR__ . '/../includes/Service/ProductFieldGroupDuplication.php';

use OPF\Service\FieldGroups;
use OPF\Service\ProductFieldGroupDuplication;

function opf_product_duplicate_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

ProductFieldGroupDuplication::init();
$source_product_id = 0;
$duplicate_id      = 0;
$local_group_id    = 0;
$global_group_id   = 0;
$local_copy_id     = 0;
$review_notes      = [ 'Fixture needs source review.' ];

try {
	$source_product = new WC_Product_Simple();
	$source_product->set_name( 'OPF local duplicate fixture' );
	$source_product->set_status( 'publish' );
	$source_product_id = $source_product->save();
	opf_product_duplicate_assert( $source_product_id > 0, 'Could not save source WooCommerce product.' );

	$group_data = [
		'fields' => [
			[ 'id' => 'material', 'label' => 'Material', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'wood', 'label' => 'Wood' ] ] ],
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'text', 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'material', 'operator' => 'is', 'value' => 'wood' ] ] ] ], 'pricing' => [ 'type' => 'formula', 'formula' => '[field.material] + [field.material-extra]' ] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $source_product_id ] ] ] ] ],
	];
	$local_group_id = FieldGroups::save( 0, $group_data, [ 'title' => 'Imported local fixture', 'status' => 'publish' ] );
	opf_product_duplicate_assert( $local_group_id > 0, 'Could not save imported field-group fixture.' );
	update_post_meta( $local_group_id, '_opf_imported_from', 'meta:' . $source_product_id );
	update_post_meta( $local_group_id, '_opf_needs_review', $review_notes );

	$global_group = $group_data;
	$global_group['fields'][0]['id'] = 'global_material';
	$global_group_id = FieldGroups::save( 0, $global_group, [ 'title' => 'Ordinary global fixture', 'status' => 'publish' ] );
	opf_product_duplicate_assert( $global_group_id > 0, 'Could not save ordinary global field-group fixture.' );

	require_once WC()->plugin_path() . '/includes/admin/class-wc-admin-duplicate-product.php';
	$woocommerce_duplicator = new WC_Admin_Duplicate_Product();
	$duplicate              = $woocommerce_duplicator->product_duplicate( wc_get_product( $source_product_id ) );
	$duplicate_id            = (int) $duplicate->get_id();
	opf_product_duplicate_assert( $duplicate_id > 0, 'WooCommerce did not save duplicate product fixture.' );
	do_action( 'woocommerce_product_duplicate', $duplicate, wc_get_product( $source_product_id ) );

	$copies = get_posts(
		[
			'post_type'      => 'opf_field_group',
			'post_status'    => 'any',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_opf_imported_from', 'value' => 'meta:' . $duplicate_id ],
				[ 'key' => '_opf_duplicate_source_group', 'value' => $local_group_id ],
			],
		]
	);
	opf_product_duplicate_assert( 1 === count( $copies ), 'Expected exactly one copied local field group.' );
	$local_copy_id = (int) $copies[0];
	$copy_post     = get_post( $local_copy_id );
	$copy          = FieldGroups::group_from_post( $copy_post );
	$source_group  = FieldGroups::group_from_post( get_post( $local_group_id ) );
	opf_product_duplicate_assert( 'publish' === $copy_post->post_status, 'Copied group did not preserve source status.' );
	opf_product_duplicate_assert( [ 'meta:' . $duplicate_id ] === get_post_meta( $local_copy_id, '_opf_imported_from', false ), 'Copied group provenance does not identify the duplicate product.' );
	opf_product_duplicate_assert( $review_notes === get_post_meta( $local_copy_id, '_opf_needs_review', true ), 'Review-required metadata was not preserved.' );
	opf_product_duplicate_assert( [ (string) $duplicate_id ] === $copy->data['rule_groups'][0]['rules'][0]['terms'], 'Copied group is not attached to the new product.' );
	$source_ids = array_column( $source_group->data['fields'], 'id' );
	$copy_ids   = array_column( $copy->data['fields'], 'id' );
	opf_product_duplicate_assert( ! array_intersect( $source_ids, $copy_ids ), 'Copied field IDs were not regenerated.' );
	opf_product_duplicate_assert( $copy->data['fields'][1]['conditionals'][0]['rules'][0]['field'] === $copy->data['fields'][0]['id'], 'Copied conditional still points at the source field ID.' );
	opf_product_duplicate_assert( $copy->data['fields'][1]['pricing']['formula'] === '[field.' . $copy->data['fields'][0]['id'] . '] + [field.material-extra]', 'Copied formula references were not remapped precisely.' );
	opf_product_duplicate_assert( ! get_post_meta( $global_group_id, '_opf_imported_from', true ), 'Ordinary global group was incorrectly marked as imported.' );

	// WooCommerce's duplicate action may be retried; the source-group marker makes it idempotent.
	do_action( 'woocommerce_product_duplicate', wc_get_product( $duplicate_id ), wc_get_product( $source_product_id ) );
	$copies_after_retry = get_posts(
		[
			'post_type'      => 'opf_field_group',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_opf_imported_from', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => 'meta:' . $duplicate_id, // phpcs:ignore WordPress.DB.SlowDBQuery
		]
	);
	opf_product_duplicate_assert( 1 === count( $copies_after_retry ), 'Repeated duplication action created another copy.' );

	WP_CLI::log( 'PASS Woo product copy, local group and review metadata remapping, global-group exclusion, idempotency.' );
} finally {
	foreach ( [ $local_copy_id, $local_group_id, $global_group_id ] as $group_id ) {
		if ( $group_id ) {
			wp_delete_post( $group_id, true );
		}
	}
	foreach ( [ $duplicate_id, $source_product_id ] as $product_id ) {
		if ( $product_id ) {
			wp_delete_post( $product_id, true );
		}
	}
	if ( $local_copy_id && get_post( $local_copy_id ) || $local_group_id && get_post( $local_group_id ) || $global_group_id && get_post( $global_group_id ) || $duplicate_id && get_post( $duplicate_id ) || $source_product_id && get_post( $source_product_id ) ) {
		throw new RuntimeException( 'Integration fixtures were not fully removed.' );
	}
}
