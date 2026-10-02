<?php
/**
 * Prepare, verify, or clean the qflat authenticated account order-again proof.
 *
 * Run only in /tmp/opf-quantity-fee-woo-20261002 after installing
 * bin/e2e-price-quantity-flat-order-again-mu.php in the clone's mu-plugins.
 */
if ( realpath( ABSPATH ) !== '/tmp/opf-quantity-fee-woo-20261002' || '1' !== getenv( 'OPF_QFL_ORDER_AGAIN_ALLOW' ) ) {
	throw new RuntimeException( 'Refusing to run outside the enabled disposable clone.' );
}

$phase = getenv( 'OPF_QFL_ORDER_AGAIN_PHASE' ) ?: 'setup';
$state_key = 'opf_qfl_order_again_state';
$option_names = [ 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop', 'woocommerce_tax_based_on' ];
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "ok $message\n";
};

if ( 'setup' === $phase ) {
	$assert( ! get_option( $state_key ), 'no stale qflat order-again fixture state' );
	$original_options = [];
	foreach ( $option_names as $name ) { $original_options[ $name ] = get_option( $name ); }
	$state = [ 'original_options' => $original_options, 'products' => [], 'groups' => [], 'orders' => [], 'user' => 0, 'tax_rate_id' => 0 ];
	try {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		$state['tax_rate_id'] = (int) WC_Tax::_insert_tax_rate( [
			'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '8.25',
			'tax_rate_name' => 'QFL order-again temporary 8.25%', 'tax_rate_priority' => 99,
			'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '',
		] );
		$assert( $state['tax_rate_id'] > 0, 'temporary 8.25% tax rate created' );

		$make_product = static function ( string $name, string $slug ) use ( &$state ): int {
			$product = new WC_Product_Simple();
			$product->set_name( $name );
			$product->set_slug( $slug );
			$product->set_status( 'publish' );
			$product->set_virtual( true );
			$product->set_regular_price( '10.00' );
			$product->set_tax_status( 'taxable' );
			$id = (int) $product->save();
			$state['products'][] = $id;
			return $id;
		};
		$wapf_product = $make_product( 'QFL WAPF order-again fixture', 'qfl-wapf-qt-order-again' );
		$opf_product = $make_product( 'QFL OPF order-again fixture', 'qfl-opf-qt-order-again' );
		$fee = 0.335;

		$wapf_model = SW_WAPF_PRO\Includes\Classes\Field_Groups::raw_json_to_field_group( [
			'id' => 'p_' . $wapf_product, 'type' => 'wapf_product',
			'fields' => [ [
				'id' => 'plan', 'type' => 'select', 'label' => 'Plan', 'required' => true,
				'description' => '', 'class' => '', 'width' => 100, 'default' => '',
				'choices' => [ [ 'slug' => 'qtyflat', 'label' => 'Quantity flat fee', 'pricing_type' => 'qt', 'pricing_amount' => $fee ] ],
				'conditionals' => [], 'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ],
			] ],
			'conditions' => [], 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ], 'variables' => [],
		] );
		$assert( $wapf_model && isset( $wapf_model->fields[0] ), 'native WAPF 3.1.5 field model parsed' );
		update_post_meta( $wapf_product, '_wapf_fieldgroup', $wapf_model->to_array() );

		$opf_group = OPF\Service\FieldGroups::save( 0, [
			'fields' => [ [ 'id' => 'plan', 'type' => 'select', 'label' => 'Plan', 'required' => true, 'choices' => [
				[ 'slug' => 'qtyflat', 'label' => 'Quantity flat fee', 'pricing' => [ 'type' => 'fixed', 'amount' => $fee, 'per_unit' => true ] ],
			] ] ],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $opf_product ] ] ] ] ],
		], [ 'title' => 'QFL OPF order-again group', 'status' => 'publish' ] );
		$state['groups'][] = (int) $opf_group;
		$assert( $opf_group > 0, 'OPF field group saved' );

		$state['user'] = (int) wp_insert_user( [
			'user_login' => 'qfl-order-again-' . wp_generate_password( 8, false ),
			'user_pass' => wp_generate_password( 32 ), 'user_email' => 'qfl-order-again@example.invalid', 'role' => 'customer',
		] );
		$assert( $state['user'] > 0, 'isolated customer account created' );

		if ( ! WC()->cart ) { wc_load_cart(); }
		$make_order = static function ( string $engine, int $product_id ) use ( $state, $opf_group, $assert ): int {
			WC()->cart->empty_cart( true );
			WC()->session->set( 'cart', [] );
			WC()->session->set( 'cart_totals', null );
			$_POST = [];
			$_REQUEST = [];
			$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
			if ( 'wapf' === $engine ) {
				$_POST['wapf_field_groups'] = 'p_' . $product_id;
				$_REQUEST['wapf_field_groups'] = $_POST['wapf_field_groups'];
				$_POST['wapf'] = [ 'field_plan' => 'qtyflat' ];
				$_REQUEST['wapf'] = $_POST['wapf'];
			} else {
				$_POST['opf'] = [ (string) $opf_group => [ 'plan' => 'qtyflat' ] ];
				$_REQUEST['opf'] = $_POST['opf'];
			}
			$key = WC()->cart->add_to_cart( $product_id, 3 );
			$assert( (bool) $key, "$engine q=3 classic fixture add succeeded" );
			WC()->cart->calculate_totals();
			$order_id = WC()->checkout()->create_order( [
				'billing_email' => 'qfl-order-again@example.invalid', 'billing_first_name' => 'QFL', 'billing_last_name' => 'E2E',
				'payment_method' => 'bacs',
			] );
			$assert( ! is_wp_error( $order_id ) && $order_id > 0, "$engine q=3 checkout order created" );
			$order = wc_get_order( $order_id );
			$order->set_customer_id( (int) $state['user'] );
			$order->save();
			$order->update_status( 'completed', 'QFL isolated order-again browser fixture.' );
			$order = wc_get_order( $order_id );
			$items = array_values( $order->get_items() );
			$assert( 'completed' === $order->get_status() && 1 === count( $items ) && 3 === (int) $items[0]->get_quantity(), "$engine completed order belongs to customer and has q=3 line" );
			$assert( abs( (float) $items[0]->get_total() - 31.005 ) < 0.0001 && abs( (float) $items[0]->get_total_tax() - 2.56 ) < 0.0001, "$engine saved order line and tax are $31.005 / $2.56" );
			return (int) $order_id;
		};
		$state['orders'] = [
			'wapf' => 0,
			'opf' => 0,
		];
		$state['orders']['wapf'] = $make_order( 'wapf', $wapf_product );
		update_option( $state_key, $state );
		$state['orders']['opf'] = $make_order( 'opf', $opf_product );
		foreach ( $state['orders'] as $engine => $order_id ) {
			$state['order_urls'][ $engine ] = wc_get_endpoint_url( 'view-order', (string) $order_id, wc_get_page_permalink( 'myaccount' ) );
		}
		$state['cart_url'] = wc_get_page_permalink( 'cart' );
		$state['created_at_utc'] = gmdate( 'c' );
		update_option( $state_key, $state );
		WC()->cart->empty_cart( true );
		wp_mkdir_p( '/tmp/opf-qfl-order-again-artifacts' );
		file_put_contents( '/tmp/opf-qfl-order-again-artifacts/state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) );
		$assert( has_filter( 'pre_wp_mail' ) !== false, 'pre_wp_mail blocker installed for this clone request' );
		echo wp_json_encode( [ 'phase' => 'setup', 'woocommerce' => WC()->version, 'wapf' => '3.1.5', 'orders' => $state['orders'], 'suppressed_mail_calls' => (int) ( $GLOBALS['opf_qfl_suppressed_mail_count'] ?? 0 ) ], JSON_PRETTY_PRINT ), "\n";
	} catch ( Throwable $error ) {
		foreach ( $state['orders'] as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( $state['groups'] as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( $state['products'] as $id ) { wp_delete_post( (int) $id, true ); }
		if ( $state['user'] ) { wp_delete_user( (int) $state['user'] ); }
		if ( $state['tax_rate_id'] ) { WC_Tax::_delete_tax_rate( (int) $state['tax_rate_id'] ); }
		foreach ( $original_options as $name => $value ) { update_option( $name, $value ); }
		delete_option( $state_key );
		@unlink( '/tmp/opf-qfl-order-again-artifacts/state.json' );
		throw $error;
	}
	return;
}

$state = get_option( $state_key );
$assert( is_array( $state ) && ! empty( $state['orders'] ), 'fixture state exists' );
if ( 'verify' === $phase ) {
	$browser_result_path = __DIR__ . '/../docs/compatibility/qfl-order-again-browser-results.json';
	$assert( is_file( $browser_result_path ), 'real browser result artifact exists' );
	$browser_result = json_decode( file_get_contents( $browser_result_path ), true );
	$assert( is_array( $browser_result ) && empty( $browser_result['errors'] ), 'real browser result has no page errors' );
	$assert( count( array_filter( $browser_result['checks'] ?? [], static fn( $check ) => ! empty( $check['pass'] ) ) ) === count( $browser_result['checks'] ?? [] ), 'all authenticated browser checks passed' );
	foreach ( $state['orders'] as $engine => $order_id ) {
		$order = wc_get_order( $order_id );
		$item = $order ? current( $order->get_items() ) : false;
		$assert( $order instanceof WC_Order && 'completed' === $order->get_status() && (int) $state['user'] === (int) $order->get_customer_id(), "$engine customer account can own completed source order" );
		$assert( $item instanceof WC_Order_Item_Product && 3 === (int) $item->get_quantity(), "$engine source order persists q=3" );
		$assert( abs( (float) $item->get_total() - 31.005 ) < 0.0001 && abs( (float) $item->get_total_tax() - 2.56 ) < 0.0001, "$engine source order persists $31.005 line / $2.56 tax" );
		$observed = $browser_result['observed'][ $engine ] ?? [];
		$assert( 3 === (int) ( $observed['quantity'] ?? 0 ) && 31.005 === (float) ( $observed['line_subtotal'] ?? 0 ) && 31.005 === (float) ( $observed['line_total'] ?? 0 ) && 2.56 === (float) ( $observed['line_subtotal_tax'] ?? 0 ) && 2.56 === (float) ( $observed['line_tax'] ?? 0 ), "$engine authenticated browser order-again cart preserves q=3 line price and tax" );
	}
	return;
}
if ( 'cleanup' !== $phase ) { throw new RuntimeException( 'Phase must be setup, verify, or cleanup.' ); }

if ( WC()->cart ) { WC()->cart->empty_cart( true ); }
foreach ( $state['orders'] as $id ) { wp_delete_post( (int) $id, true ); }
foreach ( $state['groups'] as $id ) { wp_delete_post( (int) $id, true ); }
foreach ( $state['products'] as $id ) { wp_delete_post( (int) $id, true ); }
if ( ! empty( $state['user'] ) ) { wp_delete_user( (int) $state['user'] ); }
if ( ! empty( $state['tax_rate_id'] ) ) { WC_Tax::_delete_tax_rate( (int) $state['tax_rate_id'] ); }
foreach ( $state['original_options'] as $name => $value ) { update_option( $name, $value ); }
delete_option( $state_key );
@unlink( '/tmp/opf-qfl-order-again-artifacts/state.json' );
echo "SUCCESS qflat order-again fixture cleanup\n";
