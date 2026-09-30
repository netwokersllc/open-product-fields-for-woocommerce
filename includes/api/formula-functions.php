<?php
/**
 * Public formula extension API.
 *
 * @package open-product-fields-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'opf_add_formula_function' ) ) {
	/**
	 * Register a numeric function callable from OPF pricing formulas.
	 *
	 * The callback receives `(float[] $arguments, array $context)` and must
	 * return a finite numeric result. Registration lasts for this PHP request.
	 *
	 * @param string   $name     ASCII function name.
	 * @param callable $callback Callback for evaluated arguments.
	 */
	function opf_add_formula_function( string $name, callable $callback ): bool {
		return \OPF\Engine\Calculator::add_formula_function( $name, $callback );
	}
}
