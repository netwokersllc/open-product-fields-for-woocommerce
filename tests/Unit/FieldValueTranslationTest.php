<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FieldValueTranslationTest extends TestCase {
	private const DOMAIN = 'open-product-fields-for-woocommerce';

	protected function tearDown(): void {
		unset( $GLOBALS['opf_test_translations'] );
	}

	#[DataProvider( 'validation_cases' )]
	public function test_validation_translates_complete_templates_and_preserves_english( array $field, ?string $value, bool $provided, string $template, string $english, string $translated_template, string $translated ): void {
		$today = new \DateTimeImmutable( '2026-06-15 15:00:00', new \DateTimeZone( 'UTC' ) );
		$field['label'] = 'Entrega <especial>';
		$this->assertSame( [ $english ], FieldValue::validate( $field, $value, $provided, $today ) );
		$GLOBALS['opf_test_translations'][ self::DOMAIN ][ $template ] = $translated_template;
		$this->assertSame( [ $translated ], FieldValue::validate( $field, $value, $provided, $today ) );
		$this->assertStringContainsString( 'msgid ' . json_encode( $template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), file_get_contents( OPF_DIR . 'languages/open-product-fields-for-woocommerce.pot' ) );
	}

	public static function validation_cases(): array {
		$label = 'Entrega <especial>';
		$cases = [
			'required' => [ [ 'type' => 'email', 'required' => true ], null, false, '"%s" is a required field.', '"' . $label . '" is a required field.', 'El campo «%s» es obligatorio.', 'El campo «' . $label . '» es obligatorio.' ],
			'toggle' => [ [ 'type' => 'toggle', 'required' => true ], '0', true, '"%s" is a required field.', '"' . $label . '" is a required field.', 'El campo «%s» es obligatorio.', 'El campo «' . $label . '» es obligatorio.' ],
			'email' => [ [ 'type' => 'email' ], 'invalid', true, '"%s" must be a valid email address.', '"' . $label . '" must be a valid email address.', '«%s» debe ser una dirección de correo válida.', '«' . $label . '» debe ser una dirección de correo válida.' ],
			'url' => [ [ 'type' => 'url' ], 'invalid', true, '"%s" must be a valid URL.', '"' . $label . '" must be a valid URL.', '«%s» debe ser una URL válida.', '«' . $label . '» debe ser una URL válida.' ],
		];
		$date_cases = [
			'valid date' => [ [], '2026-02-30', 'must be a valid date.', 'debe ser una fecha válida.' ],
			'past' => [ [ 'allow_past' => false ], '2026-06-14', 'cannot be in the past.', 'no puede ser una fecha pasada.' ],
			'future' => [ [ 'allow_future' => false ], '2026-06-16', 'cannot be in the future.', 'no puede ser una fecha futura.' ],
			'weekday' => [ [ 'disabled_weekdays' => [ 1 ] ], '2026-06-15', 'is unavailable on this weekday.', 'no está disponible este día de la semana.' ],
			'blackout' => [ [ 'disabled_dates' => [ '06-15' ] ], '2026-06-15', 'contains a disallowed date.', 'contiene una fecha no permitida.' ],
			'cutoff' => [ [ 'cutoff_time' => '14:30' ], '2026-06-15', 'is no longer available for today.', 'ya no está disponible para hoy.' ],
		];
		foreach ( $date_cases as $name => [ $options, $value, $english, $translated ] ) {
			$cases[ $name ] = [ array_merge( [ 'type' => 'date' ], $options ), $value, true, '"%s" ' . $english, '"' . $label . '" ' . $english, '«%s» ' . $translated, '«' . $label . '» ' . $translated ];
		}
		foreach ( [ 'min_date' => 'after', 'max_date' => 'before' ] as $key => $direction ) {
			$limit = 'min_date' === $key ? 'mínimo' : 'máximo';
			$cases[ $key ] = [ [ 'type' => 'date', $key => '2026-06-15' ], 'min_date' === $key ? '2026-06-14' : '2026-06-16', true, '"%1$s" must be on or ' . $direction . ' %2$s.', '"' . $label . '" must be on or ' . $direction . ' 2026-06-15.', '%2$s: límite ' . $limit . ' para «%1$s».', '2026-06-15: límite ' . $limit . ' para «' . $label . '».' ];
		}
		return $cases;
	}

	public function test_disabled_choice_translates_with_reordered_placeholders_without_escaping_values(): void {
		$field = [ 'type' => 'select', 'label' => 'Color <especial>', 'choices' => [ [ 'slug' => 'red', 'label' => 'Rojo & azul', 'disabled' => true ] ] ];
		$this->assertSame( [ '"Color <especial>" includes unavailable choice "Rojo & azul".' ], FieldValue::validate_choices( $field, 'red' ) );
		$template = '"%1$s" includes unavailable choice "%2$s".';
		$GLOBALS['opf_test_translations'][ self::DOMAIN ][ $template ] = 'La opción «%2$s» de «%1$s» no está disponible.';
		$this->assertSame( [ 'La opción «Rojo & azul» de «Color <especial>» no está disponible.' ], FieldValue::validate_choices( $field, 'red' ) );
		$this->assertStringContainsString( 'msgid ' . json_encode( $template ), file_get_contents( OPF_DIR . 'languages/open-product-fields-for-woocommerce.pot' ) );
	}
}
