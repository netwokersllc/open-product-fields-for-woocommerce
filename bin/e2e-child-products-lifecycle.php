<?php
/** Isolated linked child-products lifecycle fixture — disposable clone only.
 *
 * Phases (OPF_CHILD_LIFECYCLE_PHASE):
 *  - setup       create parent/children/field group/checkout, persist state
 *  - verify      assert persisted order carries child-line meta + totals
 *  - order-again simulate Woo order-again and assert child parent-key remap
 *  - cleanup     remove every created object and restore touched options
 */
if ( '1' !== getenv( 'OPF_CHILD_LIFECYCLE_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/opf-image-child-wp' ) ) {
	throw new RuntimeException( 'Explicit isolated clone authorization required.' );
}
function lp_assert( string $label, bool $pass ): void {
	if ( ! $pass ) { throw new RuntimeException( $label ); }
	echo "ok $label\n";
}
$dir = getenv( 'OPF_CHILD_ARTIFACT_DIR' ) ?: '/tmp/opf-lane-child-evidence';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}
$phase = getenv( 'OPF_CHILD_LIFECYCLE_PHASE' ) ?: 'setup';
$state = get_option( 'opf_child_lifecycle_state', [] );

/** Solid-color 800x800 PNG attachment so children have distinct gallery images. */
$lp_make_image = function ( string $hex, string $name ): int {
	$img = imagecreatetruecolor( 800, 800 );
	[ $r, $g, $b ] = array_map( 'hexdec', [ substr( $hex, 1, 2 ), substr( $hex, 3, 2 ), substr( $hex, 5, 2 ) ] );
	imagefill( $img, 0, 0, imagecolorallocate( $img, $r, $g, $b ) );
	$tmp = tempnam( sys_get_temp_dir(), 'opfimg' ) . '.png';
	imagepng( $img, $tmp );
	imagedestroy( $img );
	$upload = wp_upload_bits( $name . '.png', null, file_get_contents( $tmp ) );
	unlink( $tmp );
	lp_assert( $name . ' upload ok', empty( $upload['error'] ) );
	$att_id = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit' ], $upload['file'] );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $upload['file'] ) );
	return $att_id;
};
$lp_make_product = function ( string $name, string $slug, string $price, int $thumb ): int {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_slug( $slug );
	$p->set_status( 'publish' );
	$p->set_regular_price( $price );
	$p->set_virtual( true );
	$p->set_image_id( $thumb );
	return $p->save();
};

if ( 'setup' === $phase ) {
	lp_assert( 'fresh isolated fixtures', empty( $state ) );
	$img_parent = $lp_make_image( '#c0392b', 'opf-lp-parent' );
	$img_alpha  = $lp_make_image( '#2980b9', 'opf-lp-alpha' );
	$img_beta   = $lp_make_image( '#27ae60', 'opf-lp-beta' );
	$parent = $lp_make_product( 'OPF Linked Parent', 'opf-linked-parent', '20', $img_parent );
	$alpha  = $lp_make_product( 'OPF Child Alpha', 'opf-child-alpha', '8', $img_alpha );
	$beta   = $lp_make_product( 'OPF Child Beta', 'opf-child-beta', '12', $img_beta );

	$fields = [
		[
			'id' => 'linked_cards', 'label' => 'Included products', 'type' => 'products', 'subtype' => 'card',
			'product_selection' => 'manual', 'qty_method' => 'parent', 'image_zoom' => true,
			'incl_img' => true, 'incl_desc' => false, 'slot_1' => 'price', 'items_per_row' => 2,
			'choices' => [
				[ 'product_id' => $alpha, 'slug' => 'child-alpha', 'pricing_type' => 'fixed' ],
				[ 'product_id' => $beta, 'slug' => 'child-beta', 'pricing_type' => 'none' ],
			],
		],
		[
			'id' => 'linked_qty', 'label' => 'Extra units', 'type' => 'products', 'subtype' => 'card-qty',
			'product_selection' => 'manual', 'display' => 'plus_min',
			'min_choices' => 0, 'max_choices' => 10,
			'incl_img' => true, 'incl_desc' => false, 'slot_1' => 'price', 'items_per_row' => 2,
			'choices' => [
				[ 'product_id' => $alpha, 'slug' => 'child-alpha', 'quantity' => [ 'min' => 0, 'max' => 5 ] ],
				[ 'product_id' => $beta, 'slug' => 'child-beta', 'quantity' => [ 'min' => 0, 'max' => 5 ] ],
			],
		],
	];
	$gid = OPF\Service\FieldGroups::save( 0, [
		'fields'      => $fields,
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $parent ] ] ] ] ],
	], [ 'title' => 'Linked products lifecycle', 'status' => 'publish' ] );
	lp_assert( 'field group persisted', $gid > 0 );
	$group = OPF\Service\FieldGroups::group_from_post( get_post( $gid ) );
	lp_assert( 'group round-trips two products fields', 2 === count( $group->data['fields'] ) && 'products' === $group->data['fields'][0]['type'] );
	lp_assert( 'manual choices keep product ids', $alpha === (int) $group->data['fields'][0]['choices'][0]['product_id'] );

	$checkout = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Linked proof checkout', 'post_name' => 'linked-proof-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	$prev_checkout = get_option( 'woocommerce_checkout_page_id' );
	$prev_bacs     = get_option( 'woocommerce_bacs_settings' );
	update_option( 'woocommerce_checkout_page_id', $checkout );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );

	$state = [
		'parent' => $parent, 'alpha' => $alpha, 'beta' => $beta,
		'images' => [ $img_parent, $img_alpha, $img_beta ],
		'group' => $gid, 'checkout' => $checkout,
		'prev_checkout' => $prev_checkout, 'prev_bacs' => $prev_bacs,
	];
	update_option( 'opf_child_lifecycle_state', $state, false );
	file_put_contents( $dir . '/state.json', wp_json_encode( $state ) );
	echo "SUCCESS linked child-products fixtures\n";
	return;
}

lp_assert( 'fixture state exists', ! empty( $state['group'] ) );

if ( 'verify' === $phase ) {
	$order_id = (int) json_decode( file_get_contents( $dir . '/child-order-id.json' ), true );
	$order = wc_get_order( $order_id );
	lp_assert( 'persisted real order', $order instanceof WC_Order );
	$items = array_values( $order->get_items() );
	lp_assert( 'order has parent + three child lines', 4 === count( $items ) );
	$parent_item = null;
	$children = [];
	foreach ( $items as $item ) {
		if ( $item->get_meta( '_opf_fields' ) ) {
			$parent_item = $item;
		} elseif ( $item->get_meta( '_opf_child' ) ) {
			$children[] = $item;
		}
	}
	lp_assert( 'parent order line carries opf_fields and cart key', $parent_item instanceof WC_Order_Item_Product && '' !== (string) $parent_item->get_meta( '_opf_cart_item_key' ) );
	lp_assert( 'three child lines carry _opf_child meta', 3 === count( $children ) );
	foreach ( $children as $child ) {
		$full = $child->get_meta( '_opf_child_full' );
		lp_assert( 'child ' . $child->get_name() . ' retains full link meta for order-again remap', is_array( $full ) && ! empty( $full['parent'] ) && ! empty( $full['field'] ) );
	}
	$by_qty = [];
	foreach ( $children as $child ) {
		$by_qty[ $child->get_name() ] = [ 'qty' => $child->get_quantity(), 'total' => (float) $child->get_total() ];
	}
	// parent=3 @20; alpha card → 'parent' qty 3 @8; beta card → 'parent' qty 3 free (none); beta qty → 'relative' 6 @12.
	lp_assert( 'alpha card child scaled to parent qty', 3 === $by_qty['OPF Child Alpha']['qty'] && 24.0 === $by_qty['OPF Child Alpha']['total'] );
	lp_assert( 'free card child stays zero-priced', isset( $by_qty['OPF Child Beta'] ) );
	$beta_lines = array_filter( $children, static fn ( $i ) => 'OPF Child Beta' === $i->get_name() );
	lp_assert( 'beta contributes free card line + priced qty line', 2 === count( $beta_lines ) );
	$beta_totals = array_map( static fn ( $i ) => (float) $i->get_total(), $beta_lines );
	sort( $beta_totals );
	lp_assert( 'beta free + priced totals', [ 0.0, 72.0 ] === $beta_totals );
	lp_assert( 'order total 60+24+0+72', 156.0 === (float) $order->get_total() );
	echo "SUCCESS linked child order persistence\n";
	return;
}

if ( 'order-again' === $phase ) {
	$order_id = (int) json_decode( file_get_contents( $dir . '/child-order-id.json' ), true );
	$order = wc_get_order( $order_id );
	if ( ! WC()->cart ) { wc_load_cart(); }
	WC()->cart->empty_cart();
	$restored = [];
	foreach ( $order->get_items() as $item ) {
		$restored[] = [ 'item' => $item, 'data' => apply_filters( 'woocommerce_order_again_cart_item_data', [], $item, $order ) ];
	}
	$parent_data = null;
	$child_count = 0;
	foreach ( $restored as $row ) {
		if ( ! empty( $row['data']['old_cart_item_key'] ) ) { $parent_data = $row['data']; }
		if ( isset( $row['data']['_opf_child'] ) ) { $child_count++; }
	}
	lp_assert( 'parent line restores old cart key', is_array( $parent_data ) && '' !== $parent_data['old_cart_item_key'] );
	lp_assert( 'three children restore child marker', 3 === $child_count );
	// Mirror WC_Cart_Session::order_again() exactly: items are merged into a
	// raw $cart array (no add_to_cart, no totals sweep), then
	// woocommerce_ordered_again remaps children to the new parent key before
	// the cart is committed — that is why orphans are not swept mid-restore.
	$cart = WC()->cart->get_cart();
	foreach ( $restored as $row ) {
		$product_id = $row['item']->get_product_id();
		$variation_id = $row['item']->get_variation_id();
		$cart_item_data = $row['data'];
		if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $row['item']->get_quantity(), $variation_id, [], $cart_item_data ) ) {
			continue;
		}
		$cart_id = WC()->cart->generate_cart_id( $product_id, $variation_id, [], $cart_item_data );
		$product_data = wc_get_product( $variation_id ? $variation_id : $product_id );
		$cart[ $cart_id ] = apply_filters( 'woocommerce_add_order_again_cart_item', array_merge( $cart_item_data, [
			'key'          => $cart_id,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'variation'    => [],
			'quantity'     => $row['item']->get_quantity(),
			'data'         => $product_data,
			'data_hash'    => wc_get_cart_item_data_hash( $product_data ),
		] ), $cart_id );
	}
	do_action_ref_array( 'woocommerce_ordered_again', [ $order->get_id(), $order->get_items(), &$cart ] );
	WC()->cart->cart_contents = $cart;
	WC()->cart->set_session();
	WC()->cart->calculate_totals();
	$contents = WC()->cart->get_cart();
	lp_assert( 'order-again rebuilds parent + three children without duplication', 4 === count( $contents ) );
	$parent_key = null;
	$remapped = 0;
	foreach ( $contents as $key => $cart_item ) {
		if ( ! empty( $cart_item['old_cart_item_key'] ) ) { $parent_key = $key; }
	}
	lp_assert( 'new parent cart key identified', null !== $parent_key );
	foreach ( $contents as $cart_item ) {
		if ( ! empty( $cart_item['_opf_child']['parent'] ) && $cart_item['_opf_child']['parent'] === $parent_key ) { $remapped++; }
	}
	lp_assert( 'all children remapped to new parent key', 3 === $remapped );
	$total = (float) WC()->cart->get_total( 'edit' );
	file_put_contents( $dir . '/order-again-cart.json', wp_json_encode( [ 'lines' => count( $contents ), 'total' => $total, 'parent_key' => $parent_key ], JSON_PRETTY_PRINT ) );
	lp_assert( 'order-again totals match original order', 156.0 === $total );
	WC()->cart->empty_cart();
	echo "SUCCESS linked child order-again remap\n";
	return;
}

if ( 'cleanup' === $phase ) {
	foreach ( json_decode( file_get_contents( $dir . '/child-order-ids.json' ), true ) ?: [] as $oid ) {
		$o = wc_get_order( (int) $oid );
		if ( $o ) { $o->delete( true ); }
	}
	foreach ( [ $state['parent'], $state['alpha'], $state['beta'] ] as $pid ) {
		wp_delete_post( (int) $pid, true );
	}
	foreach ( $state['images'] as $att ) { wp_delete_attachment( (int) $att, true ); }
	wp_delete_post( (int) $state['group'], true );
	wp_delete_post( (int) $state['checkout'], true );
	if ( false !== $state['prev_checkout'] ) { update_option( 'woocommerce_checkout_page_id', $state['prev_checkout'] ); }
	if ( false !== $state['prev_bacs'] ) { update_option( 'woocommerce_bacs_settings', $state['prev_bacs'] ); }
	delete_option( 'opf_child_lifecycle_state' );
	echo "SUCCESS linked child-products cleanup\n";
	return;
}

throw new RuntimeException( 'Unknown phase: ' . $phase );
