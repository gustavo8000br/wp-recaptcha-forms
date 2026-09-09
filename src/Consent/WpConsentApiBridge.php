<?php
/**
 * Adesão à WP Consent API.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A única adesão nominal do plugin, e ela não é a um CMP: a WP Consent API é o contrato
 * padrão do ecossistema, implementado por vários CMPs ao mesmo tempo.
 */
final class WpConsentApiBridge {

	/**
	 * Registra o plugin como compatível.
	 *
	 * Sem isto, os CMPs que respeitam a API tratam o plugin como "desconhecido" e podem
	 * bloqueá-lo por fora — que é exatamente o cenário sem controle que o gating existe
	 * para eliminar.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! defined( 'WP_RECAPTCHA_FORMS_FILE' ) ) {
			return;
		}

		add_filter( 'wp_consent_api_registered_' . plugin_basename( WP_RECAPTCHA_FORMS_FILE ), '__return_true' );
	}

	/**
	 * A WP Consent API está disponível?
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_has_consent' );
	}

	/**
	 * Decisão da WP Consent API, ou null quando ela não existe.
	 *
	 * @return bool|null
	 */
	public static function consent(): ?bool {
		if ( ! self::is_available() ) {
			return null;
		}

		/**
		 * Categoria de consentimento do reCAPTCHA.
		 *
		 * Classificar o reCAPTCHA como `marketing`, `functional` ou `statistics` é decisão
		 * jurídica do operador, não nossa.
		 *
		 * @param string $category Categoria.
		 */
		$category = (string) apply_filters( 'wp_recaptcha_forms_consent_category', 'marketing' );

		return (bool) wp_has_consent( $category );
	}
}
