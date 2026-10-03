<?php
/**
 * WAPF-FIELD-DATE residual proof: the installed WAPF Extended 3.1.5 date
 * option keys survive the OPF import -> OPF WAPF-export -> OPF re-import loop.
 *
 * Source keys audited in the installed package:
 *   3.1.5 `extend/date.php` and `includes/classes/class-field-groups.php`:
 *   `disabled_days`, `min_date`, `max_date`, `disabled_dates`,
 *   `disable_today_after`; Free/Pro field registry adds `disable_past`,
 *   `disable_future`, `disable_today` (class-config.php / class-cart.php).
 *
 * The real WAPF Tools admin parser is not executed: the installed 3.1.5 clone
 * fatals while parsing this clone's pre-existing 700+ `wapf_product` rows, so
 * the round-trip is verified through OPF's own mapper/exporter (installed
 * against the same serialized option shapes WAPF's `raw_json_to_field_group`
 * produces). Bounds are documented, not hidden.
 *
 * Run with: wp eval-file bin/e2e-date-roundtrip.php
 *
 * @package open-product-fields-for-woocommerce
 */

use OPF\Engine\WapfMapper;
use OPF\Service\WapfExporter;
use OPF\Service\WapfWxrExporter;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'WP-CLI only.' );
}

$artifacts = getenv( 'OPF_DATE_RT_ARTIFACT_DIR' ) ?: '/tmp/opf-lane-prodtypes-evidence/date-import-export';
if ( ! is_dir( $artifacts ) ) {
	mkdir( $artifacts, 0755, true );
}

$checks = [];
$check  = static function ( string $name, bool $pass, $detail = null ) use ( &$checks ) {
	$checks[] = [ 'name' => $name, 'pass' => $pass, 'detail' => $detail ];
	if ( ! $pass ) {
		throw new RuntimeException( $name );
	}
	echo "ok $name\n";
};

/**
 * The exact serialized shapes WAPF's `raw_json_to_field_group` stores. WAPF
 * keeps `disabled_days` as a CSV scalar (bare `'0'` preserved) and the date
 * blackouts as `mm-dd-yyyy` / `mm-dd` CSV with space-separated ranges.
 */
$source_fields = [
	[
		'id' => 'full', 'label' => 'Full date', 'type' => 'date', 'required' => false, 'width' => 100,
		'options' => [
			'disable_past'       => true,
			'disable_future'     => false,
			'min_date'           => '02-10-2027',
			'max_date'           => '1y',
			'disabled_days'      => '0,6',
			'disabled_dates'     => '02-10-2027 02-12-2027,12-25',
			'disable_today_after' => '12:00',
		],
	],
	[
		'id' => 'scalar0', 'label' => 'Bare zero', 'type' => 'date', 'required' => false, 'width' => 100,
		'options' => [
			'disabled_days' => '0',
			'min_date'      => '-1m -7d',
		],
	],
];

$imported = WapfMapper::map( [ 'fields' => $source_fields ] );
$check( 'WAPF date options import without review flags', ! $imported['needs_review'], $imported['notes'] );
$group = $imported['group'];

$full = $group['fields'][0];
$check( 'disable_past imports as allow_past=false', false === $full['allow_past'] );
$check( 'disable_future imports as allow_future=true', true === $full['allow_future'] );
$check( 'mm-dd-yyyy min_date imports as ISO', '2027-02-10' === $full['min_date'], $full['min_date'] );
$check( 'relative max_date is preserved', '1y' === $full['max_date'], $full['max_date'] );
$check( 'CSV disabled_days imports as weekdays', [ 0, 6 ] === $full['disabled_weekdays'], $full['disabled_weekdays'] );
$check( 'disabled_dates import as ISO/recurring rules', [ '2027-02-10 2027-02-12', '12-25' ] === $full['disabled_dates'], $full['disabled_dates'] );
$check( 'disable_today_after imports as cutoff_time', '12:00' === $full['cutoff_time'], $full['cutoff_time'] );

$scalar = $group['fields'][1];
$check( 'bare 0 disabled_days imports as Sunday', [ 0 ] === $scalar['disabled_weekdays'], $scalar['disabled_weekdays'] );
$check( 'relative min_date is preserved', '-1m -7d' === $scalar['min_date'], $scalar['min_date'] );

// Export back into the WAPF Tools payload.
$payload = WapfExporter::build_payload( $group );
$exported = $payload['fields'][0];
$check( 'export emits disable_past', true === $exported['disable_past'] );
$check( 'export emits disable_future=false', false === $exported['disable_future'] );
$check( 'export emits WAPF mm-dd-yyyy min_date', '02-10-2027' === $exported['min_date'], $exported['min_date'] );
$check( 'export keeps relative max_date', '1y' === $exported['max_date'], $exported['max_date'] );
$check( 'export emits CSV disabled_days', '0,6' === $exported['disabled_days'], $exported['disabled_days'] );
$check( 'export emits WAPF blackout CSV', '02-10-2027 02-12-2027,12-25' === $exported['disabled_dates'], $exported['disabled_dates'] );
$check( 'export emits disable_today_after', '12:00' === $exported['disable_today_after'], $exported['disable_today_after'] );
$check( 'export keeps bare 0 weekday scalar', '0' === $payload['fields'][1]['disabled_days'], $payload['fields'][1]['disabled_days'] );

// Re-import the exported payload exactly as WAPF stores it (flat keys folded
// back into the field's `options` bucket).
$date_keys = [ 'disable_past', 'disable_future', 'disable_today', 'min_date', 'max_date', 'disabled_days', 'disabled_dates', 'disable_today_after' ];
$round_fields = [];
foreach ( $payload['fields'] as $field ) {
	$field['options'] = array_intersect_key( $field, array_flip( $date_keys ) );
	$round_fields[]   = $field;
}
$round = WapfMapper::map( [ 'fields' => $round_fields ] );
$check( 're-import of exported payload is clean', ! $round['needs_review'], $round['notes'] );
$round_full = $round['group']['fields'][0];
foreach ( [ 'allow_past', 'allow_future', 'min_date', 'max_date', 'disabled_weekdays', 'disabled_dates', 'cutoff_time' ] as $key ) {
	$check( "round-trip preserves $key", $full[ $key ] === $round_full[ $key ], [ 'before' => $full[ $key ], 'after' => $round_full[ $key ] ] );
}
$check( 'round-trip preserves bare Sunday', [ 0 ] === $round['group']['fields'][1]['disabled_weekdays'], $round['group']['fields'][1]['disabled_weekdays'] );

// WXR export serializes the same options into WAPF's stored `options` bucket.
$wxr  = WapfWxrExporter::build_document( [ [ 'id' => 4242, 'title' => 'Date RT', 'data' => $group ] ], [ 'site_url' => 'https://example.test', 'site_title' => 'RT' ] );
$dom  = new DOMDocument();
$check( 'WXR document is valid XML', true === $dom->loadXML( $wxr ) );
$xpath = new DOMXPath( $dom );
$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
$stored = unserialize( $xpath->query( '/rss/channel/item[1]/content:encoded' )->item( 0 )->textContent, [ 'allowed_classes' => false ] );
$stored_options = $stored['fields'][0]['options'];
$check( 'WXR stores disable_past in options', true === $stored_options['disable_past'] );
$check( 'WXR stores disable_today_after in options', '12:00' === $stored_options['disable_today_after'] );
$check( 'WXR stores disabled_dates CSV in options', '02-10-2027 02-12-2027,12-25' === $stored_options['disabled_dates'] );

file_put_contents(
	$artifacts . '/date-roundtrip-results.json',
	wp_json_encode(
		[
			'checks'    => $checks,
			'source'    => $source_fields,
			'exported'  => $payload['fields'],
			'imported'  => [ $full, $scalar ],
			'reimport'  => [ $round_full, $round['group']['fields'][1] ],
			'stored_wxr_options' => $stored_options,
		],
		JSON_PRETTY_PRINT
	)
);
echo "SUCCESS date round-trip (" . count( $checks ) . " checks)\n";
