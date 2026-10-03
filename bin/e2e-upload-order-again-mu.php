<?php
/** Disposable clone probe: capture only booleans about Woo order-again upload validation. */
add_filter(
	'woocommerce_add_to_cart_validation',
	static function ( $passed, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		$product = get_page_by_path( 'upload-proof', OBJECT, 'product' );
		if ( ! $product || (int) $product->ID !== (int) $product_id || empty( $cart_item_data['opf_fields'] ) ) {
			return $passed;
		}

		$fields = (array) $cart_item_data['opf_fields'];
		$tokens = [];
		foreach ( $fields as $group ) {
			foreach ( (array) $group as $value ) {
				foreach ( OPF\Service\Uploads::tokens( $value ) as $token ) {
					if ( is_string( $token ) && preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
						$tokens[] = $token;
					}
				}
			}
		}

		$record       = $tokens ? OPF\Service\Uploads::record( $tokens[0] ) : null;
		$bound_order  = $record ? wc_get_order( (int) $record['order_id'] ) : null;
		$error_notices = array_map(
			static function ( $notice ) {
				return is_array( $notice ) ? wp_strip_all_tags( (string) ( $notice['notice'] ?? '' ) ) : '';
			},
			wc_get_notices( 'error' )
		);
		$unavailable = false;
		foreach ( $error_notices as $notice ) {
			if ( false !== stripos( $notice, 'uploaded file is unavailable' ) ) {
				$unavailable = true;
			}
		}

		update_option(
			'opf_upload_order_again_probe',
			[
				'validation_passed' => (bool) $passed,
				'upload_tokens_present' => (bool) $tokens,
				'first_token_record_exists' => (bool) $record,
				'current_session_owns_first_token' => $record ? hash_equals( $record['owner'], OPF\Service\Uploads::owner() ) : false,
				'first_token_bound_to_completed_order' => $bound_order instanceof WC_Order && $bound_order->has_status( 'completed' ),
				'unavailable_error_notice' => $unavailable,
			],
			false
		);

		return $passed;
	},
	99,
	6
);
