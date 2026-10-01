<?php
/**
 * E2E proof for WAPF global/local import, dry-run, persistence, and idempotency.
 * Run only against a disposable WordPress clone with WAPF and OPF active:
 * OPF_IMPORT_E2E_ALLOW=1 wp eval-file bin/e2e-wapf-import-lifecycle.php
 *
 * @package open-product-fields-for-woocommerce
 */

if ( '1' !== getenv( 'OPF_IMPORT_E2E_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_IMPORT_E2E_ALLOW=1 only on a disposable WordPress clone.' );
}

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( OPF\Service\Importer::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce and OPF before running this proof.' );
}

global $wpdb;
$global_source_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wapf_product'" );
$local_source_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wapf_fieldgroup' ) );
if ( 0 !== $global_source_count || 0 !== $local_source_count ) {
	throw new RuntimeException( 'Use a clean WAPF source database; this proof will not modify existing WAPF groups.' );
}

$product_id = 0;
$global_id = 0;
$make_payload = static function ( string $field_id, string $label ): array {
	return [
		'id' => 'p_' . $field_id,
		'type' => 'wapf_product',
		'layout' => [ 'mark_required' => true, 'labels_position' => 'above' ],
		'fields' => [
			[
				'id' => $field_id,
				'label' => $label,
				'type' => 'text',
				'required' => true,
				'conditionals' => [],
				'clone' => [ 'enabled' => false ],
				'options' => [ 'choices' => [] ],
				'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ],
			],
		],
		'rule_groups' => [],
	];
};

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

try {
	$product_id = wp_insert_post( [
		'post_type' => 'product',
		'post_status' => 'publish',
		'post_title' => 'OPF WAPF import lifecycle fixture',
	], true );
	if ( is_wp_error( $product_id ) ) {
		throw new RuntimeException( $product_id->get_error_message() );
	}

	$global_payload = $make_payload( 'opf_e2e_global_note', 'Global note' );
	$global_payload['id'] = 'p_opf_e2e_global';
	$global_id = wp_insert_post( [
		'post_type' => 'wapf_product',
		'post_status' => 'publish',
		'post_title' => 'OPF WAPF global import fixture',
		'post_content' => serialize( $global_payload ),
	], true );
	if ( is_wp_error( $global_id ) ) {
		throw new RuntimeException( $global_id->get_error_message() );
	}
	update_post_meta( $product_id, '_wapf_fieldgroup', $make_payload( 'opf_e2e_local_note', 'Local note' ) );

	$dry = OPF\Service\Importer::run( false );
	$assert( 2 === $dry['imported'] && 0 === $dry['skipped'], 'Dry run did not report exactly one global and one local group.' );
	$dry_ids = get_posts( [
		'post_type' => 'opf_field_group',
		'post_status' => 'any',
		'posts_per_page' => -1,
		'fields' => 'ids',
		'meta_key' => '_opf_imported_from',
	] );
	$assert( empty( $dry_ids ), 'Dry run wrote OPF field groups.' );

	$committed = OPF\Service\Importer::run( true );
	$assert( 2 === $committed['imported'] && 0 === $committed['skipped'], 'Commit did not import exactly one global and one local group.' );
	$global_opf_id = 0;
	$local_opf_id = 0;
	foreach ( $committed['groups'] as $entry ) {
		if ( (string) $global_id === (string) $entry['source'] ) {
			$global_opf_id = (int) $entry['opf_id'];
		}
		if ( 'meta:' . $product_id === (string) $entry['source'] ) {
			$local_opf_id = (int) $entry['opf_id'];
		}
	}
	$assert( $global_opf_id > 0 && $local_opf_id > 0, 'Import report did not identify both persisted groups.' );
	$global_post = get_post( $global_opf_id );
	$global_data = json_decode( (string) $global_post->post_content, true );
	$local_post = get_post( $local_opf_id );
	$local_data = json_decode( (string) $local_post->post_content, true );
	$assert( 'publish' === $global_post->post_status && 'publish' === $local_post->post_status, 'Eligible published statuses were not preserved.' );
	$assert( 'Global note' === ( $global_data['fields'][0]['label'] ?? '' ), 'Global field data did not survive persistence.' );
	$assert( 'Local note' === ( $local_data['fields'][0]['label'] ?? '' ), 'Local field data did not survive persistence.' );
	$local_rule = $local_data['rule_groups'][0]['rules'][0] ?? [];
	$assert( 'product' === ( $local_rule['subject'] ?? '' ) && [ (string) $product_id ] === ( $local_rule['terms'] ?? [] ), 'Local group was not attached to its source product.' );

	$repeat = OPF\Service\Importer::run( true );
	$assert( 0 === $repeat['imported'] && 2 === $repeat['skipped'], 'Repeated import did not skip both source groups idempotently.' );

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$plugins = get_plugins();
	$wapf_version = $plugins['advanced-product-fields-for-woocommerce/advanced-product-fields-for-woocommerce.php']['Version'] ?? 'unknown';
	echo sprintf( "ok WAPF %s global/local import, dry-run, product attachment, persistence, idempotent repeat\n", $wapf_version );
} finally {
	$source_keys = [];
	if ( $global_id && ! is_wp_error( $global_id ) ) {
		$source_keys[] = (string) $global_id;
	}
	if ( $product_id && ! is_wp_error( $product_id ) ) {
		$source_keys[] = 'meta:' . $product_id;
	}
	foreach ( $source_keys as $source_key ) {
		$imported_ids = get_posts( [
			'post_type' => 'opf_field_group',
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'meta_key' => '_opf_imported_from',
			'meta_value' => $source_key,
		] );
		foreach ( $imported_ids as $imported_id ) {
			wp_delete_post( (int) $imported_id, true );
		}
	}
	if ( $global_id && ! is_wp_error( $global_id ) ) {
		wp_delete_post( $global_id, true );
	}
	if ( $product_id && ! is_wp_error( $product_id ) ) {
		wp_delete_post( $product_id, true );
	}
}
