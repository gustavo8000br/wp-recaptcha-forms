<?php
/**
 * Coletor único do token e do estado do cliente.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Gate;

use WpRecaptchaForms\Provider\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve BL-06: existe UM coletor, e nenhum adaptador toca em `$_POST`.
 *
 * O nome do campo de token muda entre v3 e v2, e no v2 ele é vocabulário do provedor.
 * Quem sabe disso é o provider (`token_field()`); o coletor pergunta. Trocar a origem
 * do token depois de nove integrações escritas seria retrabalho evitável — por isso
 * isto é fundação, não detalhe de adaptador.
 */
final class TokenCollector {

	/** Campo do token no v3. */
	const FIELD_TOKEN = 'wrf_token';

	/** Campo da action. */
	const FIELD_ACTION = 'wrf_action';

	/**
	 * Provider ativo.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

	/**
	 * Construtor.
	 *
	 * @param ProviderInterface $provider Provider ativo.
	 */
	public function __construct( ProviderInterface $provider ) {
		$this->provider = $provider;
	}

	/**
	 * Monta o contexto da submissão corrente.
	 *
	 * @param string $form_id Identificador do formulário.
	 * @param string $action  Action nomeada do v3.
	 * @return FormContext
	 */
	public function collect( string $form_id, string $action ): FormContext {
		return new FormContext(
			$form_id,
			$action,
			$this->token(),
			ClientState::from_request(),
			$this->remote_ip()
		);
	}

	/**
	 * Token da requisição corrente.
	 *
	 * @return string
	 */
	public function token(): string {
		$field = $this->provider->token_field();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- valor não confiável por construção; nonce em formulário público é o outro modo de falha do cache (v1 §13).
		$raw = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';

		return is_string( $raw ) ? trim( $raw ) : '';
	}

	/**
	 * IP do visitante.
	 *
	 * Sem tratamento de cabeçalho de proxy de propósito: `X-Forwarded-For` é forjável e
	 * confiar nele por default entrega ao bot o poder de escolher o próprio IP. Quem
	 * roda atrás de CDN ajusta pelo filtro.
	 *
	 * @return string|null
	 */
	public function remote_ip(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filtra o IP do visitante enviado ao provedor.
		 *
		 * @param string $ip IP detectado.
		 */
		$ip = (string) apply_filters( 'wp_recaptcha_forms_remote_ip', $ip );

		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		return $ip;
	}
}
