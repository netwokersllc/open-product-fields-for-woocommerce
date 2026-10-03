<?php
/** Disposable clone probe: capture only booleans about Woo order-again upload validation + reissue. */
add_action(
	'wp_ajax_rest-nonce',
	static function () {
		echo esc_html( wp_create_nonce( 'wp_rest' ) );
		wp_die();
	}
);
add_filter(
	'woocommerce_add_to_cart_validation',
	static function ( $passed, $product_id, $quantity, $variation_id = null, $variation = null, $cart_item_data = null ) {
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

		$source_order_id = isset( $_GET['order_again'] ) ? absint( $_GET['order_again'] ) : 0;
		$source_tokens   = [];
		if ( $source_order_id && ( $source_order = wc_get_order( $source_order_id ) ) ) {
			foreach ( $source_order->get_items() as $source_item ) {
				foreach ( (array) $source_item->get_meta( '_opf_uploads', true ) as $file ) {
					if ( is_array( $file ) && ! empty( $file['token'] ) ) {
						$source_tokens[] = (string) $file['token'];
					}
				}
			}
		}

		$record        = $tokens ? OPF\Service\Uploads::record( $tokens[0] ) : null;
		$bound_order   = $record && ! empty( $record['order_id'] ) ? wc_get_order( (int) $record['order_id'] ) : null;
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
				'validation_passed'                   => (bool) $passed,
				'cart_tokens'                        => $tokens,
				'source_order_id'                    => $source_order_id,
				'source_tokens'                      => $source_tokens,
				'upload_tokens_present'               => (bool) $tokens,
				'first_token_record_exists'           => (bool) $record,
				'current_session_owns_first_token'    => $record ? hash_equals( $record['owner'], OPF\Service\Uploads::owner() ) : false,
				'first_token_unbound_staged'          => $record && empty( $record['order_id'] ),
				'first_token_bound_to_completed_order' => $bound_order instanceof WC_Order && $bound_order->has_status( 'completed' ),
				'first_token_reissued_from_source'    => $record && ! empty( $record['reissued_from'] ) && in_array( (string) $record['reissued_from'], $source_tokens, true ),
				'first_token_not_in_source_order'     => $tokens ? ! in_array( $tokens[0], $source_tokens, true ) : false,
				'unavailable_error_notice'            => $unavailable,
			],
			false
		);

		return $passed;
	},
	99,
	6
);
