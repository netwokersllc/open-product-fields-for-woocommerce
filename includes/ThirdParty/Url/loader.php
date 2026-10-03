<?php
/** Private, lazily loaded URL parser dependencies; no Composer at runtime. */
namespace OPF\Vendor;

defined( 'ABSPATH' ) || exit;

spl_autoload_register( static function ( string $class ): void {
	$maps = [
		'OPF\\Vendor\\Rowbot\\Idna\\Resource\\' => '/rowbot/idna/resources/',
		'OPF\\Vendor\\Rowbot\\Idna\\' => '/rowbot/idna/src/',
		'OPF\\Vendor\\Rowbot\\Punycode\\' => '/rowbot/punycode/src/',
		'OPF\\Vendor\\Rowbot\\URL\\' => '/rowbot/url/src/',
		'OPF\\Vendor\\Brick\\Math\\' => '/brick/math/src/',
		'OPF\\Vendor\\Symfony\\Polyfill\\Mbstring\\' => '/symfony/polyfill-mbstring/',
		'OPF\\Vendor\\Symfony\\Polyfill\\Intl\\Normalizer\\' => '/symfony/polyfill-intl-normalizer/',
	];
	foreach ( $maps as $prefix => $directory ) {
		if ( 0 === strpos( $class, $prefix ) ) {
			$file = __DIR__ . $directory . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) { require_once $file; }
			return;
		}
	}
} );

// Private shims avoid defining mb_* functions or a Normalizer class globally.
function mb_strlen( $value, $encoding = null ) {
	return function_exists( '\\mb_strlen' ) ? \mb_strlen( $value, $encoding ) : Symfony\Polyfill\Mbstring\Mbstring::mb_strlen( $value, $encoding );
}
function mb_substr( $value, $start, $length = null, $encoding = null ) {
	return function_exists( '\\mb_substr' ) ? \mb_substr( $value, $start, $length, $encoding ) : Symfony\Polyfill\Mbstring\Mbstring::mb_substr( $value, $start, $length, $encoding );
}
function mb_convert_encoding( $value, $to, $from = null ) {
	return function_exists( '\\mb_convert_encoding' ) ? \mb_convert_encoding( $value, $to, $from ) : Symfony\Polyfill\Mbstring\Mbstring::mb_convert_encoding( $value, $to, $from );
}
function mb_substitute_character( $character = null ) {
	return function_exists( '\\mb_substitute_character' ) ? \mb_substitute_character( $character ) : Symfony\Polyfill\Mbstring\Mbstring::mb_substitute_character( $character );
}
function mb_scrub( $value, $encoding = null ) {
	return function_exists( '\\mb_scrub' ) ? \mb_scrub( $value, $encoding ) : Symfony\Polyfill\Mbstring\Mbstring::mb_scrub( $value, $encoding );
}
class Normalizer extends Symfony\Polyfill\Intl\Normalizer\Normalizer {
	public const FORM_D = 4;
	public const FORM_KD = 8;
	public const FORM_C = 16;
	public const FORM_KC = 32;
	public const NFD = 4;
	public const NFKD = 8;
	public const NFC = 16;
	public const NFKC = 32;
}
