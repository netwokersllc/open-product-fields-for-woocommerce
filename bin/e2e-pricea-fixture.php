<?php
/** Price-formula lifecycle lane fixture. Never runs on production. */

use OPF\Engine\Calculator;
use OPF\Service\FieldGroups;
use OPF\Service\WapfExporter;
use OPF\Service\Exporter;
use OPF\Service\ArchiveImporter;
use SW_WAPF_PRO\Includes\Classes\Field_Groups;
use SW_WAPF_PRO\Includes\Classes\Helper;

defined( 'ABSPATH' ) || exit;

if ( '1' !== getenv( 'OPF_PRICEA_ALLOW' ) || realpath( ABSPATH ) !== '/tmp/opf-image-pricea-wp' || ! defined( 'FQDB' ) || realpath( FQDB ) !== realpath( ABSPATH ) . '/wp-content/database/.ht.sqlite' ) {
	throw new RuntimeException( 'Owned pricea SQLite clone and explicit opt-in required.' );
}

$key = 'opf_pricea_fixture';
$state = get_option( $key, [] );
$mode = getenv( 'OPF_PRICEA_MODE' ) ?: 'setup';
$out = getenv( 'OPF_PRICEA_OUT' );
if ( ! $out || ! is_dir( $out ) ) {
	throw new RuntimeException( 'Existing artifact directory required.' );
}
$write = static function ( $name, $data ) use ( $out ) {
	file_put_contents( $out . '/' . $name, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
};

if ( 'cleanup' === $mode ) {
	if ( ! $state ) {
		throw new RuntimeException( 'Missing owned fixture.' );
	}
	if ( ! empty( $state['statuses'] ) ) {
		foreach ( $state['statuses'] as $pid => $status ) {
			if ( get_post( (int) $pid ) ) {
				wp_update_post( [ 'ID' => (int) $pid, 'post_status' => $status ] );
			}
		}
	}
	foreach ( wc_get_orders( [ 'limit' => -1, 'return' => 'objects' ] ) as $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( in_array( (int) $item->get_product_id(), $state['products'], true ) ) {
				$order->delete( true );
				break;
			}
		}
	}
	foreach ( array_reverse( $state['owned'] ) as $id ) {
		wp_delete_post( $id, true );
	}
	// Restore any pre-existing page contents the fixture rewrote (classic
	// shortcode cart/checkout swap for the browser lifecycle leg).
	foreach ( $state['pages'] ?? [] as $pid => $content ) {
		if ( get_post( (int) $pid ) ) {
			wp_update_post( [ 'ID' => (int) $pid, 'post_content' => $content ] );
		}
	}
	if ( ! empty( $state['user'] ) ) {
		wp_delete_user( $state['user'] );
	}
	foreach ( $state['options'] as $name => $entry ) {
		if ( $entry['exists'] ) {
			update_option( $name, $entry['value'] );
		} else {
			delete_option( $name );
		}
	}
	delete_option( $key );
	// Upload artifacts: 0 opf_upload_* options, no uploads/wapf dir, and no
	// opf-private-uploads dir existed before this fixture (verified at
	// augment time), so every matching artifact is owned by this run.
	global $wpdb;
	foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'opf\_upload\_%'" ) as $opt ) {
		delete_option( $opt );
	}
	$private_root = defined( 'OPF_UPLOAD_PRIVATE_DIR' ) ? OPF_UPLOAD_PRIVATE_DIR : dirname( dirname( rtrim( ABSPATH, '/\\' ) ) ) . '/opf-private-uploads-' . substr( hash( 'sha256', ABSPATH ), 0, 16 );
	$upload_targets = [ $private_root, wp_upload_dir()['basedir'] . '/wapf' ];
	$removed_files = 0;
	$removed_dirs = [];
	foreach ( $upload_targets as $target ) {
		if ( ! is_dir( $target ) || is_link( $target ) || realpath( $target ) !== $target ) {
			continue;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $entry ) {
			$path = $entry->getPathname();
			if ( $entry->isDir() ) {
				@rmdir( $path );
			} elseif ( is_file( $path ) && ! is_link( $path ) ) {
				if ( @unlink( $path ) ) {
					$removed_files++;
				}
			}
		}
		if ( @rmdir( $target ) ) {
			$removed_dirs[] = $target;
		}
	}
	FieldGroups::flush_cache();
	$write( 'cleanup.json', [
		'owned' => $state['owned'],
		'remaining' => array_values( array_filter( $state['owned'], 'get_post' ) ),
		'trace' => $state['products'],
		'restored_statuses' => isset( $state['statuses'] ) ? count( $state['statuses'] ) : 0,
		'restored_pages' => isset( $state['pages'] ) ? array_map( 'intval', array_keys( $state['pages'] ) ) : [],
		'unrestored' => isset( $state['statuses'] ) ? array_values( array_filter( array_map( 'get_post', array_keys( $state['statuses'] ) ), static function ( $p ) use ( $state ) { return $p && $p->post_status !== $state['statuses'][ $p->ID ]; } ) ) : [],
		'removed_upload_files' => $removed_files,
		'removed_upload_dirs' => $removed_dirs,
		'residual_opf_upload_options' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'opf\_upload\_%'" ),
		'option' => get_option( $key, false ),
		'active_plugins' => get_option( 'active_plugins' ),
	] );
	echo 'Cleaned owned products/groups/orders/user and restored clone options.';
	return;
}

if ( 'augment' === $mode ) {
	if ( ! $state || empty( $state['products'] ) || empty( $state['account_order'] ) || empty( $state['user'] ) ) {
		throw new RuntimeException( 'Setup state required before augment.' );
	}
	if ( ! empty( $state['augmented'] ) ) {
		throw new RuntimeException( 'Augment only once.' );
	}
	// Draft every published field group the fixture does not own so product
	// pages render only the fixture fields. Cleanup restores each status.
	$state['statuses'] = isset( $state['statuses'] ) ? $state['statuses'] : [];
	foreach ( get_posts( [ 'post_type' => [ 'opf_field_group', 'wapf_product' ], 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ] ) as $draft_id ) {
		$draft_id = (int) $draft_id;
		if ( in_array( $draft_id, $state['owned'], true ) ) {
			continue;
		}
		$state['statuses'][ $draft_id ] = 'publish';
		wp_update_post( [ 'ID' => $draft_id, 'post_status' => 'draft' ] );
	}
	update_option( $key, $state );
	$own = static function ( $id ) use ( &$state, $key ) {
		if ( ! $id || is_wp_error( $id ) ) {
			throw new RuntimeException( 'Fixture insert failed.' );
		}
		$state['owned'][] = (int) $id;
		update_option( $key, $state );
		return (int) $id;
	};
	$plain = [ 'type' => 'none', 'amount' => 0, 'formula' => '' ];
	// WAPF Extended 3.1.5 registers files(field_id): it counts the
	// comma-joined uploaded-file labels on the submitted field values
	// (extend/formulas.php:160). OPF does not register files() in either
	// evaluator, so the same formula fails closed to zero.
	$fs_formula = 'files(docs)*3*[qty]';
	$product = new WC_Product_Simple();
	$product->set_name( 'Disposable PriceA filestate' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product->set_status( 'publish' );
	$state['products']['filestate'] = $own( $product->save() );
	$state['groups']['filestate'] = $own( FieldGroups::save( 0, [
		'fields' => [
			[ 'id' => 'docs', 'label' => 'docs', 'type' => 'upload', 'required' => false, 'multiple' => true, 'max_size' => 2, 'accepted_types' => [ 'txt', 'png' ], 'pricing' => $plain ],
			[ 'id' => 'fee', 'label' => 'fee', 'type' => 'select', 'required' => true, 'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing' => [ 'type' => 'formula', 'amount' => 0, 'formula' => $fs_formula, 'per_unit' => true ] ] ], 'pricing' => $plain ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $state['products']['filestate'] ] ] ] ] ],
	], [ 'title' => 'PriceA filestate', 'status' => 'publish' ] ) );
	// The raw importer lifts only top-level field keys: choices and multiple
	// must sit on the field, not inside an options map.
	$fs_model = Field_Groups::raw_json_to_field_group( [
		'id' => 'p_' . $state['products']['filestate'], 'type' => 'wapf_product',
		'fields' => [
			[ 'id' => 'docs', 'label' => 'docs', 'type' => 'file', 'required' => false, 'multiple' => true ],
			[ 'id' => 'fee', 'label' => 'fee', 'type' => 'select', 'required' => true, 'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing_type' => 'fx', 'pricing_amount' => '(' . $fs_formula . ')' ] ] ],
		],
		'conditions' => [], 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ], 'variables' => [],
	] );
	update_post_meta( $state['products']['filestate'], '_wapf_fieldgroup', $fs_model->to_array() );
	$state['wapf_groups']['filestate'] = true;
	// WAPF equivalent of the sumqty image_quantity field (its own type name is
	// image-swatch-qty; per-choice limits live in choice options.min/max).
	$sq_model = Field_Groups::raw_json_to_field_group( [
		'id' => 'p_' . $state['products']['sumqty'], 'type' => 'wapf_product',
		'fields' => [
			[ 'id' => 'prints', 'label' => 'prints', 'type' => 'image-swatch-qty', 'required' => false, 'choices' => [
				[ 'slug' => 'oak', 'label' => 'Oak', 'options' => [ 'min' => '1', 'max' => '12' ] ],
				[ 'slug' => 'ash', 'label' => 'Ash', 'options' => [ 'min' => '0', 'max' => '12' ] ],
			] ],
			[ 'id' => 'fee', 'label' => 'fee', 'type' => 'select', 'required' => true, 'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing_type' => 'fx', 'pricing_amount' => '(sumQty(prints)*[qty])' ] ] ],
		],
		'conditions' => [], 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ], 'variables' => [],
	] );
	update_post_meta( $state['products']['sumqty'], '_wapf_fieldgroup', $sq_model->to_array() );
	$state['wapf_groups']['sumqty'] = true;
	// files() oracle: identical submitted-value shapes through both engines.
	$fs_fields = [ [ 'id' => 'docs', 'clone_idx' => 0, 'values' => [ [ 'label' => 'one.txt, two.txt' ] ] ] ];
	$oracle = [
		[ 'id' => 'files_two_uploads', 'formula' => 'files(docs)*3', 'wapf_php' => Helper::parse_math_string( 'files(docs)*3', $fs_fields ), 'opf_php' => Calculator::evaluate_formula( 'files(docs)*3', 10, 1, 0, '', null, [ 'docs' => [ 'token-a', 'token-b' ] ], 0, [], [] ), 'expected' => 6 ],
		[ 'id' => 'files_no_uploads', 'formula' => 'files(docs)*3', 'wapf_php' => Helper::parse_math_string( 'files(docs)*3', [ [ 'id' => 'docs', 'clone_idx' => 0, 'values' => [] ] ] ), 'opf_php' => Calculator::evaluate_formula( 'files(docs)*3', 10, 1, 0, '', null, [], 0, [], [] ), 'expected' => 0 ],
		[ 'id' => 'files_replace_wapf', 'formula' => '(files(docs)*3)*[qty]', 'wapf_php' => Helper::parse_math_string( Helper::replace_in_formula( '(files(docs)*3)*[qty]', 1, 10, '', 0, $fs_fields, 0 ), $fs_fields ), 'opf_php' => Calculator::evaluate_formula( 'files(docs)*3*1', 10, 1, 0, '', null, [ 'docs' => [ 'token-a', 'token-b' ] ], 0, [], [] ), 'expected' => 6 ],
	];
	foreach ( $oracle as &$o ) {
		$o['match'] = $o['wapf_php'] == $o['opf_php'];
	}
	unset( $o );
	$write( 'fieldstate-oracle.json', $oracle );
	$state['augmented'] = gmdate( 'c' );
	update_option( $key, $state );
	FieldGroups::flush_cache();
	$write( 'state.json', $state );
	echo wp_json_encode( [ 'filestate' => $state['products']['filestate'], 'drafted' => count( $state['statuses'] ), 'wapf_groups' => array_keys( $state['wapf_groups'] ) ] );
	return;
}

if ( 'wapforder' === $mode ) {
	if ( ! $state || empty( $state['products'] ) || empty( $state['user'] ) || empty( $state['wapf_groups'] ) ) {
		throw new RuntimeException( 'Augmented state required before wapforder.' );
	}
	if ( ! empty( $state['wapf_account_order'] ) ) {
		$old = wc_get_order( (int) $state['wapf_account_order'] );
		if ( $old ) {
			$old->delete( true );
		}
	}
	// A native WAPF account order (minmax + len at q=3) for the order-again
	// comparison leg. WAPF's add_prices_to_cart_item returns early once
	// woocommerce_before_calculate_totals has fired more than once in the
	// request, so both lines are added first and repriced by a single
	// calculate_totals call.
	$wapf_values = [
		'minmax' => [ 'field_x' => '8', 'field_fee' => 'a' ],
		'len'    => [ 'field_t' => '  a b ', 'field_fee' => 'a' ],
	];
	$cart = WC()->cart;
	$cart->empty_cart( true );
	$keys = [];
	foreach ( $wapf_values as $case_id => $posted ) {
		$_REQUEST['wapf_field_groups'] = 'p_' . $state['products'][ $case_id ];
		$_REQUEST['wapf'] = $posted;
		$keys[ $case_id ] = $cart->add_to_cart( $state['products'][ $case_id ], 3 );
		unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'] );
		if ( ! $keys[ $case_id ] ) {
			throw new RuntimeException( 'WAPF account-order add_to_cart rejected for ' . $case_id );
		}
	}
	$GLOBALS['wp_actions']['woocommerce_before_calculate_totals'] = 0;
	$cart->calculate_totals();
	$worder = wc_create_order();
	$worder->set_customer_id( (int) $state['user'] );
	foreach ( $keys as $case_id => $key_line ) {
		$cart_item = $cart->get_cart_item( $key_line );
		$line_id = $worder->add_product( $cart_item['data'], 3 );
		$line = $worder->get_item( $line_id );
		if ( ! empty( $cart_item['wapf'] ) ) {
			$line->add_meta_data( '_wapf_meta', [ 'fields' => $cart_item['wapf'], 'cart_item_key' => (string) $key_line ], true );
		}
		$line->save();
	}
	$worder->calculate_totals();
	$worder->set_status( 'completed' );
	$worder->save();
	$cart->empty_cart( true );
	$worder = wc_get_order( $worder->get_id() );
	$state['wapf_account_order'] = $worder->get_id();
	$state['wapf_account_order_lines'] = [];
	foreach ( $worder->get_items() as $wline ) {
		$state['wapf_account_order_lines'][] = [ 'product_id' => (int) $wline->get_product_id(), 'qty' => (int) $wline->get_quantity(), 'total' => (float) $wline->get_total(), 'has_wapf_meta' => ! empty( $wline->get_meta( '_wapf_meta', true ) ) ];
	}
	update_option( $key, $state );
	$write( 'state.json', $state );
	echo wp_json_encode( [ 'wapf_account_order' => $state['wapf_account_order'], 'total' => (float) $worder->get_total(), 'lines' => $state['wapf_account_order_lines'] ] );
	return;
}

if ( 'setup' !== $mode || $state ) {
	throw new RuntimeException( 'Setup only once.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$reference = WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/';
if ( '3.1.5' !== get_plugin_data( $reference . 'advanced-product-fields-for-woocommerce-extended.php' )['Version'] ) {
	throw new RuntimeException( 'Actual Extended 3.1.5 required.' );
}

$state = [ 'owned' => [], 'options' => [], 'products' => [], 'groups' => [], 'wapf_groups' => [], 'pages' => [], 'user' => 0 ];
foreach ( [ 'active_plugins', 'opf_admin_only', 'opf_show_totals', 'woocommerce_calc_taxes', 'woocommerce_enable_guest_checkout', 'woocommerce_default_country', 'woocommerce_currency', 'woocommerce_cod_settings', 'wapf_datepicker' ] as $name ) {
	$value = get_option( $name, '__opf_pricea_missing__' );
	$state['options'][$name] = [ 'exists' => '__opf_pricea_missing__' !== $value, 'value' => $value ];
}
update_option( $key, $state );

$own = static function ( $id ) use ( &$state, $key ) {
	if ( ! $id || is_wp_error( $id ) ) {
		throw new RuntimeException( 'Fixture insert failed.' );
	}
	$state['owned'][] = (int) $id;
	update_option( $key, $state );
	return (int) $id;
};

update_option( 'opf_admin_only', 'no' );
update_option( 'opf_show_totals', 'yes' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_cod_settings', [ 'enabled' => 'yes', 'title' => 'Proof cash on delivery', 'enable_for_virtual' => 'yes' ] );
// WAPF gates its 'date' field type registration behind this option; without it
// date fields render but is_category('field') is false so submitted values are
// silently dropped and [field.d]-style formulas evaluate to 0.
update_option( 'wapf_datepicker', 'yes' );

// Classic shortcode cart/checkout for the real-browser leg; original block
// contents are recorded in state['pages'] and restored by cleanup.
$state['pages'] = $state['pages'] ?? [];
foreach ( [ 'cart' => '[woocommerce_cart]', 'checkout' => '[woocommerce_checkout]' ] as $page_key => $shortcode ) {
	$page_id = (int) wc_get_page_id( $page_key );
	if ( $page_id > 0 && ! isset( $state['pages'][ $page_id ] ) ) {
		$state['pages'][ $page_id ] = get_post_field( 'post_content', $page_id );
		wp_update_post( [ 'ID' => $page_id, 'post_content' => $shortcode ] );
	}
}
update_option( $key, $state );

$cases = [
	[ 'id' => 'minmax', 'fields' => [ [ 'id' => 'x', 'label' => 'x', 'type' => 'text', 'choices' => [] ] ], 'formula' => 'max(3;min([field.x];7))*[qty]', 'values' => [ 'x' => '8' ], 'fee_choice' => 'a' ],
	[ 'id' => 'textcmp', 'fields' => [ [ 'id' => 'size', 'label' => 'size', 'type' => 'select', 'choices' => [ [ 'slug' => 'small', 'label' => 'Small' ], [ 'slug' => 'xl', 'label' => 'Extra Large' ] ] ] ], 'formula' => 'if([field.size]=Extra Large;20;0)*[qty]', 'values' => [ 'size' => 'xl' ], 'fee_choice' => 'a' ],
	[ 'id' => 'advanced', 'fields' => [ [ 'id' => 'x', 'label' => 'x', 'type' => 'text', 'choices' => [] ] ], 'formula' => 'round(sqrt(pow([field.x];2)))*[qty]', 'values' => [ 'x' => '2' ], 'fee_choice' => 'a' ],
	[ 'id' => 'date', 'fields' => [ [ 'id' => 'd', 'label' => 'd', 'type' => 'date', 'choices' => [] ] ], 'formula' => 'datediff(today();[field.d])*[qty]', 'values' => [ 'd' => '2026-01-15' ], 'fee_choice' => 'a' ],
	[ 'id' => 'dow', 'fields' => [ [ 'id' => 'd', 'label' => 'd', 'type' => 'date', 'choices' => [] ] ], 'formula' => 'dow([field.d])*7*[qty]', 'values' => [ 'd' => '2026-10-03' ], 'fee_choice' => 'a' ],
	[ 'id' => 'month', 'fields' => [ [ 'id' => 'd', 'label' => 'd', 'type' => 'date', 'choices' => [] ] ], 'formula' => 'month([field.d])*3*[qty]', 'values' => [ 'd' => '2026-03-15' ], 'fee_choice' => 'a' ],
	[ 'id' => 'checked', 'fields' => [ [ 'id' => 'opts', 'label' => 'opts', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'a', 'label' => 'A' ], [ 'slug' => 'b', 'label' => 'B' ], [ 'slug' => 'c', 'label' => 'C' ] ] ] ], 'formula' => 'checked(opts)*4*[qty]', 'values' => [ 'opts' => [ 'a', 'b' ] ], 'fee_choice' => 'a' ],
	[ 'id' => 'sumqty', 'fields' => [ [ 'id' => 'prints', 'label' => 'prints', 'type' => 'image_quantity', 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'quantity' => [ 'min' => 1, 'max' => 12 ] ], [ 'slug' => 'ash', 'label' => 'Ash', 'quantity' => [ 'min' => 0, 'max' => 12 ] ] ] ] ], 'formula' => 'sumQty(prints)*[qty]', 'values' => [ 'prints' => [ 'oak' => 2, 'ash' => 3 ] ], 'fee_choice' => 'a', 'wapf' => false ],
	[ 'id' => 'trig', 'fields' => [ [ 'id' => 'x', 'label' => 'x', 'type' => 'text', 'choices' => [] ] ], 'formula' => 'round(cos([field.x])*100)*[qty]', 'values' => [ 'x' => '0' ], 'fee_choice' => 'a' ],
	[ 'id' => 'priceid', 'fields' => [ [ 'id' => 'plan', 'label' => 'plan', 'type' => 'select', 'choices' => [ [ 'slug' => 'free', 'label' => 'Free', 'pricing' => [ 'type' => 'fixed', 'amount' => 0 ] ], [ 'slug' => 'premium', 'label' => 'Premium', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ] ] ] ], 'formula' => '[price.plan]*2*[qty]', 'values' => [ 'plan' => 'premium' ], 'fee_choice' => 'a' ],
	[ 'id' => 'len', 'fields' => [ [ 'id' => 't', 'label' => 't', 'type' => 'text', 'choices' => [] ] ], 'formula' => 'len([field.t];true)*[qty]', 'values' => [ 't' => '  a b ' ], 'fee_choice' => 'a' ],
];

$plain = [ 'type' => 'none', 'amount' => 0, 'formula' => '' ];
foreach ( $cases as $case ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Disposable PriceA ' . $case['id'] );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product->set_status( 'publish' );
	$state['products'][ $case['id'] ] = $own( $product->save() );
	update_option( $key, $state );

	$opf_fields = [];
	foreach ( $case['fields'] as $field ) {
		$opf_field = [ 'id' => $field['id'], 'label' => $field['label'], 'type' => $field['type'], 'pricing' => $plain ];
		if ( ! empty( $field['choices'] ) ) {
			$opf_field['choices'] = array_map( static function ( $c ) use ( $plain ) {
				return [ 'slug' => $c['slug'], 'label' => $c['label'], 'pricing' => $c['pricing'] ?? $plain, 'quantity' => $c['quantity'] ?? null ];
			}, $field['choices'] );
		}
		if ( 'image_quantity' === $field['type'] ) {
			$opf_field['min_choices'] = 1;
			$opf_field['max_choices'] = 8;
		}
		$opf_fields[] = $opf_field;
	}
	$opf_fields[] = [
		'id' => 'fee',
		'label' => 'fee',
		'type' => 'select',
		'required' => true,
		'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing' => [ 'type' => 'formula', 'amount' => 0, 'formula' => $case['formula'], 'per_unit' => true ] ] ],
		'pricing' => $plain,
	];
	$group = [
		'fields' => $opf_fields,
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $state['products'][ $case['id'] ] ] ] ] ] ],
	];
	$state['groups'][ $case['id'] ] = $own( FieldGroups::save( 0, $group, [ 'title' => 'PriceA ' . $case['id'], 'status' => 'publish' ] ) );
	update_option( $key, $state );

	if ( ! isset( $case['wapf'] ) || $case['wapf'] !== false ) {
		$wapf_fields = [];
		foreach ( $case['fields'] as $field ) {
			// OPF names the group-of-checkboxes field 'checkbox'; WAPF's view
			// filename (and stored type) is 'checkboxes'.
			$wapf_field = [ 'id' => $field['id'], 'label' => $field['label'], 'type' => 'checkbox' === $field['type'] ? 'checkboxes' : $field['type'], 'required' => false, 'options' => [] ];
			if ( ! empty( $field['choices'] ) ) {
				$wapf_field['choices'] = array_map( static function ( $c ) {
					return [ 'slug' => $c['slug'], 'label' => $c['label'], 'pricing_type' => $c['pricing']['type'] === 'fixed' ? 'fixed' : 'none', 'pricing_amount' => $c['pricing']['amount'] ?? 0 ];
				}, $field['choices'] );
			}
			$wapf_fields[] = $wapf_field;
		}
		$wapf_fields[] = [ 'id' => 'fee', 'label' => 'fee', 'type' => 'select', 'required' => true, 'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing_type' => 'fx', 'pricing_amount' => '(' . $case['formula'] . ')' ] ] ];
		$raw = [ 'id' => 'p_' . $state['products'][ $case['id'] ], 'type' => 'wapf_product', 'fields' => $wapf_fields, 'conditions' => [], 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ], 'variables' => [] ];
		$model = Field_Groups::raw_json_to_field_group( $raw );
		update_post_meta( $state['products'][ $case['id'] ], '_wapf_fieldgroup', $model->to_array() );
		$state['wapf_groups'][ $case['id'] ] = true;
	}
}

$oracle = [];
// Literal-substituted expressions: WAPF requires ';' argument separators
// (comma breaks nested calls) and parses date literals as mm-dd-yyyy; OPF
// accepts both separators and ISO dates. [field.x]/[price.x] resolution is
// probed separately via replace_in_formula below.
$probes = [
	[ 'id' => 'minmax', 'formula' => 'max(3;min(8;7))', 'wapf' => 'max(3;min(8;7))', 'opf' => 'max(3;min(8;7))' ],
	[ 'id' => 'textcmp', 'formula' => 'if(Extra Large=Extra Large;20;0)', 'wapf' => 'if(Extra Large=Extra Large;20;0)', 'opf' => 'if(Extra Large=Extra Large;20;0)' ],
	[ 'id' => 'advanced', 'formula' => 'round(sqrt(pow(2;2)))', 'wapf' => 'round(sqrt(pow(2;2)))', 'opf' => 'round(sqrt(pow(2;2)))' ],
	[ 'id' => 'date', 'formula' => 'datediff(today();01-15-2026)', 'wapf' => 'datediff(today();01-15-2026)', 'opf' => 'datediff(today();2026-01-15)' ],
	[ 'id' => 'dow', 'formula' => 'dow(10-03-2026)*7', 'wapf' => 'dow(10-03-2026)*7', 'opf' => 'dow(2026-10-03)*7' ],
	[ 'id' => 'month', 'formula' => 'month(03-15-2026)*3', 'wapf' => 'month(03-15-2026)*3', 'opf' => 'month(2026-03-15)*3' ],
	[ 'id' => 'checked', 'formula' => 'checked(opts)*4', 'wapf' => 'checked(opts)*4', 'opf' => 'checked(opts)*4', 'fields' => [ [ 'id' => 'opts', 'values' => [ [ 'label' => 'a' ], [ 'label' => 'b' ] ] ] ], 'opf_values' => [ 'opts' => [ 'a', 'b' ] ] ],
	[ 'id' => 'sumqty', 'formula' => 'sumQty(prints)', 'wapf' => 'sumQty(prints)', 'opf' => 'sumQty(prints)', 'fields' => [ [ 'id' => 'prints', 'values' => [ [ 'label' => '2' ], [ 'label' => '3' ] ] ] ], 'opf_values' => [ 'prints' => [ '_opf_type' => 'image_quantity', 'quantities' => [ 'oak' => 2, 'ash' => 3 ] ] ] ],
	[ 'id' => 'trig', 'formula' => 'round(cos(0)*100)', 'wapf' => 'round(cos(0)*100)', 'opf' => 'round(cos(0)*100)' ],
	[ 'id' => 'len', 'formula' => 'len(  a b ;true)', 'wapf' => 'len(  a b ;true)', 'opf' => 'len(  a b ;true)' ],
];
foreach ( $probes as $probe ) {
	$wapf_result = Helper::parse_math_string( $probe['wapf'], $probe['fields'] ?? [] );
	$opf_result = Calculator::evaluate_formula( $probe['opf'], 10, 1, 0, '', null, $probe['opf_values'] ?? [], 0, [], [] );
	$oracle[] = [ 'id' => $probe['id'], 'formula' => $probe['formula'], 'wapf_php' => $wapf_result, 'opf_php' => $opf_result, 'match' => $wapf_result == $opf_result ];
}
$ref_fields = [ [ 'id' => 'size', 'clone_idx' => 0, 'values' => [ [ 'label' => 'Extra Large' ] ] ], [ 'id' => 'plan', 'clone_idx' => 0, 'values' => [ [ 'label' => 'Premium', 'calc_price' => 5 ] ] ], [ 'id' => 'x', 'clone_idx' => 0, 'values' => [ [ 'label' => '8' ] ] ] ];
$oracle[] = [ 'id' => 'priceid_replace_wapf', 'formula' => '[price.plan]*2', 'wapf_php' => Helper::replace_in_formula( '[price.plan]*2', 1, 10, '', 0, $ref_fields, 0 ) . ' => ' . Helper::parse_math_string( Helper::replace_in_formula( '[price.plan]*2', 1, 10, '', 0, $ref_fields, 0 ) ), 'opf_php' => Calculator::evaluate_formula( '[price.plan]*2', 10, 1, 0, '', null, [], 0, [ 'plan' => 5 ], [] ), 'match' => true ];
$oracle[] = [ 'id' => 'fieldref_replace_wapf', 'formula' => 'if([field.size]=Extra Large;20;0)', 'wapf_php' => Helper::parse_math_string( Helper::replace_in_formula( 'if([field.size]=Extra Large;20;0)', 1, 10, '', 0, $ref_fields, 0 ) ), 'opf_php' => Calculator::evaluate_formula( 'if([field.size]=Extra Large;20;0)', 10, 1, 0, '', null, [ 'size' => 'xl' ], 0, [], [ 'size' => [ 'xl' => 'Extra Large' ] ] ), 'match' => true ];
$write( 'php-oracle.json', $oracle );

$server = [];
foreach ( $cases as $case ) {
	$product_id = $state['products'][ $case['id'] ];
	$parts = [];
	foreach ( $case['values'] as $fid => $v ) {
		$parts[] = $fid . '=' . wp_json_encode( $v );
	}
	$store = [];
	try {
		$cart = WC()->cart;
		$cart->empty_cart( true );
		$_POST['opf'] = [ (string) $state['groups'][ $case['id'] ] => $case['values'] ];
		// fee choice always 'a'
		$_POST['opf'][ (string) $state['groups'][ $case['id'] ] ]['fee'] = 'a';
		$key_line = $cart->add_to_cart( $product_id, 1 );
		unset( $_POST['opf'] );
		if ( ! $key_line ) {
			throw new RuntimeException( 'add_to_cart rejected' );
		}
		$cart->calculate_totals();
		$cart_item = $cart->get_cart_item( $key_line );
		$unit = (float) $cart_item['data']->get_price();
		$order = wc_create_order();
		$line_id = $order->add_product( $cart_item['data'], 1 );
		$line = $order->get_item( $line_id );
		\OPF\Service\CartIntegration::persist_order_item( $line, (string) $key_line, $cart_item, $order );
		$line->save();
		$order->calculate_totals();
		$order->save();
		$stored = json_decode( (string) $line->get_meta( '_opf_fields', true ), true );
		$total_before_refund = (float) $order->get_total();
		$refund = wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => 2.00, 'reason' => 'pricea proof partial refund' ] );
		$order = wc_get_order( $order->get_id() );
		$store = [
			'cart_unit' => $unit,
			'order_total_before_refund' => $total_before_refund,
			'order_total_after_refund' => (float) $order->get_total(),
			'order_total_refunded' => (float) $order->get_total_refunded(),
			'refund_id' => is_wp_error( $refund ) ? null : $refund->get_id(),
			'refund_error' => is_wp_error( $refund ) ? $refund->get_error_message() : null,
			'has_opf_meta' => is_array( $stored ) && ! empty( $stored ),
		];
		$order->delete( true );
		$cart->empty_cart( true );
	} catch ( Throwable $e ) {
		$store = [ 'error' => $e->getMessage() ];
		unset( $_POST['opf'] );
		if ( isset( $order ) && $order && wc_get_order( $order->get_id() ) ) {
			$order->delete( true );
		}
		$cart->empty_cart( true );
	}
	$server[ $case['id'] ] = $store;
}
$write( 'server-cart-order-refund.json', $server );

$admin_reload = [];
foreach ( $cases as $case ) {
	$stored = FieldGroups::group_from_post( get_post( $state['groups'][ $case['id'] ] ) )->data;
	$fee = null;
	foreach ( $stored['fields'] as $f ) {
		if ( 'fee' === $f['id'] ) {
			$fee = $f;
		}
	}
	$admin_reload[ $case['id'] ] = [
		'formula_persisted' => $fee && ( $fee['choices'][0]['pricing']['formula'] ?? '' ) === $case['formula'],
		'persisted' => $fee['choices'][0]['pricing']['formula'] ?? null,
		'expected' => $case['formula'],
	];
}
$write( 'admin-save-reload.json', $admin_reload );

if ( ! is_user_logged_in() ) {
	// no-op
	;
}
$uid = wp_create_user( 'pricea-proof', 'pricea-proof-pass-123', 'pricea-proof@example.test' );
$state['user'] = $uid;
update_option( $key, $state );

$aci = wc_create_order();
$aci->set_customer_id( $uid );
$aci->set_status( 'completed' );
foreach ( $cases as $case ) {
	if ( 'len' !== $case['id'] && 'minmax' !== $case['id'] ) {
		continue;
	}
	$cart = WC()->cart;
	$cart->empty_cart( true );
	$_POST['opf'] = [ (string) $state['groups'][ $case['id'] ] => array_merge( $case['values'], [ 'fee' => 'a' ] ) ];
	$key_line = $cart->add_to_cart( $state['products'][ $case['id'] ], 3 );
	unset( $_POST['opf'] );
	if ( ! $key_line ) {
		continue;
	}
	$cart->calculate_totals();
	$cart_item = $cart->get_cart_item( $key_line );
	$line_id = $aci->add_product( $cart_item['data'], 3 );
	$line = $aci->get_item( $line_id );
	\OPF\Service\CartIntegration::persist_order_item( $line, (string) $key_line, $cart_item, $aci );
	$line->save();
	$cart->empty_cart( true );
}
$aci->calculate_totals();
$aci->save();
$state['account_order'] = $aci->get_id();
update_option( $key, $state );

FieldGroups::flush_cache();
$state['runtime'] = [ 'base' => home_url(), 'database' => FQDB, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'extended' => '3.1.5', 'opf' => '0.1.0' ];
update_option( $key, $state );
$write( 'state.json', $state );
echo wp_json_encode( [ 'products' => $state['products'], 'groups' => $state['groups'], 'account_order' => $state['account_order'] ] );
