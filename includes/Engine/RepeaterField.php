<?php
/**
 * Canonical configuration for repeated field and section instances.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class RepeaterField {
	/** Keep configured button repeats inside a bounded request size. */
	public const MAX_BUTTON_ROWS = 1000;

	/** @return array<string,mixed> */
	public static function normalize( $raw ): array {
		if ( null === $raw || [] === $raw ) {
			return [];
		}
		if ( ! is_array( $raw ) ) {
			throw new \InvalidArgumentException( 'Repeater settings must be an object.' );
		}
		$unknown = array_diff( array_keys( $raw ), [ 'enabled', 'mode', 'max' ] );
		if ( $unknown ) {
			throw new \InvalidArgumentException( 'Repeater settings contain unsupported keys.' );
		}
		$enabled = $raw['enabled'] ?? false;
		if ( ! in_array( $enabled, [ true, false, 1, 0, '1', '0' ], true ) ) {
			throw new \InvalidArgumentException( 'Repeater enabled setting must be boolean.' );
		}
		if ( ! in_array( $enabled, [ true, 1, '1' ], true ) ) {
			return [];
		}
		$mode = (string) ( $raw['mode'] ?? 'button' );
		if ( 'qty' === $mode ) {
			$mode = 'quantity';
		}
		if ( ! in_array( $mode, [ 'button', 'quantity' ], true ) ) {
			throw new \InvalidArgumentException( 'Repeater mode must be button or quantity.' );
		}
		if ( 'quantity' === $mode ) {
			return [ 'enabled' => true, 'mode' => 'quantity' ];
		}
		$max = $raw['max'] ?? 5;
		if ( ! is_int( $max ) && ! ( is_string( $max ) && preg_match( '/^[0-9]+$/', $max ) ) ) {
			throw new \InvalidArgumentException( 'Button repeater maximum must be an integer.' );
		}
		$max = (int) $max;
		if ( $max < 1 || $max > self::MAX_BUTTON_ROWS ) {
			throw new \InvalidArgumentException( sprintf( 'Button repeater maximum must be between 1 and %d.', self::MAX_BUTTON_ROWS ) );
		}
		return [ 'enabled' => true, 'mode' => 'button', 'max' => $max ];
	}
}
