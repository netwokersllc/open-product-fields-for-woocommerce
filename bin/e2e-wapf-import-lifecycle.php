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
$review_id = 0;
$malformed_id = 0;
$make_payload = static function ( string $prefix, string $label ): array {
	return [
		'id' => 'p_' . $prefix,
		'type' => 'wapf_product',
		'layout' => [ 'mark_required' => true, 'labels_position' => 'above' ],
		'fields' => [
			[
				'id' => $prefix . '_choice',
				'label' => $label . ' choice',
				'type' => 'select',
				'required' => false,
				'conditionals' => [],
				'clone' => [ 'enabled' => false ],
				'options' => [ 'choices' => [
					[ 'slug' => 'allow', 'label' => 'Allow', 'selected' => true, 'disabled' => false, 'options' => [], 'pricing_type' => 'fixed', 'pricing_amount' => 3 ],
				] ],
				'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ],
			],
			[
				'id' => $prefix . '_note',
				'label' => $label . ' note',
				'type' => 'text',
				'required' => true,
				'conditionals' => [ [ 'rules' => [ [ 'field' => $prefix . '_choice', 'condition' => '==', 'value' => 'allow' ] ] ] ],
				'clone' => [ 'enabled' => false ],
				'options' => [ 'choices' => [] ],
				'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ],
			],
			[
				'id' => $prefix . '_paragraph',
				'label' => '',
				'type' => 'content',
				'options' => [ 'p_content' => 'Static setup instructions.' ],
				'required' => false,
				'conditionals' => [],
				'clone' => [ 'enabled' => false ],
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

	$global_payload = $make_payload( 'opf_e2e_global', 'Global' );
	$global_id = wp_insert_post( [
		'post_type' => 'wapf_product',
		'post_status' => 'publish',
		'post_title' => 'OPF WAPF global import fixture',
		'post_content' => serialize( $global_payload ),
	], true );
	if ( is_wp_error( $global_id ) ) {
		throw new RuntimeException( $global_id->get_error_message() );
	}
	$review_payload = $make_payload( 'opf_e2e_review', 'Review' );
	$review_payload['fields'][] = [
		'id' => 'opf_e2e_unsupported_file',
		'label' => 'Unsupported upload',
		'type' => 'file',
		'required' => false,
		'conditionals' => [],
		'clone' => [ 'enabled' => false ],
		'options' => [ 'choices' => [] ],
		'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ],
	];
	$review_payload['fields'][] = [
		'id' => 'opf_e2e_repeat_name',
		'label' => 'Repeated name',
		'type' => 'text',
		'required' => false,
		'conditionals' => [],
		'clone' => [ 'enabled' => true, 'type' => 'button', 'max' => 6 ],
		'options' => [ 'choices' => [] ],
		'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ],
	];
	$review_id = wp_insert_post( [
		'post_type' => 'wapf_product',
		'post_status' => 'publish',
		'post_title' => 'OPF WAPF review-required fixture',
		'post_content' => serialize( $review_payload ),
	], true );
	if ( is_wp_error( $review_id ) ) {
		throw new RuntimeException( $review_id->get_error_message() );
	}
	$malformed_id = wp_insert_post( [
		'post_type' => 'wapf_product',
		'post_status' => 'publish',
		'post_title' => 'OPF WAPF malformed fixture',
		'post_content' => 'a:2:{broken serialized payload',
	], true );
	if ( is_wp_error( $malformed_id ) ) {
		throw new RuntimeException( $malformed_id->get_error_message() );
	}
	update_post_meta( $product_id, '_wapf_fieldgroup', $make_payload( 'opf_e2e_local', 'Local' ) );

	$dry = OPF\Service\Importer::run( false );
	$assert( 3 === $dry['imported'] && 1 === $dry['skipped'], 'Dry run did not report three importable groups and one malformed source.' );
	$dry_review = null;
	$dry_malformed = null;
	foreach ( $dry['groups'] as $entry ) {
		if ( (string) $review_id === (string) $entry['source'] ) {
			$dry_review = $entry;
		}
		if ( (string) $malformed_id === (string) $entry['source'] ) {
			$dry_malformed = $entry;
		}
	}
	$assert( 'draft' === ( $dry_review['status'] ?? '' ) && ! empty( $dry_review['needs_review'] ), 'Dry run did not report the review-required group as a draft.' );
	$assert( 'unparseable' === ( $dry_malformed['result'] ?? '' ), 'Dry run did not identify the malformed source.' );
	foreach ( [ (string) $global_id, (string) $review_id, 'meta:' . $product_id ] as $source_key ) {
		$dry_ids = get_posts( [
			'post_type' => 'opf_field_group',
			'post_status' => 'any',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_key' => '_opf_imported_from',
			'meta_value' => $source_key,
		] );
		$assert( empty( $dry_ids ), 'Dry run wrote an OPF field group.' );
	}

	$committed = OPF\Service\Importer::run( true );
	$assert( 3 === $committed['imported'] && 1 === $committed['skipped'], 'Commit did not import three groups and report the malformed source.' );
	$global_opf_id = 0;
	$local_opf_id = 0;
	$review_opf_id = 0;
	$malformed_reported = false;
	foreach ( $committed['groups'] as $entry ) {
		if ( (string) $global_id === (string) $entry['source'] ) {
			$global_opf_id = (int) $entry['opf_id'];
		}
		if ( 'meta:' . $product_id === (string) $entry['source'] ) {
			$local_opf_id = (int) $entry['opf_id'];
		}
		if ( (string) $review_id === (string) $entry['source'] ) {
			$review_opf_id = (int) ( $entry['opf_id'] ?? 0 );
		}
		if ( (string) $malformed_id === (string) $entry['source'] && 'unparseable' === ( $entry['result'] ?? '' ) ) {
			$malformed_reported = true;
		}
	}
	$assert( $global_opf_id > 0 && $local_opf_id > 0 && $review_opf_id > 0, 'Import report did not identify all persisted groups.' );
	$assert( $malformed_reported, 'Malformed source payload was not reported as unparseable.' );
	$global_post = get_post( $global_opf_id );
	$global_data = json_decode( (string) $global_post->post_content, true );
	$local_post = get_post( $local_opf_id );
	$local_data = json_decode( (string) $local_post->post_content, true );
	$assert( 'publish' === $global_post->post_status && 'publish' === $local_post->post_status, 'Eligible published statuses were not preserved.' );
	$assert( 'select' === ( $global_data['fields'][0]['type'] ?? '' ) && 'Allow' === ( $global_data['fields'][0]['choices'][0]['label'] ?? '' ) && 3.0 === (float) ( $global_data['fields'][0]['choices'][0]['pricing']['amount'] ?? 0 ), 'Global choice and pricing data did not survive mapping: ' . wp_json_encode( $global_data['fields'][0] ?? null ) );
	$assert( 'text' === ( $global_data['fields'][1]['type'] ?? '' ) && 'Global note' === ( $global_data['fields'][1]['label'] ?? '' ), 'Global conditional field data did not survive persistence.' );
	$assert( $global_data['fields'][0]['id'] === ( $global_data['fields'][1]['conditionals'][0]['rules'][0]['field'] ?? '' ) && 'allow' === ( $global_data['fields'][1]['conditionals'][0]['rules'][0]['value'] ?? '' ), 'Global conditional field reference was not remapped.' );
	$assert( 'paragraph' === ( $global_data['fields'][2]['type'] ?? '' ) && 'Static setup instructions.' === ( $global_data['fields'][2]['content'] ?? '' ), 'Global WAPF paragraph content did not survive import.' );
	$assert( 'select' === ( $local_data['fields'][0]['type'] ?? '' ) && 'Allow' === ( $local_data['fields'][0]['choices'][0]['label'] ?? '' ) && 3.0 === (float) ( $local_data['fields'][0]['choices'][0]['pricing']['amount'] ?? 0 ), 'Local choice and pricing data did not survive mapping.' );
	$assert( 'Local note' === ( $local_data['fields'][1]['label'] ?? '' ), 'Local conditional field data did not survive persistence.' );
	$assert( $local_data['fields'][0]['id'] === ( $local_data['fields'][1]['conditionals'][0]['rules'][0]['field'] ?? '' ) && 'allow' === ( $local_data['fields'][1]['conditionals'][0]['rules'][0]['value'] ?? '' ), 'Local conditional field reference was not remapped.' );
	$assert( 'paragraph' === ( $local_data['fields'][2]['type'] ?? '' ) && 'Static setup instructions.' === ( $local_data['fields'][2]['content'] ?? '' ), 'Local WAPF paragraph content did not survive import.' );
	$local_rule = $local_data['rule_groups'][0]['rules'][0] ?? [];
	$assert( 'product' === ( $local_rule['subject'] ?? '' ) && [ (string) $product_id ] === ( $local_rule['terms'] ?? [] ), 'Local group was not attached to its source product.' );
	$review_post = get_post( $review_opf_id );
	$review_data = json_decode( (string) $review_post->post_content, true );
	$review_notes = get_post_meta( $review_opf_id, '_opf_needs_review', true );
	$repeat_field = null;
	foreach ( (array) ( $review_data['fields'] ?? [] ) as $field ) {
		if ( 'Repeated name' === ( $field['label'] ?? '' ) ) {
			$repeat_field = $field;
			break;
		}
	}
	$assert( 'draft' === $review_post->post_status, 'A group with unsupported source data was published instead of held for review.' );
	$assert( is_array( $review_notes ) && false !== strpos( implode( ' ', $review_notes ), 'unsupported field types dropped' ), 'Review-required source details were not recorded.' );
	$assert( [ 'enabled' => true, 'mode' => 'button', 'max' => 6 ] === ( $repeat_field['repeat'] ?? null ), 'WAPF button clone mode and maximum did not survive import persistence: ' . wp_json_encode( [ 'repeat_field' => $repeat_field, 'notes' => $review_notes ] ) );
	$assert( false !== strpos( implode( ' ', $review_notes ), 'repeat runtime is not implemented' ), 'Imported repeater was not explicitly held for runtime review.' );

	$repeat = OPF\Service\Importer::run( true );
	$assert( 0 === $repeat['imported'] && 4 === $repeat['skipped'], 'Repeated import did not skip three imports and report the malformed source.' );

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$plugins = get_plugins();
	$wapf_version = $plugins['advanced-product-fields-for-woocommerce/advanced-product-fields-for-woocommerce.php']['Version'] ?? 'unknown';
	echo sprintf( "ok WAPF %s global/local import, repeat config preservation, review drafts, malformed report, dry-run, product attachment, persistence, idempotent repeat\n", $wapf_version );
} finally {
	$source_keys = [];
	if ( $global_id && ! is_wp_error( $global_id ) ) {
		$source_keys[] = (string) $global_id;
	}
	if ( $product_id && ! is_wp_error( $product_id ) ) {
		$source_keys[] = 'meta:' . $product_id;
	}
	if ( $review_id && ! is_wp_error( $review_id ) ) {
		$source_keys[] = (string) $review_id;
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
	if ( $review_id && ! is_wp_error( $review_id ) ) {
		wp_delete_post( $review_id, true );
	}
	if ( $malformed_id && ! is_wp_error( $malformed_id ) ) {
		wp_delete_post( $malformed_id, true );
	}
	if ( $product_id && ! is_wp_error( $product_id ) ) {
		wp_delete_post( $product_id, true );
	}
}
