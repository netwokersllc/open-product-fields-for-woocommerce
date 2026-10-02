<?php
/**
 * Create/remove an isolated field-group fixture for e2e-admin-title-search-browser-test.mjs.
 *
 * @package open-product-fields-for-woocommerce
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$mode  = (string) ( $args[0] ?? '' );
$token = sanitize_key( (string) ( $args[1] ?? '' ) );

if ( '' === $token ) {
	WP_CLI::error( 'A fixture token is required.' );
}

$title = 'OPF Admin Title Search ' . $token;

if ( 'create' === $mode ) {
	$fields = [
		[
			'id'           => 'search-fixture',
			'label'        => 'Search fixture',
			'type'         => 'text',
			'required'     => false,
			'width'        => 100,
			'choices'      => [],
			'pricing'      => [ 'type' => 'none', 'amount' => 0, 'formula' => '', 'formula_raw' => '', 'per_unit' => false ],
			'conditionals' => [],
		],
	];

	$post_id = wp_insert_post(
		[
			'post_type'    => 'opf_field_group',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => wp_json_encode( [ 'schema' => 1, 'fields' => $fields, 'rule_groups' => [] ] ),
		],
		true
	);

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( $post_id->get_error_message() );
	}
	update_post_meta( $post_id, '_opf_admin_search_fixture', $token );
	WP_CLI::line( (string) $post_id );
	return;
}

if ( 'cleanup' === $mode ) {
	$ids     = get_posts(
		[
			'post_type'      => 'opf_field_group',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_opf_admin_search_fixture',
			'meta_value'     => $token,
		]
	);
	$removed = 0;
	foreach ( $ids as $post_id ) {
		if ( wp_delete_post( absint( $post_id ), true ) ) {
			++$removed;
		}
	}
	WP_CLI::line( (string) $removed );
	return;
}

WP_CLI::error( 'Mode must be create or cleanup.' );
