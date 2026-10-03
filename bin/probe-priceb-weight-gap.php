<?php
/**
 * WAPF-PRICE-FORMULA-WEIGHT gap confirmation (priceb lane).
 *
 * The commerce/tax lane reported that OPF drops WAPF `weight` metadata at
 * field normalization and has no Calculator weight function. This probe
 * re-confirms that on THIS branch with a live side-by-side:
 *   1. FieldGroup::normalize() strips both field-level and choice-level
 *      `weight` keys (whitelist schema).
 *   2. A native WAPF Extended 3.1.5 select choice with `options.weight` raises
 *      the cart item weight above the product base.
 *   3. The mapper-imported OPF equivalent leaves the cart item weight at the
 *      product base (gap), while WapfMapper flags the weight for review.
 *
 * Documentation-only: no weight engine is implemented here.
 *
 * Run on the disposable clone only:
 *   OPF_PRICEB_E2E_ALLOW=1 OPF_PRICEB_OUT=/tmp/opf-lane-priceb-evidence \
 *   wp eval-file bin/probe-priceb-weight-gap.php --path=/tmp/opf-image-priceb-wp
 */

defined( 'ABSPATH' ) || exit;

use OPF\Engine\WapfMapper;
use OPF\Engine\FieldGroup;
use OPF\Service\FieldGroups;
use SW_WAPF_PRO\Includes\Classes\Field_Groups;

if ( '1' !== getenv( 'OPF_PRICEB_E2E_ALLOW' ) || realpath( ABSPATH ) !== '/tmp/opf-image-priceb-wp' ) {
	throw new RuntimeException( 'Set OPF_PRICEB_E2E_ALLOW=1 only on the disposable /tmp/opf-image-priceb-wp clone.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( FieldGroups::class ) || ! class_exists( Field_Groups::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce, OPF, and WAPF Extended before running this probe.' );
}
$out = getenv( 'OPF_PRICEB_OUT' ) ?: '/tmp/opf-lane-priceb-evidence';
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$cart = WC()->cart;
$assert( null !== $cart && $cart->is_empty(), 'Disposable clone must have an empty cart.' );

$results = [];
$owned_products = [];
$owned_groups   = [];
$admin_only_before = get_option( 'opf_admin_only', null );

$find_item = static function ( \WC_Cart $wc_cart, int $product_id ): ?array {
	foreach ( $wc_cart->get_cart() as $item ) {
		if ( (int) ( $item['product_id'] ?? 0 ) === $product_id ) {
			return $item;
		}
	}
	return null;
};

try {
	update_option( 'opf_admin_only', 'no' );

	// --- 1. normalization drops weight keys ---------------------------------
	$normalized = FieldGroup::normalize( [
		'fields' => [
			[
				'id' => 'pack', 'type' => 'select', 'label' => 'Pack',
				'options' => [ 'weight' => '9' ],
				'weight'  => '9',
				'choices' => [ [ 'slug' => 'heavy', 'label' => 'Heavy', 'options' => [ 'weight' => '[x]*2' ], 'weight' => '2' ] ],
			],
		],
	] );
	$field  = $normalized['fields'][0];
	$choice = $field['choices'][0];
	$results['normalization'] = [
		'field_weight_key'  => array_key_exists( 'weight', $field ),
		'choice_weight_key' => array_key_exists( 'weight', $choice ),
		'field_options'     => array_key_exists( 'options', $field ),
	];
	$assert( ! array_key_exists( 'weight', $field ), 'Field-level weight unexpectedly survived normalize_field().' );
	$assert( ! array_key_exists( 'weight', $choice ), 'Choice-level weight unexpectedly survived normalize_field().' );
	$assert( ! method_exists( \OPF\Engine\Calculator::class, 'weight' ), 'A Calculator weight function now exists; re-audit the row.' );

	// --- 2/3. live cart weight: WAPF reference vs imported OPF ---------------
	$make_product = static function ( string $name ) use ( &$owned_products ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '10.00' );
		$product->set_weight( '1' );
		$product->set_status( 'publish' );
		$product->set_tax_status( 'none' );
		$pid = (int) $product->save();
		$owned_products[] = $pid;
		return $pid;
	};
	$wapf_pid = $make_product( 'PriceB weight WAPF' );
	$opf_pid  = $make_product( 'PriceB weight OPF' );

	$wapf_export = [
		'fields' => [
			[
				'id' => 'pack', 'type' => 'select', 'label' => 'Pack', 'required' => false,
				'choices' => [
					[ 'slug' => 'light', 'label' => 'Light', 'options' => [ 'weight' => '0.5' ] ],
					[ 'slug' => 'heavy', 'label' => 'Heavy', 'options' => [ 'weight' => '2' ] ],
				],
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
	$assert( $wapf_model && 1 === count( $wapf_model->fields ), 'WAPF weight model did not parse.' );
	update_post_meta( $wapf_pid, '_wapf_fieldgroup', $wapf_model->to_array() );

	$mapped = WapfMapper::map( $wapf_model->to_array(), [ 'attach_product_ids' => [ $opf_pid ] ] );
	$results['mapper'] = [ 'needs_review' => $mapped['needs_review'] ?? null, 'notes' => $mapped['notes'] ?? [] ];
	$assert( true === ( $mapped['needs_review'] ?? false ), 'Mapper did not flag the WAPF weight option for review.' );

	$group_id = FieldGroups::save( 0, $mapped['group'], [ 'title' => 'PriceB weight group', 'status' => 'publish' ] );
	$assert( $group_id > 0, 'Could not save the OPF weight group.' );
	$owned_groups[] = $group_id;
	FieldGroups::flush_cache();

	// WAPF leg.
	$cart->empty_cart( true );
	$_REQUEST['wapf_field_groups'] = 'p_' . $wapf_pid;
	$_REQUEST['wapf'] = [ 'field_pack' => 'heavy' ];
	$key = $cart->add_to_cart( $wapf_pid, 2 );
	unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'] );
	$assert( false !== $key, 'WAPF add_to_cart rejected the weight fixture.' );
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	$cart->calculate_totals();
	$wapf_item = $find_item( $cart, $wapf_pid );
	$wapf_weight = $wapf_item ? (float) $wapf_item['data']->get_weight() : null;

	// OPF leg.
	$cart->empty_cart( true );
	$_POST['opf'] = [ (string) $group_id => [ 'pack' => 'heavy' ] ];
	$_REQUEST['opf'] = $_POST['opf'];
	$key = $cart->add_to_cart( $opf_pid, 2 );
	unset( $_POST['opf'], $_REQUEST['opf'] );
	$assert( false !== $key, 'OPF add_to_cart rejected the weight fixture.' );
	$cart->calculate_totals();
	$opf_item = $find_item( $cart, $opf_pid );
	$opf_weight = $opf_item ? (float) $opf_item['data']->get_weight() : null;

	$results['live'] = [
		'product_base_weight' => 1.0,
		'wapf_choice_weight'  => 2.0,
		'wapf_item_weight'    => $wapf_weight,
		'opf_item_weight'     => $opf_weight,
		'wapf_applies_weight' => null !== $wapf_weight && abs( $wapf_weight - 3.0 ) < 0.001,
		'opf_drops_weight'    => null !== $opf_weight && abs( $opf_weight - 1.0 ) < 0.001,
	];
	$assert( $results['live']['wapf_applies_weight'], 'WAPF reference did not apply the choice weight: ' . wp_json_encode( $results['live'] ) );
	$assert( $results['live']['opf_drops_weight'], 'OPF unexpectedly applied a choice weight: ' . wp_json_encode( $results['live'] ) );
} finally {
	$cart->empty_cart( true );
	wc_clear_notices();
	foreach ( array_reverse( $owned_groups ) as $gid ) {
		wp_delete_post( (int) $gid, true );
	}
	foreach ( array_reverse( $owned_products ) as $pid ) {
		$product = wc_get_product( (int) $pid );
		if ( $product ) {
			$product->delete( true );
		}
	}
	if ( null === $admin_only_before ) {
		delete_option( 'opf_admin_only' );
	} else {
		update_option( 'opf_admin_only', $admin_only_before );
	}
	FieldGroups::flush_cache();
}

$path = $out . '/priceb-weight-gap.json';
file_put_contents( $path, wp_json_encode( [
	'generated' => gmdate( 'c' ),
	'wapf_version' => (string) ( get_plugin_data( WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' )['Version'] ?? '' ),
	'conclusion' => 'confirmed-opf-gap',
	'results' => $results,
	'cleanup' => [ 'cart_empty' => $cart->is_empty() ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

$assert( $cart->is_empty(), 'Cleanup left cart fixtures behind.' );
WP_CLI::success( 'Weight gap confirmed: OPF drops WAPF weight metadata at normalization and has no cart weight engine; WAPF reference raises item weight 1→3. Evidence: ' . $path );
