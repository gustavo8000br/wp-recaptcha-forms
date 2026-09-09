<?php
/**
 * Sonda de validação da secret no save.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Admin;

use WpRecaptchaForms\Provider\FailureClass;
use WpRecaptchaForms\Provider\ProviderFactory;
use WpRecaptchaForms\Provider\Transport\TransportInterface;
use WpRecaptchaForms\Provider\VerificationRequest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manda um token deliberadamente inválido e observa a resposta (arquitetura v1 §5.3-1).
 *
 * DELIMITAÇÃO HONESTA (v1.1 §4.3-1): isto prova que a SECRET é válida. Não prova que a
 * site key e a secret pertencem ao mesmo projeto — o endpoint de verificação nem recebe
 * a site key. A UI e o README dizem "secret validada", nunca "chaves validadas". O par
 * cruzado é detectado em runtime pelo advisory do Gate.
 */
final class KeyValidator {

	/** Token que jamais será válido. */
	const PROBE_TOKEN = 'wrf-probe-token-deliberadamente-invalido';

	/**
	 * Testa uma secret.
	 *
	 * @param string                  $secret    Secret a testar.
	 * @param string                  $version   Versão do provider.
	 * @param TransportInterface|null $transport Transporte; injetado nos testes.
	 * @return array{ok: bool, reason: string, codes: string[]}
	 */
	public static function probe( string $secret, string $version = 'v3', ?TransportInterface $transport = null ): array {
		if ( '' === $secret ) {
			return array(
				'ok'     => false,
				'reason' => 'missing',
				'codes'  => array(),
			);
		}

		$provider = ProviderFactory::for_version( $version, $transport );
		$response = $provider->verify( new VerificationRequest( self::PROBE_TOKEN, $secret ) );

		if ( FailureClass::MISCONFIG === $response->failure() ) {
			return array(
				'ok'     => false,
				'reason' => 'invalid_secret',
				'codes'  => $response->debug_codes(),
			);
		}

		if ( FailureClass::INFRA === $response->failure() ) {
			/*
			 * O provedor não respondeu. Não dá para afirmar nada sobre a secret — e
			 * recusar o save aqui trancaria o operador fora da própria configuração por
			 * causa de uma oscilação de rede. Aceita e avisa.
			 */
			return array(
				'ok'     => false,
				'reason' => 'unreachable',
				'codes'  => $response->debug_codes(),
			);
		}

		// Recusa do token de sonda é exatamente o esperado: a secret é boa.
		return array(
			'ok'     => true,
			'reason' => 'valid_secret',
			'codes'  => $response->debug_codes(),
		);
	}
}
