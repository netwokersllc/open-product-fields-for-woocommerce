<?php
/** Focused server regression; WordPress lookup is stubbed, not commerce proof. */
require dirname( __DIR__ ) . '/tests/bootstrap.php';

class WC_Product {
	public function get_parent_id(): int { return 0; }
	public function get_id(): int { return 42; }
}
function get_posts( $args = [] ): array { return []; }
function wc_get_product_term_ids( $id, $taxonomy ): array { return []; }
function apply_filters( $tag, $value, ...$args ) {
	return 'opf_groups_for_product' === $tag ? $GLOBALS['price_id_groups'] : $value;
}

$group = static function ( string $id, array $fields ): array {
	return [ 'id' => $id, 'group' => new OPF\Engine\FieldGroup( [ 'fields' => $fields ] ) ];
};
$source = [ 'id' => 'source', 'type' => 'text', 'label' => 'Source', 'pricing' => [ 'type' => 'fixed', 'amount' => 7 ] ];
$duplicate = array_replace( $source, [ 'pricing' => [ 'type' => 'fixed', 'amount' => 11 ] ] );
$derived = [ 'id' => 'derived', 'type' => 'text', 'label' => 'Derived', 'pricing' => [ 'type' => 'formula', 'formula' => '[price.source] * 2' ] ];
$base_values = [ 'early' => [ 'source' => 'first' ], 'duplicate' => [ 'source' => 'later' ], 'later' => [ 'derived' => '2' ] ];
$check = static function ( string $name, array $fields, array $values, float $expected ) use ( $group, $duplicate, $derived ): void {
	OPF\Service\FieldGroups::flush_cache();
	$GLOBALS['price_id_groups'] = [ $group( 'early', $fields ), $group( 'duplicate', [ $duplicate ] ), $group( 'later', [ $derived ] ) ];
	$actual = OPF\Service\CartIntegration::addons_per_unit( new WC_Product(), $values, 100, 1 );
	if ( $expected !== $actual ) {
		throw new RuntimeException( "$name: expected $expected, got $actual" );
	}
	fwrite( STDOUT, "ok $name\n" );
};
$check( 'visible first duplicate keeps its price', [ $source ], $base_values, 32.0 );
$zero = array_replace( $source, [ 'pricing' => [ 'type' => 'fixed', 'amount' => 0 ] ] );
$check( 'zero first duplicate does not fall through', [ $zero ], $base_values, 11.0 );
$hidden = array_replace( $source, [ 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'gate', 'operator' => 'eq', 'value' => 'yes' ] ] ] ] ] );
$check( 'excluded hidden first source uses later actual priced source', [ $hidden ], $base_values, 33.0 );
$without_field = $base_values;
$without_field['early'] = [];
$check( 'missing first field uses later actual source', [ $hidden ], $without_field, 33.0 );
$without_group = $base_values;
unset( $without_group['early'] );
$check( 'missing first group uses later actual source', [ $hidden ], $without_group, 33.0 );
