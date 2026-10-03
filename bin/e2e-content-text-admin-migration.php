<?php
/** Guarded Free 1.7.1 admin/model migration proof. No native Free Tools UI exists. */
use SW_WAPF\Includes\Classes\Field_Groups;
if ( '1' !== getenv( 'OPFCTM_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) || ! defined( 'FQDB' ) || 0 !== strpos( realpath( FQDB ), realpath( ABSPATH ) . '/' ) ) { throw new RuntimeException( 'Independent authorized /tmp SQLite clone required.' ); }
if ( ! class_exists( Field_Groups::class ) || ! class_exists( 'OPF\Service\FieldGroups' ) || class_exists( 'SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) { throw new RuntimeException( 'Free and OPF only.' ); }
$free = WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce/';
if ( '1.7.1' !== get_plugin_data( $free . 'advanced-product-fields-for-woocommerce.php' )['Version'] ) { throw new RuntimeException( 'Free 1.7.1 required.' ); }
$out = getenv( 'OPFCTM_OUT' );
if ( ! $out || 0 !== strpos( realpath( $out ), '/tmp/' ) ) { throw new RuntimeException( 'Existing /tmp output required.' ); }
$key = 'opfctm_fixture_20261003';
$phase = getenv( 'OPFCTM_PHASE' );
function opfctm_inventory() {
	global $wpdb;
	return [ 'posts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), 'postmeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ), 'users' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ), 'usermeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" ), 'plugins' => get_option( 'active_plugins' ), 'home' => get_option( 'home' ), 'siteurl' => get_option( 'siteurl' ) ];
}
function opfctm_record( $state, $key, $out ) { update_option( $key, $state, false ); file_put_contents( $out . '/state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) ); chmod( $out . '/state.json', 0600 ); }
if ( 'setup' === $phase ) {
	if ( get_option( $key ) ) { throw new RuntimeException( 'Existing fixture.' ); }
	$state = [ 'baseline' => opfctm_inventory(), 'posts' => [], 'user' => 0 ];
	opfctm_record( $state, $key, $out );
	$password = wp_generate_password( 30, true );
	$user = wp_insert_user( [ 'user_login' => 'opfctm_20261003', 'user_pass' => $password, 'role' => 'administrator', 'user_email' => 'opfctm@example.invalid' ] );
	if ( is_wp_error( $user ) ) { throw new RuntimeException( 'Fixture user creation failed.' ); }
	$state['user'] = $user;
	$state['login'] = 'opfctm_20261003';
	$state['password'] = $password;
	opfctm_record( $state, $key, $out );
	$raw = [ 'id' => '0', 'type' => 'wapf_product', 'fields' => [], 'conditions' => [], 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => 'true' ] ];
	foreach ( [ 'current' => 'content', 'legacy' => 'paragraph' ] as $id => $type ) { $raw['fields'][] = [ 'id' => $id, 'type' => $type, 'label' => $id, 'p_content' => 'Initial ' . $id, 'required' => false, 'width' => 100, 'class' => '', 'description' => '', 'conditionals' => [], 'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ] ]; }
	$model = Field_Groups::raw_json_to_field_group( $raw );
	$source = Field_Groups::save( $model, 'wapf_product', null, 'OPFCTM source 20261003', 'publish' );
	$state['source'] = $source;
	$state['posts'][] = $source;
	opfctm_record( $state, $key, $out );
	$mapped = OPF\Engine\WapfMapper::map( $model->to_array() );
	$opf = OPF\Service\FieldGroups::save( 0, $mapped['group'], [ 'title' => 'OPFCTM admin 20261003', 'status' => 'publish' ] );
	$state['opf'] = $opf;
	$state['posts'][] = $opf;
	$state['runtime'] = [ 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'free' => '1.7.1', 'opf' => OPF_VERSION, 'opf_dir' => realpath( OPF_DIR ), 'database' => realpath( FQDB ) ];
	$state['hashes'] = [];
	foreach ( [ 'includes/Service/Rest.php', 'includes/Engine/WapfMapper.php', 'includes/Engine/WapfParser.php', 'includes/Service/WapfExporter.php', 'assets/js/opf-builder.js' ] as $file ) { $state['hashes']['opf/' . $file] = hash_file( 'sha256', OPF_DIR . $file ); }
	foreach ( [ 'includes/controllers/class-admin-controller.php', 'includes/classes/class-field-groups.php', 'assets/js/admin.min.js' ] as $file ) { $state['hashes']['free/' . $file] = hash_file( 'sha256', $free . $file ); }
	opfctm_record( $state, $key, $out );
	echo "ok setup\n";
	return;
}
$state = get_option( $key );
if ( ! is_array( $state ) ) { throw new RuntimeException( 'Missing fixture.' ); }
if ( 'migrate' === $phase ) {
	$model = Field_Groups::get_by_id( $state['source'] );
	$payload = [ 'fields' => Field_Groups::field_group_to_raw_fields_json( $model ), 'conditions' => [], 'layout' => $model->layout, 'variables' => [] ];
	file_put_contents( $out . '/free-model-json.json', wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
	$parsed = OPF\Engine\WapfParser::parse( wp_json_encode( $payload ) );
	$mapped = OPF\Engine\WapfMapper::map( $parsed );
	$state['imported'] = OPF\Service\FieldGroups::save( 0, $mapped['group'], [ 'title' => 'OPFCTM model import 20261003', 'status' => 'publish' ] );
	$state['posts'][] = $state['imported'];
	$state['free_saved'] = array_column( $payload['fields'], 'p_content' );
	$state['free_saved_types'] = array_column( $payload['fields'], 'type' );
	$state['opf_imported'] = array_column( $mapped['group']['fields'], 'content' );
	$state['needs_review'] = $mapped['needs_review'];
	opfctm_record( $state, $key, $out );
	if ( $state['free_saved'] !== $state['opf_imported'] || $mapped['needs_review'] ) { throw new RuntimeException( 'Saved plain content model import lost fidelity.' ); }
	echo "ok Free model JSON parser/import fidelity\n";
	return;
}
if ( 'reverse' === $phase ) {
	$payload = json_decode( file_get_contents( $out . '/opf-wapf-export.json' ), true );
	$payload['id'] = '0';
	$payload['type'] = 'wapf_product';
	$model = Field_Groups::raw_json_to_field_group( $payload );
	$state['reverse'] = Field_Groups::save( $model, 'wapf_product', null, 'OPFCTM reverse 20261003', 'publish' );
	$state['posts'][] = $state['reverse'];
	$stored = Field_Groups::get_by_id( $state['reverse'] );
	$state['reverse_values'] = array_map( static function ( $field ) { return $field->options['p_content']; }, $stored->fields );
	opfctm_record( $state, $key, $out );
	if ( $state['reverse_values'] !== $state['free_saved'] ) { throw new RuntimeException( 'OPF export to Free model lost p_content fidelity.' ); }
	echo "ok OPF CLI export to Free model persistence fidelity\n";
	return;
}
if ( 'cleanup' === $phase ) {
	// WordPress can create an auto-draft for the fixture administrator when the
	// classic admin is opened. Remove only posts authored by this disposable user
	// that were not created/tracked by the fixture itself.
	global $wpdb;
	$fixture_authored_posts = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", (int) $state['user'] ) );
	foreach ( $fixture_authored_posts as $id ) {
		if ( ! in_array( (int) $id, array_map( 'intval', $state['posts'] ), true ) ) {
			$revisions = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d", (int) $id ) );
			foreach ( $revisions as $revision_id ) {
				wp_delete_post( (int) $revision_id, true );
			}
			wp_delete_post( (int) $id, true );
		}
	}
	foreach ( $state['posts'] as $id ) { wp_delete_post( $id, true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $state['user'] );
	delete_option( $key );
	unset( $state['password'], $state['login'] );
	file_put_contents( $out . '/state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) );
	$after = opfctm_inventory();
	$result = [ 'time' => gmdate( 'c' ), 'baseline' => $state['baseline'], 'after' => $after, 'fixture_posts_absent' => count( array_filter( $state['posts'], 'get_post' ) ) === 0, 'fixture_user_absent' => ! get_user_by( 'id', $state['user'] ), 'option_absent' => ! get_option( $key ) ];
	$result['restored'] = $after === $state['baseline'] && $result['fixture_posts_absent'] && $result['fixture_user_absent'] && $result['option_absent'];
	file_put_contents( $out . '/cleanup.json', wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	if ( ! $result['restored'] ) { throw new RuntimeException( 'Cleanup baseline differs.' ); }
	echo "ok cleanup and baseline restored\n";
	return;
}
throw new RuntimeException( 'Phase must be setup, migrate, reverse or cleanup.' );
