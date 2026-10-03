<?php
/**
 * Guarded WooCommerce fixture + server checks for the interaction/product-type
 * parity lane (INTERACTION-REPEAT, INTERACTION-QUANTITY-REPEAT,
 * INTERACTION-IMAGE-CHANGE, INTERACTION-CART-EDIT, PRODUCT-VARIABLE,
 * PRODUCT-SUBSCRIPTION, PRODUCT-BOOKINGS).
 *
 * Phases: setup (create fixture), verify (server-side checks), cleanup.
 * Browser proof runs separately between setup and cleanup.
 *
 * Run: OPF_INTERACT_E2E_ALLOW=1 OPF_INTERACT_PHASE=setup wp --path=<clone> eval-file bin/e2e-interact-lifecycle.php
 */
if ( '1' !== getenv( 'OPF_INTERACT_E2E_ALLOW' ) || 0 !== strpos( (string) realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Requires an authorized disposable /tmp WordPress.' );
}

use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;

$results = &$GLOBALS['ix_results'];
function ix_check( string $label, bool $ok, $detail = null ): void {
	$GLOBALS['ix_results'][] = [ 'label' => $label, 'pass' => (bool) $ok, 'detail' => $detail ];
	echo ( $ok ? 'ok ' : 'FAIL ' ) . $label . ( null !== $detail ? ' :: ' . wp_json_encode( $detail ) : '' ) . "\n";
}

$phase  = getenv( 'OPF_INTERACT_PHASE' ) ?: 'verify';
$dir    = '/tmp/opf-interact-e2e';
$state  = get_option( 'opf_ix_e2e_state', [] );
$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
$mu_file = $mu_dir . '/zz-opf-ix-types.php';
$mu_source = <<<'MU'
<?php
/* Disposable simulation for the interact lane proofs: registers 'subscription'
 * and 'booking' product types plus the minimal WooCommerce Subscriptions surface
 * WAPF Extended 3.1.5 checks for (class_exists('WC_Subscriptions') +
 * WC_Subscriptions_Product::get_price()). Removed by fixture cleanup.
 * mu-plugins load before WooCommerce — product classes must be declared
 * lazily once WC_Product_Simple exists. */
class WC_Subscriptions {}
class WC_Subscriptions_Product { public static function get_price( $p ) { return (float) $p->get_price( 'edit' ); } }
add_action( 'plugins_loaded', static function () {
	if ( class_exists( 'WC_Product_Simple' ) ) {
		eval( 'class OPF_IX_Subscription_Product extends WC_Product_Simple { public function get_type() { return "subscription"; } }' );
		eval( 'class OPF_IX_Booking_Product extends WC_Product_Simple { public function get_type() { return "booking"; } }' );
	}
}, 15 );
add_filter( 'woocommerce_product_class', static function ( $classname, $product_type ) {
	if ( 'subscription' === $product_type ) { return 'OPF_IX_Subscription_Product'; }
	if ( 'booking' === $product_type ) { return 'OPF_IX_Booking_Product'; }
	return $classname;
}, 10, 2 );
MU;

if ( 'setup' === $phase ) {
	ix_check( 'no existing interact fixture', ! $state );
	if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }

	// --- product type simulation mu-plugin (subscription + booking types,
	// plus the WC_Subscriptions class surface WAPF probes for).
	$mu_baseline = is_dir( $mu_dir ) ? count( glob( $mu_dir . '/*.php' ) ) : 0;
	if ( ! is_dir( $mu_dir ) ) { mkdir( $mu_dir, 0755, true ); }
	file_put_contents( $mu_file, $mu_source );
	ix_check( 'mu type-simulation installed', file_exists( $mu_file ) );

	$mk = static function ( string $name, string $slug, string $price ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_slug( $slug );
		$p->set_status( 'publish' );
		$p->set_regular_price( $price );
		$p->set_catalog_visibility( 'visible' );
		return (int) $p->save();
	};

	// Attachment images for the gallery-swap proof (GD-generated PNGs).
	$upload = wp_upload_dir();
	$img_id = static function ( string $file, int $r, int $g, int $b ) use ( $upload ): int {
		$im  = imagecreatetruecolor( 600, 600 );
		$col = imagecolorallocate( $im, $r, $g, $b );
		imagefilledrectangle( $im, 0, 0, 600, 600, $col );
		$inner = imagecolorallocate( $im, min( 255, $r + 80 ), min( 255, $g + 80 ), min( 255, $b + 80 ) );
		imagefilledrectangle( $im, 150, 150, 450, 450, $inner );
		$path = trailingslashit( $upload['path'] ) . $file;
		imagepng( $im, $path );
		imagedestroy( $im );
		$att = wp_insert_attachment( [ 'post_title' => $file, 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ], $path );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $path ) );
		return (int) $att;
	};
	$att_main  = $img_id( 'opf-ix-main.png', 40, 40, 40 );
	$att_red   = $img_id( 'opf-ix-child-a.png', 200, 40, 40 );
	$att_green = $img_id( 'opf-ix-child-b.png', 40, 160, 60 );
	ix_check( 'fixture attachments generated', $att_main > 0 && $att_red > 0 && $att_green > 0 );

	// 1) Repeater product: button-repeat field + button-repeat section +
	//    quantity-repeat field + a clone-scoped formula field.
	$repeat_pid = $mk( 'OPF IX Repeat', 'opf-ix-repeat', '20' );
	$repeat_gid = FieldGroups::save( 0, [
		'fields' => [
			[ 'id' => 'attendee', 'type' => 'text', 'label' => 'Attendee name', 'required' => true,
				'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3, 'add' => 'Add guest', 'del' => 'Remove guest', 'label' => 'Guest {n}' ],
				'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => true ] ],
			[ 'id' => 'extras', 'type' => 'section', 'label' => 'Extra {n}', 'width' => 100, 'css_class' => '',
				'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3, 'add' => 'Add extra', 'del' => 'Remove extra', 'label' => 'Extra {n}' ] ],
			[ 'id' => 'seat', 'type' => 'radio', 'label' => 'Seat', 'required' => true, 'width' => 100, 'css_class' => '',
				'choices' => [
					[ 'slug' => 'front', 'label' => 'Front', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
					[ 'slug' => 'back', 'label' => 'Back', 'pricing' => [ 'type' => 'fixed', 'amount' => 0 ] ],
				] ],
			[ 'id' => 'meal_note', 'type' => 'text', 'label' => 'Meal note', 'required' => false, 'width' => 100, 'css_class' => '' ],
			// Clone-scoped formula: len() of THIS clone's meal_note value.
			[ 'id' => 'note_fee', 'type' => 'text', 'label' => 'Note fee', 'required' => false, 'width' => 100, 'css_class' => '',
				'pricing' => [ 'type' => 'formula', 'amount' => 0, 'formula' => 'len([field.meal_note])', 'formula_raw' => 'len([field.meal_note])' ] ],
			[ 'id' => 'extras_end', 'type' => 'section_end', 'label' => '', 'width' => 100, 'css_class' => '' ],
			[ 'id' => 'ticket', 'type' => 'text', 'label' => 'Ticket holder', 'required' => true,
				'repeat' => [ 'enabled' => true, 'mode' => 'quantity', 'label' => 'Ticket {n}' ],
				'pricing' => [ 'type' => 'fixed', 'amount' => 1, 'per_unit' => true ] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $repeat_pid ] ] ] ] ],
	], [ 'title' => 'OPF IX repeat group', 'status' => 'publish' ] );
	ix_check( 'repeat fixture created', $repeat_pid > 0 && $repeat_gid > 0 );

	// 2) Variable product: attribute ixcolor Red ($10) / Blue ($15 sale $12).
	$var_parent = new WC_Product_Variable();
	$var_parent->set_name( 'OPF IX Variable' );
	$var_parent->set_slug( 'opf-ix-variable' );
	$var_parent->set_status( 'publish' );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'ixcolor' );
	$attr->set_options( [ 'Red', 'Blue' ] );
	$attr->set_position( 0 );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$var_parent->set_attributes( [ $attr ] );
	$var_pid = (int) $var_parent->save();
	$mk_var = static function ( string $color, string $reg, string $sale = '' ) use ( $var_pid ): int {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $var_pid );
		$v->set_attributes( [ 'ixcolor' => $color ] );
		$v->set_regular_price( $reg );
		if ( '' !== $sale ) { $v->set_sale_price( $sale ); }
		$v->set_status( 'publish' );
		return (int) $v->save();
	};
	$var_red  = $mk_var( 'Red', '10' );
	$var_blue = $mk_var( 'Blue', '15', '12' );
	WC_Product_Variable::sync( $var_pid );
	$var_gid = FieldGroups::save( 0, [
		'fields' => [
			[ 'id' => 'engraving', 'type' => 'text', 'label' => 'Engraving', 'required' => true, 'width' => 100, 'css_class' => '' ],
			[ 'id' => 'wrap', 'type' => 'select', 'label' => 'Wrap', 'required' => false, 'width' => 100, 'css_class' => '',
				'choices' => [
					[ 'slug' => 'none', 'label' => 'None', 'pricing' => [ 'type' => 'fixed', 'amount' => 0 ], 'selected' => true ],
					[ 'slug' => 'gift', 'label' => 'Gift wrap', 'pricing' => [ 'type' => 'fixed', 'amount' => 3 ] ],
				] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $var_pid ] ] ] ] ],
	], [ 'title' => 'OPF IX variable group', 'status' => 'publish' ] );
	ix_check( 'variable fixture created', $var_pid > 0 && $var_red > 0 && $var_blue > 0 && $var_gid > 0 );

	// 3) Gallery-swap product: parent + linked-products image field with
	//    image_zoom (the only image-change surface in this tree).
	$swap_pid  = $mk( 'OPF IX Swap', 'opf-ix-swap', '8' );
	set_post_thumbnail( $swap_pid, $att_main );
	$child_a = $mk( 'OPF IX Child A', 'opf-ix-child-a', '3' );
	set_post_thumbnail( $child_a, $att_red );
	$child_b = $mk( 'OPF IX Child B', 'opf-ix-child-b', '5' );
	set_post_thumbnail( $child_b, $att_green );
	$swap_gid = FieldGroups::save( 0, [
		'fields' => [
			[ 'id' => 'addon_prod', 'type' => 'products', 'subtype' => 'image', 'label' => 'Add-on item', 'required' => false, 'width' => 100, 'css_class' => '',
				'image_zoom' => true, 'incl_img' => true, 'product_selection' => 'manual',
				'choices' => [
					[ 'slug' => 'ca', 'product_id' => $child_a ],
					[ 'slug' => 'cb', 'product_id' => $child_b ],
				] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $swap_pid ] ] ] ] ],
	], [ 'title' => 'OPF IX swap group', 'status' => 'publish' ] );
	ix_check( 'swap fixture created', $swap_pid > 0 && $child_a > 0 && $child_b > 0 && $swap_gid > 0 );

	// 4) Simulated subscription product (type via mu filter): group with a
	//    required field + a second group targeted by product_type rule.
	$sub_pid = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'OPF IX Subscription', 'post_name' => 'opf-ix-subscription' ] );
	wp_set_object_terms( $sub_pid, 'subscription', 'product_type' );
	update_post_meta( $sub_pid, '_regular_price', '30' );
	update_post_meta( $sub_pid, '_price', '30' );
	$sub_gid = FieldGroups::save( 0, [
		'fields' => [ [ 'id' => 'note', 'type' => 'text', 'label' => 'Delivery note', 'required' => true, 'width' => 100, 'css_class' => '' ] ],
		'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $sub_pid ] ],
			[ 'subject' => 'product_type', 'operator' => 'in', 'terms' => [ 'subscription' ] ],
		] ] ],
	], [ 'title' => 'OPF IX subscription group', 'status' => 'publish' ] );
	ix_check( 'subscription fixture created', $sub_pid > 0 && $sub_gid > 0 );

	// 5) Simulated booking product (dormant WAPF class = OPF's level: generic).
	$book_pid = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'OPF IX Booking', 'post_name' => 'opf-ix-booking' ] );
	wp_set_object_terms( $book_pid, 'booking', 'product_type' );
	update_post_meta( $book_pid, '_regular_price', '40' );
	update_post_meta( $book_pid, '_price', '40' );
	$book_gid = FieldGroups::save( 0, [
		'fields' => [ [ 'id' => 'party', 'type' => 'number', 'label' => 'Party size', 'required' => true, 'width' => 100, 'css_class' => '' ] ],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product_type', 'operator' => 'in', 'terms' => [ 'booking' ] ] ] ] ],
	], [ 'title' => 'OPF IX booking group', 'status' => 'publish' ] );
	ix_check( 'booking fixture created', $book_pid > 0 && $book_gid > 0 );

	// 6) Classic checkout page + customer for authenticated order-again.
	$checkout_page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'IX classic checkout', 'post_name' => 'opf-ix-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	$cust = wp_create_user( 'opf_ix_customer', wp_generate_password( 32 ), 'ix-customer@example.invalid' );
	ix_check( 'fixture customer created', ! is_wp_error( $cust ) );
	( new WP_User( $cust ) )->set_role( 'customer' );
	$pwd = wp_generate_password( 24 );
	wp_set_password( $pwd, $cust );

	// The cart-edit browser surface check needs the opt-in enabled.
	$edit_opt_existed = false !== get_option( 'opf_edit_cart', false );
	$edit_opt_prev    = get_option( 'opf_edit_cart', null );
	update_option( 'opf_edit_cart', 'yes' );

	$state = [
		'repeat_pid' => $repeat_pid, 'repeat_gid' => $repeat_gid,
		'var_pid' => $var_pid, 'var_gid' => $var_gid, 'var_red' => $var_red, 'var_blue' => $var_blue,
		'swap_pid' => $swap_pid, 'swap_gid' => $swap_gid, 'child_a' => $child_a, 'child_b' => $child_b,
		'att' => [ $att_main, $att_red, $att_green ],
		'sub_pid' => $sub_pid, 'sub_gid' => $sub_gid,
		'book_pid' => $book_pid, 'book_gid' => $book_gid,
		'checkout_page' => $checkout_page, 'customer' => $cust,
		'mu_baseline' => $mu_baseline,
		'edit_opt_existed' => $edit_opt_existed, 'edit_opt_prev' => $edit_opt_prev,
	];
	update_option( 'opf_ix_e2e_state', $state );
	file_put_contents( $dir . '/state.json', wp_json_encode( array_merge( $state, [ 'customer_user' => 'opf_ix_customer', 'customer_pass' => $pwd ] ) ) );
	echo "ok fixture setup\n";
	return;
}

if ( 'cleanup' === $phase ) {
	ix_check( 'fixture state exists', ! empty( $state ) );
	// Orders touching any fixture product.
	$products = array_filter( [ $state['repeat_pid'] ?? 0, $state['var_pid'] ?? 0, $state['var_red'] ?? 0, $state['var_blue'] ?? 0, $state['swap_pid'] ?? 0, $state['child_a'] ?? 0, $state['child_b'] ?? 0, $state['sub_pid'] ?? 0, $state['book_pid'] ?? 0 ] );
	foreach ( wc_get_orders( [ 'limit' => -1, 'return' => 'objects' ] ) as $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( in_array( (int) $item->get_product_id(), $products, true ) || in_array( (int) $item->get_variation_id(), $products, true ) ) {
				$order->delete( true );
				break;
			}
		}
	}
	foreach ( [ 'repeat_pid', 'var_pid', 'var_red', 'var_blue', 'swap_pid', 'child_a', 'child_b', 'sub_pid', 'book_pid', 'repeat_gid', 'var_gid', 'swap_gid', 'sub_gid', 'book_gid', 'checkout_page' ] as $key ) {
		if ( ! empty( $state[ $key ] ) ) { wp_delete_post( (int) $state[ $key ], true ); }
	}
	foreach ( (array) ( $state['att'] ?? [] ) as $att ) {
		$path = get_attached_file( $att );
		wp_delete_attachment( $att, true );
		if ( is_string( $path ) && file_exists( $path ) ) { unlink( $path ); }
	}
	if ( ! empty( $state['customer'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $state['customer'] );
	}
	delete_option( 'opf_ix_e2e_state' );
	if ( ! empty( $state['edit_opt_existed'] ) ) { update_option( 'opf_edit_cart', $state['edit_opt_prev'] ); }
	else { delete_option( 'opf_edit_cart' ); }
	if ( file_exists( $mu_file ) ) { unlink( $mu_file ); }
	ix_check( 'mu type-simulation removed', ! file_exists( $mu_file ) );
	$mu_after = is_dir( $mu_dir ) ? count( glob( $mu_dir . '/*.php' ) ) : 0;
	ix_check( 'mu-plugins count restored', $mu_after === (int) ( $state['mu_baseline'] ?? $mu_after ), [ 'before' => $state['mu_baseline'] ?? null, 'after' => $mu_after ] );
	FieldGroups::flush_cache();
	wc_clear_notices();
	if ( function_exists( 'opcache_reset' ) ) { opcache_reset(); }
	echo "ok fixture cleanup\n";
	return;
}

if ( 'verify-wapf' === $phase ) {
	/* WAPF Extended 3.1.5 must be ACTIVE for this phase (reference run). */
	ix_check( 'WAPF reference plugin loaded', class_exists( '\SW_WAPF_PRO\Includes\Classes\Integrations\WooCommerce_Subscriptions' ) );
	if ( ! class_exists( '\SW_WAPF_PRO\Includes\Classes\Integrations\WooCommerce_Subscriptions' ) ) {
		file_put_contents( $dir . '/server-results-wapf.json', wp_json_encode( $results, JSON_PRETTY_PRINT ) );
		return;
	}
	$sub_product = wc_get_product( $state['sub_pid'] );
	// WAPF's Integrations_Controller already ran at plugins_loaded (the mu
	// stub defines WC_Subscriptions before that), so the integration exists.
	$types = apply_filters( 'wapf/admin/allowed_product_types', [ 'simple', 'variable', 'grouped', 'external' ] );
	ix_check( 'WAPF registers subscription admin types when WCS present', in_array( 'subscription', $types, true ) && in_array( 'variable-subscription', $types, true ), $types );
	$base = apply_filters( 'wapf/pricing/cart_item_base', 99.0, $sub_product, 1, [] );
	ix_check( 'WAPF subscription cart base uses WC_Subscriptions_Product::get_price', 30.0 === (float) $base, $base );
	do_action( 'wcs_before_renewal_setup_cart_subscriptions' );
	$skip_during = apply_filters( 'wapf/skip_cart_validation', false );
	do_action( 'wcs_after_renewal_setup_cart_subscriptions' );
	$skip_after = apply_filters( 'wapf/skip_cart_validation', false );
	ix_check( 'WAPF skips cart validation during renewal setup', true === $skip_during && false === $skip_after, [ 'during' => $skip_during, 'after' => $skip_after ] );
	// WAPF bookings integration: shipped but never registered in 3.1.5
	// (autoload=false — the probe must not load the dormant class itself).
	ix_check( 'WAPF bookings integration class not auto-loaded', ! class_exists( '\SW_WAPF_PRO\Includes\Classes\Integrations\WooCommerce_Bookings', false ) );
	// WAPF cart-edit gate: wapf_edit_cart option + wapf/_wapf_children cart
	// data required. The runner pre-sets wapf_edit_cart=yes before this phase
	// (can_edit_in_cart() memoizes statically, so the enabled state must be
	// present at first call). This phase deletes the option afterwards.
	if ( class_exists( '\SW_WAPF_PRO\Includes\Classes\Util' ) ) {
		$gate_on     = \SW_WAPF_PRO\Includes\Classes\Util::can_edit_cart_item( [ 'wapf' => [ 'x' ] ] );
		$gate_nodata = \SW_WAPF_PRO\Includes\Classes\Util::can_edit_cart_item( [] );
		delete_option( 'wapf_edit_cart' );
		ix_check( 'WAPF edit-cart gate: setting + wapf data required', true === $gate_on && false === $gate_nodata, [ 'on' => $gate_on, 'no_data' => $gate_nodata ] );
	}
	file_put_contents( $dir . '/server-results-wapf.json', wp_json_encode( $results, JSON_PRETTY_PRINT ) );
	$failed = array_filter( $results, static fn ( $r ) => ! $r['pass'] );
	echo count( $results ) . ' wapf-reference checks, ' . count( $failed ) . " failed\n";
	return;
}

/* ------------------------------------------------------------------------
 * verify — server-side checks against the live fixture.
 * ---------------------------------------------------------------------- */
ix_check( 'fixture exists', ! empty( $state['repeat_gid'] ) );
if ( ! WC()->cart ) { wc_load_cart(); }
WC()->cart->empty_cart();
wc_clear_notices();
$rgid = (string) $state['repeat_gid'];
$rpid = (int) $state['repeat_pid'];
$vgid = (string) $state['var_gid'];

/* --- REPEAT (button) + QUANTITY-REPEAT + section clone values ---------- */

$repeat_values = [
	'attendee' => [ 'Ada', 'Grace' ],
	'seat'     => [ 'front', 'back' ],
	'meal_note' => [ 'abc', 'x' ],
	'note_fee' => [ 'n1', 'n2' ],
	'ticket'   => [ 'Ada', 'Grace' ],
];
$_POST['opf'] = [ $rgid => $repeat_values ];
$_POST['quantity'] = 2;
ix_check( 'button+qty repeat payload validates', true === CartIntegration::validate_add_to_cart( true, $rpid, 2 ), wc_get_notices( 'error' ) );
wc_clear_notices();
$key = WC()->cart->add_to_cart( $rpid, 2 );
ix_check( 'repeat product added to cart', false !== $key );
unset( $_POST['opf'], $_POST['quantity'] );
$items = array_values( array_filter( WC()->cart->get_cart(), static fn ( $i ) => (int) $i['product_id'] === $rpid ) );
ix_check( 'qty-repeat split produced 2 unit lines', 2 === count( $items ), array_map( static fn ( $i ) => $i['quantity'], $items ) );
$line0 = $items[0][ CartIntegration::ITEM_KEY ][ $rgid ] ?? [];
$line1 = $items[1][ CartIntegration::ITEM_KEY ][ $rgid ] ?? [];
ix_check( 'line 1 keeps button rows + ticket row 0', ( $line0['attendee'] ?? null ) === [ 'Ada', 'Grace' ] && ( $line0['ticket'] ?? null ) === [ 'Ada' ] && ( $line0['seat'] ?? null ) === [ 'front', 'back' ], $line0 );
ix_check( 'line 2 keeps button rows + ticket row 1', ( $line1['ticket'] ?? null ) === [ 'Grace' ] && ( $line1['meal_note'] ?? null ) === [ 'abc', 'x' ], $line1 );
WC()->cart->calculate_totals();
// Pricing: base 20 + attendee 2*2 + seats (5+0) + note_fee len(3)+len(1)=4 + ticket 1 = 20+4+5+4+1 = 34.
$price0 = (float) $items[0]['data']->get_price();
ix_check( 'per-unit price includes clone-scoped formula + per-row sums', abs( $price0 - 34.0 ) < 0.001, [ 'expected' => 34, 'got' => $price0 ] );
$sel = CartIntegration::visible_selections( $items[0]['data'], $items[0][ CartIntegration::ITEM_KEY ] );
ix_check( 'button clone labels numbered', in_array( [ 'label' => 'Guest 2', 'value' => 'Grace' ], $sel, true ) && in_array( [ 'label' => 'Extra 2 - Seat', 'value' => 'Back' ], $sel, true ), $sel );

// Persist to a real order item via the production hook. Meta must be staged
// after the order's own save cycle (a full save re-reads items and would
// discard staged meta), then read back from a fresh order object.
$order      = wc_create_order( [ 'customer_id' => (int) $state['customer'] ] );
$added      = [];
foreach ( $items as $item ) {
	$added[] = [ $order->add_product( $item['data'], (int) $item['quantity'], [ 'variation_id' => (int) ( $item['variation_id'] ?? 0 ), 'variation' => (array) ( $item['variation'] ?? [] ) ] ), $item ];
}
$order->calculate_totals();
$order->set_status( 'completed' );
$order->save();
foreach ( $added as [ $oid, $item ] ) {
	$line = $order->get_item( $oid );
	CartIntegration::persist_order_item( $line, $item['key'], $item, $order );
	$line->save();
}
$order_items = array_values( wc_get_order( $order->get_id() )->get_items() );
ix_check( 'order has 2 repeat lines', 2 === count( $order_items ) );
$meta0 = json_decode( (string) $order_items[0]->get_meta( '_opf_fields', true ), true );
ix_check( 'order meta keeps clone rows', ( $meta0[ $rgid ]['attendee'] ?? null ) === [ 'Ada', 'Grace' ] && ( $meta0[ $rgid ]['seat'] ?? null ) === [ 'front', 'back' ], $meta0[ $rgid ] ?? null );
ix_check( 'order display labels numbered clones', 'Grace' === $order_items[0]->get_meta( 'Guest 2', true ) && 'Back' === $order_items[0]->get_meta( 'Extra 2 - Seat', true ) );
ix_check( 'per-unit order totals reflect addons', abs( (float) $order_items[0]->get_subtotal() / (int) $order_items[0]->get_quantity() - 34.0 ) < 0.01, [ 'subtotal' => $order_items[0]->get_subtotal(), 'qty' => $order_items[0]->get_quantity() ] );

// Order-again restores the structured values.
WC()->cart->empty_cart();
$restored_ok = true;
$restored_count = 0;
foreach ( $order_items as $oi ) {
	$restored = CartIntegration::restore_order_again( [], $oi, $order );
	if ( ! empty( $restored[ CartIntegration::ITEM_KEY ] ) ) { $restored_count++; }
}
ix_check( 'order-again restores both repeat lines', 2 === $restored_count );
$order->delete( true );
WC()->cart->empty_cart();
wc_clear_notices();

// Validation residuals: max rows, section child required-per-clone, qty mismatch.
$_POST['opf'] = [ $rgid => [ 'attendee' => [ 'a', 'b', 'c', 'd' ], 'seat' => [ 'front' ], 'ticket' => [ 'x' ] ] ];
ix_check( 'button max enforced server-side', false === CartIntegration::validate_add_to_cart( true, $rpid, 1 ) );
wc_clear_notices();
$_POST['opf'] = [ $rgid => [ 'attendee' => [ 'a' ], 'seat' => [ 'front', '' ], 'meal_note' => [ '', '' ], 'note_fee' => [ '', '' ], 'ticket' => [ 'x' ] ] ];
ix_check( 'required section child enforced per clone', false === CartIntegration::validate_add_to_cart( true, $rpid, 1 ) );
wc_clear_notices();
$_POST['opf'] = [ $rgid => [ 'attendee' => [ 'a' ], 'seat' => [ 'front' ], 'ticket' => [ 'x', 'y', 'z' ] ] ];
ix_check( 'qty-repeat row-count mismatch rejected', false === CartIntegration::validate_add_to_cart( true, $rpid, 2 ) );
wc_clear_notices();
unset( $_POST['opf'] );

/* --- PRODUCT-VARIABLE -------------------------------------------------- */

$var = wc_get_product( $state['var_blue'] );
ix_check( 'blue variation resolves sale price', 12.0 === (float) $var->get_price(), $var->get_price() );
$groups_for_parent = FieldGroups::for_product( wc_get_product( $state['var_pid'] ) );
ix_check( 'parent-targeted group matches variable product', 1 <= count( array_filter( $groups_for_parent, static fn ( $e ) => (int) $e['id'] === (int) $state['var_gid'] ) ) );
$groups_for_variation = FieldGroups::for_product( $var );
ix_check( 'parent-targeted group matches via variation too', 1 <= count( array_filter( $groups_for_variation, static fn ( $e ) => (int) $e['id'] === (int) $state['var_gid'] ) ) );

$_POST['opf'] = [ $vgid => [ 'engraving' => 'IX-ENG', 'wrap' => 'gift' ] ];
$_POST['variation_id'] = $state['var_blue'];
$_POST['attribute_ixcolor'] = 'Blue';
$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $state['var_pid'], 1, $state['var_blue'], [ 'attribute_ixcolor' => 'Blue' ] );
ix_check( 'variable add validates w/ required field', true === $passed, wc_get_notices( 'error' ) );
wc_clear_notices();
$vkey = WC()->cart->add_to_cart( $state['var_pid'], 1, $state['var_blue'], [ 'attribute_ixcolor' => 'Blue' ] );
ix_check( 'variation added to cart', false !== $vkey );
unset( $_POST['opf'], $_POST['variation_id'], $_POST['attribute_ixcolor'] );
$vitem = WC()->cart->get_cart_item( $vkey );
ix_check( 'cart line carries variation + opf values', (int) $vitem['variation_id'] === (int) $state['var_blue'] && ( $vitem[ CartIntegration::ITEM_KEY ][ $vgid ]['engraving'] ?? null ) === 'IX-ENG', [ 'vid' => $vitem['variation_id'], 'values' => $vitem[ CartIntegration::ITEM_KEY ] ] );
WC()->cart->calculate_totals();
$vprice = (float) $vitem['data']->get_price();
ix_check( 'variation sale base + gift wrap priced', abs( $vprice - 15.0 ) < 0.001, [ 'expected' => 15, 'got' => $vprice ] );
$vorder = wc_create_order();
$void = $vorder->add_product( $vitem['data'], 1, [ 'variation_id' => $vitem['variation_id'], 'variation' => $vitem['variation'] ] );
$vorder->calculate_totals();
$vorder->save();
$vline = $vorder->get_item( $void );
CartIntegration::persist_order_item( $vline, $vkey, $vitem, $vorder );
$vline->save();
$vline  = wc_get_order( $vorder->get_id() )->get_item( $void );
$vmeta  = json_decode( (string) $vline->get_meta( '_opf_fields', true ), true );
ix_check( 'variation order keeps attribute + opf meta', ( $vmeta[ $vgid ]['engraving'] ?? null ) === 'IX-ENG' && 'Gift wrap' === $vline->get_meta( 'Wrap', true ) && false !== strpos( strtolower( (string) $vline->get_meta( 'ixcolor', true ) ), 'blue' ), [ 'meta' => $vmeta, 'attr' => $vline->get_meta( 'ixcolor', true ) ] );
$vorder->delete( true );
WC()->cart->empty_cart();
wc_clear_notices();

// Missing required field on a variation add is rejected.
$_POST['opf'] = [ $vgid => [ 'wrap' => 'gift' ] ];
ix_check( 'required field enforced on variation add', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['var_pid'], 1, $state['var_blue'], [ 'attribute_ixcolor' => 'Blue' ] ) );
wc_clear_notices();
unset( $_POST['opf'] );

// Variation targeting gap: WAPF's product_var rule subject matches a
// variation id (on the variation and its variable parent). OPF resolves
// every rule against the parent id, so variation-targeted groups match
// nothing — documented gap, not silently assumed parity.
$vonly_gid = FieldGroups::save( 0, [
	'fields' => [ [ 'id' => 'vonly', 'type' => 'text', 'label' => 'Blue only', 'required' => false, 'width' => 100, 'css_class' => '' ] ],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $state['var_blue'] ] ] ] ] ],
], [ 'title' => 'OPF IX variation-only probe', 'status' => 'publish' ] );
$vonly_on_blue = count( array_filter( FieldGroups::for_product( $var ), static fn ( $e ) => (int) $e['id'] === (int) $vonly_gid ) );
ix_check( 'OPF cannot target a specific variation (product_var gap)', 0 === $vonly_on_blue, [ 'probe_gid' => $vonly_gid, 'matches_on_blue' => $vonly_on_blue ] );
wp_delete_post( $vonly_gid, true );
FieldGroups::flush_cache();

/* --- PRODUCT-SUBSCRIPTION (simulated type + WCS class surface) --------- */

$sub_product = wc_get_product( $state['sub_pid'] );
ix_check( 'subscription type resolves through WC factory', $sub_product && 'subscription' === $sub_product->get_type(), $sub_product ? $sub_product->get_type() : null );
$sub_groups = FieldGroups::for_product( $sub_product );
ix_check( 'product_type=subscription rule + product rule match', 1 === count( array_filter( $sub_groups, static fn ( $e ) => (int) $e['id'] === (int) $state['sub_gid'] ) ), array_column( $sub_groups, 'id' ) );
// OPF generic lifecycle on the simulated subscription product.
$_POST['opf'] = [ (string) $state['sub_gid'] => [ 'note' => 'renew me' ] ];
ix_check( 'subscription add validates with fields', true === CartIntegration::validate_add_to_cart( true, $state['sub_pid'], 1 ), wc_get_notices( 'error' ) );
wc_clear_notices();
$skey = WC()->cart->add_to_cart( $state['sub_pid'], 1 );
ix_check( 'subscription line attaches opf data', false !== $skey && ( WC()->cart->get_cart_item( $skey )[ CartIntegration::ITEM_KEY ][ (string) $state['sub_gid'] ]['note'] ?? null ) === 'renew me' );
WC()->cart->empty_cart();
unset( $_POST['opf'] );
// Renewal-style add (no field payload): WAPF skips validation via wcs hooks;
// OPF has no equivalent — document actual behavior.
ix_check( 'OPF blocks fieldless renewal-style add (required fields)', false === CartIntegration::validate_add_to_cart( true, $state['sub_pid'], 1 ), wc_get_notices( 'error' ) );
wc_clear_notices();

/* --- PRODUCT-BOOKINGS -------------------------------------------------- */

$book_product = wc_get_product( $state['book_pid'] );
ix_check( 'booking type resolves through WC factory', $book_product && 'booking' === $book_product->get_type(), $book_product ? $book_product->get_type() : null );
$book_groups = FieldGroups::for_product( $book_product );
ix_check( 'product_type=booking rule matches', 1 === count( array_filter( $book_groups, static fn ( $e ) => (int) $e['id'] === (int) $state['book_gid'] ) ), array_column( $book_groups, 'id' ) );
$_POST['opf'] = [ (string) $state['book_gid'] => [ 'party' => '4' ] ];
ix_check( 'booking add validates with fields', true === CartIntegration::validate_add_to_cart( true, $state['book_pid'], 1 ), wc_get_notices( 'error' ) );
wc_clear_notices();
$bkey = WC()->cart->add_to_cart( $state['book_pid'], 1 );
ix_check( 'booking line attaches opf data', false !== $bkey && ( WC()->cart->get_cart_item( $bkey )[ CartIntegration::ITEM_KEY ][ (string) $state['book_gid'] ]['party'] ?? null ) === '4' );
WC()->cart->empty_cart();
unset( $_POST['opf'] );
// WAPF's shipped bookings class is never registered by the 3.1.5 bootstrap.
$wapf_base = WP_CONTENT_DIR . '/plugins/advanced-product-fields-for-woocommerce-extended';
$ctrl_src  = file_get_contents( $wapf_base . '/includes/controllers/class-integrations-controller.php' );
ix_check( 'WAPF 3.1.5 integrations map omits WooCommerce_Bookings', false === strpos( $ctrl_src, 'WooCommerce_Bookings' ) );
ix_check( 'WAPF bookings integration file ships dormant', file_exists( $wapf_base . '/includes/classes/integrations/class-woocommerce-bookings.php' ) );

/* --- CART-EDIT surface check ------------------------------------------- */

$settings_src = file_get_contents( OPF_DIR . 'includes/Service/Admin/Settings.php' );
ix_check( 'OPF exposes an opt-in edit-cart setting', false !== strpos( $settings_src, 'opf_edit_cart' ) );
$ci_src = file_get_contents( OPF_DIR . 'includes/Service/CartIntegration.php' );
ix_check( 'OPF wires the CartEdit service into cart integration', false !== strpos( $ci_src, 'CartEdit::init' ) );
ix_check( 'OPF ships the edit-cart service', class_exists( 'OPF\\Service\\CartEdit' ) );

// write machine-readable results
file_put_contents( $dir . '/server-results.json', wp_json_encode( $results, JSON_PRETTY_PRINT ) );
$failed = array_filter( $results, static fn ( $r ) => ! $r['pass'] );
echo count( $results ) . ' checks, ' . count( $failed ) . " failed\n";
if ( $failed ) { throw new RuntimeException( 'Server verification failures.' ); }
