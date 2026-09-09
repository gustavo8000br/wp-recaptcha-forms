<?php
/**
 * Impressão dos campos no formulário.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Frontend;

use WpRecaptchaForms\Gate\ClientState;
use WpRecaptchaForms\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imprime hidden fields VAZIOS, e nada mais (arquitetura v1 §6.2, princípio A3).
 *
 * O bug clássico da categoria não é "o token expira": é o token estar no HTML. Um token
 * de 2 minutos dentro de uma página guardada por 10 horas é servido expirado para todo
 * visitante, e o formulário reprova gente legítima em silêncio.
 *
 * Como aqui nada tem validade e nada é específico de usuário, este HTML é idêntico para
 * todo visitante e eternamente cacheável. Cache deixa de ser um problema em vez de virar
 * uma exclusão a documentar.
 */
final class FieldRenderer {

	/**
	 * Imprime os campos de um formulário protegido.
	 *
	 * @param string $form_id Identificador do formulário.
	 * @param string $action  Action nomeada do v3.
	 * @return void
	 */
	public static function render( string $form_id, string $action ): void {
		echo self::markup( $form_id, $action ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup() já escapa cada atributo.
	}

	/**
	 * Markup dos campos.
	 *
	 * @param string $form_id Identificador do formulário.
	 * @param string $action  Action nomeada do v3.
	 * @return string
	 */
	public static function markup( string $form_id, string $action ): string {
		AssetManager::instance()->request();

		$html = '<div class="wrf-field" data-wrf-form="' . esc_attr( $form_id ) . '">';

		if ( 'v2' === Options::version() ) {
			// O widget do v2 é renderizado pelo script a partir deste div. A site key é
			// pública e igual para todo visitante, logo o markup continua cacheável.
			$html .= '<div class="g-recaptcha wrf-v2" data-sitekey="' . esc_attr( Options::site_key() ) . '"></div>';
		}

		$html .= '<input type="hidden" name="wrf_token" value="">';
		$html .= '<input type="hidden" name="wrf_action" value="' . esc_attr( $action ) . '">';
		$html .= '<input type="hidden" name="' . esc_attr( ClientState::FIELD ) . '" value="">';
		$html .= '</div>';

		return $html;
	}
}
