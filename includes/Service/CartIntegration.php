<?php
/**
 * Cart & order integration.
 *
 * Capture happens through `woocommerce_add_cart_item_data` (classic form POST)
 * and `woocommerce_store_api_add_to_cart_data` (block product pages). Display
 * uses `woocommerce_get_item_data`, which WooCommerce's own CartItemSchema
 * reuses for the block cart and checkout — one filter, both worlds.
 * Pricing is applied server-side only, per unit, in
 * `woocommerce_before_calculate_totals`.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\Calculator;
use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;

defined( 'ABSPATH' ) || exit;

final class CartIntegration {

	/**
	 * Cart item key holding our structured data.
	 */
	public const ITEM_KEY = 'opf_fields';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_add_to_cart' ], 10, 5 );
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'attach' ], 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', [ __CLASS__, 'restore_from_session' ], 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'apply_prices' ], 20, 1 );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'persist_order_item' ], 10, 4 );
		add_filter( 'woocommerce_order_again_cart_item_data', [ __CLASS__, 'restore_order_again' ], 10, 3 );
		add_filter( 'woocommerce_store_api_add_to_cart_data', [ __CLASS__, 'capture_store_api' ], 10, 2 );
	}

	/**
	 * Capture values posted by the block/Store API add-to-cart request and
	 * hand them to the cart controller via cart_item_data.
	 *
	 * @param array            $add_to_cart_data Data heading to CartController::add_to_cart.
	 * @param \WP_REST_Request $request          Store API request.
	 */
	public static function capture_store_api( array $add_to_cart_data, \WP_REST_Request $request ): array {
		$submitted = $request->get_param( 'opf_fields' );
		if ( null === $submitted ) {
			// Unregistered params can be stripped from the param bag; the raw
			// body is the fallback.
			$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_string( $raw ) && '' !== $raw ) {
				$parsed    = json_decode( $raw, true );
				$submitted = is_array( $parsed ) ? ( $parsed['opf_fields'] ?? null ) : null;
			}
		}
		if ( is_array( $submitted ) ) {
			// Validation runs after attach on the Store API path, so both need
			// the payload; neither consumes it. Cleared on shutdown.
			self::$store_api_raw = $submitted;
			$add_to_cart_data['cart_item_data']['opf_fields_raw'] = $submitted;
			add_action(
				'shutdown',
				static function () {
					CartIntegration::$store_api_raw = null;
				}
			);
		}
		return $add_to_cart_data;
	}

	/**
	 * Raw Store API payload for this request. Validation runs before cart item
	 * data exists, so the capture step stashes the payload here.
	 *
	 * @var array|null
	 */
	private static $store_api_raw = null;

	/**
	 * Validate on add-to-cart. Runs for classic AND Store API paths.
	 *
	 * @param bool $passed     Whether validation passed so far.
	 * @param int  $product_id Product id.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public static function validate_add_to_cart( bool $passed, int $product_id, int $quantity ): bool {
		if ( ! $passed ) {
			return false;
		}

		// Escape hatch for automated E2E traffic (parity with the legacy
		// wapf/skip_cart_validation filters).
		if ( apply_filters( 'opf_skip_validation', false ) ) {
			return true;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $passed;
		}

		$values = self::collect_submitted( $product, self::$store_api_raw );
		self::$store_api_raw = null; // Consumed: never leak into the next add.
		$errors = self::validate_values( $product, $values );

		foreach ( $errors as $error ) {
			wc_add_notice( $error, 'error' );
		}

		return $passed && empty( $errors );
	}

	/**
	 * Attach validated data to the cart item.
	 *
	 * @param array $cart_item_data Incoming cart item data.
	 * @param int   $product_id     Product id.
	 */
	public static function attach( array $cart_item_data, int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $cart_item_data;
		}

		// Capture always places the Store API payload in cart_item_data;
		// classic submissions come through $_POST. The static is validation-
		// only (consumed and cleared there) — reading it here could leak a
		// previous submission into this cart item.
		$raw = null;
		if ( isset( $cart_item_data['opf_fields_raw'] ) && is_array( $cart_item_data['opf_fields_raw'] ) ) {
			$raw = $cart_item_data['opf_fields_raw'];
			unset( $cart_item_data['opf_fields_raw'] );
		}

		$values = self::collect_submitted( $product, $raw );
		if ( empty( $values ) ) {
			return $cart_item_data;
		}

		$cart_item_data[ self::ITEM_KEY ] = $values;
		$cart_item_data['opf_base_price'] = (float) $product->get_price( 'edit' );

		return $cart_item_data;
	}

	/**
	 * Re-price items loaded from session (base price is authoritative).
	 *
	 * @param array $cart_item Cart item.
	 * @param array $values    Session values.
	 */
	public static function restore_from_session( array $cart_item, array $values ): array {
		if ( ! empty( $values[ self::ITEM_KEY ] ) && isset( $values['opf_base_price'] ) ) {
			$cart_item['opf_base_price'] = (float) $values['opf_base_price'];
		}
		return $cart_item;
	}

	/**
	 * Apply addon prices in the cart. Per unit, server-side only.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function apply_prices( \WC_Cart $cart ): void {
		static $recursing = false;
		if ( $recursing ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item[ self::ITEM_KEY ] ) || ! isset( $cart_item['opf_base_price'] ) ) {
				continue;
			}

			$product = $cart_item['data'];
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$base     = (float) $cart_item['opf_base_price'];
			$quantity = max( 1, (int) $cart_item['quantity'] );
			$per_unit = self::addons_per_unit( $product, $cart_item[ self::ITEM_KEY ], $base, $quantity );
			$target   = $base + $per_unit;

			if ( abs( (float) $product->get_price( 'edit' ) - $target ) > 0.000001 ) {
				$recursing = true;
				$product->set_price( $target );
				$recursing = false;
			}
		}
	}

	/**
	 * Compute total per-unit addons for a cart line.
	 *
	 * @param \WC_Product           $product  Product.
	 * @param array<string,mixed>   $values   gid => fid => value(s).
	 * @param float                 $base     Base unit price.
	 * @param int                   $quantity Line quantity.
	 */
	public static function addons_per_unit( \WC_Product $product, array $values, float $base, int $quantity ): float {
		$per_unit = 0.0;

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			if ( ! isset( $values[ $gid ] ) ) {
				continue;
			}

			$group_values = (array) $values[ $gid ];

			foreach ( $group->data['fields'] as $field ) {
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $group_values ) ) {
					continue;
				}
				if ( ! Evaluator::is_visible( $field, $group_values ) ) {
					continue;
				}
				$per_unit += Calculator::field_addon(
					$field,
					$group_values[ $fid ],
					[
						'price'  => $base,
						'qty'    => $quantity,
						'addons' => $per_unit,
					]
				);
			}
		}

		return apply_filters( 'opf_addon_price', $per_unit, $product, $values, $base );
	}

	/**
	 * Cart (classic) + block cart/checkout display.
	 *
	 * @param array $other_data Display data so far.
	 * @param array $cart_item  Cart item.
	 * @return array<int,array{name:string,value:string}>
	 */
	public static function display_item_data( array $other_data, array $cart_item ): array {
		if ( empty( $cart_item[ self::ITEM_KEY ] ) ) {
			return $other_data;
		}

		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return $other_data;
		}

		foreach ( self::visible_selections( $product, $cart_item[ self::ITEM_KEY ] ) as $selection ) {
			$other_data[] = [
				'name'    => $selection['label'],
				'value'   => $selection['value'],
				'display' => '',
			];
		}

		return $other_data;
	}

	/**
	 * Visible label/value pairs for a cart item's selections.
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<string,mixed> $values  Stored values.
	 * @return array<int,array{label:string,value:string}>
	 */
	public static function visible_selections( \WC_Product $product, array $values ): array {
		$out = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			if ( ! isset( $values[ $gid ] ) ) {
				continue;
			}
			$group_values = (array) $values[ $gid ];

			foreach ( $group->data['fields'] as $field ) {
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $group_values ) || ! Evaluator::is_visible( $field, $group_values ) ) {
					continue;
				}
				$raw = $group_values[ $fid ];
				if ( '' === $raw || [] === $raw ) {
					continue;
				}

				$value = self::display_value( $field, $raw );
				if ( '' !== $value ) {
					$out[] = [
						'label' => $field['label'],
						'value' => $value,
					];
				}
			}
		}

		return $out;
	}

	/**
	 * Human display for a stored value (choice labels, not slugs).
	 *
	 * @param array<string,mixed> $field Field data.
	 * @param string|array        $raw   Stored value.
	 */
	private static function display_value( array $field, $raw ): string {
		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			$slugs = is_array( $raw ) ? $raw : [ $raw ];
			$map   = [];
			foreach ( $field['choices'] as $choice ) {
				$map[ $choice['slug'] ] = $choice['label'];
			}
			$labels = [];
			foreach ( $slugs as $slug ) {
				if ( isset( $map[ $slug ] ) ) {
					$labels[] = $map[ $slug ];
				}
			}
			return implode( ', ', $labels );
		}
		return (string) $raw;
	}

	/**
	 * Persist selections to the order item: one display meta per field plus a
	 * hidden structured record for re-order and admin tooling.
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $cart_item     Cart item.
	 * @param \WC_Order              $order         Order.
	 */
	public static function persist_order_item( \WC_Order_Item_Product $item, string $cart_item_key, array $cart_item, \WC_Order $order ): void {
		if ( empty( $cart_item[ self::ITEM_KEY ] ) ) {
			return;
		}
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$values = $cart_item[ self::ITEM_KEY ];
		foreach ( self::visible_selections( $product, $values ) as $selection ) {
			$item->add_meta_data( $selection['label'], $selection['value'] );
		}
		$item->add_meta_data( '_opf_fields', wp_json_encode( $values, JSON_UNESCAPED_UNICODE ), true );
	}

	/**
	 * Restore selections on "order again".
	 *
	 * @param array                 $cart_item_data Cart item data being built.
	 * @param \WC_Order_Item_Product $order_item    Order item.
	 * @param \WC_Order             $order          Order.
	 */
	public static function restore_order_again( array $cart_item_data, \WC_Order_Item_Product $order_item, \WC_Order $order ): array {
		$stored = $order_item->get_meta( '_opf_fields', true );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				$cart_item_data[ self::ITEM_KEY ] = $decoded;
			}
		}
		return $cart_item_data;
	}

	/**
	 * Read submitted values from the Store API payload or the classic POST.
	 *
	 * @param \WC_Product      $product Product (variation resolved to parent).
	 * @param array<mixed>|null $raw     Raw payload from the Store API path, if any.
	 * @return array<string,mixed> gid => fid => value(s).
	 */
	private static function collect_submitted( \WC_Product $product, ?array $raw ): array {
		// Classic form POST.
		if ( null === $raw && isset( $_POST['opf'] ) && is_array( $_POST['opf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST['opf'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		}

		if ( ! is_array( $raw ) ) {
			return [];
		}

		return self::sanitize_submitted( $product, $raw );
	}

	/**
	 * Sanitize raw submitted data against known groups/fields/types.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $raw     Raw submitted array.
	 * @return array<string,mixed>
	 */
	private static function sanitize_submitted( \WC_Product $product, array $raw ): array {
		$values = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			if ( ! isset( $raw[ $gid ] ) || ! is_array( $raw[ $gid ] ) ) {
				continue;
			}

			foreach ( $group->data['fields'] as $field ) {
				$fid = $field['id'];
				if ( ! isset( $raw[ $gid ][ $fid ] ) ) {
					continue;
				}
				$value = self::sanitize_value( $field, $raw[ $gid ][ $fid ] );
				if ( null !== $value ) {
					$values[ $gid ][ $fid ] = $value;
				}
			}
		}

		return $values;
	}

	/**
	 * Sanitize one value by field type. Null when nothing was submitted.
	 *
	 * @param array<string,mixed> $field  Field definition.
	 * @param mixed               $value  Submitted value.
	 */
	private static function sanitize_value( array $field, $value ) {
		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			$valid_slugs = wp_list_pluck( $field['choices'], 'slug' );
			$slugs       = (array) $value;
			$clean       = [];
			foreach ( $slugs as $slug ) {
				$slug = sanitize_text_field( (string) $slug );
				if ( in_array( $slug, $valid_slugs, true ) ) {
					$clean[] = $slug;
				}
			}
			if ( empty( $clean ) ) {
				return null;
			}
			return in_array( $field['type'], [ 'swatch', 'select', 'radio' ], true ) ? $clean[0] : $clean;
		}

		if ( is_array( $value ) ) {
			return null;
		}

		switch ( $field['type'] ) {
			case 'number':
				return is_numeric( $value ) ? (string) ( $value + 0 ) : null;
			case 'url':
				$url = esc_url_raw( trim( (string) $value ) );
				return '' === $url ? null : $url;
			case 'textarea':
				$text = sanitize_textarea_field( (string) $value );
				return '' === trim( $text ) ? null : $text;
			default:
				$text = sanitize_text_field( (string) $value );
				return '' === trim( $text ) ? null : $text;
		}
	}

	/**
	 * Validation errors for a product's submitted values.
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<string,mixed> $values  Sanitized values.
	 * @return string[]
	 */
	private static function validate_values( \WC_Product $product, array $values ): array {
		$errors = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$given = (array) ( $values[ $gid ] ?? [] );

			foreach ( $group->data['fields'] as $field ) {
				if ( ! Evaluator::is_visible( $field, $given ) ) {
					continue;
				}
				$value = $given[ $field['id'] ] ?? null;
				$empty = null === $value || '' === $value || [] === $value;

				if ( $field['required'] && $empty ) {
					$errors[] = sprintf( '"%s" is a required field.', $field['label'] );
				}
			}
		}

		return $errors;
	}
}
