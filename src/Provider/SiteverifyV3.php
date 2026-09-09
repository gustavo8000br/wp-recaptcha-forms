<?php
/**
 * reCAPTCHA v3 (invisível, por score).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verificação por score e por action nomeada.
 */
final class SiteverifyV3 extends AbstractSiteverifyProvider {

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
	 * @return string
	 */
	public function token_field(): string {
		return 'wrf_token';
	}

	/**
	 * Score da resposta.
	 *
	 * @param array $payload Resposta.
	 * @return float|null
	 */
	protected function extract_score( array $payload ): ?float {
		return isset( $payload['score'] ) && is_numeric( $payload['score'] ) ? (float) $payload['score'] : null;
	}

	/**
	 * Valida score, action e hostname.
	 *
	 * @param array               $payload Resposta.
	 * @param VerificationRequest $request Requisição.
	 * @return string|null
	 */
	protected function validate_success( array $payload, VerificationRequest $request ): ?string {
		if ( ! $this->hostname_matches( $payload, $request ) ) {
			return RejectionReason::HOSTNAME_MISMATCH;
		}

		$expected_action = $request->expected_action();

		if ( null !== $expected_action && '' !== $expected_action ) {
			$action = isset( $payload['action'] ) ? (string) $payload['action'] : '';

			/*
			 * Action divergente com score alto é o sinal de token reaproveitado de outro
			 * formulário do mesmo site — o motivo de a action ser nomeada por formulário.
			 */
			if ( $action !== $expected_action ) {
				return RejectionReason::ACTION_MISMATCH;
			}
		}

		$score = $this->extract_score( $payload );

		if ( null === $score ) {
			// v3 sem score é resposta malformada; tratar como recusa, não como aprovação.
			return RejectionReason::INVALID_TOKEN;
		}

		// Score exatamente no limite passa: o threshold é o piso do aceitável.
		if ( $score < $request->threshold() ) {
			return RejectionReason::LOW_SCORE;
		}

		return null;
	}

	/**
	 * Argumentos do script.
	 *
	 * @param string $site_key Site key.
	 * @return array
	 */
	protected function script_args( string $site_key ): array {
		return array( 'render' => $site_key );
	}
}
