<?php
/**
 * Contrato de transporte HTTP.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transporte injetável (arquitetura v1 §4.3).
 *
 * Existe para que os testes usem um fake injetado em vez do hook global
 * `pre_http_request`, que polui estado entre testes e é a razão de suítes de plugin
 * WordPress ficarem flaky.
 */
interface TransportInterface {

	/**
	 * Faz um POST.
	 *
	 * @param string $url  URL.
	 * @param array  $body Corpo do formulário.
	 * @param array  $args Argumentos extras de transporte.
	 * @return TransportResult
	 */
	public function post( string $url, array $body, array $args = array() ): TransportResult;
}
