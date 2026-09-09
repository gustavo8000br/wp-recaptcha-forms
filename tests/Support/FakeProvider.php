<?php
/**
 * Provider falso: devolve respostas normalizadas prontas.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Support;

use WpRecaptchaForms\Provider\ProviderInterface;
use WpRecaptchaForms\Provider\ProviderResponse;
use WpRecaptchaForms\Provider\VerificationRequest;

/**
 * Usado nos testes do Gate.
 *
 * Enquanto o `FakeTransport` exercita a tradução "resposta crua → FailureClass" dentro
 * da fronteira, este fake exercita a metade que o Gate possui: ordem de avaliação,
 * memoização e política efetiva, sem depender de parsing.
 */
final class FakeProvider implements ProviderInterface {

	/**
	 * Resposta a devolver.
	 *
	 * @var ProviderResponse
	 */
	private $response;

	/**
	 * Quantas vezes verify() foi chamado.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Campo de token declarado.
	 *
	 * @var string
	 */
	private $token_field;

	/**
	 * Construtor.
	 *
	 * @param ProviderResponse $response    Resposta fixa.
	 * @param string           $token_field Campo de token.
	 */
	public function __construct( ProviderResponse $response, string $token_field = 'wrf_token' ) {
		$this->response    = $response;
		$this->token_field = $token_field;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param VerificationRequest $request Requisição.
	 * @return ProviderResponse
	 */
	public function verify( VerificationRequest $request ): ProviderResponse {
		++$this->calls;

		return $this->response;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function version(): string {
		return 'v3';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $site_key Site key.
	 * @param string $locale   Locale.
	 * @return string
	 */
	public function script_url( string $site_key, string $locale ): string {
		return 'https://example.test/script.js';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function token_field(): string {
		return $this->token_field;
	}
}
