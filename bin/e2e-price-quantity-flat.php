<?php
/**
 * WAPF Pro `qt` versus OPF per-unit fixed choice lifecycle proof.
 *
 * Run only in the disposable /tmp/opf-quantity-fee-woo-20261002 site:
 *   OPF_QFL_E2E_ALLOW=1 wp --path=/tmp/opf-quantity-fee-woo-20261002 eval-file bin/e2e-price-quantity-flat.php
 */
if ( realpath( ABSPATH ) !== '/tmp/opf-quantity-fee-woo-20261002' || '1' !== getenv( 'OPF_QFL_E2E_ALLOW' ) ) {
	throw new RuntimeException( 'Refusing to run outside the explicitly enabled disposable WooCommerce clone.' );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$option_names = [ 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop', 'woocommerce_tax_based_on' ];
$old_options = [];
foreach ( $option_names as $name ) {
	$old_options[ $name ] = get_option( $name );
}

$product_ids = [];
$group_ids = [];
$order_ids = [];
$tax_rate_id = 0;
$results = [];

try {
	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	update_option( 'woocommerce_tax_display_shop', 'excl' );
	update_option( 'woocommerce_tax_based_on', 'base' );
	$tax_rate_id = WC_Tax::_insert_tax_rate( [
		'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '8.25',
		'tax_rate_name' => 'QFL temporary 8.25%', 'tax_rate_priority' => 99,
		'tax_rate_compound' => 0, 'tax_rate_shipping' => 0,
		'tax_rate_order' => 1, 'tax_rate_class' => '',
	] );
	$assert( $tax_rate_id > 0, 'Could not create temporary tax rate.' );

	$make_product = static function ( string $name ) use ( &$product_ids ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_virtual( true );
		$product->set_regular_price( '10.00' );
		$product->set_tax_status( 'taxable' );
		$product_id = (int) $product->save();
		$product_ids[] = $product_id;
		return $product_id;
	};
	$rules_for = static function ( int $product_id ): array {
		return [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ];
	};

	$wapf_product = $make_product( 'QFL WAPF Pro qt oracle' );
	$opf_product = $make_product( 'QFL OPF quantity fixed choice' );
	$fee = 0.335;
	$wapf_model = SW_WAPF_PRO\Includes\Classes\Field_Groups::raw_json_to_field_group( [
		'id' => 'p_' . $wapf_product,
		'type' => 'wapf_product',
		'fields' => [ [
			'id' => 'plan', 'type' => 'select', 'label' => 'Plan', 'required' => true,
			'description' => '', 'class' => '', 'width' => 100, 'default' => '',
			'choices' => [ [ 'slug' => 'qtyflat', 'label' => 'Quantity flat fee', 'pricing_type' => 'qt', 'pricing_amount' => $fee ] ],
			'conditionals' => [], 'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ],
		] ],
		'conditions' => [],
		'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ],
		'variables' => [],
	] );
	$assert( $wapf_model && isset( $wapf_model->fields[0] ), 'WAPF fixture model did not parse.' );
	update_post_meta( $wapf_product, '_wapf_fieldgroup', $wapf_model->to_array() );

	$opf_group = OPF\Service\FieldGroups::save( 0, [
		'fields' => [ [ 'id' => 'plan', 'type' => 'select', 'label' => 'Plan', 'required' => true, 'choices' => [
			[ 'slug' => 'qtyflat', 'label' => 'Quantity flat fee', 'pricing' => [ 'type' => 'fixed', 'amount' => $fee, 'per_unit' => true ] ],
		] ] ],
		'rule_groups' => $rules_for( $opf_product ),
	], [ 'title' => 'QFL OPF quantity fixed choice', 'status' => 'publish' ] );
	$group_ids[] = (int) $opf_group;
	$assert( $opf_group > 0, 'Could not save OPF fixture group.' );

	if ( ! WC()->cart ) {
		wc_load_cart();
	}
	$empty = static function (): void {
		WC()->cart->empty_cart( true );
		WC()->session->set( 'cart', [] );
		WC()->session->set( 'cart_totals', null );
		$_POST = [];
		$_REQUEST = [];
		// WAPF's before-calculation callback is once-per-request by design.
		$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	};
	$cart_snapshot = static function ( int $product_id ): array {
		WC()->cart->calculate_totals();
		$lines = [];
		foreach ( WC()->cart->get_cart() as $line ) {
			if ( (int) $line['product_id'] !== $product_id ) {
				continue;
			}
			$lines[] = [
				'quantity' => (int) $line['quantity'],
				'unit_price_raw' => round( (float) $line['data']->get_price( 'edit' ), 4 ),
				'line_subtotal' => round( (float) $line['line_subtotal'], 4 ),
				'line_subtotal_tax' => round( (float) $line['line_subtotal_tax'], 4 ),
				'line_total' => round( (float) $line['line_total'], 4 ),
				'line_tax' => round( (float) $line['line_tax'], 4 ),
				'choice_meta' => isset( $line['opf_fields'] ) ? $line['opf_fields'] : ( $line['wapf'] ?? null ),
			];
		}
		return $lines;
	};
	$add = static function ( string $engine, string $path, int $product_id, int $quantity ) use ( $opf_group, $wapf_product, $opf_product, $empty, $cart_snapshot, $assert ): array {
		$empty();
		if ( 'classic' === $path ) {
			if ( 'wapf' === $engine ) {
				$_POST['wapf_field_groups'] = 'p_' . $wapf_product;
				$_REQUEST['wapf_field_groups'] = 'p_' . $wapf_product;
				$_POST['wapf'] = [ 'field_plan' => 'qtyflat' ];
				$_REQUEST['wapf'] = $_POST['wapf'];
			} else {
				$_POST['opf'] = [ (string) $opf_group => [ 'plan' => 'qtyflat' ] ];
				$_REQUEST['opf'] = $_POST['opf'];
			}
			$key = WC()->cart->add_to_cart( $product_id, $quantity );
			$assert( false !== $key, "$engine classic add-to-cart failed at qty=$quantity." );
		} else {
			$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
			$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
			$request->set_param( 'id', $product_id );
			$request->set_param( 'quantity', $quantity );
			if ( 'wapf' === $engine ) {
				$extra = [ 'wapf_field_groups' => 'p_' . $wapf_product, 'wapf' => [ 'field_plan' => 'qtyflat' ] ];
			} else {
				$extra = [ 'opf_fields' => [ (string) $opf_group => [ 'plan' => 'qtyflat' ] ] ];
			}
			foreach ( $extra as $key => $value ) {
				$request->set_param( $key, $value );
				$_REQUEST[ $key ] = $value;
			}
			$response = rest_get_server()->dispatch( $request );
			$assert( in_array( $response->get_status(), [ 200, 201 ], true ), "$engine Store API add failed at qty=$quantity: " . wp_json_encode( $response->get_data() ) );
		}
		$lines = $cart_snapshot( $product_id );
		$assert( 1 === count( $lines ), "$engine $path did not create exactly one fixture line." );
		$assert( $quantity === $lines[0]['quantity'], "$engine $path cart quantity mismatch." );
		return [ 'status' => 'passed', 'lines' => $lines ];
	};

	foreach ( [ 1, 3 ] as $quantity ) {
		foreach ( [ 'classic', 'store_api' ] as $path ) {
			$results[ "{$path}/wapf/q{$quantity}" ] = $add( 'wapf', $path, $wapf_product, $quantity );
			$results[ "{$path}/opf/q{$quantity}" ] = $add( 'opf', $path, $opf_product, $quantity );
		}
	}

	$make_order = static function ( string $engine, int $product_id, int $quantity ) use ( $add, &$order_ids, $opf_group, $assert, $cart_snapshot, $empty ): array {
		$add( $engine, 'classic', $product_id, $quantity );
		$order_id = WC()->checkout()->create_order( [
			'billing_email' => 'qfl-e2e@example.test', 'billing_first_name' => 'QFL', 'billing_last_name' => 'E2E',
			'payment_method' => 'bacs',
		] );
		$assert( ! is_wp_error( $order_id ) && $order_id > 0, "$engine checkout order creation failed: " . ( is_wp_error( $order_id ) ? $order_id->get_error_message() : 'no id' ) );
		$order_ids[] = (int) $order_id;
		$order = wc_get_order( $order_id );
		$order->set_customer_id( 1 );
		$order->save();
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = [
				'quantity' => (int) $item->get_quantity(),
				'line_total' => round( (float) $item->get_total(), 4 ),
				'line_tax' => round( (float) $item->get_total_tax(), 4 ),
				'engine_meta' => 'opf' === $engine ? $item->get_meta( '_opf_fields', true ) : $item->get_meta( '_wapf_meta', true ),
			];
		}
		$assert( 1 === count( $items ) && $quantity === $items[0]['quantity'], "$engine order item quantity did not persist." );
		$assert( $items[0]['line_total'] > 30, "$engine quantity-scaled fee did not persist to order line: " . wp_json_encode( $items ) );

		// Exercise WooCommerce's own order-again cart population with the
		// transient checkout order's pending status allowed for this clone run.
		// Production orders qualify through a completed status; this avoids
		// firing a payment/status transition or customer email in the fixture.
		wp_set_current_user( 1 );
		$allow_pending = static function ( array $statuses ): array {
			$statuses[] = 'pending';
			return array_values( array_unique( $statuses ) );
		};
		add_filter( 'woocommerce_valid_order_statuses_for_order_again', $allow_pending );
		try {
			$cart_reflection = new ReflectionProperty( WC_Cart::class, 'session' );
			$cart_reflection->setAccessible( true );
			$cart_session = $cart_reflection->getValue( WC()->cart );
			$populate = new ReflectionMethod( $cart_session, 'populate_cart_from_order' );
			$populate->setAccessible( true );
			$again_cart = $populate->invoke( $cart_session, (int) $order_id, [] );
		} finally {
			remove_filter( 'woocommerce_valid_order_statuses_for_order_again', $allow_pending );
		}
		$assert( is_array( $again_cart ) && 1 === count( $again_cart ), "$engine Woo order-again did not restore one cart line: " . wp_json_encode( [ 'cart' => $again_cart, 'status' => $order->get_status(), 'can_order_again' => current_user_can( 'order_again', (int) $order_id ), 'user' => get_current_user_id() ] ) );
		$empty();
		WC()->cart->set_cart_contents( $again_cart );
		$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
		$again_lines = $cart_snapshot( $product_id );
		$assert( 1 === count( $again_lines ) && $quantity === $again_lines[0]['quantity'], "$engine order-again quantity did not persist." );
		$assert( abs( $again_lines[0]['line_subtotal'] - $items[0]['line_total'] ) < 0.0001, "$engine order-again line subtotal differs from its saved order." );
		return [ 'order_id' => (int) $order_id, 'status' => $order->get_status(), 'total' => round( (float) $order->get_total(), 4 ), 'items' => $items, 'order_again' => [ 'status' => 'passed', 'lines' => $again_lines ] ];
	};
	$results['checkout_order/wapf/q3'] = $make_order( 'wapf', $wapf_product, 3 );
	$results['checkout_order/opf/q3'] = $make_order( 'opf', $opf_product, 3 );

	$assert( round( $results['classic/wapf/q1']['lines'][0]['line_subtotal'], 2 ) === round( $results['classic/opf/q1']['lines'][0]['line_subtotal'], 2 ), 'q=1 native and OPF subtotals differ.' );
	$assert( round( $results['classic/wapf/q3']['lines'][0]['line_subtotal'], 2 ) === round( $results['classic/opf/q3']['lines'][0]['line_subtotal'], 2 ), 'q=3 native and OPF subtotals differ.' );
	$assert( round( $results['classic/wapf/q3']['lines'][0]['line_tax'], 2 ) === round( $results['classic/opf/q3']['lines'][0]['line_tax'], 2 ), 'q=3 native and OPF tax differs.' );

	echo wp_json_encode( [ 'runtime' => [ 'woocommerce' => WC()->version, 'wapf' => '3.1.5', 'opf_source' => realpath( WP_PLUGIN_DIR . '/open-product-fields-for-woocommerce' ), 'tax_rate_id' => $tax_rate_id ], 'results' => $results ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
} finally {
	if ( WC()->cart ) {
		WC()->cart->empty_cart( true );
	}
	foreach ( $order_ids as $order_id ) {
		wp_delete_post( $order_id, true );
	}
	foreach ( $group_ids as $group_id ) {
		wp_delete_post( $group_id, true );
	}
	foreach ( $product_ids as $product_id ) {
		wp_delete_post( $product_id, true );
	}
	if ( $tax_rate_id > 0 ) {
		WC_Tax::_delete_tax_rate( $tax_rate_id );
	}
	foreach ( $old_options as $name => $value ) {
		update_option( $name, $value );
	}
}
