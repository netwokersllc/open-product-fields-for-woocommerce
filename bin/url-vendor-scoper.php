<?php
/** Build-only configuration. Runtime code supports PHP 7.4. */
return [
	'prefix' => 'OPF\\Vendor',
	'expose-global-classes' => false,
	'expose-global-functions' => false,
	'expose-global-constants' => false,
	'patchers' => [
		static function ( string $path, string $prefix, string $contents ): string {
			// PHP 7.1-compatible explicit nullable types also avoid PHP 8.4 warnings.
			$contents = preg_replace( '/([,(]\\s*)([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*) (\\$[a-zA-Z_][a-zA-Z0-9_]*) = null/', '$1?$2 $3 = null', $contents );
			// Keep optional extension fallbacks private to this plugin.
			if ( false !== strpos( $path, '/rowbot/' ) ) {
				$contents = preg_replace( '/(?<![a-zA-Z0-9_:])(?:\\\\)?(mb_(?:strlen|substr|convert_encoding|substitute_character|scrub))\\(/', '\\\\OPF\\\\Vendor\\\\$1(', $contents );
				$contents = str_replace( 'use Normalizer;', 'use OPF\\Vendor\\Normalizer;', $contents );
			}
			if ( false !== strpos( $path, '/polyfill-intl-normalizer/' ) ) {
				$contents = str_replace( '\\Normalizer::', '\\OPF\\Vendor\\Normalizer::', $contents );
			}
			return $contents;
		},
	],
];
