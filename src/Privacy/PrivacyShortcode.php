<?php
/**
 * Superfícies do aviso de privacidade.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode e ferramenta nativa de privacidade do WordPress.
 *
 * As duas existem porque cobrem operadores diferentes: quem usa Configurações →
 * Privacidade nunca procuraria um shortcode, e quem escreveu a política à mão num
 * construtor de páginas nunca abriria Configurações → Privacidade.
 */
final class PrivacyShortcode {

	/** Tag do shortcode. */
	const TAG = 'wp_recaptcha_forms_privacy_notice';

	/**
	 * Registra as superfícies.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_policy_content' ) );
	}

	/**
	 * Render do shortcode.
	 *
	 * @param array $atts Atributos.
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		$atts = shortcode_atts( array( 'format' => 'html' ), is_array( $atts ) ? $atts : array(), self::TAG );

		return PrivacyNotice::render( array( 'format' => 'plain' === $atts['format'] ? 'plain' : 'html' ) );
	}

	/**
	 * Publica o texto na guia de política sugerida do WordPress.
	 *
	 * @return void
	 */
	public static function register_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'WP reCAPTCHA Forms', 'wp-recaptcha-forms' ),
			wp_kses_post( PrivacyNotice::render() )
		);
	}
}
