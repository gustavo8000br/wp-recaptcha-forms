<?php
/**
 * Resolução do consentimento para carregar o script do provedor.
 *
 * @package WpRecaptchaForms;
 */

namespace WpRecaptchaForms\Consent;

use WpRecaptchaForms\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O `api.js` vira opt-in atrás de um hook genérico (arquitetura v1.1 §2).
 *
 * Restrição de desenho que governa a classe inteira: **o plugin não integra com CMP
 * nenhum por nome.** Integração nominal significa manter N adaptadores para N plugins que
 * mudam de API sem avisar — dívida garantida num projeto de mantenedor solo. O plugin
 * publica um contrato; quem conhece o CMP conecta os dois fios com três linhas.
 *
 * A única exceção é a WP Consent API, porque ela É o contrato padrão do ecossistema, não
 * um plugin específico, e a adesão custa quatro linhas.
 */
final class ConsentGate {

	/** Carrega sempre. Comportamento anterior ao gating. */
	const MODE_OFF = 'off';

	/** Consulta a WP Consent API se existir; senão, comporta-se como `off`. */
	const MODE_AUTO = 'auto';

	/** Nunca carrega sem sinal explícito. */
	const MODE_REQUIRED = 'required';

	/**
	 * Decisão forçada por `wp_recaptcha_forms_set_consent()`.
	 *
	 * @var bool|null
	 */
	private static $forced = null;

	/**
	 * Modos aceitos.
	 *
	 * @return string[]
	 */
	public static function modes(): array {
		return array( self::MODE_OFF, self::MODE_AUTO, self::MODE_REQUIRED );
	}

	/**
	 * Modo configurado.
	 *
	 * @return string
	 */
	public static function mode(): string {
		$mode = (string) Options::get( 'consent_mode', self::MODE_OFF );

		return in_array( $mode, self::modes(), true ) ? $mode : self::MODE_OFF;
	}

	/**
	 * Força a decisão no lado servidor (helper do integrador).
	 *
	 * @param bool|null $granted Decisão, ou null para voltar à resolução normal.
	 * @return void
	 */
	public static function set( ?bool $granted ): void {
		self::$forced = $granted;
	}

	/**
	 * O consentimento foi concedido?
	 *
	 * Resolução em cinco passos, na ordem da arquitetura v1.1 §2.3. A primeira que
	 * devolver um booleano ganha.
	 *
	 * @param string $form_id Formulário em questão.
	 * @return bool
	 */
	public static function granted( string $form_id = '' ): bool {
		$mode = self::mode();

		// 1. Curto-circuito: um site sem CMP não tem consentimento a gerenciar. Se o
		// default fosse "sem consentimento não carrega", todo site sem CMP instalaria o
		// plugin e ele nunca funcionaria — opt-in aqui é do GATING, não do plugin.
		if ( self::MODE_OFF === $mode ) {
			return true;
		}

		if ( null !== self::$forced ) {
			return self::$forced;
		}

		/**
		 * Decisão de consentimento. Este é o hook genérico: qualquer CMP conecta aqui.
		 *
		 * @param bool|null $granted null = indeterminado (sem provedor); bool = decisão.
		 * @param string    $form_id Formulário. Um operador pode legitimamente exigir
		 *                           consentimento na loja e não no wp-login.php.
		 */
		$granted = apply_filters( 'wp_recaptcha_forms_consent_granted', null, $form_id );

		// 2. Filtro respondeu.
		if ( is_bool( $granted ) ) {
			return $granted;
		}

		// 3. WP Consent API, se presente.
		$api = WpConsentApiBridge::consent();

		if ( is_bool( $api ) ) {
			return $api;
		}

		// 4 e 5. Ninguém respondeu: `auto` degrada para `off`, `required` nega.
		return self::MODE_AUTO === $mode;
	}

	/**
	 * Descrição do estado resolvido, para a tela de configurações.
	 *
	 * Existe para o operador não descobrir em produção que ligou `required` sem nada para
	 * conceder.
	 *
	 * @return string
	 */
	public static function status_text(): string {
		$mode = self::mode();

		if ( self::MODE_OFF === $mode ) {
			return __( 'Consentimento: não exigido — o script do Google carrega em toda página com formulário protegido.', 'wp-recaptcha-forms' );
		}

		$provider = WpConsentApiBridge::is_available()
			? __( 'WP Consent API detectada', 'wp-recaptcha-forms' )
			: __( 'nenhum provedor detectado', 'wp-recaptcha-forms' );

		if ( self::MODE_AUTO === $mode ) {
			return sprintf(
				/* translators: %s: detected consent provider. */
				__( 'Consentimento: automático (%s). Sem provedor, o comportamento é o mesmo de "não exigir".', 'wp-recaptcha-forms' ),
				$provider
			);
		}

		return sprintf(
			/* translators: %s: detected consent provider. */
			__( 'Consentimento: exigido (%s). Sem sinal de consentimento o script do Google não é carregado e os envios chegam como "navegador não alcançou o Google".', 'wp-recaptcha-forms' ),
			$provider
		);
	}
}
