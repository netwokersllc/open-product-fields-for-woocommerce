<?php
/**
 * Disposable real proof: variation targeting (`product_var`/`var_att`) end to
 * end — group placement, generated per-field variation gates, variation-aware
 * visibility, classic cart capture, order-item meta, and WAPF Extended 3.1.5
 * reference parity for the same rule model.
 *
 * Phases: setup, assert, order, wapf_setup, wapf_assert, wapf_cleanup, cleanup.
 * Guarded to a /tmp WordPress clone; never touches anything else.
 */
if ( '1' !== getenv( 'OPF_VRULES_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Explicit disposable /tmp authorization required.' );
}

function vrules_check( string $name, bool $pass ): void {
	if ( ! $pass ) {
		throw new RuntimeException( $name );
	}
	echo "ok $name\n";
}

$phase = getenv( 'OPF_VRULES_E2E_PHASE' ) ?: 'assert';
$state = get_option( 'opf_vrules_e2e_state', [] );
$artifacts = getenv( 'OPF_VRULES_ARTIFACT_DIR' ) ?: '/tmp/opf-lane-variations-evidence/variation-rules';
$state_file = $artifacts . '/state.json';

$w = static function ( $id, string $text ): array {
	return [ 'id' => (string) $id, 'text' => $text ];
};

/** Group specs shared by OPF fixture, expectation, and WAPF reference. */
function vrules_specs( array $state ): array {
	$w = static function ( $id, string $text ): array {
		return [ 'id' => (string) $id, 'text' => $text ];
	};
	return [
		'vOnlyRed' => [
			'label' => 'Only red variation',
			'opf'   => [ [ 'rules' => [ [ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ (string) $state['red_var'] ] ] ] ] ],
			'wapf'  => [ [ 'rules' => [ [ 'condition' => 'product_var', 'value' => [ $w( $state['red_var'], 'Red variation' ) ], 'subject' => 'product_variation' ] ] ] ],
		],
		'vNotRed' => [
			'label' => 'Every variation except red',
			'opf'   => [ [ 'rules' => [ [ 'subject' => 'product_var', 'operator' => 'not_in', 'terms' => [ (string) $state['red_var'] ] ] ] ] ],
			'wapf'  => [ [ 'rules' => [ [ 'condition' => '!product_var', 'value' => [ $w( $state['red_var'], 'Red variation' ) ], 'subject' => 'product_variation' ] ] ] ],
		],
		'vAttrRed' => [
			'label' => 'Red attribute',
			'opf'   => [ [ 'rules' => [ [ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'opf_varcolor|red' ] ] ] ] ],
			'wapf'  => [ [ 'rules' => [ [ 'condition' => 'patts', 'value' => [ $w( 'opf_varcolor|red', 'Color: Red' ) ], 'subject' => 'var_att' ] ] ] ],
		],
		'vAttrAny' => [
			'label' => 'Any color attribute',
			'opf'   => [ [ 'rules' => [ [ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'opf_varcolor|*' ] ] ] ] ],
			'wapf'  => [ [ 'rules' => [ [ 'condition' => 'patts', 'value' => [ $w( 'opf_varcolor|*', 'Color: any' ) ], 'subject' => 'var_att' ] ] ] ],
		],
		'vAlways' => [
			'label' => 'Always (control)',
			'opf'   => [],
			'wapf'  => [],
		],
	];
}

function vrules_field_id( string $key ): string {
	return 'vr_' . strtolower( $key );
}

if ( 'setup' === $phase ) {
	vrules_check( 'fixture is new', empty( $state ) );
	$state['baseline'] = [
		'products'   => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product'" ),
		'variations' => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product_variation'" ),
		'groups'     => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='opf_field_group'" ),
		'wapf_posts' => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='wapf_product'" ),
		'orders'     => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ),
		'users'      => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users}" ),
		'attributes' => count( (array) wc_get_attribute_taxonomies() ),
		'plugins'    => (array) get_option( 'active_plugins', [] ),
	];

	$state['attr_id'] = wc_create_attribute( [ 'name' => 'OPF Var Color', 'slug' => 'opf_varcolor', 'type' => 'select', 'orderby' => 'menu_order', 'has_archives' => false ] );
	vrules_check( 'attribute created', is_int( $state['attr_id'] ) && $state['attr_id'] > 0 );
	if ( ! taxonomy_exists( 'pa_opf_varcolor' ) ) {
		register_taxonomy( 'pa_opf_varcolor', [ 'product' ] );
	}
	$red  = wp_insert_term( 'Red', 'pa_opf_varcolor' );
	$blue = wp_insert_term( 'Blue', 'pa_opf_varcolor' );
	vrules_check( 'terms created', ! is_wp_error( $red ) && ! is_wp_error( $blue ) );
	$state['red']  = (int) $red['term_id'];
	$state['blue'] = (int) $blue['term_id'];

	$parent = new WC_Product_Variable();
	$parent->set_name( 'OPF Variation Rules Product' );
	$parent->set_slug( 'opf-variation-rules-product' );
	$parent->set_status( 'publish' );
	$parent->set_catalog_visibility( 'visible' );
	$parent_id = (int) $parent->save();
	vrules_check( 'variable product created', $parent_id > 0 );

	$attribute = new WC_Product_Attribute();
	$attribute->set_id( (int) $state['attr_id'] );
	$attribute->set_name( 'pa_opf_varcolor' );
	$attribute->set_options( [ $state['red'], $state['blue'] ] );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$parent->set_attributes( [ 'pa_opf_varcolor' => $attribute ] );
	$parent->save();

	$make_variation = static function ( string $slug, string $price ) use ( $parent_id ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_status( 'publish' );
		$variation->set_virtual( true );
		$variation->set_regular_price( $price );
		$variation->set_attributes( [ 'pa_opf_varcolor' => $slug ] );
		return (int) $variation->save();
	};
	$state['parent']  = $parent_id;
	$state['red_var'] = $make_variation( 'red', '10' );
	$state['blue_var'] = $make_variation( 'blue', '20' );
	WC_Product_Variable::sync( $parent_id );
	$state['parent_url'] = (string) get_permalink( $parent_id );
	vrules_check( 'variations created', $state['red_var'] > 0 && $state['blue_var'] > 0 );

	// Draft pre-existing published groups so the visibility matrix is exact.
	$state['baseline_groups'] = [];
	foreach ( OPF\Service\FieldGroups::all() as $entry ) {
		$state['baseline_groups'][ (int) $entry['id'] ] = 'publish';
		wp_update_post( [ 'ID' => (int) $entry['id'], 'post_status' => 'draft' ] );
	}
	OPF\Service\FieldGroups::flush_cache();

	$state['groups'] = [];
	foreach ( vrules_specs( $state ) as $key => $spec ) {
		$state['groups'][ $key ] = OPF\Service\FieldGroups::save(
			0,
			[
				'fields'      => [ [ 'id' => vrules_field_id( $key ), 'type' => 'text', 'label' => $spec['label'], 'required' => false ] ],
				'rule_groups' => $spec['opf'],
			],
			[ 'title' => 'OPF vrules ' . $key, 'status' => 'publish' ]
		);
		vrules_check( "$key saved", $state['groups'][ $key ] > 0 );
	}

	update_option( 'opf_vrules_e2e_state', $state );
	if ( ! is_dir( $artifacts ) ) {
		mkdir( $artifacts, 0755, true );
	}
	file_put_contents( $state_file, wp_json_encode( array_diff_key( $state, [ 'baseline_groups' => 1 ] ), JSON_PRETTY_PRINT ) );
	echo "SUCCESS vrules setup\n";
	return;
}

vrules_check( 'fixture exists', ! empty( $state['groups']['vOnlyRed'] ) );

/** Matched OPF group ids for a product. */
function vrules_matched( array $state, int $product_id ): array {
	$ids = array_map( static function ( $entry ) {
		return (int) $entry['id'];
	}, OPF\Service\FieldGroups::for_product( wc_get_product( $product_id ) ) );
	sort( $ids );
	return $ids;
}

function vrules_contains( array $state, string $key, int $product_id ): bool {
	return in_array( (int) $state['groups'][ $key ], vrules_matched( $state, $product_id ), true );
}

if ( 'assert' === $phase ) {
	// Parent variable product: all variation-scoped groups render and carry a
	// generated frontend gate (context is selection-dependent, so no baked ctx).
	foreach ( [ 'vOnlyRed', 'vNotRed', 'vAttrRed', 'vAttrAny', 'vAlways' ] as $key ) {
		vrules_check( "parent matches $key", vrules_contains( $state, $key, (int) $state['parent'] ) );
	}

	$parent_groups = OPF\Service\FieldGroups::for_product( wc_get_product( (int) $state['parent'] ) );
	$by_id = [];
	foreach ( $parent_groups as $entry ) {
		$by_id[ (int) $entry['id'] ] = $entry['group'];
	}
	foreach ( [ 'vOnlyRed', 'vNotRed', 'vAttrRed', 'vAttrAny' ] as $key ) {
		$field = $by_id[ (int) $state['groups'][ $key ] ]->data['fields'][0];
		$gates = array_values( array_filter( (array) $field['conditionals'], static function ( $c ) {
			return is_array( $c ) && 'var' === ( $c['action'] ?? '' );
		} ) );
		vrules_check( "parent $key field has one generated var gate", 1 === count( $gates ) );
		vrules_check( "parent $key gate keeps subject", ( $gates[0]['rules'][0]['subject'] ?? '' ) === ( 'vAttrRed' === $key || 'vAttrAny' === $key ? 'var_att' : 'product_var' ) );
		vrules_check( "parent $key has no baked context", ! isset( $field['_var_ctx'] ) );
	}
	// Control group carries no variation gate.
	$control_field = $by_id[ (int) $state['groups']['vAlways'] ]->data['fields'][0];
	vrules_check( 'control group has no gate', empty( array_filter( (array) $control_field['conditionals'], static function ( $c ) {
		return is_array( $c ) && 'var' === ( $c['action'] ?? '' );
	} ) ) );

	// Variation product: strict per-variation placement for `product_var`.
	// `var_att` group placement is non-strict (WAPF product_has_attribute_values
	// default): a variation inherits its parent's attribute values, so the group
	// renders for every child and the generated per-field gate does the strict
	// per-variation filtering.
	vrules_check( 'red variation matches vOnlyRed', vrules_contains( $state, 'vOnlyRed', (int) $state['red_var'] ) );
	vrules_check( 'red variation matches vAttrRed', vrules_contains( $state, 'vAttrRed', (int) $state['red_var'] ) );
	vrules_check( 'red variation matches vAttrAny', vrules_contains( $state, 'vAttrAny', (int) $state['red_var'] ) );
	vrules_check( 'blue variation does NOT match vOnlyRed', ! vrules_contains( $state, 'vOnlyRed', (int) $state['blue_var'] ) );
	// WAPF 3.1.5 `check()` shares the positive branch for `!product_var`, so
	// the negated group only matches targeted variations; the merged per-field
	// `not_in` gate performs the real exclusion. OPF reproduces this exactly.
	vrules_check( 'red variation matches vNotRed group (WAPF shared positive branch)', vrules_contains( $state, 'vNotRed', (int) $state['red_var'] ) );
	vrules_check( 'blue variation does NOT match vNotRed group (WAPF defect parity)', ! vrules_contains( $state, 'vNotRed', (int) $state['blue_var'] ) );
	vrules_check( 'blue variation matches vAttrRed group (non-strict parent attrs)', vrules_contains( $state, 'vAttrRed', (int) $state['blue_var'] ) );
	vrules_check( 'blue variation matches vAttrAny', vrules_contains( $state, 'vAttrAny', (int) $state['blue_var'] ) );

	$red_groups = OPF\Service\FieldGroups::for_product( wc_get_product( (int) $state['red_var'] ) );
	$red_by_id = [];
	foreach ( $red_groups as $entry ) {
		$red_by_id[ (int) $entry['id'] ] = $entry['group'];
	}
	$red_field = $red_by_id[ (int) $state['groups']['vOnlyRed'] ]->data['fields'][0];
	vrules_check( 'red variation bakes variation context', ( $red_field['_var_ctx']['id'] ?? 0 ) === (int) $state['red_var'] );
	vrules_check( 'red variation vOnlyRed field visible', OPF\Engine\Evaluator::is_visible( $red_field, [] ) );
	vrules_check( 'red variation vAttrRed field visible', OPF\Engine\Evaluator::is_visible( $red_by_id[ (int) $state['groups']['vAttrRed'] ]->data['fields'][0], [] ) );

	$blue_groups = OPF\Service\FieldGroups::for_product( wc_get_product( (int) $state['blue_var'] ) );
	$blue_by_id = [];
	foreach ( $blue_groups as $entry ) {
		$blue_by_id[ (int) $entry['id'] ] = $entry['group'];
	}
	vrules_check( 'blue variation bakes its own context', ( $blue_by_id[ (int) $state['groups']['vAttrRed'] ]->data['fields'][0]['_var_ctx']['id'] ?? 0 ) === (int) $state['blue_var'] );
	vrules_check( 'blue variation vAttrRed field hidden by strict gate', ! OPF\Engine\Evaluator::is_visible( $blue_by_id[ (int) $state['groups']['vAttrRed'] ]->data['fields'][0], [] ) );
	vrules_check( 'blue variation vAttrAny field visible', OPF\Engine\Evaluator::is_visible( $blue_by_id[ (int) $state['groups']['vAttrAny'] ]->data['fields'][0], [] ) );

	// A simple product matches nothing variation-scoped (context passes through
	// but placement itself excludes it).
	$simple = new WC_Product_Simple();
	$simple->set_name( 'OPF vrules simple control' );
	$simple->set_slug( 'opf-vrules-simple-control' );
	$simple->set_status( 'publish' );
	$simple->set_regular_price( '5' );
	$simple_id = (int) $simple->save();
	$state['simple'] = $simple_id;
	update_option( 'opf_vrules_e2e_state', $state );
	vrules_check( 'simple product matches only control group', [ (int) $state['groups']['vAlways'] ] === vrules_matched( $state, $simple_id ) );
	wp_delete_post( $simple_id, true );

	// Server-side visibility: on the parent, no selected variation hides gates.
	$parent_field = $by_id[ (int) $state['groups']['vOnlyRed'] ]->data['fields'][0];
	OPF\Engine\Evaluator::set_context_product( wc_get_product( (int) $state['parent'] ) );
	$_POST['variation_id'] = (string) $state['red_var'];
	$_POST['attribute_pa_opf_varcolor'] = 'red';
	try {
		$parent_field_red = $by_id[ (int) $state['groups']['vOnlyRed'] ]->data['fields'][0];
		vrules_check( 'parent with posted red variation gate passes', OPF\Engine\Evaluator::is_visible( $parent_field_red, [] ) );
		$_POST['variation_id'] = (string) $state['blue_var'];
		$parent_field_blue = $by_id[ (int) $state['groups']['vOnlyRed'] ]->data['fields'][0];
		vrules_check( 'parent with posted blue variation gate fails', ! OPF\Engine\Evaluator::is_visible( $parent_field_blue, [] ) );
	} finally {
		unset( $_POST['variation_id'], $_POST['attribute_pa_opf_varcolor'] );
	}
	vrules_check( 'parent field without posted variation hidden', ! OPF\Engine\Evaluator::is_visible( $parent_field, [] ) );

	echo "SUCCESS vrules assert\n";
	return;
}

if ( 'order' === $phase ) {
	if ( ! WC()->cart ) {
		wc_load_cart();
	}
	$cart = WC()->cart;
	add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
	$cart->empty_cart();

	// Classic payload for the positive red-scoped group.
	$_POST['opf'] = [
		(string) $state['groups']['vOnlyRed'] => [ vrules_field_id( 'vOnlyRed' ) => 'red scoped value' ],
		(string) $state['groups']['vAttrRed'] => [ vrules_field_id( 'vAttrRed' ) => 'attr red value' ],
	];
	wc_clear_notices();
	$valid = apply_filters( 'woocommerce_add_to_cart_validation', true, (int) $state['parent'], 1, (int) $state['red_var'], [ 'pa_opf_varcolor' => 'red' ] );
	vrules_check( 'red variation add-to-cart validates', true === $valid );
	$key = $cart->add_to_cart( (int) $state['parent'], 1, (int) $state['red_var'], [ 'pa_opf_varcolor' => 'red' ] );
	vrules_check( 'red variation added to cart', is_string( $key ) && '' !== $key );
	$cart_item = $cart->get_cart_item( $key );
	$stored    = $cart_item['opf_fields'] ?? [];
	vrules_check( 'cart keeps red-scoped value', ( $stored[ (string) $state['groups']['vOnlyRed'] ][ vrules_field_id( 'vOnlyRed' ) ] ?? null ) === 'red scoped value' );
	vrules_check( 'cart keeps attribute-scoped value', ( $stored[ (string) $state['groups']['vAttrRed'] ][ vrules_field_id( 'vAttrRed' ) ] ?? null ) === 'attr red value' );

	// Order item persistence via the real checkout hook.
	$order = wc_create_order();
	$order->set_created_via( 'opf-vrules-fixture' );
	$order->set_status( 'pending' );
	$item = new WC_Order_Item_Product();
	$item->set_product( wc_get_product( (int) $state['red_var'] ) );
	$item->set_quantity( 1 );
	$item->set_subtotal( '10' );
	$item->set_total( '10' );
	$order->add_item( $item );
	do_action( 'woocommerce_checkout_create_order_line_item', $item, $key, $cart_item, $order );
	$order->save();
	$meta = (string) $item->get_meta( '_opf_fields', true );
	$decoded = json_decode( $meta, true );
	vrules_check( 'order item meta stores red-scoped value', ( $decoded[ (string) $state['groups']['vOnlyRed'] ][ vrules_field_id( 'vOnlyRed' ) ] ?? null ) === 'red scoped value' );
	$state['order'] = (int) $order->get_id();
	update_option( 'opf_vrules_e2e_state', $state );

	$cart->empty_cart();
	unset( $_POST['opf'] );
	remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
	file_put_contents( $artifacts . '/order.json', wp_json_encode( [ 'order_id' => $state['order'], 'meta' => $decoded ], JSON_PRETTY_PRINT ) );
	echo "SUCCESS vrules order\n";
	return;
}

if ( 'wapf_setup' === $phase ) {
	$activated = activate_plugin( 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php', '', false, true );
	vrules_check( 'WAPF Extended activated', ! is_wp_error( $activated ) && is_plugin_active( 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' ) );
	vrules_check( 'WAPF Field_Groups available', class_exists( 'SW_WAPF_PRO\Includes\Classes\Field_Groups' ) );
	$state['wapf'] = [];
	foreach ( vrules_specs( $state ) as $key => $spec ) {
		$raw = [
			'id' => 0, 'type' => 'wapf_product', 'layout' => [], 'variables' => [],
			'rule_groups' => $spec['wapf'],
			'fields' => [ [ 'id' => 'wr' . strtolower( $key ), 'label' => 'WAPF ' . $spec['label'], 'description' => '', 'type' => 'text', 'required' => false, 'class' => '', 'width' => 100, 'options' => [], 'pricing' => [ 'enabled' => 'false', 'type' => 'fixed', 'amount' => 0 ], 'conditionals' => [] ] ],
		];
		$fg = SW_WAPF_PRO\Includes\Classes\Field_Groups::process_data( $raw );
		$state['wapf'][ $key ] = (int) SW_WAPF_PRO\Includes\Classes\Field_Groups::save( $fg, 'wapf_product', null, 'WAPF vrules ' . $key, 'publish' );
		vrules_check( "wapf $key saved", $state['wapf'][ $key ] > 0 );
	}
	update_option( 'opf_vrules_e2e_state', $state );
	echo "SUCCESS vrules wapf setup\n";
	return;
}

if ( 'wapf_assert' === $phase ) {
	vrules_check( 'WAPF active for assert', is_plugin_active( 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' ) );
	$all = SW_WAPF_PRO\Includes\Classes\Field_Groups::get_all();
	$by_id = [];
	foreach ( $all as $fg ) {
		$by_id[ (int) $fg->id ] = $fg;
	}
	$valid = static function ( array $state, array $by_id, string $key, int $product_id ): bool {
		return SW_WAPF_PRO\Includes\Classes\Conditions::is_field_group_valid_for_product( $by_id[ (int) $state['wapf'][ $key ] ], wc_get_product( $product_id ) );
	};
	// Group-level parity matrix (note WAPF's shared positive branch for the
	// negative predicate is reproduced by OPF's placement evaluator).
	vrules_check( 'wapf red variation valid for vOnlyRed', $valid( $state, $by_id, 'vOnlyRed', (int) $state['red_var'] ) );
	vrules_check( 'wapf blue variation invalid for vOnlyRed', ! $valid( $state, $by_id, 'vOnlyRed', (int) $state['blue_var'] ) );
	vrules_check( 'wapf red variation valid for vNotRed group (shared positive branch defect)', $valid( $state, $by_id, 'vNotRed', (int) $state['red_var'] ) );
	vrules_check( 'wapf blue variation invalid for vNotRed group (shared positive branch defect)', ! $valid( $state, $by_id, 'vNotRed', (int) $state['blue_var'] ) );
	vrules_check( 'wapf parent valid for vOnlyRed', $valid( $state, $by_id, 'vOnlyRed', (int) $state['parent'] ) );
	vrules_check( 'wapf red variation valid for vAttrRed', $valid( $state, $by_id, 'vAttrRed', (int) $state['red_var'] ) );
	vrules_check( 'wapf blue variation valid for vAttrRed group (non-strict parent attrs)', $valid( $state, $by_id, 'vAttrRed', (int) $state['blue_var'] ) );
	vrules_check( 'wapf parent valid for vAttrAny', $valid( $state, $by_id, 'vAttrAny', (int) $state['parent'] ) );
	vrules_check( 'wapf vAlways valid everywhere (simple)', $valid( $state, $by_id, 'vAlways', (int) $state['parent'] ) );

	// Frontend merge parity: WAPF converts the valid rule group's variation
	// rules into generated per-field conditionals (`get_frontend_conditions` +
	// `merge_frontend_conditions`), exactly what OPF's inject_variation_rules
	// reproduces as `action=var` gates.
	$fg = $by_id[ (int) $state['wapf']['vOnlyRed'] ];
	$group = $fg->rules_groups[0];
	$rules = SW_WAPF_PRO\Includes\Classes\Conditions::get_frontend_conditions( $group );
	vrules_check( 'wapf exposes frontend variation rule for vOnlyRed', count( $rules ) >= 1 );
	$field = clone $fg->fields[0];
	SW_WAPF_PRO\Includes\Classes\Conditions::merge_frontend_conditions( $field, $rules );
	$generated = [];
	foreach ( $field->conditionals as $cond ) {
		foreach ( $cond->rules as $rule ) {
			if ( in_array( (string) $rule->condition, [ 'product_var', '!product_var', 'patts', '!patts' ], true ) ) {
				$generated[] = $rule->condition;
			}
		}
	}
	vrules_check( 'wapf merged field carries generated product_var rule', in_array( 'product_var', $generated, true ) );

	// Strict attribute check (used by field-level validation) inspects the
	// variation's own attributes, which is what OPF's baked `_var_ctx` does.
	$blue_variation = wc_get_product( (int) $state['blue_var'] );
	$red_variation  = wc_get_product( (int) $state['red_var'] );
	vrules_check(
		'wapf strict patts rejects blue for opf_varcolor|red',
		! SW_WAPF_PRO\Includes\Classes\Conditions::product_has_attribute_values( $blue_variation, [ 'opf_varcolor|red' ], true )
	);
	vrules_check(
		'wapf strict patts accepts red for opf_varcolor|red',
		SW_WAPF_PRO\Includes\Classes\Conditions::product_has_attribute_values( $red_variation, [ 'opf_varcolor|red' ], true )
	);

	echo "SUCCESS vrules wapf assert\n";
	return;
}

if ( 'wapf_cleanup' === $phase ) {
	foreach ( (array) ( $state['wapf'] ?? [] ) as $fg_id ) {
		wp_delete_post( (int) $fg_id, true );
	}
	unset( $state['wapf'] );
	update_option( 'opf_vrules_e2e_state', $state );
	deactivate_plugins( 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' );
	vrules_check( 'WAPF deactivated', ! is_plugin_active( 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' ) );
	vrules_check( 'wapf posts deleted', (int) $state['baseline']['wapf_posts'] === (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='wapf_product'" ) );
	echo "SUCCESS vrules wapf cleanup\n";
	return;
}

if ( 'cleanup' === $phase ) {
	if ( ! empty( $state['order'] ) ) {
		$order = wc_get_order( (int) $state['order'] );
		if ( $order ) {
			$order->delete( true );
		}
	}
	foreach ( (array) ( $state['groups'] ?? [] ) as $gid ) {
		wp_delete_post( (int) $gid, true );
	}
	foreach ( (array) ( $state['wapf'] ?? [] ) as $gid ) {
		wp_delete_post( (int) $gid, true );
	}
	foreach ( (array) ( $state['baseline_groups'] ?? [] ) as $gid => $status ) {
		wp_update_post( [ 'ID' => (int) $gid, 'post_status' => (string) $status ] );
	}
	foreach ( [ 'red_var', 'blue_var', 'parent' ] as $key ) {
		if ( ! empty( $state[ $key ] ) ) {
			wp_delete_post( (int) $state[ $key ], true );
		}
	}
	if ( ! taxonomy_exists( 'pa_opf_varcolor' ) ) {
		register_taxonomy( 'pa_opf_varcolor', [ 'product' ] );
	}
	if ( ! empty( $state['red'] ) ) {
		wp_delete_term( (int) $state['red'], 'pa_opf_varcolor' );
	}
	if ( ! empty( $state['blue'] ) ) {
		wp_delete_term( (int) $state['blue'], 'pa_opf_varcolor' );
	}
	if ( ! empty( $state['attr_id'] ) ) {
		wc_delete_attribute( (int) $state['attr_id'] );
	}
	OPF\Service\FieldGroups::flush_cache();

	// Targeted WooCommerce session sweep: drop only cart lines that reference
	// this fixture's products, leaving every pre-existing session untouched.
	$targets = array_values( array_filter( array_map( 'intval', [ $state['parent'] ?? 0, $state['red_var'] ?? 0, $state['blue_var'] ?? 0 ] ) ) );
	if ( $targets ) {
		$table = $GLOBALS['wpdb']->prefix . 'woocommerce_sessions';
		foreach ( (array) $GLOBALS['wpdb']->get_results( "SELECT session_key, session_value FROM {$table}" ) as $row ) {
			$data = maybe_unserialize( $row->session_value );
			if ( ! is_array( $data ) || empty( $data['cart'] ) || ! is_string( $data['cart'] ) ) {
				continue;
			}
			$cart = maybe_unserialize( $data['cart'] );
			if ( ! is_array( $cart ) ) {
				continue;
			}
			$changed = false;
			foreach ( $cart as $line_key => $line ) {
				$ids = [ (int) ( $line['product_id'] ?? 0 ), (int) ( $line['variation_id'] ?? 0 ) ];
				if ( array_intersect( $ids, $targets ) ) {
					unset( $cart[ $line_key ] );
					$changed = true;
				}
			}
			if ( ! $changed ) {
				continue;
			}
			if ( empty( $cart ) ) {
				$GLOBALS['wpdb']->delete( $table, [ 'session_key' => $row->session_key ] );
			} else {
				$data['cart'] = serialize( $cart );
				unset( $data['cart_totals'], $data['previous_cart_hash'] );
				$GLOBALS['wpdb']->update(
					$table,
					[ 'session_value' => serialize( $data ), 'session_expiry' => time() + HOUR_IN_SECONDS ],
					[ 'session_key' => $row->session_key ]
				);
			}
		}
	}

	delete_option( 'opf_vrules_e2e_state' );

	$after = [
		'products'   => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product'" ),
		'variations' => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product_variation'" ),
		'groups'     => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='opf_field_group'" ),
		'wapf_posts' => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='wapf_product'" ),
		'orders'     => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ),
		'users'      => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users}" ),
		'attributes' => count( (array) wc_get_attribute_taxonomies() ),
		'plugins'    => (array) get_option( 'active_plugins', [] ),
	];
	foreach ( $state['baseline'] as $key => $value ) {
		vrules_check( "baseline restored: $key", $after[ $key ] === $value );
	}
	file_put_contents( $artifacts . '/cleanup.json', wp_json_encode( [ 'baseline' => $state['baseline'], 'after' => $after ], JSON_PRETTY_PRINT ) );
	echo "SUCCESS vrules cleanup\n";
	return;
}

throw new RuntimeException( 'Unknown phase: ' . $phase );
