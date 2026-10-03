<?php
/**
 * Disposable real rules/conditions lifecycle proof; setup, commerce, wapf_setup,
 * wapf_assert, wapf_cleanup, cleanup phases. Covers group placement subjects
 * (category, tag exclusion, product type, attribute, auth, role, language,
 * global, OR groups) end-to-end: for_product matching, classic + Store API
 * cart/order capture, and WAPF Extended 3.1.5 reference comparison.
 */
if ( '1' !== getenv( 'OPF_RULES_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Explicit disposable /tmp authorization required.' );
}
function rules_check( string $name, bool $pass ): void {
	if ( ! $pass ) { throw new RuntimeException( $name ); }
	echo "ok $name\n";
}
$phase = getenv( 'OPF_RULES_E2E_PHASE' ) ?: 'commerce';
$state = get_option( 'opf_rules_e2e_state', [] );
$artifact_dir = getenv( 'OPF_RULES_ARTIFACT_DIR' ) ?: '/tmp/opf-rules-artifacts';
$state_file   = getenv( 'OPF_RULES_STATE_FILE' ) ?: '/tmp/opf-rules-state.json';

/** Group spec shared by OPF fixture, expectation matrix and WAPF mapping. */
function rules_group_specs( array $state ): array {
	// WAPF admin serializes select2 values as [{id,text}] objects; scalar
	// values in rules that "require frontend validation" (var_att subject)
	// crash Conditions::get_frontend_conditions() with a TypeError.
	$w = static function ( $id, string $text ): array {
		return [ 'id' => (string) $id, 'text' => $text ];
	};
	return [
		'gCat'        => [ 'label' => 'Cat A only',        'opf' => [ [ 'rules' => [ [ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ (string) $state['catA'] ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => 'product_cats', 'value' => [ $w( $state['catA'], 'Cat A' ) ], 'subject' => 'product_cat' ] ] ] ] ],
		'gCatAny'     => [ 'label' => 'Cat A or B',        'opf' => [ [ 'rules' => [ [ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ (string) $state['catA'], (string) $state['catB'] ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => 'product_cats', 'value' => [ $w( $state['catA'], 'Cat A' ), $w( $state['catB'], 'Cat B' ) ], 'subject' => 'product_cat' ] ] ] ] ],
		'gCatTagExcl' => [ 'label' => 'Cat A minus tag B', 'opf' => [ [ 'rules' => [ [ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ (string) $state['catA'] ] ], [ 'subject' => 'product_tag', 'operator' => 'not_in', 'terms' => [ (string) $state['tagB'] ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => 'product_cats', 'value' => [ $w( $state['catA'], 'Cat A' ) ], 'subject' => 'product_cat' ], [ 'condition' => '!p_tags', 'value' => [ $w( $state['tagB'], 'Tag B' ) ], 'subject' => 'product_tag' ] ] ] ] ],
		'gCatExclCat' => [ 'label' => 'Cat A+B minus A',   'opf' => [ [ 'rules' => [ [ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ (string) $state['catA'], (string) $state['catB'] ] ], [ 'subject' => 'product_cat', 'operator' => 'not_in', 'terms' => [ (string) $state['catA'] ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => 'product_cats', 'value' => [ $w( $state['catA'], 'Cat A' ), $w( $state['catB'], 'Cat B' ) ], 'subject' => 'product_cat' ], [ 'condition' => '!product_cats', 'value' => [ $w( $state['catA'], 'Cat A' ) ], 'subject' => 'product_cat' ] ] ] ] ],
		'gType'       => [ 'label' => 'External only',     'opf' => [ [ 'rules' => [ [ 'subject' => 'product_type', 'operator' => 'in', 'terms' => [ 'external' ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => 'product_type', 'value' => [ $w( 'external', 'External product' ) ], 'subject' => 'product_type' ] ] ] ] ],
		'gTypeVar'    => [ 'label' => 'Variable only',     'opf' => [ [ 'rules' => [ [ 'subject' => 'product_type', 'operator' => 'in', 'terms' => [ 'variable' ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => 'product_type', 'value' => [ $w( 'variable', 'Variable product' ) ], 'subject' => 'product_type' ] ] ] ] ],
		'gTypeNot'    => [ 'label' => 'Not external',      'opf' => [ [ 'rules' => [ [ 'subject' => 'product_type', 'operator' => 'not_in', 'terms' => [ 'external' ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => '!product_type', 'value' => [ $w( 'external', 'External product' ) ], 'subject' => 'product_type' ] ] ] ] ],
		'gAttr'       => [ 'label' => 'Red only',          'opf' => [ [ 'rules' => [ [ 'subject' => 'pa_color', 'operator' => 'in', 'terms' => [ (string) $state['red'] ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => 'patts', 'value' => [ $w( 'color|red', 'Color: Red' ) ], 'subject' => 'var_att' ] ] ] ] ],
		'gAttrNot'    => [ 'label' => 'Not red',           'opf' => [ [ 'rules' => [ [ 'subject' => 'pa_color', 'operator' => 'not_in', 'terms' => [ (string) $state['red'] ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => '!patts', 'value' => [ $w( 'color|red', 'Color: Red' ) ], 'subject' => 'var_att' ] ] ] ] ],
		'gAuthIn'     => [ 'label' => 'Logged-in only',    'opf' => [ [ 'rules' => [ [ 'subject' => 'user_auth', 'operator' => 'in', 'terms' => [ 'logged_in' ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => 'auth', 'value' => '', 'subject' => 'auth' ] ] ] ] ],
		'gAuthOut'    => [ 'label' => 'Logged-out only',   'opf' => [ [ 'rules' => [ [ 'subject' => 'user_auth', 'operator' => 'not_in', 'terms' => [ 'logged_in' ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => '!auth', 'value' => '', 'subject' => 'auth' ] ] ] ] ],
		'gRoleEd'     => [ 'label' => 'Editor only',       'opf' => [ [ 'rules' => [ [ 'subject' => 'user_role', 'operator' => 'in', 'terms' => [ 'editor' ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => 'role', 'value' => 'editor', 'subject' => 'role' ] ] ] ] ],
		'gRoleNotAdm' => [ 'label' => 'Not administrator', 'opf' => [ [ 'rules' => [ [ 'subject' => 'user_role', 'operator' => 'not_in', 'terms' => [ 'administrator' ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => '!role', 'value' => 'administrator', 'subject' => 'role' ] ] ] ] ],
		'gLang'       => [ 'label' => 'French only',       'opf' => [ [ 'rules' => [ [ 'subject' => 'user_language', 'operator' => 'in', 'terms' => [ 'fr_FR' ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => 'lang', 'value' => 'fr_FR', 'subject' => 'lang' ] ] ] ] ],
		'gLangNot'    => [ 'label' => 'Not French',        'opf' => [ [ 'rules' => [ [ 'subject' => 'user_language', 'operator' => 'not_in', 'terms' => [ 'fr_FR' ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => '!lang', 'value' => 'fr_FR', 'subject' => 'lang' ] ] ] ] ],
		'gGlobal'     => [ 'label' => 'Everywhere',        'opf' => [], 'wapf' => [] ],
		'gOr'         => [ 'label' => 'Cat B or external', 'opf' => [ [ 'rules' => [ [ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ (string) $state['catB'] ] ] ] ], [ 'rules' => [ [ 'subject' => 'product_type', 'operator' => 'in', 'terms' => [ 'external' ] ] ] ] ],
			'wapf' => [ [ 'rules' => [ [ 'condition' => 'product_cats', 'value' => [ $w( $state['catB'], 'Cat B' ) ], 'subject' => 'product_cat' ] ] ], [ 'rules' => [ [ 'condition' => 'product_type', 'value' => [ $w( 'external', 'External product' ) ], 'subject' => 'product_type' ] ] ] ] ],
		'gAdminEdit'  => [ 'label' => 'Admin edited',      'opf' => [ [ 'rules' => [ [ 'subject' => 'product_cat', 'operator' => 'not_in', 'terms' => [ (string) $state['catA'] ] ] ] ] ],
			'wapf' => null ],
		'gLangPostFr' => [ 'label' => 'FR post language',  'opf' => [], 'wapf' => null, 'post_lang' => 'fr' ],
	];
}

/** Expected matched group keys per product per viewer context (en unless noted). */
function rules_expected_matrix(): array {
	$base      = [ 'gGlobal' ];
	$auth_out  = [ 'gAuthOut', 'gRoleNotAdm', 'gLangNot' ];
	$editor    = [ 'gAuthIn', 'gRoleEd', 'gRoleNotAdm', 'gLangNot' ];
	$admin     = [ 'gAuthIn', 'gLangNot' ];
	return [
		'anon' => [
			'pA'    => array_merge( [ 'gCat', 'gCatAny', 'gTypeNot', 'gAttr' ], $auth_out, $base ),
			'pA2'   => array_merge( [ 'gCat', 'gCatAny', 'gCatTagExcl', 'gTypeNot', 'gAttrNot' ], $auth_out, $base ),
			'pB'    => array_merge( [ 'gCatAny', 'gCatExclCat', 'gTypeNot', 'gAttrNot' ], $auth_out, [ 'gOr', 'gAdminEdit' ], $base ),
			'pExt'  => array_merge( [ 'gCatAny', 'gCatExclCat', 'gType', 'gAttrNot' ], $auth_out, [ 'gOr', 'gAdminEdit' ], $base ),
			'pBlue' => array_merge( [ 'gTypeNot', 'gAttrNot' ], $auth_out, [ 'gAdminEdit' ], $base ),
			'pVar'  => array_merge( [ 'gTypeVar', 'gTypeNot', 'gAttrNot' ], $auth_out, [ 'gAdminEdit' ], $base ),
		],
		'editor' => [
			'pA'    => array_merge( [ 'gCat', 'gCatAny', 'gTypeNot', 'gAttr' ], $editor, $base ),
			'pA2'   => array_merge( [ 'gCat', 'gCatAny', 'gCatTagExcl', 'gTypeNot', 'gAttrNot' ], $editor, $base ),
			'pBlue' => array_merge( [ 'gTypeNot', 'gAttrNot' ], $editor, [ 'gAdminEdit' ], $base ),
		],
		'admin' => [
			'pA'    => array_merge( [ 'gCat', 'gCatAny', 'gTypeNot', 'gAttr' ], $admin, $base ),
			'pA2'   => array_merge( [ 'gCat', 'gCatAny', 'gCatTagExcl', 'gTypeNot', 'gAttrNot' ], $admin, $base ),
			'pBlue' => array_merge( [ 'gTypeNot', 'gAttrNot' ], $admin, [ 'gAdminEdit' ], $base ),
		],
		'anon_fr' => [
			'pA'    => array_merge( [ 'gCat', 'gCatAny', 'gTypeNot', 'gAttr' ], [ 'gAuthOut', 'gRoleNotAdm', 'gLang' ], $base, [ 'gLangPostFr' ] ),
			'pAFr'  => array_merge( [ 'gCat', 'gCatAny', 'gTypeNot', 'gAttr' ], [ 'gAuthOut', 'gRoleNotAdm', 'gLang' ], $base, [ 'gLangPostFr' ] ),
			'pBlue' => array_merge( [ 'gTypeNot', 'gAttrNot' ], [ 'gAuthOut', 'gRoleNotAdm', 'gLang' ], [ 'gAdminEdit' ], $base, [ 'gLangPostFr' ] ),
		],
	];
}

/** Sorted group ids matched by the real production path. */
function rules_for_product_ids( array $state, int $product_id, int $user_id, string $lang_slug ): array {
	wp_set_current_user( $user_id );
	if ( function_exists( 'pll_languages_list' ) ) {
		PLL()->curlang = 'none' === $lang_slug ? null : PLL()->model->get_language( $lang_slug );
	}
	$ids = array_map( static function ( $entry ) { return (int) $entry['id']; }, OPF\Service\FieldGroups::for_product( wc_get_product( $product_id ) ) );
	sort( $ids );
	return $ids;
}

/** Map expected group keys to sorted ids. */
function rules_expected_ids( array $state, array $keys ): array {
	$ids = array_map( static function ( $key ) use ( $state ) { return (int) $state['groups'][ $key ]; }, $keys );
	sort( $ids );
	return $ids;
}

/** Build the opf[gid][fid] payload for the full matched set of a context. */
function rules_matched_payload( array $state, array $group_keys ): array {
	$payload = [];
	foreach ( $group_keys as $key ) {
		$payload[ (string) $state['groups'][ $key ] ] = [ 'f_' . strtolower( $key ) => 'Rules ' . $key ];
	}
	ksort( $payload );
	return $payload;
}

/** Key-order-insensitive comparison for stored value maps. */
function rules_same_map( $a, array $b ): bool {
	if ( ! is_array( $a ) ) { return false; }
	ksort( $a );
	return $a === $b;
}

if ( 'setup' === $phase ) {
	rules_check( 'fixture is new', ! $state );
	$state['baseline'] = [
		'products'    => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product'" ),
		'variations'  => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product_variation'" ),
		'users'       => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users}" ),
		'groups'      => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='opf_field_group'" ),
		'wapf_posts'  => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='wapf_product'" ),
		'plugins'     => get_option( 'active_plugins' ),
		'orders'      => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ),
		'opf_admin_only' => get_option( 'opf_admin_only', 'no' ),
		'bacs'        => get_option( 'woocommerce_bacs_settings', [] ),
		'term_counts' => [
			'product_cat'       => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->term_taxonomy} WHERE taxonomy='product_cat'" ),
			'product_tag'       => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->term_taxonomy} WHERE taxonomy='product_tag'" ),
			'post_translations' => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->term_taxonomy} WHERE taxonomy='post_translations'" ),
		],
		'attributes'  => count( (array) wc_get_attribute_taxonomies() ),
	];
	// Terms.
	$insert_term = static function ( string $name, string $taxonomy ): int {
		$created = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $created ) ) { throw new RuntimeException( $taxonomy . ' term failed: ' . $created->get_error_message() ); }
		return (int) $created['term_id'];
	};
	$state['catA'] = $insert_term( 'OPF rules cat A', 'product_cat' );
	$state['catB'] = $insert_term( 'OPF rules cat B', 'product_cat' );
	$state['tagA'] = $insert_term( 'OPF rules tag A', 'product_tag' );
	$state['tagB'] = $insert_term( 'OPF rules tag B', 'product_tag' );
	// Attribute taxonomy: registered at init by Woo from the attribute table;
	// register it for this request so terms/assignments work immediately.
	$state['attr_id'] = wc_create_attribute( [ 'name' => 'Color', 'slug' => 'color', 'type' => 'select', 'orderby' => 'menu_order', 'has_archives' => false ] );
	rules_check( 'color attribute created', is_int( $state['attr_id'] ) && $state['attr_id'] > 0 );
	if ( ! taxonomy_exists( 'pa_color' ) ) {
		register_taxonomy( 'pa_color', [ 'product' ] );
	}
	rules_check( 'pa_color registered', taxonomy_exists( 'pa_color' ) );
	$state['red']  = $insert_term( 'red', 'pa_color' );
	$state['blue'] = $insert_term( 'blue', 'pa_color' );
	// Products.
	$simple = static function ( string $name, string $slug ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name ); $p->set_slug( $slug ); $p->set_status( 'publish' );
		$p->set_virtual( true ); $p->set_regular_price( '10' );
		return (int) $p->save();
	};
	// Assign a global attribute the way the product editor does: _product_attributes
	// meta (read by WAPF's resolver) plus object terms (read by wc_get_product_term_ids).
	$attach_attr = static function ( int $product_id, int $term_id ): void {
		$product = wc_get_product( $product_id );
		$attributes = $product->get_attributes();
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( (int) wc_attribute_taxonomy_id_by_name( 'pa_color' ) );
		$attribute->set_name( 'pa_color' );
		$attribute->set_options( [ $term_id ] );
		$attribute->set_visible( true );
		$attribute->set_variation( false );
		$attributes['pa_color'] = $attribute;
		$product->set_attributes( $attributes );
		$product->save();
		wp_set_object_terms( $product_id, [ $term_id ], 'pa_color' );
	};
	$state['pA']    = $simple( 'OPF rules simple A', 'opf-rules-simple-a' );
	wp_set_object_terms( $state['pA'], [ $state['catA'] ], 'product_cat' );
	wp_set_object_terms( $state['pA'], [ $state['tagA'], $state['tagB'] ], 'product_tag' );
	$attach_attr( $state['pA'], $state['red'] );
	$state['pA2']   = $simple( 'OPF rules simple A2', 'opf-rules-simple-a2' );
	wp_set_object_terms( $state['pA2'], [ $state['catA'] ], 'product_cat' );
	wp_set_object_terms( $state['pA2'], [ $state['tagA'] ], 'product_tag' );
	$state['pB']    = $simple( 'OPF rules cat B', 'opf-rules-cat-b' );
	wp_set_object_terms( $state['pB'], [ $state['catB'] ], 'product_cat' );
	$ext = new WC_Product_External();
	$ext->set_name( 'OPF rules external' ); $ext->set_slug( 'opf-rules-external' );
	$ext->set_status( 'publish' ); $ext->set_regular_price( '10' ); $ext->set_product_url( 'http://example.invalid/buy' );
	$state['pExt']  = (int) $ext->save();
	wp_set_object_terms( $state['pExt'], [ $state['catB'] ], 'product_cat' );
	$state['pBlue'] = $simple( 'OPF rules blue', 'opf-rules-blue' );
	$attach_attr( $state['pBlue'], $state['blue'] );
	$var = new WC_Product_Variable();
	$var->set_name( 'OPF rules variable' ); $var->set_slug( 'opf-rules-variable' ); $var->set_status( 'publish' );
	$state['pVar'] = (int) $var->save();
	$child = new WC_Product_Variation();
	$child->set_parent_id( $state['pVar'] ); $child->set_regular_price( '10' ); $child->set_status( 'publish' ); $child->set_virtual( true );
	$state['pVariation'] = (int) $child->save();
	WC_Product_Variable::sync( $state['pVar'] );
	$state['pAFr']  = $simple( 'OPF règles simple A', 'opf-rules-simple-a-fr' );
	wp_set_object_terms( $state['pAFr'], [ $state['catA'] ], 'product_cat' );
	wp_set_object_terms( $state['pAFr'], [ $state['tagA'], $state['tagB'] ], 'product_tag' );
	$attach_attr( $state['pAFr'], $state['red'] );
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $state['pA'], 'en' );
		pll_set_post_language( $state['pAFr'], 'fr' );
		pll_save_post_translations( [ 'en' => $state['pA'], 'fr' => $state['pAFr'] ] );
	}
	// Pre-existing published groups would pollute the visibility matrix; draft
	// them for the run and republish during cleanup.
	$state['baseline_groups'] = [];
	foreach ( OPF\Service\FieldGroups::all() as $entry ) {
		$state['baseline_groups'][ (int) $entry['id'] ] = 'publish';
		wp_update_post( [ 'ID' => (int) $entry['id'], 'post_status' => 'draft' ] );
	}
	OPF\Service\FieldGroups::flush_cache();
	// Groups.
	$state['groups'] = [];
	foreach ( rules_group_specs( $state ) as $key => $spec ) {
		$state['groups'][ $key ] = OPF\Service\FieldGroups::save(
			0,
			[ 'fields' => [ [ 'id' => 'f_' . strtolower( $key ), 'type' => 'text', 'label' => $spec['label'], 'required' => true ] ], 'rule_groups' => $spec['opf'] ],
			[ 'title' => 'OPF rules ' . $key, 'status' => 'publish' ]
		);
		rules_check( "$key saved", $state['groups'][ $key ] > 0 );
		if ( ! empty( $spec['post_lang'] ) && function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $state['groups'][ $key ], $spec['post_lang'] );
		}
	}
	// Users for session variants.
	$state['users'] = [];
	foreach ( [ 'editor' => 'editor', 'admin' => 'administrator' ] as $login => $role ) {
		$password = wp_generate_password( 24, false );
		$uid = wp_insert_user( [ 'user_login' => 'opf_rules_' . $login, 'user_pass' => $password, 'user_email' => 'rules-' . $login . '@example.invalid', 'role' => $role ] );
		rules_check( "user $login created", ! is_wp_error( $uid ) );
		$state['users'][ $login ] = [ 'id' => (int) $uid, 'login' => 'opf_rules_' . $login ];
		$state['logins'][ $login ] = [ 'user' => 'opf_rules_' . $login, 'password' => $password ];
	}
	$state['permalinks'] = [];
	foreach ( [ 'pA', 'pA2', 'pB', 'pExt', 'pBlue', 'pVar', 'pAFr' ] as $key ) {
		$state['permalinks'][ $key ] = (string) get_permalink( $state[ $key ] );
	}
	$state['expected'] = rules_expected_matrix();
	$state['orders'] = [];
	update_option( 'opf_rules_e2e_state', $state );
	if ( ! is_dir( $artifact_dir ) ) { mkdir( $artifact_dir, 0755, true ); }
	file_put_contents( $state_file, wp_json_encode( array_diff_key( $state, [ 'logins' => 1 ] ) ) );
	file_put_contents( $artifact_dir . '/private-login.json', wp_json_encode( $state['logins'] ) );
	chmod( $artifact_dir . '/private-login.json', 0600 );
	echo "SUCCESS rules setup\n";
	return;
}

rules_check( 'fixture exists', ! empty( $state['groups']['gCat'] ) );

if ( 'commerce' === $phase ) {
	$expect = rules_expected_matrix();
	foreach ( $expect as $context => $products ) {
		$user_id = 'editor' === $context ? $state['users']['editor']['id'] : ( 'admin' === $context ? $state['users']['admin']['id'] : 0 );
		$lang    = 'anon_fr' === $context ? 'fr' : 'en';
		foreach ( $products as $pkey => $group_keys ) {
			$actual   = rules_for_product_ids( $state, (int) $state[ $pkey ], $user_id, $lang );
			$expected = rules_expected_ids( $state, $group_keys );
			rules_check( "$context $pkey for_product matches expected group set", $actual === $expected );
		}
	}
	// Variation object resolves parent product placement and parent type.
	$variation_match = rules_for_product_ids( $state, (int) $state['pVariation'], 0, 'en' );
	$parent_match    = rules_for_product_ids( $state, (int) $state['pVar'], 0, 'en' );
	rules_check( 'variation resolves to parent rules incl. variable type', $variation_match === $parent_match );
	// Classic add-to-cart: forged values for non-matching groups never substitute.
	if ( ! WC()->cart ) { wc_load_cart(); }
	$cart = WC()->cart;
	add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
	wp_set_current_user( 0 );
	if ( function_exists( 'pll_languages_list' ) ) { PLL()->curlang = PLL()->model->get_language( 'en' ); }
	$anon_pa_groups  = $expect['anon']['pA'];
	$cart->empty_cart();
	$_POST['opf'] = [ (string) $state['groups']['gAuthIn'] => [ 'f_gauthin' => 'forged auth' ] ];
	wc_clear_notices();
	rules_check( 'anon pA forged logged-in group alone fails required matched fields', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['pA'], 1 ) );
	$_POST['opf'] = rules_matched_payload( $state, $anon_pa_groups ) + $_POST['opf'];
	wc_clear_notices();
	rules_check( 'anon pA matched set plus forged group validates', true === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['pA'], 1 ) );
	$cartkey = $cart->add_to_cart( $state['pA'], 1 );
	rules_check( 'anon pA cart line keeps only matched groups, forged dropped', rules_same_map( $cart->get_cart_item( $cartkey )['opf_fields'], rules_matched_payload( $state, $anon_pa_groups ) ) );
	$cart->empty_cart();
	unset( $_POST['opf'] );
	// Logged-in editor: matched set changes; same product requires different fields.
	$editor_groups = $expect['editor']['pA'];
	$_POST['opf'] = rules_matched_payload( $state, $editor_groups );
	// Simulate the editor session for the validation path.
	wp_set_current_user( $state['users']['editor']['id'] );
	wc_clear_notices();
	rules_check( 'editor pA matched set validates (auth+role groups required)', true === apply_filters( 'woocommerce_add_to_cart_validation', true, $state['pA'], 1 ) );
	$editor_key = $cart->add_to_cart( $state['pA'], 1 );
	rules_check( 'editor pA cart line carries role/auth group values', rules_same_map( $cart->get_cart_item( $editor_key )['opf_fields'], rules_matched_payload( $state, $editor_groups ) ) );
	$cart->empty_cart();
	unset( $_POST['opf'] );
	wp_set_current_user( 0 );
	// Store API: forged-only fails, matched set flows through checkout to order meta.
	$dispatch = static function ( $path, $params ) {
		$r = new WP_REST_Request( 'POST', '/wc/store/v1/' . $path );
		$r->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
		return rest_get_server()->dispatch( $r );
	};
	$cart->empty_cart();
	$bad = $dispatch( 'cart/add-item', [ 'id' => $state['pA2'], 'quantity' => 1, 'opf_fields' => [ (string) $state['groups']['gType'] => [ 'f_gtype' => 'forged' ] ] ] );
	rules_check( 'anon Store API forged-only submission rejected', $bad->get_status() >= 400 && ! count( $cart->get_cart() ) );
	$good = $dispatch( 'cart/add-item', [ 'id' => $state['pA2'], 'quantity' => 1, 'opf_fields' => rules_matched_payload( $state, $expect['anon']['pA2'] ) ] );
	rules_check( 'anon Store API matched submission accepted', in_array( $good->get_status(), [ 200, 201 ], true ) );
	WC()->payment_gateways()->init();
	$checkout = $dispatch( 'checkout', [ 'payment_method' => 'bacs', 'billing_address' => [ 'first_name' => 'Rules', 'last_name' => 'Buyer', 'email' => 'rules@example.invalid', 'address_1' => '1 Rule Street', 'city' => 'Testville', 'postcode' => '90210', 'country' => 'US', 'state' => 'CA' ] ] );
	rules_check( 'anon Store API checkout succeeds', in_array( $checkout->get_status(), [ 200, 201 ], true ) );
	$order = wc_get_order( $checkout->get_data()['order_id'] );
	rules_check( 'order reloads', $order instanceof WC_Order );
	foreach ( $order->get_items() as $item ) {
		rules_check( 'order line stores only matched group values', rules_same_map( json_decode( $item->get_meta( '_opf_fields', true ), true ), rules_matched_payload( $state, $expect['anon']['pA2'] ) ) );
	}
	$state['orders'][] = $order->get_id();
	update_option( 'opf_rules_e2e_state', $state );
	$cart->empty_cart();
	wp_set_current_user( 0 );
	echo "SUCCESS rules commerce\n";
	return;
}

if ( 'wapf_setup' === $phase ) {
	$activated = activate_plugin( 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php', '', false, true );
	rules_check( 'WAPF Extended activated', ! is_wp_error( $activated ) && is_plugin_active( 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' ) );
	rules_check( 'WAPF Field_Groups class available', class_exists( 'SW_WAPF_PRO\Includes\Classes\Field_Groups' ) );
	$state['wapf'] = [];
	foreach ( rules_group_specs( $state ) as $key => $spec ) {
		if ( null === $spec['wapf'] ) { continue; }
		$raw = [
			'id' => 0, 'type' => 'wapf_product', 'layout' => [], 'variables' => [],
			'rule_groups' => $spec['wapf'],
			'fields' => [ [ 'id' => 'wrules' . strtolower( $key ), 'label' => 'WAPF ' . $spec['label'], 'description' => '', 'type' => 'text', 'required' => false, 'class' => '', 'width' => 100, 'options' => [], 'pricing' => [ 'enabled' => 'false', 'type' => 'fixed', 'amount' => 0 ], 'conditionals' => [] ] ],
		];
		$fg = SW_WAPF_PRO\Includes\Classes\Field_Groups::process_data( $raw );
		$state['wapf'][ $key ] = (int) SW_WAPF_PRO\Includes\Classes\Field_Groups::save( $fg, 'wapf_product', null, 'WAPF rules ' . $key, 'publish' );
		rules_check( "wapf $key saved", $state['wapf'][ $key ] > 0 );
	}
	// Extras exercised separately from the shared matrix.
	$state['wapf_extra'] = [];
	$extras = [
		'wAttrAny'    => [ [ 'rules' => [ [ 'condition' => 'patts', 'value' => [ [ 'id' => 'color|*', 'text' => 'Color: any' ] ], 'subject' => 'var_att' ] ] ] ],
		'wGlobalEmpty'=> [ [ 'rules' => [] ] ],
	];
	foreach ( $extras as $key => $rule_groups ) {
		$raw = [
			'id' => 0, 'type' => 'wapf_product', 'layout' => [], 'variables' => [],
			'rule_groups' => $rule_groups,
			'fields' => [ [ 'id' => 'wrulesx' . strtolower( $key ), 'label' => 'WAPF ' . $key, 'description' => '', 'type' => 'text', 'required' => false, 'class' => '', 'width' => 100, 'options' => [], 'pricing' => [ 'enabled' => 'false', 'type' => 'fixed', 'amount' => 0 ], 'conditionals' => [] ] ],
		];
		$fg = SW_WAPF_PRO\Includes\Classes\Field_Groups::process_data( $raw );
		$state['wapf_extra'][ $key ] = (int) SW_WAPF_PRO\Includes\Classes\Field_Groups::save( $fg, 'wapf_product', null, 'WAPF rules ' . $key, 'publish' );
		rules_check( "wapf extra $key saved", $state['wapf_extra'][ $key ] > 0 );
	}
	update_option( 'opf_rules_e2e_state', $state );
	file_put_contents( $state_file, wp_json_encode( array_diff_key( $state, [ 'logins' => 1 ] ) ) );
	echo "SUCCESS wapf setup\n";
	return;
}

if ( 'wapf_assert' === $phase ) {
	rules_check( 'WAPF active for assert', is_plugin_active( 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' ) );
	$all = SW_WAPF_PRO\Includes\Classes\Field_Groups::get_all();
	$groups_by_id = [];
	foreach ( $all as $fg ) { $groups_by_id[ (int) $fg->id ] = $fg; }
	$expect = rules_expected_matrix();
	// WAPF has no OPF-only groups; filter expectation to keys with a WAPF group.
	foreach ( $expect as $context => $products ) {
		$user_id = 'editor' === $context ? $state['users']['editor']['id'] : ( 'admin' === $context ? $state['users']['admin']['id'] : 0 );
		$lang    = 'anon_fr' === $context ? 'fr' : 'en';
		foreach ( $products as $pkey => $group_keys ) {
			wp_set_current_user( $user_id );
			if ( function_exists( 'pll_languages_list' ) ) { PLL()->curlang = PLL()->model->get_language( 'fr' === $lang ? 'fr' : 'en' ); }
			$product = wc_get_product( (int) $state[ $pkey ] );
			$actual = [];
			foreach ( $state['wapf'] as $key => $fg_id ) {
				if ( isset( $groups_by_id[ $fg_id ] ) && SW_WAPF_PRO\Includes\Classes\Conditions::is_field_group_valid_for_product( $groups_by_id[ $fg_id ], $product ) ) {
					$actual[] = $fg_id;
				}
			}
			sort( $actual );
			$expected = array_map( 'intval', array_values( array_intersect_key( $state['wapf'], array_flip( $group_keys ) ) ) );
			sort( $expected );
			rules_check( "wapf $context $pkey rule matrix matches", $actual === $expected );
		}
	}
	// Extra WAPF-only rule shapes: attribute wildcard + empty rule group.
	wp_set_current_user( 0 );
	if ( function_exists( 'pll_languages_list' ) ) { PLL()->curlang = PLL()->model->get_language( 'en' ); }
	foreach ( [ 'pA' => true, 'pBlue' => true, 'pA2' => false, 'pB' => false, 'pExt' => false ] as $pkey => $has_color ) {
		$valid = SW_WAPF_PRO\Includes\Classes\Conditions::is_field_group_valid_for_product( $groups_by_id[ (int) $state['wapf_extra']['wAttrAny'] ], wc_get_product( (int) $state[ $pkey ] ) );
		rules_check( "wapf patts wildcard on $pkey " . ( $has_color ? 'matches' : 'does not match' ), $valid === $has_color );
	}
	foreach ( [ 'pA', 'pA2', 'pB', 'pExt', 'pBlue', 'pVar' ] as $pkey ) {
		$valid = SW_WAPF_PRO\Includes\Classes\Conditions::is_field_group_valid_for_product( $groups_by_id[ (int) $state['wapf_extra']['wGlobalEmpty'] ], wc_get_product( (int) $state[ $pkey ] ) );
		rules_check( "wapf empty rule group still global on $pkey", true === $valid );
	}
	wp_set_current_user( 0 );
	echo "SUCCESS wapf assert\n";
	return;
}

if ( 'wapf_cleanup' === $phase ) {
	foreach ( (array) ( $state['wapf'] ?? [] ) as $fg_id ) { wp_delete_post( (int) $fg_id, true ); }
	foreach ( (array) ( $state['wapf_extra'] ?? [] ) as $fg_id ) { wp_delete_post( (int) $fg_id, true ); }
	unset( $state['wapf'], $state['wapf_extra'] );
	update_option( 'opf_rules_e2e_state', $state );
	deactivate_plugins( 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' );
	rules_check( 'WAPF deactivated', ! is_plugin_active( 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' ) );
	rules_check( 'wapf posts deleted', (int) $state['baseline']['wapf_posts'] === (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='wapf_product'" ) );
	echo "SUCCESS wapf cleanup\n";
	return;
}

if ( 'cleanup' === $phase ) {
	foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( in_array( $item->get_product_id(), [ $state['pA'], $state['pA2'], $state['pB'], $state['pExt'], $state['pBlue'], $state['pVar'], $state['pAFr'] ], true ) ) { $order->delete( true ); break; }
		}
	}
	foreach ( (array) $state['groups'] as $gid ) { wp_delete_post( (int) $gid, true ); }
	foreach ( (array) ( $state['wapf'] ?? [] ) as $gid ) { wp_delete_post( (int) $gid, true ); }
	foreach ( (array) ( $state['wapf_extra'] ?? [] ) as $gid ) { wp_delete_post( (int) $gid, true ); }
	foreach ( (array) ( $state['baseline_groups'] ?? [] ) as $gid => $status ) { wp_update_post( [ 'ID' => (int) $gid, 'post_status' => (string) $status ] ); }
	foreach ( [ 'pA', 'pA2', 'pB', 'pExt', 'pBlue', 'pVar', 'pVariation', 'pAFr' ] as $key ) { wp_delete_post( (int) $state[ $key ], true ); }
	foreach ( [ 'catA', 'catB' ] as $key ) { wp_delete_term( (int) $state[ $key ], 'product_cat' ); }
	foreach ( [ 'tagA', 'tagB' ] as $key ) { wp_delete_term( (int) $state[ $key ], 'product_tag' ); }
	if ( ! taxonomy_exists( 'pa_color' ) ) { register_taxonomy( 'pa_color', [ 'product' ] ); }
	wp_delete_term( (int) $state['red'], 'pa_color' );
	wp_delete_term( (int) $state['blue'], 'pa_color' );
	wc_delete_attribute( (int) $state['attr_id'] );
	// Polylang translation-set terms created by pll_save_post_translations.
	$extra_translations = get_terms( [ 'taxonomy' => 'post_translations', 'hide_empty' => false ] );
	foreach ( (array) $extra_translations as $term ) {
		$count = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->term_relationships} WHERE term_taxonomy_id=%d", $term->term_taxonomy_id ) );
		if ( 0 === $count && ! in_array( $term->name, [ 'en', 'fr' ], true ) ) {
			wp_delete_term( $term->term_id, 'post_translations' );
		}
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( (array) $state['users'] as $entry ) { wp_delete_user( (int) $entry['id'] ); }
	update_option( 'opf_admin_only', $state['baseline']['opf_admin_only'] );
	update_option( 'woocommerce_bacs_settings', $state['baseline']['bacs'] );
	delete_option( 'opf_rules_e2e_state' );
	@unlink( $artifact_dir . '/private-login.json' );
	@unlink( $state_file );
	$now = [
		'products'    => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product'" ),
		'variations'  => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product_variation'" ),
		'users'       => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users}" ),
		'groups'      => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='opf_field_group'" ),
		'wapf_posts'  => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='wapf_product'" ),
		'orders'      => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ),
		'term_counts' => [
			'product_cat'       => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->term_taxonomy} WHERE taxonomy='product_cat'" ),
			'product_tag'       => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->term_taxonomy} WHERE taxonomy='product_tag'" ),
			'post_translations' => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->term_taxonomy} WHERE taxonomy='post_translations'" ),
		],
		'attributes'  => count( (array) wc_get_attribute_taxonomies() ),
	];
	rules_check( 'baseline products/variations/users/groups/wapf restored',
		$now['products'] === $state['baseline']['products'] && $now['variations'] === $state['baseline']['variations']
		&& $now['users'] === $state['baseline']['users'] && $now['groups'] === $state['baseline']['groups']
		&& $now['wapf_posts'] === $state['baseline']['wapf_posts'] );
	rules_check( 'baseline orders/terms/attributes restored',
		$now['orders'] === $state['baseline']['orders'] && $now['term_counts'] === $state['baseline']['term_counts'] && $now['attributes'] === $state['baseline']['attributes'] );
	rules_check( 'active plugins restored', get_option( 'active_plugins' ) === $state['baseline']['plugins'] );
	rules_check( 'state option removed', ! get_option( 'opf_rules_e2e_state' ) );
	echo "SUCCESS rules cleanup\n";
	return;
}

throw new RuntimeException( 'Unknown phase ' . $phase );
