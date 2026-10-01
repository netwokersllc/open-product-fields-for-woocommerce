<?php
/**
 * Standalone PHP 7.1+ archive JSON regression. No WordPress or database writes.
 * Empty groups avoid loading FieldGroup's separately tracked PHP 7.4 syntax.
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/includes/Service/ArchiveImporter.php';

use OPF\Service\ArchiveImporter;

set_error_handler( static function ( $severity, $message, $file, $line ) {
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );

$passed = 0;
$failed = 0;
$valid = '{"format":"opf-field-groups","format_version":1,"scope":{"type":"all","site":"https://example.test/","label":"Español"},"groups":[]}';
$decoded = json_decode( $valid, true );

$cases = [
	'valid empty archive' => [ $valid, null ],
	'truncated object' => [ '{', 'OPF archive is not valid JSON.' ],
	'trailing data' => [ $valid . ' trailing', 'OPF archive is not valid JSON.' ],
	'empty input' => [ '', 'OPF archive is not valid JSON.' ],
	'invalid UTF-8' => [ '{"scope":"' . chr( 255 ) . '"}', 'OPF archive is not valid JSON.' ],
	'invalid UTF-16 surrogate' => [ '{"scope":"\\uD800"}', 'OPF archive is not valid JSON.' ],
	'depth over 64' => [ str_repeat( '[', 65 ) . '0' . str_repeat( ']', 65 ), 'OPF archive is not valid JSON.' ],
	'valid JSON null' => [ 'null', 'OPF archive format or version is unsupported.' ],
	'valid JSON scalar' => [ '42', 'OPF archive format or version is unsupported.' ],
	'unsupported version' => [ str_replace( '"format_version":1', '"format_version":2', $valid ), 'OPF archive format or version is unsupported.' ],
	'oversize input' => [ str_repeat( ' ', ArchiveImporter::MAX_BYTES + 1 ), 'OPF archive exceeds the 5 MiB import limit.' ],
	'valid after invalid JSON' => [ $valid, null ],
];

// A throwing json_decode call does not reset an unrelated prior JSON error.
// Verify that a successful archive does not inspect that stale global state.
json_decode( '{' );

foreach ( $cases as $label => $case ) {
	try {
		$result = ArchiveImporter::decode( $case[0] );
		if ( null !== $case[1] || $decoded !== $result ) {
			throw new RuntimeException( 'Unexpected decoded archive or accepted invalid input.' );
		}
		++$passed;
		echo 'PASS ', $label, "\n";
	} catch ( InvalidArgumentException $exception ) {
		if ( null !== $case[1] && $case[1] === $exception->getMessage() ) {
			if ( 'OPF archive is not valid JSON.' === $case[1] && defined( 'JSON_THROW_ON_ERROR' ) && ! ( $exception->getPrevious() instanceof JsonException ) ) {
				++$failed;
				echo 'FAIL ', $label, ': modern JSON exception chain was lost.', "\n";
			} else {
				++$passed;
				echo 'PASS ', $label, "\n";
			}
		} else {
			++$failed;
			echo 'FAIL ', $label, ': ', $exception->getMessage(), "\n";
		}
	} catch ( Throwable $exception ) {
		++$failed;
		echo 'FAIL ', $label, ': ', get_class( $exception ), ': ', $exception->getMessage(), "\n";
	}
}

restore_error_handler();
echo 'PHP ', PHP_VERSION, ' TOTAL ', count( $cases ), ' PASSED ', $passed, ' FAILED ', $failed, "\n";
exit( $failed ? 1 : 0 );
