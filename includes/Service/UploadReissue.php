<?php
/**
 * Secure order-again reissue for private uploads.
 *
 * WooCommerce rebuilds an order-again cart line straight from order meta and
 * never passes through `woocommerce_add_cart_item_data`, so a completed
 * order's session-bound upload tokens can never validate for a new session.
 * WAPF solves this by re-linking the same public-uploads file path, protected
 * only by .htaccess. OPF keeps its stronger session+order binding and instead
 * mints a fresh private copy owned by the reordering session — but only when
 * that session may already access the source file (upload owner, bound-order
 * customer, or shop manager). Every failure path leaves the original token in
 * place, so validation still fails closed exactly as it did before reissue
 * existed.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class UploadReissue {

	/**
	 * Runs after CartIntegration::restore_order_again() (priority 10) has
	 * repopulated the stored values and still inside
	 * WC_Cart_Session::populate_cart_from_order(), before WooCommerce invokes
	 * `woocommerce_add_to_cart_validation` on the rebuilt line. Registering
	 * here needs no CartIntegration change; the integrator only merges.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_order_again_cart_item_data', [ __CLASS__, 'reissue' ], 20, 3 );
	}

	/**
	 * Swap order-bound upload tokens for fresh session-owned reissues.
	 *
	 * @param array                  $cart_item_data Cart item data being built.
	 * @param \WC_Order_Item_Product $order_item     Source order line.
	 * @param \WC_Order              $order          Source order.
	 */
	public static function reissue( array $cart_item_data, $order_item, $order ): array {
		$values = $cart_item_data[ CartIntegration::ITEM_KEY ] ?? null;
		if ( ! is_array( $values ) || ! $values || ! $order_item instanceof \WC_Order_Item_Product || ! $order instanceof \WC_Order ) {
			return $cart_item_data;
		}

		$product_id = (int) $order_item->get_product_id();
		foreach ( $values as $gid => $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $fid => $value ) {
				$values[ $gid ][ $fid ] = self::reissue_value( $value, $product_id, (string) $gid, (string) $fid, $order );
			}
		}
		$cart_item_data[ CartIntegration::ITEM_KEY ] = $values;
		return $cart_item_data;
	}

	/**
	 * Reissue each token leaf of a stored field value. Upload fields cannot
	 * repeat, so values are flat token lists; scalars and nested rows still
	 * pass through defensively unchanged.
	 *
	 * @param mixed     $value      Stored/sanitized field value.
	 * @param int       $product_id Parent product of the reordered line.
	 * @param string    $gid        Field group key in the cart values.
	 * @param string    $fid        Field key in the cart values.
	 * @param \WC_Order $order      Source order.
	 * @return mixed
	 */
	private static function reissue_value( $value, int $product_id, string $gid, string $fid, \WC_Order $order ) {
		if ( ! is_array( $value ) ) {
			return is_string( $value ) ? self::reissue_token( $value, $product_id, $gid, $fid, $order ) : $value;
		}
		$out = [];
		foreach ( $value as $key => $token ) {
			$out[ $key ] = is_string( $token ) ? self::reissue_token( $token, $product_id, $gid, $fid, $order ) : $token;
		}
		return $out;
	}

	/**
	 * Reissue one upload token, or return it unchanged to fail closed.
	 *
	 * The token must genuinely belong to this order and field scope: a
	 * reference smuggled into foreign order meta, or one pointing at a
	 * different product/group/field, can never mint a session copy.
	 */
	private static function reissue_token( string $token, int $product_id, string $gid, string $fid, \WC_Order $order ): string {
		$record = Uploads::record( $token );
		if ( ! $record ) {
			return $token;
		}
		if ( (int) ( $record['order_id'] ?? 0 ) !== (int) $order->get_id()
			|| (int) ( $record['product_id'] ?? 0 ) !== $product_id
			|| (string) ( $record['group_id'] ?? '' ) !== $gid
			|| (string) ( $record['field_id'] ?? '' ) !== $fid ) {
			return $token;
		}

		// The record's bound order is the order being reordered. While that
		// order stays retryable the original token still validates for its own
		// session, so reissue is unnecessary as well as unwanted.
		$claimed = ! $order->has_status( 'checkout-draft' ) && ! $order->needs_payment();
		$owner   = Uploads::owner();
		if ( ! $claimed && hash_equals( (string) $record['owner'], $owner ) ) {
			return $token;
		}

		// Mirror Uploads::download()'s ACL exactly: upload owner, bound-order
		// customer, or shop manager. WooCommerce already verified the
		// `order_again` capability for this order in populate_cart_from_order();
		// this re-check keeps the file safe if the filter ever runs elsewhere.
		$allowed = hash_equals( (string) $record['owner'], $owner )
			|| current_user_can( 'manage_woocommerce' )
			|| ( get_current_user_id() > 0 && (int) $order->get_customer_id() === get_current_user_id() );
		if ( ! $allowed ) {
			return $token;
		}

		$reissued = Uploads::reissue_token( $token, $record );
		return is_string( $reissued ) ? $reissued : $token;
	}
}
