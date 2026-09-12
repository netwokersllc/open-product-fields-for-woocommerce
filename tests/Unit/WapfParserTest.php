<?php
/**
 * WapfParser unit tests — strict format, damaged (charset-corrupted)
 * payloads in the shape found on production, and JSON payloads.
 *
 * Byte-length notes: PHP serialization prefixes are BYTE lengths. Latin1-era
 * writers counted accented characters as one byte; UTF-8 needs two. A payload
 * like s:7:"Duración" (8 UTF-8 bytes) is therefore unreadable by
 * unserialize() and exercises the recovering decoder.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\WapfParser;
use PHPUnit\Framework\TestCase;

final class WapfParserTest extends TestCase {

	public function test_parses_clean_wapf_payload(): void {
		$payload = 'a:2:{s:2:"id";s:8:"p_214469";s:6:"fields";a:1:{i:0;a:2:{s:2:"id";s:11:"6437e2f37ae";s:5:"label";s:6:"Origen";}}}';
		$parsed  = WapfParser::parse( $payload );
		$this->assertIsArray( $parsed );
		$this->assertSame( 'p_214469', $parsed['id'] );
		$this->assertSame( 'Origen', $parsed['fields'][0]['label'] );
	}

	public function test_repairs_multibyte_length_corruption(): void {
		// "Duración" is 8 UTF-8 bytes but latin1 counted 7.
		$damaged = 'a:1:{s:5:"label";s:7:"Duración";}';
		$this->assertFalse( (bool) @unserialize( $damaged, [ 'allowed_classes' => false ] ), 'payload must be corrupt for this test to be meaningful' );

		$parsed = WapfParser::parse( $damaged );
		$this->assertIsArray( $parsed );
		$this->assertSame( 'Duración', $parsed['label'] );
	}

	public function test_repairs_nested_damaged_payload(): void {
		// Production-shaped payload: multiple damaged multibyte strings nested
		// inside fields and choices ("1 año" = 6 UTF-8 bytes, latin1 said 5;
		// "30 días" = 7 UTF-8 bytes, latin1 said 6).
		$damaged = 'a:3:{s:2:"id";s:8:"p_607379";s:6:"fields";a:1:{i:0;a:3:{s:2:"id";s:10:"produration";s:5:"label";s:5:"1 año";s:7:"options";a:1:{s:7:"choices";a:2:{i:0;a:2:{s:5:"label";s:6:"30 días";s:12:"pricing_type";s:4:"none";}i:1;a:2:{s:5:"label";s:5:"1 año";s:12:"pricing_type";s:2:"fx";}}}}}s:11:"rule_groups";a:0:{}}}';
		$parsed  = WapfParser::parse( $damaged );
		$this->assertIsArray( $parsed );
		$this->assertCount( 2, $parsed['fields'][0]['options']['choices'] );
		$this->assertSame( '1 año', $parsed['fields'][0]['label'] );
		$this->assertSame( '30 días', $parsed['fields'][0]['options']['choices'][0]['label'] );
		$this->assertSame( 'fx', $parsed['fields'][0]['options']['choices'][1]['pricing_type'] );
	}

	public function test_repairs_payload_with_multiple_damaged_strings(): void {
		$damaged = 'a:2:{s:4:"name";s:5:"Español";s:4:"city";s:6:"Zaragoza";}';
		$parsed  = WapfParser::parse( $damaged );
		$this->assertIsArray( $parsed );
		$this->assertSame( 'Español', $parsed['name'] );
		$this->assertSame( 'Zaragoza', $parsed['city'] );
	}

	public function test_repairs_strings_containing_escaped_quotes(): void {
		// Damaged length + escaped quotes inside the string.
		$damaged = 'a:1:{s:4:"text";s:4:"say \"hi\" friend";}';
		$parsed  = WapfParser::parse( $damaged );
		$this->assertIsArray( $parsed );
		$this->assertSame( 'say "hi" friend', $parsed['text'] );
	}

	public function test_parses_json_payload(): void {
		$json   = json_encode( [ 'id' => 'x', 'fields' => [] ] );
		$parsed = WapfParser::parse( (string) $json );
		$this->assertIsArray( $parsed );
		$this->assertSame( 'x', $parsed['id'] );
	}

	public function test_garbage_returns_null(): void {
		$this->assertNull( WapfParser::parse( '' ) );
		$this->assertNull( WapfParser::parse( 'not a payload at all' ) );
	}
}
