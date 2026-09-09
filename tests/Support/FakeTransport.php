<?php
/**
 * Transporte falso para a suíte unitária.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Support;

use WpRecaptchaForms\Provider\Transport\TransportInterface;
use WpRecaptchaForms\Provider\Transport\TransportResult;

/**
 * Injetado diretamente no provider, sem passar pelo hook global `pre_http_request`
 * (arquitetura v1 §4.3). É o que torna a matriz de §4.5 barata e não-flaky.
 */
final class FakeTransport implements TransportInterface {

	/**
	 * Fila de resultados a devolver, em ordem.
	 *
	 * @var TransportResult[]
	 */
	private $queue = array();

	/**
	 * Chamadas recebidas: cada item é array{url:string, body:array, args:array}.
	 *
	 * @var array[]
	 */
	public $calls = array();

	/**
	 * Enfileira uma resposta JSON com status 200.
	 *
	 * @param array $payload Corpo decodificado.
	 * @return FakeTransport
	 */
	public function will_return_json( array $payload ): FakeTransport {
		$this->queue[] = new TransportResult( 200, (string) wp_json_encode_compat( $payload ) );

		return $this;
	}

	/**
	 * Enfileira um corpo cru.
	 *
	 * @param int    $status Status HTTP.
	 * @param string $body   Corpo.
	 * @return FakeTransport
	 */
	public function will_return_raw( int $status, string $body = '' ): FakeTransport {
		$this->queue[] = new TransportResult( $status, $body );

		return $this;
	}

	/**
	 * Enfileira um erro de transporte.
	 *
	 * @param string $code Código.
	 * @return FakeTransport
	 */
	public function will_fail( string $code = 'http_request_failed' ): FakeTransport {
		$this->queue[] = TransportResult::error( $code );

		return $this;
	}

	/**
	 * Quantas vezes o transporte foi chamado.
	 *
	 * @return int
	 */
	public function call_count(): int {
		return count( $this->calls );
	}

	/**
	 * Executa a chamada falsa.
	 *
	 * @param string $url  URL.
	 * @param array  $body Corpo.
	 * @param array  $args Argumentos.
	 * @return TransportResult
	 */
	public function post( string $url, array $body, array $args = array() ): TransportResult {
		$this->calls[] = array(
			'url'  => $url,
			'body' => $body,
			'args' => $args,
		);

		if ( empty( $this->queue ) ) {
			throw new \RuntimeException( 'FakeTransport: chamada sem resposta enfileirada para ' . $url );
		}

		return array_shift( $this->queue );
	}
}

/**
 * `wp_json_encode` não existe fora do WordPress; a suíte não precisa das opções dele.
 *
 * @param mixed $data Dados.
 * @return string
 */
function wp_json_encode_compat( $data ): string {
	return (string) json_encode( $data );
}
