<?php
/**
 * WAPF serialized date option shapes -> OPF WapfMapper mapping proof.
 * OPF_DATE_IMPORT_ALLOW=1 wp --path=<disposable> eval-file bin/e2e-dates-import-equivalence.php
 *
 * The disposable clone already hosts many real WAPF groups, so this proof maps
 * serialized payloads directly instead of running the bulk Importer.
 */
if ( '1' !== getenv( 'OPF_DATE_IMPORT_ALLOW' ) ) { throw new RuntimeException( 'guarded disposable use only' ); }
if ( ! class_exists( OPF\Engine\WapfMapper::class ) ) { throw new RuntimeException( 'OPF missing' ); }
$failures = 0;
$assert = static function ( bool $ok, string $what ) use ( &$failures ) {
	if ( ! $ok ) { fwrite( STDERR, "FAIL $what\n" ); $failures++; } else { echo "ok $what\n"; }
};
$base = [ 'id' => 'dk', 'label' => 'Delivery', 'type' => 'date', 'required' => false, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ] ];

// 1. Array shape (Tools/JSON payloads may carry arrays).
$m = OPF\Engine\WapfMapper::map( [ 'fields' => [ $base + [ 'options' => [ 'disabled_days' => [ 0, '6' ], 'disabled_dates' => '2027-03-03', 'disable_today_after' => '09:15' ] ] ] ] );
$f = $m['group']['fields'][0] ?? [];
$assert( ( $f['disabled_weekdays'] ?? null ) === [ 0, 6 ], 'array disabled_days maps to [0,6]: ' . wp_json_encode( $f['disabled_weekdays'] ?? null ) );
$assert( ( $f['disabled_dates'] ?? null ) === [ '2027-03-03' ], 'array-shape disabled_dates maps to ["2027-03-03"]' );
$assert( ( $f['cutoff_time'] ?? null ) === '09:15', 'array-shape disable_today_after maps to cutoff_time 09:15' );
$assert( ! $m['needs_review'], 'array shape maps without review notes: ' . wp_json_encode( $m['notes'] ) );

// 2. Scalar comma-separated shape (the format WAPF's own sanitizer stores: text, comma-joined).
//    disabled_dates uses WAPF's documented mm-dd-yyyy / mm-dd notation
//    (class-extended-controller.php admin note), mapping to OPF ISO rules.
$m = OPF\Engine\WapfMapper::map( [ 'fields' => [ $base + [ 'options' => [ 'disabled_days' => '0,6', 'disabled_dates' => '12-25, 02-10-2027 02-12-2027', 'disable_today_after' => '14:30' ] ] ] ] );
$f = $m['group']['fields'][0] ?? [];
$assert( ( $f['disabled_weekdays'] ?? null ) === [ 0, 6 ], 'scalar disabled_days "0,6" maps to [0,6]: ' . wp_json_encode( $f['disabled_weekdays'] ?? null ) );
$assert( ( $f['disabled_dates'] ?? null ) === [ '12-25', '2027-02-10 2027-02-12' ], 'scalar disabled_dates rules mapped (mm-dd-yyyy -> ISO): ' . wp_json_encode( $f['disabled_dates'] ?? null ) );
$assert( ( $f['cutoff_time'] ?? null ) === '14:30', 'scalar disable_today_after maps to cutoff_time 14:30' );
$assert( ! $m['needs_review'], 'scalar shape maps without review notes: ' . wp_json_encode( $m['notes'] ) );

// 2b. Single mm-dd-yyyy disabled date maps to ISO.
$m = OPF\Engine\WapfMapper::map( [ 'fields' => [ $base + [ 'options' => [ 'disabled_dates' => '03-03-2027' ] ] ] ] );
$f = $m['group']['fields'][0] ?? [];
$assert( ( $f['disabled_dates'] ?? null ) === [ '2027-03-03' ], 'mm-dd-yyyy single disabled date maps to ISO: ' . wp_json_encode( $f['disabled_dates'] ?? null ) );
$assert( ! $m['needs_review'], 'mm-dd-yyyy maps without review notes: ' . wp_json_encode( $m['notes'] ) );

// 3. Single disabled day stored as bare '0' (WAPF sanitize special case).
$m = OPF\Engine\WapfMapper::map( [ 'fields' => [ $base + [ 'options' => [ 'disabled_days' => '0' ] ] ] ] );
$f = $m['group']['fields'][0] ?? [];
$assert( ( $f['disabled_weekdays'] ?? null ) === [ 0 ], 'bare "0" maps to [0]' );

// 4. Malformed remains review-flagged.
$m = OPF\Engine\WapfMapper::map( [ 'fields' => [ $base + [ 'options' => [ 'disabled_days' => 'mon' ] ] ] ] );
$assert( $m['needs_review'] && ( $m['group']['fields'][0]['disabled_weekdays'] ?? null ) === null, 'malformed disabled_days still review-flagged' );

// 5. Empty string is no restriction, not malformed.
$m = OPF\Engine\WapfMapper::map( [ 'options' => [ ] ] );
// (covered above) — verify empty string disabled_dates is harmless:
$m = OPF\Engine\WapfMapper::map( [ 'fields' => [ $base + [ 'options' => [ 'disabled_dates' => '' ] ] ] ] );
$f = $m['group']['fields'][0] ?? [];
$assert( ! isset( $f['disabled_dates'] ) && ! $m['needs_review'], 'empty disabled_dates inert' );

exit( $failures ? 1 : 0 );
