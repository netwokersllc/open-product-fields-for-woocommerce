# Public PHP API

OPF exposes helpers with the `opf_` prefix. These are OPF APIs; WAPF's beta
global functions are not defined or overridden.

## Formula functions

Register a numeric function during the current PHP request:

```php
opf_add_formula_function(
	'area_cost',
	static function ( array $arguments, array $context ): float {
		$width  = $arguments[0] ?? 0.0;
		$height = $arguments[1] ?? 0.0;
		return $width * $height * 0.02;
	}
);
```

Use it in a field's formula as `area_cost(12, 8)`. Arguments are arithmetic
expressions evaluated by OPF's formula parser. The callback receives float
arguments and this context: `price`, `quantity`, `addons`, `value`, `today`,
and validated `field_values`.

Function names are ASCII identifiers up to 32 characters. `today`, `dow`,
`month`, and formula variable names `p`, `q`, `a`, and `v` are reserved. A
callback must return a finite number; thrown errors,
non-numeric results, non-finite results, malformed arguments, and division by
zero make the formula return zero. Callbacks run during server-side pricing,
so they should be deterministic and avoid side effects.

This is OPF's stable numeric callback contract. WAPF's beta
`wapf_add_formula_function()` callback receives raw string arguments and a
different context; this helper does not claim source-compatible callback
signatures. WAPF's current paid package still needs review before OPF 1.0.
