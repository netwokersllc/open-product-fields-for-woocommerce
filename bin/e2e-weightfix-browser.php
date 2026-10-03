<?php
/**
 * weightfix lane — browser fixture for bin/e2e-weightfix-browser.mjs.
 *
 * Creates one published taxable product (slug opf-weightfix-browser, price
 * 100, weight 2, 10% standard tax rate) with a single OPF field group:
 *   pack  select  Packaging  -> light "+$10 / 0.5 kg", heavy "+$20 / 2 kg"
 *   pct   text    Percent fee -> percent 50 (base 100 -> "$50.00" hint)
 *
 * Phases (OPF_WEIGHTFIX_PHASE):
 *   setup    create fixture + record baseline (once)
 *   display  apply OPF_WEIGHTFIX_DISPLAY=incl|excl to woocommerce_tax_display_shop
 *   cleanup  remove everything created and assert the baseline is restored
 *
 * Guarded: requires OPF_WEIGHTFIX_ALLOW=1 and an owned /tmp SQLite clone.
 * Run: OPF_WEIGHTFIX_ALLOW=1 OPF_WEIGHTFIX_PHASE=setup \
 *      wp eval-file bin/e2e-weightfix-browser.php --path=/tmp/opf-image-weightfix-wp
 */

defined( 'ABSPATH' ) || exit;

if (
	'1' !== getenv( 'OPF_WEIGHTFIX_ALLOW' )
	|| ! defined( 'WP_CLI' ) || ! WP_CLI
	|| 0 !== strpos( realpath( ABSPATH ), '/tmp/opf-image-' )
	|| ! defined( 'FQDB' ) || 0 !== strpos( realpath( FQDB ), realpath( ABSPATH ) . '/' )
	|| ! defined( 'OPF_VERSION' )
) {
	throw new RuntimeException( 'Guarded: owned disposable /tmp SQLite clone + OPF active + OPF_WEIGHTFIX_ALLOW=1 only.' );
}

$key   = 'opf_weightfix_browser_state';
$phase = getenv( 'OPF_WEIGHTFIX_PHASE' ) ?: 'setup';

/** Baseline/final environment snapshot compared on cleanup. */
$snapshot = static function (): array {
	return [
		'posts'  => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts}" ),
		'users'  => count( get_users( [ 'fields' => 'ID' ] ) ),
		'plugins' => get_option( 'active_plugins' ),
	];
};

if ( 'display' === $phase ) {
	$state = get_option( $key );
	if ( ! $state ) {
		throw new RuntimeException( 'Missing owned fixture — run setup first.' );
	}
	$mode = getenv( 'OPF_WEIGHTFIX_DISPLAY' ) ?: 'excl';
	update_option( 'woocommerce_tax_display_shop', in_array( $mode, [ 'incl', 'excl' ], true ) ? $mode : 'excl' );
	echo "ok shop tax display = {$mode}\n";
	return;
}

if ( 'cleanup' === $phase ) {
	$state = get_option( $key );
	if ( ! $state ) {
		throw new RuntimeException( 'Missing owned fixture.' );
	}
	foreach ( $state['products'] as $id ) {
		$p = wc_get_product( (int) $id );
		if ( $p ) { $p->delete( true ); }
	}
	foreach ( $state['groups'] as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( $state['tax_rates'] as $id ) {
		WC_Tax::_delete_tax_rate( (int) $id );
	}
	foreach ( $state['options'] as $name => $value ) {
		null === $value ? delete_option( $name ) : update_option( $name, $value );
	}
	delete_option( $key );
	\OPF\Service\FieldGroups::flush_cache();

	$remaining = [];
	foreach ( array_merge( $state['products'], $state['groups'] ) as $id ) {
		if ( null !== get_post( (int) $id ) ) { $remaining[] = (int) $id; }
	}
	$after = $snapshot();
	if ( $remaining || $state['baseline'] !== $after ) {
		throw new RuntimeException( 'Cleanup failed: remaining=' . wp_json_encode( $remaining ) . ' baseline=' . wp_json_encode( $state['baseline'] ) . ' after=' . wp_json_encode( $after ) );
	}
	echo "ok owned product/group/tax-rate removed; baseline restored\n";
	return;
}

if ( 'setup' !== $phase || get_option( $key ) ) {
	throw new RuntimeException( 'Setup only once (phase=setup).' );
}

$option_names = [
	'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop',
	'woocommerce_weight_unit', 'opf_admin_only', 'opf_theme_compat', 'opf_price_summary_mode',
	'opf_show_price_hints',
];
$options = [];
foreach ( $option_names as $name ) {
	$options[ $name ] = get_option( $name, null );
}

$baseline = $snapshot();

// 10% standard tax rate (disposable; deleted on cleanup).
$rate = WC_Tax::_insert_tax_rate( [
	'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '10.0000',
	'tax_rate_name' => 'weightfix-std', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0,
	'tax_rate_shipping' => 0, 'tax_rate_order' => 950, 'tax_rate_class' => '',
] );

update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_display_shop', 'excl' );
update_option( 'woocommerce_weight_unit', 'kg' );
update_option( 'opf_admin_only', 'no' );
update_option( 'opf_theme_compat', 'yes' );
update_option( 'opf_price_summary_mode', 'three' );
update_option( 'opf_show_price_hints', 'yes' );

$product = new WC_Product_Simple();
$product->set_name( 'opf weightfix browser' );
$product->set_slug( 'opf-weightfix-browser' );
$product->set_regular_price( '100' );
$product->set_weight( '2' );
$product->set_tax_status( 'taxable' );
$product->set_status( 'publish' );
$pid = $product->save();

$group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[
			'id' => 'pack', 'type' => 'select', 'label' => 'Packaging',
			'choices' => [
				[ 'slug' => 'light', 'label' => 'Light pack', 'pricing' => [ 'type' => 'fixed', 'amount' => 10, 'per_unit' => true ], 'options' => [ 'weight' => '0.5' ] ],
				[ 'slug' => 'heavy', 'label' => 'Heavy pack', 'pricing' => [ 'type' => 'fixed', 'amount' => 20, 'per_unit' => true ], 'options' => [ 'weight' => '2' ] ],
			],
		],
		[
			'id' => 'pct', 'type' => 'text', 'label' => 'Percent fee',
			'pricing' => [ 'type' => 'percent', 'amount' => 50, 'per_unit' => true ],
		],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
] );
$gid = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => 'opf weightfix browser group' ] );
\OPF\Service\FieldGroups::flush_cache();

$state = [
	'products'  => [ $pid ],
	'groups'    => [ $gid ],
	'tax_rates' => [ (string) $rate ],
	'options'   => $options,
	'baseline'  => $baseline,
	'product'   => $pid,
	'group'     => $gid,
	'url'       => get_permalink( $pid ),
];
update_option( $key, $state );

echo 'ok fixture product=' . $pid . ' group=' . $gid . ' url=' . get_permalink( $pid ) . "\n";
