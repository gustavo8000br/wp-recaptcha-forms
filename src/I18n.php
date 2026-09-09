<?php
/**
 * Fallback de variantes de idioma (Story 1.21 AC 2).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms;

/**
 * Mapeia variantes regionais para o catálogo mantido.
 *
 * O espanhol é o caso que motiva a classe. O WordPress distribui `es_ES`, `es_MX`,
 * `es_AR`, `es_CO`, `es_CL`, `es_PE`, `es_VE`, `es_GT`, `es_CR`, `es_DO`, `es_EC`,
 * `es_HN`, `es_PR`, `es_UY` — quatorze locales para um idioma cujas diferenças, no
 * vocabulário desta tela, são nenhuma. Sem o mapa, um visitante em `es_MX` vê a interface
 * em português, porque `wp-recaptcha-forms-es_MX.mo` não existe.
 *
 * A alternativa seria copiar o mesmo catálogo quatorze vezes no zip. Um filtro é mais
 * barato de manter e mais honesto: existe **um** catálogo em espanhol, e ele é declarado
 * como tal.
 */
final class I18n {

	/** Text domain do plugin. */
	const DOMAIN = 'wp-recaptcha-forms';

	/**
	 * Registra os filtros.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'load_textdomain_mofile', array( __CLASS__, 'filter_mofile' ), 10, 2 );
		add_filter( 'load_translation_file', array( __CLASS__, 'filter_translation_file' ), 10, 3 );
	}

	/**
	 * Mapa de fallback: variante => catálogo mantido.
	 *
	 * Filtrável para que um site com tradução própria de `es_AR` possa desviar a sua
	 * variante sem editar o plugin.
	 *
	 * @return array<string,string>
	 */
	public static function fallbacks(): array {
		$map = array(
			'es_AR' => 'es_ES',
			'es_CL' => 'es_ES',
			'es_CO' => 'es_ES',
			'es_CR' => 'es_ES',
			'es_DO' => 'es_ES',
			'es_EC' => 'es_ES',
			'es_GT' => 'es_ES',
			'es_HN' => 'es_ES',
			'es_MX' => 'es_ES',
			'es_PE' => 'es_ES',
			'es_PR' => 'es_ES',
			'es_UY' => 'es_ES',
			'es_VE' => 'es_ES',
			'en_AU' => 'en_US',
			'en_CA' => 'en_US',
			'en_GB' => 'en_US',
			'en_NZ' => 'en_US',
			'en_ZA' => 'en_US',
			'pt_AO' => 'pt_BR',
			'pt_PT' => 'pt_BR',
		);

		/**
		 * Filtra o mapa de fallback de variantes.
		 *
		 * @param array<string,string> $map Variante => catálogo mantido.
		 */
		return (array) apply_filters( 'wp_recaptcha_forms_locale_fallbacks', $map );
	}

	/**
	 * Desvia o caminho do `.mo` (todas as versões do WordPress).
	 *
	 * @param string $mofile Caminho proposto.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public static function filter_mofile( $mofile, $domain ) {
		if ( self::DOMAIN !== $domain ) {
			return $mofile;
		}

		return self::redirect( (string) $mofile, '.mo' );
	}

	/**
	 * Desvia o caminho do `.l10n.php` (WP 6.5+).
	 *
	 * **Condição (a)-1 da revisão do @qa, e o motivo de este método existir.** A partir do
	 * WP 6.5 o carregamento passa por `load_translation_file`, e um filtro que só cubra
	 * `load_textdomain_mofile` deixa de valer sem aviso: o site atualiza o WordPress e o
	 * espanhol some. Cobrir só o `.mo` seria uma lacuna silenciosa com prazo de validade.
	 *
	 * @param string $file   Caminho proposto.
	 * @param string $domain Text domain.
	 * @param string $locale Locale corrente.
	 * @return string
	 */
	public static function filter_translation_file( $file, $domain, $locale ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- assinatura fixada pelo filtro do WordPress; o locale já está embutido no nome do arquivo.
		if ( self::DOMAIN !== $domain ) {
			return $file;
		}

		$extension = '.l10n.php' === substr( (string) $file, -9 ) ? '.l10n.php' : '.mo';

		return self::redirect( (string) $file, $extension );
	}

	/**
	 * Troca a variante pelo catálogo mantido, se houver arquivo.
	 *
	 * Só desvia quando o arquivo de destino existe: um fallback que aponta para um arquivo
	 * ausente troca "interface em português" por "interface em português e um warning", o
	 * que não melhora nada.
	 *
	 * @param string $path      Caminho proposto.
	 * @param string $extension Extensão a considerar.
	 * @return string
	 */
	private static function redirect( string $path, string $extension ): string {
		$base = basename( $path, $extension );

		foreach ( self::fallbacks() as $variant => $target ) {
			if ( self::DOMAIN . '-' . $variant !== $base ) {
				continue;
			}

			$candidate = dirname( $path ) . '/' . self::DOMAIN . '-' . $target . $extension;

			return file_exists( $candidate ) ? $candidate : $path;
		}

		return $path;
	}
}
