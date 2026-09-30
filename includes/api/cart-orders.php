<?php
/** Public cart and order field readers. */

defined( 'ABSPATH' ) || exit;

use OPF\Engine\Evaluator;
use OPF\Service\FieldGroups;

if ( ! function_exists( 'opf_get_custom_fields_in_cart' ) ) {
	/** @return array<int,array{cart_item_key:string,product_id:int,fields:array<int,array<string,mixed>>}> */
	function opf_get_custom_fields_in_cart(): array {
		if ( ! function_exists( 'WC' ) ) {
			return [];
		}
		$woocommerce = WC();
		if ( ! is_object( $woocommerce ) || empty( $woocommerce->cart ) || ! is_callable( [ $woocommerce->cart, 'get_cart' ] ) ) {
			return [];
		}

		$result = [];
		foreach ( (array) $woocommerce->cart->get_cart() as $cart_key => $cart_item ) {
			$product = $cart_item['data'] ?? null;
			$values  = $cart_item['opf_fields'] ?? null;
			if ( ! $product instanceof \WC_Product || ! is_array( $values ) || ! $values ) {
				continue;
			}

			$fields = [];
			foreach ( FieldGroups::for_product( $product ) as $entry ) {
				$group_id = (string) $entry['id'];
				if ( ! isset( $values[ $group_id ] ) || ! is_array( $values[ $group_id ] ) ) {
					continue;
				}
				$group_values = $values[ $group_id ];
				foreach ( $entry['group']->data['fields'] as $field ) {
					$field_id = $field['id'];
					if ( ! array_key_exists( $field_id, $group_values ) || ! Evaluator::is_visible( $field, $group_values ) ) {
						continue;
					}
					$value = $group_values[ $field_id ];
					if ( null === $value || '' === $value || [] === $value ) {
						continue;
					}
					$fields[] = [
						'group_id' => (int) $entry['id'],
						'field_id' => $field_id,
						'label'    => $field['label'],
						'value'    => $value,
						'type'     => $field['type'],
					];
				}
			}
			if ( $fields ) {
				$result[] = [
					'cart_item_key' => (string) $cart_key,
					'product_id'    => (int) $product->get_id(),
					'fields'        => $fields,
				];
			}
		}
		return $result;
	}
}

if ( ! function_exists( 'opf_get_options_from_order' ) ) {
	/**
	 * Read a saved OPF selection snapshot from order line items.
	 *
	 * Callers must authorize access to the order before passing its ID/object.
	 *
	 * @param \WC_Order|int|string $order Order object or ID.
	 * @return array<int,array{product_id:int|false,item_id:int,quantity:int,options:array<int,array<string,mixed>>}>
	 */
	function opf_get_options_from_order( $order ): array {
		if ( ! $order instanceof \WC_Order ) {
			$order = ( is_int( $order ) || ( is_string( $order ) && ctype_digit( $order ) ) ) && function_exists( 'wc_get_order' )
				? wc_get_order( (int) $order )
				: null;
		}
		if ( ! $order instanceof \WC_Order ) {
			return [];
		}

		$result = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! is_callable( [ $item, 'get_meta' ] ) ) {
				continue;
			}
			$snapshot = $item->get_meta( '_opf_fields_snapshot', true );
			if ( is_string( $snapshot ) ) {
				$snapshot = json_decode( $snapshot, true );
			}
			$options = is_array( $snapshot )
				? array_values( array_filter( $snapshot, static function ( $option ): bool {
					return is_array( $option ) && isset( $option['field_id'] ) && array_key_exists( 'value', $option );
				} ) )
				: [];
			if ( ! $options ) {
				$options = opf_api_get_legacy_order_options( $item );
			}
			if ( ! $options ) {
				continue;
			}
			$product = is_callable( [ $item, 'get_product' ] ) ? $item->get_product() : false;
			$result[] = [
				'product_id' => $product instanceof \WC_Product ? $product->get_id() : false,
				'item_id'    => (int) $item->get_id(),
				'quantity'   => (int) $item->get_quantity(),
				'options'    => $options,
			];
		}
		return $result;
	}
}

if ( ! function_exists( 'opf_api_get_legacy_order_options' ) ) {
	/** @return array<int,array<string,mixed>> */
	function opf_api_get_legacy_order_options( $item ): array {
		$stored = $item->get_meta( '_opf_fields', true );
		if ( is_string( $stored ) ) {
			$stored = json_decode( $stored, true );
		}
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$options = [];
		foreach ( $stored as $group_id => $group_values ) {
			if ( ! is_array( $group_values ) ) {
				continue;
			}
			$group = opf_get_field_group_by_id( $group_id );
			foreach ( $group_values as $field_id => $value ) {
				if ( null === $value || '' === $value || [] === $value ) {
					continue;
				}
				$field = $group ? $group->field( (string) $field_id ) : null;
				$options[] = [
					'group_id'  => is_numeric( $group_id ) ? (int) $group_id : $group_id,
					'field_id'  => (string) $field_id,
					'label'     => $field['label'] ?? '',
					'value'     => $value,
					'raw_value' => $value,
					'type'      => $field['type'] ?? '',
				];
			}
		}
		return $options;
	}
}
