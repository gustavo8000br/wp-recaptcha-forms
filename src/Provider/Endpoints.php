<?php
/**
 * URLs do provedor de verificação.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O ÚNICO arquivo do repositório com as URLs do Google (princípio A2, arquitetura v1 §4.4).
 *
 * `bin/check-boundary.sh` falha o CI se a string aparecer em qualquer outro lugar.
 */
final class Endpoints {

	/** Endpoint de verificação server-side. */
	const SITEVERIFY = 'https://www.google.com/recaptcha/api/siteverify';

	/** Script do reCAPTCHA (mesmo arquivo serve v3 e v2). */
	const SCRIPT = 'https://www.google.com/recaptcha/api.js';

	/** Identificadores usados no filtro. */
	const WHICH_SITEVERIFY = 'siteverify';
	const WHICH_SCRIPT     = 'script';

	/**
	 * URL do siteverify, filtrável.
	 *
	 * @return string
	 */
	public static function siteverify(): string {
		return self::filter( self::SITEVERIFY, self::WHICH_SITEVERIFY );
	}

	/**
	 * URL do script, filtrável.
	 *
	 * @return string
	 */
	public static function script(): string {
		return self::filter( self::SCRIPT, self::WHICH_SCRIPT );
	}

	/**
	 * Aplica o filtro documentado de endpoint.
	 *
	 * Resolve de graça um caso real de suporte — redes que bloqueiam google.com usam
	 * `recaptcha.net` — e é o mecanismo de escape se o host mudar.
	 *
	 * @param string $url   URL default.
	 * @param string $which Identificador do endpoint.
	 * @return string
	 */
	private static function filter( string $url, string $which ): string {
		/**
		 * Filtra a URL de um endpoint do provedor.
		 *
		 * @param string $url   URL default.
		 * @param string $which 'siteverify' ou 'script'.
		 */
		$filtered = apply_filters( 'wp_recaptcha_forms_endpoint', $url, $which );

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $url;
	}
}
