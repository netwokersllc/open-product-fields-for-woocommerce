<?php

namespace OPF\Service {
	function apply_filters( $name, $value, ...$args ) {
		$callback = $GLOBALS['opf_wpml_filters'][ $name ] ?? null;
		return $callback ? $callback( $value, ...$args ) : $value;
	}
	function do_action( $name, ...$args ): void {
		$GLOBALS['opf_wpml_actions'][] = [ $name, $args ];
	}
}

namespace OPF\Tests\Unit {
	use OPF\Engine\FieldGroup;
	use OPF\Service\FieldGroups;
	use OPF\Service\WpmlIntegration;
	use PHPUnit\Framework\TestCase;

	final class WpmlIntegrationTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['opf_wpml_filters'] = [];
			$GLOBALS['opf_wpml_actions'] = [];
			$GLOBALS['opf_woocs_meta'] = [];
		}

		protected function tearDown(): void {
			unset( $GLOBALS['opf_wpml_filters'], $GLOBALS['opf_wpml_actions'], $GLOBALS['opf_woocs_meta'] );
			FieldGroups::flush_cache();
			unset( $GLOBALS['opf_auth_test_posts'], $GLOBALS['opf_auth_test_cache'] );
		}

		private function group(): array {
			return [
				'schema' => 1,
				'fields' => [
					[
						'id' => 'gift', 'type' => 'select', 'label' => 'Gift wrap',
						'description' => 'Choose wrapping', 'placeholder' => 'Select',
						'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ] ],
						'pricing' => [ 'type' => 'formula', 'formula' => '[field.width]*2' ],
						'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'other', 'operator' => 'is', 'value' => 'yes' ] ] ] ],
						'repeat' => [ 'enabled' => true, 'mode' => 'button', 'add' => 'Add', 'del' => 'Remove', 'label' => 'Copy {n}' ],
					],
					[ 'id' => 'info', 'type' => 'paragraph', 'content' => '<b>Information</b>', 'content_format' => 'html' ],
				],
				'rule_groups' => [ [ 'rules' => [
					[ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '12' ] ],
					[ 'subject' => 'product_cat', 'operator' => 'not_in', 'terms' => [ '3' ] ],
					[ 'subject' => 'product_tag', 'operator' => 'in', 'terms' => [ '4' ] ],
					[ 'subject' => 'user_role', 'operator' => 'in', 'terms' => [ 'customer' ] ],
				] ] ],
			];
		}

		public function test_only_display_text_changes_and_names_survive_reordering(): void {
			$source = $this->group();
			$strings = [];
			$translated = WpmlIntegration::map_text( $source, static function ( $text, $name, $type ) use ( &$strings ) {
				$strings[ $name ] = [ $text, $type ];
				return 'fr:' . $text;
			} );
			$this->assertSame( 'fr:Gift wrap', $translated['fields'][0]['label'] );
			$this->assertSame( 'fr:Red', $translated['fields'][0]['choices'][0]['label'] );
			$this->assertSame( 'fr:Add', $translated['fields'][0]['repeat']['add'] );
			$this->assertSame( [ '<b>Information</b>', 'VISUAL' ], $strings['field:info:content'] );
			foreach ( [ 'id', 'pricing', 'conditionals' ] as $key ) {
				$this->assertSame( $source['fields'][0][$key], $translated['fields'][0][$key] );
			}
			$this->assertSame( 'red', $translated['fields'][0]['choices'][0]['slug'] );
			$this->assertSame( $source['rule_groups'], $translated['rule_groups'] );
			$reordered = $source;
			$reordered['fields'] = array_reverse( $reordered['fields'] );
			$names = [];
			WpmlIntegration::map_text( $reordered, static function ( $text, $name ) use ( &$names ) { $names[] = $name; return $text; } );
			$this->assertEqualsCanonicalizing( array_keys( $strings ), $names );
		}

		public function test_registration_and_deletion_follow_package_lifecycle(): void {
			$post = (object) [ 'post_type' => 'opf_field_group', 'post_status' => 'publish', 'post_title' => 'Gift', 'post_content' => json_encode( $this->group() ) ];
			WpmlIntegration::register_post( 7, $post );
			$actions = $GLOBALS['opf_wpml_actions'];
			$this->assertSame( 'wpml_start_string_package_registration', $actions[0][0] );
			$this->assertSame( 'wpml_delete_unused_package_strings', end( $actions )[0] );
			$this->assertSame( '7', $actions[0][1][0]['name'] );
			$this->assertSame( 'Gift (#7)', $actions[0][1][0]['title'] );
			$this->assertCount( 10, $actions ); // Eight display strings, plus lifecycle markers.
			WpmlIntegration::delete_post( 7, $post );
			$this->assertSame( [ 'wpml_delete_package', [ '7', 'open-product-fields' ] ], end( $GLOBALS['opf_wpml_actions'] ) );
		}

		public function test_runtime_translation_maps_explicit_language_without_mutating_source(): void {
			$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => 'fr';
			$GLOBALS['opf_wpml_filters']['wpml_translate_string'] = static fn( $text ) => 'fr:' . $text;
			$calls = [];
			$GLOBALS['opf_wpml_filters']['wpml_object_id'] = static function ( $id, $type, $fallback, $language ) use ( &$calls ) {
				$calls[] = [ $id, $type, $fallback, $language ];
				return 'product' === $type ? 112 : $id + 100;
			};
			$source = new FieldGroup( $this->group() );
			$entries = [ [ 'id' => 7, 'title' => 'Gift', 'lang' => '', 'group' => $source ] ];
			$result = WpmlIntegration::translate_groups( $entries );
			$this->assertNotSame( $source, $result[0]['group'] );
			$this->assertSame( 'Gift wrap', $source->data['fields'][0]['label'] );
			$this->assertSame( 'fr:Gift wrap', $result[0]['group']->data['fields'][0]['label'] );
			$this->assertSame( [ '112' ], $result[0]['group']->data['rule_groups'][0]['rules'][0]['terms'] );
			$this->assertSame( [ '12' ], $source->data['rule_groups'][0]['rules'][0]['terms'] );
			$this->assertSame( [ [ 12, 'product', true, 'fr' ], [ 3, 'product_cat', true, 'fr' ], [ 4, 'product_tag', true, 'fr' ] ], $calls );
		}

		public function test_absent_wpml_and_all_languages_leave_entries_unchanged(): void {
			$entries = [ [ 'id' => 7, 'group' => new FieldGroup( $this->group() ) ] ];
			$this->assertSame( $entries, WpmlIntegration::translate_groups( $entries ) );
			$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => 'all';
			$this->assertSame( $entries, WpmlIntegration::translate_groups( $entries ) );
		}

		public function test_runtime_seam_keeps_source_groups_and_translations_fresh(): void {
			$data = $this->group();
			$data['rule_groups'] = [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '12' ] ] ] ] ];
			$GLOBALS['opf_auth_test_posts'] = [ new \WP_Post( 7, 'Gift', json_encode( $data ) ) ];
			$GLOBALS['opf_auth_test_cache'] = [];
			FieldGroups::flush_cache();
			$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => 'fr';
			$GLOBALS['opf_wpml_filters']['wpml_object_id'] = static fn() => 42;
			$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = [ WpmlIntegration::class, 'translate_groups' ];
			$GLOBALS['opf_wpml_filters']['wpml_translate_string'] = static fn( $text ) => 'fr:' . $text;
			$first = FieldGroups::for_product( new \WC_Product() );
			$this->assertSame( 'fr:Gift wrap', $first[0]['group']->data['fields'][0]['label'] );
			$GLOBALS['opf_wpml_filters']['wpml_translate_string'] = static fn( $text ) => 'updated:' . $text;
			$second = FieldGroups::for_product( new \WC_Product() );
			$this->assertSame( 'updated:Gift wrap', $second[0]['group']->data['fields'][0]['label'] );
			$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => 'de';
			$GLOBALS['opf_wpml_filters']['wpml_translate_string'] = static fn( $text ) => 'de:' . $text;
			$third = FieldGroups::for_product( new \WC_Product() );
			$this->assertSame( 'de:Gift wrap', $third[0]['group']->data['fields'][0]['label'] );
			$this->assertSame( 'Gift wrap', FieldGroups::all()[0]['group']->data['fields'][0]['label'] );
			$this->assertSame( [], $GLOBALS['opf_auth_test_cache']['opf_groups_for_product'] );
		}

		public function test_unresolved_import_language_ownership_is_not_remapped(): void {
			$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => 'fr';
			$GLOBALS['opf_wpml_filters']['wpml_translate_string'] = static function () { throw new \RuntimeException( 'Must not guess import language.' ); };
			$GLOBALS['opf_woocs_meta'][7]['_opf_imported_from'] = 'meta:12';
			$entries = [ [ 'id' => 7, 'group' => new FieldGroup( $this->group() ) ] ];
			$this->assertSame( $entries, WpmlIntegration::translate_groups( $entries ) );
			$GLOBALS['opf_woocs_meta'][7] = [ '_opf_archive_import_key' => 'opaque-archive-id' ];
			$this->assertSame( $entries, WpmlIntegration::translate_groups( $entries ) );
		}

		public function test_source_language_uses_the_element_record_without_admin_language_fallback(): void {
			$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => 'de';
			$GLOBALS['opf_wpml_filters']['wpml_default_language'] = static fn() => 'en';
			$this->assertSame( '', WpmlIntegration::source_language( 12, 'product' ) );
			$calls = [];
			$GLOBALS['opf_wpml_filters']['wpml_element_language_code'] = static function ( $language, $args ) use ( &$calls ) {
				$calls[] = [ $language, $args ];
				return 'product' === $args['element_type'] ? 'fr' : 'en';
			};
			$this->assertSame( 'fr', WpmlIntegration::source_language( 12, 'product' ) );
			$this->assertSame( 'en', WpmlIntegration::source_language( 34, 'wapf_product' ) );
			$this->assertSame( [
				[ null, [ 'element_id' => 12, 'element_type' => 'product' ] ],
				[ null, [ 'element_id' => 34, 'element_type' => 'wapf_product' ] ],
			], $calls );
			$this->assertSame( '', WpmlIntegration::source_language( 0, 'product' ) );
			$this->assertSame( '', WpmlIntegration::source_language( 12, 'attachment' ) );
			foreach ( [ 'all', '', null, false, [], 'fr invalid' ] as $invalid ) {
				$GLOBALS['opf_wpml_filters']['wpml_element_language_code'] = static fn() => $invalid;
				$this->assertSame( '', WpmlIntegration::source_language( 12, 'product' ) );
			}
		}

		public function test_owned_imports_render_once_in_their_language_with_original_labels_and_targets(): void {
			$GLOBALS['opf_wpml_filters']['wpml_translate_string'] = static function () { throw new \RuntimeException( 'Imported labels are already localized.' ); };
			$GLOBALS['opf_wpml_filters']['wpml_object_id'] = static function () { throw new \RuntimeException( 'Do not remap independently localized imports.' ); };
			$GLOBALS['opf_woocs_meta'] = [
				7 => [ '_opf_imported_from' => '10', '_opf_wpml_source_language' => 'en' ],
				8 => [ '_opf_imported_from' => '20', '_opf_wpml_source_language' => 'fr' ],
			];
			$entries = [
				[ 'id' => 7, 'group' => new FieldGroup( $this->group() ) ],
				[ 'id' => 8, 'group' => new FieldGroup( $this->group() ) ],
			];
			foreach ( [ [ 'fr', 8 ], [ 'en', 7 ], [ 'de', null ], [ 'fr', 8 ] ] as [ $language, $expected ] ) {
				$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => $language;
				$result = WpmlIntegration::translate_groups( $entries );
				$this->assertSame( null === $expected ? [] : [ $expected ], array_column( $result, 'id' ) );
				if ( null !== $expected ) {
					$this->assertSame( $entries[ 7 === $expected ? 0 : 1 ]['group'], reset( $result )['group'] );
				}
			}
			unset( $GLOBALS['opf_wpml_filters']['wpml_current_language'] );
			$this->assertSame( $entries, WpmlIntegration::translate_groups( $entries ) );
			$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => 'all';
			$this->assertSame( $entries, WpmlIntegration::translate_groups( $entries ) );
		}

		public function test_owned_import_language_switch_keeps_source_and_product_caches_clean(): void {
			$data = $this->group();
			$data['rule_groups'] = [];
			$french = $data;
			$french['fields'][0]['label'] = 'Emballage cadeau';
			$GLOBALS['opf_auth_test_posts'] = [
				new \WP_Post( 7, 'English', json_encode( $data ) ),
				new \WP_Post( 8, 'French', json_encode( $french ) ),
			];
			$GLOBALS['opf_woocs_meta'] = [
				7 => [ '_opf_imported_from' => 'meta:12', '_opf_wpml_source_language' => 'en' ],
				8 => [ '_opf_imported_from' => 'meta:42', '_opf_wpml_source_language' => 'fr' ],
			];
			$GLOBALS['opf_auth_test_cache'] = [];
			FieldGroups::flush_cache();
			$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = [ WpmlIntegration::class, 'translate_groups' ];
			foreach ( [ 'fr', 'en', 'de', 'fr' ] as $language ) {
				$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => $language;
				$result = FieldGroups::for_product( new \WC_Product() );
				$this->assertSame( 'de' === $language ? [] : [ 'fr' === $language ? 8 : 7 ], array_column( $result, 'id' ) );
				if ( $result ) {
					$this->assertSame( 'fr' === $language ? 'Emballage cadeau' : 'Gift wrap', $result[0]['group']->data['fields'][0]['label'] );
				}
			}
			$this->assertCount( 2, FieldGroups::all() );
			$this->assertSame( [], $GLOBALS['opf_auth_test_cache']['opf_groups_for_product'] );
			WpmlIntegration::init();
			$this->assertSame( [ [ FieldGroups::class, 'flush_cache' ], 100, 1 ], $GLOBALS['opf_woocs_hooks']['wpml_switch_language'] );
			unset( $GLOBALS['opf_woocs_hooks'] );
		}

		public function test_imports_do_not_register_packages_in_the_admin_language(): void {
			$post = (object) [ 'post_type' => 'opf_field_group', 'post_status' => 'publish', 'post_title' => 'Imported', 'post_content' => json_encode( $this->group() ) ];
			foreach ( [
				[ '_opf_imported_from' => 'meta:12', '_opf_wpml_source_language' => 'fr' ],
				[ '_opf_imported_from' => '12' ],
				[ '_opf_archive_import_key' => 'legacy-archive' ],
			] as $meta ) {
				$GLOBALS['opf_woocs_meta'][7] = $meta;
				WpmlIntegration::register_post( 7, $post );
			}
			$this->assertSame( [], $GLOBALS['opf_wpml_actions'] );
		}

		public function test_invalid_or_unrelated_posts_cannot_register_or_delete_packages(): void {
			WpmlIntegration::register_post( 7, (object) [ 'post_type' => 'product', 'post_content' => '{}' ] );
			WpmlIntegration::register_post( 7, (object) [ 'post_type' => 'opf_field_group', 'post_content' => 'invalid JSON' ] );
			WpmlIntegration::delete_post( 7, (object) [ 'post_type' => 'product' ] );
			$this->assertSame( [], $GLOBALS['opf_wpml_actions'] );
			$config = simplexml_load_file( OPF_DIR . 'wpml-config.xml' );
			$this->assertSame( '0', (string) $config->{'custom-types'}->{'custom-type'}['translate'] );
			$this->assertSame( 'opf_field_group', (string) $config->{'custom-types'}->{'custom-type'} );
		}
	}
}
