<?php
namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use OPF\Engine\RepeaterField;
use OPF\Service\CartIntegration;
use OPF\Engine\WapfMapper;
use OPF\Service\WapfExporter;
use PHPUnit\Framework\TestCase;

final class UrlFieldTest extends TestCase {
 public function test_wapf_url_default_and_placeholder_round_trip(): void {
  foreach ( [ 'https://例え.テスト/こんにちは?q=✓', 'https://example.invalid/a b', 'mailto:é@example.invalid', 'tel:+123', 'urn:isbn:978123', 'http:example.invalid', '' ] as $value ) {
   $mapped = WapfMapper::map( [ 'fields' => [ [ 'id' => 'url', 'label' => 'URL', 'type' => 'url', 'required' => true, 'options' => [ 'default' => $value, 'placeholder' => 'Profile URL' ] ] ] ] );
   $this->assertFalse( $mapped['needs_review'] );
   $field = $mapped['group']['fields'][0];
   $this->assertSame( $value, $field['default'] );
   $this->assertSame( 'Profile URL', $field['placeholder'] );
   $payload = WapfExporter::build_payload( $mapped['group'] );
   $this->assertSame( $value, $payload['fields'][0]['default'] );
  }
 }
 public function test_native_unicode_and_opaque_urls_keep_their_exact_values(): void {
  $field = [ 'type' => 'url', 'label' => 'URL' ];
  foreach ( [ 'https://例え.テスト/こんにちは?q=✓', 'https://example.invalid/café?q=é', 'https://example.invalid/a b', 'mailto:é@example.invalid', 'tel:+123', 'urn:isbn:978123', 'http:example.invalid' ] as $value ) {
   $this->assertSame( $value, FieldValue::sanitize( $field, ' ' . $value . ' ' ) );
   $this->assertSame( [], FieldValue::validate( $field, $value, true ), $value );
   $group = new FieldGroup( [ 'fields' => [ [ 'id' => 'url', 'type' => 'url', 'default' => $value ] ] ] );
   $this->assertSame( $value, $group->data['fields'][0]['default'] );
   $this->assertSame( [], RepeaterField::validate( $field + [ 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ] ], [ $value ], true ) );
  }
 }
 public function test_native_invalid_hosts_and_unsafe_values_are_rejected(): void {
  $field = [ 'type' => 'url', 'label' => 'URL' ];
  foreach ( [ 'http://256.256.256.256', 'http://1.2.3.999', 'http://[no]/', 'https://example.invalid:99999/', 'https://', 'https://%zz/', 'data:text/html,x', 'javascript:alert(1)', 'vbscript:alert(1)', "\0https://example.invalid/", 'https://example.invalid/' . "\0", 'https://example.invalid/<script>', 'https://example.invalid/"onclick="x' ] as $value ) {
   $clean = FieldValue::sanitize( $field, $value );
   $this->assertNotNull( $clean, $value );
   $this->assertNotEmpty( FieldValue::validate( $field, $clean, true ), $value );
  }
 }
 public function test_repeated_url_uses_the_same_validation(): void {
  $field = [ 'type' => 'url', 'label' => 'URL', 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ] ];
  $this->assertSame( [], RepeaterField::validate( $field, [ 'https://example.invalid/' ], true ) );
  $this->assertSame( [ '"URL" must be a valid URL in repeated row 1.' ], RepeaterField::validate( $field, [ 'not-a-url' ], true ) );
 }
 public function test_cart_transport_preserves_malformed_url_for_validation(): void {
  $method = new \ReflectionMethod( CartIntegration::class, 'sanitize_value' );
  $field = [ 'type' => 'url', 'label' => 'URL' ];
  foreach ( [ 'not-a-url', 'https://example.invalid/<script>', 'javascript:alert(1)' ] as $value ) {
   $clean = $method->invoke( null, $field, $value );
   $this->assertSame( $value, $clean );
   $this->assertNotEmpty( FieldValue::validate( $field, $clean, true ) );
  }
  $this->assertNotEmpty( FieldValue::validate( $field, $method->invoke( null, $field, [ 'https://example.invalid/' ] ), true ) );
 }
 public function test_valid_schemes_and_optional_empty(): void {
  $field = [ 'type' => 'url', 'label' => 'URL' ];
  foreach ( [ 'https://example.invalid/?a=1&b=2', 'http://localhost/x', 'ftp://example.invalid/x', 'ftps://example.invalid/x', 'mailto:ada@example.invalid', 'irc://example.invalid/channel' ] as $value ) {
   $this->assertSame( $value, FieldValue::sanitize( $field, ' ' . $value . ' ' ) );
   $this->assertSame( [], FieldValue::validate( $field, $value, true ) );
  }
  $this->assertNull( FieldValue::sanitize( $field, '' ) );
  $this->assertSame( [], FieldValue::validate( $field, null, true ) );
  $this->assertSame( [], FieldValue::validate( $field, null, false ) );
  $this->assertNotEmpty( FieldValue::validate( $field + [ 'required' => true ], null, true ) );
 }
 public function test_malformed_scalar_and_array_are_rejected_without_repair(): void {
  $field = [ 'type' => 'url', 'label' => 'URL' ];
  foreach ( [ 'not-a-url', 'example.invalid/x', 'https://', 'javascript:alert(1)', 'data:text/html,x', 'https://example.invalid/<script>', 'https://example.invalid/"onclick="x', "https://example.invalid/\npath", [ 'https://example.invalid/' ] ] as $value ) {
   $clean = FieldValue::sanitize( $field, $value );
   $this->assertNotNull( $clean );
   $this->assertSame( [ '"URL" must be a valid URL.' ], FieldValue::validate( $field, $clean, true ) );
  }
 }
 public function test_default_is_trimmed_and_legacy_shape_retained(): void {
  $group = new FieldGroup( [ 'fields' => [ [ 'id' => 'url', 'type' => 'url', 'default' => ' https://example.invalid/?a=1&b=2 ' ] ] ] );
  $this->assertSame( 'https://example.invalid/?a=1&b=2', $group->data['fields'][0]['default'] );
  $legacy = new FieldGroup( [ 'fields' => [ [ 'id' => 'url', 'type' => 'url' ] ] ] );
  $this->assertArrayNotHasKey( 'default', $legacy->data['fields'][0] );
 }
 public function test_invalid_default_is_rejected(): void {
  $this->expectException( \InvalidArgumentException::class );
  new FieldGroup( [ 'fields' => [ [ 'id' => 'url', 'type' => 'url', 'default' => 'not-a-url' ] ] ] );
 }
 public function test_array_default_is_rejected(): void {
  $this->expectException( \InvalidArgumentException::class );
  new FieldGroup( [ 'fields' => [ [ 'id' => 'url', 'type' => 'url', 'default' => [] ] ] ] );
 }
}
