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

use OPF\Engine\DateFormat;

use OPF\Engine\Calculator;
use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use OPF\Engine\RepeaterField;

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
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_add_to_cart' ], 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'attach' ], 10, 2 );
		add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'split_quantity_repeat_cart_item' ], 10, 6 );
		add_filter( 'woocommerce_get_cart_item_from_session', [ __CLASS__, 'restore_from_session' ], 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'apply_prices' ], 20, 1 );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'persist_order_item' ], 10, 4 );
		add_filter( 'woocommerce_order_again_cart_item_data', [ __CLASS__, 'restore_order_again' ], 10, 3 );
		// Hide internal OPF meta from admin/customer order item display.
		add_filter( 'woocommerce_hidden_order_itemmeta', [ __CLASS__, 'hidden_order_meta' ] );
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

	/** Prevent recursive quantity splitting when a per-unit cart line is added. */
	private static $splitting_quantity_repeats = false;

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

		// Transition gate: when OPF is admin/e2e-only, customers' carts carry
		// no OPF data and validation stays out of their way entirely.
		if ( ! Renderer::visible_to_viewer() ) {
			return $passed;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $passed;
		}

		$values = self::collect_submitted( $product, self::$store_api_raw );
		self::$store_api_raw = null; // Consumed: never leak into the next add.
		$errors = self::validate_values( $product, $values, $quantity );

		foreach ( $errors as $error ) {
			wc_add_notice( $error, 'error' );
		}

		return empty( $errors );
	}

	/**
	 * Attach validated data to the cart item.
	 *
	 * @param array $cart_item_data Incoming cart item data.
	 * @param int   $product_id     Product id.
	 */
	public static function attach( array $cart_item_data, int $product_id ): array {
		if ( self::$splitting_quantity_repeats ) {
			return $cart_item_data;
		}

		if ( ! Renderer::visible_to_viewer() ) {
			return $cart_item_data;
		}

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
	 * Split quantity-repeated field values into per-unit cart lines, merging
	 * identical clone configurations by increasing that line's quantity.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product id.
	 * @param int    $quantity      Quantity added by this request.
	 * @param int    $variation_id  Variation id.
	 * @param array  $variation     Variation attributes.
	 * @param array  $cart_item_data Submitted cart item data.
	 */
	public static function split_quantity_repeat_cart_item( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void {
		if ( self::$splitting_quantity_repeats || (int) $quantity < 1 || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		$item = $cart->get_cart_item( $cart_item_key );
		$values = is_array( $cart_item_data[ self::ITEM_KEY ] ?? null )
			? $cart_item_data[ self::ITEM_KEY ]
			: ( $item[ self::ITEM_KEY ] ?? [] );
		if ( ! $item || ! is_array( $values ) ) {
			return;
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return;
		}

		$quantity_fields = [];
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid = (string) $entry['id'];
			$group_fields = $entry['group']->data['fields'];
			$section_repeats = self::section_repeat_context( $group_fields );
			foreach ( $group_fields as $field ) {
				$section_repeat = empty( $field['repeat']['enabled'] ) && isset( $section_repeats[ $field['id'] ] );
				$repeat = $section_repeat ? $section_repeats[ $field['id'] ] : ( $field['repeat'] ?? [] );
				if ( ! in_array( $field['type'], [ 'section', 'section_end' ], true ) && ! empty( $repeat['enabled'] ) && 'quantity' === ( $repeat['mode'] ?? '' ) ) {
					$quantity_fields[] = [ $gid, (string) $field['id'], $field, $repeat, $section_repeat ];
				}
			}
		}
		if ( ! $quantity_fields ) {
			return;
		}

		$clone_groups = [];
		for ( $unit_index = 0; $unit_index < (int) $quantity; $unit_index++ ) {
			$unit_values = $values;
			$canonical_values = $values;
			$clone_labels = [];
			foreach ( $quantity_fields as [ $gid, $fid, $field, $repeat, $section_repeat ] ) {
				$source_rows = $values[ $gid ][ $fid ] ?? [];
				if ( ! is_array( $source_rows ) ) {
					$source_rows = [ $source_rows ];
				}
				if ( ! array_key_exists( $unit_index, $source_rows ) || null === $source_rows[ $unit_index ] || '' === $source_rows[ $unit_index ] || [] === $source_rows[ $unit_index ] ) {
					unset( $unit_values[ $gid ][ $fid ], $canonical_values[ $gid ][ $fid ] );
					continue;
				}

				$row = $source_rows[ $unit_index ];
				$repeat_label = $section_repeat ? '' : (string) ( $repeat['label'] ?? '' );
				$display_label = $unit_index > 0 && '' !== $repeat_label
					? str_replace( '{n}', (string) ( $unit_index + 1 ), $repeat_label )
					: (string) $field['label'];
				if ( ! $section_repeat ) {
					$clone_labels[ $gid . ':' . $fid ] = $display_label;
				}
				$storage_index = ! $section_repeat && $unit_index > 0 && false !== strpos( $repeat_label, '{n}' ) ? $unit_index : ( ! $section_repeat && $unit_index > 0 && '' !== $repeat_label ? 1 : 0 );
				$unit_values[ $gid ][ $fid ] = [ $storage_index => $row ];
				$canonical_values[ $gid ][ $fid ] = [ 0 => $row ];
			}

			$signature = hash( 'sha256', serialize( [ (int) $product_id, (int) $variation_id, $canonical_values, $clone_labels ] ) );
			if ( ! isset( $clone_groups[ $signature ] ) ) {
				$clone_groups[ $signature ] = [ 'values' => $unit_values, 'quantity' => 0 ];
			}
			$clone_groups[ $signature ]['quantity']++;
		}

		if ( ! $clone_groups ) {
			return;
		}

		$original_quantity = (int) ( $item['quantity'] ?? $quantity );
		$original_values = $item[ self::ITEM_KEY ] ?? $values;
		$previous_quantity = max( 0, $original_quantity - (int) $quantity );
		$first = array_shift( $clone_groups );
		$cart->cart_contents[ $cart_item_key ][ self::ITEM_KEY ] = $first['values'];
		$cart->set_quantity( $cart_item_key, $previous_quantity + $first['quantity'], false );

		if ( ! $clone_groups ) {
			return;
		}

		$added_lines = [];
		self::$splitting_quantity_repeats = true;
		$add_failed = false;
		try {
			foreach ( $clone_groups as $clone_group ) {
				$clone_data = $cart_item_data;
				$clone_data[ self::ITEM_KEY ] = $clone_group['values'];
				$clone_data['opf_base_price'] = (float) ( $item['opf_base_price'] ?? 0.0 );
				unset( $clone_data['opf_fields_raw'] );
				$previous_line_quantities = [];
				foreach ( $cart->get_cart() as $existing_key => $existing_item ) {
					$previous_line_quantities[ $existing_key ] = (int) $existing_item['quantity'];
				}
				$added_key = $cart->add_to_cart( $product_id, $clone_group['quantity'], $variation_id, $variation, $clone_data );
				if ( false === $added_key ) {
					$add_failed = true;
					break;
				}
				$added_lines[] = [
					'key' => $added_key,
					'previous_quantity' => array_key_exists( $added_key, $previous_line_quantities ) ? $previous_line_quantities[ $added_key ] : null,
				];
			}
		} finally {
			self::$splitting_quantity_repeats = false;
		}

		if ( $add_failed ) {
			foreach ( array_reverse( $added_lines ) as $added_line ) {
				if ( null === $added_line['previous_quantity'] ) {
					$cart->remove_cart_item( $added_line['key'] );
				} else {
					$cart->set_quantity( $added_line['key'], (int) $added_line['previous_quantity'], false );
				}
			}
			$cart->cart_contents[ $cart_item_key ][ self::ITEM_KEY ] = $original_values;
			$cart->set_quantity( $cart_item_key, $original_quantity, false );
		}
	}

	/**
	 * Re-price items loaded from session. The base price is re-derived from
	 * the CURRENT product price so catalog price changes (sales ending, price
	 * updates) apply to existing cart lines; the stored value is only a
	 * fallback if the product no longer resolves a price.
	 *
	 * @param array $cart_item Cart item.
	 * @param array $values    Session values.
	 */
	public static function restore_from_session( array $cart_item, array $values ): array {
		if ( empty( $values[ self::ITEM_KEY ] ) ) {
			return $cart_item;
		}

		$product = $cart_item['data'] ?? null;
		$current = $product instanceof \WC_Product ? (float) $product->get_price( 'edit' ) : 0.0;

		$cart_item['opf_base_price'] = $current > 0
			? $current
			: (float) ( $values['opf_base_price'] ?? $current );

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

			$base     = (float) apply_filters( 'opf_cart_item_base_price', (float) $cart_item['opf_base_price'], $product, $cart_item );
			$quantity = max( 1, (int) $cart_item['quantity'] );
			$per_unit = self::addons_per_unit( $product, $cart_item[ self::ITEM_KEY ], $base, $quantity );
			$target   = $base + $per_unit;

			if ( abs( (float) $product->get_price( 'edit' ) - $target ) > 0.000001 ) {
				$recursing = true;
				$product->set_price( (string) $target );
				$recursing = false;
			}
		}
	}

	/**
	 * Compute total per-unit addons for a cart line.
	 *
	 * @param \WC_Product           $product  Product.
	 * @param array<int|string, array<string, mixed>> $values gid => fid => value(s).
	 * @param float                 $base     Base unit price.
	 * @param int                   $quantity Line quantity.
	 */
	public static function addons_per_unit( \WC_Product $product, array $values, float $base, int $quantity ): float {
		$per_unit = 0.0;
		$field_prices = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$section_repeats = self::section_repeat_context( $group->data['fields'] );
			if ( ! isset( $values[ $gid ] ) ) {
				continue;
			}

			$group_values = (array) $values[ $gid ];

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'paragraph', 'section', 'section_end' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $group_values ) ) {
					continue;
				}
				$priced_field = $field;
				if ( empty( $priced_field['repeat']['enabled'] ) && isset( $section_repeats[ $fid ] ) ) {
					$priced_field['repeat'] = $section_repeats[ $fid ];
				}
				if ( ! empty( $priced_field['repeat']['enabled'] ) ) {
					$instance_field = $priced_field;
					unset( $instance_field['repeat'] );
					$rows = is_array( $group_values[ $fid ] ) ? $group_values[ $fid ] : [ $group_values[ $fid ] ];
					$row_prices = [];
					foreach ( $rows as $row_index => $row_value ) {
						$clone_values = self::values_for_clone( $group->data['fields'], $group_values, $section_repeats, (int) $row_index );
						if ( ! Evaluator::is_visible( $field, $clone_values ) ) {
							continue;
						}
						$clone_prices = [];
						foreach ( $field_prices as $previous_id => $previous_price ) {
							$clone_prices[ $previous_id ] = is_array( $previous_price )
								? (float) ( $previous_price[ $row_index ] ?? 0.0 )
								: $previous_price;
						}
						$row_addon = Calculator::field_addon(
							$instance_field,
							$row_value,
							[
								'price'        => $base,
								'qty'          => $quantity,
								'addons'       => $per_unit,
								'field_values' => $clone_values,
								'field_prices' => $clone_prices,
								'product_id'   => $product->get_id(),
							]
						);
						$row_prices[ $row_index ] = $row_addon;
						$per_unit += $row_addon;
					}
					if ( ! array_key_exists( $fid, $field_prices ) ) {
						$field_prices[ $fid ] = $row_prices;
					}
					continue;
				}
				if ( ! Evaluator::is_visible( $field, $group_values ) ) {
					continue;
				}
				$field_addon = Calculator::field_addon(
					$priced_field,
					$group_values[ $fid ],
					[
						'price'  => $base,
						'qty'    => $quantity,
						'addons' => $per_unit,
						'field_values' => $group_values,
						'field_prices' => $field_prices,
						'product_id' => $product->get_id(),
					]
				);
				if ( ! array_key_exists( $fid, $field_prices ) ) {
					$field_prices[ $fid ] = $field_addon;
				}
				$per_unit += $field_addon;
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
	 * @param array<int|string, array<string, mixed>> $values Stored values.
	 * @return array<int,array{label:string,value:string}>
	 */
	public static function visible_selections( \WC_Product $product, array $values ): array {
		$out = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$section_repeats = self::section_repeat_context( $group->data['fields'] );
			if ( ! isset( $values[ $gid ] ) ) {
				continue;
			}
			$group_values = (array) $values[ $gid ];

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'section', 'section_end' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $group_values ) || ! Evaluator::is_visible( $field, $group_values ) ) {
					continue;
				}
				$raw = $group_values[ $fid ];
				$section_repeat = empty( $field['repeat']['enabled'] ) && isset( $section_repeats[ $fid ] );
				$repeat_field = $field;
				if ( $section_repeat ) {
					$repeat_field['repeat'] = $section_repeats[ $fid ];
				}
				if ( ! empty( $repeat_field['repeat']['enabled'] ) ) {
					foreach ( (array) $raw as $index => $row ) {
						if ( null === $row || '' === $row || [] === $row ) {
							continue;
						}
						$row_display = self::display_value( $field, $row );
						if ( '' !== $row_display ) {
							$label = (string) $field['label'];
							if ( $section_repeat && 'button' === ( $repeat_field['repeat']['mode'] ?? '' ) && $index > 0 && ! empty( $repeat_field['repeat']['label'] ) ) {
								$label = str_replace( '{n}', (string) ( $index + 1 ), $repeat_field['repeat']['label'] ) . ' - ' . $label;
							} elseif ( ! $section_repeat && $index > 0 && ! empty( $repeat_field['repeat']['label'] ) ) {
								$label = str_replace( '{n}', (string) ( $index + 1 ), $repeat_field['repeat']['label'] );
							}
							$out[] = [ 'label' => $label, 'value' => $row_display ];
						}
					}
					continue;
				} elseif ( '' === $raw || [] === $raw ) {
					continue;
				} else {
					$value = self::display_value( $field, $raw );
				}
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
	private static function display_value( array $field, $raw ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- called from visible_selections().
		if ( 'image_quantity' === $field['type'] ) {
			$map = [];
			$quantities = is_array( $raw ) ? ( $raw['quantities'] ?? [] ) : [];
			foreach ( $field['choices'] as $choice ) {
				$count = (int) ( $quantities[ $choice['slug'] ] ?? 0 );
				if ( $count > 0 ) {
					$map[] = $choice['label'] . ': ' . $count;
				}
			}
			return implode( ', ', $map );
		}
		if ( 'date' === $field['type'] ) {
			$format = get_option( 'opf_date_format', get_option( 'wapf_date_format', DateFormat::DEFAULT_FORMAT ) );
			return DateFormat::format( (string) $raw, $format );
		}
		if ( 'toggle' === $field['type'] ) {
			return '1' === $raw
				? __( 'Yes', 'open-product-fields-for-woocommerce' )
				: __( 'No', 'open-product-fields-for-woocommerce' );
		}
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
		$snapshot = \OPF\API::field_snapshot_for_product( $product, $values );
		$item->add_meta_data( '_opf_fields_snapshot', wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE ), true );
	}

	/**
	 * Restore selections on "order again".
	 *
	 * @param array                 $cart_item_data Cart item data being built.
	 * @param \WC_Order_Item_Product $order_item    Order item.
	 * @param \WC_Order             $order          Order.
	 */
	public static function hidden_order_meta( array $keys ): array {
		$keys[] = '_opf_fields';
		$keys[] = '_opf_fields_snapshot';
		// Otros plugins que ensucian el display de órdenes
		$keys = array_merge( $keys, [
			'_nova_start_url',
			'_nova_start_type',
			'_nova_start_at',
			'_nova_start_metric',
			'_nova_start_count',
			'_nova_start_label',
			'_nova_start_name',
			'_nova_start_snapshot',
			'_wc_cog_item_cost',
			'_wc_cog_item_total_cost',
		] );
		return array_unique( $keys );
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
				$quantity = max( 1, (int) $order_item->get_quantity() );
				$product  = $order_item->get_product();
				if ( $quantity > 1 && $product instanceof \WC_Product ) {
					foreach ( FieldGroups::for_product( $product ) as $entry ) {
						$gid = (string) $entry['id'];
						$section_repeats = self::section_repeat_context( $entry['group']->data['fields'] );
						foreach ( $entry['group']->data['fields'] as $field ) {
							$repeat = ! empty( $field['repeat']['enabled'] )
								? $field['repeat']
								: ( $section_repeats[ $field['id'] ] ?? [] );
							$rows = $decoded[ $gid ][ $field['id'] ] ?? null;
							if ( 'quantity' === ( $repeat['mode'] ?? '' ) && is_array( $rows ) && 1 === count( $rows ) ) {
								$decoded[ $gid ][ $field['id'] ] = array_fill( 0, $quantity, reset( $rows ) );
							}
						}
					}
				}
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
	 * @return array<int|string, array<string, mixed>>
	 */
	private static function sanitize_submitted( \WC_Product $product, array $raw ): array {
		$values = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$section_repeats = self::section_repeat_context( $group->data['fields'] );
			$submitted = isset( $raw[ $gid ] ) && is_array( $raw[ $gid ] ) ? $raw[ $gid ] : [];

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'paragraph', 'section', 'section_end' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				$repeat_field = $field;
				if ( empty( $repeat_field['repeat']['enabled'] ) && isset( $section_repeats[ $fid ] ) ) {
					$repeat_field['repeat'] = $section_repeats[ $fid ];
				}
				if ( ! array_key_exists( $fid, $submitted ) ) {
					if ( 'text' === $field['type'] && empty( $repeat_field['repeat']['enabled'] ) && isset( $field['default'] ) ) {
						$submitted[ $fid ] = $field['default'];
					} else {
						continue;
					}
				}
				$value = ! empty( $repeat_field['repeat']['enabled'] )
					? RepeaterField::sanitize( $repeat_field, $submitted[ $fid ], static fn( $row ) => self::sanitize_value( $field, $row ) )
					: self::sanitize_value( $field, $submitted[ $fid ] );
				if ( null !== $value ) {
					$values[ $gid ][ $fid ] = $value;
				}
			}
		}

		return $values;
	}

	/**
	 * Map fields inside a repeated section to the repeat settings inherited from it.
	 *
	 * @param array<int,array<string,mixed>> $fields Normalized group fields.
	 * @return array<string,array<string,mixed>> Field ID to repeat configuration.
	 */
	private static function section_repeat_context( array $fields ): array {
		$context = [];
		$stack   = [];
		$active  = [];

		foreach ( $fields as $field ) {
			if ( 'section_end' === $field['type'] ) {
				array_pop( $stack );
				$active = $stack ? end( $stack ) : [];
				continue;
			}

			if ( 'section' === $field['type'] ) {
				$repeat = ! empty( $field['repeat']['enabled'] ) ? $field['repeat'] : $active;
				$stack[] = $repeat;
				$active  = $repeat;
				continue;
			}

			if ( $active ) {
				$context[ $field['id'] ] = $active;
			}
		}

		return $context;
	}

	/**
	 * Sanitize one value by field type. Null when nothing was submitted.
	 *
	 * @param array<string,mixed> $field  Field definition.
	 * @param mixed               $value  Submitted value.
	 */
	private static function sanitize_value( array $field, $value ) {
		if ( 'image_quantity' === $field['type'] ) {
			if ( ! is_array( $value ) ) {
				return null;
			}
			$clean = [];
			$invalid = [];
			foreach ( $field['choices'] as $choice ) {
				$raw_quantity = $value[ $choice['slug'] ] ?? 0;
				if ( ! is_scalar( $raw_quantity ) || ! preg_match( '/^\\d+$/', (string) $raw_quantity ) ) {
					$invalid[] = $choice['slug'];
					$clean[ $choice['slug'] ] = 0;
					continue;
				}
				$quantity = (int) $raw_quantity;
				if ( $quantity < $choice['quantity']['min'] || $quantity > $choice['quantity']['max'] || ( ! empty( $choice['disabled'] ) && $quantity > 0 ) ) {
					$invalid[] = $choice['slug'];
				}
				$clean[ $choice['slug'] ] = ! empty( $choice['disabled'] ) ? 0 : $quantity;
			}
			return [ '_opf_type' => 'image_quantity', 'quantities' => $clean, 'invalid' => $invalid ];
		}
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
			$multi_swatch = 'swatch' === $field['type'] && ! empty( $field['multiple'] );
			return in_array( $field['type'], [ 'select', 'radio' ], true ) || ( 'swatch' === $field['type'] && ! $multi_swatch ) ? $clean[0] : array_values( array_unique( $clean ) );
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
			case 'email':
				return FieldValue::sanitize( $field, sanitize_text_field( (string) $value ) );
			case 'date':
				return FieldValue::sanitize( $field, sanitize_text_field( (string) $value ) );
			case 'toggle':
				return FieldValue::sanitize( $field, $value );
			default:
				$text = sanitize_text_field( (string) $value );
				return '' === trim( $text ) ? null : $text;
		}
	}

	/**
	 * Validation errors for a product's submitted values.
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<int|string, array<string, mixed>> $values Sanitized values.
	 * @return string[]
	 */
	private static function validate_values( \WC_Product $product, array $values, int $product_quantity = 1 ): array {
		$errors = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$given = $values[ $gid ] ?? [];
			$section_repeats = self::section_repeat_context( $group->data['fields'] );

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'section', 'section_end' ], true ) ) {
					continue;
				}
				$provided = array_key_exists( $field['id'], $given );
				$repeat_field = $field;
				if ( empty( $repeat_field['repeat']['enabled'] ) && isset( $section_repeats[ $field['id'] ] ) ) {
					$repeat_field['repeat'] = $section_repeats[ $field['id'] ];
				}
				if ( ! empty( $repeat_field['repeat']['enabled'] ) ) {
					$rows = $provided && is_array( $given[ $field['id'] ] ) ? $given[ $field['id'] ] : [];
					$errors = array_merge( $errors, RepeaterField::validate(
						$repeat_field,
						$rows,
						$provided,
						$product_quantity,
						static function ( int $row_index ) use ( $field, $group, $given, $section_repeats ): bool {
							$clone_values = self::values_for_clone( $group->data['fields'], $given, $section_repeats, $row_index );
							return Evaluator::is_visible( $field, $clone_values );
						}
					) );
					continue;
				}
				if ( ! Evaluator::is_visible( $field, $given ) ) {
					continue;
				}
				if ( 'image_quantity' === $field['type'] ) {
					$submitted = $provided && is_array( $given[ $field['id'] ] ) ? $given[ $field['id'] ] : [];
					$errors = array_merge( $errors, self::validate_image_quantity( $field, $submitted ) );
					continue;
				}
				$value    = $provided && ! is_array( $given[ $field['id'] ] ) ? (string) $given[ $field['id'] ] : null;
				if ( in_array( $field['type'], [ 'email', 'date', 'toggle' ], true ) ) {
					$errors = array_merge( $errors, FieldValue::validate( $field, $value, $provided ) );
				} elseif ( $field['required'] && ! $provided && !( 'swatch' === $field['type'] && ! empty( $field['multiple'] ) && isset( $field['min_choices'] ) ) ) {
					$errors[] = sprintf( '"%s" is a required field.', $field['label'] );
				}
				if ( 'swatch' === $field['type'] && ! empty( $field['multiple'] ) ) {
					$submitted_value = $provided ? $given[ $field['id'] ] : null;
					$count = is_array( $submitted_value ) ? count( $submitted_value ) : ( $provided ? 1 : 0 );
					if ( isset( $field['min_choices'] ) && $count < $field['min_choices'] ) {
						$errors[] = sprintf( '"%s" requires at least %d choices.', $field['label'], $field['min_choices'] );
					}
					if ( isset( $field['max_choices'] ) && $count > $field['max_choices'] ) {
						$errors[] = sprintf( '"%s" allows at most %d choices.', $field['label'], $field['max_choices'] );
					}
				}
			}
		}

		return $errors;
	}

	/** Validate per-choice and aggregate image quantities. */
	private static function validate_image_quantity( array $field, array $submitted ): array {
		$errors = [];
		$quantities = is_array( $submitted['quantities'] ?? null ) ? $submitted['quantities'] : $submitted;
		$invalid = is_array( $submitted['invalid'] ?? null ) ? $submitted['invalid'] : [];
		$total = 0;
		foreach ( $field['choices'] as $choice ) {
			$q = (int) ( $quantities[ $choice['slug'] ] ?? 0 );
			$total += $q;
			if ( in_array( $choice['slug'], $invalid, true ) ) {
				$errors[] = sprintf( '"%s" quantity is invalid.', $choice['label'] );
			} elseif ( $q < $choice['quantity']['min'] || $q > $choice['quantity']['max'] ) {
				$errors[] = sprintf( '"%s" quantity must be between %d and %d.', $choice['label'], $choice['quantity']['min'], $choice['quantity']['max'] );
			}
		}
		if ( isset( $field['min_choices'] ) && $total < $field['min_choices'] ) {
			$errors[] = sprintf( '"%s" requires at least %d total items.', $field['label'], $field['min_choices'] );
		}
		if ( isset( $field['max_choices'] ) && $total > $field['max_choices'] ) {
			$errors[] = sprintf( '"%s" allows at most %d total items.', $field['label'], $field['max_choices'] );
		}
		return $errors;
	}

	/**
	 * Build conditional values for one repeated clone while leaving non-repeated
	 * fields at their group-wide values.
	 *
	 * @param array<int,array<string,mixed>> $fields          Normalized group fields.
	 * @param array<string,mixed>             $given           Sanitized group values.
	 * @param array<string,array<string,mixed>> $section_repeats Inherited section repeat settings.
	 */
	private static function values_for_clone( array $fields, array $given, array $section_repeats, int $row_index ): array {
		$values = $given;
		foreach ( $fields as $field ) {
			if ( in_array( $field['type'], [ 'paragraph', 'section', 'section_end' ], true ) ) {
				continue;
			}
			$repeat = ! empty( $field['repeat']['enabled'] )
				? $field['repeat']
				: ( $section_repeats[ $field['id'] ] ?? [] );
			if ( empty( $repeat['enabled'] ) ) {
				continue;
			}
			$rows = $given[ $field['id'] ] ?? [];
			$values[ $field['id'] ] = is_array( $rows ) && array_key_exists( $row_index, $rows ) ? $rows[ $row_index ] : null;
		}
		return $values;
	}
}
