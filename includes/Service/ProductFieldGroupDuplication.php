<?php
/**
 * Preserve migrated product-local field groups when WooCommerce duplicates a product.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\FieldGroupDuplicator;

defined( 'ABSPATH' ) || exit;

final class ProductFieldGroupDuplication {

	/** Register WooCommerce's product duplication callback. */
	public static function init(): void {
		add_action( 'woocommerce_product_duplicate', [ __CLASS__, 'duplicate_for_product' ], 10, 2 );
	}

	/**
	 * Clone imported product-local OPF groups onto WooCommerce's new product.
	 *
	 * Only groups imported from the source product's WAPF `_wapf_fieldgroup`
	 * metadata are local. Ordinary global OPF groups must remain shared.
	 *
	 * @param \WC_Product $duplicate WooCommerce's saved duplicate.
	 * @param \WC_Product $source    Original product.
	 */
	public static function duplicate_for_product( $duplicate, $source ): void {
		if ( ! $duplicate instanceof \WC_Product || ! $source instanceof \WC_Product ) {
			return;
		}

		$source_id    = (int) $source->get_id();
		$duplicate_id = (int) $duplicate->get_id();
		if ( $source_id < 1 || $duplicate_id < 1 || $source_id === $duplicate_id ) {
			return;
		}

		$source_key    = 'meta:' . $source_id;
		$duplicate_key = 'meta:' . $duplicate_id;
		$groups         = get_posts(
			[
				'post_type'      => 'opf_field_group',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => '_opf_imported_from', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $source_key, // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);

		foreach ( $groups as $post ) {
			if ( self::has_copy( $duplicate_key, (int) $post->ID ) ) {
				continue;
			}

			$group = FieldGroups::group_from_post( $post );
			if ( ! $group ) {
				continue;
			}

			try {
				$duplicated = FieldGroupDuplicator::duplicate_with_map( $group->data );
				$data       = $duplicated['group'];
				$id_map     = $duplicated['id_map'];
			} catch ( \Throwable $error ) {
				self::log_failure( (int) $post->ID, $source_id, 'invalid-group' );
				continue;
			}

			self::retarget_product( $data, $source_id, $duplicate_id );
			$new_id = FieldGroups::save(
				0,
				$data,
				[
					'title'     => sprintf( '%s - %s', $post->post_title, __( 'Copy', 'open-product-fields-for-woocommerce' ) ),
					'status'    => $post->post_status,
					'menu_order' => (int) $post->menu_order,
				]
			);
			if ( ! $new_id ) {
				self::log_failure( (int) $post->ID, $source_id, 'save-failed' );
				continue;
			}

			update_post_meta( $new_id, '_opf_imported_from', $duplicate_key );
			update_post_meta( $new_id, '_opf_duplicate_source_group', (int) $post->ID );
			$review = get_post_meta( $post->ID, '_opf_needs_review', true );
			if ( '' !== $review && null !== $review ) {
				update_post_meta( $new_id, '_opf_needs_review', $review );
			}

			if ( function_exists( 'pll_get_post_language' ) && function_exists( 'pll_set_post_language' ) ) {
				$language = pll_get_post_language( $post->ID, 'slug' );
				if ( $language ) {
					pll_set_post_language( $new_id, $language );
				}
			}

			do_action(
				'opf/admin/after_product_duplication',
				$duplicate,
				$source,
				new \OPF\Engine\FieldGroup( $data ),
				$id_map,
				$new_id
			);
		}
	}

	/**
	 * Replace the source product term in product placement rules.
	 *
	 * @param array<string,mixed> $data         Normalized field-group data.
	 * @param int                 $source_id    Original product ID.
	 * @param int                 $duplicate_id New product ID.
	 */
	private static function retarget_product( array &$data, int $source_id, int $duplicate_id ): void {
		foreach ( $data['rule_groups'] as &$rule_group ) {
			foreach ( $rule_group['rules'] as &$rule ) {
				if ( 'product' !== ( $rule['subject'] ?? '' ) || ! isset( $rule['terms'] ) ) {
					continue;
				}
				$rule['terms'] = array_map(
					static fn( $term ): string => (string) $source_id === (string) $term ? (string) $duplicate_id : (string) $term,
					(array) $rule['terms']
				);
			}
			unset( $rule );
		}
		unset( $rule_group );
	}

	private static function has_copy( string $source_key, int $source_group_id ): bool {
		return (bool) get_posts(
			[
				'post_type'      => 'opf_field_group',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'AND',
					[ 'key' => '_opf_imported_from', 'value' => $source_key ],
					[ 'key' => '_opf_duplicate_source_group', 'value' => $source_group_id ],
				], // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
	}

	private static function log_failure( int $group_id, int $product_id, string $reason ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->error(
			'Could not duplicate an OPF product-local field group.',
			[
				'source_group_id'   => $group_id,
				'source_product_id' => $product_id,
				'reason'            => $reason,
				'source'            => 'open-product-fields-for-woocommerce',
			]
		);
	}
}
