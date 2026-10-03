<?php
/**
 * Build portable OPF field-group archives.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class Exporter {

	/** Build a versioned package without changing stored field-group data. */
	public static function build_package( array $groups, array $scope ): array {
		$exported = [];
		$all_warnings = [];
		foreach ( $groups as $group ) {
			$data = is_array( $group['data'] ?? null ) ? $group['data'] : [];
			$warnings = self::group_warnings( $data );
			$exported[] = [
				'source_id' => (int) ( $group['id'] ?? 0 ),
				'title' => (string) ( $group['title'] ?? '' ),
				'status' => (string) ( $group['status'] ?? 'draft' ),
				'menu_order' => (int) ( $group['menu_order'] ?? 0 ),
				'language' => (string) ( $group['language'] ?? '' ),
				'data' => $data,
				'warnings' => $warnings,
			];
			$all_warnings = array_merge( $all_warnings, $warnings );
		}
		return [
			'format' => 'opf-field-groups',
			'format_version' => 1,
			'exported_at' => gmdate( 'c' ),
			'scope' => $scope,
			'groups' => $exported,
			'warnings' => array_values( array_unique( $all_warnings ) ),
		];
	}

	private static function group_warnings( array $data ): array {
		$warnings = [];
		if ( self::has_media_reference( $data ) ) {
			$warnings[] = 'media_files_not_included';
		}
		$has_site_local = false;
		foreach ( (array) ( $data['rule_groups'] ?? [] ) as $rule_group ) {
			foreach ( (array) ( $rule_group['rules'] ?? [] ) as $rule ) {
				if ( is_array( $rule ) && in_array( $rule['subject'] ?? '', [ 'product', 'product_cat', 'product_tag', 'product_var' ], true ) && ! empty( $rule['terms'] ) ) {
					$has_site_local = true;
					break 2;
				}
			}
		}
		if ( ! $has_site_local ) {
			$has_site_local = self::has_linked_product_references( $data );
		}
		if ( $has_site_local ) {
			$warnings[] = 'product_target_ids_may_not_match';
		}
		return $warnings;
	}

	/** Linked-products fields carry site-local product/category IDs. */
	private static function has_linked_product_references( array $data ): bool {
		foreach ( (array) ( $data['fields'] ?? [] ) as $field ) {
			if ( ! is_array( $field ) || 'products' !== ( $field['type'] ?? '' ) ) {
				continue;
			}
			if ( 'category' === ( $field['product_selection'] ?? '' ) ) {
				if ( (int) ( $field['product_query']['query_id'] ?? 0 ) > 0 ) {
					return true;
				}
				continue;
			}
			foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
				if ( is_array( $choice ) && (int) ( $choice['product_id'] ?? 0 ) > 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function has_media_reference( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( in_array( $key, [ 'image', 'image_url' ], true ) && is_string( $item ) && '' !== $item ) {
				return true;
			}
			if ( 'image_id' === $key && is_numeric( $item ) && (int) $item > 0 ) {
				return true;
			}
			if ( self::has_media_reference( $item ) ) {
				return true;
			}
		}
		return false;
	}
}
