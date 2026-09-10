<?php
/**
 * Texto de aviso de privacidade (FR-31/FR-32).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Privacy;

use WpRecaptchaForms\Consent\ConsentGate;
use WpRecaptchaForms\Frontend\Messages;
use WpRecaptchaForms\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Texto montado por blocos, conforme a configuração REAL da instalação.
 *
 * FR-32 é a parte que importa: um texto que descreva um comportamento que a instalação não
 * tem é pior que nenhum texto. Se o operador desligou o envio de IP, o parágrafo sobre
 * envio de IP não pode aparecer — e, principalmente, tem que aparecer a frase que impede a
 * crença errada de que desligar aquilo esconde o visitante do Google.
 *
 * Todos os textos em MÉTODOS, nunca em `const`: `__()` em escopo de arquivo dispara
 * `_load_textdomain_just_in_time` a partir do WP 6.7 (arquitetura v1 §9.1).
 */
final class PrivacyNotice {

	/** Política de privacidade do Google. */
	const URL_PRIVACY = 'https://policies.google.com/privacy';

	/** Termos de serviço do Google. */
	const URL_TERMS = 'https://policies.google.com/terms';

	/**
	 * Os parágrafos do aviso, na ordem.
	 *
	 * @return string[]
	 */
	public static function paragraphs(): array {
		$blocks = array();

		$blocks[] = __( 'Este texto é um ponto de partida para a sua política de privacidade. Ele não é aconselhamento jurídico e não torna a instalação conforme à LGPD ou ao GDPR.', 'wp-recaptcha-forms' );

		$blocks[] = __( 'Este site usa o reCAPTCHA do Google para proteger formulários contra envios automatizados. Ao interagir com um formulário protegido, o seu navegador carrega um script servido pelo Google, o que expõe ao Google o seu endereço IP, informações do navegador e cookies do domínio google.com.', 'wp-recaptcha-forms' );

		$blocks[] = sprintf(
			/* translators: 1: Google privacy policy URL, 2: Google terms URL. */
			__( 'Esse processamento está sujeito à Política de Privacidade (%1$s) e aos Termos de Serviço (%2$s) do Google.', 'wp-recaptcha-forms' ),
			self::URL_PRIVACY,
			self::URL_TERMS
		);

		$blocks[] = __( 'O script do reCAPTCHA é carregado apenas nas páginas que contêm um formulário protegido — em qualquer outra página do site, o seu navegador não fala com o Google por causa deste recurso.', 'wp-recaptcha-forms' );

		if ( Options::send_remote_ip() ) {
			$blocks[] = __( 'Além disso, o endereço IP do visitante é enviado ao Google pelo servidor deste site, junto com a verificação de cada envio de formulário.', 'wp-recaptcha-forms' );
		} else {
			$blocks[] = __( 'O servidor deste site não envia o endereço IP do visitante ao Google junto com a verificação. Desligar esse envio não impede que o Google receba o endereço IP, porque o script do reCAPTCHA é carregado diretamente do domínio do Google pelo navegador do visitante.', 'wp-recaptcha-forms' );
		}

		if ( ConsentGate::MODE_OFF !== ConsentGate::mode() ) {
			$blocks[] = __( 'O script do reCAPTCHA só é carregado depois que o visitante concede consentimento para cookies e scripts de terceiros.', 'wp-recaptcha-forms' );
		}

		/*
		 * Telemetria (telemetry-design §4.3). Bloco condicional, e a condição é a
		 * resolução única de `Options::telemetry_enabled()` — que já considera
		 * WRF_DISABLE e WRF_TELEMETRY_DISABLE. Uma instalação com a opção ligada mas a
		 * constante definida não envia nada, e portanto não pode dizer que envia.
		 *
		 * Desligada: SILÊNCIO TOTAL, nenhum bloco. Diferente do caso do `remoteip`
		 * acima, em que a frase de não-falsa-conformidade existe porque desligar o envio
		 * não esconde o visitante do Google. Aqui desligar significa mesmo que nada sai,
		 * então não há crença errada a corrigir — e descrever o que não acontece só
		 * empurraria ruído para a política de privacidade do operador.
		 */
		if ( Options::telemetry_enabled() ) {
			$blocks[] = Messages::telemetry_notice();
		}

		/**
		 * Filtra os parágrafos do aviso de privacidade.
		 *
		 * @param string[] $blocks Parágrafos em texto plano.
		 */
		return (array) apply_filters( 'wp_recaptcha_forms_privacy_notice_paragraphs', $blocks );
	}

	/**
	 * O aviso montado.
	 *
	 * @param array $args `format` => 'html'|'plain'.
	 * @return string
	 */
	public static function render( array $args = array() ): string {
		$format     = isset( $args['format'] ) ? (string) $args['format'] : 'html';
		$paragraphs = self::paragraphs();

		if ( 'plain' === $format ) {
			return implode( "\n\n", array_map( 'wp_strip_all_tags', $paragraphs ) );
		}

		$html = '';

		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>' . "\n";
		}

		return $html;
	}
}
