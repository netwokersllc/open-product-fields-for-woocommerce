<?php
/**
 * Standalone calculator preservation matrix, runnable on PHP 7.1+.
 * Usage: php bin/probe-calculator-php71.php [path/to/Calculator.php]
 * Compare stdout from the original and backported sources with cmp.
 * Every result includes its exact IEEE-754 bytes, not rounded money.
 * This verifies calculator behavior; it is not a whole-plugin runtime gate.
 */

define( 'ABSPATH', __DIR__ . '/' );
function current_time( $format ) { return '2026-10-02'; }
function get_option( $name, $default = false ) { return $GLOBALS['calculator_options'][ $name ] ?? $default; }
function apply_filters( $name, $value, $product_id ) {
	return 'opf_formula_base_price' === $name ? $value * ( 7 === $product_id ? 1.25 : 1 ) : $value;
}
$calculator_path = $argv[1] ?? dirname( __DIR__ ) . '/includes/Engine/Calculator.php';
require_once dirname( $calculator_path ) . '/DateFormat.php';
require $calculator_path;

use OPF\Engine\Calculator;

// Runtime callbacks exercise captured context, nested expansion, failures,
// boolean returns, invalid output, and callback replacement.
Calculator::register_formula_function( 'probe_context', static function ( array $args, array $context ) {
	return (float) ( $args[0] ?? 0 ) + $context['price'] + $context['qty'] + $context['addons'] + $context['product_id'];
} );
Calculator::register_formula_function( 'probe_twice', static function ( array $args ) { return 2 * (float) ( $args[0] ?? 0 ); } );
Calculator::register_formula_function( 'probe_throw', static function () { throw new RuntimeException( 'Expected failure' ); } );
Calculator::register_formula_function( 'probe_bad', static function () { return [ 1 ]; } );
Calculator::register_formula_function( 'probe_inf', static function () { return INF; } );
Calculator::register_formula_function( 'probe_true', static function () { return true; } );
Calculator::register_formula_function( 'probe_replace', static function () { return 1; } );
Calculator::register_formula_function( 'probe_replace', static function () { return 2; } );

$fields = [ 'count' => '2', 'size' => 'Large', 'tags' => [ 'red', 'blue' ], 'start' => '2024-01-01', 'end' => '2024-02-29',
	'images' => [ '_opf_type' => 'image_quantity', 'quantities' => [ 'a' => 2, 'b' => '3', 'c' => -1, 'd' => '1.5', 'e' => [], 'f' => '00', 'g' => null, 'h' => true ] ] ];
$prices = [ 'plan' => 5.5, 'repeat' => [ 2, 3.25, -1 ], 'bad' => 'not numeric' ];
$formulas = [
	'[price] * 0.1 + [qty] * 2', '(([price] + [options_total]) * 0.2)', '[addons]+[val]', '-3', '-(2+4)/3',
	'min(5;1;3)', 'max(5;8;3)', 'min()', 'max()', 'min([price];[qty];[addons])', 'max([price];[qty];[addons])',
	'min(max(2;3); round(2.546;2))', 'len(a quick brown fox)', 'len(a quick brown fox;true)', 'len()',
	'checked(TAGS)', 'checked(missing)', 'sumQty(images)', 'sumQty(tags)', 'round(2.546;2)', 'round(-2.5)', 'round(1234.567;-2)',
	'abs([val])', 'floor(2.9)', 'ceil(-2.1)', 'sqrt(9)', 'sqrt(-1)+20', 'pow(2;3)', 'pow(2)', 'pow(2;1024)',
	'sin(0)', 'cos(0)', 'tan(0)', 'sin([qty])+cos([addons])+tan(0)',
	'if(2<5;10;20)', 'if(or(2<1;3>=3);20;10)', 'if(and(2<1;3>=3);20;10)', 'if(and();1;2)', 'if(or();1;2)',
	'if([field.size]=Large;10;20)', 'if(2!=3;true;false)', 'if(2<=2;10;20)', 'if(3>2;10;20)', 'if(false;10;20)',
	'if(2=2;min(3;8);max(7;9))', 'if(1;2)', '[field.count]*3', '[price.plan]+[price.repeat]', '[price.bad]+1', '[price.MISSING]+1',
	'dow([field.start])', 'month([field.end])', 'dow([val])', 'month(today())', "datediff('01-10-2023';'01-12-2023')",
	"datediff('02-30-2023';'03-01-2023')", "dow('2024-02-29')", "month('2024-02-30')",
	'probe_context(2)', 'probe_twice(probe_twice(2))', 'probe_throw(1)+10', 'probe_bad()+10', 'probe_inf()+10', 'probe_true()+1', 'probe_replace()',
	'', '1/0', 'system(1)', '2+unknown', '[price]*', '((2+1)', '2)', '2..3', 'sqrt(-1)', 'unknown(1)',
];
$contexts = [ [ 0.0, 1, 0.0, '', 0 ], [ 100.0, 3, 10.0, '-4', 0 ], [ 19.99, 0, -5.0, '2024-01-01', 7 ], [ -2.5, 5, 0.125, '2.5', 7 ] ];
$results = [];
foreach ( $contexts as $context_index => $context ) {
	foreach ( $formulas as $formula_index => $formula ) {
		$results[ 'formula:' . $context_index . ':' . $formula_index ] = Calculator::evaluate_formula( $formula, $context[0], $context[1], $context[2], $context[3], '2026-10-02', $fields, $context[4], $prices );
	}
}
foreach ( [ 'dd/mm/yyyy' => '31/12/2023', 'd.m.yy' => '1.3.23', 'yyyy-mm-dd' => '2024-02-29', 'invalid' => '31/12/2023' ] as $format => $value ) {
	$GLOBALS['calculator_options']['opf_date_format'] = $format;
	foreach ( [ 'dow([val])', 'month([val])', 'datediff([val];today())' ] as $formula ) {
		$results[ 'date:' . $format . ':' . $formula ] = Calculator::evaluate_formula( $formula, 10, 1, 0, $value, '2026-10-02' );
	}
}
unset( $GLOBALS['calculator_options']['opf_date_format'] );

$pricing_matrix = [
	[ 'type' => 'fixed', 'amount' => 5.0, 'per_unit' => false ],
	[ 'type' => 'fixed', 'amount' => -2.0, 'per_unit' => true ],
	[ 'type' => 'percent', 'amount' => 12.5 ],
	[ 'type' => 'formula', 'formula' => 'max([price];[addons])+[qty]+[price.plan]+[field.count]' ],
	[ 'type' => 'formula', 'formula' => '-50' ],
	[ 'type' => 'none' ],
];
foreach ( $pricing_matrix as $pricing_index => $pricing ) {
	foreach ( [ 0, 1, 3, 99 ] as $qty ) {
		$context = [ 'price' => 19.99, 'qty' => $qty, 'addons' => 2.0, 'field_values' => $fields, 'field_prices' => $prices, 'product_id' => 7 ];
		$prefix = 'price:' . $pricing_index . ':' . $qty . ':';
		$results[ $prefix . 'choice' ] = Calculator::choice_addon( $pricing, 19.99, $qty, 2.0, $fields, 7, $prices );
		foreach ( [ 'text', 'textarea' ] as $type ) {
			$field = [ 'type' => $type, 'pricing' => $pricing ];
			$results[ $prefix . $type ] = Calculator::field_addon( $field, '2', $context );
			$results[ $prefix . $type . ':blank' ] = Calculator::field_addon( $field, ' ', $context );
			$field['repeat'] = [ 'enabled' => true ];
			$results[ $prefix . $type . ':repeat' ] = Calculator::field_addon( $field, [ '2', '', '-1' ], $context );
		}
		foreach ( [ 'select', 'radio', 'checkbox', 'swatch', 'image_quantity' ] as $type ) {
			$field = [ 'type' => $type, 'multiple' => true, 'choices' => [
				[ 'slug' => 'a', 'disabled' => false, 'pricing' => $pricing ],
				[ 'slug' => 'b', 'disabled' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 999 ] ],
				[ 'slug' => 'c', 'disabled' => false, 'pricing' => $pricing ],
			] ];
			$value = 'image_quantity' === $type ? [ '_opf_type' => 'image_quantity', 'quantities' => [ 'a' => 2, 'b' => 10, 'c' => 3 ] ] : [ 'a', 'b', 'c', 'missing' ];
			$results[ $prefix . $type ] = Calculator::field_addon( $field, $value, $context );
			$field['repeat'] = [ 'enabled' => true ];
			$results[ $prefix . $type . ':repeat' ] = Calculator::field_addon( $field, [ $value, $value ], $context );
		}
	}
}

// Explicit oracles avoid allowing identical wrong output to pass every check.
$oracles = [ 'formula:0:' . array_search( 'min(5;1;3)', $formulas, true ) => 1.0,
	'formula:0:' . array_search( 'max(5;8;3)', $formulas, true ) => 8.0,
	'formula:0:' . array_search( 'checked(TAGS)', $formulas, true ) => 2.0,
	'formula:0:' . array_search( 'sumQty(images)', $formulas, true ) => 6.0,
	'formula:0:' . array_search( 'sqrt(-1)+20', $formulas, true ) => 0.0,
	'price:0:3:choice' => 5.0 / 3, 'price:0:3:text:blank' => 0.0, 'price:1:1:text' => 0.0, 'price:5:1:checkbox' => 0.0 ];
foreach ( $oracles as $key => $expected ) {
	if ( $expected !== $results[ $key ] ) {
		fwrite( STDERR, 'Oracle failed: ' . $key . "\n" );
		exit( 1 );
	}
}
foreach ( $results as $key => $value ) {
	if ( ! is_float( $value ) || ! is_finite( $value ) ) {
		fwrite( STDERR, 'Non-finite/non-float result: ' . $key . "\n" );
		exit( 1 );
	}
	echo $key, ' ', bin2hex( pack( 'E', $value ) ), "\n";
}
fwrite( STDERR, count( $results ) . ' finite float outputs; ' . count( $oracles ) . " explicit oracles passed\n" );
