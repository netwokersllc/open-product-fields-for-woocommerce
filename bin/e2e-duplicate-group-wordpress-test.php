<?php
/**
 * Integration test for the real WordPress field-group row action and copy persistence.
 * Run: wp --user=1 eval-file bin/e2e-duplicate-group-wordpress-test.php
 */

require_once __DIR__ . '/../includes/Engine/FieldGroupDuplicator.php';
require_once __DIR__ . '/../includes/Service/Admin/GroupDuplication.php';

use OPF\Service\Admin\GroupDuplication;
use OPF\Service\FieldGroups;

function opf_duplicate_group_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$marker = 'OPF duplicate integration ' . wp_generate_password( 10, false );
$data   = [
	'fields' => [
		[ 'id' => 'material', 'label' => 'Material', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'wood', 'label' => 'Wood', 'pricing' => [ 'type' => 'fixed', 'amount' => 3 ] ] ] ],
		[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'text', 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'material', 'operator' => 'is', 'value' => 'wood' ] ] ] ], 'pricing' => [ 'type' => 'formula', 'formula' => 'checked(material) + [field.material] + [field.material-copy]' ] ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '42' ] ] ] ] ],
];

$source_id = 0;
$copy_id   = 0;
try {
	$source_id = FieldGroups::save( 0, $data, [ 'title' => $marker, 'status' => 'publish' ] );
	opf_duplicate_group_assert( $source_id > 0, 'Could not create source fixture.' );
	$source = get_post( $source_id );
	opf_duplicate_group_assert( $source instanceof WP_Post, 'Source fixture was not persisted.' );

	$actions = GroupDuplication::row_action( [], $source );
	opf_duplicate_group_assert( isset( $actions['duplicate'] ), 'Authorized editor does not see the duplicate row action.' );
	$link = html_entity_decode( $actions['duplicate'], ENT_QUOTES, 'UTF-8' );
	opf_duplicate_group_assert( preg_match( '/href="([^"]+)"/', $link, $match ) === 1, 'Duplicate action has no link.' );
	parse_str( (string) wp_parse_url( $match[1], PHP_URL_QUERY ), $query );
	opf_duplicate_group_assert( 'opf_duplicate_field_group' === ( $query['action'] ?? '' ) && $source_id === (int) ( $query['post_id'] ?? 0 ), 'Duplicate action targets the wrong handler or post.' );
	opf_duplicate_group_assert( wp_verify_nonce( (string) ( $query['_wpnonce'] ?? '' ), 'opf_duplicate_field_group_' . $source_id ), 'Duplicate link nonce is invalid.' );
	$_REQUEST['_wpnonce'] = $query['_wpnonce'];
	check_admin_referer( 'opf_duplicate_field_group_' . $source_id );
	unset( $_REQUEST['_wpnonce'] );

	$copy_id = GroupDuplication::create_copy( $source );
	opf_duplicate_group_assert( is_int( $copy_id ) && $copy_id > 0, 'Authorized copy request failed.' );
	$copy_post = get_post( $copy_id );
	$copy      = FieldGroups::group_from_post( $copy_post );
	$source_group = FieldGroups::group_from_post( get_post( $source_id ) );
	opf_duplicate_group_assert( 'publish' === $copy_post->post_status, 'Copy is not published.' );
	opf_duplicate_group_assert( $marker . ' - Copy' === $copy_post->post_title, 'Copy title does not follow WAPF behavior.' );
	$source_ids = array_column( $source_group->data['fields'], 'id' );
	$copy_ids   = array_column( $copy->data['fields'], 'id' );
	opf_duplicate_group_assert( ! array_intersect( $source_ids, $copy_ids ) && count( $copy_ids ) === count( array_unique( $copy_ids ) ), 'Copy field IDs are not all new and unique.' );
	opf_duplicate_group_assert( $source_ids === [ 'material', 'finish' ], 'Source field IDs were changed.' );
	opf_duplicate_group_assert( $copy->data['fields'][1]['conditionals'][0]['rules'][0]['field'] === $copy->data['fields'][0]['id'], 'Copied conditional still points outside the copied group.' );
	opf_duplicate_group_assert( $copy->data['fields'][1]['pricing']['formula'] === 'checked(' . $copy->data['fields'][0]['id'] . ') + [field.' . $copy->data['fields'][0]['id'] . '] + [field.material-copy]', 'Formula references were not remapped precisely.' );
	opf_duplicate_group_assert( $copy->data['rule_groups'] === $data['rule_groups'], 'Product placement changed in the copy.' );

	wp_set_current_user( 0 );
	opf_duplicate_group_assert( ! isset( GroupDuplication::row_action( [], $source )['duplicate'] ), 'Unauthorized visitor sees a duplicate action.' );
	$error = GroupDuplication::create_copy( $source );
	opf_duplicate_group_assert( is_wp_error( $error ) && 'opf_forbidden' === $error->get_error_code(), 'Unauthorized visitor created a copy.' );

	WP_CLI::log( 'PASS WordPress row action, nonce, authorized published copy, field remapping, and anonymous denial.' );
} finally {
	wp_set_current_user( 1 );
	if ( $copy_id ) {
		wp_delete_post( $copy_id, true );
	}
	if ( $source_id ) {
		wp_delete_post( $source_id, true );
	}
	if ( ( $copy_id && get_post( $copy_id ) ) || ( $source_id && get_post( $source_id ) ) ) {
		throw new RuntimeException( 'Integration fixtures were not fully removed.' );
	}
}
