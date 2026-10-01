<?php

namespace OPF\Tests\Unit;

use OPF\Service\WapfWxrExporter;
use PHPUnit\Framework\TestCase;

final class WapfWxrExporterTest extends TestCase {

	public function test_exports_all_groups_as_wapf_registered_posts_with_serialized_fieldgroups(): void {
		$groups = [
			[
				'id' => 91,
				'title' => 'Custom finish & sizing',
				'status' => 'publish',
				'menu_order' => 3,
				'data' => [
					'schema' => 1,
					'labels_position' => 'below',
					'fields' => [
						[
							'id' => 'finish', 'label' => 'Finish ]]> & more', 'type' => 'select', 'required' => false,
							'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen', 'selected' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ] ],
							'pricing' => [ 'type' => 'none', 'amount' => 0 ],
							'conditionals' => [],
						],
					],
					'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '44' ] ] ] ] ],
				],
			],
			[
				'id' => 92,
				'title' => 'Second group',
				'status' => 'draft',
				'menu_order' => 4,
				'data' => [ 'schema' => 1, 'fields' => [], 'rule_groups' => [] ],
			],
		];

		$xml = WapfWxrExporter::build_document( $groups, [ 'site_url' => 'https://example.test', 'site_title' => 'Example & Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.2/' );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$this->assertSame( 2, $xpath->query( '/rss/channel/item' )->length );
		$this->assertSame( 'Example & Store', $xpath->query( '/rss/channel/title' )->item( 0 )->textContent );
		$this->assertSame( 'wapf_product', $xpath->query( '/rss/channel/item[1]/wp:post_type' )->item( 0 )->textContent );
		$this->assertSame( 'publish', $xpath->query( '/rss/channel/item[1]/wp:status' )->item( 0 )->textContent );
		$this->assertSame( 'draft', $xpath->query( '/rss/channel/item[2]/wp:status' )->item( 0 )->textContent );

		$content = $xpath->query( '/rss/channel/item[1]/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );
		$this->assertIsArray( $group );
		$this->assertSame( 'wapf_product', $group['type'] );
		$this->assertSame( 'below', $group['layout']['labels_position'] );
		$this->assertSame( 'Finish ]]> & more', $group['fields'][0]['label'] );
		$this->assertSame( 'fixed', $group['fields'][0]['options']['choices'][0]['pricing_type'] );
		$this->assertSame( 2.0, $group['fields'][0]['options']['choices'][0]['pricing_amount'] );
		$this->assertSame( [ [ 'id' => '44', 'text' => '44' ] ], $group['rule_groups'][0]['rules'][0]['value'] );
	}

	public function test_rejects_field_types_the_wapf_json_exporter_cannot_preserve(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'field type "date"' );
		WapfWxrExporter::build_document( [ [
			'id' => 5,
			'title' => 'Unsupported group',
			'data' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'date', 'type' => 'date' ] ] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
	}

	public function test_wxr_preserves_image_swatch_type_and_choice_media_references(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 93,
			'title' => 'Image finishes',
			'data' => [ 'schema' => 1, 'fields' => [ [
				'id' => 'finish',
				'label' => 'Finish',
				'type' => 'swatch',
				'swatch_style' => 'image',
				'image_zoom' => true,
				'label_pos' => 'tooltip',
				'grid_layout' => 'flexible',
				'item_width' => 96,
				'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image' => 'https://example.test/oak.jpg', 'image_id' => 481 ] ],
			] ], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( 'image-swatch', $group['fields'][0]['type'] );
		$this->assertTrue( $group['fields'][0]['options']['large_image'] );
		$this->assertSame( 'tooltip', $group['fields'][0]['options']['label_pos'] );
		$this->assertSame( 'flexible', $group['fields'][0]['options']['grid_layout'] );
		$this->assertSame( 96, $group['fields'][0]['options']['item_width'] );
		$this->assertSame( 'https://example.test/oak.jpg', $group['fields'][0]['options']['choices'][0]['image'] );
		$this->assertSame( 481, $group['fields'][0]['options']['choices'][0]['attachment'] );
	}

	public function test_wxr_preserves_multi_color_swatch_options_and_choices(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 94,
			'title' => 'Color choices',
			'data' => [ 'schema' => 1, 'fields' => [ [
				'id' => 'palette', 'label' => 'Palette', 'type' => 'swatch', 'swatch_style' => 'color',
				'multiple' => true, 'min_choices' => 1, 'max_choices' => 2,
				'color_layout' => 'rounded', 'color_size' => 36, 'color_label_pos' => 'default',
				'choices' => [ [ 'slug' => 'navy', 'label' => 'Navy', 'color' => '#123456' ] ],
			] ], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( 'multi-color-swatch', $group['fields'][0]['type'] );
		$this->assertSame( 1, $group['fields'][0]['options']['min_choices'] );
		$this->assertSame( 2, $group['fields'][0]['options']['max_choices'] );
		$this->assertSame( 'rounded', $group['fields'][0]['options']['layout'] );
		$this->assertSame( 36, $group['fields'][0]['options']['size'] );
		$this->assertSame( '#123456', $group['fields'][0]['options']['choices'][0]['color'] );
	}

	public function test_wxr_preserves_extended_p_content_markup_and_type(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 95,
			'title' => 'Rich content',
			'data' => [ 'schema' => 1, 'fields' => [ [
				'id' => 'offer', 'label' => 'Offer', 'type' => 'paragraph',
				'content_format' => 'html', 'process_shortcodes' => true,
				'content' => '<strong>Special</strong> [site_name]',
			] ], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( 'p', $group['fields'][0]['type'] );
		$this->assertSame( '<strong>Special</strong> [site_name]', $group['fields'][0]['options']['p_content'] );
	}

	public function test_wxr_preserves_informative_image_type_url_and_attachment(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 96, 'title' => 'Informative image',
			'data' => [ 'schema' => 1, 'fields' => [ [
				'id' => 'fabric-guide', 'label' => 'Fabric guide', 'type' => 'content_image',
				'image_url' => 'https://example.test/fabric.jpg', 'image_id' => 481,
			] ], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( 'img', $group['fields'][0]['type'] );
		$this->assertSame( 'https://example.test/fabric.jpg', $group['fields'][0]['options']['image'] );
		$this->assertSame( 481, $group['fields'][0]['options']['attachment'] );
	}

	public function test_wxr_preserves_section_and_sectionend_markers(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 97, 'title' => 'Section layout',
			'data' => [ 'schema' => 1, 'fields' => [
				[ 'id' => 'details', 'type' => 'section' ],
				[ 'id' => 'note', 'type' => 'paragraph', 'content' => 'Inside' ],
				[ 'id' => 'details-end', 'type' => 'section_end' ],
			], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( [ 'section', 'content', 'sectionend' ], array_column( $group['fields'], 'type' ) );
	}

	public function test_requires_valid_site_url_and_source_group_identity(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'source site URL' );
		WapfWxrExporter::build_document( [], [ 'site_url' => '', 'site_title' => 'Example Store' ] );
	}
}
