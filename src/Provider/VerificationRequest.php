<?php
/**
 * Entrada imutável da verificação.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O que atravessa a fronteira em direção ao provedor (arquitetura v1 §4.1).
 */
final class VerificationRequest {

	/**
	 * Token devolvido pelo cliente.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Secret key.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * IP do visitante, ou null quando o operador desligou o envio.
	 *
	 * @var string|null
	 */
	private $remote_ip;

	/**
	 * Action esperada (v3).
	 *
	 * @var string|null
	 */
	private $expected_action;

	/**
	 * Hostname esperado.
	 *
	 * @var string|null
	 */
	private $expected_hostname;

	/**
	 * Threshold de score (v3).
	 *
	 * @var float
	 */
	private $threshold;

	/**
	 * Construtor.
	 *
	 * @param string      $token             Token do cliente.
	 * @param string      $secret            Secret key.
	 * @param string|null $remote_ip         IP do visitante.
	 * @param string|null $expected_action   Action esperada.
	 * @param string|null $expected_hostname Hostname esperado.
	 * @param float       $threshold         Threshold de score.
	 */
	public function __construct(
		string $token,
		string $secret,
		?string $remote_ip = null,
		?string $expected_action = null,
		?string $expected_hostname = null,
		float $threshold = 0.6
	) {
		$this->token             = $token;
		$this->secret            = $secret;
		$this->remote_ip         = $remote_ip;
		$this->expected_action   = $expected_action;
		$this->expected_hostname = $expected_hostname;
		$this->threshold         = $threshold;
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
	 * Secret.
	 *
	 * @return string
	 */
	public function secret(): string {
		return $this->secret;
	}

	/**
	 * IP do visitante.
	 *
	 * @return string|null
	 */
	public function remote_ip(): ?string {
		return $this->remote_ip;
	}

	/**
	 * Action esperada.
	 *
	 * @return string|null
	 */
	public function expected_action(): ?string {
		return $this->expected_action;
	}

	/**
	 * Hostname esperado.
	 *
	 * @return string|null
	 */
	public function expected_hostname(): ?string {
		return $this->expected_hostname;
	}

	/**
	 * Threshold.
	 *
	 * @return float
	 */
	public function threshold(): float {
		return $this->threshold;
	}
}
