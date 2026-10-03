<?php
/** Engine gettext stub, scoped to avoid other tests' global WordPress stubs. */

namespace OPF\Engine;

function __( $text, $domain = null ): string {
	return (string) ( $GLOBALS['opf_test_translations'][ $domain ][ $text ] ?? $text );
}
