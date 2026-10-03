<?php
/**
 * Linked (child) products — WAPF "products" field parity.
 *
 * Selected products become native WooCommerce cart lines carrying an
 * `_opf_child` marker. The parent line's `opf_fields` value records only the
 * selection (slugs/quantities); everything else — price, stock, removal,
 * qty sync — is driven by the child marker, mirroring WAPF's
 * Linked_Products_Controller:
 *
 *  - children are added on `woocommerce_add_to_cart` (after OPF data attach)
 *  - `before_calculate_totals` removes orphans and zeroes `none`-priced kids
 *  - parent qty changes propagate by the child's `qty_type`
 *    (one|parent|relative|custom)
 *  - `price_type` mirrors WAPF (fixed|qt|nr|none) — informational in linked
 *    mode since each child prices itself; `none` forces $0
 *  - visibility/removal/quantity controls on children are locked down
 *  - order items get `_opf_child` meta; order-again restores children from
 *    their own order lines and remaps the parent cart key
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class LinkedProducts {

	/**
	 * Cart item key marking a linked child line.
	 */
	public const CHILD_KEY = '_opf_child';

	/** True while this service is inserting child lines (guards recursion). */
	private static $adding = false;

	/** Mirror of WAPF adding_to_cart: suppresses qty sync during an add. */
	private static $adding_to_cart = false;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		if ( ! apply_filters( 'opf_features_linked_products', true ) ) {
			return;
		}

		add_action( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'set_adding_to_cart' ], 50 );
		add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'add_children' ], 11, 6 );
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'remove_orphaned_children' ], 10 );
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'apply_child_prices' ], 10 );
		add_action( 'woocommerce_after_cart_item_quantity_update', [ __CLASS__, 'sync_child_quantities' ], 1, 4 );
		add_action( 'woocommerce_update_cart_validation', [ __CLASS__, 'validate_quantity_update' ], 10, 4 );

		add_filter( 'woocommerce_cart_item_remove_link', [ __CLASS__, 'remove_link' ], 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', [ __CLASS__, 'quantity_display' ], 10, 3 );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'child_item_data' ], 10, 2 );

		add_filter( 'woocommerce_cart_item_visible', [ __CLASS__, 'child_visible' ], 10, 3 );
		add_filter( 'woocommerce_widget_cart_item_visible', [ __CLASS__, 'child_visible' ], 10, 3 );
		add_filter( 'woocommerce_checkout_cart_item_visible', [ __CLASS__, 'child_visible' ], 10, 3 );
		add_filter( 'woocommerce_order_item_visible', [ __CLASS__, 'order_item_visible' ], 10, 2 );

		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'persist_child_meta' ], 20, 4 );
		add_filter( 'woocommerce_order_again_cart_item_data', [ __CLASS__, 'restore_child_order_again' ], 10, 3 );
		add_action( 'woocommerce_ordered_again', [ __CLASS__, 'remap_ordered_again' ], 11, 3 );

		// `woocommerce_blocks_loaded` may already have fired by the time OPF
		// boots (plugins_loaded p20). Woo's own helpers handle this race by
		// registering directly once the action has run.
		if ( function_exists( 'did_action' ) && did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_store_api' ] );
		}
		add_action( 'wp_footer', [ __CLASS__, 'alter_cart_object' ] );
	}

	/**
	 * True while child lines are being inserted — used by CartIntegration to
	 * skip its own validation/attach on the nested add_to_cart calls.
	 */
	public static function adding(): bool {
		return self::$adding;
	}

	public static function is_child( $cart_item ): bool {
		return is_array( $cart_item ) && isset( $cart_item[ self::CHILD_KEY ] );
	}

	public static function is_qty_subtype( array $field ): bool {
		return in_array( $field['subtype'] ?? '', [ 'card-qty', 'vcard-qty' ], true );
	}

	/**
	 * Qty propagation type, mirroring WAPF build_cache_data():
	 * qty-selector fields are `relative` when aggregate min/max is set (so
	 * children scale with the parent), otherwise `custom` (independent).
	 * Other subtypes use the field's qty_method (one|parent).
	 */
	public static function qty_type( array $field ): string {
		if ( self::is_qty_subtype( $field ) ) {
			return isset( $field['min_choices'] ) || isset( $field['max_choices'] ) ? 'relative' : 'custom';
		}
		return in_array( $field['qty_method'] ?? '', [ 'one', 'parent' ], true ) ? $field['qty_method'] : 'one';
	}

	/**
	 * WAPF get_product_price_type(): 'none' wins, qty-selectors are 'nr',
	 * then 'qt' (parent-qty) vs 'fixed' (one).
	 */
	public static function price_type( array $field, string $choice_pricing_type ): string {
		if ( 'none' === $choice_pricing_type ) {
			return 'none';
		}
		if ( self::is_qty_subtype( $field ) ) {
			return 'nr';
		}
		return 'one' === self::qty_type( $field ) ? 'fixed' : 'qt';
	}

	/**
	 * Mark that an add-to-cart is in flight so child qty sync stays quiet.
	 */
	public static function set_adding_to_cart( $validation ) {
		if ( $validation ) {
			self::$adding_to_cart = true;
		}
		return $validation;
	}

	/* ------------------------------------------------------------------
	 * Product lookup — mirrors Woocommerce_Service.
	 * ------------------------------------------------------------------ */

	/**
	 * Manual-mode product map for a field's choices.
	 * Returns array<int,\WC_Product> keyed by product id.
	 */
	public static function products_by_id( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$args = [
			'status'  => [ 'publish' ],
			'include' => $ids,
			// WAPF lets manual picks be simple, variable, or a single variation.
			'type'    => [ 'simple', 'variable', 'variation' ],
		];
		if ( get_option( 'woocommerce_hide_out_of_stock_items' ) === 'yes' ) {
			$args['stock_status'] = 'instock';
		}
		$out = [];
		foreach ( wc_get_products( apply_filters( 'opf/linked_products/by_id_args', $args, $ids ) ) as $product ) {
			$out[ $product->get_id() ] = $product;
		}
		return $out;
	}

	/**
	 * Category/query-mode product list (cap 50, published simple products,
	 * parent product excluded) — WAPF get_products_by_query().
	 *
	 * @return \WC_Product[]
	 */
	public static function products_by_query( array $query, $main_product ): array {
		$query_id = (int) ( $query['query_id'] ?? 0 );
		if ( ! $query_id ) {
			return [];
		}
		$sort     = (string) ( $query['sort'] ?? 'date_desc' );
		$order_by = str_replace( [ '_asc', '_desc' ], '', $sort );
		$args = [
			'status'              => [ 'publish' ],
			'limit'               => max( 1, min( 50, (int) ( $query['limit'] ?? 10 ) ) ),
			'orderby'             => in_array( $order_by, [ 'date', 'name' ], true ) ? $order_by : 'date',
			'order'               => str_contains( $sort, 'asc' ) ? 'ASC' : 'DESC',
			'type'                => 'simple',
			'product_category_id' => $query_id,
			'exclude'             => [ is_object( $main_product ) ? $main_product->get_id() : (int) $main_product ],
		];
		if ( get_option( 'woocommerce_hide_out_of_stock_items' ) === 'yes' ) {
			$args['stock_status'] = 'instock';
		}
		return wc_get_products( apply_filters( 'opf/linked_products/query_args', $args, $query, $main_product ) );
	}

	/* ------------------------------------------------------------------
	 * Frontend choice resolution (Renderer).
	 * ------------------------------------------------------------------ */

	/**
	 * Resolved display choices for a products field.
	 *
	 * Each choice: slug, label, product_id, product, selected, disabled,
	 * pricing_type, pricing_amount, attachment (image id), image, zoom_url,
	 * link, price, stock, desc, quantity bounds.
	 *
	 * @param array<string,mixed> $field         Normalized field.
	 * @param \WC_Product|null    $main_product  Product the field renders on.
	 * @return array<int,array<string,mixed>>
	 */
	public static function product_choices( array $field, $main_product = null ): array {
		$choices = [];
		$manual  = ( $field['product_selection'] ?? 'manual' ) === 'manual';

		// WAPF silences backorder notices while resolving child products.
		add_filter( 'woocommerce_product_backorders_require_notification', '__return_false', 166 );

		if ( $manual ) {
			$ids = array_column( $field['choices'], 'product_id' );
			$parent_id = $main_product ? $main_product->get_id() : 0;
			$products = self::products_by_id( array_filter( $ids, static fn ( $id ) => (int) $id !== $parent_id ) );
			foreach ( $field['choices'] as $choice ) {
				$product = $products[ (int) ( $choice['product_id'] ?? 0 ) ] ?? null;
				if ( ! $product ) {
					continue;
				}
				$choice['product'] = $product;
				$choices[] = self::expand_choice( $field, $choice, $product );
			}
		} else {
			$query = $field['product_query'] ?? [];
			foreach ( self::products_by_query( $query, $main_product ?? 0 ) as $product ) {
				$choice = [
					'slug'         => (string) $product->get_id(),
					'label'        => '',
					'selected'     => false,
					'disabled'     => false,
					'product_id'   => $product->get_id(),
					'pricing_type' => in_array( $query['pricing_type'] ?? '', [ 'fixed', 'none' ], true ) ? $query['pricing_type'] : 'fixed',
					'product'      => $product,
				];
				$choices[] = self::expand_choice( $field, $choice, $product );
			}
		}

		remove_filter( 'woocommerce_product_backorders_require_notification', '__return_false', 166 );

		return $choices;
	}

	/**
	 * WAPF expand_product_choice(): name/price/image/desc enrichment.
	 */
	private static function expand_choice( array $field, array $choice, \WC_Product $product ): array {
		$price_type = self::price_type( $field, (string) ( $choice['pricing_type'] ?? 'fixed' ) );

		$choice['label']          = $product->get_name();
		$choice['pricing_type']   = $price_type;
		$choice['pricing_amount'] = 'none' === $price_type ? 0.0 : (float) $product->get_price();
		$choice['attachment']     = (int) $product->get_image_id();
		$choice['image']          = '';
		if ( $choice['attachment'] && function_exists( 'wp_get_attachment_image_src' ) ) {
			$src = wp_get_attachment_image_src( $choice['attachment'], 'medium' );
			if ( is_array( $src ) ) {
				$choice['image'] = (string) $src[0];
			}
			$full = wp_get_attachment_image_src( $choice['attachment'], 'full' );
			if ( is_array( $full ) ) {
				$choice['zoom_url'] = (string) $full[0];
			}
		}
		if ( '' === $choice['image'] && function_exists( 'wc_placeholder_img_src' ) ) {
			$choice['image'] = wc_placeholder_img_src( 'medium' );
		}
		$choice['zoom_url'] = $choice['zoom_url'] ?? $choice['image'];

		// Card info slots (WAPF get_cart_info: link/price/stock).
		$slots = [ $field['slot_1'] ?? 'none', $field['slot_2'] ?? 'none', $field['slot_3'] ?? 'none' ];
		$choice['link']  = in_array( 'link', $slots, true ) ? $product->get_permalink() : '';
		$choice['price'] = in_array( 'price', $slots, true ) && function_exists( 'wc_price' )
			? wc_price( (float) $product->get_price() )
			: '';
		$choice['stock'] = '';
		if ( in_array( 'stock', $slots, true ) ) {
			$availability = $product->get_availability();
			$choice['stock'] = $availability['availability'] ?? '';
			if ( '' === $choice['stock'] ) {
				$choice['stock'] = __( 'In stock', 'woocommerce' );
			}
		}
		$choice['desc'] = '';
		if ( ! empty( $field['incl_desc'] ) ) {
			$short = $product->get_short_description();
			$choice['desc'] = '' !== $short ? $short : $product->get_description();
		}

		return apply_filters( 'opf/linked_products/choice', $choice, $field, $product );
	}

	/** Check both authored and current render-time availability. */
	private static function choice_is_disabled( array $field, array $choice, \WC_Product $product ): bool {
		if ( ! empty( $choice['disabled'] ) ) {
			return true;
		}
		$choice['product'] = $product;
		return ! empty( self::expand_choice( $field, $choice, $product )['disabled'] );
	}

	/* ------------------------------------------------------------------
	 * Submission handling — sanitize + resolve + validate.
	 * ------------------------------------------------------------------ */

	/**
	 * Sanitize a submitted products-field value (classic POST or Store API
	 * `opf_fields`). Returns the canonical stored shape or null.
	 *
	 * Qty-selector subtypes store `{_opf_type:'products',quantities:{slug:int},invalid:[...]}`
	 * (same wrapper convention as image_quantity); other subtypes store a
	 * slug (single) or slug list (multi).
	 *
	 * @param array<string,mixed> $field Normalized field.
	 * @param mixed               $value Submitted value.
	 * @param bool                $structured Stored cart/order value pass-through.
	 */
	public static function sanitize_value( array $field, $value, bool $structured = false ) {
		if ( self::is_qty_subtype( $field ) ) {
			if ( $structured && is_array( $value ) && 'products' === ( $value['_opf_type'] ?? '' ) ) {
				$value = $value['quantities'] ?? [];
			}
			if ( ! is_array( $value ) ) {
				return null;
			}
			$clean = [];
			$invalid = [];
			$bounds = self::valid_slugs( $field );
			$category_mode = 'category' === ( $field['product_selection'] ?? 'manual' );
			foreach ( $value as $slug => $raw_qty ) {
				$slug = (string) $slug;
				if ( ! isset( $bounds[ $slug ] ) ) {
					// Category mode: submitted slugs are live product ids.
					if ( ! ( $category_mode && ctype_digit( $slug ) ) ) {
						continue; // foreign keys are ignored, not errors
					}
					$bounds[ $slug ] = [ 'min' => 0, 'max' => 999999 ];
				}
				if ( ! is_scalar( $raw_qty ) || ! preg_match( '/^-?\d+$/', (string) $raw_qty ) ) {
					$invalid[] = $slug;
					$clean[ $slug ] = 0;
					continue;
				}
				$qty = (int) $raw_qty;
				$limits = $bounds[ $slug ];
				if ( $qty < 0 || ( $qty > 0 && $qty < $limits['min'] ) || $qty > $limits['max'] ) {
					$invalid[] = $slug;
				}
				$clean[ $slug ] = max( 0, $qty );
			}
			if ( ! $clean ) {
				return null;
			}
			return [ '_opf_type' => 'products', 'quantities' => $clean, 'invalid' => $invalid ];
		}

		// Non-qty: slug or slug list; '0'/'' are sentinel noise.
		$slugs = (array) $value;
		$clean = [];
		foreach ( $slugs as $slug ) {
			if ( ! is_scalar( $slug ) ) {
				continue;
			}
			$slug = sanitize_text_field( (string) $slug );
			if ( '' !== $slug && '0' !== $slug ) {
				$clean[] = $slug;
			}
		}
		if ( ! $clean ) {
			return null;
		}
		$multi = in_array( $field['subtype'] ?? '', [ 'checkbox', 'image', 'card', 'vcard' ], true );
		return $multi ? array_values( array_unique( $clean ) ) : $clean[0];
	}

	/**
	 * Valid slugs for a field. Manual mode → choice slugs with per-choice qty
	 * bounds; category mode → live query product ids (loose bounds, ids
	 * re-checked against the live query at resolve time).
	 *
	 * @return array<string,array{min:int,max:int}>
	 */
	private static function valid_slugs( array $field ): array {
		$out = [];
		if ( 'manual' === ( $field['product_selection'] ?? 'manual' ) ) {
			foreach ( $field['choices'] as $choice ) {
				$min = (int) ( $choice['quantity']['min'] ?? 0 );
				$max = (int) ( $choice['quantity']['max'] ?? 999999 );
				$out[ (string) $choice['slug'] ] = [ 'min' => $min, 'max' => $max ];
			}
			return $out;
		}
		// Category mode: submitted slugs are product ids from the live query.
		// Accept numeric ids here; resolve() verifies them against the query.
		return $out; // resolved dynamically below
	}

	/**
	 * Resolve a sanitized selection to child products — mirrors
	 * build_cache_data(). Returns [ 'choices' => [pid => ['product','qty',
	 * 'price_type','slug','label']], 'qty_type' ] or [ 'error' => message ].
	 *
	 * @param array<string,mixed> $field       Normalized field.
	 * @param mixed               $value       Sanitized stored value.
	 * @param int                 $parent_qty  Parent line quantity.
	 * @param int                 $parent_pid  Parent product id (exclusion).
	 */
	public static function resolve( array $field, $value, int $parent_qty, int $parent_pid = 0 ) {
		$is_qty   = self::is_qty_subtype( $field );
		$qty_type = self::qty_type( $field );
		$manual   = 'manual' === ( $field['product_selection'] ?? 'manual' );

		// Slug => requested qty.
		$requested = [];
		if ( $is_qty ) {
			$quantities = is_array( $value ) ? ( $value['quantities'] ?? $value ) : [];
			foreach ( (array) $quantities as $slug => $qty ) {
				$qty = (int) $qty;
				if ( $qty > 0 ) {
					$requested[ (string) $slug ] = $qty;
				}
			}
		} else {
			foreach ( (array) $value as $slug ) {
				$slug = (string) $slug;
				if ( '' !== $slug && '0' !== $slug ) {
					$requested[ $slug ] = 'one' === $qty_type ? 1 : $parent_qty;
				}
			}
		}
		if ( ! $requested ) {
			return [ 'choices' => [], 'qty_type' => $qty_type ];
		}

		$choices = [];

		if ( $manual ) {
			$by_slug = [];
			foreach ( $field['choices'] as $choice ) {
				$by_slug[ (string) $choice['slug'] ] = $choice;
			}
			$wanted_ids = [];
			foreach ( $requested as $slug => $qty ) {
				if ( ! isset( $by_slug[ $slug ] ) ) {
					/* translators: %s: field label. */
					return [ 'error' => sprintf( __( 'Some selections of "%s" are invalid. Please refresh the page and try again.', 'open-product-fields-for-woocommerce' ), $field['label'] ) ];
				}
				$wanted_ids[] = (int) $by_slug[ $slug ]['product_id'];
			}
			$products = self::products_by_id( $wanted_ids );
			foreach ( $requested as $slug => $qty ) {
				$choice = $by_slug[ $slug ];
				$pid    = (int) $choice['product_id'];
				if ( $pid === $parent_pid ) {
					/* translators: %s: field label. */
					return [ 'error' => sprintf( __( 'Some selections of "%s" are invalid. Please refresh the page and try again.', 'open-product-fields-for-woocommerce' ), $field['label'] ) ];
				}
				$product = $products[ $pid ] ?? null;
				if ( ! $product || self::choice_is_disabled( $field, $choice, $product ) ) {
					/* translators: %s: field label. */
					return [ 'error' => sprintf( __( 'Some selections of "%s" are no longer available for purchase.', 'open-product-fields-for-woocommerce' ), $field['label'] ) ];
				}
				$price_type = self::price_type( $field, (string) ( $choice['pricing_type'] ?? 'fixed' ) );
				if ( isset( $choices[ $pid ] ) ) {
					$choices[ $pid ]['qty'] += $qty;
				} else {
					$choices[ $pid ] = [
						'product'    => $product,
						'qty'        => $qty,
						'price_type' => $price_type,
						'slug'       => $slug,
						'label'      => $choice['label'] !== '' ? $choice['label'] : $product->get_name(),
					];
				}
			}
		} else {
			$query    = $field['product_query'] ?? [];
			$allowed  = [];
			foreach ( self::products_by_query( $query, $parent_pid ) as $product ) {
				$allowed[ $product->get_id() ] = $product;
			}
			foreach ( $requested as $slug => $qty ) {
				$product = $allowed[ (int) $slug ] ?? null;
				if ( ! $product ) {
					/* translators: %s: field label. */
					return [ 'error' => sprintf( __( 'Some selections of "%s" are invalid. Please refresh the page and try again.', 'open-product-fields-for-woocommerce' ), $field['label'] ) ];
				}
				// Category choices receive their unavailable flag from the same
				// live choice filter used when rendering the products field.
				$choice = [
					'slug'         => (string) $product->get_id(),
					'label'        => '',
					'selected'     => false,
					'disabled'     => false,
					'product_id'   => $product->get_id(),
					'pricing_type' => $query['pricing_type'] ?? 'fixed',
					'product'      => $product,
				];
				if ( self::choice_is_disabled( $field, $choice, $product ) ) {
					/* translators: %s: field label. */
					return [ 'error' => sprintf( __( 'Some selections of "%s" are no longer available for purchase.', 'open-product-fields-for-woocommerce' ), $field['label'] ) ];
				}
				$price_type = self::price_type( $field, (string) ( $query['pricing_type'] ?? 'fixed' ) );
				$choices[ $product->get_id() ] = [
					'product'    => $product,
					'qty'        => $qty,
					'price_type' => $price_type,
					'slug'       => $slug,
					'label'      => $product->get_name(),
				];
			}
		}

		return [ 'choices' => $choices, 'qty_type' => $qty_type ];
	}

	/**
	 * Server-side validation for a products field — mirrors validate_cart()
	 * plus validate_quantity_selector() bounds.
	 *
	 * @param array<string,mixed> $field      Normalized field.
	 * @param mixed               $value      Sanitized value (null when absent).
	 * @param int                 $parent_qty Parent add quantity.
	 * @param \WC_Product|null    $product    Parent product.
	 * @return string[] Error messages (empty when valid).
	 */
	public static function validate_field( array $field, $value, int $parent_qty, $product = null ): array {
		$errors   = [];
		$provided = null !== $value && '' !== $value && [] !== $value;

		if ( self::is_qty_subtype( $field ) ) {
			$quantities = is_array( $value ) ? ( $value['quantities'] ?? $value ) : [];
			$total      = array_sum( array_map( 'intval', (array) $quantities ) );
			$provided   = $provided && $total > 0;
			foreach ( (array) ( is_array( $value ) ? ( $value['invalid'] ?? [] ) : [] ) as $slug ) {
				/* translators: %s: choice slug. */
				$errors[] = sprintf( __( '"%s" quantity is invalid.', 'open-product-fields-for-woocommerce' ), $slug );
			}
			// Aggregate bounds (WAPF validate_quantity_selector).
			if ( isset( $field['min_choices'] ) && $total < $field['min_choices'] ) {
				/* translators: 1: field label, 2: minimum number of items. */
				$errors[] = sprintf( __( '"%1$s" requires a minimum of %2$d total items.', 'open-product-fields-for-woocommerce' ), $field['label'], $field['min_choices'] );
			}
			if ( isset( $field['max_choices'] ) && $total > $field['max_choices'] ) {
				/* translators: 1: field label, 2: maximum number of items. */
				$errors[] = sprintf( __( '"%1$s" requires a maximum of %2$d total items.', 'open-product-fields-for-woocommerce' ), $field['label'], $field['max_choices'] );
			}
		}

		if ( ! $provided ) {
			if ( ! empty( $field['required'] ) ) {
				/* translators: %s: field label. */
				$errors[] = sprintf( __( 'The field "%s" is required.', 'open-product-fields-for-woocommerce' ), $field['label'] );
			}
			return $errors;
		}

		$resolved = self::resolve( $field, $value, $parent_qty, $product ? $product->get_id() : 0 );
		if ( isset( $resolved['error'] ) ) {
			$errors[] = $resolved['error'];
			return $errors;
		}

		foreach ( $resolved['choices'] as $child ) {
			/** @var \WC_Product $child_product */
			$child_product = $child['product'];
			if ( ! $child_product->is_purchasable() ) {
				/* translators: %s: product name. */
				$errors[] = sprintf( __( 'The product "%s" is no longer available for purchase.', 'open-product-fields-for-woocommerce' ), $child_product->get_name() );
				continue;
			}
			if ( ! $child_product->is_in_stock() ) {
				/* translators: %s: product name. */
				$errors[] = sprintf( __( 'The product "%s" is no longer in stock.', 'open-product-fields-for-woocommerce' ), $child_product->get_name() );
				continue;
			}
			if ( ! $child_product->has_enough_stock( $child['qty'] ) ) {
				/* translators: %s: product name. */
				$errors[] = sprintf( __( 'The product "%s" doesn\'t have enough stock. Please select a smaller quantity.', 'open-product-fields-for-woocommerce' ), $child_product->get_name() );
			}
		}

		return $errors;
	}

	/**
	 * Human-readable display of a stored products selection.
	 *
	 * @param array<string,mixed> $field Normalized field.
	 * @param mixed               $raw   Stored value.
	 * @param \WC_Product|null    $product Parent product (for category lookups).
	 */
	public static function display_value( array $field, $raw, $product = null ): string {
		$parts = [];

		if ( self::is_qty_subtype( $field ) ) {
			$quantities = is_array( $raw ) ? ( $raw['quantities'] ?? $raw ) : [];
			$resolved   = self::resolve( $field, $raw, 1, $product ? $product->get_id() : 0 );
			$names      = [];
			if ( empty( $resolved['error'] ) ) {
				foreach ( $resolved['choices'] as $pid => $child ) {
					$names[ $child['slug'] ] = $child['label'];
				}
			}
			foreach ( (array) $quantities as $slug => $qty ) {
				$qty = (int) $qty;
				if ( $qty > 0 ) {
					$parts[] = ( $names[ (string) $slug ] ?? (string) $slug ) . ': ' . $qty;
				}
			}
			return implode( ', ', $parts );
		}

		$slugs    = array_filter( array_map( 'strval', (array) $raw ), static fn ( $s ) => '' !== $s && '0' !== $s );
		$resolved = self::resolve( $field, $raw, 1, $product ? $product->get_id() : 0 );
		if ( isset( $resolved['error'] ) ) {
			return implode( ', ', $slugs );
		}
		foreach ( $resolved['choices'] as $child ) {
			$parts[] = $child['label'];
		}
		return implode( ', ', $parts );
	}

	/* ------------------------------------------------------------------
	 * Cart lifecycle.
	 * ------------------------------------------------------------------ */

	/**
	 * After the parent line lands, insert each selected child as its own
	 * cart line — mirrors add_linked_products_from_cart_item().
	 */
	public static function add_children( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void {
		self::$adding_to_cart = false;

		if ( self::$adding || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		// Order-again restores children from their own order lines.
		if ( ! empty( $cart_item_data['opf_order_again'] ) ) {
			return;
		}
		$values = $cart_item_data[ CartIntegration::ITEM_KEY ] ?? null;
		if ( ! is_array( $values ) ) {
			return;
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid = (string) $entry['id'];
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( 'products' !== $field['type'] || ! isset( $values[ $gid ][ $field['id'] ] ) ) {
					continue;
				}
				$resolved = self::resolve( $field, $values[ $gid ][ $field['id'] ], (int) $quantity, $product->get_id() );
				if ( empty( $resolved['choices'] ) ) {
					continue;
				}
				self::$adding = true;
				try {
					foreach ( $resolved['choices'] as $child ) {
						/** @var \WC_Product $child_product */
						$child_product = $child['product'];
						$is_variation  = str_contains( $child_product->get_type(), 'variation' );
						$item_data = [
							self::CHILD_KEY => [
								'parent'        => $cart_item_key,
								'qty_type'      => $resolved['qty_type'],
								'price_type'    => $child['price_type'],
								'field'         => $field['id'],
								'hide_cart'     => ! empty( $field['hide_cart'] ),
								'hide_checkout' => ! empty( $field['hide_checkout'] ),
								'hide_order'    => ! empty( $field['hide_order'] ),
							],
						];
						WC()->cart->add_to_cart(
							$is_variation ? $child_product->get_parent_id() : $child_product->get_id(),
							$child['qty'],
							$is_variation ? $child_product->get_id() : 0,
							[],
							$item_data
						);
					}
				} finally {
					self::$adding = false;
				}
			}
		}
	}

	/**
	 * Orphaned children (parent line gone) get removed — WAPF remove_linked_products().
	 */
	public static function remove_orphaned_children( \WC_Cart $cart ): void {
		$cart_items = $cart->get_cart();
		foreach ( $cart_items as $key => $cart_item ) {
			if ( ! self::is_child( $cart_item ) ) {
				continue;
			}
			$parent_key = $cart_item[ self::CHILD_KEY ]['parent'] ?? '';
			if ( ! isset( $cart_items[ $parent_key ] ) ) {
				$cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * `none` price_type children are free — WAPF set_linked_product_price().
	 */
	public static function apply_child_prices( \WC_Cart $cart ): void {
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! self::is_child( $cart_item ) ) {
				continue;
			}
			if ( 'none' === ( $cart_item[ self::CHILD_KEY ]['price_type'] ?? '' ) && isset( $cart_item['data'] ) ) {
				$cart_item['data']->set_price( 0 );
				$cart_item['data']->set_sale_price( 0 );
			}
		}
	}

	/**
	 * Sync child quantities when the parent line's qty changes —
	 * WAPF update_quantity_in_cart().
	 */
	public static function sync_child_quantities( $parent_key, $quantity, $old_quantity, $cart ): void {
		if ( self::$adding_to_cart || self::$adding ) {
			return;
		}
		if ( empty( $cart->cart_contents[ $parent_key ] ) ) {
			return;
		}
		$parent = $cart->cart_contents[ $parent_key ];
		if ( empty( $parent[ CartIntegration::ITEM_KEY ] ) || ! self::has_products_values( $parent ) ) {
			return;
		}
		foreach ( $cart->cart_contents as $child_key => $child ) {
			if ( ! self::is_child( $child ) || ( $child[ self::CHILD_KEY ]['parent'] ?? '' ) !== $parent_key ) {
				continue;
			}
			$qty_type = $child[ self::CHILD_KEY ]['qty_type'] ?? 'one';
			if ( 'custom' === $qty_type ) {
				continue;
			}
			if ( $child['data'] instanceof \WC_Product && $child['data']->is_sold_individually() ) {
				$cart->set_quantity( $child_key, 1, false );
				continue;
			}
			switch ( $qty_type ) {
				case 'one':
					$cart->set_quantity( $child_key, 1, false );
					break;
				case 'parent':
					$cart->set_quantity( $child_key, $quantity, false );
					break;
				case 'relative':
					// WAPF update_quantity_in_cart: raw proportional scaling.
					$difference = $quantity - $old_quantity;
					if ( $difference > 0 ) {
						$qty = $child['quantity'] + ( $child['quantity'] * $difference );
					} else {
						$qty = $old_quantity > 0
							? $child['quantity'] * ( $quantity / $old_quantity )
							: $child['quantity'];
					}
					$cart->set_quantity( $child_key, $qty, false );
					break;
			}
		}
	}

	/**
	 * Does a parent cart item hold products-field selections?
	 */
	private static function has_products_values( array $cart_item ): bool {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product || empty( $cart_item[ CartIntegration::ITEM_KEY ] ) ) {
			// Fallback: any children pointing at this parent counts as linked.
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( self::is_child( $item ) ) {
					return true;
				}
			}
			return false;
		}
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid = (string) $entry['id'];
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( 'products' === $field['type'] && isset( $cart_item[ CartIntegration::ITEM_KEY ][ $gid ][ $field['id'] ] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * WAPF validate_update_quantity_in_cart(): `parent` qty_type children
	 * must have stock for the new parent quantity.
	 */
	public static function validate_quantity_update( $is_valid, $cart_item_key, $values, $quantity ) {
		if ( ! $is_valid || empty( $quantity ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $is_valid;
		}
		$cart_contents = WC()->cart->get_cart();
		if ( empty( $cart_contents[ $cart_item_key ] ) ) {
			return $is_valid;
		}
		foreach ( $cart_contents as $child_item ) {
			if ( ! self::is_child( $child_item ) || ( $child_item[ self::CHILD_KEY ]['parent'] ?? '' ) !== $cart_item_key ) {
				continue;
			}
			if ( 'parent' === ( $child_item[ self::CHILD_KEY ]['qty_type'] ?? '' )
				&& $child_item['data'] instanceof \WC_Product
				&& ! $child_item['data']->has_enough_stock( (int) $quantity ) ) {
				wc_add_notice( __( 'Cannot change product quantity because a linked child product has insufficient stock.', 'open-product-fields-for-woocommerce' ), 'error' );
				return false;
			}
		}
		return $is_valid;
	}

	/* ------------------------------------------------------------------
	 * Presentation / visibility.
	 * ------------------------------------------------------------------ */

	/** No remove link on children — they die with the parent. */
	public static function remove_link( $remove_link, $cart_item_key ) {
		$cart_item = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_item( $cart_item_key ) : null;
		if ( self::is_child( $cart_item ) ) {
			return '';
		}
		return $remove_link;
	}

	/** Children show a static quantity — WAPF maybe_remove_cart_quantity_components(). */
	public static function quantity_display( $product_quantity, $cart_item_key, $cart_item ) {
		if ( self::is_child( $cart_item ) && 'custom' !== ( $cart_item[ self::CHILD_KEY ]['qty_type'] ?? '' ) ) {
			return esc_html( (string) $cart_item['quantity'] );
		}
		return $product_quantity;
	}

	/** "Included with: <parent>" on child lines — WAPF parity. */
	public static function child_item_data( array $item_data, array $cart_item ): array {
		if ( self::is_child( $cart_item ) && ! empty( $cart_item[ self::CHILD_KEY ]['parent'] ) && function_exists( 'WC' ) && WC()->cart ) {
			$parent = WC()->cart->get_cart()[ $cart_item[ self::CHILD_KEY ]['parent'] ] ?? null;
			if ( $parent && isset( $parent['data'] ) ) {
				$item_data[] = [
					'key'     => __( 'Included with', 'open-product-fields-for-woocommerce' ),
					'value'   => $parent['data']->get_title(),
					'display' => '',
				];
			}
		}
		return $item_data;
	}

	/** hide_cart/hide_checkout flags on children — WAPF is_linked_product_visible(). */
	public static function child_visible( $visible, $cart_item, $cart_item_key ) {
		if ( ! self::is_child( $cart_item ) ) {
			return $visible;
		}
		$child = $cart_item[ self::CHILD_KEY ];
		if ( ! empty( $child['hide_cart'] )
			&& in_array( current_filter(), [ 'woocommerce_cart_item_visible', 'woocommerce_widget_cart_item_visible' ], true ) ) {
			return false;
		}
		if ( ! empty( $child['hide_checkout'] ) && 'woocommerce_checkout_cart_item_visible' === current_filter() ) {
			return false;
		}
		return $visible;
	}

	/** hide_order on child order lines — WAPF is_linked_product_visible_on_order(). */
	public static function order_item_visible( $visible, $order_item ) {
		if ( is_admin() ) {
			return $visible;
		}
		$child = $order_item->get_meta( self::CHILD_KEY );
		if ( ! empty( $child ) && is_array( $child ) ) {
			return isset( $child['hide_order'] ) ? ! $child['hide_order'] : $visible;
		}
		return $visible;
	}

	/* ------------------------------------------------------------------
	 * Orders.
	 * ------------------------------------------------------------------ */

	/** `_opf_child` meta on child order items — WAPF add_child_meta_to_order_item(). */
	public static function persist_child_meta( $order_item, $cart_item_key, $cart_item, $order ): void {
		if ( ! self::is_child( $cart_item ) ) {
			return;
		}
		$order_item->add_meta_data( self::CHILD_KEY, [
			'hide_order' => ! empty( $cart_item[ self::CHILD_KEY ]['hide_order'] ),
		] );
		// Needed to remap child→parent links on order-again.
		$order_item->add_meta_data( '_opf_child_full', $cart_item[ self::CHILD_KEY ] );
	}

	/**
	 * Order-again: child order items restore their `_opf_child` marker so
	 * they re-add as children, not standalone lines — WAPF parity.
	 */
	public static function restore_child_order_again( array $cart_item_data, $order_item, $order ): array {
		$full = $order_item->get_meta( '_opf_child_full' );
		if ( is_array( $full ) && ! empty( $full['parent'] ) ) {
			$cart_item_data[ self::CHILD_KEY ] = $full;
			$cart_item_data['opf_order_again'] = true;
		}
		// Parent lines: remember their old cart key so children can remap.
		if ( $order_item->get_meta( '_opf_fields' ) ) {
			$cart_item_data['opf_order_again']    = true;
			$cart_item_data['old_cart_item_key']  = $order_item->get_meta( '_opf_cart_item_key' );
		}
		return $cart_item_data;
	}

	/**
	 * After order-again re-adds everything, point restored children at the
	 * parent's NEW cart key — WAPF update_order_again_child_parent().
	 * `woocommerce_ordered_again` is do_action_ref_array: &$cart is the
	 * live cart_contents array.
	 */
	public static function remap_ordered_again( $order_id, $order_items, &$cart ): void {
		foreach ( $cart as &$cart_item ) {
			if ( empty( $cart_item[ self::CHILD_KEY ]['parent'] ) ) {
				continue;
			}
			foreach ( $cart as $item ) {
				if ( ! empty( $item['old_cart_item_key'] ) && $item['old_cart_item_key'] === $cart_item[ self::CHILD_KEY ]['parent'] ) {
					$cart_item[ self::CHILD_KEY ]['parent'] = $item['key'];
				}
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Store API + block cart/checkout.
	 * ------------------------------------------------------------------ */

	/**
	 * `extensions.opf.childItem` on Store API cart items — block UI hides
	 * remove/quantity controls via the footer filter registration.
	 */
	public static function register_store_api(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data( [
			'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
			'namespace'       => 'opf',
			'data_callback'   => static function ( $cart_item ) {
				return [ 'childItem' => self::is_child( $cart_item ) ];
			},
			'schema_callback' => static function () {
				return [
					'childItem' => [
						'description' => __( 'Linked child product line managed by OPF.', 'open-product-fields-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => [ 'view', 'edit' ],
						'readonly'    => true,
					],
				];
			},
			'schema_type'     => ARRAY_A,
		] );
	}

	/**
	 * Block cart/checkout: hide remove link + tag child rows (WAPF
	 * alter_cart_object parity).
	 */
	public static function alter_cart_object(): void {
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				if (window.wc && window.wc.blocksCheckout) {
					window.wc.blocksCheckout.registerCheckoutFilters('opf-child-items', {
						showRemoveItemLink: function (val, extensions, args) {
							if (args && args.context === 'cart' && args.cartItem.extensions && args.cartItem.extensions.opf && args.cartItem.extensions.opf.childItem) {
								return false;
							}
							return val;
						},
						cartItemClass: function (val, extensions, args) {
							if (args && args.context === 'cart' && args.cartItem.extensions && args.cartItem.extensions.opf && args.cartItem.extensions.opf.childItem) {
								return (val ? val + ' ' : '') + 'opf-child-item';
							}
							return val;
						}
					});
				}
			});
		</script>
		<?php
	}
}
