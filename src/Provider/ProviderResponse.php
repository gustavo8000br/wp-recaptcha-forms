<?php
/**
 * Saída normalizada da verificação.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O que atravessa a fronteira em direção ao Gate (arquitetura v1 §4.1).
 *
 * Imutável. Nada do vocabulário do provedor sai daqui exceto `debug_codes()`, que é a
 * única fuga controlada e vai apenas para log e tela de admin — nunca para lógica de
 * decisão, nunca para o usuário final.
 */
final class ProviderResponse {

	/**
	 * O provedor respondeu algo inteligível?
	 *
	 * @var bool
	 */
	private $reached;

	/**
	 * Campo `success` da resposta.
	 *
	 * @var bool
	 */
	private $success;

	/**
	 * Score (null em v2).
	 *
	 * @var float|null
	 */
	private $score;

	/**
	 * Action devolvida.
	 *
	 * @var string|null
	 */
	private $action;

	/**
	 * Hostname devolvido.
	 *
	 * @var string|null
	 */
	private $hostname;

	/**
	 * Classe de falha, ou null em caso de sucesso.
	 *
	 * @var string|null
	 */
	private $failure;

	/**
	 * Códigos crus do provedor.
	 *
	 * @var string[]
	 */
	private $debug_codes;

	/**
	 * Motivo da recusa no nosso vocabulário (RejectionReason::*).
	 *
	 * @var string|null
	 */
	private $reason;

	/**
	 * Construtor.
	 *
	 * @param bool        $reached     Se o provedor respondeu.
	 * @param bool        $success     Campo success.
	 * @param string|null $failure     Classe de falha (FailureClass::*).
	 * @param float|null  $score       Score.
	 * @param string|null $action      Action.
	 * @param string|null $hostname    Hostname.
	 * @param string[]    $debug_codes Códigos crus.
	 * @param string|null $reason      Motivo da recusa (RejectionReason::*).
	 */
	public function __construct(
		bool $reached,
		bool $success,
		?string $failure = null,
		?float $score = null,
		?string $action = null,
		?string $hostname = null,
		array $debug_codes = array(),
		?string $reason = null
	) {
		$this->reason = $reason;
		$this->reached     = $reached;
		$this->success     = $success;
		$this->failure     = $failure;
		$this->score       = $score;
		$this->action      = $action;
		$this->hostname    = $hostname;
		$this->debug_codes = array_values( array_filter( $debug_codes, 'is_string' ) );
	}

	/**
	 * O provedor respondeu algo inteligível?
	 *
	 * @return bool
	 */
	public function reached_provider(): bool {
		return $this->reached;
	}

	/**
	 * Verificação aprovada?
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Score do v3.
	 *
	 * @return float|null
	 */
	public function score(): ?float {
		return $this->score;
	}

	/**
	 * Action devolvida.
	 *
	 * @return string|null
	 */
	public function action(): ?string {
		return $this->action;
	}

	/**
	 * Hostname devolvido.
	 *
	 * @return string|null
	 */
	public function hostname(): ?string {
		return $this->hostname;
	}

	/**
	 * Classe de falha.
	 *
	 * @return string|null
	 */
	public function failure(): ?string {
		return $this->failure;
	}

	/**
	 * Códigos crus. Só log e admin.
	 *
	 * @return string[]
	 */
	public function debug_codes(): array {
		return $this->debug_codes;
	}

	/**
	 * Motivo da recusa, no vocabulário do plugin.
	 *
	 * @return string|null
	 */
	public function reason(): ?string {
		return $this->reason;
	}
}
