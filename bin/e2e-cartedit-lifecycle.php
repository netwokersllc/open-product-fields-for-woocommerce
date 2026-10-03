<?php
/**
 * Guarded fixture + server checks for the edit-in-cart lane
 * (WAPF-INTERACTION-CART-EDIT).
 *
 * Phases: setup (create fixture), verify (server-side lifecycle checks),
 * cleanup (remove everything, assert baseline restored). The real-browser
 * proof runs separately (bin/e2e-cartedit-browser.mjs) between setup and
 * cleanup, and the WAPF reference run is bin/e2e-cartedit-wapf-reference.php.
 *
 * Run:
 *   OPF_CARTEDIT_E2E_ALLOW=1 OPF_CARTEDIT_PHASE=setup wp --path=<clone> eval-file bin/e2e-cartedit-lifecycle.php
 */
if ( '1' !== getenv( 'OPF_CARTEDIT_E2E_ALLOW' ) || 0 !== strpos( (string) realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Requires an authorized disposable /tmp WordPress.' );
}

use OPF\Service\CartEdit;
use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;
use OPF\Service\Uploads;

$results = &$GLOBALS['ce_results'];
function ce_check( string $label, bool $ok, $detail = null ): void {
	$GLOBALS['ce_results'][] = [ 'label' => $label, 'pass' => (bool) $ok, 'detail' => $detail ];
	echo ( $ok ? 'ok ' : 'FAIL ' ) . $label . ( null !== $detail ? ' :: ' . wp_json_encode( $detail ) : '' ) . "\n";
}

$phase = getenv( 'OPF_CARTEDIT_PHASE' ) ?: 'verify';
$dir   = '/tmp/opf-cartedit-e2e';
$state = get_option( 'opf_ce_e2e_state', [] );
$mu_dir   = WP_CONTENT_DIR . '/mu-plugins';
$mu_file  = $mu_dir . '/zz-opf-ce-uploads.php';
$upload_root = '/tmp/opf-cartedit-private-uploads';
$mu_source = <<<'MU'
<?php
/* Disposable only: give the cartedit clone a private upload root so the
 * edit-in-cart upload-retain proof can run. Removed by fixture cleanup. */
if ( ! defined( 'OPF_UPLOAD_PRIVATE_DIR' ) ) {
	define( 'OPF_UPLOAD_PRIVATE_DIR', '/tmp/opf-cartedit-private-uploads' );
}
MU;

/** All product IDs currently present. */
$product_ids = static function (): array {
	$ids = get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ] );
	return array_map( 'intval', $ids );
};
$group_ids = static function (): array {
	$ids = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ] );
	return array_map( 'intval', $ids );
};
$order_ids = static function (): array {
	return array_map( 'intval', wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) );
};

if ( 'setup' === $phase ) {
	ce_check( 'no existing cartedit fixture', ! $state );
	if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
	// Capture the true baseline BEFORE creating anything.
	$baseline = [
		'products_baseline'    => $product_ids(),
		'groups_baseline'      => $group_ids(),
		'orders_baseline'      => $order_ids(),
		'users_baseline'       => (int) count_users()['total_users'],
		'upload_root_baseline' => is_dir( $upload_root ),
	];
	$mu_baseline = is_dir( $mu_dir ) ? count( glob( $mu_dir . '/*.php' ) ) : 0;
	if ( ! is_dir( $mu_dir ) ) { mkdir( $mu_dir, 0755, true ); }
	file_put_contents( $mu_file, $mu_source );

	$mk = static function ( string $name, string $slug, string $price ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_slug( $slug );
		$p->set_status( 'publish' );
		$p->set_regular_price( $price );
		$p->set_catalog_visibility( 'visible' );
		$p->set_virtual( true );
		return (int) $p->save();
	};

	$edit_pid  = $mk( 'OPF CE Simple', 'opf-ce-simple', '12' );
	$plain_pid = $mk( 'OPF CE Plain', 'opf-ce-plain', '5' );

	$edit_gid = FieldGroups::save( 0, [
		'fields' => [
			[ 'id' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => true, 'width' => 100, 'css_class' => '', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
			[ 'id' => 'finish', 'type' => 'select', 'label' => 'Finish', 'required' => false, 'width' => 100, 'css_class' => '',
				'choices' => [
					[ 'slug' => 'matte', 'label' => 'Matte', 'selected' => true ],
					[ 'slug' => 'gloss', 'label' => 'Gloss' ],
				] ],
			[ 'id' => 'extras', 'type' => 'checkbox', 'label' => 'Extras', 'required' => false, 'width' => 100, 'css_class' => '',
				'choices' => [
					[ 'slug' => 'gift', 'label' => 'Gift' ],
					[ 'slug' => 'rush', 'label' => 'Rush' ],
				] ],
			[ 'id' => 'seat', 'type' => 'radio', 'label' => 'Seat', 'required' => false, 'width' => 100, 'css_class' => '',
				'choices' => [
					[ 'slug' => 'front', 'label' => 'Front' ],
					[ 'slug' => 'back', 'label' => 'Back' ],
				] ],
			[ 'id' => 'attendee', 'type' => 'text', 'label' => 'Attendee', 'required' => false, 'width' => 100, 'css_class' => '',
				'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3, 'add' => 'Add guest', 'del' => 'Remove guest', 'label' => 'Guest {n}' ] ],
			[ 'id' => 'ticket', 'type' => 'text', 'label' => 'Ticket', 'required' => false, 'width' => 100, 'css_class' => '',
				'repeat' => [ 'enabled' => true, 'mode' => 'quantity', 'label' => 'Ticket {n}' ] ],
			[ 'id' => 'art', 'type' => 'upload', 'label' => 'Artwork', 'required' => false, 'multiple' => false, 'accepted_types' => 'png', 'max_size' => 1 ],
			[ 'id' => 'gsec', 'type' => 'section', 'label' => 'Guest {n}', 'required' => false, 'width' => 100, 'css_class' => '',
				'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3, 'add' => 'Add guest row', 'del' => 'Remove guest row', 'label' => 'Guest {n}' ] ],
			[ 'id' => 'gs_name', 'type' => 'text', 'label' => 'Guest name', 'required' => false, 'width' => 100, 'css_class' => '' ],
			[ 'id' => 'gs_meal', 'type' => 'radio', 'label' => 'Meal', 'required' => false, 'width' => 100, 'css_class' => '',
				'choices' => [
					[ 'slug' => 'veg', 'label' => 'Veg' ],
					[ 'slug' => 'meat', 'label' => 'Meat' ],
				] ],
			[ 'id' => 'gsec_end', 'type' => 'section_end', 'label' => '', 'width' => 100, 'css_class' => '' ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $edit_pid ] ] ] ] ],
	], [ 'title' => 'OPF CE group', 'status' => 'publish' ] );
	ce_check( 'cartedit fixture created', $edit_pid > 0 && $plain_pid > 0 && $edit_gid > 0, [ 'edit_pid' => $edit_pid, 'plain_pid' => $plain_pid, 'edit_gid' => $edit_gid ] );

	$classic_cart  = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'CE classic cart', 'post_name' => 'opf-ce-cart', 'post_content' => '[woocommerce_cart]' ] );
	$classic_check = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'CE classic checkout', 'post_name' => 'opf-ce-checkout', 'post_content' => '[woocommerce_checkout]' ] );

	$cust = wp_create_user( 'opf_ce_customer', wp_generate_password( 32 ), 'ce-customer@example.invalid' );
	ce_check( 'fixture customer created', ! is_wp_error( $cust ) );
	( new WP_User( $cust ) )->set_role( 'customer' );
	$pwd = wp_generate_password( 24 );
	wp_set_password( $pwd, $cust );

	$opt_existed = false !== get_option( 'opf_edit_cart', false );
	$opt_prev    = get_option( 'opf_edit_cart', null );
	update_option( 'opf_edit_cart', 'yes' );

	$state = [
		'edit_pid' => $edit_pid, 'plain_pid' => $plain_pid, 'edit_gid' => $edit_gid,
		'classic_cart' => $classic_cart, 'classic_check' => $classic_check,
		'customer' => $cust,
		'mu_baseline' => $mu_baseline,
		'opf_edit_cart_existed' => $opt_existed,
		'opf_edit_cart_prev' => $opt_prev,
	] + $baseline;
	update_option( 'opf_ce_e2e_state', $state );
	file_put_contents( $dir . '/state.json', wp_json_encode( array_merge( $state, [ 'customer_user' => 'opf_ce_customer', 'customer_pass' => $pwd ] ) ) );
	echo "ok fixture setup\n";
	return;
}

if ( 'cleanup' === $phase ) {
	ce_check( 'fixture state exists', ! empty( $state ) );

	// Delete orders touching fixture products.
	$products = array_filter( [ $state['edit_pid'] ?? 0, $state['plain_pid'] ?? 0 ] );
	foreach ( wc_get_orders( [ 'limit' => -1, 'return' => 'objects' ] ) as $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( in_array( (int) $item->get_product_id(), $products, true ) ) { $order->delete( true ); break; }
		}
	}

	// Remove upload records + bytes created by the server/browser proofs.
	global $wpdb;
	$records = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", 'opf_upload_%' ) );
	foreach ( (array) $records as $option_name ) {
		$token = substr( $option_name, strlen( 'opf_upload_' ) );
		delete_option( $option_name );
		$path = $upload_root . '/' . $token . '.bin';
		if ( is_file( $path ) ) { unlink( $path ); }
	}
	if ( is_dir( $upload_root ) ) {
		foreach ( glob( $upload_root . '/*' ) ?: [] as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
		foreach ( [ '.upload.lock' ] as $lock ) { if ( is_file( $upload_root . '/' . $lock ) ) { unlink( $upload_root . '/' . $lock ); } }
		@rmdir( $upload_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	foreach ( [ 'edit_pid', 'plain_pid', 'edit_gid', 'classic_cart', 'classic_check' ] as $key ) {
		if ( ! empty( $state[ $key ] ) ) { wp_delete_post( (int) $state[ $key ], true ); }
	}
	if ( ! empty( $state['customer'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $state['customer'] );
	}
	delete_option( 'opf_ce_e2e_state' );

	// Restore the edit-cart option to its baseline.
	if ( ! empty( $state['opf_edit_cart_existed'] ) ) {
		update_option( 'opf_edit_cart', $state['opf_edit_cart_prev'] );
	} else {
		delete_option( 'opf_edit_cart' );
	}

	if ( file_exists( $mu_file ) ) { unlink( $mu_file ); }
	$mu_after = is_dir( $mu_dir ) ? count( glob( $mu_dir . '/*.php' ) ) : 0;
	ce_check( 'mu-plugins count restored', $mu_after === (int) ( $state['mu_baseline'] ?? $mu_after ), [ 'before' => $state['mu_baseline'] ?? null, 'after' => $mu_after ] );

	// Baseline assertions.
	$products_after = $product_ids();
	$groups_after   = $group_ids();
	$orders_after   = $order_ids();
	$users_after    = (int) count_users()['total_users'];
	ce_check( 'product set restored', $products_after === array_map( 'intval', (array) $state['products_baseline'] ), [ 'after' => $products_after, 'before' => $state['products_baseline'] ] );
	ce_check( 'field-group set restored', $groups_after === array_map( 'intval', (array) $state['groups_baseline'] ), [ 'after' => $groups_after, 'before' => $state['groups_baseline'] ] );
	ce_check( 'order set restored', $orders_after === array_map( 'intval', (array) $state['orders_baseline'] ), [ 'after' => count( $orders_after ) ] );
	ce_check( 'user count restored', $users_after === (int) $state['users_baseline'], [ 'after' => $users_after, 'before' => $state['users_baseline'] ] );
	ce_check( 'upload private dir restored', is_dir( $upload_root ) === (bool) $state['upload_root_baseline'], [ 'exists' => is_dir( $upload_root ) ] );

	FieldGroups::flush_cache();
	wc_clear_notices();
	if ( function_exists( 'opcache_reset' ) ) { opcache_reset(); }
	file_put_contents( $dir . '/cleanup-results.json', wp_json_encode( $GLOBALS['ce_results'], JSON_PRETTY_PRINT ) );
	echo "ok fixture cleanup\n";
	return;
}

if ( 'verify-wapf' === $phase ) {
	/* WAPF Extended 3.1.5 must be ACTIVE. */
	ce_check( 'WAPF reference plugin loaded', class_exists( '\SW_WAPF_PRO\Includes\Classes\Util' ) );
	if ( ! class_exists( '\SW_WAPF_PRO\Includes\Classes\Util' ) ) {
		file_put_contents( $dir . '/server-results-wapf.json', wp_json_encode( $results, JSON_PRETTY_PRINT ) );
		return;
	}
	update_option( 'wapf_edit_cart', 'yes' );
	$gate_on     = \SW_WAPF_PRO\Includes\Classes\Util::can_edit_cart_item( [ 'wapf' => [ 'x' ] ] );
	$gate_nodata = \SW_WAPF_PRO\Includes\Classes\Util::can_edit_cart_item( [] );
	delete_option( 'wapf_edit_cart' );
	ce_check( 'WAPF gate: setting + wapf data required', true === $gate_on && false === $gate_nodata, [ 'on' => $gate_on, 'no_data' => $gate_nodata ] );
	file_put_contents( $dir . '/server-results-wapf.json', wp_json_encode( $results, JSON_PRETTY_PRINT ) );
	echo count( $results ) . " wapf-reference checks\n";
	return;
}

/* ------------------------------------------------------------------------
 * verify — server-side lifecycle checks.
 * ---------------------------------------------------------------------- */
ce_check( 'fixture exists', ! empty( $state['edit_gid'] ) );
if ( ! is_dir( $upload_root ) ) { mkdir( $upload_root, 0700, true ); }
if ( ! WC()->cart ) { wc_load_cart(); }
WC()->cart->empty_cart();
wc_clear_notices();

$pid  = (int) $state['edit_pid'];
$plain = (int) $state['plain_pid'];
$gid  = (string) $state['edit_gid'];

$values_v1 = [
	'note'     => 'ORIGINAL',
	'finish'   => 'matte',
	'extras'   => [ 'gift' ],
	'seat'     => 'front',
	'attendee' => [ 'Ada', 'Grace' ],
	'ticket'   => [ 'Ada' ],
	'gs_name'  => [ 'G1', 'G2' ],
	'gs_meal'  => [ 'veg', 'meat' ],
];
$_POST['opf']      = [ $gid => $values_v1 ];
$_POST['quantity'] = 1;
ce_check( 'original payload validates', true === CartIntegration::validate_add_to_cart( true, $pid, 1 ), wc_get_notices( 'error' ) );
wc_clear_notices();
$key = WC()->cart->add_to_cart( $pid, 1 );
unset( $_POST['opf'], $_POST['quantity'] );
ce_check( 'original line added', false !== $key );
$item = WC()->cart->get_cart_item( $key );
ce_check( 'original line carries OPF values', ( $item[ CartIntegration::ITEM_KEY ][ $gid ]['note'] ?? null ) === 'ORIGINAL', $item[ CartIntegration::ITEM_KEY ] ?? null );
ce_check( 'can_edit_cart_item gate true for OPF line', true === CartEdit::can_edit_cart_item( $item ) );

// An unrelated non-OPF line must survive edits untouched.
$plain_key = WC()->cart->add_to_cart( $plain, 1 );
ce_check( 'non-OPF line added', false !== $plain_key );
ce_check( 'non-OPF line not editable', false === CartEdit::can_edit_cart_item( WC()->cart->get_cart_item( $plain_key ) ) );

// Snapshot the pre-edit line into a real order so we can prove orders are unaffected.
$order = wc_create_order( [ 'customer_id' => (int) $state['customer'] ] );
$oid   = $order->add_product( $item['data'], (int) $item['quantity'] );
$order->save();
$oline = $order->get_item( $oid );
CartIntegration::persist_order_item( $oline, $key, $item, $order );
$oline->save();
$order->calculate_totals();
$order_meta_before = json_decode( (string) wc_get_order( $order->get_id() )->get_item( $oid )->get_meta( '_opf_fields', true ), true );

// --- edit submit: remove-then-add, values replaced ------------------------
$cart_key_before = $key;
$_POST['_opf_edit'] = $key;
$_POST['opf'] = [ $gid => [
	'note'     => 'EDITED',
	'finish'   => 'gloss',
	'extras'   => [ 'rush' ],
	'seat'     => 'back',
	'attendee' => [ 'A2', 'B2' ],
	'ticket'   => [ 'A2' ],
	'gs_name'  => [ 'G1e', 'G2e' ],
	'gs_meal'  => [ 'meat', 'veg' ],
] ];
$_POST['quantity'] = 1;
ce_check( 'edit payload validates', true === CartIntegration::validate_add_to_cart( true, $pid, 1 ), wc_get_notices( 'error' ) );
wc_clear_notices();
$edited_key = WC()->cart->add_to_cart( $pid, 1 );
unset( $_POST['_opf_edit'], $_POST['opf'], $_POST['quantity'] );
ce_check( 'edited add succeeded', false !== $edited_key );
ce_check( 'old key no longer resolves', ! WC()->cart->get_cart_item( $cart_key_before ), [ 'old' => $cart_key_before, 'new' => $edited_key ] );
$edit_lines = array_values( array_filter( WC()->cart->get_cart(), static fn ( $i ) => (int) $i['product_id'] === $pid ) );
ce_check( 'exactly one line for the edited product', 1 === count( $edit_lines ), array_map( static fn ( $i ) => $i['key'], $edit_lines ) );
$edited = $edit_lines[0];
ce_check( 'edited line carries new values', ( $edited[ CartIntegration::ITEM_KEY ][ $gid ]['note'] ?? null ) === 'EDITED' && ( $edited[ CartIntegration::ITEM_KEY ][ $gid ]['finish'] ?? null ) === 'gloss' && ( $edited[ CartIntegration::ITEM_KEY ][ $gid ]['seat'] ?? null ) === 'back', $edited[ CartIntegration::ITEM_KEY ][ $gid ] ?? null );
ce_check( 'edited line carries replaced section-repeat rows', ( $edited[ CartIntegration::ITEM_KEY ][ $gid ]['gs_name'] ?? null ) === [ 'G1e', 'G2e' ] && ( $edited[ CartIntegration::ITEM_KEY ][ $gid ]['gs_meal'] ?? null ) === [ 'meat', 'veg' ], $edited[ CartIntegration::ITEM_KEY ][ $gid ] ?? null );
ce_check( 'non-OPF line untouched by edit', null !== WC()->cart->get_cart_item( $plain_key ) );
ce_check( 'edit did not delete the pre-edit order', $order_meta_before !== null && ( new WC_Order( $order->get_id() ) )->get_item( $oid ) instanceof WC_Order_Item_Product );
$after_meta = json_decode( (string) ( new WC_Order( $order->get_id() ) )->get_item( $oid )->get_meta( '_opf_fields', true ), true );
ce_check( 'order meta unchanged after cart edit', ( $after_meta[ $gid ]['note'] ?? null ) === 'ORIGINAL', $after_meta[ $gid ] ?? null );

// --- fail closed: forged key pointing at a non-OPF line -------------------
WC()->cart->empty_cart();
$plain_key2 = WC()->cart->add_to_cart( $plain, 1 );
$_POST['_opf_edit'] = $plain_key2; // forged: names a non-OPF line
$_POST['opf']       = [ $gid => [ 'note' => 'ATTACK' ] ];
WC()->cart->add_to_cart( $pid, 1 );
unset( $_POST['_opf_edit'], $_POST['opf'] );
ce_check( 'forged non-OPF edit key is not destructive', null !== WC()->cart->get_cart_item( $plain_key2 ) );
WC()->cart->empty_cart();

// --- upload token retain across an edit -----------------------------------
// Mint a session-owned record deterministically (same shape receive() writes).
$token = bin2hex( random_bytes( 32 ) );
$png   = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1cAAAAASUVORK5CYII=' );
file_put_contents( $upload_root . '/' . $token . '.bin', $png );
$owner = Uploads::owner();
add_option( 'opf_upload_' . $token, [
	'name' => 'proof.png', 'mime' => 'image/png', 'size' => strlen( $png ),
	'owner' => $owner, 'product_id' => $pid, 'group_id' => $gid, 'field_id' => 'art',
	'created' => time(), 'order_id' => 0, 'cart' => true,
], '', false );
$_POST['opf']      = [ $gid => [ 'note' => 'WITHFILE', 'art' => [ $token ] ] ];
$_POST['quantity'] = 1;
$up_key = WC()->cart->add_to_cart( $pid, 1 );
unset( $_POST['opf'], $_POST['quantity'] );
$up_item = $up_key ? WC()->cart->get_cart_item( $up_key ) : null;
ce_check( 'upload token accepted on add', $up_item && ( $up_item[ CartIntegration::ITEM_KEY ][ $gid ]['art'][0] ?? null ) === $token, $up_item ? ( $up_item[ CartIntegration::ITEM_KEY ][ $gid ] ?? null ) : null );

if ( $up_key ) {
	$_POST['_opf_edit'] = $up_key;
	$_POST['opf']       = [ $gid => [ 'note' => 'WITHFILE2', 'art' => [ $token ] ] ];
	$_POST['quantity']  = 1;
	$up_edited = WC()->cart->add_to_cart( $pid, 1 );
	unset( $_POST['_opf_edit'], $_POST['opf'], $_POST['quantity'] );
	$up_after = $up_edited ? WC()->cart->get_cart_item( $up_edited ) : null;
	ce_check( 'upload token retained across edit (not re-minted)', $up_after && ( $up_after[ CartIntegration::ITEM_KEY ][ $gid ]['art'][0] ?? null ) === $token, $up_after ? ( $up_after[ CartIntegration::ITEM_KEY ][ $gid ] ?? null ) : null );
	$record = Uploads::record( $token );
	ce_check( 'retained upload record bound to cart session, not order', is_array( $record ) && empty( $record['order_id'] ), $record ? [ 'order_id' => $record['order_id'] ] : null );
}
WC()->cart->empty_cart();
wc_clear_notices();

file_put_contents( $dir . '/server-results.json', wp_json_encode( $results, JSON_PRETTY_PRINT ) );
$failed = array_filter( $results, static fn ( $r ) => ! $r['pass'] );
echo count( $results ) . ' checks, ' . count( $failed ) . " failed\n";
if ( $failed ) { throw new RuntimeException( 'Server verification failures.' ); }
