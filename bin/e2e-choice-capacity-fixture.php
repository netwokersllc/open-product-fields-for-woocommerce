<?php
/**
 * Create/remove the large field group used by the authenticated capacity run.
 *
 * @package open-product-fields-for-woocommerce
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( '1' !== getenv( 'OPF_CHOICE_CAPACITY_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	WP_CLI::error( 'Choice-capacity fixture requires explicit opt-in on a /tmp WordPress clone.' );
}

$mode  = (string) ( $args[0] ?? '' );
$token = sanitize_key( (string) ( $args[1] ?? '' ) );

if ( '' === $token ) {
	WP_CLI::error( 'A fixture token is required.' );
}

if ( 'create' === $mode ) {
	$field_count  = max( 2, min( 2048, (int) ( getenv( 'OPF_CHOICE_CAPACITY_FIELDS' ) ?: 512 ) ) );
	$choice_count = max( 2, min( 4096, (int) ( getenv( 'OPF_CHOICE_CAPACITY_CHOICES' ) ?: 512 ) ) );
	$fields       = [];

	for ( $index = 1; $index < $field_count; ++$index ) {
		$fields[] = [
			'id'           => sprintf( 'capacity-field-%04d', $index ),
			'label'        => sprintf( 'Capacity field %04d', $index ),
			'description'  => '',
			'type'         => 'text',
			'required'     => false,
			'width'        => 100,
			'choices'      => [],
			'pricing'      => [ 'type' => 'none', 'amount' => 0, 'formula' => '' ],
			'conditionals' => [],
		];
	}

	$choices = [];
	for ( $index = 1; $index <= $choice_count; ++$index ) {
		$choices[] = [
			'slug'     => sprintf( 'capacity-choice-%04d', $index ),
			'label'    => sprintf( 'Capacity choice %04d', $index ),
			'selected' => false,
			'disabled' => false,
			'pricing'  => [ 'type' => 'none', 'amount' => 0, 'formula' => '' ],
		];
	}
	$fields[] = [
		'id'           => sprintf( 'capacity-field-%04d', $field_count ),
		'label'        => sprintf( 'Capacity field %04d', $field_count ),
		'description'  => '',
		'type'         => 'select',
		'required'     => false,
		'width'        => 100,
		'choices'      => $choices,
		'pricing'      => [ 'type' => 'none', 'amount' => 0, 'formula' => '' ],
		'conditionals' => [],
	];

	$post_id = wp_insert_post(
		[
			'post_type'    => 'opf_field_group',
			'post_status'  => 'publish',
			'post_title'   => 'OPF Choice Capacity ' . $token,
			'post_content' => wp_json_encode( [ 'schema' => 1, 'fields' => $fields, 'rule_groups' => [] ] ),
		],
		true
	);
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( $post_id->get_error_message() );
	}
	update_post_meta( $post_id, '_opf_choice_capacity_fixture', $token );
	WP_CLI::line( (string) $post_id );
	return;
}

if ( 'cleanup' === $mode ) {
	$ids = get_posts(
		[
			'post_type'      => 'opf_field_group',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_opf_choice_capacity_fixture',
			'meta_value'     => $token,
		]
	);
	$removed = 0;
	foreach ( $ids as $post_id ) {
		if ( wp_delete_post( (int) $post_id, true ) ) {
			++$removed;
		}
	}
	WP_CLI::line( (string) $removed );
	return;
}

WP_CLI::error( 'Mode must be create or cleanup.' );
