<?php
/**
 * Declarative fixtures for OPF's WAPF replacement capability ledger.
 *
 * A fixture is intentionally data-only. Later feature slices can execute the
 * declared request/result through browser and WooCommerce harnesses without
 * making the acceptance contract depend on a particular test implementation.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class CapabilityFixtureRegistry {

	/**
	 * Flows named by the capability ledger's promotion rule.
	 */
	public const FLOWS = [
		'storefront',
		'submission',
		'server_pricing',
		'classic_cart',
		'block_cart_checkout',
		'order_storage',
		'order_email_meta',
		'order_again',
	];

	/**
	 * Return all implemented capability fixtures, keyed by stable ledger ID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$fixtures = [
			'WAPF-FIELD-TEXT' => [
				'ledger_id' => 'WAPF-FIELD-TEXT',
				'title'     => 'Text field lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'       => 'engraving',
							'label'    => 'Engraving',
							'type'     => 'text',
							'required' => true,
						],
					],
				],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [
						[
							'id'           => 'engraving',
							'label'        => 'Engraving',
							'description'  => '',
							'type'         => 'text',
							'required'     => true,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'engraving' => 'Ada' ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-SWATCH-TEXT' => [
				'ledger_id' => 'WAPF-FIELD-SWATCH-TEXT',
				'title'     => 'Priced text swatch lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'    => 'finish',
							'label' => 'Finish',
							'type'  => 'swatch',
							'choices' => [
								[
									'slug'    => 'gold',
									'label'   => 'Gold',
									'pricing' => [ 'type' => 'fixed', 'amount' => 3 ],
								],
							],
						],
					],
				],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [
						[
							'id'           => 'finish',
							'label'        => 'Finish',
							'description'  => '',
							'type'         => 'swatch',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [
								[
									'slug'     => 'gold',
									'label'    => 'Gold',
									'selected' => false,
									'disabled' => false,
									'pricing'  => [ 'type' => 'fixed', 'amount' => 3.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => false ],
								],
							],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'finish' => 'gold' ],
					'addon_per_unit'  => 3.0,
				],
				'supported_flows' => self::FLOWS,
			],
		];

		foreach ( $fixtures as $id => $fixture ) {
			self::validate( $fixture );
			if ( $id !== $fixture['ledger_id'] ) {
				throw new \InvalidArgumentException( 'Capability fixture key must match ledger_id.' );
			}
		}

		return $fixtures;
	}

	/**
	 * Validate one capability fixture and return it unchanged.
	 *
	 * @param array<string,mixed> $fixture Fixture declaration.
	 * @return array<string,mixed>
	 */
	public static function validate( array $fixture ): array {
		foreach ( [ 'ledger_id', 'title', 'field_group', 'expected_normalization', 'expected_result', 'supported_flows' ] as $key ) {
			if ( ! array_key_exists( $key, $fixture ) ) {
				throw new \InvalidArgumentException( 'Capability fixture requires ' . $key . '.' );
			}
		}

		if ( ! is_string( $fixture['ledger_id'] ) || ! preg_match( '/^WAPF-[A-Z0-9-]+$/', $fixture['ledger_id'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture ledger_id must be a WAPF ledger ID.' );
		}
		if ( ! is_string( $fixture['title'] ) || '' === trim( $fixture['title'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture title must be a non-empty string.' );
		}
		if ( ! is_array( $fixture['field_group'] ) || empty( $fixture['field_group']['fields'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture field_group must contain fields.' );
		}
		if ( ! is_array( $fixture['expected_normalization'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture expected_normalization must be an array.' );
		}
		if ( FieldGroup::normalize( $fixture['field_group'] ) !== $fixture['expected_normalization'] ) {
			throw new \InvalidArgumentException( 'Capability fixture expected_normalization must match FieldGroup::normalize().' );
		}
		if ( ! is_array( $fixture['expected_result'] ) || empty( $fixture['expected_result'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture expected_result must be a non-empty array.' );
		}
		if ( ! isset( $fixture['expected_result']['submitted_values'] ) || ! is_array( $fixture['expected_result']['submitted_values'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture expected_result must declare submitted_values.' );
		}
		if ( isset( $fixture['expected_result']['addon_per_unit'] ) && ! is_numeric( $fixture['expected_result']['addon_per_unit'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture expected_result addon_per_unit must be numeric.' );
		}
		if ( ! is_array( $fixture['supported_flows'] ) || empty( $fixture['supported_flows'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture supported_flows must be a non-empty array.' );
		}
		foreach ( $fixture['supported_flows'] as $flow ) {
			if ( ! is_string( $flow ) || ! in_array( $flow, self::FLOWS, true ) ) {
				throw new \InvalidArgumentException( 'Capability fixture supported_flows contains an unknown flow.' );
			}
		}
		if ( count( $fixture['supported_flows'] ) !== count( array_unique( $fixture['supported_flows'] ) ) ) {
			throw new \InvalidArgumentException( 'Capability fixture supported_flows must not contain duplicates.' );
		}

		return $fixture;
	}
}
