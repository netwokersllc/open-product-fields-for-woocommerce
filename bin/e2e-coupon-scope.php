<?php
/** Guarded disposable WooCommerce proof for WAPF coupon scope parity. */

defined( 'ABSPATH' ) || exit;

if ( '1' !== getenv( 'OPF_COUPON_E2E_ALLOW' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || '127.0.0.1' !== wp_parse_url( home_url(), PHP_URL_HOST ) || ! defined( 'SQLITE_DB_DROPIN_VERSION' ) ) {
	throw new RuntimeException( 'Coupon proof requires explicit opt-in and disposable loopback SQLite WordPress.' );
}

$GLOBALS['opf_coupon_scope_failures'] = 0;
$fixtures = [ 'product' => 0, 'group' => 0, 'coupons' => [], 'orders' => [], 'tax_rates' => [], 'files' => [] ];
$option_names = [ 'opf_admin_only', 'woocommerce_calc_discounts_sequentially', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on', 'woocommerce_bacs_settings' ];
$original_options = [];
foreach ( $option_names as $name ) {
	$original_options[ $name ] = get_option( $name, null );
}

function coupon_scope_check( string $label, bool $condition ): void {
	if ( $condition ) {
		WP_CLI::log( "  ok    $label" );
	} else {
		$GLOBALS['opf_coupon_scope_failures']++;
		WP_CLI::log( "  FAIL  $label" );
	}
}

register_shutdown_function(
	static function () use ( &$fixtures, &$original_options ): void {
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		foreach ( $fixtures['orders'] as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		foreach ( $fixtures['coupons'] as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( $fixtures['group'] ) {
			wp_delete_post( (int) $fixtures['group'], true );
		}
		if ( $fixtures['product'] ) {
			wp_delete_post( (int) $fixtures['product'], true );
		}
		foreach ( $fixtures['tax_rates'] as $id ) {
			WC_Tax::_delete_tax_rate( (int) $id );
		}
		foreach ( $fixtures['files'] as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( $original_options as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		if ( WC()->payment_gateways() ) {
			WC()->payment_gateways()->init();
		}
	}
);

WP_CLI::log( '== OPF coupon scope E2E ==' );
update_option( 'opf_admin_only', 'no' );
$coupon_suffix = strtolower( wp_generate_password( 8, false, false ) );
$product = new WC_Product_Simple();
$product->set_name( 'OPF coupon scope ' . $coupon_suffix );
$product->set_regular_price( '100.00' );
$product->set_status( 'publish' );
$product->set_virtual( true );
$fixtures['product'] = $product->save();

$group = [
	'fields' => [
		[
			'id' => 'addon',
			'label' => 'Addon',
			'type' => 'swatch',
			'choices' => [
				[ 'slug' => 'none', 'label' => 'None', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ] ],
				[ 'slug' => 'plus', 'label' => 'Plus', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'percent', 'amount' => 20.0, 'formula' => '' ] ],
			],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals' => [],
		],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $fixtures['product'] ] ] ] ] ],
];
$fixtures['group'] = OPF\Service\FieldGroups::save( 0, new OPF\Engine\FieldGroup( $group ), [ 'title' => 'OPF coupon scope ' . $coupon_suffix ] );
OPF\Service\FieldGroups::flush_cache();

$new_coupon = static function ( string $suffix, string $type = 'percent', float $amount = 10.0 ) use ( $coupon_suffix, &$fixtures ): WC_Coupon {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'opf-' . $coupon_suffix . '-' . $suffix );
	$coupon->set_discount_type( $type );
	$coupon->set_amount( $amount );
	$coupon->set_product_ids( [ $fixtures['product'] ] );
	$coupon->set_usage_limit( 0 );
	$coupon->save();
	$fixtures['coupons'][] = $coupon->get_id();
	return $coupon;
};
$add_product = static function ( int $quantity = 1 ) use ( $fixtures ): bool {
	$_POST['opf'] = [ (string) $fixtures['group'] => [ 'addon' => 'plus' ] ];
	$key = WC()->cart->add_to_cart( $fixtures['product'], $quantity );
	unset( $_POST['opf'] );
	return false !== $key;
};

$default_coupon = $new_coupon( 'default' );
coupon_scope_check( 'new coupon defaults off with absent WAPF metadata', ! OPF\Service\Admin\CouponSettings::coupon_excludes_addons( $default_coupon ) );

$editor_coupon = $new_coupon( 'editor' );
$_POST['opf_excl_addons'] = 'yes';
OPF\Service\Admin\CouponSettings::save_field( $editor_coupon->get_id(), $editor_coupon );
unset( $_POST['opf_excl_addons'] );
$editor_coupon = new WC_Coupon( $editor_coupon->get_id() );
coupon_scope_check( 'native coupon editor saves WAPF-compatible opt-in metadata', 'yes' === $editor_coupon->get_meta( 'wapf_excl_addons' ) );
OPF\Service\Admin\CouponSettings::save_field( $editor_coupon->get_id(), $editor_coupon );
$editor_coupon = new WC_Coupon( $editor_coupon->get_id() );
coupon_scope_check( 'saving unchecked removes coupon opt-in and restores default behavior', '' === $editor_coupon->get_meta( 'wapf_excl_addons' ) );

$unscoped_coupon = $new_coupon( 'unscoped' );
WC()->cart->empty_cart();
$line_added = $add_product();
$applied = WC()->cart->apply_coupon( $unscoped_coupon->get_code() );
WC()->cart->calculate_totals();
coupon_scope_check( 'unselected percent coupon discounts base plus addon', $line_added && $applied && abs( (float) WC()->cart->get_discount_total() - 12.0 ) < 0.001 );

// Exercise the real WordPress coupon transfer path, not a mocked importer.
if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
	define( 'WP_LOAD_IMPORTERS', true );
}
// WordPress loads the plugin before WP_LOAD_IMPORTERS is set during eval-file.
// Load its implementation files without redeclaring its bootstrap function.
require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
$importer_dir = WP_PLUGIN_DIR . '/wordpress-importer/';
require_once $importer_dir . 'compat.php';
require_once $importer_dir . 'php-toolkit/load.php';
foreach ( [ 'class-wxr-parser', 'class-wxr-parser-simplexml', 'class-wxr-parser-xml', 'class-wxr-parser-regex', 'class-wxr-parser-xml-processor' ] as $parser ) {
	require_once $importer_dir . 'parsers/' . $parser . '.php';
}
require_once $importer_dir . 'class-wp-import.php';
require_once ABSPATH . 'wp-admin/includes/export.php';
$legacy_code = 'opf-' . $coupon_suffix . '-legacy';
$import_file = wp_tempnam( 'opf-coupon-import.xml' );
$fixtures['files'][] = $import_file;
$import_xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>OPF coupon proof</title><link>http://example.test</link><wp:wxr_version>1.2</wp:wxr_version><wp:base_site_url>http://example.test</wp:base_site_url><wp:base_blog_url>http://example.test</wp:base_blog_url><item><title>' . $legacy_code . '</title><dc:creator></dc:creator><content:encoded><![CDATA[]]></content:encoded><wp:post_id>987654321</wp:post_id><wp:post_date>2026-10-01 00:00:00</wp:post_date><wp:post_date_gmt>2026-10-01 00:00:00</wp:post_date_gmt><wp:post_name>' . $legacy_code . '</wp:post_name><wp:status>publish</wp:status><wp:post_type>shop_coupon</wp:post_type>';
foreach ( [ 'discount_type' => 'percent', 'coupon_amount' => '10', 'product_ids' => (string) $fixtures['product'], 'wapf_excl_addons' => 'yes' ] as $key => $value ) {
	$import_xml .= '<wp:postmeta><wp:meta_key>' . $key . '</wp:meta_key><wp:meta_value><![CDATA[' . $value . ']]></wp:meta_value></wp:postmeta>';
}
$import_xml .= '</item></channel></rss>';
file_put_contents( $import_file, $import_xml );
ob_start();
( new WP_Import() )->import( $import_file );
ob_end_clean();
$legacy_coupon = new WC_Coupon( $legacy_code );
$fixtures['coupons'][] = $legacy_coupon->get_id();
coupon_scope_check( 'WordPress WXR import preserves percentage type, amount, and WAPF scope metadata', $legacy_coupon->get_id() > 0 && 'percent' === $legacy_coupon->get_discount_type() && 10.0 === (float) $legacy_coupon->get_amount() && 'yes' === $legacy_coupon->get_meta( 'wapf_excl_addons' ) );
ob_start();
export_wp( [ 'content' => 'shop_coupon' ] );
$export_xml = (string) ob_get_clean();
$export_file = wp_tempnam( 'opf-coupon-export.xml' );
$fixtures['files'][] = $export_file;
file_put_contents( $export_file, $export_xml );
$export_data = ( new WXR_Parser() )->parse( $export_file );
$export_scope = '';
foreach ( is_array( $export_data ) ? $export_data['posts'] : [] as $export_post ) {
	if ( $legacy_code === $export_post['post_title'] ) {
		foreach ( $export_post['postmeta'] as $meta ) {
			if ( 'wapf_excl_addons' === $meta['key'] ) {
				$export_scope = $meta['value'];
			}
		}
	}
}
coupon_scope_check( 'native WordPress coupon export retains WAPF scope metadata', 'yes' === $export_scope );
WC()->cart->empty_cart();
$line_added = $add_product();
$applied = WC()->cart->apply_coupon( $legacy_coupon->get_code() );
WC()->cart->calculate_totals();
coupon_scope_check( 'imported WAPF coupon metadata excludes option price', $line_added && $applied && abs( (float) WC()->cart->get_discount_total() - 10.0 ) < 0.001 );

$classic_order_id = WC()->checkout()->create_order( [ 'payment_method' => 'bacs', 'billing_email' => 'coupon-e2e@example.test' ] );
$classic_order = is_wp_error( $classic_order_id ) ? false : wc_get_order( $classic_order_id );
if ( $classic_order ) {
	$fixtures['orders'][] = $classic_order->get_id();
	$classic_item = array_values( $classic_order->get_items() )[0] ?? null;
	coupon_scope_check( 'classic Woo checkout persists scoped coupon total and OPF selection', $classic_item instanceof WC_Order_Item_Product && abs( (float) $classic_item->get_subtotal() - 120.0 ) < 0.001 && abs( (float) $classic_item->get_total() - 110.0 ) < 0.001 && abs( (float) $classic_order->get_discount_total() - 10.0 ) < 0.001 && '' !== $classic_item->get_meta( '_opf_fields' ) );
} else {
	coupon_scope_check( 'classic Woo checkout persists scoped coupon total and OPF selection', false );
}

$fixed_coupon = $new_coupon( 'fixed', 'fixed_product', 5.0 );
$fixed_coupon->update_meta_data( 'wapf_excl_addons', 'yes' );
$fixed_coupon->save();
WC()->cart->empty_cart();
$line_added = $add_product();
$applied = WC()->cart->apply_coupon( $fixed_coupon->get_code() );
WC()->cart->calculate_totals();
coupon_scope_check( 'fixed-product coupon remains unchanged despite scope metadata', $line_added && $applied && abs( (float) WC()->cart->get_discount_total() - 5.0 ) < 0.001 );

$fixed_cart_coupon = $new_coupon( 'fixed-cart', 'fixed_cart', 7.0 );
$fixed_cart_coupon->update_meta_data( 'wapf_excl_addons', 'yes' );
$fixed_cart_coupon->save();
WC()->cart->empty_cart();
$line_added = $add_product();
$applied = WC()->cart->apply_coupon( $fixed_cart_coupon->get_code() );
WC()->cart->calculate_totals();
coupon_scope_check( 'fixed-cart coupon remains unchanged despite scope metadata', $line_added && $applied && abs( (float) WC()->cart->get_discount_total() - 7.0 ) < 0.001 );

$first = $new_coupon( 'sequence-a' );
$second = $new_coupon( 'sequence-b' );
foreach ( [ $first, $second ] as $coupon ) {
	$coupon->update_meta_data( 'wapf_excl_addons', 'yes' );
	$coupon->save();
}
update_option( 'woocommerce_calc_discounts_sequentially', 'yes' );
WC()->cart->empty_cart();
$line_added = $add_product( 3 );
WC()->cart->apply_coupon( $first->get_code() );
WC()->cart->apply_coupon( $second->get_code() );
WC()->cart->calculate_totals();
coupon_scope_check( 'two sequential percent coupons discount only the three base units', $line_added && abs( (float) WC()->cart->get_discount_total() - 57.0 ) < 0.001 && abs( (float) WC()->cart->get_cart_contents_total() - 303.0 ) < 0.001 );

$limited = $new_coupon( 'limited' );
$limited->update_meta_data( 'wapf_excl_addons', 'yes' );
$limited->set_limit_usage_to_x_items( 1 );
$limited->save();
WC()->cart->empty_cart();
$line_added = $add_product( 3 );
WC()->cart->apply_coupon( $limited->get_code() );
WC()->cart->calculate_totals();
coupon_scope_check( 'usage limit discounts one eligible base unit out of three', $line_added && abs( (float) WC()->cart->get_discount_total() - 10.0 ) < 0.001 );

WC()->cart->empty_cart();
$product = wc_get_product( $fixtures['product'] );
$product->set_regular_price( '33.37' );
$product->save();
$rounded = $new_coupon( 'rounded' );
$rounded->update_meta_data( 'wapf_excl_addons', 'yes' );
$rounded->save();
$line_added = $add_product();
WC()->cart->apply_coupon( $rounded->get_code() );
WC()->cart->calculate_totals();
coupon_scope_check( 'fractional base discount uses Woo rounding instead of flooring', $line_added && abs( (float) WC()->cart->get_discount_total() - 3.34 ) < 0.001 );

$tax_rate_id = WC_Tax::_insert_tax_rate( [
	'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '10.0000',
	'tax_rate_name' => 'OPF coupon scope E2E', 'tax_rate_priority' => 1,
	'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 999, 'tax_rate_class' => '',
] );
$fixtures['tax_rates'][] = $tax_rate_id;
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
update_option( 'woocommerce_tax_based_on', 'base' );
update_option( 'woocommerce_calc_discounts_sequentially', 'no' );
$product = wc_get_product( $fixtures['product'] );
$product->set_regular_price( '110.00' );
$product->set_tax_status( 'taxable' );
$product->set_tax_class( '' );
$product->save();
$tax_coupon = $new_coupon( 'taxed' );
$tax_coupon->update_meta_data( 'wapf_excl_addons', 'yes' );
$tax_coupon->save();

update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
WC()->payment_gateways()->init();
WC()->cart->empty_cart();
$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$request->set_param( 'id', $fixtures['product'] );
$request->set_param( 'quantity', 1 );
$request->set_param( 'opf_fields', [ (string) $fixtures['group'] => [ 'addon' => 'plus' ] ] );
$add_response = rest_get_server()->dispatch( $request );
$apply_coupon_request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/apply-coupon' );
$apply_coupon_request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$apply_coupon_request->set_param( 'code', $tax_coupon->get_code() );
$apply_coupon_response = rest_get_server()->dispatch( $apply_coupon_request );
$tax_coupon_applied = in_array( $add_response->get_status(), [ 200, 201 ], true ) && 200 === $apply_coupon_response->get_status();
WC()->cart->calculate_totals();
$cart_gross_total = (float) WC()->cart->get_total( 'edit' );
$cart_tax = (float) WC()->cart->get_cart_contents_tax();
WP_CLI::log( sprintf( '        tax-inclusive Store API cart: total=%0.4f tax=%0.4f discount=%0.4f discount_tax=%0.4f', $cart_gross_total, $cart_tax, (float) WC()->cart->get_discount_total(), (float) WC()->cart->get_discount_tax() ) );
coupon_scope_check( 'tax-inclusive Store API cart excludes the addon from the coupon', $tax_coupon_applied && abs( $cart_gross_total - 121.0 ) < 0.001 && $cart_tax > 0 );

$checkout = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
$checkout->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$checkout->set_param( 'payment_method', 'bacs' );
$checkout->set_param( 'billing_address', [
	'first_name' => 'Coupon', 'last_name' => 'Proof', 'email' => 'coupon-e2e@example.test',
	'address_1' => '1 Test St', 'city' => 'Testville', 'postcode' => '12345', 'country' => 'US', 'state' => 'CA',
] );
$checkout_response = rest_get_server()->dispatch( $checkout );
$order = in_array( $checkout_response->get_status(), [ 200, 201 ], true ) ? wc_get_order( (int) ( $checkout_response->get_data()['order_id'] ?? 0 ) ) : false;
if ( $order instanceof WC_Order ) {
	$fixtures['orders'][] = $order->get_id();
	$item = array_values( $order->get_items() )[0] ?? null;
	$gross_subtotal = $item ? (float) $item->get_subtotal() + (float) $item->get_subtotal_tax() : 0.0;
	$gross_line = $item ? (float) $item->get_total() + (float) $item->get_total_tax() : 0.0;
	WP_CLI::log( sprintf( '        taxed order: subtotal=%0.4f line=%0.4f tax=%0.4f total=%0.4f', $gross_subtotal, $gross_line, (float) $order->get_total_tax(), (float) $order->get_total() ) );
	coupon_scope_check( 'taxed Store API order persists discounted inclusive line and tax', $item instanceof WC_Order_Item_Product && abs( $gross_subtotal - 132.0 ) < 0.001 && abs( $gross_line - 121.0 ) < 0.001 && (float) $order->get_total_tax() > 0 && abs( (float) $order->get_total() - 121.0 ) < 0.001 );
} else {
	coupon_scope_check( 'taxed Store API order persists discounted inclusive line and tax', false );
	WP_CLI::log( '        checkout response: ' . wp_json_encode( $checkout_response->get_data() ) );
}

if ( $GLOBALS['opf_coupon_scope_failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d coupon scope check(s) failed.', $GLOBALS['opf_coupon_scope_failures'] ) );
}
WP_CLI::success( 'All coupon scope E2E checks passed.' );
