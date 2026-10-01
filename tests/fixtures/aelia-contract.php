<?php
/** Isolated fake Aelia/Woo API contract; never loaded outside child tests. */
class WC_Product {
	public int $id;
	public float $original;
	public float $view;
	public function __construct( int $id, float $original, float $view ) { $this->id = $id; $this->original = $original; $this->view = $view; }
	public function get_id(): int { return $this->id; }
	public function get_price( string $context = 'view' ): float { return 'edit' === $context ? $this->original : $this->view; }
	public function set_price( string $price ): void { $this->original = $this->view = (float) $price; }
}
class WC_Cart {
	public array $items = [];
	public function get_cart(): array { return $this->items; }
}
class WC_Aelia_CurrencySwitcher {
	public static function settings() { return new class { public function get_exchange_rate( string $currency ) { return $GLOBALS['aelia_rate']; } }; }
}
function add_filter( $name, $callback, $priority = 10, $accepted = 1 ): void { $GLOBALS['aelia_hooks'][$name][$priority][] = [ $callback, $accepted ]; }
function add_action( $name, $callback, $priority = 10, $accepted = 1 ): void { add_filter( $name, $callback, $priority, $accepted ); }
function apply_filters( $name, $value, ...$args ) {
	if ( 'wc_aelia_cs_convert' === $name ) {
		$GLOBALS['aelia_conversions'][] = [ $value, ...$args ];
		return $GLOBALS['aelia_bad_result'] ?? ( $args[0] === $args[1] ? $value : $value * $GLOBALS['aelia_rate'] );
	}
	$hooks = $GLOBALS['aelia_hooks'][$name] ?? [];
	ksort( $hooks );
	foreach ( $hooks as $callbacks ) foreach ( $callbacks as [ $callback, $accepted ] ) $value = $callback( ...array_slice( [ $value, ...$args ], 0, $accepted ) );
	return $value;
}
function wc_get_product( int $id ) { return $GLOBALS['aelia_products'][$id] ?? false; }
function wc_get_price_including_tax( $product, array $args ): float { return $args['price'] * 1.2; }
function wc_get_price_excluding_tax( $product, array $args ): float { return $args['price']; }
function get_woocommerce_currency(): string { return $GLOBALS['aelia_currency']; }
function get_woocommerce_currency_symbol( $currency = '' ): string { return 'EUR' === $currency ? '€' : '$'; }
function wc_get_price_thousand_separator(): string { return '.'; }
function wc_get_price_decimal_separator(): string { return ','; }
function wc_get_price_decimals(): int { return 2; }
function get_woocommerce_price_format(): string { return '%2$s&nbsp;%1$s'; }
function wp_json_encode( $value, $options = 0 ) { return json_encode( $value, $options ); }
