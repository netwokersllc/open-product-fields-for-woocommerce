<?php
/**
 * Resolve the active translation language and available language choices.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class LanguageResolver {

	/** Current-language key, matching WAPF's Polylang locale/WPML code behavior. */
	public static function current(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$language = pll_current_language( 'locale' );
			return is_string( $language ) && '' !== $language ? $language : 'default';
		}

		if ( defined( 'ICL_LANGUAGE_CODE' ) && is_string( ICL_LANGUAGE_CODE ) && '' !== ICL_LANGUAGE_CODE ) {
			return ICL_LANGUAGE_CODE;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$language = apply_filters( 'wpml_current_language', null );
			if ( is_string( $language ) && '' !== $language ) {
				return $language;
			}
		}

		return 'default';
	}

	/**
	 * Language choices available to the group-placement editor.
	 *
	 * @return array<int,array{code:string,label:string}>
	 */
	public static function available(): array {
		$languages = [];
		if ( function_exists( 'pll_languages_list' ) ) {
			$polylang = pll_languages_list( [ 'fields' => null ] );
			foreach ( (array) $polylang as $language ) {
				$code  = is_object( $language ) ? (string) ( $language->locale ?? '' ) : '';
				$label = is_object( $language ) ? (string) ( $language->name ?? $code ) : $code;
				if ( '' !== $code ) {
					$languages[] = [ 'code' => $code, 'label' => $label ];
				}
			}
			return $languages;
		}

		$wpml = function_exists( 'icl_get_languages' ) ? icl_get_languages( 'skip_missing=0&orderby=code' ) : null;
		if ( null === $wpml && function_exists( 'apply_filters' ) ) {
			$wpml = apply_filters( 'wpml_active_languages', [], [ 'skip_missing' => 0, 'orderby' => 'code' ] );
		}
		foreach ( (array) $wpml as $code => $language ) {
			if ( ! is_array( $language ) ) {
				continue;
			}
			$id    = (string) ( $language['language_code'] ?? $code );
			$label = (string) ( $language['native_name'] ?? $language['translated_name'] ?? $id );
			if ( '' !== $id ) {
				$languages[] = [ 'code' => $id, 'label' => $label ];
			}
		}
		return $languages;
	}
}
