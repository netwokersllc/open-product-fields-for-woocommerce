<?php
/** Disposable real WooCommerce product placement proof; setup, commerce, cleanup phases. */
if ( '1' !== getenv( 'OPF_PRODUCT_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
 throw new RuntimeException( 'Explicit disposable /tmp authorization required.' );
}
function product_check( string $name, bool $pass ): void {
 if ( ! $pass ) { throw new RuntimeException( $name ); }
 echo "ok $name\n";
}
$phase = getenv( 'OPF_PRODUCT_E2E_PHASE' ) ?: 'commerce';
$state = get_option( 'opf_product_e2e_state', [] );
if ( 'setup' === $phase ) {
 product_check( 'fixture is new', ! $state );
 foreach ( [ 'selected', 'unselected', 'parent', 'otherparent' ] as $key ) {
  $p = in_array( $key, [ 'parent', 'otherparent' ], true ) ? new WC_Product_Variable() : new WC_Product_Simple();
  $p->set_name( 'OPF targeting ' . $key ); $p->set_slug( 'opf-targeting-' . $key );
  $p->set_status( 'publish' ); $p->set_virtual( true ); $p->set_regular_price( '10' );
  $state[$key] = $p->save();
 }
 foreach ( [ 'parent' => 'variation', 'otherparent' => 'othervariation' ] as $parent => $key ) {
  $p = new WC_Product_Variation(); $p->set_parent_id( $state[$parent] );
  $p->set_regular_price( '10' ); $p->set_status( 'publish' ); $p->set_virtual( true );
  $state[$key] = $p->save(); WC_Product_Variable::sync( $state[$parent] );
 }
 foreach ( [ 'positive', 'negative' ] as $key ) {
  $state[$key] = OPF\Service\FieldGroups::save( 0, [
   'fields' => [ [ 'id' => $key, 'type' => 'text', 'label' => 'Targeting ' . $key, 'required' => true ] ],
   'rule_groups' => [], // Rules are authored by the real browser, not seeded.
  ], [ 'title' => 'OPF targeting ' . $key, 'status' => 'publish' ] );
 }
 $state['user'] = wp_create_user( 'opf_product_e2e', wp_generate_password( 32 ), 'targeting@example.invalid' );
 product_check( 'fixture administrator created', ! is_wp_error( $state['user'] ) );
 ( new WP_User( $state['user'] ) )->set_role( 'administrator' );
 $state['orders'] = []; update_option( 'opf_product_e2e_state', $state );
 update_option( 'opf_admin_only', 'no' );
 update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
 file_put_contents( getenv( 'OPF_PRODUCT_STATE_FILE' ) ?: '/tmp/opf-product-state.json', wp_json_encode( $state ) );
 echo "SUCCESS product targeting setup\n"; return;
}
product_check( 'fixture exists', ! empty( $state['positive'] ) );
if ( 'or_setup' === $phase ) {
 if ( ! empty( $state['or_group'] ) ) { wp_delete_post( $state['or_group'], true ); wp_delete_term( $state['or_term'], 'product_cat' ); }
 $term = wp_insert_term( 'OPF product OR clearing', 'product_cat' );
 $state['or_term'] = $term['term_id'];
 $state['or_group'] = OPF\Service\FieldGroups::save( 0, [
  'fields' => [ [ 'id' => 'or_note', 'type' => 'text', 'label' => 'OR clearing note' ] ],
  'rule_groups' => [
   [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $state['selected'] ] ] ] ],
   [ 'rules' => [ [ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ (string) $state['or_term'] ] ] ] ],
  ],
 ], [ 'title' => 'OPF OR clearing', 'status' => 'publish' ] );
 update_option( 'opf_product_e2e_state', $state ); echo "SUCCESS OR fixture setup\n"; return;
}
if ( 'or_cleanup' === $phase ) {
 wp_delete_post( $state['or_group'], true ); wp_delete_term( $state['or_term'], 'product_cat' );
 unset( $state['or_group'], $state['or_term'] ); update_option( 'opf_product_e2e_state', $state );
 echo "SUCCESS OR fixture cleanup\n"; return;
}
if ( 'cleanup' === $phase ) {
 if ( ! empty( $state['or_group'] ) ) { wp_delete_post( $state['or_group'], true ); wp_delete_term( $state['or_term'], 'product_cat' ); }
 if ( ! empty( $state['wapf_ux_group'] ) ) { wp_delete_post( $state['wapf_ux_group'], true ); }
 foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $order ) {
  foreach ( $order->get_items() as $item ) {
   if ( in_array( $item->get_product_id(), [ $state['selected'], $state['unselected'], $state['parent'], $state['otherparent'] ], true ) ) { $order->delete( true ); break; }
  }
 }
 foreach ( [ 'positive', 'negative', 'variation', 'othervariation', 'selected', 'unselected', 'parent', 'otherparent' ] as $key ) { wp_delete_post( $state[$key], true ); }
 require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $state['user'] );
 delete_option( 'opf_product_e2e_state' );
 product_check( 'fixture groups and products deleted', ! get_post( $state['positive'] ) && ! wc_get_product( $state['selected'] ) );
 product_check( 'fixture state removed', ! get_option( 'opf_product_e2e_state' ) );
 $leftover_items = 0;
 foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $order ) {
  foreach ( $order->get_items() as $item ) {
   if ( in_array( $item->get_product_id(), [ $state['selected'], $state['unselected'], $state['parent'], $state['otherparent'] ], true ) ) { $leftover_items++; }
  }
 }
 product_check( 'no surviving fixture order items', 0 === $leftover_items );
 echo "SUCCESS product targeting cleanup\n"; return;
}
$expected_terms = [ (string) $state['selected'], (string) $state['parent'] ];
foreach ( [ 'positive' => 'in', 'negative' => 'not_in' ] as $key => $operator ) {
 $group = OPF\Service\FieldGroups::group_from_post( get_post( $state[$key] ) );
 product_check( "$key actual admin save preserves exact product rule", $group->data['rule_groups'] === [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => $operator, 'terms' => $expected_terms ] ] ] ] && 'Saved targeting ' . $key === $group->data['fields'][0]['label'] );
}
foreach ( [ 'selected' => 'positive', 'unselected' => 'negative', 'parent' => 'positive', 'otherparent' => 'negative', 'variation' => 'positive', 'othervariation' => 'negative' ] as $key => $matching ) {
 $ids = array_column( OPF\Service\FieldGroups::for_product( wc_get_product( $state[$key] ) ), 'id' );
 product_check( "$key only matches $matching group", [ $state[$matching] ] === $ids );
}
$manifest = json_decode( file_get_contents( ( getenv( 'OPF_PRODUCT_ARTIFACT_DIR' ) ?: '/tmp/opf-product-artifacts' ) . '/browser-order.json' ), true );
$browser_order = wc_get_order( $manifest['order_id'] );
product_check( 'real browser order reloads from Woo data store', $browser_order instanceof WC_Order );
foreach ( $browser_order->get_items() as $item ) {
 $matching = $item->get_product_id() === $state['selected'] ? 'positive' : 'negative';
 $key = 'positive' === $matching ? 'selected' : 'unselected';
 product_check( "$key browser order contains only matched group", json_decode( $item->get_meta( '_opf_fields', true ), true ) === [ (string) $state[$matching] => [ $matching => 'Browser ' . $key ] ] );
}
if ( ! WC()->cart ) { wc_load_cart(); } $cart = WC()->cart;
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
foreach ( [ 'selected' => 'positive', 'unselected' => 'negative', 'variation' => 'positive', 'othervariation' => 'negative' ] as $key => $matching ) {
 $cart->empty_cart(); $wrong = 'positive' === $matching ? 'negative' : 'positive';
 $pid = in_array( $key, [ 'variation', 'othervariation' ], true ) ? $state[ 'variation' === $key ? 'parent' : 'otherparent' ] : $state[$key];
 $vid = $pid === $state[$key] ? 0 : $state[$key];
 $_POST['opf'] = [ (string) $state[$wrong] => [ $wrong => 'Forged wrong group' ] ]; wc_clear_notices();
 product_check( "$key wrong-group-only classic submission rejects missing matched required field", false === apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, 1, $vid ) );
 $_POST['opf'][(string) $state[$matching]] = [ $matching => 'Classic ' . $key ]; wc_clear_notices();
 $cartkey = $cart->add_to_cart( $pid, 1, $vid ); unset( $_POST['opf'] );
 product_check( "$key classic accepts only matched group", (bool) $cartkey && $cart->get_cart_item( $cartkey )['opf_fields'] === [ (string) $state[$matching] => [ $matching => 'Classic ' . $key ] ] );
}
$dispatch = static function ( $path, $params ) {
 $r = new WP_REST_Request( 'POST', '/wc/store/v1/' . $path ); $r->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
 foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); } return rest_get_server()->dispatch( $r );
};
foreach ( [ 'selected' => 'positive', 'unselected' => 'negative', 'variation' => 'positive', 'othervariation' => 'negative' ] as $key => $matching ) {
 $cart->empty_cart(); $wrong = 'positive' === $matching ? 'negative' : 'positive';
 $raw = [ (string) $state[$wrong] => [ $wrong => 'Forged wrong group' ] ];
 $bad = $dispatch( 'cart/add-item', [ 'id' => $state[$key], 'quantity' => 1, 'opf_fields' => $raw ] );
 product_check( "$key Store API wrong-group-only rejects with empty cart", $bad->get_status() >= 400 && ! count( $cart->get_cart() ) );
 $raw[(string) $state[$matching]] = [ $matching => 'Store ' . $key ];
 $good = $dispatch( 'cart/add-item', [ 'id' => $state[$key], 'quantity' => 1, 'opf_fields' => $raw ] );
 product_check( "$key Store API accepts matched selection", 200 === $good->get_status() || 201 === $good->get_status() );
 $line = reset( $cart->cart_contents );
 $expected = [ (string) $state[$matching] => [ $matching => 'Store ' . $key ] ];
 product_check( "$key Store API captures only matched group", $line['opf_fields'] === $expected );
 $display = wp_json_encode( $good->get_data()['items'][0]['item_data'] );
 product_check( "$key Store API cart display excludes forged group", false !== strpos( $display, 'Store ' . $key ) && false === strpos( $display, 'Forged wrong group' ) );
}
$cart->empty_cart();
foreach ( [ 'selected' => 'positive', 'unselected' => 'negative', 'variation' => 'positive', 'othervariation' => 'negative' ] as $key => $matching ) {
 $response = $dispatch( 'cart/add-item', [ 'id' => $state[$key], 'quantity' => 1, 'opf_fields' => [ (string) $state[$matching] => [ $matching => 'Store ' . $key ] ] ] );
 product_check( "$key added to combined checkout cart", in_array( $response->get_status(), [ 200, 201 ], true ) );
}
WC()->payment_gateways()->init();
$checkout = $dispatch( 'checkout', [ 'payment_method' => 'bacs', 'billing_address' => [ 'first_name' => 'Targeting', 'last_name' => 'Buyer', 'email' => 'targeting@example.invalid', 'address_1' => '1 Test Street', 'city' => 'Testville', 'postcode' => '90210', 'country' => 'US', 'state' => 'CA' ] ] );
product_check( 'combined Store API checkout succeeds', in_array( $checkout->get_status(), [ 200, 201 ], true ) );
$order = wc_get_order( $checkout->get_data()['order_id'] );
product_check( 'combined order reloads all four lines', 4 === count( $order->get_items() ) );
foreach ( $order->get_items() as $item ) {
 $id = $item->get_variation_id() ?: $item->get_product_id();
 $key = array_search( $id, array_intersect_key( $state, array_flip( [ 'selected', 'unselected', 'variation', 'othervariation' ] ) ), true );
 $matching = in_array( $key, [ 'selected', 'variation' ], true ) ? 'positive' : 'negative';
 $wrong = 'positive' === $matching ? 'negative' : 'positive';
 $expected = [ (string) $state[$matching] => [ $matching => 'Store ' . $key ] ];
 product_check( "$key reloaded order contains only matched structured metadata", json_decode( $item->get_meta( '_opf_fields', true ), true ) === $expected );
 product_check( "$key public order meta excludes forged selection", 'Store ' . $key === $item->get_meta( 'Saved targeting ' . $matching, true ) && '' === $item->get_meta( 'Saved targeting ' . $wrong, true ) );
}
$state['orders'][] = $order->get_id(); update_option( 'opf_product_e2e_state', $state );
$cart->empty_cart(); echo "SUCCESS product targeting commerce\n";
