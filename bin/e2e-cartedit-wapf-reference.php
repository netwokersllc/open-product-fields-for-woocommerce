<?php
/**
 * WAPF Extended 3.1.5 edit-in-cart reference proof (guarded disposable use).
 *
 * Confirms the semantics OPF mirrors: opt-in gate, native cart-line link
 * markup/attributes, edit-permalink prefill, and remove-then-add replacement.
 * Requires WAPF Extended active and OPF inactive.
 *
 * Env: OPF_CARTEDIT_REF_ALLOW=1 OPF_CARTEDIT_REF_PHASE=setup|verify|cleanup
 */
if ( '1' !== getenv( 'OPF_CARTEDIT_REF_ALLOW' ) || 0 !== strpos( (string) realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Requires an authorized disposable /tmp WordPress.' );
}
if ( ! class_exists( 'SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
	throw new RuntimeException( 'WAPF Extended must be active for the reference run.' );
}
if ( class_exists( 'OPF\Service\CartEdit' ) ) {
	throw new RuntimeException( 'OPF must be inactive during the WAPF reference run.' );
}

use SW_WAPF_PRO\Includes\Classes\Field_Groups;
use SW_WAPF_PRO\Includes\Classes\Util;
use SW_WAPF_PRO\Includes\Controllers\Product_Controller;

$results = &$GLOBALS['cer'];
function cer( string $label, bool $ok, $detail = null ): void {
	$GLOBALS['cer'][] = [ 'label' => $label, 'pass' => (bool) $ok, 'detail' => $detail ];
	echo ( $ok ? 'ok ' : 'FAIL ' ) . $label . ( null !== $detail ? ' :: ' . wp_json_encode( $detail ) : '' ) . "\n";
}

$phase = getenv( 'OPF_CARTEDIT_REF_PHASE' ) ?: 'verify';
$dir   = '/tmp/opf-cartedit-e2e';
$state = get_option( 'opf_ce_wapf_ref_state', [] );

if ( 'setup' === $phase ) {
	cer( 'no existing reference fixture', ! $state );
	if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
	$product = new WC_Product_Simple();
	$product->set_name( 'WAPF cart-edit reference' );
	$product->set_slug( 'wapf-cartedit-reference' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '12' );
	$product->set_virtual( true );
	$pid = (int) $product->save();
	update_post_meta( $pid, '_wapf_fieldgroup', [
		'id'          => 'p_' . $pid,
		'type'        => 'wapf_product',
		'fields'      => [
			[
				'id' => 'source', 'label' => 'Source', 'type' => 'text', 'required' => true,
				'conditionals' => [], 'clone' => [ 'enabled' => false ],
				'options' => [ 'default' => '' ], 'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ],
			],
		],
		'rule_groups' => [],
		'layout'      => [],
	] );
	$state = [
		'pid' => $pid,
		'gid' => 'p_' . $pid,
		'edit_opt_existed' => false !== get_option( 'wapf_edit_cart', false ),
		'edit_opt_prev'    => get_option( 'wapf_edit_cart', null ),
	];
	// Set now: Util::can_edit_in_cart() memoizes per request.
	update_option( 'wapf_edit_cart', 'yes' );
	update_option( 'opf_ce_wapf_ref_state', $state );
	file_put_contents( '/tmp/opf-cartedit-e2e/wapf-state.json', wp_json_encode( $state ) );
	echo "ok wapf reference fixture setup (product $pid)\n";
	return;
}

if ( 'cleanup' === $phase ) {
	cer( 'reference state exists', ! empty( $state ) );
	if ( ! empty( $state['pid'] ) ) {
		foreach ( wc_get_orders( [ 'limit' => -1, 'return' => 'objects' ] ) as $order ) {
			foreach ( $order->get_items() as $item ) { if ( (int) $item->get_product_id() === (int) $state['pid'] ) { $order->delete( true ); break; } }
		}
		wp_delete_post( (int) $state['pid'], true );
	}
	if ( ! empty( $state['edit_opt_existed'] ) ) { update_option( 'wapf_edit_cart', $state['edit_opt_prev'] ); }
	else { delete_option( 'wapf_edit_cart' ); }
	delete_option( 'opf_ce_wapf_ref_state' );
	if ( file_exists( '/tmp/opf-cartedit-e2e/wapf-state.json' ) ) { unlink( '/tmp/opf-cartedit-e2e/wapf-state.json' ); }
	echo "ok wapf reference fixture cleanup\n";
	return;
}

/* verify */
cer( 'reference fixture exists', ! empty( $state['pid'] ) );
$pid = (int) $state['pid'];
$gid = (string) $state['gid'];
if ( ! WC()->cart ) { wc_load_cart(); }
WC()->cart->empty_cart();
wc_clear_notices();

// 1) opt-in gate requires the setting AND wapf cart data.
// (wapf_edit_cart=yes was set in setup; can_edit_in_cart memoizes per request.)
cer( 'gate open: setting + wapf data', true === Util::can_edit_cart_item( [ 'wapf' => [ [ 'id' => 'source' ] ] ] ) );
cer( 'gate closed without wapf data', false === Util::can_edit_cart_item( [] ) );

// 2) add an original line through WAPF's own pipeline.
$_REQUEST['wapf_field_groups'] = $gid;
$_REQUEST['wapf']              = [ 'field_source' => 'ORIG' ];
$key = WC()->cart->add_to_cart( $pid, 1 );
unset( $_REQUEST['wapf_field_groups'], $_REQUEST['wapf'] );
$item = $key ? WC()->cart->get_cart_item( $key ) : null;
cer( 'WAPF line carries wapf data', $item && isset( $item['wapf'] ), $item ? array_keys( $item ) : null );
cer( 'WAPF gate true for the added line', $item && true === Util::can_edit_cart_item( $item ) );

// 3) cart-line edit link markup + attributes.
$controller = new Product_Controller();
ob_start();
$controller->add_edit_link( $item, $key );
$link_html = (string) ob_get_clean();
cer( 'edit link uses class wapf-edit-cartitem', false !== strpos( $link_html, 'class="wapf-edit-cartitem"' ), $link_html );
cer( 'edit link carries _edit=<cart key>', false !== strpos( $link_html, '_edit=' . $key ), $link_html );
cer( 'edit link text is (edit)', false !== strpos( $link_html, '(edit)' ), $link_html );

// 4) edit permalink prefills the product form from the cart line.
$_GET['_edit'] = $key;
$GLOBALS['product'] = wc_get_product( $pid );
ob_start();
$controller->display_field_groups();
$prefill_html = (string) ob_get_clean();
unset( $_GET['_edit'], $GLOBALS['product'] );
cer( 'prefilled form restores the stored value', false !== strpos( $prefill_html, 'value="ORIG"' ), substr( $prefill_html, 0, 200 ) );
cer( 'prefilled form emits hidden _wapf_edit key', false !== strpos( $prefill_html, 'name="_wapf_edit"' ) && false !== strpos( $prefill_html, 'value="' . $key . '"' ) );

// 5) submit-vs-replace: remove-then-add; values updated, old key gone.
$old_key = $key;
$_POST['_wapf_edit']           = $old_key;
$_REQUEST['wapf_field_groups'] = $gid;
$_REQUEST['wapf']              = [ 'field_source' => 'EDITED' ];
$new_key = WC()->cart->add_to_cart( $pid, 1 );
unset( $_POST['_wapf_edit'], $_REQUEST['wapf_field_groups'], $_REQUEST['wapf'] );
$new_item = $new_key ? WC()->cart->get_cart_item( $new_key ) : null;
cer( 'remove-then-add replaced the line', null !== $new_item && null === ( WC()->cart->get_cart_item( $old_key ) ?: null ), [ 'old' => $old_key, 'new' => $new_key ] );
$values = [];
if ( $new_item && isset( $new_item['wapf'][0]['values'] ) ) {
	foreach ( $new_item['wapf'][0]['values'] as $v ) { $values[] = $v['label'] ?? ( $v['slug'] ?? null ); }
}
cer( 'replacement stores the edited value', in_array( 'EDITED', $values, true ), [ 'values' => $values ] );
cer( 'WAPF retains remove-then-add (key not preserved when data changes)', $old_key !== $new_key, [ 'old' => $old_key, 'new' => $new_key ] );

WC()->cart->empty_cart();
wc_clear_notices();
file_put_contents( $dir . '/wapf-reference-results.json', wp_json_encode( $results, JSON_PRETTY_PRINT ) );
$failed = array_filter( $results, static fn ( $r ) => ! $r['pass'] );
echo count( $results ) . ' reference checks, ' . count( $failed ) . " failed\n";
if ( $failed ) { throw new RuntimeException( 'WAPF reference verification failures.' ); }
