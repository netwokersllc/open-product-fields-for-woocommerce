<?php
/**
 * One-time backfill: assign the Polylang language to imported OPF groups from
 * their source WAPF post. Safe to re-run (skips groups that have a language).
 *
 *   wp eval-file bin/backfill-locales.php
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'pll_get_post_language' ) || ! function_exists( 'pll_set_post_language' ) ) {
	WP_CLI::error( 'Polylang is not active.' );
}

$groups = get_posts(
	[
		'post_type'      => 'opf_field_group',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
			[
				'key'     => '_opf_imported_from',
				'compare' => 'EXISTS',
			],
		],
	]
);

$set = 0; $skipped = 0;
foreach ( $groups as $gid ) {
	if ( pll_get_post_language( $gid, 'slug' ) ) {
		$skipped++;
		continue;
	}
	$source = (int) get_post_meta( $gid, '_opf_imported_from', true );
	if ( $source > 0 ) {
		$lang = pll_get_post_language( $source, 'slug' );
		if ( $lang ) {
			pll_set_post_language( $gid, $lang );
			$set++;
		}
	}
}

WP_CLI::success( sprintf( 'Language set on %d groups (%d already had one).', $set, $skipped ) );
