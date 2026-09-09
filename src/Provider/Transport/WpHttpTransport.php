<?php
/**
 * Transporte real, sobre a HTTP API do WordPress.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Embrulha `wp_remote_post()` (arquitetura v1 §4.3).
 */
final class WpHttpTransport implements TransportInterface {

	/** Timeout default, em segundos. */
	const DEFAULT_TIMEOUT = 5;

	/**
	 * Faz o POST.
	 *
	 * O timeout de 5s é trade-off deliberado de UX: o siteverify responde tipicamente em
	 * menos de 300ms, e 5s é margem de rede ruim sem transformar a indisponibilidade do
	 * provedor em 30s de spinner no checkout.
	 *
	 * @param string $url  URL.
	 * @param array  $body Corpo.
	 * @param array  $args Argumentos extras.
	 * @return TransportResult
	 */
	public function post( string $url, array $body, array $args = array() ): TransportResult {
		/**
		 * Filtra o timeout da requisição de verificação.
		 *
		 * @param int $timeout Segundos.
		 */
		$timeout = (int) apply_filters( 'wp_recaptcha_forms_request_timeout', self::DEFAULT_TIMEOUT );

		if ( $timeout < 1 ) {
			$timeout = self::DEFAULT_TIMEOUT;
		}

		$defaults = array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'httpversion' => '1.1',
			'user-agent'  => 'wp-recaptcha-forms/' . WP_RECAPTCHA_FORMS_VERSION . '; ' . home_url( '/' ),
			'body'        => $body,
		);

		$response = wp_remote_post( $url, array_merge( $defaults, $args ) );

		if ( is_wp_error( $response ) ) {
			return TransportResult::error( (string) $response->get_error_code() );
		}

		return new TransportResult(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response )
		);
	}
}
