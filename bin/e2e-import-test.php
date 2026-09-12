<?php
/**
 * OPF import verification against REAL WAPF data.
 *
 * Usage (disposable WordPress install with WooCommerce + OPF active):
 *   wp eval-file bin/e2e-import-test.php /tmp/wapf-export.json
 *
 * The export file is produced read-only from the source site:
 *   wp eval-file bin/wapf-export.php /tmp/wapf-export.json
 *
 * Re-creates the legacy wapf_product payloads locally, runs the OPF
 * importer in commit mode, then verifies mapping fidelity, idempotency,
 * and pricing on imported groups. No legacy code is executed.
 */

defined( 'ABSPATH' ) || exit;

global $argv, $failures;
$path = $argv[1] ?? ( $argv[0] ?? '' );
$path = is_string( $path ) && str_ends_with( $path, '.json' ) ? $path : '/tmp/wapf-export.json';
if ( ! file_exists( $path ) ) {
	WP_CLI::error( "Export file not found: $path" );
}
$export = json_decode( (string) file_get_contents( $path ), true );
if ( ! is_array( $export ) ) {
	WP_CLI::error( 'Invalid export file.' );
}

$failures = 0;
function check( $label, $condition ): void {
	global $failures;
	if ( $condition ) {
		WP_CLI::log( "  ok    $label" );
	} else {
		$failures++;
		WP_CLI::log( "  FAIL  $label" );
	}
}

WP_CLI::log( '== OPF import verification ==' );

// ------------------------------------------------- recreate legacy payloads.
foreach ( $export['global'] as $row ) {
	$exists = get_posts( [ 'post_type' => 'wapf_product', 'posts_per_page' => 1, 'fields' => 'ids', 'title' => $row['title'] ] );
	if ( $exists ) {
		continue;
	}
	wp_insert_post(
		[
			'post_type'    => 'wapf_product',
			'post_status'  => 'publish',
			'post_title'   => $row['title'],
			'post_content' => $row['content'],
		]
	);
}
foreach ( $export['local'] as $row ) {
	// Local payloads live on products; store on an existing product if present.
	$product_id = (int) ( wc_get_products( [ 'limit' => 1, 'return' => 'ids' ] )[0] ?? 0 );
	if ( ! $product_id || get_post_meta( $product_id, '_wapf_fieldgroup', true ) ) {
		continue;
	}
	update_post_meta( $product_id, '_wapf_fieldgroup', $row['content'] );
}

// ------------------------------------------------------------- run import.
$t0       = microtime( true );
$report   = OPF\Service\Importer::run( true );
$seconds  = microtime( true ) - $t0;
WP_CLI::log( sprintf( 'imported=%d skipped=%d repaired=%d review=%d in %.1fs',
	$report['imported'], $report['skipped'], $report['repaired'], count( $report['needs_review'] ), $seconds ) );

check( 'import: groups imported', $report['imported'] > 0 );
check( 'import: nothing unparseable', 0 === count(
	array_filter( $report['groups'], static fn( $g ) => in_array( $g['result'] ?? '', [ 'unparseable', 'unparseable-or-empty' ], true ) )
) );

// --------------------------------------------------- idempotency (re-run).
$report2 = OPF\Service\Importer::run( true );
check( 'import: second run imports nothing new', 0 === $report2['imported'] );

// ------------------------------------------------- mapping fidelity probe.
$groups   = OPF\Service\FieldGroups::all();
$types    = [];
$pricings = [];
foreach ( $groups as $entry ) {
	foreach ( $entry['group']->data['fields'] as $field ) {
		$types[ $field['type'] ] = ( $types[ $field['type'] ] ?? 0 ) + 1;
		foreach ( $field['choices'] as $choice ) {
			$pricings[ $choice['pricing']['type'] ] = ( $pricings[ $choice['pricing']['type'] ] ?? 0 ) + 1;
		}
	}
}
WP_CLI::log( 'field types across imported groups: ' . wp_json_encode( $types ) );
WP_CLI::log( 'choice pricing across imported groups: ' . wp_json_encode( $pricings ) );

check( 'fidelity: swatch fields present', ( $types['swatch'] ?? 0 ) > 0 );
check( 'fidelity: percent pricing preserved', ( $pricings['percent'] ?? 0 ) > 0 );
check( 'fidelity: formula pricing translated', ( $pricings['formula'] ?? 0 ) > 0 );
check( 'fidelity: no formulas still carry qty compensation', 0 === count(
	array_filter( $groups, static function ( $entry ) {
		foreach ( $entry['group']->data['fields'] as $field ) {
			foreach ( $field['choices'] as $choice ) {
				if ( 'formula' === $choice['pricing']['type']
					&& preg_match( '/\*\s*\[\s*qty\s*\]\s*$/i', $choice['pricing']['formula'] ) ) {
					return true;
				}
			}
		}
		return false;
	} )
) );

// ------------------------------------------- pricing sanity on one import.
$priced_group = null;
foreach ( $groups as $entry ) {
	foreach ( $entry['group']->data['fields'] as $field ) {
		foreach ( $field['choices'] as $choice ) {
			if ( 'percent' === $choice['pricing']['type'] ) {
				$priced_group = [ $entry, $field, $choice ];
				break 3;
			}
		}
	}
}
if ( $priced_group ) {
	[ $entry, $field, $choice ] = $priced_group;
	$addon = OPF\Engine\Calculator::field_addon( $field, $choice['slug'], [ 'price' => 100.0, 'qty' => 1, 'addons' => 0.0 ] );
	$expected = 100.0 * ( (float) $choice['pricing']['amount'] / 100 );
	check( 'pricing: imported percent choice computes on a 100.00 base', abs( $addon - $expected ) < 0.001 );
}

// Placement: at least one imported group matches a synthetic tag hit.
$placement_ok = false;
foreach ( $groups as $entry ) {
	foreach ( $entry['group']->data['rule_groups'] as $rule_group ) {
		foreach ( $rule_group['rules'] as $rule ) {
			if ( ! empty( $rule['terms'] ) ) {
				$terms         = $rule['subject'];
				$has_terms     = [ $terms => array_map( 'intval', $rule['terms'] ) ];
				$placement_ok = $placement_ok || OPF\Engine\Evaluator::group_matches( $entry['group']->data, $has_terms, 999999 );
			}
		}
	}
}
check( 'placement: imported rule groups match their own terms', $placement_ok );

WP_CLI::log( '' );
if ( $failures > 0 ) {
	WP_CLI::error( "$failures check(s) failed." );
} else {
	WP_CLI::success( 'All import verification checks passed.' );
}
