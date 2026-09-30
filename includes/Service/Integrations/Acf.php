<?php
/**
 * ACF values for variable-product live pricing.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service\Integrations;

use OPF\Engine\ACFFormula;
use OPF\Service\FieldGroups;

defined( 'ABSPATH' ) || exit;

final class Acf {

	/** Add only referenced numeric ACF values to each WooCommerce variation. */
	public static function init(): void {
		add_filter( 'woocommerce_available_variation', [ __CLASS__, 'variation_data' ], 20, 3 );
	}

	/**
	 * @param array<string,mixed> $data      Variation payload.
	 * @param \WC_Product         $product   Variable parent product.
	 * @param \WC_Product         $variation Variation.
	 * @return array<string,mixed>
	 */
	public static function variation_data( array $data, \WC_Product $product, \WC_Product $variation ): array {
		$groups = FieldGroups::for_product( $product );
		if ( empty( $groups ) || empty( ACFFormula::references( $groups ) ) ) {
			return $data;
		}

		$data['opf_acf_values'] = ACFFormula::values_for_groups( $groups, $variation->get_id() );
		return $data;
	}
}
