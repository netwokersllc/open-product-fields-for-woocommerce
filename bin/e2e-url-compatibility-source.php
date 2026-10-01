<?php
/** Read the installed WAPF URL sanitation contract in disposable WordPress. */
if ( '1' !== getenv( 'OPF_URL_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
 throw new RuntimeException( 'Explicit disposable /tmp site authorization required.' );
}
$root = WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce/';
require_once $root . 'includes/models/class-fieldpricing.php';
require_once $root . 'includes/models/class-field.php';
require_once $root . 'includes/classes/class-fields.php';
$field = new SW_WAPF\Includes\Models\Field();
$field->type = 'url';
$field->required = true;
$cases = [ 'https://例え.テスト/こんにちは?q=✓', 'https://example.invalid/café?q=é', 'https://example.invalid/a b', 'customsafe://example.invalid/path', 'mailto:é@example.invalid', 'tel:+123', 'urn:isbn:978123', 'http:example.invalid', 'http://256.256.256.256' ];
$observations = [];
foreach ( $cases as $value ) {
 $clean = SW_WAPF\Includes\Classes\Fields::sanitize_value( $field, $value );
 $default_error = null;
 try {
  new OPF\Engine\FieldGroup( [ 'fields' => [ [ 'id' => 'url', 'type' => 'url', 'default' => $value ] ] ] );
 } catch ( InvalidArgumentException $error ) { $default_error = $error->getMessage(); }
 $observations[] = [
  'value' => $value,
  'wapf_sanitized' => $clean,
  'wapf_server_valid' => SW_WAPF\Includes\Classes\Fields::is_field_value_valid( $field, $clean ),
  'opf_server_valid' => ! OPF\Engine\FieldValue::validate( [ 'type' => 'url', 'label' => 'URL' ], $value, true ),
  'opf_default_error' => $default_error,
 ];
}
echo wp_json_encode( [ 'wordpress_allowed_protocols' => wp_allowed_protocols(), 'observations' => $observations ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
