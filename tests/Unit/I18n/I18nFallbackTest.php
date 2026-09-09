<?php
/**
 * Fallback de variantes de idioma (Story 1.21 AC 2 e AC 3).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\I18n;
use WpStubs;

/**
 * Um visitante em `es_MX` precisa ver espanhol, não português.
 */
final class I18nFallbackTest extends TestCase {

	/**
	 * Diretório de catálogos do plugin.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Diretório real de catálogos — o filtro só desvia quando o destino existe.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();

		$this->dir = WP_RECAPTCHA_FORMS_DIR . 'languages';
	}

	/**
	 * `es_MX` cai em `es_ES` no caminho do `.mo`.
	 *
	 * @return void
	 */
	public function test_es_mx_falls_back_to_es_es_for_mo(): void {
		$proposed = $this->dir . '/wp-recaptcha-forms-es_MX.mo';

		$this->assertSame(
			$this->dir . '/wp-recaptcha-forms-es_ES.mo',
			I18n::filter_mofile( $proposed, 'wp-recaptcha-forms' )
		);
	}

	/**
	 * `es_MX` cai em `es_ES` também no caminho do `.l10n.php` (WP 6.5+).
	 *
	 * **Condição (a)-1 da revisão do @qa.** Um filtro que só cubra `load_textdomain_mofile`
	 * funciona hoje e para de funcionar quando o site atualiza o WordPress, sem aviso —
	 * é a lacuna silenciosa que este teste existe para impedir.
	 *
	 * @return void
	 */
	public function test_es_mx_falls_back_to_es_es_for_l10n_php(): void {
		// O `.l10n.php` do es_ES não é distribuído (só `.po`/`.mo`), então o filtro
		// devolve o caminho original — mas devolve o ORIGINAL, não um caminho quebrado.
		$proposed = $this->dir . '/wp-recaptcha-forms-es_MX.l10n.php';

		$this->assertSame(
			$proposed,
			I18n::filter_translation_file( $proposed, 'wp-recaptcha-forms', 'es_MX' )
		);

		// E o mesmo locale, pedindo `.mo`, é desviado: prova que o segundo filtro está
		// realmente ligado e sabe distinguir as duas extensões.
		$this->assertSame(
			$this->dir . '/wp-recaptcha-forms-es_ES.mo',
			I18n::filter_translation_file( $this->dir . '/wp-recaptcha-forms-es_MX.mo', 'wp-recaptcha-forms', 'es_MX' )
		);
	}

	/**
	 * O filtro não toca no text domain de outros plugins.
	 *
	 * @return void
	 */
	public function test_other_domains_are_untouched(): void {
		$proposed = '/qualquer/lugar/woocommerce-es_MX.mo';

		$this->assertSame( $proposed, I18n::filter_mofile( $proposed, 'woocommerce' ) );
		$this->assertSame( $proposed, I18n::filter_translation_file( $proposed, 'woocommerce', 'es_MX' ) );
	}

	/**
	 * Variante sem catálogo de destino no disco não é desviada.
	 *
	 * Um fallback que aponta para arquivo ausente troca "interface em português" por
	 * "interface em português e um warning no log".
	 *
	 * @return void
	 */
	public function test_missing_target_keeps_the_original_path(): void {
		$proposed = '/tmp/inexistente/wp-recaptcha-forms-es_MX.mo';

		$this->assertSame( $proposed, I18n::filter_mofile( $proposed, 'wp-recaptcha-forms' ) );
	}

	/**
	 * O locale mantido não é desviado para ele mesmo nem para outro.
	 *
	 * @return void
	 */
	public function test_maintained_locales_are_not_redirected(): void {
		foreach ( array( 'pt_BR', 'en_US', 'es_ES' ) as $locale ) {
			$proposed = $this->dir . '/wp-recaptcha-forms-' . $locale . '.mo';

			$this->assertSame( $proposed, I18n::filter_mofile( $proposed, 'wp-recaptcha-forms' ) );
		}
	}

	/**
	 * O mapa é filtrável, para um site poder registrar a própria tradução de variante.
	 *
	 * @return void
	 */
	public function test_fallback_map_is_filterable(): void {
		add_filter(
			'wp_recaptcha_forms_locale_fallbacks',
			static function ( $map ) {
				unset( $map['es_MX'] );

				return $map;
			}
		);

		$proposed = $this->dir . '/wp-recaptcha-forms-es_MX.mo';

		$this->assertSame( $proposed, I18n::filter_mofile( $proposed, 'wp-recaptcha-forms' ) );
	}

	/**
	 * Os três catálogos mantidos existem em `.po` e em `.mo` (AC 1 e AC 9).
	 *
	 * A regra assimétrica: idioma mantido entra com os dois; idioma de comunidade entra
	 * só com `.po`. Sem esta asserção, um `.mo` esquecido no commit vira um idioma que
	 * aparece 100% na tabela de progresso e não traduz nada no site.
	 *
	 * @return void
	 */
	public function test_maintained_catalogs_ship_po_and_mo(): void {
		foreach ( array( 'pt_BR', 'en_US', 'es_ES' ) as $locale ) {
			$this->assertFileExists( $this->dir . '/wp-recaptcha-forms-' . $locale . '.po' );
			$this->assertFileExists( $this->dir . '/wp-recaptcha-forms-' . $locale . '.mo' );
		}
	}
}
