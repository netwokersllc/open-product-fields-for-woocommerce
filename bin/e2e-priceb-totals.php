<?php
/**
 * Guarded fixture for the PriceB totals-display browser comparison.
 *
 * Closes WAPF-PRICE-OPTIONS-TOTAL-NEGATIVE-FORMAT and WAPF-PRICE-TOTAL-DISPLAY
 * against the installed WAPF Extended 3.1.5 runtime: one native WAPF product
 * and one OPF product carrying the mapper-imported equivalent group, so a
 * Playwright pass can compare the rendered pre-cart totals at q=1/q=3 and the
 * negative options-total sign placement.
 *
 * Phases (OPF_PRICEB_TOTALS_PHASE):
 *   setup    record baseline, create products + groups, write state JSON
 *   cleanup  delete everything setup created, restore options, assert baseline
 *
 * Run on the disposable clone only:
 *   OPF_PRICEB_TOTALS_PHASE=setup \
 *   wp eval-file bin/e2e-priceb-totals.php --path=/tmp/opf-image-priceb-wp
 */

defined( 'ABSPATH' ) || exit;

use OPF\Engine\WapfMapper;
use OPF\Service\FieldGroups;
use SW_WAPF_PRO\Includes\Classes\Field_Groups;

if ( '1' !== getenv( 'OPF_PRICEB_E2E_ALLOW' ) || realpath( ABSPATH ) !== '/tmp/opf-image-priceb-wp' ) {
	throw new RuntimeException( 'Set OPF_PRICEB_E2E_ALLOW=1 only on the disposable /tmp/opf-image-priceb-wp clone.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( FieldGroups::class ) || ! class_exists( Field_Groups::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce, OPF, and WAPF Extended before running this fixture.' );
}

$phase = getenv( 'OPF_PRICEB_TOTALS_PHASE' ) ?: 'setup';
$out   = getenv( 'OPF_PRICEB_OUT' ) ?: '/tmp/opf-lane-priceb-evidence';
if ( ! is_dir( $out ) || ! is_writable( $out ) ) {
	throw new RuntimeException( 'Evidence directory must be writable: ' . $out );
}
$state_path = $out . '/priceb-totals-state.json';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

/** Live fixture/baseline counters used to prove a clean cleanup. */
$counters = static function (): array {
	return [
		'products'    => (int) wp_count_posts( 'product' )->publish,
		'groups'      => (int) wp_count_posts( 'opf_field_group' )->publish,
		'wapf_posts'  => array_sum( array_map( 'intval', (array) wp_count_posts( 'wapf_product' ) ) ),
		'users'       => count( get_users( [ 'fields' => 'ID' ] ) ),
		'orders'      => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ),
		'attachments' => (int) wp_count_posts( 'attachment' )->inherit,
		'tax_rates'   => (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->prefix . 'woocommerce_tax_rates' ),
	];
};

$option_names = [ 'opf_admin_only', 'opf_price_summary_mode', 'wapf_pricing_summary', 'woocommerce_calc_taxes', 'woocommerce_tax_display_shop' ];

if ( 'cleanup' === $phase ) {
	$state = json_decode( (string) file_get_contents( $state_path ), true );
	$assert( is_array( $state ), 'Missing or unreadable state file for cleanup.' );
	foreach ( array_reverse( (array) ( $state['groups'] ?? [] ) ) as $gid ) {
		wp_delete_post( (int) $gid, true );
	}
	foreach ( array_reverse( (array) ( $state['products'] ?? [] ) ) as $pid ) {
		$product = wc_get_product( (int) $pid );
		if ( $product ) {
			$product->delete( true );
		}
	}
	foreach ( (array) ( $state['drafted_groups'] ?? [] ) as $drafted_id ) {
		wp_update_post( [ 'ID' => (int) $drafted_id, 'post_status' => 'publish' ] );
	}
	foreach ( $option_names as $name ) {
		if ( array_key_exists( $name, (array) $state['options'] ) && null !== $state['options'][ $name ] ) {
			update_option( $name, $state['options'][ $name ] );
		} else {
			delete_option( $name );
		}
	}
	FieldGroups::flush_cache();
	wp_cache_flush();

	$after = $counters();
	$assert( $after === $state['baseline'], 'Cleanup did not restore baseline: ' . wp_json_encode( [ 'before' => $state['baseline'], 'after' => $after ] ) );
	echo wp_json_encode( [ 'phase' => 'cleanup', 'restored' => true, 'counts' => $after ] ), "\n";
	return;
}

$assert( 'setup' === $phase, 'Unknown phase: ' . $phase );
$assert( WC()->cart && WC()->cart->is_empty(), 'Disposable clone must have an empty cart before setup.' );

$options = [];
foreach ( $option_names as $name ) {
	$options[ $name ] = get_option( $name, null );
}
$baseline = $counters();

try {
	update_option( 'opf_admin_only', 'no' );
	update_option( 'opf_price_summary_mode', 'three' );
	update_option( 'wapf_pricing_summary', 'lines' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_tax_display_shop', 'excl' );

	$make_product = static function ( string $name ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '10.00' );
		$product->set_status( 'publish' );
		$product->set_tax_status( 'none' );
		return (int) $product->save();
	};

	$wapf_pid = $make_product( 'PriceB totals WAPF' );
	$opf_pid  = $make_product( 'PriceB totals OPF' );

	// Shared shape: a choice-level flat +5 and negative-fx -15, plus a
	// per-character charq text field (qty-scaled display).
	$wapf_export = [
		'fields' => [
			[
				'id' => 'pack', 'type' => 'select', 'label' => 'Pack', 'required' => false,
				'choices' => [
					[ 'slug' => 'none',  'label' => 'None',          'pricing_type' => 'none' ],
					[ 'slug' => 'plus',  'label' => 'Plus five',     'pricing_type' => 'fixed', 'pricing_amount' => '5' ],
					[ 'slug' => 'minus', 'label' => 'Minus fifteen', 'pricing_type' => 'fx',    'pricing_amount' => '-15' ],
				],
			],
			[
				'id' => 'msg', 'type' => 'text', 'label' => 'Message', 'required' => false,
				'pricing' => [ 'enabled' => 'true', 'type' => 'charq', 'amount' => '0.5' ],
			],
		],
		'conditions' => [],
		'layout'     => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ],
		'variables'  => [],
	];

	$wapf_model = Field_Groups::raw_json_to_field_group( [
		'id' => 'p_' . $wapf_pid, 'type' => 'wapf_product',
		'fields' => array_map( static function ( array $field ): array {
			$field['conditionals'] = [];
			return $field;
		}, $wapf_export['fields'] ),
		'conditions' => [], 'layout' => $wapf_export['layout'], 'variables' => [],
	] );
	$assert( $wapf_model && 2 === count( $wapf_model->fields ), 'WAPF totals model did not parse.' );
	update_post_meta( $wapf_pid, '_wapf_fieldgroup', $wapf_model->to_array() );

	$mapped = WapfMapper::map( $wapf_model->to_array(), [ 'attach_product_ids' => [ $opf_pid ] ] );
	$assert( is_array( $mapped ) && ! empty( $mapped['group']['fields'] ), 'WapfMapper returned no fields.' );
	$field_ids = [];
	foreach ( $mapped['group']['fields'] as $field ) {
		$field_ids[ (string) $field['label'] ] = (string) $field['id'];
	}
	$assert( isset( $field_ids['Pack'], $field_ids['Message'] ), 'Mapped field ids missing: ' . wp_json_encode( $field_ids ) );

	$group_id = FieldGroups::save( 0, $mapped['group'], [ 'title' => 'PriceB totals group', 'status' => 'publish' ] );
	$assert( $group_id > 0, 'Could not save the OPF totals group.' );
	FieldGroups::flush_cache();

	// Neutralise pre-existing OPF groups that target ALL products (empty
	// rule_groups). They render on the WAPF fixture product too, and OPF's
	// frontend JS writes into `.wapf-product-totals`, corrupting the WAPF
	// reference reading. Draft them for the browser pass; cleanup restores.
	$drafted_groups = [];
	foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'publish', 'numberposts' => -1 ] ) as $other ) {
		if ( (int) $other->ID === (int) $group_id ) {
			continue;
		}
		$data = json_decode( (string) $other->post_content, true );
		if ( is_array( $data ) && empty( $data['rule_groups'] ) ) {
			$drafted_groups[] = (int) $other->ID;
			wp_update_post( [ 'ID' => (int) $other->ID, 'post_status' => 'draft' ] );
		}
	}
	FieldGroups::flush_cache();

	$state = [
		'generated'    => gmdate( 'c' ),
		'wapf_version' => (string) ( get_plugin_data( WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' )['Version'] ?? '' ),
		'base'         => 'http://127.0.0.1:8317',
		'wapf_product' => $wapf_pid,
		'opf_product'  => $opf_pid,
		'opf_group'    => (int) $group_id,
		'products'     => [ $wapf_pid, $opf_pid ],
		'groups'       => [ (int) $group_id ],
		'drafted_groups' => $drafted_groups,
		'field_ids'    => $field_ids,
		'wapf_field_ids' => [ 'Pack' => 'pack', 'Message' => 'msg' ],
		'options'      => $options,
		'baseline'     => $baseline,
	];
	$assert( '3.1.5' === $state['wapf_version'], 'WAPF Extended 3.1.5 required, found ' . $state['wapf_version'] );
	file_put_contents( $state_path, wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
} catch ( \Throwable $e ) {
	// Best-effort rollback so a failed setup never leaves fixtures behind.
	if ( isset( $group_id ) && $group_id ) {
		wp_delete_post( (int) $group_id, true );
	}
	foreach ( [ $opf_pid ?? null, $wapf_pid ?? null ] as $pid ) {
		if ( $pid ) {
			$product = wc_get_product( (int) $pid );
			if ( $product ) {
				$product->delete( true );
			}
		}
	}
	foreach ( (array) ( $drafted_groups ?? [] ) as $drafted_id ) {
		wp_update_post( [ 'ID' => (int) $drafted_id, 'post_status' => 'publish' ] );
	}
	foreach ( $option_names as $name ) {
		if ( null !== ( $options[ $name ] ?? null ) ) {
			update_option( $name, $options[ $name ] );
		} else {
			delete_option( $name );
		}
	}
	throw $e;
}
