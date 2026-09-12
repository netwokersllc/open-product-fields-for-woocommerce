<?php
/**
 * Field type registry.
 *
 * @package open-product-fields-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and provides the built-in field types.
 */
class OPF_Field_Types {

	/**
	 * Get all registered field types.
	 *
	 * Filterable via the `opf_field_types` filter.
	 *
	 * @return array<string, array{label: string, class: string}>
	 */
	public function all() {
		$types = array(
			// 'text' => array(
			//     'label' => __( 'Text', 'opf' ),
			//     'class' => 'OPF_Field_Type_Text',
			// ),
		);

		/**
		 * Filter the registered field types.
		 *
		 * @param array<string, array{label: string, class: string}> $types Field types keyed by slug.
		 */
		return apply_filters( 'opf_field_types', $types );
	}
}
