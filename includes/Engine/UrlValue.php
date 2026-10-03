<?php
/** Native HTML URL parsing with WordPress's safe protocol policy. */
namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class UrlValue {
	private const PROTOCOLS = [ 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn' ];

	/** Validate without rewriting the stored selection/default, matching WAPF. */
	public static function is_valid( string $value ): bool {
		// Bound parser work and preserve rejected input for an actionable error.
		if ( strlen( $value ) > 65536 || preg_match( '/[\x00-\x1f\x7f<>"`]/', $value ) || ! preg_match( '/^([a-z][a-z0-9+.-]*):/i', $value, $scheme ) ) {
			return false;
		}
		$protocol = strtolower( $scheme[1] );
		$allowed = function_exists( 'wp_allowed_protocols' ) ? wp_allowed_protocols() : self::PROTOCOLS;
		if ( in_array( $protocol, [ 'javascript', 'data', 'vbscript' ], true ) || ! in_array( $protocol, $allowed, true ) ) {
			return false;
		}
		require_once OPF_DIR . 'includes/ThirdParty/Url/loader.php';
		try {
			// WHATWG handles IDN, opaque URLs, shortened special schemes and IPv4.
			new \OPF\Vendor\Rowbot\URL\URL( $value );
			return true;
		} catch ( \OPF\Vendor\Rowbot\URL\Exception\TypeError $error ) {
			return false;
		}
	}
}
