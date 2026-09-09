<?php
/**
 * Veredito de uma avaliação.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saída imutável de `Gate::assess()`.
 *
 * Os adaptadores só traduzem isto para o idioma de erro do host (WP_Error, wc_add_notice,
 * RouteException). Nenhum deles reavalia nada.
 */
final class Verdict {

	/**
	 * Passa?
	 *
	 * @var bool
	 */
	private $allowed;

	/**
	 * Classe de falha, ou null quando aprovado.
	 *
	 * @var string|null
	 */
	private $failure;

	/**
	 * Mensagem já traduzida, vazia quando aprovado.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Motivo, no vocabulário do plugin.
	 *
	 * @var string|null
	 */
	private $reason;

	/**
	 * Códigos de diagnóstico do provedor. Só log e admin.
	 *
	 * @var string[]
	 */
	private $debug_codes;

	/**
	 * Construtor.
	 *
	 * @param bool        $allowed     Se o envio passa.
	 * @param string|null $failure     FailureClass::*.
	 * @param string      $message     Mensagem traduzida.
	 * @param string|null $reason      Motivo.
	 * @param string[]    $debug_codes Diagnóstico.
	 */
	public function __construct(
		bool $allowed,
		?string $failure = null,
		string $message = '',
		?string $reason = null,
		array $debug_codes = array()
	) {
		$this->allowed     = $allowed;
		$this->failure     = $failure;
		$this->message     = $message;
		$this->reason      = $reason;
		$this->debug_codes = $debug_codes;
	}

	/**
	 * Atalho de aprovação.
	 *
	 * @param string|null $failure Classe de falha que ocorreu mas foi permitida.
	 * @return Verdict
	 */
	public static function allow( ?string $failure = null ): Verdict {
		return new self( true, $failure );
	}

	/**
	 * Passa?
	 *
	 * @return bool
	 */
	public function is_allowed(): bool {
		return $this->allowed;
	}

	/**
	 * Bloqueia?
	 *
	 * @return bool
	 */
	public function is_blocked(): bool {
		return ! $this->allowed;
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
	 * Mensagem traduzida.
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Motivo.
	 *
	 * @return string|null
	 */
	public function reason(): ?string {
		return $this->reason;
	}

	/**
	 * Códigos de diagnóstico.
	 *
	 * @return string[]
	 */
	public function debug_codes(): array {
		return $this->debug_codes;
	}
}
