<?php
/**
 * Server-side pricing engine. The only place addon money is computed.
 *
 * Semantics (documented contract):
 *  - Fixed pricing is flat per cart line unless its per_unit flag is enabled.
 *  - percent : unit_price * amount / 100
 *  - fixed   : amount (shop currency; currency plugins may convert via opf_fixed_price filter)
 *  - formula : expression over [price] (base unit price), [addons] (addons computed
 *              before this choice, per unit), [qty] (line quantity), [val] (text input).
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class Calculator {

	/** @var array<string,callable> Request-local extension functions. */
	private static array $formula_functions = [];

	/**
	 * Register a pure numeric formula function for this request.
	 *
	 * Callbacks receive evaluated float arguments and formula context. Their
	 * result must be numeric and finite; unsafe or invalid results make the
	 * containing formula evaluate to zero.
	 *
	 * @param string   $name     ASCII function name.
	 * @param callable $callback Callback receiving (float[] $args, array $context).
	 */
	public static function add_formula_function( string $name, callable $callback ): bool {
		$name = strtolower( $name );
		if ( ! preg_match( '/^[a-z_][a-z0-9_]{0,31}$/', $name ) || in_array( $name, [ 'today', 'dow', 'month', 'p', 'q', 'a', 'v' ], true ) ) {
			return false;
		}
		self::$formula_functions[ $name ] = $callback;
		return true;
	}

	/**
	 * Compute the per-unit addon price for a field selection.
	 *
	 * @param array<string,mixed>      $field   Normalized field array.
	 * @param string|array<int,string> $value   Submitted value(s) (choice slugs or raw text).
	 * @param array{price?:float,qty?:int,addons?:float,field_values?:array<string,mixed>,product_id?:int} $context Pricing context.
	 * @return float Per-unit addon (never negative).
	 */
	public static function field_addon( array $field, $value, array $context ): float {
		$price  = (float) ( $context['price'] ?? 0.0 );
		$qty    = max( 1, (int) ( $context['qty'] ?? 1 ) );
		$addons = (float) ( $context['addons'] ?? 0.0 );
		$field_values = is_array( $context['field_values'] ?? null ) ? $context['field_values'] : [];
		$product_id   = max( 0, (int) ( $context['product_id'] ?? 0 ) );

		$total = 0.0;

		switch ( $field['type'] ) {
			case 'swatch':
			case 'select':
			case 'radio':
			case 'checkbox':
				$slugs = is_array( $value ) ? $value : [ $value ];
				foreach ( $slugs as $slug ) {
					foreach ( $field['choices'] as $choice ) {
						if ( $choice['slug'] === (string) $slug && ! $choice['disabled'] ) {
							$total += self::choice_addon( $choice['pricing'], $price, $qty, $addons, $field_values, $product_id );
							if ( ! in_array( $field['type'], [ 'checkbox' ], true ) ) {
								break;
							}
						}
					}
				}
				break;

			default:
				// Text-like fields use field-level pricing only.
				$amount = is_scalar( $value ) ? (string) $value : '';
				$total += self::field_pricing_addon( $field['pricing'], $amount, $price, $qty, $addons, $field_values, $product_id );
				break;
		}

		return max( 0.0, (float) $total );
	}

	/**
	 * Addon for a single choice — expressed as the PER-UNIT contribution to
	 * the line price. Flat (per_unit=false) amounts are divided by quantity
	 * so the line total adds exactly the flat fee (WAPF parity).
	 *
	 * @param array<string,mixed> $pricing Normalized choice pricing.
	 */
	public static function choice_addon( array $pricing, float $price, int $qty, float $addons, array $field_values = [], int $product_id = 0 ): float {
		$qty = max( 1, $qty );
		switch ( $pricing['type'] ) {
			case 'fixed':
				$amount = (float) $pricing['amount'];
				return empty( $pricing['per_unit'] ) ? $amount / $qty : $amount;
			case 'percent':
				return $price * ( (float) $pricing['amount'] / 100 );
			case 'formula':
				return self::evaluate_formula( $pricing['formula'], $price, $qty, $addons, '', null, $field_values, $product_id );
			default:
				return 0.0;
		}
	}

	/**
	 * Field-level pricing for text-like input — per-unit contribution
	 * (see normalize_pricing for the semantics table).
	 *
	 * @param array<string,mixed> $pricing Normalized field pricing.
	 */
	public static function field_pricing_addon( array $pricing, string $value, float $price, int $qty, float $addons, array $field_values = [], int $product_id = 0 ): float {
		if ( '' === trim( $value ) ) {
			return 0.0;
		}
		$qty = max( 1, $qty );
		switch ( $pricing['type'] ) {
			case 'fixed':
				$amount = (float) $pricing['amount'];
				return empty( $pricing['per_unit'] ) ? $amount / $qty : $amount;
			case 'percent':
				$amount = $price * ( (float) $pricing['amount'] / 100 );
				return empty( $pricing['per_unit'] ) ? $amount / $qty : $amount;
			case 'formula':
				return self::evaluate_formula( $pricing['formula'], $price, $qty, $addons, $value, null, $field_values, $product_id );
			case 'char':
			case 'charq':
			case 'nr':
			case 'nrq':
				$units = in_array( $pricing['type'], [ 'char', 'charq' ], true )
					? ( function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value ) )
					: ( is_numeric( $value ) ? (float) $value : 0.0 );
				$amount = (float) $pricing['amount'] * $units;
				return empty( $pricing['per_unit'] ) ? $amount / $qty : $amount;
			default:
				return 0.0;
		}
	}

	/**
	 * Safely evaluate a formula expression.
	 *
	 * Supports arithmetic tokens plus WAPF `dow()`, `month()`, `today()`, and
	 * validated [field.{id}] date references. No eval() — recursive descent
	 * parser. Syntax errors and invalid dates fail closed to zero.
	 */
	public static function evaluate_formula( string $formula, float $price, int $qty, float $addons, string $val = '', ?string $today = null, array $field_values = [], int $product_id = 0 ): float {
		$formula = ACFFormula::resolve( $formula, max( 0, $product_id ) );
		$today = $today ?? ( function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' ) );
		if ( ! self::is_formula_iso_date( $today ) ) {
			$today = gmdate( 'Y-m-d' );
		}
		$formula = preg_replace( '/today\s*\(\s*\)/i', '__OPF_TODAY__', $formula );
		$formula = preg_replace_callback(
			'/\b(dow|month)\s*\(([^()]*)\)/i',
			static function ( array $match ) use ( $val, $today, $field_values ): string {
				$date = self::parse_formula_date( $match[2], $val, $field_values, $today );
				if ( null === $date ) {
					return '0';
				}
				return 'dow' === strtolower( $match[1] ) ? $date->format( 'w' ) : $date->format( 'n' );
			},
			$formula
		);
		$formula = str_replace(
			[ '[price]', '[qty]', '[addons]', '[options_total]', '[val]' ],
			[ ' P ', ' Q ', ' A ', ' A ', ' V ' ],
			$formula
		);
		$vars = [ 'P' => $price, 'Q' => (float) $qty, 'A' => $addons, 'V' => (float) $val ];
		$context = [
			'price'        => $price,
			'quantity'     => $qty,
			'addons'       => $addons,
			'value'        => $val,
			'today'        => $today,
			'field_values' => $field_values,
		];

		$tokens = self::tokenize( $formula, $vars );
		if ( null === $tokens ) {
			return 0.0;
		}
		$pos   = 0;
		$value = self::parse_expression( $tokens, $pos, $context );
		if ( null === $value || $pos < count( $tokens ) ) {
			return 0.0;
		}
		// Negatives allowed here (formulas may offset other addons); the
		// final addon total is clamped at the field_addon boundary.
		return is_finite( $value ) ? (float) $value : 0.0;
	}

	/** Resolve a WAPF date function argument to a strictly validated date. */
	private static function parse_formula_date( string $argument, string $val, array $field_values, string $today ): ?\DateTimeImmutable {
		$argument = trim( $argument );
		if ( strlen( $argument ) >= 2 && ( ( "'" === $argument[0] && "'" === substr( $argument, -1 ) ) || ( '"' === $argument[0] && '"' === substr( $argument, -1 ) ) ) ) {
			$argument = substr( $argument, 1, -1 );
		}
		if ( '__OPF_TODAY__' === $argument ) {
			$argument = $today;
		} elseif ( '[val]' === strtolower( $argument ) ) {
			$argument = $val;
		} elseif ( preg_match( '/^\[field\.([a-zA-Z0-9_-]+)\]$/i', $argument, $field_match ) ) {
			$value = $field_values[ strtolower( $field_match[1] ) ] ?? null;
			$argument = is_scalar( $value ) ? (string) $value : '';
		}
		$argument = trim( $argument );

		if ( self::is_formula_iso_date( $argument ) ) {
			return \DateTimeImmutable::createFromFormat( '!Y-m-d', $argument, new \DateTimeZone( 'UTC' ) ) ?: null;
		}

		$date_format = function_exists( 'get_option' )
			? (string) get_option( 'opf_date_format', get_option( 'wapf_date_format', 'mm-dd-yyyy' ) )
			: 'mm-dd-yyyy';
		preg_match_all( '/yyyy|yy|mm|m|dd|d|[-\/., ]/i', $date_format, $format_tokens );
		if ( 5 !== count( $format_tokens[0] ) ) {
			return null;
		}

		$pattern = '';
		$parts   = [];
		foreach ( $format_tokens[0] as $token ) {
			$token = strtolower( $token );
			if ( in_array( $token, [ '-', '/', '.', ',', ' ' ], true ) ) {
				$pattern .= preg_quote( $token, '/' );
				continue;
			}
			$part = 'y' === substr( $token, 0, 1 ) ? 'year' : ( 'm' === $token[0] ? 'month' : 'day' );
			if ( isset( $parts[ $part ] ) ) {
				return null;
			}
			$parts[ $part ] = count( $parts ) + 1;
			$digits = 'yyyy' === $token ? '4' : ( in_array( $token, [ 'yy', 'mm', 'dd' ], true ) ? '2' : '1,2' );
			$pattern .= '(\\d{' . $digits . '})';
		}
		if ( 3 !== count( $parts ) || ! preg_match( '/^' . $pattern . '$/', $argument, $matches ) ) {
			return null;
		}

		$date = [];
		foreach ( $parts as $part => $index ) {
			$date[ $part ] = (int) $matches[ $index ];
		}
		$year = $date['year'];
		if ( 2 === strlen( (string) $matches[ $parts['year'] ] ) ) {
			$year = $year < 70 ? 2000 + $year : 1900 + $year;
		}
		if ( ! checkdate( $date['month'], $date['day'], $year ) ) {
			return null;
		}
		return \DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%04d-%02d-%02d', $year, $date['month'], $date['day'] ), new \DateTimeZone( 'UTC' ) ) ?: null;
	}

	/** Return whether a value is an exact valid ISO calendar date. */
	private static function is_formula_iso_date( string $value ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $value ) );
		return checkdate( $month, $day, $year );
	}

	/**
	 * Tokenize. A variable letter is valid only as a standalone token.
	 *
	 * @param array<string,float> $vars Variable values.
	 * @return array<int,array{t:string,v:float|string}>|null
	 */
	private static function tokenize( string $formula, array $vars ): ?array {
		$tokens = [];
		$len    = strlen( $formula );
		$i      = 0;
		while ( $i < $len ) {
			$ch = $formula[ $i ];
			if ( ' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch ) {
				$i++;
				continue;
			}
			if ( isset( $vars[ $ch ] ) && ( $i + 1 >= $len || ! ctype_alpha( $formula[ $i + 1 ] ) ) ) {
				$tokens[] = [ 't' => 'num', 'v' => $vars[ $ch ] ];
				$i++;
				continue;
			}
			if ( preg_match( '/^[a-z_][a-z0-9_]*/i', substr( $formula, $i ), $function ) ) {
				$name = strtolower( $function[0] );
				if ( ! isset( self::$formula_functions[ $name ] ) ) {
					return null;
				}
				$tokens[] = [ 't' => 'fn', 'v' => $name ];
				$i       += strlen( $function[0] );
				continue;
			}
			if ( preg_match( '/\d+(?:\.\d+)?/', substr( $formula, $i ), $m ) && ( '.' === $ch || ctype_digit( $ch ) ) ) {
				$tokens[] = [ 't' => 'num', 'v' => (float) $m[0] ];
				$i       += strlen( $m[0] );
				continue;
			}
			if ( false !== strpos( '+-*/(),', $ch ) && 1 === strlen( $ch ) ) {
				$tokens[] = [ 't' => $ch, 'v' => 0.0 ];
				$i++;
				continue;
			}
			return null;
		}
		return $tokens;
	}

	/**
	 * expression := term (('+'|'-') term)*
	 *
	 * @param array<int,array{t:string,v:float|string}> $tokens Tokens.
	 * @param int                                       $pos    Cursor (by reference).
	 * @param array<string,mixed>                       $context Formula context.
	 */
	private static function parse_expression( array $tokens, int &$pos, array $context ): ?float {
		$value = self::parse_term( $tokens, $pos, $context );
		while ( null !== $value && $pos < count( $tokens ) && in_array( $tokens[ $pos ]['t'], [ '+', '-' ], true ) ) {
			$op = $tokens[ $pos ]['t'];
			$pos++;
			$right = self::parse_term( $tokens, $pos, $context );
			if ( null === $right ) {
				return null;
			}
			$value = '+' === $op ? $value + $right : $value - $right;
		}
		return $value;
	}

	/**
	 * term := factor (('*'|'/') factor)*
	 *
	 * @param array<int,array{t:string,v:float|string}> $tokens Tokens.
	 * @param int                                       $pos    Cursor (by reference).
	 * @param array<string,mixed>                       $context Formula context.
	 */
	private static function parse_term( array $tokens, int &$pos, array $context ): ?float {
		$value = self::parse_factor( $tokens, $pos, $context );
		while ( null !== $value && $pos < count( $tokens ) && in_array( $tokens[ $pos ]['t'], [ '*', '/' ], true ) ) {
			$op = $tokens[ $pos ]['t'];
			$pos++;
			$right = self::parse_factor( $tokens, $pos, $context );
			if ( null === $right || ( '/' === $op && 0.0 === $right ) ) {
				return null;
			}
			$value = '*' === $op ? $value * $right : $value / $right;
		}
		return $value;
	}

	/**
	 * factor := number | '(' expression ')' | '-' factor
	 *
	 * @param array<int,array{t:string,v:float|string}> $tokens Tokens.
	 * @param int                                       $pos    Cursor (by reference).
	 * @param array<string,mixed>                       $context Formula context.
	 */
	private static function parse_factor( array $tokens, int &$pos, array $context ): ?float {
		if ( $pos >= count( $tokens ) ) {
			return null;
		}
		$token = $tokens[ $pos ];
		if ( 'num' === $token['t'] ) {
			$pos++;
			return $token['v'];
		}
		if ( 'fn' === $token['t'] ) {
			$pos++;
			if ( $pos >= count( $tokens ) || '(' !== $tokens[ $pos ]['t'] ) {
				return null;
			}
			$pos++;
			$args = [];
			if ( $pos >= count( $tokens ) || ')' === $tokens[ $pos ]['t'] ) {
				return null;
			}
			while ( $pos < count( $tokens ) ) {
				if ( count( $args ) >= 20 ) {
					return null;
				}
				$argument = self::parse_expression( $tokens, $pos, $context );
				if ( null === $argument ) {
					return null;
				}
				$args[] = $argument;
				if ( $pos < count( $tokens ) && ',' === $tokens[ $pos ]['t'] ) {
					$pos++;
					continue;
				}
				break;
			}
			if ( $pos >= count( $tokens ) || ')' !== $tokens[ $pos ]['t'] ) {
				return null;
			}
			$pos++;
			try {
				$result = call_user_func( self::$formula_functions[ $token['v'] ], $args, $context );
			} catch ( \Throwable $exception ) {
				return null;
			}
			return is_numeric( $result ) && is_finite( (float) $result ) ? (float) $result : null;
		}
		if ( '(' === $token['t'] ) {
			$pos++;
			$value = self::parse_expression( $tokens, $pos, $context );
			if ( null === $value || $pos >= count( $tokens ) || ')' !== $tokens[ $pos ]['t'] ) {
				return null;
			}
			$pos++;
			return $value;
		}
		if ( '-' === $token['t'] ) {
			$pos++;
			$value = self::parse_factor( $tokens, $pos, $context );
			return null === $value ? null : -$value;
		}
		return null;
	}
}
