<?php
/**
 * Public field-group helpers.
 *
 * @package open-product-fields-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

use OPF\Engine\FieldGroup;
use OPF\Service\FieldGroups;
use OPF\Service\Renderer;

if ( ! function_exists( 'opf_get_field_group_by_id' ) ) {
	/** Return a readable OPF field group by post ID. */
	function opf_get_field_group_by_id( $id ): ?FieldGroup {
		$post_id = absint( $id );
		if ( $post_id < 1 ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'opf_field_group' !== $post->post_type ) {
			return null;
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post_id ) ) {
			return null;
		}
		return FieldGroups::group_from_post( $post );
	}
}

if ( ! function_exists( 'opf_get_field_groups_by_ids' ) ) {
	/** @param array<int,int|string> $ids @return array<int,FieldGroup> */
	function opf_get_field_groups_by_ids( array $ids ): array {
		$groups = [];
		foreach ( $ids as $id ) {
			$group = opf_get_field_group_by_id( $id );
			if ( $group ) {
				$groups[] = $group;
			}
		}
		return $groups;
	}
}

if ( ! function_exists( 'opf_get_field_groups_of_product' ) ) {
	/** @param \WC_Product|int $product @return array<int,FieldGroup> */
	function opf_get_field_groups_of_product( $product ): array {
		if ( is_int( $product ) || ( is_string( $product ) && ctype_digit( $product ) ) ) {
			$product = wc_get_product( (int) $product );
		}
		if ( ! $product instanceof \WC_Product ) {
			return [];
		}
		return array_values(
			array_map(
				static function ( array $entry ): FieldGroup {
					return $entry['group'];
				},
			FieldGroups::for_product( $product )
			)
		);
	}
}

if ( ! function_exists( 'opf_product_has_options' ) ) {
	/** Whether a product has at least one applicable OPF field group. */
	function opf_product_has_options( $product ): bool {
		return ! empty( opf_get_field_groups_of_product( $product ) );
	}
}

if ( ! function_exists( 'opf_display_field_groups_for_product' ) ) {
	/** Render the applicable fields and totals for a product, or return an empty string. */
	function opf_display_field_groups_for_product( $product ): string {
		if ( is_int( $product ) || ( is_string( $product ) && ctype_digit( $product ) ) ) {
			$product = wc_get_product( (int) $product );
		}
		return $product instanceof \WC_Product ? Renderer::render_for_product( $product ) : '';
	}
}

if ( ! function_exists( 'opf_fieldgroup_to_array' ) ) {
	/** Return the canonical normalized data for a field group. */
	function opf_fieldgroup_to_array( FieldGroup $group ): array {
		return $group->data;
	}
}

if ( ! function_exists( 'opf_array_to_fieldgroup' ) ) {
	/** Normalize an array into an OPF field-group object. */
	function opf_array_to_fieldgroup( array $data ): FieldGroup {
		return new FieldGroup( $data );
	}
}
