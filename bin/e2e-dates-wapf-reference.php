<?php
/**
 * WAPF Extended 3.1.5 reference dates lifecycle proof; guarded disposable use only.
 *
 * Phases:
 *  - setup   Create a product whose `_wapf_fieldgroup` meta mirrors the OPF
 *            dates-lifecycle fixture (disabled weekday 6, range
 *            2027-02-10 2027-02-12, recurring 12-25, cutoff 00:00).
 *  - verify  Server-side `woocommerce_add_to_cart_validation` cases through
 *            WAPF's own validator + cart display output, for both the default
 *            `mm-dd-yyyy` format and a configured `yyyy.mm.dd` format.
 *  - cleanup Delete every object created by setup; restore options.
 *
 * Env: OPF_DATES_E2E_ALLOW=1 OPF_DATES_E2E_PHASE=setup|verify|cleanup
 * Requires: disposable /tmp WordPress, WAPF Extended active, OPF inactive.
 */
if ( '1' !== getenv( 'OPF_DATES_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Requires an authorized disposable /tmp WordPress.' );
}
if ( ! class_exists( 'SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
	throw new RuntimeException( 'WAPF Extended must be active for the reference run.' );
}
if ( class_exists( 'OPF\Service\FieldGroups' ) ) {
	throw new RuntimeException( 'OPF must be inactive during the WAPF reference run.' );
}
function wapf_ref_check( string $label, bool $ok ): void { echo ( $ok ? 'ok ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } }

$phase = getenv( 'OPF_DATES_E2E_PHASE' ) ?: 'verify';
$state = get_option( 'opf_dates_wapf_ref_state', [] );

if ( 'setup' === $phase ) {
	wapf_ref_check( 'no existing reference fixture', ! $state );
	$product = new WC_Product_Simple();
	$product->set_name( 'WAPF dates lifecycle reference' );
	$product->set_slug( 'wapf-dates-lifecycle-reference' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$pid = $product->save();
	// Real WAPF storage shape: a product-local group id is "p_<product_id>"
	// (class-admin-controller.php:1066), disabled_days is a CSV scalar, and
	// disabled_dates uses documented mm-dd-yyyy / mm-dd notation.
	$group = [
		'id'          => 'p_' . $pid,
		'type'        => 'wapf_product',
		'fields'      => [
			[
				'id'           => 'dk',
				'label'        => 'Delivery date',
				'type'         => 'date',
				'required'     => true,
				'conditionals' => [],
				'clone'        => [ 'enabled' => false ],
				'options'      => [
					'disabled_days'       => '6',
					'disabled_dates'      => '02-10-2027 02-12-2027, 12-25',
					'disable_today_after' => '00:00',
				],
				'pricing'      => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ],
			],
			// Second field carries ONLY the cutoff so the same-day rule can be
			// isolated even when "today" falls on a disabled weekday.
			[
				'id'           => 'co',
				'label'        => 'Cutoff-only date',
				'type'         => 'date',
				'required'     => false,
				'conditionals' => [],
				'clone'        => [ 'enabled' => false ],
				'options'      => [ 'disable_today_after' => '00:00' ],
				'pricing'      => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ],
			],
		],
		'rule_groups' => [],
		'layout'      => [],
	];
	update_post_meta( $pid, '_wapf_fieldgroup', $group );
	$prev = [ 'wapf_datepicker' => get_option( 'wapf_datepicker', null ) ];
	update_option( 'wapf_datepicker', 'yes' );
	$state = [ 'product' => $pid, 'group_id' => 'p_' . $pid, 'options_prev' => $prev ];
	update_option( 'opf_dates_wapf_ref_state', $state );
	file_put_contents( '/tmp/opf-dates-wapf-ref-state.json', wp_json_encode( $state ) );
	echo "ok reference fixture setup (product $pid)\n";
	return;
}

if ( 'cleanup' === $phase ) {
	foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $order ) {
		foreach ( $order->get_items() as $item ) { if ( $item->get_product_id() === $state['product'] ) { $order->delete( true ); break; } }
	}
	if ( ! empty( $state['product'] ) ) { wp_delete_post( $state['product'], true ); }
	foreach ( (array) ( $state['options_prev'] ?? [] ) as $key => $value ) {
		null === $value ? delete_option( $key ) : update_option( $key, $value );
	}
	delete_option( 'wapf_date_format' ); // Reference-run override; absent at baseline.
	delete_option( 'opf_dates_wapf_ref_state' );
	if ( file_exists( '/tmp/opf-dates-wapf-ref-state.json' ) ) { unlink( '/tmp/opf-dates-wapf-ref-state.json' ); }
	echo "ok reference fixture cleanup\n";
	return;
}

// verify
wapf_ref_check( 'reference fixture exists', ! empty( $state['product'] ) );
$pid     = $state['product'];
$groups  = SW_WAPF_PRO\Includes\Classes\Field_Groups::get_field_groups_of_product( $pid );
wapf_ref_check( 'WAPF sees the product field group', 1 === count( $groups ) && $state['group_id'] === $groups[0]->id );
$today   = current_datetime()->format( 'Y-m-d' );
$results = [ 'product' => $pid, 'cases' => [] ];

$attempt = static function ( array $values ) use ( $pid, $state ) {
	if ( ! WC()->cart ) { wc_load_cart(); }
	WC()->cart->empty_cart( true );
	wc_clear_notices();
	$_REQUEST['wapf_field_groups'] = $state['group_id'];
	$_REQUEST['wapf']              = $values;
	$passed                        = apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, 1 );
	$notices                       = wc_get_notices( 'error' );
	wc_clear_notices();
	unset( $_REQUEST['wapf_field_groups'], $_REQUEST['wapf'] );
	return [ $passed, array_map( static function ( $n ) { return is_array( $n ) ? ( $n['notice'] ?? '' ) : (string) $n; }, (array) $notices ) ];
};

$record = static function ( string $label, bool $ok, array $extra = [] ) use ( &$results ) {
	$results['cases'][] = [ 'label' => $label, 'pass' => $ok ] + $extra;
	echo ( $ok ? 'ok ' : 'FAIL ' ) . $label . ( $extra ? ' ' . wp_json_encode( $extra ) : '' ) . "\n";
};

// ---- Pass 1: default mm-dd-yyyy format ------------------------------------
$fmt = static fn( string $iso ) => substr( $iso, 5, 2 ) . '-' . substr( $iso, 8, 2 ) . '-' . substr( $iso, 0, 4 );
list( $ok, $notices ) = $attempt( [ 'field_dk' => $fmt( '2027-02-13' ) ] );
$record( 'default fmt: Saturday rejected (disabled weekday)', false === $ok, [ 'notices' => $notices ] );
list( $ok, $notices ) = $attempt( [ 'field_dk' => $fmt( '2027-02-11' ) ] );
$record( 'default fmt: range middle rejected (disabled dates)', false === $ok, [ 'notices' => $notices ] );
list( $ok, $notices ) = $attempt( [ 'field_dk' => $fmt( '2026-12-25' ) ] );
$record( 'default fmt: recurring 12-25 rejected in current year (Friday)', false === $ok, [ 'notices' => $notices ] );
list( $ok, $notices ) = $attempt( [ 'field_dk' => $fmt( '2028-12-25' ) ] );
// WAPF Helper::string_to_date expands "12-25" to the CURRENT year only, so a
// 2028 submission is accepted server-side even though the picker blocks it.
// Documented reference gap; OPF rejects it (stricter, consistent with WAPF JS).
$record( 'default fmt: recurring 12-25 in 2028 -> WAPF server accepts (reference gap)', true === $ok, [ 'notices' => $notices ] );
// dk alone cannot isolate cutoff when today is a disabled weekday (weekday
// check fires first in both plugins). The co field carries only the cutoff.
list( $ok, $notices ) = $attempt( [ 'field_dk' => $fmt( '2027-02-15' ), 'field_co' => $fmt( $today ) ] );
$record( 'default fmt: today rejected by cutoff (isolated field)', false === $ok, [ 'notices' => $notices ] );
list( $ok, $notices ) = $attempt( [ 'field_dk' => $fmt( '2027-02-15' ), 'field_co' => $fmt( '2027-02-16' ) ] );
$record( 'default fmt: later date passes cutoff field', true === $ok, [ 'notices' => $notices ] );
list( $ok, $notices ) = $attempt( [ 'field_dk' => $fmt( '2027-02-15' ) ] );
$record( 'default fmt: valid Monday accepted', true === $ok, [ 'notices' => $notices ] );

// Valid add through the real cart pipeline; display value in configured format.
if ( ! WC()->cart ) { wc_load_cart(); }
WC()->cart->empty_cart( true );
wc_clear_notices();
$_REQUEST['wapf_field_groups'] = $state['group_id'];
$_REQUEST['wapf']              = [ 'field_dk' => $fmt( '2027-02-15' ) ];
$key = WC()->cart->add_to_cart( $pid, 1 );
unset( $_REQUEST['wapf_field_groups'], $_REQUEST['wapf'] );
$record( 'default fmt: valid add reaches cart', (bool) $key );
$item = WC()->cart->get_cart_item( $key );
// WAPF stores the submitted formatted string verbatim as the cart field's
// raw value + label; woocommerce_get_item_data is is_cart()-gated, so the
// rendered cart label is asserted by the browser proof instead.
$wapf_row = $item['wapf'][0] ?? null;
$record(
	'default fmt: cart stores submitted formatted value verbatim',
	null !== $wapf_row && 'dk' === $wapf_row['id'] && '02-15-2027' === $wapf_row['raw'] && '02-15-2027' === ( $wapf_row['values'][0]['label'] ?? null ),
	[ 'wapf' => $wapf_row ]
);
WC()->cart->empty_cart( true );

// ---- Pass 2: configured yyyy.mm.dd format (mirrors the OPF run) ------------
update_option( 'wapf_date_format', 'yyyy.mm.dd' );
$fmt2 = static fn( string $iso ) => str_replace( '-', '.', $iso );
list( $ok, $notices ) = $attempt( [ 'field_dk' => $fmt2( '2027-02-13' ) ] );
$record( 'yyyy.mm.dd: Saturday rejected', false === $ok, [ 'notices' => $notices ] );
list( $ok, $notices ) = $attempt( [ 'field_dk' => $fmt2( '2027-02-11' ) ] );
$record( 'yyyy.mm.dd: range middle rejected', false === $ok, [ 'notices' => $notices ] );
list( $ok, $notices ) = $attempt( [ 'field_dk' => $fmt2( '2027-02-15' ), 'field_co' => $fmt2( $today ) ] );
$record( 'yyyy.mm.dd: today rejected by cutoff (isolated field)', false === $ok, [ 'notices' => $notices ] );
list( $ok, $notices ) = $attempt( [ 'field_dk' => $fmt2( '2027-02-15' ) ] );
$record( 'yyyy.mm.dd: valid Monday accepted', true === $ok, [ 'notices' => $notices ] );
WC()->cart->empty_cart( true );
wc_clear_notices();
$_REQUEST['wapf_field_groups'] = $state['group_id'];
$_REQUEST['wapf']              = [ 'field_dk' => $fmt2( '2027-02-15' ) ];
$key = WC()->cart->add_to_cart( $pid, 1 );
unset( $_REQUEST['wapf_field_groups'], $_REQUEST['wapf'] );
$item     = $key ? WC()->cart->get_cart_item( $key ) : null;
$wapf_row = $item['wapf'][0] ?? null;
$record(
	'yyyy.mm.dd: cart stores submitted formatted value verbatim',
	null !== $wapf_row && '2027.02.15' === $wapf_row['raw'] && '2027.02.15' === ( $wapf_row['values'][0]['label'] ?? null ),
	[ 'wapf' => $wapf_row ]
);
WC()->cart->empty_cart( true );
delete_option( 'wapf_date_format' );

file_put_contents( '/tmp/opf-dates-wapf-ref-results.json', wp_json_encode( $results, JSON_PRETTY_PRINT ) );
echo "ok reference verification complete\n";
