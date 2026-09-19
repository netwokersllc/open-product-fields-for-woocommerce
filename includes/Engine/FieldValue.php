<?php
/**
 * Canonical submitted-value behavior for fields with non-text semantics.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class FieldValue {

	/**
	 * Normalize scalar values after the transport layer has sanitized them.
	 *
	 * A malformed, non-empty email remains a value here so validation can
	 * distinguish it from an omitted optional field and return the right error.
	 *
	 * @param array<string,mixed> $field Field definition.
	 * @param mixed               $value Submitted scalar value.
	 * @return string|null
	 */
	public static function sanitize( array $field, $value ): ?string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		if ( 'toggle' === ( $field['type'] ?? '' ) ) {
			return in_array( $value, [ true, 1, '1', 'true', 'on', 'yes' ], true ) ? '1' : '0';
		}

		$text = trim( (string) $value );
		return '' === $text ? null : $text;
	}

	/**
	 * Return server-side validation messages for a submitted field value.
	 *
	 * @param array<string,mixed> $field    Field definition.
	 * @param string|null         $value    Sanitized value.
	 * @param bool                $provided Whether this input appeared in the payload.
	 * @return string[]
	 */
	public static function validate( array $field, ?string $value, bool $provided ): array {
		$label = (string) ( $field['label'] ?? '' );
		$type  = (string) ( $field['type'] ?? '' );

		if ( 'toggle' === $type ) {
			if ( ! empty( $field['required'] ) && ( ! $provided || '1' !== $value ) ) {
				return [ sprintf( '"%s" is a required field.', $label ) ];
			}
			return [];
		}

		if ( ! $provided || null === $value ) {
			return ! empty( $field['required'] ) ? [ sprintf( '"%s" is a required field.', $label ) ] : [];
		}

		if ( 'email' === $type && false === filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			return [ sprintf( '"%s" must be a valid email address.', $label ) ];
		}

		return [];
	}
}
