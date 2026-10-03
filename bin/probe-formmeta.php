<?php
/**
 * WAPF Extended 3.1.5 ↔ OPF formula parity probe for the custom-variable lane:
 * [var_*] variables, files(), lookuptable(), [x], and the unregistered
 * map()/reduce() calls the reference does not implement. Read-only probe:
 * never writes posts, options, or files. Requires the disposable clone.
 *
 * Usage:
 *   OPF_FORMMETA_ALLOW=1 OPF_FORMMETA_OUT=/tmp/opf-lane-formmeta-evidence \
 *     wp --path=/tmp/opf-image-formmeta-wp eval-file bin/probe-formmeta.php
 */
use OPF\Engine\Calculator;
use SW_WAPF_PRO\Includes\Classes\Field_Groups;
use SW_WAPF_PRO\Includes\Classes\Helper;

if ( '1' !== getenv( 'OPF_FORMMETA_ALLOW' ) || '/tmp/opf-image-formmeta-wp' !== realpath( ABSPATH ) ) {
	throw new RuntimeException( 'Owned disposable clone + explicit opt-in required.' );
}
$out = getenv( 'OPF_FORMMETA_OUT' );
if ( ! $out || ! is_dir( $out ) ) {
	throw new RuntimeException( 'Existing artifact directory required.' );
}

$reference = WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php';
if ( ! function_exists( 'SW_WAPF_PRO\\wapf_pro' ) && file_exists( $reference ) ) {
	require_once $reference; // registers autoloader + builds controllers (Woo is active).
}
if ( ! class_exists( Helper::class ) ) {
	throw new RuntimeException( 'WAPF Extended 3.1.5 classes unavailable.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$version = get_plugin_data( WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' )['Version'] ?? '';
if ( '3.1.5' !== $version ) {
	throw new RuntimeException( 'Reference must be Extended 3.1.5.' );
}

// Shared lookup-table fixture used through WAPF's own extension filter.
$tables = [
	'cutting' => [
		10 => [ 5 => 100, 20 => 150 ],
		30 => [ 5 => 200, 20 => 300 ],
	],
	'deep'    => [
		10 => [ 5 => [ 1 => 11, 9 => 19 ] ],
	],
	'textleaf' => [ 1 => [ 1 => 'abc' ] ],
	'flat'    => [ 10 => 40, 30 => 80 ],
];
add_filter(
	'wapf/lookup_tables',
	static function () use ( $tables ) {
		return $tables;
	}
);

// WAPF cart-field entries carry ['id','clone_idx','type','raw','values'=>[['slug','label','price','price_type','calc_price']]].
$cf = static function ( string $id, array $values, $raw = null, string $type = 'number' ): array {
	return [
		'id'        => $id,
		'clone_idx' => 0,
		'type'      => $type,
		'raw'       => $raw ?? ( isset( $values[0]['label'] ) ? $values[0]['label'] : '' ),
		'values'    => $values,
	];
};
$val = static function ( string $label, string $slug = '', float $calc = 0.0 ): array {
	return [ 'label' => $label, 'slug' => $slug, 'price' => 0, 'price_type' => 'none', 'calc_price' => $calc ];
};

// A WAPF group model so evaluate_variables sees real Field objects + variables.
$group_raw = [
	'id'    => 'probe',
	'type'  => 'wapf_product',
	'fields' => [
		[ 'id' => 'widthF', 'label' => 'Width', 'type' => 'number', 'description' => '', 'required' => false, 'class' => '', 'width' => 100, 'options' => [], 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'parent_clone' => [], 'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ] ],
		[ 'id' => 'heightF', 'label' => 'Height', 'type' => 'number', 'description' => '', 'required' => false, 'class' => '', 'width' => 100, 'options' => [], 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'parent_clone' => [], 'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ] ],
		[ 'id' => 'sizeS', 'label' => 'Size', 'type' => 'select', 'description' => '', 'required' => false, 'class' => '', 'width' => 100, 'options' => [ 'choices' => [ [ 'slug' => 'lg', 'label' => 'Large', 'selected' => false ] ] ], 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'parent_clone' => [], 'pricing' => [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ] ],
	],
	'conditions' => [],
	'layout'     => [],
	'variables'  => [
		[ 'name' => 'fee', 'default' => '1.5', 'rules' => [] ],
		[ 'name' => 'dyn', 'default' => '1', 'rules' => [ [ 'type' => 'field', 'field' => 'sizeS', 'condition' => '==', 'value' => 'lg', 'variable' => '2.5' ] ] ],
		[ 'name' => 'qtyvar', 'default' => '10', 'rules' => [ [ 'type' => 'qty', 'field' => 'qty', 'condition' => 'gt', 'value' => '2', 'variable' => '99' ] ] ],
		[ 'name' => 'nested', 'default' => '[var_fee]*2', 'rules' => [] ],
		[ 'name' => 'fxvar', 'default' => '[field.widthF]*files(upFiles)', 'rules' => [] ],
		[ 'name' => 'tworule', 'default' => '0', 'rules' => [ [ 'type' => 'field', 'field' => 'sizeS', 'condition' => '==', 'value' => 'lg', 'variable' => '7' ], [ 'type' => 'field', 'field' => 'sizeS', 'condition' => '==', 'value' => 'lg', 'variable' => '8' ] ] ],
	],
];
$model = Field_Groups::raw_json_to_field_group( $group_raw );
$wapf_fields = $model->fields;
$variables   = $model->variables;

// OPF-side mirror: same variables in OPF context shape + same field defs.
$opf_fields    = [];
foreach ( $wapf_fields as $field ) {
	$opf_fields[] = [ 'id' => strtolower( (string) $field->id ), 'type' => (string) $field->type ];
}
$opf_variables = [];
foreach ( $variables as $variable ) {
	$opf_variables[] = [
		'name'    => (string) $variable['name'],
		'default' => (string) $variable['default'],
		'rules'   => array_map(
			static function ( $rule ) {
				return [ 'type' => (string) $rule['type'], 'field' => strtolower( (string) $rule['field'] ), 'condition' => (string) $rule['condition'], 'value' => (string) $rule['value'], 'variable' => (string) $rule['variable'] ];
			},
			(array) $variable['rules']
		),
	];
}
// WAPF formulas reference [var_name] by exact name; OPF field ids normalize to
// lowercase, so [field.X]/lookup/files refs inside variable bodies remap.
$opf_variables = json_decode( str_replace( [ 'widthF', 'heightF', 'sizeS', 'upFiles' ], [ 'widthf', 'heightf', 'sizes', 'upfiles' ], wp_json_encode( $opf_variables ) ), true );

/**
 * WAPF fx path: replace_in_formula → evaluate_variables → parse_math_string
 * (mirrors Fields::do_pricing case 'fx').
 */
$wapf_eval = static function ( string $formula, array $cart_fields, float $price, int $qty, string $value, array $vars, int $product_id = 0 ) use ( $wapf_fields ): string {
	try {
		$math = Helper::replace_in_formula( $formula, $qty, $price, $value, 0, $cart_fields, $product_id ?: null, 0 );
		if ( $vars ) {
			$math = Helper::evaluate_variables( $math, $wapf_fields, $vars, $product_id ?: null, 0, $price, $value, $qty, 0, $cart_fields );
		}
		$result = Helper::parse_math_string( $math, $cart_fields, true, [ 'product_id' => $product_id ?: null, 'clone_index' => 0 ] );
		return is_scalar( $result ) ? (string) $result : wp_json_encode( $result );
	} catch ( \Throwable $error ) {
		return 'FATAL:' . get_class( $error ) . ':' . $error->getMessage();
	}
};

$opf_eval = static function ( string $formula, array $field_values, array $field_labels, float $price, int $qty, string $value, array $options ): float {
	return Calculator::evaluate_formula( $formula, $price, $qty, 0.0, $value, null, $field_values, 0, [], $field_labels, $options );
};

// OPF field_values mirroring a submitted file field (private tokens), number
// fields, and a select choice slug.
$opf_values_base = [
	'upfiles' => [ 'tok_a', 'tok_b', 'tok_c' ],
	'widthf'  => '15',
	'heightf' => '5',
	'sizes'   => 'lg',
];
$opf_labels_base = [ 'sizes' => [ 'lg' => 'Large' ] ];
$wapf_cf_base    = [
	$cf( 'upFiles', [ $val( 'a.png,b.png,c.png' ) ], 'a.png,b.png,c.png', 'file' ),
	$cf( 'widthF', [ $val( '15' ) ] ),
	$cf( 'heightF', [ $val( '5' ) ] ),
	$cf( 'sizeS', [ $val( 'Large', 'lg' ) ], 'lg', 'select' ),
];

$matrix = [];

$push = static function ( string $name, string $formula, array $wapf_cf, array $opf_values, array $opf_labels, array $options, float $price = 10.0, int $qty = 1, string $value = '' ) use ( &$matrix, $wapf_eval, $opf_eval, $variables ): void {
	$matrix[] = [
		'name'         => $name,
		'formula'      => $formula,
		'wapf_result'  => $wapf_eval( $formula, $wapf_cf, $price, $qty, $value, $variables ),
		'opf_result'   => $opf_eval( $formula, $opf_values, $opf_labels, $price, $qty, $value, $options ),
	];
};
$opts = [ 'variables' => $opf_variables, 'fields' => $opf_fields, 'lookup_tables' => $tables ];

// --- files() --------------------------------------------------------------
$push( 'files-basic-3', 'files(upFiles)', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'files-single', 'files(upFiles)', [ $cf( 'upFiles', [ $val( 'a.png' ) ], 'a.png', 'file' ) ], [ 'upfiles' => [ 'tok_a' ] ], [], $opts );
$push( 'files-empty-values', 'files(upFiles)', [ $cf( 'upFiles', [], '', 'file' ) ], [ 'upfiles' => [] ], [], $opts );
$push( 'files-empty-label', 'files(upFiles)', [ $cf( 'upFiles', [ $val( '' ) ], '', 'file' ) ], [ 'upfiles' => [ '' ] ], [], $opts );
$push( 'files-missing-field', 'files(nope)', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'files-comma-label', 'files(textF)', [ $cf( 'textF', [ $val( 'a,b' ) ], 'a,b', 'text' ) ], [ 'textf' => 'a,b' ], [], $opts );
$push( 'files-in-expr', 'files(upFiles)*[qty]', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts, 10.0, 2 );

// --- lookuptable() ----------------------------------------------------------
$push( 'lookup-exact', 'lookuptable(cutting;widthF;heightF)', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'lookup-roundup', 'lookuptable(cutting;widthF;heightF)', [ $cf( 'widthF', [ $val( '15' ) ] ), $cf( 'heightF', [ $val( '12' ) ] ) ], [ 'widthf' => '15', 'heightf' => '12' ], [], $opts );
$push( 'lookup-below-first', 'lookuptable(cutting;widthF;heightF)', [ $cf( 'widthF', [ $val( '2' ) ] ), $cf( 'heightF', [ $val( '1' ) ] ) ], [ 'widthf' => '2', 'heightf' => '1' ], [], $opts );
$push( 'lookup-beyond-last', 'lookuptable(cutting;widthF;heightF)', [ $cf( 'widthF', [ $val( '99' ) ] ), $cf( 'heightF', [ $val( '99' ) ] ) ], [ 'widthf' => '99', 'heightf' => '99' ], [], $opts );
$push( 'lookup-beyond-last-final-dim', 'lookuptable(cutting;widthF;heightF)', [ $cf( 'widthF', [ $val( '10' ) ] ), $cf( 'heightF', [ $val( '99' ) ] ) ], [ 'widthf' => '10', 'heightf' => '99' ], [], $opts );
$push( 'lookup-1dim-beyond-last', 'lookuptable(flat;widthF)', [ $cf( 'widthF', [ $val( '99' ) ] ) ], [ 'widthf' => '99' ], [], $opts );
$push( 'lookup-literal-args', 'lookuptable(cutting;15;5)', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'lookup-short-fieldid-literal', 'lookuptable(cutting;abcde;5)', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'lookup-missing-field', 'lookuptable(cutting;zzzzzzz;5)', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'lookup-empty-label-field', 'lookuptable(cutting;widthF;emptyFld)', [ $cf( 'widthF', [ $val( '15' ) ] ), $cf( 'emptyFld', [ $val( '' ) ], '' ) ], [ 'widthf' => '15', 'emptyfld' => '' ], [], $opts );
$push( 'lookup-missing-table', 'lookuptable(nothere;widthF;heightF)', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'lookup-3dim', 'lookuptable(deep;widthF;heightF;2)', [ $cf( 'widthF', [ $val( '12' ) ] ), $cf( 'heightF', [ $val( '7' ) ] ) ], [ 'widthf' => '12', 'heightf' => '7' ], [], $opts );
$push( 'lookup-nonnumeric-leaf', 'lookuptable(textleaf;widthF;heightF)', [ $cf( 'widthF', [ $val( '1' ) ] ), $cf( 'heightF', [ $val( '1' ) ] ) ], [ 'widthf' => '1', 'heightf' => '1' ], [], $opts );
$push( 'lookup-inside-expr', 'lookuptable(cutting;widthF;heightF)*[qty]', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts, 10.0, 3 );
$push( 'lookup-quoted-table', "lookuptable('cutting';widthF;heightF)", $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );

// --- [x] value token ---------------------------------------------------------
$push( 'x-token-value', '[x]*2', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts, 10.0, 1, '4' );

// --- [var_*] custom variables -------------------------------------------------
$push( 'var-default', '[var_fee]*2', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'var-rule-match', '[var_dyn]', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'var-rule-nomatch', '[var_dyn]', [ $cf( 'sizeS', [ $val( 'Small', 'sm' ) ], 'sm', 'select' ) ], [ 'sizes' => 'sm' ], $opf_labels_base, $opts );
$push( 'var-qty-rule-match', '[var_qtyvar]', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts, 10.0, 5 );
$push( 'var-qty-rule-nomatch', '[var_qtyvar]', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts, 10.0, 1 );
$push( 'var-nested', '[var_nested]', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'var-with-functions', '[var_fxvar]', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'var-unknown', '[var_nothere]', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'var-first-rule-wins', '[var_tworule]', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'var-in-expr', '([var_fee]+1)*[qty]', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts, 10.0, 4 );
$push( 'var-case-sensitive', '[VAR_FEE]', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );

// --- map()/reduce() are not WAPF functions -----------------------------------
$push( 'map-unregistered', 'map(1;2)', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'reduce-unregistered', 'reduce(1;2)', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'map-text-args', 'map(widthF;x*2)', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );

// --- composition sanity -------------------------------------------------------
$push( 'files-plus-lookup', 'files(upFiles)+lookuptable(cutting;widthF;heightF)', $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );
$push( 'semicolon-in-quotes', "len('a;b')", $wapf_cf_base, $opf_values_base, $opf_labels_base, $opts );

$write = static function ( string $name, $data ) use ( $out ): void {
	file_put_contents( $out . '/' . $name, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
};

$normalized = [];
foreach ( $matrix as $row ) {
	$wapf_numeric = is_numeric( $row['wapf_result'] ) ? (float) $row['wapf_result'] : null;
	$match        = null !== $wapf_numeric && abs( $wapf_numeric - (float) $row['opf_result'] ) < 1e-9;
	$normalized[] = $row + [ 'wapf_numeric' => $wapf_numeric, 'match' => $match ];
}
$write( 'formmeta-probe-matrix.json', [
	'utc'    => gmdate( 'c' ),
	'plugin' => [ 'wapf_extended' => $version ],
	'rows'   => $normalized,
	'summary' => [
		'total'   => count( $normalized ),
		'matches' => count( array_filter( array_column( $normalized, 'match' ) ) ),
	],
] );
echo wp_json_encode( array_column( $normalized, 'match', 'name' ) ), "\n";
