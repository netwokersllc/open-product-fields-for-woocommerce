<?php
/**
 * Read-only export of legacy WAPF payloads from the current site.
 *
 * Usage (on the site that still runs WAPF):
 *   wp eval-file bin/wapf-export.php /tmp/wapf-export.json
 *
 * Performs SELECTs only. The output feeds bin/e2e-import-test.php and is a
 * safety snapshot for the migration.
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$target = $argv[1] ?? ( defined( 'WP_CLI' ) ? '/tmp/wapf-export.json' : '' );
if ( ! is_string( $target ) || ! str_ends_with( $target, '.json' ) ) {
	$target = '/tmp/wapf-export.json';
}

$rows = $wpdb->get_results(
	"SELECT p.ID, p.post_title, p.post_status, p.post_content
	 FROM {$wpdb->posts} p
	 WHERE p.post_type = 'wapf_product' AND p.post_content <> ''"
);

$export = [ 'global' => [], 'local' => [] ];
foreach ( $rows as $r ) {
	$export['global'][] = [
		'id'      => (int) $r->ID,
		'title'   => $r->post_title,
		'status'  => $r->post_status,
		'content' => $r->post_content,
	];
}

$metas = $wpdb->get_results(
	"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wapf_fieldgroup' AND meta_value <> ''"
);
foreach ( $metas as $m ) {
	$export['local'][] = [
		'product_id' => (int) $m->post_id,
		'content'    => $m->meta_value,
	];
}

file_put_contents( $target, wp_json_encode( $export ) );
WP_CLI::success( sprintf( 'Exported %d global + %d local WAPF payloads to %s (read-only).',
	count( $export['global'] ), count( $export['local'] ), $target ) );
