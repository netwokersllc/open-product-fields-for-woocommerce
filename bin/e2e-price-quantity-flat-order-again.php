<?php
/**
 * Prepare, verify, or clean the qflat authenticated account order-again proof.
 *
 * Run only in /tmp/opf-quantity-fee-woo-20261002 via
 * bin/run-e2e-price-quantity-flat-proof.sh.
 */
if ( realpath( ABSPATH ) !== '/tmp/opf-quantity-fee-woo-20261002' || '1' !== getenv( 'OPF_QFL_ORDER_AGAIN_ALLOW' ) ) {
	throw new RuntimeException( 'Refusing to run outside the enabled disposable clone.' );
}

$phase = getenv( 'OPF_QFL_ORDER_AGAIN_PHASE' ) ?: 'setup';
$state_key = 'opf_qfl_order_again_state';
$verification_key = 'opf_qfl_order_again_verified';
$option_names = [ 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart', 'woocommerce_tax_based_on' ];
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "ok $message\n";
};
$artifact_dir = '/tmp/opf-qfl-order-again-artifacts';
$state_path = $artifact_dir . '/state.json';
$safe_write = static function ( string $path, string $contents ): void {
	$dir = dirname( $path );
	if ( is_link( $dir ) || is_link( $path ) ) { throw new RuntimeException( "Refusing symlink artifact path: $path" ); }
	$temp = $path . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
	$handle = fopen( $temp, 'x' );
	if ( false === $handle ) { throw new RuntimeException( "Cannot create artifact temp: $temp" ); }
	try { if ( strlen( $contents ) !== fwrite( $handle, $contents ) || ! fflush( $handle ) ) { throw new RuntimeException( "Cannot write artifact temp: $temp" ); } }
	catch ( Throwable $error ) { fclose( $handle ); @unlink( $temp ); throw $error; }
	fclose( $handle );
	chmod( $temp, 0600 );
	if ( is_link( $path ) || ! rename( $temp, $path ) ) { @unlink( $temp ); throw new RuntimeException( "Cannot safely replace artifact: $path" ); }
};
$remove_mu_link = static function (): void {
	$link = '/tmp/opf-quantity-fee-woo-20261002/wp-content/mu-plugins/qfl-order-again.php';
	$expected = realpath( __DIR__ . '/e2e-price-quantity-flat-order-again-mu.php' );
	if ( is_link( $link ) ) {
		if ( readlink( $link ) !== $expected ) { throw new RuntimeException( 'Refusing to remove unexpected clone MU-plugin symlink.' ); }
		if ( ! unlink( $link ) ) { throw new RuntimeException( 'Could not remove clone MU-plugin symlink.' ); }
	}
};
$remove_order = static function ( int $id, string $run_id ): bool {
	$order = wc_get_order( $id );
	if ( ! $order ) { return true; }
	if ( $run_id !== $order->get_meta( '_opf_qfl_order_again_fixture', true ) ) { throw new RuntimeException( "Refusing to delete unmarked order $id." ); }
	$order->delete( true );
	return false === wc_get_order( $id );
};
$remove_fresh_order = static function ( int $id, array $created_ids ): bool {
	if ( ! in_array( $id, $created_ids, true ) ) { throw new RuntimeException( "Refusing rollback of order $id not returned by this setup's create_order call." ); }
	$order = wc_get_order( $id );
	if ( $order ) { $order->delete( true ); }
	return false === wc_get_order( $id );
};

if ( 'setup' === $phase ) {
	$assert( ! get_option( $state_key ), 'no stale qflat order-again fixture state' );
	$assert( ! get_option( $verification_key ), 'no stale qflat verifier success sentinel' );
	$original_options = [];
	foreach ( $option_names as $name ) { $original_options[ $name ] = get_option( $name ); }
	$run_id = wp_generate_uuid4();
	$state = [ 'run_id' => $run_id, 'runtime' => [ 'woocommerce' => WC()->version, 'wapf' => '3.1.5', 'opf_source' => realpath( WP_PLUGIN_DIR . '/open-product-fields-for-woocommerce' ) ], 'original_options' => $original_options, 'products' => [], 'groups' => [], 'orders' => [], 'user' => 0, 'tax_rate_id' => 0, 'login_tokens' => [], 'probe_tokens' => [] ];
	$created_order_ids = [];
	$state_option_created = false;
	$state_file_created = false;
	try {
		if ( is_link( $artifact_dir ) ) { throw new RuntimeException( 'Refusing symlink artifact directory.' ); }
		wp_mkdir_p( $artifact_dir );
		if ( is_link( $state_path ) ) { throw new RuntimeException( 'Refusing symlink state artifact.' ); }
		if ( file_exists( $state_path ) ) { throw new RuntimeException( 'Refusing stale fixture state artifact; preserving existing file.' ); }
		$browser_result_path = __DIR__ . '/../docs/compatibility/qfl-order-again-browser-results.json';
		if ( is_link( $browser_result_path ) ) { throw new RuntimeException( 'Refusing symlink browser result artifact.' ); }
		$run_result_path = __DIR__ . '/../docs/compatibility/qfl-order-again-run-results.json';
		if ( is_link( $run_result_path ) ) { throw new RuntimeException( 'Refusing symlink run result artifact.' ); }
		foreach ( [ $browser_result_path, $run_result_path ] as $artifact_path ) {
			if ( file_exists( $artifact_path ) && ! is_file( $artifact_path ) ) { throw new RuntimeException( "Refusing non-regular evidence artifact: $artifact_path" ); }
			if ( is_file( $artifact_path ) && ! unlink( $artifact_path ) ) { throw new RuntimeException( "Could not invalidate prior evidence artifact: $artifact_path" ); }
			if ( file_exists( $artifact_path ) ) { throw new RuntimeException( "Prior evidence artifact remains: $artifact_path" ); }
		}
		if ( '1' === getenv( 'OPF_QFL_TEST_FAIL_AFTER_EVIDENCE_INVALIDATION' ) ) { throw new RuntimeException( 'Injected setup failure after evidence invalidation.' ); }
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
		update_option( 'woocommerce_tax_display_cart', 'excl' );
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
		$make_order = static function ( string $engine, int $product_id ) use ( &$state, &$created_order_ids, $opf_group, $assert, $run_id ): int {
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
			$order_id = (int) $order_id;
			$created_order_ids[] = $order_id;
			$state['orders'][ $engine ] = $order_id;
			$order = wc_get_order( $order_id );
			$assert( $order instanceof WC_Order, "$engine new order loads through Woo CRUD / HPOS" );
			$order->update_meta_data( '_opf_qfl_order_again_fixture', $run_id );
			$order->set_customer_id( (int) $state['user'] );
			$order->save();
			$order->update_status( 'completed', 'QFL isolated order-again browser fixture.' );
			$order = wc_get_order( $order_id );
			$items = array_values( $order->get_items() );
			$assert( 'completed' === $order->get_status() && 1 === count( $items ) && 3 === (int) $items[0]->get_quantity(), "$engine completed order belongs to customer and has q=3 line" );
			$assert( 31.005 === round( (float) $items[0]->get_total(), 3 ) && 2.56 === (float) $items[0]->get_total_tax() && 33.57 === round( (float) $order->get_total(), 2 ), "$engine saved order has exact $31.005 line / $2.56 tax / $33.57 total" );
			$metadata = 'wapf' === $engine ? $items[0]->get_meta( '_wapf_meta', true ) : $items[0]->get_meta( '_opf_fields', true );
			$metadata_json = wp_json_encode( $metadata );
			$assert( false !== strpos( $metadata_json, 'qtyflat' ), "$engine persisted selected choice metadata" );
			if ( 'wapf' === $engine ) { $assert( false !== strpos( $metadata_json, 'qt' ), 'WAPF persisted native qt pricing metadata' ); }
			return $order_id;
		};
		$state['orders']['wapf'] = $make_order( 'wapf', $wapf_product );
		$state['orders']['opf'] = $make_order( 'opf', $opf_product );
		foreach ( $state['orders'] as $engine => $order_id ) {
			$state['order_urls'][ $engine ] = wc_get_endpoint_url( 'view-order', (string) $order_id, wc_get_page_permalink( 'myaccount' ) );
		}
		$state['cart_url'] = wc_get_page_permalink( 'cart' );
		$state['created_at_utc'] = gmdate( 'c' );
		foreach ( [ 'wapf', 'opf' ] as $engine ) {
			$state['login_tokens'][ $engine ] = bin2hex( random_bytes( 32 ) );
			$state['probe_tokens'][ $engine ] = bin2hex( random_bytes( 32 ) );
		}
		$state['setup_mail_hook_calls'] = (int) ( $GLOBALS['opf_qfl_pre_wp_mail_calls'] ?? 0 );
		$state['setup_mail_short_circuits'] = (int) ( $GLOBALS['opf_qfl_pre_wp_mail_short_circuits'] ?? 0 );
		$state['browser_mail_hook_calls'] = 0;
		$state['browser_mail_short_circuits'] = 0;
		update_option( $state_key, $state );
		$state_option_created = true;
		WC()->cart->empty_cart( true );
		$safe_write( $state_path, wp_json_encode( $state, JSON_PRETTY_PRINT ) );
		$state_file_created = true;
		$assert( has_filter( 'pre_wp_mail' ) !== false, 'pre_wp_mail blocker installed for this clone request' );
		$assert( $state['setup_mail_hook_calls'] > 0 && $state['setup_mail_hook_calls'] === $state['setup_mail_short_circuits'], 'each setup wp_mail hook call was short-circuited by pre_wp_mail=true' );
		echo wp_json_encode( [ 'phase' => 'setup', 'runtime' => $state['runtime'], 'run_id' => $run_id, 'orders' => $state['orders'], 'pre_wp_mail_calls' => $state['setup_mail_hook_calls'], 'pre_wp_mail_short_circuits' => $state['setup_mail_short_circuits'] ], JSON_PRETTY_PRINT ), "\n";
	} catch ( Throwable $error ) {
		$rollback_errors = [];
		$attempt_rollback = static function ( callable $cleanup ) use ( &$rollback_errors ): void { try { $cleanup(); } catch ( Throwable $rollback_error ) { $rollback_errors[] = $rollback_error->getMessage(); } };
		foreach ( $created_order_ids as $id ) { $attempt_rollback( static function () use ( $remove_fresh_order, $id, $created_order_ids ): void { if ( ! $remove_fresh_order( (int) $id, $created_order_ids ) ) { throw new RuntimeException( "Fresh order $id remains after Woo CRUD rollback." ); } } ); }
		foreach ( $state['groups'] as $id ) { $attempt_rollback( static function () use ( $id ): void { if ( $id ) { wp_delete_post( (int) $id, true ); if ( get_post( (int) $id ) ) { throw new RuntimeException( "OPF group $id remains after rollback." ); } } } ); }
		foreach ( $state['products'] as $id ) { $attempt_rollback( static function () use ( $id ): void { if ( $id ) { wp_delete_post( (int) $id, true ); if ( wc_get_product( (int) $id ) ) { throw new RuntimeException( "Product $id remains after rollback." ); } } } ); }
		if ( $state['user'] ) { $attempt_rollback( static function () use ( $state ): void { wp_delete_user( (int) $state['user'] ); if ( get_userdata( (int) $state['user'] ) ) { throw new RuntimeException( 'Customer remains after rollback.' ); } } ); }
		if ( $state['tax_rate_id'] ) { $attempt_rollback( static function () use ( $state ): void { WC_Tax::_delete_tax_rate( (int) $state['tax_rate_id'] ); if ( WC_Tax::_get_tax_rate( (int) $state['tax_rate_id'] ) ) { throw new RuntimeException( 'Temporary tax rate remains after rollback.' ); } } ); }
		foreach ( $original_options as $name => $value ) { $attempt_rollback( static function () use ( $name, $value ): void { update_option( $name, $value ); if ( get_option( $name ) !== $value ) { throw new RuntimeException( "Option $name was not restored during rollback." ); } } ); }
		if ( $state_option_created ) { $attempt_rollback( static function () use ( $state_key ): void { delete_option( $state_key ); if ( false !== get_option( $state_key, false ) ) { throw new RuntimeException( 'Fixture state remains after rollback.' ); } } ); }
		if ( $state_file_created ) { $attempt_rollback( static function () use ( $state_path ): void { if ( is_link( $state_path ) ) { throw new RuntimeException( 'Refusing to remove symlink state artifact during rollback.' ); } @unlink( $state_path ); if ( file_exists( $state_path ) ) { throw new RuntimeException( 'Fixture state file remains after rollback.' ); } } ); }
		$attempt_rollback( $remove_mu_link );
		if ( $rollback_errors ) { throw new RuntimeException( $error->getMessage() . '; rollback errors: ' . implode( '; ', $rollback_errors ), 0, $error ); }
		throw $error;
	}
	return;
}

$state = get_option( $state_key );
$assert( is_array( $state ) && ! empty( $state['orders'] ), 'fixture state exists' );
if ( 'verify' === $phase ) {
	$browser_result_path = __DIR__ . '/../docs/compatibility/qfl-order-again-browser-results.json';
	$assert( ! is_link( $browser_result_path ), 'browser result artifact is not a symlink' );
	$assert( is_file( $browser_result_path ), 'real browser result artifact exists' );
	$browser_result = json_decode( file_get_contents( $browser_result_path ), true );
	$expected_labels = [
		'wapf loopback login authenticated the isolated customer', 'wapf authenticated My Account displays actual Order again action',
		'wapf action targets this completed order with Woo nonce', 'wapf real Order again click redirects to cart',
		'wapf cart page confirms WooCommerce restored the prior order', 'wapf authenticated cart probe responds',
		'wapf cart probe remains the fixture customer', 'wapf order-again restores exactly one q=3 item',
		'wapf restored fixture product has $31.005 line subtotal and total', 'wapf line tax recalculates to $2.56',
		'WAPF order-again restores the native qt choice', 'opf loopback login authenticated the isolated customer',
		'opf authenticated My Account displays actual Order again action', 'opf action targets this completed order with Woo nonce',
		'opf real Order again click redirects to cart', 'opf cart page confirms WooCommerce restored the prior order',
		'opf authenticated cart probe responds', 'opf cart probe remains the fixture customer',
		'opf order-again restores exactly one q=3 item', 'opf restored fixture product has $31.005 line subtotal and total',
		'opf line tax recalculates to $2.56', 'OPF order-again restores its native selected choice',
		'no uncaught Chromium page errors',
	];
	$actual_labels = array_column( $browser_result['checks'] ?? [], 'label' );
	$assert( is_array( $browser_result ) && true === ( $browser_result['completed'] ?? false ), 'browser artifact has explicit completion marker' );
	$assert( ( $state['run_id'] ?? '' ) === ( $browser_result['run_id'] ?? '' ), 'browser artifact belongs to current setup run' );
	$assert( $expected_labels === $actual_labels && 23 === count( $actual_labels ), 'browser artifact has exact 23 expected labels in order' );
	$assert( empty( $browser_result['errors'] ) && 23 === count( array_filter( $browser_result['checks'], static fn( $check ) => true === ( $check['pass'] ?? false ) ) ), 'all exact authenticated browser checks passed with no errors' );
	$assert( $browser_result['orders'] === $state['orders'] && $browser_result['runtime'] === $state['runtime'], 'browser artifact records this run’s order IDs and runtime versions' );
	$assert( $browser_result['mail_suppression_setup'] === [ 'hook_calls' => $state['setup_mail_hook_calls'], 'short_circuit_returns' => $state['setup_mail_short_circuits'] ], 'browser artifact records measured setup mail interception' );
	foreach ( $state['orders'] as $engine => $order_id ) {
		$order = wc_get_order( $order_id );
		$item = $order ? current( $order->get_items() ) : false;
		$assert( $order instanceof WC_Order && 'completed' === $order->get_status() && (int) $state['user'] === (int) $order->get_customer_id(), "$engine customer account can own completed source order" );
		$assert( $item instanceof WC_Order_Item_Product && 3 === (int) $item->get_quantity(), "$engine source order persists q=3" );
		$assert( 31.005 === round( (float) $item->get_total(), 3 ) && 2.56 === (float) $item->get_total_tax() && 33.57 === round( (float) $order->get_total(), 2 ), "$engine source order persists exact $31.005 line / $2.56 tax / $33.57 total" );
		$assert( $state['run_id'] === $order->get_meta( '_opf_qfl_order_again_fixture', true ), "$engine order has this fixture's unique marker" );
		$meta_key = 'wapf' === $engine ? '_wapf_meta' : '_opf_fields';
		$meta = $item->get_meta( $meta_key, true );
		$assert( false !== strpos( wp_json_encode( $meta ), 'qtyflat' ), "$engine persisted choice metadata reloads from Woo order CRUD" );
		if ( 'wapf' === $engine ) { $assert( false !== strpos( wp_json_encode( $meta ), 'qt' ), 'WAPF order persists qt metadata' ); }
		$observed = $browser_result['observed'][ $engine ] ?? [];
		$choice = $observed['restored_choice'] ?? [];
		$choice_ok = 'wapf' === $engine ? ( 'qtyflat' === ( $choice['slug'] ?? '' ) && 'qt' === ( $choice['price_type'] ?? '' ) ) : ( 'qtyflat' === ( $choice['slug'] ?? '' ) );
		$assert( 3 === (int) ( $observed['quantity'] ?? 0 ) && 31.005 === (float) ( $observed['line_subtotal'] ?? 0 ) && 31.005 === (float) ( $observed['line_total'] ?? 0 ) && 2.56 === (float) ( $observed['line_subtotal_tax'] ?? 0 ) && 2.56 === (float) ( $observed['line_tax'] ?? 0 ) && $choice_ok, "$engine browser restored actual choice and q=3 exact line/tax values" );
	}
	$verification = [ 'passed' => true, 'run_id' => $state['run_id'], 'browser_result_sha256' => hash_file( 'sha256', $browser_result_path ), 'checked_labels' => $expected_labels, 'verified_at_utc' => gmdate( 'c' ) ];
	update_option( $verification_key, $verification );
	$stored_verification = get_option( $verification_key, false );
	if ( ! is_array( $stored_verification ) || $stored_verification !== $verification ) { delete_option( $verification_key ); throw new RuntimeException( 'Could not persist this run’s successful verification sentinel.' ); }
	echo "ok successful verification sentinel stored for run {$state['run_id']}\n";
	return;
}
if ( 'cleanup' !== $phase ) { throw new RuntimeException( 'Phase must be setup, verify, or cleanup.' ); }

$verification = get_option( $verification_key, false );
if ( WC()->cart ) { WC()->cart->empty_cart( true ); }
$orders_before_cleanup = [];
foreach ( $state['orders'] as $engine => $id ) {
	$order = wc_get_order( (int) $id );
	$items = [];
	if ( $order ) {
		foreach ( $order->get_items() as $item ) { $items[] = [ 'quantity' => (int) $item->get_quantity(), 'line_total' => round( (float) $item->get_total(), 3 ), 'line_tax' => round( (float) $item->get_total_tax(), 2 ), 'choice_meta' => $item->get_meta( 'wapf' === $engine ? '_wapf_meta' : '_opf_fields', true ) ]; }
		$orders_before_cleanup[ $engine ] = [ 'id' => (int) $id, 'status' => $order->get_status(), 'total' => round( (float) $order->get_total(), 2 ), 'items' => $items ];
	}
}
$deleted_orders = [];
foreach ( $state['orders'] as $id ) { $deleted_orders[] = $remove_order( (int) $id, $state['run_id'] ); }
foreach ( $state['groups'] as $id ) { wp_delete_post( (int) $id, true ); }
foreach ( $state['products'] as $id ) { wp_delete_post( (int) $id, true ); }
if ( ! empty( $state['user'] ) ) { wp_delete_user( (int) $state['user'] ); }
if ( ! empty( $state['tax_rate_id'] ) ) { WC_Tax::_delete_tax_rate( (int) $state['tax_rate_id'] ); }
foreach ( $state['original_options'] as $name => $value ) { update_option( $name, $value ); }
delete_option( $state_key );
$browser_result_path = __DIR__ . '/../docs/compatibility/qfl-order-again-browser-results.json';
$browser_result = null;
if ( ! is_link( $browser_result_path ) && is_file( $browser_result_path ) ) { $browser_result = json_decode( file_get_contents( $browser_result_path ), true ); }
$verification_valid = is_array( $verification )
	&& true === ( $verification['passed'] ?? false )
	&& ( $verification['run_id'] ?? '' ) === $state['run_id']
	&& is_array( $browser_result )
	&& ( $browser_result['run_id'] ?? '' ) === $state['run_id']
	&& true === ( $browser_result['completed'] ?? false )
	&& hash_file( 'sha256', $browser_result_path ) === ( $verification['browser_result_sha256'] ?? '' );
$verification_deleted = delete_option( $verification_key );
$remove_mu_link();
$all_mail_calls_short_circuited = $state['setup_mail_hook_calls'] === $state['setup_mail_short_circuits']
	&& (int) ( $state['browser_mail_hook_calls'] ?? 0 ) === (int) ( $state['browser_mail_short_circuits'] ?? 0 );
$cleanup = [
	'order_ids' => array_values( $state['orders'] ),
	'orders_absent_via_wc_get_order' => ! in_array( false, $deleted_orders, true ) && array_reduce( $state['orders'], static fn( bool $ok, int $id ): bool => $ok && false === wc_get_order( $id ), true ),
	'product_ids' => array_values( $state['products'] ),
	'products_absent' => array_reduce( $state['products'], static fn( bool $ok, int $id ): bool => $ok && ! wc_get_product( $id ), true ),
	'group_ids' => array_values( $state['groups'] ),
	'groups_absent' => array_reduce( $state['groups'], static fn( bool $ok, int $id ): bool => $ok && ! get_post( $id ), true ),
	'customer_id' => (int) $state['user'],
	'customer_absent' => ! get_userdata( (int) $state['user'] ),
	'tax_rate_id' => (int) $state['tax_rate_id'],
	'tax_rate_absent' => ! WC_Tax::_get_tax_rate( (int) $state['tax_rate_id'] ),
	'cart_empty' => ! WC()->cart || 0 === WC()->cart->get_cart_contents_count(),
	'fixture_state_absent' => false === get_option( $state_key, false ),
	'verification_sentinel_absent' => false === get_option( $verification_key, false ),
	'mu_symlink_absent' => ! is_link( '/tmp/opf-quantity-fee-woo-20261002/wp-content/mu-plugins/qfl-order-again.php' ),
	'every_pre_wp_mail_call_short_circuited' => $all_mail_calls_short_circuited,
	'options_restored' => true,
];
foreach ( $state['original_options'] as $name => $value ) { $cleanup['options_restored'] = $cleanup['options_restored'] && get_option( $name ) === $value; }
$assert( ! in_array( false, $cleanup, true ), 'cleanup verification passed: ' . wp_json_encode( $cleanup ) );

if ( $verification_valid && $verification_deleted && ! in_array( false, $cleanup, true ) ) {
	$run_artifact = [ 'completed' => true, 'run_id' => $state['run_id'], 'runtime' => $state['runtime'], 'orders' => $orders_before_cleanup, 'mail_suppression' => [ 'setup_hook_calls' => $state['setup_mail_hook_calls'], 'setup_short_circuit_returns' => $state['setup_mail_short_circuits'], 'browser_hook_calls' => (int) ( $state['browser_mail_hook_calls'] ?? 0 ), 'browser_short_circuit_returns' => (int) ( $state['browser_mail_short_circuits'] ?? 0 ), 'every_hook_returned_true' => $all_mail_calls_short_circuited ], 'browser' => $browser_result, 'verification' => [ 'passed' => true, 'run_id' => $verification['run_id'], 'browser_result_sha256' => $verification['browser_result_sha256'] ], 'cleanup' => $cleanup ];
	$run_path = __DIR__ . '/../docs/compatibility/qfl-order-again-run-results.json';
	$safe_write( $run_path, wp_json_encode( $run_artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}
if ( is_link( $state_path ) ) { throw new RuntimeException( 'Refusing to remove symlink state artifact.' ); }
@unlink( $state_path );
echo wp_json_encode( [ 'phase' => 'cleanup', 'cleanup' => $cleanup, 'orders_before_cleanup' => $orders_before_cleanup ], JSON_PRETTY_PRINT ), "\n";
