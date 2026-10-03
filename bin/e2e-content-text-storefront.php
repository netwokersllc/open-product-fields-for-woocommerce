<?php
/** WAPF Free 1.7.1 save/render reference; authorized disposable SQLite clone only. */
if ( '1' !== getenv( 'OPF_CONTENT_TEXT_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) || ! defined( 'FQDB' ) || 0 !== strpos( realpath( FQDB ), realpath( ABSPATH ) . '/' ) ) {
	throw new RuntimeException( 'Requires explicitly authorized /tmp WordPress with its own SQLite database.' );
}
if ( ! class_exists( 'SW_WAPF\Includes\Classes\Field_Groups' ) || ! class_exists( 'OPF\Service\FieldGroups' ) || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Activate WooCommerce, OPF and WAPF Free.' );
}
$free = get_plugin_data( WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce/advanced-product-fields-for-woocommerce.php' );
if ( '1.7.1' !== $free['Version'] || class_exists( 'SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
	throw new RuntimeException( 'Reference must be Free 1.7.1, without Extended.' );
}
$option = 'opf_content_text_proof_20261003';
$out = getenv( 'OPF_CONTENT_TEXT_OUT' );
if ( ! $out || 0 !== strpos( realpath( $out ), '/tmp/' ) ) { throw new RuntimeException( 'Set an existing /tmp artifact directory.' ); }
$phase = getenv( 'OPF_CONTENT_TEXT_PHASE' );
function opfct_inventory() {
	global $wpdb;
	return [ 'posts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), 'postmeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ), 'active_plugins' => get_option( 'active_plugins' ), 'home' => get_option( 'home' ), 'siteurl' => get_option( 'siteurl' ) ];
}
function opfct_assert( $pass, $label, &$checks ) {
	$checks[] = [ 'label' => $label, 'pass' => (bool) $pass ];
	echo ( $pass ? 'ok ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $pass ) { throw new RuntimeException( $label ); }
}
if ( 'setup' === $phase ) {
	if ( get_option( $option ) ) { throw new RuntimeException( 'Fixture already exists.' ); }
	$state = [ 'baseline' => opfct_inventory(), 'posts' => [] ];
	update_option( $option, $state, false );
	try {
		$product = new WC_Product_Simple();
		$product->set_name( 'OPF Content Text proof 20261003' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		$product->set_virtual( true );
		$pid = $product->save();
		$state['posts'][] = $pid;
		update_option( $option, $state );
		$payload = "  Alpha <strong>bold</strong> &amp; &lt;tag&gt; &#169; \"quotes\" 'single' & raw\nSecond\tline [opfct_unregistered]\n<script>window.opfctExecuted=1</script>Tail %41  ";
		$raw = [ 'id' => 'p_' . $pid, 'type' => 'wapf_product', 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => 'true' ], 'fields' => [], 'conditions' => [] ];
		foreach ( [ 'gate' => 'text', 'current' => 'content', 'legacy' => 'paragraph' ] as $id => $type ) {
			$field = [ 'id' => $id, 'type' => $type, 'label' => 'gate' === $id ? 'Type show' : '', 'width' => 100, 'description' => '', 'required' => false, 'class' => '', 'conditionals' => [], 'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ] ];
			if ( 'gate' !== $id ) {
				$field['p_content'] = $payload;
				$field['conditionals'] = [ [ 'rules' => [ [ 'field' => 'gate', 'condition' => '==', 'value' => 'show' ] ] ] ];
			}
			$raw['fields'][] = $field;
		}
		$model = SW_WAPF\Includes\Classes\Field_Groups::raw_json_to_field_group( $raw );
		$stored = $model->to_array();
		// Imported raw stored records bypass admin sanitization: retain one probe to
		// distinguish render-time escaping from mapper's review-required stripping.
		$probe = $stored['fields'][1];
		$probe['id'] = 'storedraw';
		$probe['conditionals'] = [];
		$probe['options']['p_content'] = '<b>raw markup</b> &amp; <script>window.opfctExecuted=2</script>';
		$stored['fields'][] = $probe;
		update_post_meta( $pid, '_wapf_fieldgroup', $stored );
		$mapped = OPF\Engine\WapfMapper::map( $stored );
		$mapped['group']['rule_groups'] = [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ];
		$gid = OPF\Service\FieldGroups::save( 0, $mapped['group'], [ 'title' => 'OPF Content Text proof 20261003', 'status' => 'publish' ] );
		$state['posts'][] = $gid;
		update_option( $option, $state );
		$page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'OPF Content Text host 20261003', 'post_content' => '[product_page id="' . $pid . '"]' ] );
		if ( ! $page || is_wp_error( $page ) ) { throw new RuntimeException( 'Host page failed.' ); }
		$state['posts'][] = $page;
		$checks = [];
		opfct_assert( $stored['fields'][1]['options']['p_content'] === sanitize_textarea_field( $payload ), 'content admin save uses sanitize_textarea_field', $checks );
		opfct_assert( $stored['fields'][2]['options']['p_content'] === sanitize_textarea_field( $payload ), 'legacy paragraph admin save uses sanitize_textarea_field', $checks );
		opfct_assert( false === strpos( $stored['fields'][1]['options']['p_content'], '<strong>' ) && false === strpos( $stored['fields'][1]['options']['p_content'], 'opfctExecuted' ), 'admin save strips markup and script body', $checks );
		opfct_assert( false !== strpos( $stored['fields'][1]['options']['p_content'], "\nSecond\tline" ) && false === strpos( $stored['fields'][1]['options']['p_content'], '%41' ), 'admin save preserves newline/tab and removes percent octet', $checks );
		opfct_assert( $mapped['needs_review'] && false === strpos( $mapped['group']['fields'][3]['content'], '<b>' ), 'raw markup import is stripped and explicitly review-required', $checks );
		$state += [ 'page' => $page, 'product' => $pid, 'group' => $gid, 'expected_html' => esc_html( sanitize_textarea_field( $payload ) ), 'raw_expected_html' => esc_html( $probe['options']['p_content'] ), 'opf_ids' => array_column( $mapped['group']['fields'], 'id' ), 'checks' => $checks, 'runtime' => [ 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'free' => $free['Version'], 'opf_dir' => realpath( OPF_DIR ), 'renderer_sha256' => hash_file( 'sha256', OPF_DIR . 'includes/Service/Renderer.php' ), 'mapper_sha256' => hash_file( 'sha256', OPF_DIR . 'includes/Engine/WapfMapper.php' ), 'free_html_sha256' => hash_file( 'sha256', WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce/includes/classes/class-html.php' ) ] ];
		update_option( $option, $state );
		file_put_contents( $out . '/state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) );
		echo "ok fixture created\n";
	} catch ( Throwable $e ) {
		foreach ( $state['posts'] as $id ) { wp_delete_post( $id, true ); }
		delete_option( $option );
		throw $e;
	}
	return;
}
if ( 'cleanup' === $phase ) {
	$state = get_option( $option );
	if ( ! is_array( $state ) ) { throw new RuntimeException( 'No owned fixture state.' ); }
	foreach ( $state['posts'] as $id ) { wp_delete_post( $id, true ); }
	delete_option( $option );
	$after = opfct_inventory();
	$remaining = array_values( array_filter( $state['posts'], static function ( $id ) { return null !== get_post( $id ); } ) );
	$result = [ 'time' => gmdate( 'c' ), 'baseline' => $state['baseline'], 'after' => $after, 'remaining_posts' => $remaining, 'option_absent' => false === get_option( $option ), 'restored' => $after === $state['baseline'] && [] === $remaining && false === get_option( $option ) ];
	file_put_contents( $out . '/cleanup.json', wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	if ( ! $result['restored'] ) { throw new RuntimeException( 'Baseline restoration failed.' ); }
	echo "ok fixture posts/meta/option removed; baseline plugin and site state restored\n";
	return;
}
throw new RuntimeException( 'Set OPF_CONTENT_TEXT_PHASE=setup or cleanup.' );
