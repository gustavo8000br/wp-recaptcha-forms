<?php
/**
 * reCAPTCHA v2 checkbox.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verificação sem score: o desafio já foi resolvido pelo visitante.
 */
final class SiteverifyV2 extends AbstractSiteverifyProvider {

	/** Callback global chamado quando o script termina de carregar. */
	const ONLOAD_CALLBACK = 'wrfV2Init';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function version(): string {
		return 'v2';
	}

	/**
	 * Nome do campo gerado pelo script do provedor.
	 *
	 * É vocabulário DELE, e é por isso que este método existe: assim o coletor único
	 * não precisa conhecê-lo (BL-06 + princípio A2).
	 *
	 * @return string
	 */
	public function token_field(): string {
		return 'g-recaptcha-response';
	}

	/**
	 * O v2 não tem score.
	 *
	 * @param array $payload Resposta.
	 * @return float|null
	 */
	protected function extract_score( array $payload ): ?float {
		return null;
	}

	/**
	 * Validação: só `success` (já garantido pelo chamador) e hostname.
	 *
	 * @param array               $payload Resposta.
	 * @param VerificationRequest $request Requisição.
	 * @return string|null
	 */
	protected function validate_success( array $payload, VerificationRequest $request ): ?string {
		if ( ! $this->hostname_matches( $payload, $request ) ) {
			return RejectionReason::HOSTNAME_MISMATCH;
		}

		return null;
	}

	/**
	 * Argumentos do script.
	 *
	 * `render=explicit` para que o widget seja criado pelo nosso callback: é o que
	 * permite registrar o `expired-callback` que limpa o campo (arquitetura v1 §6.5).
	 *
	 * @param string $site_key Site key.
	 * @return array
	 */
	protected function script_args( string $site_key ): array {
		return array(
			'onload' => self::ONLOAD_CALLBACK,
			'render' => 'explicit',
		);
	}
}
