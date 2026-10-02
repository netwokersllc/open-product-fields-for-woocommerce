<?php
/**
 * Guarded disposable-clone proof: render the same Extended `p` HTML+shortcode
 * payload through installed WAPF Extended 3.1.5 and OPF, then compare the
 * sanitized/executed fragments.
 *
 * Run only on a disposable WordPress/WooCommerce clone under /tmp with WAPF
 * Extended and OPF both active:
 *   OPF_CONTENT_HTML_E2E_ALLOW=1 OPF_CONTENT_HTML_E2E_PHASE=setup   wp eval-file bin/e2e-content-html-storefront.php
 *   OPF_CONTENT_HTML_E2E_ALLOW=1 OPF_CONTENT_HTML_E2E_PHASE=verify  wp eval-file bin/e2e-content-html-storefront.php
 *   OPF_CONTENT_HTML_E2E_ALLOW=1 OPF_CONTENT_HTML_E2E_PHASE=cleanup wp eval-file bin/e2e-content-html-storefront.php
 *
 * @package open-product-fields-for-woocommerce
 */

if ( '1' !== getenv( 'OPF_CONTENT_HTML_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Requires an authorized disposable /tmp WordPress.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'OPF\Service\FieldGroups' ) || ! class_exists( 'SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
	throw new RuntimeException( 'Activate WooCommerce, OPF, and WAPF Extended before running this proof.' );
}

const OPFCH_OPTION   = 'opfch_e2e_state_20261002';
const OPFCH_SHORTCODE = 'opfch_probe_20261002';
const OPFCH_STATE    = '/tmp/opfch-content-html-state-20261002.json';
const OPFCH_TITLE    = 'OPFCH content HTML storefront fixture 20261002';
const OPFCH_SLUG     = 'opfch-content-html-20261002';
const OPFCH_MU_FILE  = 'opfch-e2e-fixture-20261002.php';

/**
 * Shared paragraph payload: allowlisted markup, disallowed markup, one fixture
 * shortcode, escaping probes, and the img alt/target attributes that Extended's
 * `p` view permits.
 */
function opfch_payload(): string {
	return <<<'OPFCH_PAYLOAD'
OPFCH intro <strong class="opfch-keep">OPFCH_BOLD</strong> &amp; <em>OPFCH_EM</em><br>
<a href="https://example.test/opfch?a=1&amp;b=2" target="_blank" class="opfch-ln" id="opfch-a" style="color:blue">OPFCH_LINK</a>
<ul class="opfch-lst"><li id="opfch-li1">OPFCH_ONE</li><li>OPFCH_TWO</li></ul>
<h3 class="opfch-h">OPFCH_H3</h3>
<table class="opfch-t"><thead><tr><th>OPFCH_TH</th></tr></thead><tbody><tr><td>OPFCH_TD</td></tr></tbody></table>
<img src="/wp-includes/images/blank.gif" class="opfch-im" id="opfch-img" style="max-width:2px" alt="OPFCH_ALT" target="_blank">
<span style="color:red" class="opfch-sp">OPFCH_SPAN</span><div class="opfch-dv" id="opfch-d1">OPFCH_DIV</div>
<hr class="opfch-hr">
[opfch_probe_20261002]
<script>alert('OPFCH_SCRIPT')</script>
<u>OPFCH_U</u><iframe src="https://evil.invalid/x">OPFCH_IFRAME</iframe>
<i onclick="opfchEvil()">OPFCH_I</i>
<object>OPFCH_OBJ</object>
<a href="javascript:alert('OPFCH_XSS')">OPFCH_JSURL</a>
Tail "quotes" &amp; ampersand
OPFCH_PAYLOAD;
}

/** mu-plugin that registers the uniquely named fixture shortcode. */
function opfch_mu_source(): string {
	return <<<'OPFCH_MU'
<?php
// Disposable OPF content-HTML E2E fixture; loaded only while the guarded proof runs.
add_shortcode( 'opfch_probe_20261002', static function () {
	return '<mark data-opfch="probe">OPFCH_SC_OK_20261002</mark>';
} );
OPFCH_MU;
}

function opfch_mu_path(): string {
	return trailingslashit( WPMU_PLUGIN_DIR ) . OPFCH_MU_FILE;
}

/** Strip the single outer wrapper element, returning the inner fragment. */
function opfch_inner( string $html ): string {
	$inner = preg_replace( '/\A\s*<div\b[^>]*>/s', '', $html );
	$inner = preg_replace( '/<\/div>\s*\z/s', '', (string) $inner );
	return (string) $inner;
}

/** Whitespace-insensitive comparison key for two rendered fragments. */
function opfch_norm( string $html ): string {
	$html = preg_replace( '/\s+/u', ' ', $html );
	return trim( (string) preg_replace( '/>\s+</u', '><', (string) $html ) );
}

function opfch_check( array &$results, string $label, bool $pass ): void {
	$results[] = [ 'label' => $label, 'pass' => $pass ];
	echo ( $pass ? 'ok' : 'FAIL' ) . ' ' . $label . "\n";
}

$phase = (string) getenv( 'OPF_CONTENT_HTML_E2E_PHASE' );

if ( 'setup' === $phase ) {
	if ( get_page_by_title( OPFCH_TITLE, OBJECT, 'opf_field_group' ) || get_option( OPFCH_OPTION ) ) {
		throw new RuntimeException( 'Fixture already exists; refusing to modify it.' );
	}

	$mu_path = opfch_mu_path();
	if ( file_exists( $mu_path ) ) {
		throw new RuntimeException( 'Fixture mu-plugin already exists; refusing to overwrite it.' );
	}
	$mu_dir_existed = is_dir( WPMU_PLUGIN_DIR );
	if ( ! $mu_dir_existed && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
		throw new RuntimeException( 'Could not create mu-plugins directory.' );
	}
	if ( false === file_put_contents( $mu_path, opfch_mu_source() ) ) {
		throw new RuntimeException( 'Could not write fixture mu-plugin.' );
	}

	$product_id = 0;
	$group_id   = 0;
	$page_id    = 0;
	try {
		$product = new WC_Product_Simple();
		$product->set_name( OPFCH_TITLE );
		$product->set_slug( OPFCH_SLUG );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		$product->set_virtual( true );
		$product_id = $product->save();
		if ( ! $product_id ) {
			throw new RuntimeException( 'Could not create the temporary WooCommerce product.' );
		}

		// Product-local WAPF Extended field group (stored `to_array()` shape).
		// `p_content` is written raw so the storefront render-time wp_kses() is
		// exercised, exactly as an imported or hand-written record would be.
		update_post_meta( $product_id, '_wapf_fieldgroup', [
			'id'          => 'p_' . $product_id,
			'type'        => 'wapf_product',
			'layout'      => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ],
			'variables'   => [],
			'rule_groups' => [],
			'fields'      => [ [
				'id'           => 'opfch_html',
				'label'        => '',
				'description'  => '',
				'type'         => 'p',
				'required'     => false,
				'class'        => '',
				'width'        => 100,
				'parent_clone' => [],
				'options'      => [ 'p_content' => opfch_payload() ],
				'conditionals' => [],
				'clone'        => [ 'enabled' => false ],
				'pricing'      => [ 'type' => 'fixed', 'amount' => 0, 'enabled' => false ],
			] ],
		] );

		// Host page: the block single-product template does not run
		// woocommerce_before_add_to_cart_button under this theme; the
		// [product_page] shortcode renders the classic add-to-cart template
		// where both plugins hook their field output.
		$page_id = wp_insert_post( [
			'post_title'   => OPFCH_TITLE . ' page',
			'post_name'    => 'opfch-content-html-page-20261002',
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => '[product_page id="' . $product_id . '"]',
		] );
		if ( ! $page_id || is_wp_error( $page_id ) ) {
			throw new RuntimeException( 'Could not create the temporary host page.' );
		}

		$data = OPF\Engine\FieldGroup::normalize( [
			'fields' => [
				[
					'id' => 'opfch_html', 'label' => '', 'type' => 'paragraph',
					'content' => opfch_payload(), 'content_format' => 'html', 'process_shortcodes' => true,
				],
				[
					'id' => 'opfch_nosc', 'label' => '', 'type' => 'paragraph',
					'content' => 'OPFCH_NOSC [opfch_probe_20261002]', 'content_format' => 'html', 'process_shortcodes' => false,
				],
			],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
		] );
		$group_id = OPF\Service\FieldGroups::save( 0, $data, [ 'title' => OPFCH_TITLE, 'status' => 'publish' ] );
		if ( ! $group_id ) {
			throw new RuntimeException( 'Could not create the temporary OPF field group.' );
		}

		$state = [
			'product'        => $product_id,
			'opf_group'      => $group_id,
			'page'           => $page_id,
			'mu_file'        => $mu_path,
			'mu_dir_created' => ! $mu_dir_existed,
		];
		update_option( OPFCH_OPTION, $state );
		file_put_contents( OPFCH_STATE, wp_json_encode( $state ) );
		echo "ok fixture persisted product=$product_id group=$group_id page=$page_id mu=" . OPFCH_MU_FILE . "\n";
	} catch ( Throwable $e ) {
		foreach ( [ $group_id, $page_id, $product_id ] as $created ) {
			if ( $created ) {
				wp_delete_post( (int) $created, true );
			}
		}
		throw $e;
	}
	return;
}

if ( 'verify' === $phase ) {
	$state = get_option( OPFCH_OPTION );
	if ( ! is_array( $state ) || empty( $state['product'] ) || empty( $state['opf_group'] ) ) {
		throw new RuntimeException( 'Run the setup phase first; fixture state is missing.' );
	}
	if ( ! shortcode_exists( 'opfch_probe_20261002' ) ) {
		throw new RuntimeException( 'Fixture shortcode is not registered; mu-plugin missing.' );
	}

	$product = wc_get_product( (int) $state['product'] );
	if ( ! $product ) {
		throw new RuntimeException( 'Fixture product is missing.' );
	}

	// Real WAPF Extended render path: stored meta -> model -> frontend field view.
	$wapf_group = SW_WAPF_PRO\Includes\Classes\Field_Groups::process_data( get_post_meta( $product->get_id(), '_wapf_fieldgroup', true ) );
	if ( ! $wapf_group || empty( $wapf_group->fields ) ) {
		throw new RuntimeException( 'WAPF field group did not load from _wapf_fieldgroup meta.' );
	}
	$wapf_html = SW_WAPF_PRO\Includes\Classes\Html::field( $product, $wapf_group->fields[0], $wapf_group->id );

	// Real OPF render path for the html+shortcode paragraph field.
	$entry = null;
	foreach ( OPF\Service\FieldGroups::for_product( $product ) as $candidate ) {
		if ( (int) $candidate['id'] === (int) $state['opf_group'] ) {
			$entry = $candidate;
		}
	}
	if ( ! $entry ) {
		throw new RuntimeException( 'OPF field group did not match the fixture product.' );
	}
	ob_start();
	OPF\Service\Renderer::render_group( $entry['id'], $entry['title'], $entry['group'], 10.0 );
	$opf_group_html = (string) ob_get_clean();

	if ( ! preg_match( '#data-opf-field="opfch_html"[^>]*><div class="opf-field-content">(.*?)</div>\s*</div>#s', $opf_group_html, $opf_m )
		|| ! preg_match( '#data-opf-field="opfch_nosc"[^>]*><div class="opf-field-content">(.*?)</div>\s*</div>#s', $opf_group_html, $opf_nosc_m ) ) {
		throw new RuntimeException( 'Could not extract OPF paragraph fragments from rendered group.' );
	}
	$wapf_inner = opfch_inner( $wapf_html );
	$opf_inner  = $opf_m[1];
	$opf_nosc   = $opf_nosc_m[1];

	$results = [];

	opfch_check( $results, 'WAPF keeps allowlisted strong/em/link/list/heading/table/img/span/div/hr markup',
		false !== strpos( $wapf_inner, '<strong class="opfch-keep">OPFCH_BOLD</strong>' )
		&& false !== strpos( $wapf_inner, '<em>OPFCH_EM</em>' )
		&& false !== strpos( $wapf_inner, '<ul class="opfch-lst">' )
		&& false !== strpos( $wapf_inner, '<h3 class="opfch-h">OPFCH_H3</h3>' )
		&& false !== strpos( $wapf_inner, '<td>OPFCH_TD</td>' )
		&& false !== strpos( $wapf_inner, '<span style="color:red" class="opfch-sp">' )
		&& false !== strpos( $wapf_inner, '<hr class="opfch-hr">' ) );

	opfch_check( $results, 'OPF keeps allowlisted strong/em/link/list/heading/table/img/span/div/hr markup',
		false !== strpos( $opf_inner, '<strong class="opfch-keep">OPFCH_BOLD</strong>' )
		&& false !== strpos( $opf_inner, '<em>OPFCH_EM</em>' )
		&& false !== strpos( $opf_inner, '<ul class="opfch-lst">' )
		&& false !== strpos( $opf_inner, '<h3 class="opfch-h">OPFCH_H3</h3>' )
		&& false !== strpos( $opf_inner, '<td>OPFCH_TD</td>' )
		&& false !== strpos( $opf_inner, '<span style="color:red" class="opfch-sp">' )
		&& false !== strpos( $opf_inner, '<hr class="opfch-hr">' ) );

	opfch_check( $results, 'WAPF strips disallowed tags and attributes (script/u/iframe/object/onclick/javascript:)',
		false === stripos( $wapf_inner, '<script' ) && false === stripos( $wapf_inner, '<u>' )
		&& false === stripos( $wapf_inner, '<iframe' ) && false === stripos( $wapf_inner, '<object' )
		&& false === stripos( $wapf_inner, 'onclick' ) && false === stripos( $wapf_inner, 'javascript:' ) );
	opfch_check( $results, 'OPF strips disallowed tags and attributes (script/u/iframe/object/onclick/javascript:)',
		false === stripos( $opf_inner, '<script' ) && false === stripos( $opf_inner, '<u>' )
		&& false === stripos( $opf_inner, '<iframe' ) && false === stripos( $opf_inner, '<object' )
		&& false === stripos( $opf_inner, 'onclick' ) && false === stripos( $opf_inner, 'javascript:' ) );

	opfch_check( $results, 'WAPF executes the fixture shortcode after sanitization (<mark> output survives wp_kses)',
		false !== strpos( $wapf_inner, '<mark data-opfch="probe">OPFCH_SC_OK_20261002</mark>' )
		&& false === strpos( $wapf_inner, '[opfch_probe_20261002]' ) );
	opfch_check( $results, 'OPF executes the fixture shortcode after sanitization (<mark> output survives wp_kses)',
		false !== strpos( $opf_inner, '<mark data-opfch="probe">OPFCH_SC_OK_20261002</mark>' )
		&& false === strpos( $opf_inner, '[opfch_probe_20261002]' ) );

	opfch_check( $results, 'OPF opt-out paragraph keeps the literal shortcode text',
		false !== strpos( $opf_nosc, '[opfch_probe_20261002]' ) && false === strpos( $opf_nosc, '<mark' ) );

	opfch_check( $results, 'WAPF keeps img alt and target attributes',
		(bool) preg_match( '/<img\b[^>]*\balt="OPFCH_ALT"/', $wapf_inner )
		&& (bool) preg_match( '/<img\b[^>]*\btarget="_blank"/', $wapf_inner ) );
	opfch_check( $results, 'OPF keeps img alt and target attributes',
		(bool) preg_match( '/<img\b[^>]*\balt="OPFCH_ALT"/', $opf_inner )
		&& (bool) preg_match( '/<img\b[^>]*\btarget="_blank"/', $opf_inner ) );

	opfch_check( $results, 'Escaped entity text survives identically on both sides',
		false !== strpos( $wapf_inner, '&amp;' ) && false !== strpos( $opf_inner, '&amp;' ) );

	$wapf_norm = opfch_norm( $wapf_inner );
	$opf_norm  = opfch_norm( $opf_inner );
	opfch_check( $results, 'WAPF and OPF sanitized+shortcode fragments are equivalent', $wapf_norm === $opf_norm );

	if ( $wapf_norm !== $opf_norm ) {
		echo "--- wapf fragment ---\n$wapf_norm\n--- opf fragment ---\n$opf_norm\n";
	}
	file_put_contents( '/tmp/opfch-content-html-verify-20261002.json', wp_json_encode( [
		'time' => gmdate( 'c' ), 'checks' => $results,
		'wapf_fragment' => $wapf_norm, 'opf_fragment' => $opf_norm, 'opf_nosc' => $opf_nosc,
	] ) );

	foreach ( $results as $r ) {
		if ( ! $r['pass'] ) {
			throw new RuntimeException( 'Content HTML parity verification failed: ' . $r['label'] );
		}
	}
	echo "ok all content HTML storefront checks passed\n";
	return;
}

if ( 'cleanup' === $phase ) {
	$state = get_option( OPFCH_OPTION );
	if ( is_array( $state ) ) {
		foreach ( [ 'product', 'opf_group', 'page' ] as $key ) {
			if ( ! empty( $state[ $key ] ) ) {
				wp_delete_post( (int) $state[ $key ], true );
			}
		}
	}
	delete_option( OPFCH_OPTION );

	$mu_path = opfch_mu_path();
	if ( file_exists( $mu_path ) ) {
		unlink( $mu_path );
	}
	if ( ! empty( $state['mu_dir_created'] ) && is_dir( WPMU_PLUGIN_DIR ) && [] === (array) array_diff( (array) scandir( WPMU_PLUGIN_DIR ), [ '.', '..' ] ) ) {
		rmdir( WPMU_PLUGIN_DIR );
	}
	if ( file_exists( OPFCH_STATE ) ) {
		unlink( OPFCH_STATE );
	}

	$remaining = get_posts( [ 'post_type' => 'product', 'name' => OPFCH_SLUG, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1 ] );
	foreach ( [ OPFCH_TITLE, OPFCH_TITLE . ' page' ] as $fixture_title ) {
		foreach ( [ 'opf_field_group', 'page' ] as $fixture_type ) {
			if ( get_page_by_title( $fixture_title, OBJECT, $fixture_type ) ) {
				$remaining[] = $fixture_title;
			}
		}
	}
	if ( [] !== $remaining || get_option( OPFCH_OPTION ) || file_exists( $mu_path ) ) {
		throw new RuntimeException( 'Fixture cleanup incomplete.' );
	}
	echo "ok content HTML storefront fixture cleanup verified\n";
	return;
}

throw new RuntimeException( 'Set OPF_CONTENT_HTML_E2E_PHASE to setup, verify, or cleanup.' );
