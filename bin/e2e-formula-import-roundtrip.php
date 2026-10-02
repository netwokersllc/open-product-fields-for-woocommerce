<?php
/** Native WAPF Tools / legacy importer / OPF archive proof on a fresh SQLite clone. */

use OPF\Engine\Calculator;
use OPF\Engine\WapfMapper;
use OPF\Service\ArchiveImporter;
use OPF\Service\Exporter;
use OPF\Service\FieldGroups;
use OPF\Service\Importer;
use OPF\Service\WapfExporter;
use SW_WAPF_PRO\Includes\Classes\Field_Groups;
use SW_WAPF_PRO\Includes\Classes\Fields;
use SW_WAPF_PRO\Includes\Classes\Helper;

if ( '1' !== getenv( 'OPF_FORMULA_ROUNDTRIP_ALLOW' ) || '/tmp/opf-formula-roundtrip-wp/' !== ABSPATH || FQDB !== ABSPATH . 'wp-content/database/.ht.sqlite' ) {
	throw new RuntimeException( 'Requires the dedicated /tmp/opf-formula-roundtrip-wp SQLite clone and explicit authorization.' );
}
$mode = getenv( 'OPF_FORMULA_ROUNDTRIP_MODE' );
$dir = getenv( 'OPF_FORMULA_ROUNDTRIP_ARTIFACT_DIR' ) ?: '/tmp/opf-formula-roundtrip-artifacts';
$state = get_option( 'opf_formula_roundtrip_state', [] );
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$write = static function ( string $file, $value ) use ( $dir ): void {
	if ( false === file_put_contents( $dir . '/' . $file, wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
		throw new RuntimeException( 'Artifact write failed: ' . $file );
	}
};
if ( 'setup' === $mode ) {
	$assert( empty( $state ), 'Existing fixture state; run cleanup first.' );
	$assert( 0 === (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type IN ('wapf_product','opf_field_group')" ), 'Requires an empty field-group database.' );
	$cases = [
		'flat' => '7',
		'qty-middle' => '2 * [qty] + 5',
		'qty-tail' => '(2 + 5) * [qty]',
		'qty-head' => '[qty] * (2 + 5)',
		'base-options' => '[price] * 0.1 + [options_total]',
		'sibling-value-price' => '[field.CountID] + [price.FeeID]',
		'checked' => 'checked(FlagsID) + 3',
		'min-max' => 'min(5; 1; 3) + max(5; 8; 3)',
		'round-pow' => 'round(pow([field.CountID]; 2) / 7; 2)',
		'abs-floor-ceil' => 'abs(-4) + floor(2.9) + ceil(2.1)',
		'sqrt-trig' => 'sqrt(9) + sin(0) + cos(0) + tan(0)',
		'all-eleven' => '(round(pow([field.CountID]; 2) / 3; 2) + max(abs(-4); floor(2.9); ceil(2.1)) + sqrt(9) + sin(0) + cos(0) + tan(0) + min(5; 1; 3)) * [qty]',
	];
	$fields = [
		[ 'id' => 'CountID', 'type' => 'number', 'label' => 'Count' ],
		[ 'id' => 'FeeID', 'type' => 'select', 'label' => 'Fee', 'choices' => [ [ 'slug' => 'fee', 'label' => 'Fee', 'pricing_type' => 'fixed', 'pricing_amount' => 8 ] ] ],
		[ 'id' => 'FlagsID', 'type' => 'checkboxes', 'label' => 'Flags', 'choices' => [ [ 'slug' => 'a', 'label' => 'A' ], [ 'slug' => 'b', 'label' => 'B' ] ] ],
		[ 'id' => 'ChoiceID', 'type' => 'select', 'label' => 'Formula choice', 'choices' => [] ],
	];
	foreach ( $cases as $slug => $formula ) {
		$fields[3]['choices'][] = [ 'slug' => $slug, 'label' => $slug, 'pricing_type' => 'fx', 'pricing_amount' => $formula ];
		$fields[] = [ 'id' => 'Scalar' . count( $fields ), 'type' => 'text', 'label' => 'Scalar ' . $slug, 'pricing' => [ 'enabled' => true, 'type' => 'fx', 'amount' => $formula ] ];
	}
	foreach ( $fields as &$field ) { $field['conditionals'] = []; }
	unset( $field );
	$raw = [ 'fields' => $fields, 'conditions' => [], 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ], 'variables' => [] ];
	$state = [ 'source' => 0, 'target' => 0, 'cases' => $cases, 'owned' => [] ];
	update_option( 'opf_formula_roundtrip_state', $state );
	try {
	foreach ( [ 'source' => 'Formula native source', 'target' => 'Formula Tools destination' ] as $key => $title ) {
		$id = wp_insert_post( [ 'post_type' => 'wapf_product', 'post_status' => 'draft', 'post_title' => $title ], true );
		$assert( ! is_wp_error( $id ) && $id > 0, 'Could not create ' . $key . ' fixture.' );
		$state[ $key ] = $id;
		$state['owned'][] = $id;
		update_option( 'opf_formula_roundtrip_state', $state );
		if ( $key === getenv( 'OPF_FORMULA_ROUNDTRIP_FAIL_SETUP_AFTER' ) ) { throw new RuntimeException( 'Injected setup failure after ' . $key ); }
	}
	$source = $state['source'];
	$model = Field_Groups::raw_json_to_field_group( $raw + [ 'id' => $source, 'type' => 'wapf_product' ] );
	Field_Groups::save( $model, 'wapf_product', $source, 'Formula native source', 'publish' );
	$write( 'wapf-model-export.json', [ 'fields' => Field_Groups::field_group_to_raw_fields_json( Field_Groups::get_by_id( $source ) ), 'conditions' => $model->rules_groups, 'layout' => $model->layout, 'variables' => $model->variables ] );
	if ( 'export' === getenv( 'OPF_FORMULA_ROUNDTRIP_FAIL_SETUP_AFTER' ) ) { throw new RuntimeException( 'Injected setup failure after export' ); }
	$write( 'native-seed.json', $raw );
	$write( 'state.json', $state );
	} catch ( Throwable $error ) {
		foreach ( $state['owned'] as $id ) { wp_delete_post( $id, true ); }
		delete_option( 'opf_formula_roundtrip_state' );
		FieldGroups::flush_cache();
		throw $error;
	}
	echo wp_json_encode( $state );
	return;
}
$assert( ! empty( $state ), 'Missing fixture state.' );
if ( 'cleanup' === $mode ) {
	$ids = array_unique( array_map( 'intval', $state['owned'] ) );
	foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
	foreach ( $ids as $id ) { $assert( ! get_post( $id ), 'Cleanup left post ' . $id ); }
	delete_option( 'opf_formula_roundtrip_state' );
	FieldGroups::flush_cache();
	$assert( 0 === (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type IN ('wapf_product','opf_field_group','product')" ), 'Cleanup left fixture records.' );
	echo 'Cleaned all owned products, WAPF groups, OPF groups and fixture option.';
	return;
}
$assert( 'verify' === $mode, 'Unknown mode.' );
$native_export = json_decode( file_get_contents( $dir . '/wapf-model-export.json' ), true );
// Native Tools UI requires a license in this installed package. Exercise its
// actual PHP raw converter/save/export path without changing license state.
$first_map = WapfMapper::map( Field_Groups::get_by_id( $state['source'] )->to_array() );
$native_import = WapfExporter::build_payload( $first_map['group'] );
$model = Field_Groups::raw_json_to_field_group( $native_import + [ 'id' => $state['target'], 'type' => 'wapf_product' ] );
Field_Groups::save( $model, 'wapf_product', $state['target'], 'Formula model destination', 'publish' );
$reloaded = Field_Groups::get_by_id( $state['target'] );
$assert( 'count' === $reloaded->fields[0]->id, 'OPF exported field IDs were not persisted by native converter.' );
$write( 'wapf-model-reexport.json', [ 'fields' => Field_Groups::field_group_to_raw_fields_json( $reloaded ), 'conditions' => $reloaded->rules_groups, 'layout' => $reloaded->layout, 'variables' => $reloaded->variables ] );
$raw_reexport = json_decode( file_get_contents( $dir . '/wapf-model-reexport.json' ), true );
$from_raw_export = Field_Groups::raw_json_to_field_group( $raw_reexport + [ 'id' => $state['target'], 'type' => 'wapf_product' ] );
$assert( $first_map['group'] === WapfMapper::map( $from_raw_export->to_array() )['group'], 'Native raw export reimport changed mapped OPF group data.' );
$product = new WC_Product_Simple();
$product->set_name( 'Formula local import fixture' );
$product->set_regular_price( '100' );
$product_id = $product->save();
$state['owned'][] = $product_id;
update_option( 'opf_formula_roundtrip_state', $state );
update_post_meta( $product_id, '_wapf_fieldgroup', Field_Groups::get_by_id( $state['source'] )->to_array() );
$dry = Importer::run( false );
$assert( 3 === $dry['imported'] && 0 === $dry['skipped'], 'Legacy importer dry-run expected two global and one local source.' );
$assert( ! get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any' ] ), 'Legacy dry-run wrote groups.' );
$committed = Importer::run( true );
foreach ( $committed['groups'] as $entry ) { if ( ! empty( $entry['opf_id'] ) ) { $state['owned'][] = $entry['opf_id']; } }
update_option( 'opf_formula_roundtrip_state', $state );
$assert( 3 === $committed['imported'] && 0 === $committed['skipped'], 'Legacy import failed.' );
$groups = [];
foreach ( $committed['groups'] as $entry ) {
	$assert( ! $entry['needs_review'], 'Supported formula import unexpectedly needs review: ' . wp_json_encode( $entry ) );
	$groups[ (string) $entry['source'] ] = FieldGroups::group_from_post( get_post( $entry['opf_id'] ) )->data;
}
$again = Importer::run( true );
$assert( 0 === $again['imported'] && 3 === $again['skipped'], 'Legacy repeated import is not idempotent.' );
$group = $groups[ (string) $state['source'] ];
$remapped_group = $groups[ (string) $state['target'] ];
$assert( $group === $remapped_group, 'Native model round-trip changed mapped OPF group.' );
$local = $groups[ 'meta:' . $product_id ];
unset( $local['rule_groups'] );
$global = $group;
unset( $global['rule_groups'] );
$assert( $local === $global, 'Local import changed formula data.' );
$tools = WapfExporter::build_payload( $group );
$write( 'opf-wapf-tools-export.json', $tools );
// Export canonicalizes case before native Tools performs literal ID replacement.
$case_group = $group;
$case_group['fields'][3]['choices'][5]['pricing']['formula_raw'] = '[FIELD.COUNT] + [PRICE.FEE]';
$case_payload = WapfExporter::build_payload( $case_group );
$assert( '[field.count] + [price.fee]' === $case_payload['fields'][3]['choices'][5]['pricing_amount'], 'Export did not canonicalize token and ID casing.' );
$write( 'opf-casing-export.json', $case_payload );
$source_opf = (int) $committed['groups'][0]['opf_id'];
$package = Exporter::build_package( [ [ 'id' => $source_opf, 'title' => 'Formula archive copy', 'status' => 'draft', 'data' => $group ] ], [ 'type' => 'group', 'id' => $source_opf ] );
$decoded = ArchiveImporter::decode( wp_json_encode( $package ) );
$archive_dry = ArchiveImporter::import( $decoded, false );
$assert( 1 === $archive_dry['imported'], 'OPF archive dry-run failed.' );
$archive = ArchiveImporter::import( $decoded, true );
$archive_id = (int) $archive['groups'][0]['opf_id'];
$state['owned'][] = $archive_id;
update_option( 'opf_formula_roundtrip_state', $state );
$assert( $group === FieldGroups::group_from_post( get_post( $archive_id ) )->data, 'OPF archive changed formula bytes/data.' );
$assert( 1 === ArchiveImporter::import( $decoded, true )['skipped'], 'OPF archive repeat is not idempotent.' );
$write( 'opf-archive.json', $package );
$rows = [];
foreach ( [ 1, 4 ] as $qty ) {
	$cart_fields = [
		[ 'id' => 'CountID', 'type' => 'number', 'clone_idx' => 0, 'values' => [ [ 'label' => '3' ] ] ],
		[ 'id' => 'FeeID', 'type' => 'select', 'clone_idx' => 0, 'values' => [ [ 'label' => 'Fee', 'slug' => 'fee', 'calc_price' => 8 / $qty ] ] ],
		[ 'id' => 'FlagsID', 'type' => 'checkboxes', 'clone_idx' => 0, 'values' => [ [ 'label' => 'A' ], [ 'label' => 'B' ] ] ],
	];
	$values = [ 'count' => '3', 'fee' => 'fee', 'flags' => [ 'a', 'b' ] ];
	$prices = [ 'fee' => 8 / $qty ];
	$roundtrip_fields = $cart_fields;
	foreach ( $roundtrip_fields as &$cart_field ) { $cart_field['id'] = [ 'CountID' => 'count', 'FeeID' => 'fee', 'FlagsID' => 'flags' ][ $cart_field['id'] ]; }
	unset( $cart_field );
	foreach ( array_values( $state['cases'] ) as $index => $formula ) {
		$native = Fields::do_pricing( false, 'fx', $formula, 100, 100, $qty, '3', $product_id, $cart_fields, [ $state['source'] ], 0, 8 / $qty );
		$native_choice_back = Fields::do_pricing( false, 'fx', $from_raw_export->fields[3]->options['choices'][ $index ]['pricing_amount'], 100, 100, $qty, '3', $product_id, $roundtrip_fields, [ $state['target'] ], 0, 8 / $qty );
		$native_field_back = Fields::do_pricing( false, 'fx', $from_raw_export->fields[4 + $index ]->pricing->amount, 100, 100, $qty, '3', $product_id, $roundtrip_fields, [ $state['target'] ], 0, 8 / $qty );
		$choice = $group['fields'][3]['choices'][ $index ]['pricing'];
		$field = $group['fields'][4 + $index ]['pricing'];
		$actual_choice = Calculator::choice_addon( $choice, 100, $qty, 8 / $qty, $values, $product_id, $prices );
		$actual_field = Calculator::field_pricing_addon( $field, '3', 100, $qty, 8 / $qty, $values, $product_id, $prices );
		$assert( 0.0 === Calculator::field_pricing_addon( $field, ' ', 100, $qty, 8 / $qty, $values, $product_id, $prices ), 'PHP scalar empty-input guard failed.' );
		$assert( abs( $native - $actual_choice ) < 0.000001 && abs( $native - $actual_field ) < 0.000001, 'Native/OPF formula mismatch: ' . wp_json_encode( [ $formula, $qty, $native, $actual_choice, $actual_field ] ) );
		$assert( abs( $native - $native_choice_back ) < 0.000001 && abs( $native - $native_field_back ) < 0.000001, 'Native pricing changed after export/import model round-trip.' );
		$rows[] = [ 'case' => array_keys( $state['cases'] )[ $index ], 'qty' => $qty, 'native' => $native, 'native_roundtrip_choice' => $native_choice_back, 'native_roundtrip_field' => $native_field_back, 'php_choice' => $actual_choice, 'php_field' => $actual_field, 'choice' => $choice, 'field' => $field, 'values' => $values, 'prices' => $prices, 'addons' => 8 / $qty ];
	}
}
$boundaries = [];
foreach ( [ 'if(2 < 5; 1; 2)', 'len(abc)', 'datediff(01-10-2023; 01-12-2023)', 'today()', 'lookuptable(table; 1)', 'sumQty(FlagsID)', 'files(FlagsID)', '[field.COUNTID] + [price.FEEID]', '[field.missing]' ] as $formula ) {
	$raw = $native_export;
	$raw['fields'][3]['choices'] = [ [ 'slug' => 'boundary', 'label' => 'Boundary', 'pricing_type' => 'fx', 'pricing_amount' => $formula ] ];
	$boundary_model = Field_Groups::raw_json_to_field_group( $raw + [ 'id' => $state['source'], 'type' => 'wapf_product' ] );
	$mapped = WapfMapper::map( $boundary_model->to_array() );
	$pricing = $mapped['group']['fields'][3]['choices'][0]['pricing'];
	$retained = in_array( $formula, [ 'sumQty(FlagsID)', 'files(FlagsID)' ], true );
	$assert( ( $retained ? 'formula' : 'none' ) === $pricing['type'], 'Unexpected retained/dropped behavior: ' . $formula );
	$expected_notes = [];
	if ( $retained ) {
		$remapped = str_replace( 'FlagsID', 'flags', $formula );
		$assert( $remapped === $pricing['formula_raw'] && $remapped === $pricing['formula'], 'Retained boundary formula lost remapping.' );
		$expected_notes[] = 'field "Boundary" formula contains references whose runtime behavior is not implemented yet (' . strtolower( $remapped ) . '); pricing needs review.';
	} else {
		$assert( '' === $pricing['formula'] && '' === $pricing['formula_raw'] && 0.0 === (float) $pricing['amount'], 'Dropped boundary retained pricing.' );
		if ( '[field.COUNTID] + [price.FEEID]' === $formula || '[field.missing]' === $formula ) {
			$ids = '[field.missing]' === $formula ? 'missing' : 'COUNTID, FEEID';
			$expected_notes[] = 'field "Boundary" formula references unavailable or ambiguous WAPF field IDs (' . $ids . '); pricing needs review.';
		}
		$expected_notes[] = 'choice "Boundary" formula could not be translated: ' . $formula;
	}
	$assert( $expected_notes === $mapped['notes'], 'Unexpected boundary notes: ' . wp_json_encode( $mapped['notes'] ) );
	$boundaries[] = [ 'formula' => $formula, 'expected_behavior' => $retained ? 'formula-retained-with-review' : 'pricing-dropped-with-review', 'needs_review' => $mapped['needs_review'], 'pricing' => $pricing, 'notes' => $mapped['notes'] ];
	$assert( $mapped['needs_review'], 'Expected explicit boundary review for ' . $formula );
}
$write( 'php-results.json', [ 'context_provenance' => 'Fixture-authored synthetic base/addons/field-value/field-price contexts; production cart/storefront context construction is out of scope.', 'environment' => [ 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'database' => FQDB, 'opf' => realpath( OPF_DIR ), 'functions' => Helper::get_all_formula_functions() ], 'scalar_empty_input_guards' => count( $rows ), 'raw_export_reimport_exact_opf_group' => true, 'rows' => $rows, 'boundaries' => $boundaries, 'legacy_dry' => $dry, 'legacy_committed' => $committed, 'archive' => $archive ] );
echo 'PHP: 24 synthetic-context WAPF evaluator comparisons pass for choice/scalar pricing and reimported raw-export formulas; scalar empty-input guards, persisted global/local import, exact archive, repeats, casing and 9 precise retained/dropped review checks pass.';
