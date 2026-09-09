<?php
/**
 * Resultado de uma chamada de transporte.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resultado normalizado e imutável do transporte.
 *
 * Não conhece o vocabulário do provedor: só sabe se houve erro de transporte, qual o
 * status HTTP e qual o corpo. A interpretação é do provider.
 */
final class TransportResult {

	/**
	 * Erro de transporte (WP_Error, DNS, conexão, timeout).
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Status HTTP (0 quando não houve resposta).
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Corpo cru.
	 *
	 * @var string
	 */
	private $body;

	/**
	 * Construtor.
	 *
	 * @param int    $status     Status HTTP.
	 * @param string $body       Corpo.
	 * @param string $error_code Código de erro de transporte, '' se não houve.
	 */
	public function __construct( int $status, string $body = '', string $error_code = '' ) {
		$this->status     = $status;
		$this->body       = $body;
		$this->error_code = $error_code;
	}

	/**
	 * Atalho para erro de transporte.
	 *
	 * @param string $code Código.
	 * @return TransportResult
	 */
	public static function error( string $code ): TransportResult {
		return new self( 0, '', '' !== $code ? $code : 'transport_error' );
	}

	/**
	 * Houve erro de transporte?
	 *
	 * @return bool
	 */
	public function is_error(): bool {
		return '' !== $this->error_code;
	}

	/**
	 * Código do erro de transporte.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Status HTTP.
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Corpo cru.
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Corpo decodificado como array associativo.
	 *
	 * Devolve null quando o corpo está vazio ou não é um objeto JSON — o provider
	 * traduz esse null em INFRA.
	 *
	 * @return array|null
	 */
	public function json(): ?array {
		if ( '' === trim( $this->body ) ) {
			return null;
		}

		$decoded = json_decode( $this->body, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
