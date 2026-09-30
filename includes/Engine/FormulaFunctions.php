<?php
/**
 * Built-in numeric formula functions shared by imported WAPF pricing.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class FormulaFunctions {

	/** Register the numeric functions available in WAPF Extended formulas. */
	public static function init(): void {
		$functions = [
			'abs'   => static function ( array $args ) {
				return 1 === count( $args ) ? abs( $args[0] ) : NAN;
			},
			'ceil'  => static function ( array $args ) {
				return 1 === count( $args ) ? ceil( $args[0] ) : NAN;
			},
			'cos'   => static function ( array $args ) {
				return 1 === count( $args ) ? cos( $args[0] ) : NAN;
			},
			'floor' => static function ( array $args ) {
				return 1 === count( $args ) ? floor( $args[0] ) : NAN;
			},
			'max'   => static function ( array $args ) {
				return $args ? max( $args ) : NAN;
			},
			'min'   => static function ( array $args ) {
				return $args ? min( $args ) : NAN;
			},
			'pow'   => static function ( array $args ) {
				return 2 === count( $args ) ? pow( $args[0], $args[1] ) : NAN;
			},
			'round' => static function ( array $args ) {
				return in_array( count( $args ), [ 1, 2 ], true ) ? round( $args[0], (int) ( $args[1] ?? 0 ) ) : NAN;
			},
			'sin'   => static function ( array $args ) {
				return 1 === count( $args ) ? sin( $args[0] ) : NAN;
			},
			'sqrt'  => static function ( array $args ) {
				return 1 === count( $args ) ? sqrt( $args[0] ) : NAN;
			},
			'tan'   => static function ( array $args ) {
				return 1 === count( $args ) ? tan( $args[0] ) : NAN;
			},
		];

		foreach ( $functions as $name => $callback ) {
			Calculator::add_formula_function( $name, $callback );
		}
	}
}
