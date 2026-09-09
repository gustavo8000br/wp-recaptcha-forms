<?php
/**
 * Base comum dos providers de verificação server-side.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider;

use WpRecaptchaForms\Provider\Transport\TransportInterface;
use WpRecaptchaForms\Provider\Transport\TransportResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transporte e parsing comuns a v3 e v2 (arquitetura v1 §4.2).
 *
 * Este é o único lugar do sistema que traduz o vocabulário de erro do provedor em
 * `FailureClass`. Fora daqui, nada conhece esses códigos.
 */
abstract class AbstractSiteverifyProvider implements ProviderInterface {

	/**
	 * Transporte injetado.
	 *
	 * @var TransportInterface
	 */
	protected $transport;

	/**
	 * Construtor.
	 *
	 * @param TransportInterface $transport Transporte.
	 */
	public function __construct( TransportInterface $transport ) {
		$this->transport = $transport;
	}

	/**
	 * Valida os campos específicos da versão numa resposta de sucesso.
	 *
	 * @param array               $payload Resposta decodificada.
	 * @param VerificationRequest $request Requisição.
	 * @return string|null Motivo da recusa (RejectionReason::*) ou null se aprovada.
	 */
	abstract protected function validate_success( array $payload, VerificationRequest $request ): ?string;

	/**
	 * Score extraído da resposta, ou null quando a versão não tem score.
	 *
	 * @param array $payload Resposta decodificada.
	 * @return float|null
	 */
	abstract protected function extract_score( array $payload ): ?float;

	/**
	 * {@inheritDoc}
	 *
	 * @param VerificationRequest $request Requisição.
	 * @return ProviderResponse
	 */
	public function verify( VerificationRequest $request ): ProviderResponse {
		if ( '' === $request->token() ) {
			// Defesa em profundidade: o Gate já barra antes de gastar rede (v1.1 §7.2, passo 4).
			return new ProviderResponse( false, false, FailureClass::REJECTED, null, null, null, array(), RejectionReason::MISSING_TOKEN );
		}

		$body = array(
			'secret'   => $request->secret(),
			'response' => $request->token(),
		);

		if ( null !== $request->remote_ip() && '' !== $request->remote_ip() ) {
			$body['remoteip'] = $request->remote_ip();
		}

		$result = $this->transport->post( Endpoints::siteverify(), $body );

		return $this->interpret( $result, $request );
	}

	/**
	 * Traduz o resultado de transporte em resposta normalizada.
	 *
	 * @param TransportResult     $result  Resultado do transporte.
	 * @param VerificationRequest $request Requisição.
	 * @return ProviderResponse
	 */
	protected function interpret( TransportResult $result, VerificationRequest $request ): ProviderResponse {
		if ( $result->is_error() ) {
			return $this->infra( array( 'transport:' . $result->error_code() ) );
		}

		$status = $result->status();

		if ( $status >= 500 || 429 === $status ) {
			return $this->infra( array( 'http:' . $status ) );
		}

		$payload = $result->json();

		if ( null === $payload ) {
			// Corpo vazio ou não-JSON: portal cativo, WAF, proxy. Infra, não recusa.
			return $this->infra( array( 'http:' . $status, 'body:unparseable' ) );
		}

		$codes    = $this->extract_codes( $payload );
		$hostname = isset( $payload['hostname'] ) && is_string( $payload['hostname'] ) ? $payload['hostname'] : null;
		$action   = isset( $payload['action'] ) && is_string( $payload['action'] ) ? $payload['action'] : null;
		$score    = $this->extract_score( $payload );

		if ( ! empty( $payload['success'] ) ) {
			$reason = $this->validate_success( $payload, $request );

			if ( null === $reason ) {
				return new ProviderResponse( true, true, null, $score, $action, $hostname, $codes );
			}

			return new ProviderResponse( true, false, FailureClass::REJECTED, $score, $action, $hostname, $codes, $reason );
		}

		$failure = self::classify( $codes );
		$reason  = self::reason_for( $codes );

		return new ProviderResponse( true, false, $failure, $score, $action, $hostname, $codes, $reason );
	}

	/**
	 * Tabela normativa de classificação (arquitetura v1.1 §4.2). Ponto único.
	 *
	 * @param string[] $codes Códigos devolvidos pelo provedor.
	 * @return string FailureClass::*
	 */
	public static function classify( array $codes ): string {
		$misconfig = array(
			'invalid-input-secret',
			'invalid-keys',
			'missing-input-secret',
			'bad-request',
		);

		$rejected = array(
			'missing-input-response',
			'invalid-input-response',
			'timeout-or-duplicate',
		);

		foreach ( $codes as $code ) {
			if ( in_array( $code, $misconfig, true ) ) {
				return FailureClass::MISCONFIG;
			}
		}

		foreach ( $codes as $code ) {
			if ( in_array( $code, $rejected, true ) ) {
				return FailureClass::REJECTED;
			}
		}

		/*
		 * Código desconhecido (ou nenhum código) → INFRA, e é fail-safe deliberado: a
		 * política de INFRA é configurável e tem default fail-open, então um código novo
		 * do provedor não derruba o site nem cria notice crítico falso. Um `switch` sem
		 * `default` decidiria isso por acidente; aqui foi decidido de propósito.
		 */
		return FailureClass::INFRA;
	}

	/**
	 * Motivo da recusa, no vocabulário do plugin.
	 *
	 * @param string[] $codes Códigos.
	 * @return string|null
	 */
	protected static function reason_for( array $codes ): ?string {
		if ( in_array( 'timeout-or-duplicate', $codes, true ) ) {
			return RejectionReason::DUPLICATE_TOKEN;
		}

		if ( in_array( 'missing-input-response', $codes, true ) ) {
			return RejectionReason::MISSING_TOKEN;
		}

		if ( in_array( 'invalid-input-response', $codes, true ) ) {
			return RejectionReason::INVALID_TOKEN;
		}

		return null;
	}

	/**
	 * Extrai os códigos de erro da resposta.
	 *
	 * @param array $payload Resposta decodificada.
	 * @return string[]
	 */
	protected function extract_codes( array $payload ): array {
		$raw = $payload['error-codes'] ?? array();

		if ( is_string( $raw ) ) {
			$raw = array( $raw );
		}

		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_string' ) ) : array();
	}

	/**
	 * Resposta de INFRA.
	 *
	 * @param string[] $debug Códigos de diagnóstico.
	 * @return ProviderResponse
	 */
	protected function infra( array $debug ): ProviderResponse {
		return new ProviderResponse( false, false, FailureClass::INFRA, null, null, null, $debug );
	}

	/**
	 * Verifica o hostname quando o operador espera um específico.
	 *
	 * @param array               $payload Resposta.
	 * @param VerificationRequest $request Requisição.
	 * @return bool
	 */
	protected function hostname_matches( array $payload, VerificationRequest $request ): bool {
		$expected = $request->expected_hostname();

		if ( null === $expected || '' === $expected ) {
			return true;
		}

		return isset( $payload['hostname'] ) && $payload['hostname'] === $expected;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $site_key Site key.
	 * @param string $locale   Locale.
	 * @return string
	 */
	public function script_url( string $site_key, string $locale ): string {
		$url = Endpoints::script();

		$args = $this->script_args( $site_key );

		if ( '' !== $locale ) {
			$args['hl'] = str_replace( '_', '-', $locale );
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
	}

	/**
	 * Argumentos de query do script, por versão.
	 *
	 * @param string $site_key Site key.
	 * @return array
	 */
	abstract protected function script_args( string $site_key ): array;
}
