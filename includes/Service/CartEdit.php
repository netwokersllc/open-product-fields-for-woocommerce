<?php
/**
 * Edit-in-cart (WAPF-INTERACTION-CART-EDIT parity).
 *
 * Mirrors WAPF 3.1.5's opt-in edit flow: an admin setting
 * (`opf_edit_cart`, off by default) enables an `(edit)` link on eligible
 * cart lines — those carrying OPF field values (`opf_fields`, the
 * equivalent of WAPF's `wapf`/`_wapf_children` gate; `_wapf_children` is a
 * dead key in WAPF 3.1.5 and is never written). The link points at the cart
 * line's own product permalink with `opf_edit=<cart_item_key>`; the product
 * form restores stored values, prefills the quantity, and submits a hidden
 * `_opf_edit` key. On add-to-cart the original line is removed and the new
 * line is added — WAPF's remove-then-add semantics (the cart key is NOT
 * retained; identical submissions re-merge onto the same generated key).
 *
 * OPF differences, all fail-closed:
 *  - The edit target must still resolve in the current session's cart and
 *    still be editable before it is removed (`replace_edited_item`); WAPF
 *    removes whatever key `_wapf_edit` names. A forged or stale key that
 *    points at a non-OPF line is ignored, never destructive.
 *  - Upload values carry over as the same session-owned private tokens via
 *    hidden inputs; `Uploads::validate_tokens` re-checks owner/product/
 *    group/field on resubmit exactly like a fresh upload. No WAPF-style
 *    "extract URL from rendered HTML" rehydration and no `UploadReissue`
 *    re-minting — cart tokens are not order-bound.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class CartEdit {

	/** $_GET parameter carrying the cart key to edit (WAPF `_edit` parity). */
	public const QUERY_PARAM = 'opf_edit';
	/** $_POST hidden input carrying the cart key on submit (WAPF `_wapf_edit` parity). */
	public const POST_PARAM = '_opf_edit';

	/** @var array{key:string,item:array<string,mixed>}|null Memoized request resolution. */
	private static $request = null;
	/** @var bool Whether $request has been resolved. */
	private static $request_resolved = false;
	/** @var array<int,array{key:string,item:array<string,mixed>,values:array}> Per-product edit context memo. */
	private static $for_product_cache = [];

	/**
	 * Register hooks. Classic surfaces mirror WAPF's product-controller
	 * hooks 1:1; the block cart uses the same Store API extension +
	 * registerCheckoutFilters('itemName') pattern.
	 */
	public static function init(): void {
		add_action( 'woocommerce_after_cart_item_name', [ __CLASS__, 'add_edit_link' ], 10, 2 );
		add_action( 'wp_footer', [ __CLASS__, 'add_edit_link_for_cart_block' ] );

		// `woocommerce_blocks_loaded` may already have fired by the time OPF
		// boots (plugins_loaded p20) — same race handling as LinkedProducts.
		if ( function_exists( 'did_action' ) && did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_store_api' ] );
		}

		// Edit-state product page adjustments (WAPF parity).
		add_filter( 'woocommerce_quantity_input_args', [ __CLASS__, 'prefill_quantity' ], 20, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', [ __CLASS__, 'update_button_text' ] );
		add_action( 'woocommerce_before_add_to_cart_button', [ __CLASS__, 'render_edit_state' ], 25 );

		// Remove-then-add replacement + post-submit UX (WAPF parity).
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'replace_edited_item' ], 20, 3 );
		add_filter( 'woocommerce_add_to_cart_redirect', [ __CLASS__, 'redirect_to_cart' ], 10, 2 );
		add_filter( 'wc_add_to_cart_message_html', [ __CLASS__, 'updated_message' ], 10, 3 );
	}

	/**
	 * The opt-in gate — WAPF `Util::can_edit_in_cart()` parity.
	 */
	public static function enabled(): bool {
		return 'yes' === get_option( 'opf_edit_cart', 'no' );
	}

	/**
	 * WAPF `Util::can_edit_cart_item()` parity: setting on AND the cart line
	 * carries OPF field values. OPF linked child lines (`_opf_child`) do not
	 * carry `opf_fields` and are never editable — same as WAPF child lines.
	 *
	 * @param mixed $cart_item Cart item (or cart item data array).
	 */
	public static function can_edit_cart_item( $cart_item ): bool {
		return self::enabled() && is_array( $cart_item ) && isset( $cart_item[ CartIntegration::ITEM_KEY ] );
	}

	/**
	 * Resolve the current `opf_edit=<key>` request against the session cart.
	 * Returns ['key','item'] or null. Session ownership is inherent: cart
	 * keys only resolve inside the current session's cart.
	 *
	 * @return array{key:string,item:array<string,mixed>}|null
	 */
	public static function request(): ?array {
		if ( self::$request_resolved ) {
			return self::$request;
		}
		self::$request_resolved = true;
		self::$request          = null;

		if ( ! self::enabled() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WAPF `_edit` parity: read-only cart-key lookup.
		$key = isset( $_GET[ self::QUERY_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_PARAM ] ) ) : '';
		if ( '' === $key ) {
			return null;
		}
		$contents = WC()->cart->cart_contents;
		$item     = $contents[ $key ] ?? null;
		if ( ! self::can_edit_cart_item( $item ) ) {
			return null;
		}
		self::$request = [ 'key' => $key, 'item' => $item ];
		return self::$request;
	}

	/**
	 * Edit context for the product currently being rendered, or null.
	 * The edit link lands on the cart line's own product permalink, so the
	 * page product must be the line's product (variation lines resolve to
	 * their parent — `$cart_item['product_id']` is the parent id).
	 *
	 * @return array{key:string,item:array<string,mixed>,values:array}|null
	 */
	public static function for_product( \WC_Product $product ): ?array {
		$pid = $product->get_id();
		if ( array_key_exists( $pid, self::$for_product_cache ) ) {
			return self::$for_product_cache[ $pid ];
		}
		self::$for_product_cache[ $pid ] = null;
		$request = self::request();
		if ( $request && (int) ( $request['item']['product_id'] ?? 0 ) === (int) $pid ) {
			self::$for_product_cache[ $pid ] = [
				'key'    => $request['key'],
				'item'   => $request['item'],
				'values' => is_array( $request['item'][ CartIntegration::ITEM_KEY ] ) ? $request['item'][ CartIntegration::ITEM_KEY ] : [],
			];
		}
		return self::$for_product_cache[ $pid ];
	}

	/**
	 * Stored OPF values for the edit-in-progress on this product page:
	 * `gid => fid => stored value`, or null when not editing.
	 */
	public static function edit_values_for( \WC_Product $product ): ?array {
		$context = self::for_product( $product );
		return $context ? $context['values'] : null;
	}

	/**
	 * Cart line's edit permalink — `add_query_arg(opf_edit, key, permalink)`.
	 * Empty string when the product is not visible (WAPF
	 * `wapf/disable_cart_edit_when_invisible` parity).
	 */
	private static function edit_url( array $cart_item ): string {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}
		if ( apply_filters( 'opf_disable_cart_edit_when_invisible', true ) && ! $product->is_visible() ) {
			return '';
		}
		return add_query_arg(
			self::QUERY_PARAM,
			(string) ( $cart_item['key'] ?? '' ),
			$product->get_permalink( $cart_item )
		);
	}

	/** `<a class="opf-edit-cartitem">(edit)</a>` — WAPF markup parity. */
	private static function link_html( array $cart_item ): string {
		$url = self::edit_url( $cart_item );
		if ( '' === $url ) {
			return '';
		}
		$text = apply_filters( 'opf_cart_edit_text', '(' . __( 'edit', 'open-product-fields-for-woocommerce' ) . ')', $cart_item );
		return '<a class="opf-edit-cartitem" href="' . esc_url( $url ) . '">' . esc_html( (string) $text ) . '</a>';
	}

	/** `woocommerce_after_cart_item_name` — classic cart line link. */
	public static function add_edit_link( $cart_item, $cart_item_key ): void {
		if ( ! self::can_edit_cart_item( $cart_item ) ) {
			return;
		}
		$link = self::link_html( $cart_item );
		if ( '' !== $link ) {
			echo '&nbsp;' . $link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped link.
		}
	}

	/**
	 * `extensions.opf_edit.editLink` on Store API cart items — WAPF `apf.editLink`
	 * parity for the block cart/checkout itemName filter below.
	 */
	public static function register_store_api(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data( [
			'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
			// Distinct from LinkedProducts' `opf` namespace: the Store API
			// registry keys endpoint data by namespace, so sharing `opf` would
			// silently drop one of the two callbacks.
			'namespace'       => 'opf_edit',
			'data_callback'   => static function ( $cart_item ) {
				$data = [];
				if ( self::can_edit_cart_item( $cart_item ) ) {
					$link = self::link_html( $cart_item );
					if ( '' !== $link ) {
						$data['editLink'] = $link;
					}
				}
				return apply_filters( 'opf_store_api_cart_data', $data, $cart_item );
			},
			'schema_callback' => static function () {
				return apply_filters( 'opf_store_api_cart_schema', [
					'editLink' => [
						'description' => __( 'Link to edit the cart item.', 'open-product-fields-for-woocommerce' ),
						'type'        => 'string',
						'context'     => [ 'view', 'edit' ],
						'readonly'    => true,
					],
				] );
			},
			'schema_type'     => ARRAY_A,
		] );
	}

	/** Block cart/checkout: append the edit link to the item name (WAPF parity). */
	public static function add_edit_link_for_cart_block(): void {
		if ( ! self::enabled() ) {
			return;
		}
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				if (window.wc && window.wc.blocksCheckout) {
					window.wc.blocksCheckout.registerCheckoutFilters('opf-editlink', {
						itemName: function (defaultVal, extensions, args) {
							if (args && args.context === 'cart' && args.cartItem.extensions && args.cartItem.extensions.opf_edit && args.cartItem.extensions.opf_edit.editLink) {
								return defaultVal + ' ' + args.cartItem.extensions.opf_edit.editLink;
							}
							return defaultVal;
						}
					});
				}
			});
		</script>
		<?php
	}

	/**
	 * Prefill the quantity input with the cart line's quantity while editing
	 * (WAPF `change_add_to_cart_quantity` parity).
	 */
	public static function prefill_quantity( array $args, \WC_Product $product ): array {
		$context = self::for_product( $product );
		if ( $context ) {
			$args['input_value'] = isset( $context['item']['quantity'] ) ? $context['item']['quantity'] : 1;
		}
		return $args;
	}

	/** "Update cart" button text while editing (WAPF parity). */
	public static function update_button_text( string $text ): string {
		return self::request() ? __( 'Update cart', 'woocommerce' ) : $text;
	}

	/** Hidden `_opf_edit` state inside `form.cart` (WAPF `_wapf_edit` parity). */
	public static function render_edit_state(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$context = self::for_product( $product );
		if ( $context ) {
			echo '<input type="hidden" name="' . esc_attr( self::POST_PARAM ) . '" value="' . esc_attr( $context['key'] ) . '" />';
		}
	}

	/**
	 * Sanitized `_opf_edit` POST value, or null when not an edit submit.
	 */
	private static function submitted_key(): ?string {
		if ( ! self::enabled() || empty( $_POST[ self::POST_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$key = sanitize_text_field( wp_unslash( (string) $_POST[ self::POST_PARAM ] ) );
		return '' !== $key ? $key : null;
	}

	/**
	 * Replace semantics (WAPF remove-then-add parity): drop the original line
	 * while the replacement's cart item data is being assembled — inside
	 * `woocommerce_add_cart_item_data`, before `generate_cart_id`, so an
	 * identical replacement remerges onto the same generated key.
	 *
	 * OPF hardening: the named line is only removed when it still resolves
	 * and is still editable — forged or stale keys are ignored rather than
	 * destroying an unrelated line.
	 */
	public static function replace_edited_item( array $cart_item_data, int $product_id, int $variation_id ): array {
		$key = self::submitted_key();
		if ( null === $key || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $cart_item_data;
		}
		$target = WC()->cart->cart_contents[ $key ] ?? null;
		if ( self::can_edit_cart_item( $target ) ) {
			WC()->cart->remove_cart_item( $key );
		}
		return $cart_item_data;
	}

	/** After a successful edit submit, land on the cart (WAPF parity). */
	public static function redirect_to_cart( string $url, $adding_to_cart ): string {
		if ( $adding_to_cart && self::submitted_key() && apply_filters( 'opf_add_to_cart_redirect_when_editing', true ) ) {
			return wc_get_cart_url();
		}
		return $url;
	}

	/** "Cart updated." notice instead of "added to cart" (WAPF parity). */
	public static function updated_message( $message, $products, $show_qty ) {
		if ( self::submitted_key() ) {
			return esc_html__( 'Cart updated.', 'woocommerce' );
		}
		return $message;
	}
}
