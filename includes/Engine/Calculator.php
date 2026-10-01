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

	/** @var array<string,callable> */
	private static array $formula_functions = [];

	/** Register a trusted extension callback through OPF\API. */
	public static function register_formula_function( string $function, callable $callback ): void {
		self::$formula_functions[ $function ] = $callback;
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

		if ( ! empty( $field['repeat']['enabled'] ) ) {
			$instance_field = $field;
			unset( $instance_field['repeat'] );
			$instances = is_array( $value ) ? $value : ( null === $value ? [] : [ $value ] );
			$total = 0.0;
			foreach ( $instances as $instance_value ) {
				$total += self::field_addon( $instance_field, $instance_value, $context );
			}
			return max( 0.0, $total );
		}

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
							$total += self::choice_addon( $choice['pricing'], $price, $qty, $addons, $field_values, (int) ( $context['product_id'] ?? 0 ) );
							if ( ! in_array( $field['type'], [ 'checkbox' ], true ) && !( 'swatch' === $field['type'] && ! empty( $field['multiple'] ) ) ) {
								break;
							}
						}
					}
				}
				break;

			default:
				// Text-like fields use field-level pricing only.
				$amount = is_scalar( $value ) ? (string) $value : '';
				$total += self::field_pricing_addon( $field['pricing'], $amount, $price, $qty, $addons, $field_values, (int) ( $context['product_id'] ?? 0 ) );
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
				return $price * ( (float) $pricing['amount'] / 100 );
			case 'formula':
				return self::evaluate_formula( $pricing['formula'], $price, $qty, $addons, $value, null, $field_values, $product_id );
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
		$today = $today ?? ( function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' ) );
		if ( ! self::is_formula_iso_date( $today ) ) {
			$today = gmdate( 'Y-m-d' );
		}
		$formula = preg_replace_callback(
			'/\[field\.([a-zA-Z0-9_-]+)\]/i',
			static function ( array $match ) use ( $field_values ): string {
				$value = $field_values[ strtolower( $match[1] ) ] ?? '';
				if ( is_array( $value ) ) {
					$value = reset( $value );
				}
				return is_scalar( $value ) ? (string) $value : '';
			},
			$formula
		);
		$formula = preg_replace( '/today\s*\(\s*\)/i', '__OPF_TODAY__', $formula );
		$formula = preg_replace_callback(
			'/\bdatediff\s*\(([^()]*)\)/i',
			static function ( array $match ) use ( $val, $today, $field_values ): string {
				$args = self::split_formula_arguments( $match[1] );
				if ( 2 !== count( $args ) ) {
					return '0';
				}
				$date1 = self::parse_formula_date( $args[0], $val, $field_values, $today );
				$date2 = self::parse_formula_date( $args[1], $val, $field_values, $today );
				if ( null === $date1 || null === $date2 ) {
					return '0';
				}
				return (string) $date1->diff( $date2 )->days;
			},
			$formula
		);
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
		$formula = self::expand_formula_functions(
			$formula,
			[
				'price'        => $price,
				'qty'          => $qty,
				'addons'       => $addons,
				'value'        => $val,
				'field_values' => $field_values,
				'product_id'   => $product_id > 0 ? $product_id : null,
			]
		);
		if ( null === $formula ) {
			return 0.0;
		}
		$vars = [ 'P' => $price, 'Q' => (float) $qty, 'A' => $addons, 'V' => (float) $val ];

		$tokens = self::tokenize( $formula, $vars );
		if ( null === $tokens ) {
			return 0.0;
		}
		$pos   = 0;
		$value = self::parse_expression( $tokens, $pos );
		if ( null === $value || $pos < count( $tokens ) ) {
			return 0.0;
		}
		// Negatives allowed here (formulas may offset other addons); the
		// final addon total is clamped at the field_addon boundary.
		return is_finite( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Expand registered functions into numeric literals before tokenization.
	 * No PHP evaluation is used. Nested calls, quoted strings, and WAPF's
	 * semicolon argument separator are parsed explicitly.
	 *
	 * @param array<string,mixed> $context Formula callback context.
	 */
	private static function expand_formula_functions( string $formula, array $context, int $depth = 0 ): ?string {
		if ( $depth > 16 ) {
			return null;
		}

		$out = '';
		$length = strlen( $formula );
		for ( $i = 0; $i < $length; ) {
			$char = $formula[ $i ];
			if ( '\'' === $char || '"' === $char ) {
				$quote = $char;
				$out  .= $char;
				$i++;
				while ( $i < $length ) {
					$out .= $formula[ $i ];
					if ( '\\' === $formula[ $i ] && $i + 1 < $length ) {
						$out .= $formula[ $i + 1 ];
						$i   += 2;
						continue;
					}
					if ( $quote === $formula[ $i++ ] ) {
						break;
					}
				}
				continue;
			}

			if ( ctype_alpha( $char ) || '_' === $char ) {
				$name_end = $i + 1;
				while ( $name_end < $length && ( ctype_alnum( $formula[ $name_end ] ) || '_' === $formula[ $name_end ] ) ) {
					$name_end++;
				}
				$name = strtolower( substr( $formula, $i, $name_end - $i ) );
				$open = $name_end;
				while ( $open < $length && ctype_space( $formula[ $open ] ) ) {
					$open++;
				}
				$builtin_functions = self::builtin_formula_functions();
				$callback = $builtin_functions[ $name ] ?? ( self::$formula_functions[ $name ] ?? null );
				if ( null !== $callback && $open < $length && '(' === $formula[ $open ] ) {
					$close = self::formula_call_end( $formula, $open );
					if ( null === $close ) {
						return null;
					}
					$inner = substr( $formula, $open + 1, $close - $open - 1 );
					$inner = self::expand_formula_functions( $inner, $context, $depth + 1 );
					if ( null === $inner ) {
						return null;
					}
					$args = self::split_formula_arguments( $inner );
					try {
						$result = $callback( $args, $context );
					} catch ( \Throwable $error ) {
						return null;
					}
					if ( is_bool( $result ) ) {
						$result = $result ? 1 : 0;
					}
					if ( ! is_numeric( $result ) || ! is_finite( (float) $result ) ) {
						return null;
					}
					$out .= sprintf( '%.14g', (float) $result );
					$i = $close + 1;
					continue;
				}
			}
			$out .= $char;
			$i++;
		}

		return $out;
	}

	/**
	 * Built-ins exposed by WAPF Free, Pro, and Extended formula references.
	 * Function names, arguments, and examples follow WAPF's reference:
	 * https://www.studiowombat.com/knowledge-base/formula-functions-reference/
	 */
	private static function builtin_formula_functions(): array {
		static $functions = null;
		if ( null !== $functions ) {
			return $functions;
		}

		$numeric = static fn( string $expression, array $context ): float => self::formula_numeric_value( $expression, $context );
		$functions = [
			'min' => static function ( array $args, array $context ) use ( $numeric ) {
				return $args ? min( array_map( static fn( $arg ): float => $numeric( (string) $arg, $context ), $args ) ) : 0;
			},
			'max' => static function ( array $args, array $context ) use ( $numeric ) {
				return $args ? max( array_map( static fn( $arg ): float => $numeric( (string) $arg, $context ), $args ) ) : 0;
			},
			'len' => static function ( array $args ): int {
				$text = (string) ( $args[0] ?? '' );
				if ( isset( $args[1] ) && 'true' === strtolower( trim( (string) $args[1] ) ) ) {
					$text = preg_replace( '/\s/u', '', $text ) ?? $text;
				}
				return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
			},
			'round' => static function ( array $args, array $context ) use ( $numeric ) {
				$value = $numeric( (string) ( $args[0] ?? '' ), $context );
				$precision = isset( $args[1] ) && '' !== trim( (string) $args[1] ) ? (int) $numeric( (string) $args[1], $context ) : 0;
				return round( $value, $precision );
			},
			'abs' => static fn( array $args, array $context ): float => abs( $numeric( (string) ( $args[0] ?? '' ), $context ) ),
			'floor' => static fn( array $args, array $context ): float => floor( $numeric( (string) ( $args[0] ?? '' ), $context ) ),
			'ceil' => static fn( array $args, array $context ): float => ceil( $numeric( (string) ( $args[0] ?? '' ), $context ) ),
			'sqrt' => static fn( array $args, array $context ): float => sqrt( max( 0, $numeric( (string) ( $args[0] ?? '' ), $context ) ) ),
			'pow' => static fn( array $args, array $context ): float => 2 === count( $args ) ? pow( $numeric( (string) $args[0], $context ), $numeric( (string) $args[1], $context ) ) : NAN,
			'sin' => static fn( array $args, array $context ): float => sin( $numeric( (string) ( $args[0] ?? '' ), $context ) ),
			'cos' => static fn( array $args, array $context ): float => cos( $numeric( (string) ( $args[0] ?? '' ), $context ) ),
			'tan' => static fn( array $args, array $context ): float => tan( $numeric( (string) ( $args[0] ?? '' ), $context ) ),
			'if' => static function ( array $args, array $context ) use ( $numeric ) {
				if ( 3 !== count( $args ) ) {
					return NAN;
				}
				return self::formula_condition( (string) $args[0], $context )
					? $numeric( (string) $args[1], $context )
					: $numeric( (string) $args[2], $context );
			},
			'or' => static function ( array $args, array $context ): bool {
				foreach ( $args as $arg ) {
					if ( self::formula_condition( (string) $arg, $context ) ) {
						return true;
					}
				}
				return false;
			},
			'and' => static function ( array $args, array $context ): bool {
				foreach ( $args as $arg ) {
					if ( ! self::formula_condition( (string) $arg, $context ) ) {
						return false;
					}
				}
				return true;
			},
		];

		return $functions;
	}

	/** Evaluate an arithmetic expression using the current pricing context. */
	private static function formula_numeric_value( string $expression, array $context ): float {
		$expression = strtolower( trim( $expression ) );
		if ( 'true' === $expression ) {
			return 1.0;
		}
		if ( 'false' === $expression ) {
			return 0.0;
		}
		return self::evaluate_formula(
			$expression,
			(float) ( $context['price'] ?? 0 ),
			(int) ( $context['qty'] ?? 1 ),
			(float) ( $context['addons'] ?? 0 ),
			(string) ( $context['value'] ?? '' ),
		null,
			(array) ( $context['field_values'] ?? [] ),
			(int) ( $context['product_id'] ?? 0 )
		);
	}

	/** Evaluate one WAPF-style comparison without PHP eval(). */
	private static function formula_condition( string $condition, array $context ): bool {
		$parts = self::formula_comparison_parts( trim( $condition ) );
		if ( null === $parts ) {
			$value = strtolower( trim( $condition ) );
			return in_array( $value, [ 'true', '1' ], true );
		}
		[ $left_raw, $operator, $right_raw ] = $parts;
		$left = self::formula_comparison_value( $left_raw, $context );
		$right = self::formula_comparison_value( $right_raw, $context );
		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			$left = (float) $left;
			$right = (float) $right;
		}
		switch ( $operator ) {
			case '=': return $left === $right;
			case '!=': return $left !== $right;
			case '<': return $left < $right;
			case '>': return $left > $right;
			case '<=': return $left <= $right;
			case '>=': return $left >= $right;
		}
		return false;
	}

	/** @return array{string,string,string}|null */
	private static function formula_comparison_parts( string $expression ): ?array {
		$depth = 0;
		$quote = '';
		$length = strlen( $expression );
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $expression[ $index ];
			if ( '' !== $quote ) {
				if ( $char === $quote && ( 0 === $index || '\\' !== $expression[ $index - 1 ] ) ) {
					$quote = '';
				}
				continue;
			}
			if ( in_array( $char, [ '\'', '"' ], true ) ) {
				$quote = $char;
				continue;
			}
			if ( '(' === $char ) {
				$depth++;
				continue;
			}
			if ( ')' === $char ) {
				$depth--;
				continue;
			}
			if ( 0 !== $depth ) {
				continue;
			}
			$operator = null;
			if ( in_array( substr( $expression, $index, 2 ), [ '!=', '<=', '>=' ], true ) ) {
				$operator = substr( $expression, $index, 2 );
			} elseif ( in_array( $char, [ '=', '<', '>' ], true ) ) {
				$operator = $char;
			}
			if ( null !== $operator ) {
				return [ trim( substr( $expression, 0, $index ) ), $operator, trim( substr( $expression, $index + strlen( $operator ) ) ) ];
			}
		}
		return null;
	}

	/** Evaluate a numeric comparison operand or retain unquoted text. */
	private static function formula_comparison_value( string $value, array $context ) {
		$value = trim( $value );
		if ( strlen( $value ) >= 2 && in_array( $value[0], [ '\'', '"' ], true ) && $value[0] === substr( $value, -1 ) ) {
			return substr( $value, 1, -1 );
		}
		if ( in_array( strtolower( $value ), [ 'true', 'false' ], true ) ) {
			return 'true' === strtolower( $value );
		}
		if ( is_numeric( $value ) || preg_match( '/^[\d\s().+*\/-]+$/', $value ) ) {
			return self::formula_numeric_value( $value, $context );
		}
		return $value;
	}

	/** Find the matching `)` while respecting nested calls and quoted text. */
	private static function formula_call_end( string $formula, int $open ): ?int {
		$depth = 0;
		$quote = '';
		$length = strlen( $formula );
		for ( $i = $open; $i < $length; $i++ ) {
			$char = $formula[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					$i++;
				} elseif ( $quote === $char ) {
					$quote = '';
				}
				continue;
			}
			if ( '\'' === $char || '"' === $char ) {
				$quote = $char;
			} elseif ( '(' === $char ) {
				$depth++;
			} elseif ( ')' === $char && 0 === --$depth ) {
				return $i;
			}
		}
		return null;
	}

	/** @return array<int,string> */
	private static function split_formula_arguments( string $arguments ): array {
		$parts = [];
		$start = 0;
		$depth = 0;
		$quote = '';
		$length = strlen( $arguments );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $arguments[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					$i++;
				} elseif ( $quote === $char ) {
					$quote = '';
				}
				continue;
			}
			if ( '\'' === $char || '"' === $char ) {
				$quote = $char;
			} elseif ( '(' === $char ) {
				$depth++;
			} elseif ( ')' === $char ) {
				$depth--;
			} elseif ( ';' === $char && 0 === $depth ) {
				$parts[] = trim( substr( $arguments, $start, $i - $start ) );
				$start = $i + 1;
			}
		}
		$parts[] = trim( substr( $arguments, $start ) );
		return $parts;
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
	 * @return array<int,array{t:string,v:float}>|null
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
			if ( preg_match( '/\d+(?:\.\d+)?/', substr( $formula, $i ), $m ) && ( '.' === $ch || ctype_digit( $ch ) ) ) {
				$tokens[] = [ 't' => 'num', 'v' => (float) $m[0] ];
				$i       += strlen( $m[0] );
				continue;
			}
			if ( false !== strpos( '+-*/()', $ch ) && 1 === strlen( $ch ) ) {
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
	 * @param array<int,array{t:string,v:float}> $tokens Tokens.
	 * @param int                            $pos    Cursor (by reference).
	 */
	private static function parse_expression( array $tokens, int &$pos ): ?float {
		$value = self::parse_term( $tokens, $pos );
		while ( null !== $value && $pos < count( $tokens ) && in_array( $tokens[ $pos ]['t'], [ '+', '-' ], true ) ) {
			$op = $tokens[ $pos ]['t'];
			$pos++;
			$right = self::parse_term( $tokens, $pos );
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
	 * @param array<int,array{t:string,v:float}> $tokens Tokens.
	 * @param int                            $pos    Cursor (by reference).
	 */
	private static function parse_term( array $tokens, int &$pos ): ?float {
		$value = self::parse_factor( $tokens, $pos );
		while ( null !== $value && $pos < count( $tokens ) && in_array( $tokens[ $pos ]['t'], [ '*', '/' ], true ) ) {
			$op = $tokens[ $pos ]['t'];
			$pos++;
			$right = self::parse_factor( $tokens, $pos );
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
	 * @param array<int,array{t:string,v:float}> $tokens Tokens.
	 * @param int                            $pos    Cursor (by reference).
	 */
	private static function parse_factor( array $tokens, int &$pos ): ?float {
		if ( $pos >= count( $tokens ) ) {
			return null;
		}
		$token = $tokens[ $pos ];
		if ( 'num' === $token['t'] ) {
			$pos++;
			return $token['v'];
		}
		if ( '(' === $token['t'] ) {
			$pos++;
			$value = self::parse_expression( $tokens, $pos );
			if ( null === $value || $pos >= count( $tokens ) || ')' !== $tokens[ $pos ]['t'] ) {
				return null;
			}
			$pos++;
			return $value;
		}
		if ( '-' === $token['t'] ) {
			$pos++;
			$value = self::parse_factor( $tokens, $pos );
			return null === $value ? null : -$value;
		}
		return null;
	}
}
