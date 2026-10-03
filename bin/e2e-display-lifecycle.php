<?php
/**
 * Disposable real-WooCommerce proof for the display/settings lifecycle rows:
 * labels & instructions (inline/tooltip), placeholders & defaults, field
 * layout, price hints, summary modes, cart/checkout/order summary content,
 * WAPF hide-values reference semantics, and admin REST save/reload.
 *
 * Phases:
 *   setup          create fixture product + OPF groups + admin user (records baseline)
 *   rest           admin REST save/reload for group display data + OPF settings
 *   commerce       OPF cart/checkout/order data-level assertions
 *   wapf_setup     create WAPF reference field group on the same product (needs WAPF active)
 *   wapf_layout    switch WAPF layout flags (labels_position/instructions_position)
 *   wapf_commerce  WAPF cart/order assertions incl. hide_cart/hide_checkout/hide_order
 *   cleanup        remove everything the fixture created; verify baseline restored
 *
 * Guarded: requires OPF_DISPLAY_E2E_ALLOW=1 and a /tmp clone.
 */
if ( '1' !== getenv( 'OPF_DISPLAY_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Requires explicitly authorized disposable /tmp WordPress.' );
}

$phase = getenv( 'OPF_DISPLAY_E2E_PHASE' ) ?: 'setup';
$JSON  = '/tmp/opf-display-state.json';
$state = get_option( 'opf_display_e2e_state', [] );

function display_check( string $label, bool $ok ): void {
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL ' . $label );
	}
	echo "ok $label\n";
}

function display_counts(): array {
	return [
		'products'     => (int) wp_count_posts( 'product' )->publish,
		'opf_groups'   => (int) wp_count_posts( 'opf_field_group' )->publish,
		'wapf_posts'   => array_sum( array_map( 'intval', (array) wp_count_posts( 'wapf_product' ) ) ),
		'users'        => count( get_users( [ 'fields' => 'ID' ] ) ),
		'wc_orders'    => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ),
		'attachments'  => (int) wp_count_posts( 'attachment' )->inherit,
	];
}

/** OPF group data exercised by every display row. */
function display_opf_group_fields(): array {
	return [
		[ 'id' => 'engraving', 'type' => 'text', 'label' => 'Engraving', 'description' => 'Keep it short', 'description_presentation' => 'tooltip', 'placeholder' => 'Your name', 'default' => 'Ada', 'width' => 50, 'required' => true ],
		[ 'id' => 'note', 'type' => 'text', 'label' => 'Note', 'description' => 'Inline note', 'description_presentation' => 'inline', 'placeholder' => 'Note here', 'width' => 50 ],
		[ 'id' => 'contact', 'type' => 'email', 'label' => 'Contact', 'placeholder' => 'Email', 'default' => 'ada@example.test' ],
		[ 'id' => 'qty_units', 'type' => 'number', 'label' => 'Units', 'placeholder' => '0', 'default' => '0' ],
		[ 'id' => 'details', 'type' => 'textarea', 'label' => 'Details', 'placeholder' => 'Details', 'default' => "line one\nline two" ],
		[ 'id' => 'site', 'type' => 'url', 'label' => 'Website', 'default' => 'https://example.test' ],
		[ 'id' => 'priced', 'type' => 'text', 'label' => 'Priced field', 'pricing' => [ 'type' => 'fixed', 'amount' => 5, 'per_unit' => true ] ],
		[ 'id' => 'size', 'type' => 'select', 'label' => 'Size', 'choices' => [
			[ 'slug' => 'small', 'label' => 'Small', 'selected' => true ],
			[ 'slug' => 'large', 'label' => 'Large', 'pricing' => [ 'type' => 'fixed', 'amount' => 10, 'per_unit' => true ] ],
		] ],
		[ 'id' => 'gift', 'type' => 'checkbox', 'label' => 'Extras', 'choices' => [
			[ 'slug' => 'gift', 'label' => 'Gift wrap', 'selected' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => true ] ],
		] ],
		[ 'id' => 'internal', 'type' => 'text', 'label' => 'Internal note', 'default' => 'secret-ref',
			'hide_cart' => true, 'hide_checkout' => true, 'hide_order' => true ],
		[ 'id' => 'enabled', 'type' => 'toggle', 'label' => 'Enabled toggle', 'message' => 'Enable it', 'default' => '1' ],
		[ 'id' => 'finish', 'type' => 'radio', 'label' => 'Finish', 'choices' => [
			[ 'slug' => 'blue', 'label' => 'Blue', 'selected' => true ],
			[ 'slug' => 'red', 'label' => 'Red' ],
			[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 3, 'per_unit' => true ] ],
		] ],
		[ 'id' => 'info', 'type' => 'paragraph', 'label' => 'Info', 'content' => 'Some <strong>info</strong>', 'content_format' => 'html' ],
	];
}

/** Submitted selection values shared by classic and Store API paths. */
function display_opf_values(): array {
	return [
		'engraving' => 'Ada', 'note' => 'Hello', 'contact' => 'ada@example.test',
		'qty_units' => '2', 'details' => 'Rush it', 'site' => 'https://example.test',
		'priced' => 'yes', 'size' => 'large', 'gift' => [ 'gift' ],
		'internal' => 'secret-ref', 'enabled' => '1', 'finish' => 'gold',
	];
}

/** WAPF Extended field-group model array (FieldGroup::to_array() shape). */
function display_wapf_group_array( string $gid, array $layout ): array {
	$field = static function ( array $f ): array {
		return array_merge( [
			'description' => '', 'required' => false, 'class' => '', 'width' => 100,
			'subtype' => '', 'options' => [], 'conditionals' => [], 'clone' => [ 'enabled' => false ],
			'parent_clone' => [], 'pricing' => [ 'type' => 'none', 'amount' => '', 'enabled' => false ],
		], $f );
	};
	return [
		'id'          => $gid,
		'type'        => 'product',
		'layout'      => $layout,
		'variables'   => [],
		'rule_groups' => [],
		'fields'      => [
			$field( [ 'id' => 'engraving', 'label' => 'Engraving', 'type' => 'text', 'required' => true, 'width' => 50,
				'description' => 'Keep it short', 'options' => [ 'default' => 'Ada', 'placeholder' => 'Your name' ] ] ),
			$field( [ 'id' => 'internal', 'label' => 'Internal note', 'type' => 'text',
				'options' => [ 'default' => 'secret-ref', 'hide_cart' => true, 'hide_checkout' => true, 'hide_order' => true ] ] ),
			$field( [ 'id' => 'units', 'label' => 'Units', 'type' => 'text',
				'pricing' => [ 'type' => 'fixed', 'amount' => 5, 'enabled' => true ] ] ),
			$field( [ 'id' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => [ 'choices' => [
				[ 'slug' => 'small', 'label' => 'Small', 'selected' => true ],
				[ 'slug' => 'large', 'label' => 'Large', 'selected' => false, 'pricing_type' => 'fixed', 'pricing_amount' => 10 ],
			] ] ] ),
			$field( [ 'id' => 'gift', 'label' => 'Extras', 'type' => 'checkboxes', 'options' => [ 'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap', 'selected' => true, 'pricing_type' => 'fixed', 'pricing_amount' => 2 ],
			] ] ] ),
			$field( [ 'id' => 'enabled', 'label' => 'Enabled toggle', 'type' => 'true-false',
				'options' => [ 'default' => 'checked', 'message' => 'Enable it' ] ] ),
			$field( [ 'id' => 'finish', 'label' => 'Finish', 'type' => 'radio', 'options' => [ 'choices' => [
				[ 'slug' => 'blue', 'label' => 'Blue', 'selected' => true ],
				[ 'slug' => 'red', 'label' => 'Red', 'selected' => false ],
				[ 'slug' => 'gold', 'label' => 'Gold', 'selected' => false, 'pricing_type' => 'fixed', 'pricing_amount' => 3 ],
			] ] ] ),
		],
	];
}

function display_wapf_values(): array {
	return [
		'field_engraving' => 'Ada',
		'field_internal'  => 'secret-ref',
		'field_units'     => 'yes',
		'field_size'      => 'large',
		'field_gift'      => [ 'gift' ],
		'field_enabled'   => '1',
		'field_finish'    => 'gold',
	];
}

/* ---------------------------------------------------------------- setup */
if ( 'setup' === $phase ) {
	display_check( 'no pre-existing fixture', ! $state );
	$state['baseline'] = display_counts();
	// Exact option state (sentinel distinguishes "absent" from "empty").
	$absent = "\0OPF_E2E_ABSENT\0";
	foreach ( [
		'opf_price_summary_mode', 'opf_show_price_hints',
		'wapf_pricing_summary', 'wapf_show_pricing_hints', 'wapf_hint_format',
		'wapf_settings_show_in_cart', 'wapf_settings_show_in_checkout', 'wapf_settings_show_in_mini_cart',
		'wapf_date_format', 'wapf_datepicker', 'wapf_upload_ajax',
	] as $opt ) {
		$state['baseline_options'][ $opt ] = get_option( $opt, $absent );
	}
	$state['baseline_plugins'] = (array) get_option( 'active_plugins', [] );

	$product = new WC_Product_Simple();
	$product->set_name( 'OPF display lifecycle fixture' );
	$product->set_slug( 'opf-display-lifecycle' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$pid = $product->save();

	$gid = OPF\Service\FieldGroups::save( 0, [
		'fields'      => display_opf_group_fields(),
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
	], [ 'title' => 'OPF display lifecycle', 'status' => 'publish' ] );

	// Second group proves labels_position=below.
	$gid2 = OPF\Service\FieldGroups::save( 0, [
		'labels_position' => 'below',
		'fields'          => [ [ 'id' => 'lowfield', 'type' => 'text', 'label' => 'Below label field', 'description' => 'Desc below' ] ],
		'rule_groups'     => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
	], [ 'title' => 'OPF display below-labels', 'status' => 'publish' ] );

	$uid = wp_create_user( 'opf_display_e2e', wp_generate_password( 32 ), 'display@example.invalid' );
	display_check( 'fixture administrator created', ! is_wp_error( $uid ) );
	( new WP_User( $uid ) )->set_role( 'administrator' );

	$state = array_merge( $state, [
		'product' => $pid, 'group' => $gid, 'group_below' => $gid2,
		'user' => $uid, 'orders' => [], 'wapf_orders' => [],
	] );
	update_option( 'opf_display_e2e_state', $state );
	update_option( 'opf_price_summary_mode', 'three' );
	update_option( 'opf_show_price_hints', 'yes' );
	file_put_contents( $JSON, wp_json_encode( $state ) );
	echo "ok fixtures created product=$pid group=$gid below=$gid2\n";
	return;
}

display_check( 'fixture exists', ! empty( $state['group'] ) );

/* ----------------------------------------------------------------- rest */
if ( 'rest' === $phase ) {
	wp_set_current_user( (int) $state['user'] );
	do_action( 'rest_api_init' );

	// --- group save/reload via opf/v1 ------------------------------------
	$data = [
		'fields' => [
			[ 'id' => 'rest_tt', 'type' => 'text', 'label' => 'Tip field', 'description' => 'Help me', 'description_presentation' => 'tooltip', 'placeholder' => 'Ph', 'default' => 'Dv', 'width' => 33 ],
			[ 'id' => 'rest_mail', 'type' => 'email', 'label' => 'Mail', 'default' => 'x@y.z', 'placeholder' => 'Mail' ],
			[ 'id' => 'rest_num', 'type' => 'number', 'label' => 'Num', 'default' => '7' ],
			[ 'id' => 'rest_area', 'type' => 'textarea', 'label' => 'Area', 'default' => "a\nb" ],
			[ 'id' => 'rest_choice', 'type' => 'select', 'label' => 'Pick', 'choices' => [
				[ 'slug' => 'one', 'label' => 'One', 'selected' => true ],
				[ 'slug' => 'two', 'label' => 'Two', 'pricing' => [ 'type' => 'fixed', 'amount' => 4 ] ],
			] ],
		],
		'labels_position' => 'below',
		'rule_groups'     => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $state['product'] ] ] ] ] ],
	];
	$req = new WP_REST_Request( 'POST', '/opf/v1/groups' );
	$req->set_param( 'title', 'OPF display REST fixture' );
	$req->set_param( 'data', $data );
	$resp = rest_get_server()->dispatch( $req );
	display_check( 'REST POST /opf/v1/groups status 200', 200 === $resp->get_status() );
	$saved = $resp->get_data();
	$rest_gid = (int) $saved['id'];
	display_check( 'REST returned group id', $rest_gid > 0 );
	$by_id = [];
	foreach ( $saved['data']['fields'] as $f ) {
		$by_id[ $f['id'] ] = $f;
	}
	display_check( 'REST save kept description_presentation=tooltip', 'tooltip' === ( $by_id['rest_tt']['description_presentation'] ?? '' ) );
	display_check( 'REST save kept email default', 'x@y.z' === ( $by_id['rest_mail']['default'] ?? '' ) );
	display_check( 'REST save kept number default', '7' === ( $by_id['rest_num']['default'] ?? '' ) );
	display_check( 'REST save kept textarea default', "a\nb" === ( $by_id['rest_area']['default'] ?? '' ) );
	display_check( 'REST save kept labels_position=below', 'below' === ( $saved['data']['labels_position'] ?? '' ) );
	display_check( 'REST save kept width 33 clamped>=25', 33 === ( $by_id['rest_tt']['width'] ?? 0 ) );
	display_check( 'REST save kept choice selected+pricing', ! empty( $by_id['rest_choice']['choices'][0]['selected'] ) && 10 > ( $by_id['rest_choice']['choices'][1]['pricing']['amount'] ?? 99 ) );

	// reload through GET /opf/v1/groups
	$req = new WP_REST_Request( 'GET', '/opf/v1/groups' );
	$list = rest_get_server()->dispatch( $req )->get_data();
	$reloaded = null;
	foreach ( $list as $entry ) {
		if ( (int) $entry['id'] === $rest_gid ) {
			$reloaded = $entry;
		}
	}
	display_check( 'REST GET reload finds saved group', null !== $reloaded );
	$rf = [];
	foreach ( $reloaded['data']['fields'] as $f ) {
		$rf[ $f['id'] ] = $f;
	}
	display_check( 'REST reload persists tooltip presentation', 'tooltip' === ( $rf['rest_tt']['description_presentation'] ?? '' ) );
	display_check( 'REST reload persists scalar defaults', 'x@y.z' === ( $rf['rest_mail']['default'] ?? '' ) && '7' === ( $rf['rest_num']['default'] ?? '' ) );

	// permission denied for anonymous
	wp_set_current_user( 0 );
	$req = new WP_REST_Request( 'GET', '/opf/v1/groups' );
	display_check( 'REST groups require manage_woocommerce', 401 === rest_get_server()->dispatch( $req )->get_status() );
	wp_set_current_user( (int) $state['user'] );

	// --- WooCommerce settings save/reload --------------------------------
	$set = static function ( string $id, $value ): int {
		$r = new WP_REST_Request( 'POST', '/wc/v3/settings/products/' . $id );
		$r->set_param( 'value', $value );
		return rest_get_server()->dispatch( $r )->get_status();
	};
	$get = static function ( string $id ) {
		$r = new WP_REST_Request( 'GET', '/wc/v3/settings/products/' . $id );
		$d = rest_get_server()->dispatch( $r )->get_data();
		return $d['value'] ?? null;
	};
	display_check( 'settings REST save summary grand', 200 === $set( 'opf_price_summary_mode', 'grand' ) );
	display_check( 'settings REST reload reads grand', 'grand' === $get( 'opf_price_summary_mode' ) );
	display_check( 'settings save maps to option', 'grand' === get_option( 'opf_price_summary_mode' ) );
	display_check( 'settings REST save hints off', 200 === $set( 'opf_show_price_hints', 'no' ) );
	display_check( 'settings REST reload hints off', 'no' === $get( 'opf_show_price_hints' ) );
	display_check( 'settings save summary hidden', 200 === $set( 'opf_price_summary_mode', 'hidden' ) );
	display_check( 'settings reload reads hidden', 'hidden' === $get( 'opf_price_summary_mode' ) );
	display_check( 'settings save summary three', 200 === $set( 'opf_price_summary_mode', 'three' ) );
	display_check( 'settings save hints on', 200 === $set( 'opf_show_price_hints', 'yes' ) );
	display_check( 'settings restored to fixture state', 'three' === get_option( 'opf_price_summary_mode' ) && 'yes' === get_option( 'opf_show_price_hints' ) );

	wp_delete_post( $rest_gid, true );
	$state['rest_proven'] = true;
	update_option( 'opf_display_e2e_state', $state );
	echo "ok admin REST save/reload\n";
	return;
}

/* ------------------------------------------------------------- commerce */
if ( 'commerce' === $phase || 'order' === $phase ) {
	$gid = (string) $state['group'];
	if ( ! WC()->cart ) {
		wc_load_cart();
	}
	add_filter( 'pre_wp_mail', '__return_true' );
	$cart = WC()->cart;
	$cart->empty_cart();

	// Classic form path.
	$_POST['opf'] = [ $gid => display_opf_values() ];
	wc_clear_notices();
	$key = $cart->add_to_cart( (int) $state['product'], 1 );
	unset( $_POST['opf'] );
	display_check( 'classic cart accepts selections', (bool) $key );
	$line = $cart->get_cart_item( $key );
	display_check( 'cart line stores field values', isset( $line['opf_fields'][ $gid ] ) );
	$item_data = apply_filters( 'woocommerce_get_item_data', [], $line );
	$pairs = [];
	foreach ( $item_data as $row ) {
		$pairs[ $row['name'] ?? $row['key'] ?? '' ] = $row['value'] ?? '';
	}
	display_check( 'cart item_data shows Engraving=Ada', 'Ada' === ( $pairs['Engraving'] ?? '' ) );
	display_check( 'cart item_data shows choice label not slug', 'Large' === ( $pairs['Size'] ?? '' ) && 'Gift wrap' === ( $pairs['Extras'] ?? '' ) && 'Gold' === ( $pairs['Finish'] ?? '' ) );
	display_check( 'cart item_data shows toggle Yes', 'Yes' === ( $pairs['Enabled toggle'] ?? '' ) );
	display_check( 'cart item_data carries scalar defaults entered', 'Rush it' === ( $pairs['Details'] ?? '' ) && 'ada@example.test' === ( $pairs['Contact'] ?? '' ) );
	$flat = wp_json_encode( $pairs );
	display_check( 'OPF cart summary has no price hint markup (documented gap vs WAPF)', false === strpos( (string) $flat, 'pricing-hint' ) );

	// hide_* flags: values stay stored+priced; only surface display is
	// suppressed. CLI has no template context, so simulate the surfaces the
	// same way WAPF detects them (Store API rest_route, Ajax mini-cart).
	display_check( 'cart line stores hidden field value', 'secret-ref' === ( $line['opf_fields'][ $gid ]['internal'] ?? '' ) );
	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}
	$GLOBALS['wp']->query_vars['rest_route'] = '/wc/store/v1/cart/items';
	$names = array_map( static function ( $r ) { return $r['name'] ?? $r['key'] ?? ''; }, apply_filters( 'woocommerce_get_item_data', [], $line ) );
	display_check( 'OPF Store-API cart hides hide_cart field', ! in_array( 'Internal note', $names, true ) );
	display_check( 'OPF Store-API cart shows other fields', in_array( 'Engraving', $names, true ) && in_array( 'Finish', $names, true ) );
	$GLOBALS['wp']->query_vars['rest_route'] = '/wc/store/v1/checkout/';
	$names = array_map( static function ( $r ) { return $r['name'] ?? $r['key'] ?? ''; }, apply_filters( 'woocommerce_get_item_data', [], $line ) );
	display_check( 'OPF Store-API checkout hides hide_checkout field', ! in_array( 'Internal note', $names, true ) );
	$GLOBALS['wp']->query_vars['rest_route'] = '/opf/v1/groups';
	$names = array_map( static function ( $r ) { return $r['name'] ?? $r['key'] ?? ''; }, apply_filters( 'woocommerce_get_item_data', [], $line ) );
	display_check( 'OPF unknown surface keeps hidden field visible', in_array( 'Internal note', $names, true ) );
	if ( ! defined( 'DOING_AJAX' ) ) {
		define( 'DOING_AJAX', true );
	}
	unset( $_GET['wc-ajax'] );
	$names = array_map( static function ( $r ) { return $r['name'] ?? $r['key'] ?? ''; }, apply_filters( 'woocommerce_get_item_data', [], $line ) );
	display_check( 'OPF mini-cart Ajax hides hide_cart field', ! in_array( 'Internal note', $names, true ) );
	$_GET['wc-ajax'] = 'update_order_review'; // classic order-review is checkout context, not mini-cart
	$cart->empty_cart();

	// Store API path.
	$dispatch = static function ( string $path, array $params ) {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/' . $path );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}
		return rest_get_server()->dispatch( $request );
	};
	$cart_resp = $dispatch( 'cart/add-item', [ 'id' => $state['product'], 'quantity' => 1, 'opf_fields' => [ $gid => display_opf_values() ] ] );
	display_check( 'Store API cart/add-item succeeds', 200 <= $cart_resp->get_status() && $cart_resp->get_status() < 300 );
	$cart_json = $cart_resp->get_data();
	$item_data_json = $cart_json['items'][0]['item_data'] ?? [];
	display_check( 'Store API cart item_data has labels', count( $item_data_json ) >= 5 );
	$cart->empty_cart();

	if ( 'order' === $phase ) {
		$_POST['opf'] = [ $gid => display_opf_values() ];
		$key2 = $cart->add_to_cart( (int) $state['product'], 1 );
		unset( $_POST['opf'] );
		$order_id = WC()->checkout()->create_order( [ 'payment_method' => 'bacs', 'billing_email' => 'display@example.invalid', 'billing_first_name' => 'D', 'billing_last_name' => 'F', 'billing_address_1' => 'x', 'billing_city' => 'y', 'billing_postcode' => '1', 'billing_country' => 'US' ] );
		display_check( 'checkout creates order', is_int( $order_id ) && $order_id > 0 );
		if ( $order_id ) {
			$state['orders'][] = $order_id;
		}
		$order = $order_id ? wc_get_order( $order_id ) : null;
		display_check( 'order found', $order instanceof WC_Order );
		if ( $order ) {
			$labels = [];
			$raw_meta = [];
			$first_item = null;
			foreach ( $order->get_items() as $item ) {
				$first_item = $item;
				foreach ( $item->get_formatted_meta_data() as $m ) {
					$labels[ $m->key ] = $m->value;
				}
				$raw_meta = $item->get_meta( '_opf_fields' );
			}
			display_check( 'order formatted meta shows labels+values', 'Ada' === ( $labels['Engraving'] ?? '' ) && 'Gold' === ( $labels['Finish'] ?? '' ) && 'Large' === ( $labels['Size'] ?? '' ) );
			display_check( 'order formatted meta hides hide_order field', ! isset( $labels['Internal note'] ) );
			display_check( 'order keeps structured _opf_fields', is_string( $raw_meta ) && false !== strpos( $raw_meta, 'engraving' ) );
			display_check( '_opf_fields retains hidden value', is_string( $raw_meta ) && false !== strpos( $raw_meta, 'secret-ref' ) );
			if ( $first_item instanceof WC_Order_Item_Product ) {
				$restored = apply_filters( 'woocommerce_order_again_cart_item_data', [], $first_item, $order );
				display_check( 'order-again restores hidden field value', false !== strpos( (string) wp_json_encode( $restored ), 'secret-ref' ) );
			}
			$state['opf_order_url'] = $order->get_checkout_order_received_url();
			$state['opf_order_id']  = $order_id;
			update_option( 'opf_display_e2e_state', $state );
			file_put_contents( $JSON, wp_json_encode( $state ) );
		}
		$cart->empty_cart();
	}
	echo "ok commerce lifecycle\n";
	return;
}

/* ------------------------------------------------------------ wapf side */
if ( 'wapf_setup' === $phase || 'wapf_layout' === $phase ) {
	display_check( 'WAPF Extended active', class_exists( 'SW_WAPF_PRO\Includes\Models\FieldGroup' ) );
	$layout = [
		'labels_position'       => getenv( 'OPF_WAPF_LABELS' ) ?: 'above',
		'instructions_position' => getenv( 'OPF_WAPF_INSTRUCTIONS' ) ?: 'field',
		'mark_required'         => true,
		'enable_gallery_images' => false,
		'gallery_images'        => [],
	];
	$existing = get_post_meta( (int) $state['product'], '_wapf_fieldgroup', true );
	if ( 'wapf_layout' === $phase ) {
		display_check( 'wapf fixture exists for layout switch', is_string( $existing ) && '' !== $existing );
		$fg = SW_WAPF_PRO\Includes\Classes\Field_Groups::process_data( $existing );
		$fg->layout = array_merge( (array) $fg->layout, $layout );
		update_post_meta( (int) $state['product'], '_wapf_fieldgroup', SW_WAPF_PRO\Includes\Classes\Helper::wp_slash( $fg->to_array() ) );
		echo "ok wapf layout -> {$layout['labels_position']}/{$layout['instructions_position']}\n";
		return;
	}
	// Product-level groups carry the p_<product_id> id convention; it is what
	// the frontend renders into the wapf_field_groups hidden input and what
	// Field_Groups::get_by_id resolves back to the product meta.
	$arr = display_wapf_group_array( 'p_' . (int) $state['product'], $layout );
	$fg  = new SW_WAPF_PRO\Includes\Models\FieldGroup();
	$fg->from_array( $arr );
	update_post_meta( (int) $state['product'], '_wapf_fieldgroup', SW_WAPF_PRO\Includes\Classes\Helper::wp_slash( $fg->to_array() ) );

	$product = wc_get_product( (int) $state['product'] );
	$groups  = SW_WAPF_PRO\Includes\Classes\Field_Groups::get_field_groups_of_product( $product );
	display_check( 'WAPF sees local field group', count( $groups ) >= 1 );
	$ids = array_map( static function ( $f ) { return $f->id; }, $groups[0]->fields );
	foreach ( [ 'engraving', 'internal', 'size', 'gift', 'finish' ] as $want ) {
		display_check( 'WAPF field present: ' . $want, in_array( $want, $ids, true ) );
	}
	$state['wapf_ready'] = true;
	update_option( 'opf_display_e2e_state', $state );
	echo "ok wapf fixture\n";
	return;
}

if ( 'wapf_commerce' === $phase ) {
	display_check( 'WAPF Extended active', class_exists( 'SW_WAPF_PRO\Includes\Classes\Cart' ) );
	if ( ! WC()->cart ) {
		wc_load_cart();
	}
	add_filter( 'pre_wp_mail', '__return_true' );
	$cart = WC()->cart;
	$cart->empty_cart();

	$_REQUEST['wapf_field_groups'] = '';
	$groups = SW_WAPF_PRO\Includes\Classes\Field_Groups::get_field_groups_of_product( wc_get_product( (int) $state['product'] ) );
	$gids = [];
	foreach ( $groups as $g ) {
		$gids[] = $g->id;
	}
	$_REQUEST['wapf_field_groups'] = implode( ',', $gids );
	$_REQUEST['wapf'] = display_wapf_values();
	wc_clear_notices();
	$key = $cart->add_to_cart( (int) $state['product'], 1 );
	display_check( 'WAPF cart accepts selections', (bool) $key );
	$line = $cart->get_cart_item( $key );
	display_check( 'WAPF cart line stores field data', ! empty( $line['wapf'] ) );
	$hidden = [];
	foreach ( (array) $line['wapf'] as $f ) {
		if ( 'internal' === $f['id'] ) {
			$hidden = $f;
		}
	}
	display_check( 'WAPF hide flags stored on cart field', ! empty( $hidden ) && ! empty( $hidden['hide_cart'] ) && ! empty( $hidden['hide_checkout'] ) && ! empty( $hidden['hide_order'] ) );

	// Cart context (Store API cart route) -> hide_cart field excluded.
	// WAPF detects the surface via strpos($rest_route, '/cart/') so the
	// simulated route must carry a trailing-slash segment.
	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}
	$GLOBALS['wp']->query_vars['rest_route'] = '/wc/store/v1/cart/items';
	$item_data = apply_filters( 'woocommerce_get_item_data', [], $line );
	$names = array_map( static function ( $r ) { return $r['key'] ?? $r['name'] ?? ''; }, $item_data );
	display_check( 'WAPF cart item_data shows visible fields', in_array( 'Engraving', $names, true ) && in_array( 'Finish', $names, true ) );
	display_check( 'WAPF cart item_data hides hide_cart field', ! in_array( 'Internal note', $names, true ) );
	$with_hint = wp_json_encode( $item_data );
	display_check( 'WAPF cart display embeds pricing hint markup', false !== strpos( (string) $with_hint, 'wapf-pricing-hint' ) );

	// Checkout context -> hide_checkout field excluded.
	$GLOBALS['wp']->query_vars['rest_route'] = '/wc/store/v1/checkout/';
	$item_data = apply_filters( 'woocommerce_get_item_data', [], $line );
	$names = array_map( static function ( $r ) { return $r['key'] ?? $r['name'] ?? ''; }, $item_data );
	display_check( 'WAPF checkout item_data hides hide_checkout field', ! in_array( 'Internal note', $names, true ) );
	display_check( 'WAPF checkout item_data keeps visible fields', in_array( 'Engraving', $names, true ) );

	// Global "show in cart" switch off -> nothing shown.
	$GLOBALS['wp']->query_vars['rest_route'] = '/wc/store/v1/cart/items';
	update_option( 'wapf_settings_show_in_cart', 'no' );
	$item_data = apply_filters( 'woocommerce_get_item_data', [], $line );
	display_check( 'WAPF show_in_cart=no suppresses all item_data', 0 === count( $item_data ) );

	// Order lifecycle: plain meta for every field, formatted meta hides
	// hide_order on customer-facing surfaces, structured meta retained.
	$order_id = WC()->checkout()->create_order( [ 'payment_method' => 'bacs', 'billing_email' => 'display@example.invalid', 'billing_first_name' => 'D', 'billing_last_name' => 'F', 'billing_address_1' => 'x', 'billing_city' => 'y', 'billing_postcode' => '1', 'billing_country' => 'US' ] );
	display_check( 'WAPF checkout creates order', is_int( $order_id ) && $order_id > 0 );
	if ( $order_id ) {
		$state['wapf_orders'][] = $order_id;
	}
	$order = $order_id ? wc_get_order( $order_id ) : null;
	if ( $order ) {
		$formatted_keys = [];
		$raw_internal = null;
		$structured = null;
		foreach ( $order->get_items() as $item ) {
			foreach ( $item->get_formatted_meta_data() as $m ) {
				$formatted_keys[] = $m->key;
			}
			$raw_internal = $item->get_meta( 'Internal note' );
			$structured   = $item->get_meta( '_wapf_meta' );
		}
		display_check( 'WAPF order raw meta keeps hidden value', 'secret-ref' === $raw_internal );
		display_check( 'WAPF formatted order meta hides hide_order field (frontend)', ! in_array( 'Internal note', $formatted_keys, true ) );
		display_check( 'WAPF order formatted meta keeps visible fields', in_array( 'Engraving', $formatted_keys, true ) && in_array( 'Finish', $formatted_keys, true ) );
		display_check( 'WAPF order keeps structured _wapf_meta', is_array( $structured ) && ! empty( $structured['fields'] ) );
		$hide_flag = false;
		foreach ( (array) ( $structured['settings'] ?? [] ) as $settings ) {
			foreach ( (array) $settings as $s ) {
				if ( ! empty( $s['hide'] ) ) {
					$hide_flag = true;
				}
			}
		}
		display_check( 'WAPF _wapf_meta settings carry hide flag', $hide_flag );
		$state['wapf_order_url'] = $order->get_checkout_order_received_url();
		$state['wapf_order_id']  = $order_id;
		update_option( 'opf_display_e2e_state', $state );
		file_put_contents( $JSON, wp_json_encode( $state ) );
	}
	unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'] );
	$cart->empty_cart();
	echo "ok wapf commerce\n";
	return;
}

/* -------------------------------------------------------------- cleanup */
if ( 'cleanup' === $phase ) {
	// Remove orders touching the fixture product.
	foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $candidate ) {
		foreach ( $candidate->get_items() as $item ) {
			if ( (int) $state['product'] === $item->get_product_id() ) {
				$candidate->delete( true );
				break;
			}
		}
	}
	foreach ( [ 'group', 'group_below', 'product' ] as $key ) {
		if ( ! empty( $state[ $key ] ) ) {
			delete_post_meta( (int) $state[ $key ], '_wapf_fieldgroup' );
			wp_delete_post( (int) $state[ $key ], true );
		}
	}
	if ( ! empty( $state['user'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $state['user'] );
	}
	// Restore every option the fixture (or the WAPF reference run) touched to
	// its exact pre-fixture state; sentinel value means "was absent".
	$absent = "\0OPF_E2E_ABSENT\0";
	foreach ( (array) ( $state['baseline_options'] ?? [] ) as $opt => $val ) {
		if ( $absent === $val ) {
			delete_option( $opt );
		} else {
			update_option( $opt, $val );
		}
	}
	delete_option( 'opf_display_e2e_state' );
	if ( file_exists( $JSON ) ) {
		unlink( $JSON );
	}

	$now = display_counts();
	$base = $state['baseline'] ?? [];
	foreach ( [ 'products', 'opf_groups', 'wapf_posts', 'users', 'wc_orders', 'attachments' ] as $key ) {
		display_check( "baseline restored: $key", isset( $base[ $key ] ) ? $now[ $key ] === $base[ $key ] : true );
	}
	$plugins_now = (array) get_option( 'active_plugins', [] );
	$plugins_base = (array) ( $state['baseline_plugins'] ?? $plugins_now );
	display_check( 'baseline restored: active_plugins', $plugins_now === $plugins_base );
	foreach ( (array) ( $state['baseline_options'] ?? [] ) as $opt => $val ) {
		$now_val = get_option( $opt, $absent );
		display_check( "baseline restored: option $opt", $now_val === $val );
	}
	echo "ok fixture cleanup\n";
	return;
}

throw new RuntimeException( 'Unknown phase ' . $phase );
