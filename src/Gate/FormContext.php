<?php
/**
 * Contexto de uma submissão.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O que os adaptadores entregam ao Gate (arquitetura v1 §2).
 *
 * Imutável. Construído pelo `TokenCollector`, nunca à mão por adaptador — é o que
 * garante que nenhum adaptador toque em `$_POST` (BL-06).
 */
final class FormContext {

	/**
	 * Identificador do formulário.
	 *
	 * @var string
	 */
	private $form_id;

	/**
	 * Action nomeada do v3.
	 *
	 * @var string
	 */
	private $action;

	/**
	 * Token coletado.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Estado declarado pelo cliente.
	 *
	 * @var string
	 */
	private $client_state;

	/**
	 * IP do visitante, ou null quando o operador desligou o envio.
	 *
	 * @var string|null
	 */
	private $remote_ip;

	/**
	 * Construtor.
	 *
	 * @param string      $form_id      Identificador do formulário.
	 * @param string      $action       Action do v3.
	 * @param string      $token        Token.
	 * @param string      $client_state Estado do cliente já normalizado.
	 * @param string|null $remote_ip    IP do visitante.
	 */
	public function __construct(
		string $form_id,
		string $action,
		string $token = '',
		string $client_state = ClientState::NONE,
		?string $remote_ip = null
	) {
		$this->form_id      = $form_id;
		$this->action       = $action;
		$this->token        = $token;
		$this->client_state = ClientState::normalize( $client_state );
		$this->remote_ip    = $remote_ip;
	}

	/**
	 * Identificador do formulário.
	 *
	 * @return string
	 */
	public function form_id(): string {
		return $this->form_id;
	}

	/**
	 * Action.
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->action;
	}

	/**
	 * Token.
	 *
	 * @return string
	 */
	public function token(): string {
		return $this->token;
	}

	/**
	 * Estado do cliente.
	 *
	 * @return string
	 */
	public function client_state(): string {
		return $this->client_state;
	}

	/**
	 * IP do visitante.
	 *
	 * @return string|null
	 */
	public function remote_ip(): ?string {
		return $this->remote_ip;
	}
}
