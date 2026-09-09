<?php
/**
 * Testes da camada de transporte (Story 1.2 AC3, AC7).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Provider\Endpoints;
use WpRecaptchaForms\Provider\Transport\TransportResult;
use WpRecaptchaForms\Tests\Support\FakeTransport;
use WpStubs;

/**
 * A fronteira de transporte, sem rede.
 */
final class TransportTest extends TestCase {

	/**
	 * Zera o estado global dos stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
	}

	/**
	 * Sucesso: corpo JSON decodificado.
	 *
	 * @return void
	 */
	public function test_json_body_is_decoded(): void {
		$result = new TransportResult( 200, '{"success":true,"score":0.9}' );

		$this->assertFalse( $result->is_error() );
		$this->assertSame( 200, $result->status() );
		$this->assertSame(
			array(
				'success' => true,
				'score'   => 0.9,
			),
			$result->json()
		);
	}

	/**
	 * Timeout / erro de rede.
	 *
	 * @return void
	 */
	public function test_transport_error_is_flagged(): void {
		$result = TransportResult::error( 'http_request_failed' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'http_request_failed', $result->error_code() );
		$this->assertSame( 0, $result->status() );
		$this->assertNull( $result->json() );
	}

	/**
	 * HTTP 5xx e 429 chegam com o status preservado para o provider classificar.
	 *
	 * @return void
	 */
	public function test_http_error_statuses_are_preserved(): void {
		$this->assertSame( 503, ( new TransportResult( 503, '' ) )->status() );
		$this->assertSame( 429, ( new TransportResult( 429, '' ) )->status() );
	}

	/**
	 * Corpo não-JSON vira null, não exceção.
	 *
	 * @return void
	 */
	public function test_non_json_body_returns_null(): void {
		$this->assertNull( ( new TransportResult( 200, '<html>gateway timeout</html>' ) )->json() );
	}

	/**
	 * Corpo vazio vira null.
	 *
	 * @return void
	 */
	public function test_empty_body_returns_null(): void {
		$this->assertNull( ( new TransportResult( 200, '' ) )->json() );
		$this->assertNull( ( new TransportResult( 200, '   ' ) )->json() );
	}

	/**
	 * JSON válido mas escalar (não objeto) também vira null.
	 *
	 * @return void
	 */
	public function test_scalar_json_returns_null(): void {
		$this->assertNull( ( new TransportResult( 200, '"ok"' ) )->json() );
	}

	/**
	 * O fake devolve a fila em ordem e registra as chamadas.
	 *
	 * @return void
	 */
	public function test_fake_transport_returns_queue_in_order(): void {
		$fake = new FakeTransport();
		$fake->will_return_json( array( 'success' => true ) )->will_fail( 'timeout' );

		$first = $fake->post( 'https://example.test/a', array( 'x' => 1 ) );
		$this->assertSame( array( 'success' => true ), $first->json() );

		$second = $fake->post( 'https://example.test/b', array() );
		$this->assertTrue( $second->is_error() );

		$this->assertSame( 2, $fake->call_count() );
		$this->assertSame( array( 'x' => 1 ), $fake->calls[0]['body'] );
	}

	/**
	 * O endpoint default é o do provedor, e o filtro documentado o troca.
	 *
	 * Caso real de suporte: rede que bloqueia o domínio principal.
	 *
	 * @return void
	 */
	public function test_endpoint_filter_replaces_url(): void {
		$this->assertStringContainsString( 'siteverify', Endpoints::siteverify() );

		add_filter(
			'wp_recaptcha_forms_endpoint',
			static function ( $url, $which ) {
				return str_replace( 'www.google.com', 'www.recaptcha.net', $url );
			},
			10,
			2
		);

		$this->assertStringContainsString( 'www.recaptcha.net', Endpoints::siteverify() );
		$this->assertStringContainsString( 'www.recaptcha.net', Endpoints::script() );
	}

	/**
	 * Filtro que devolve lixo não pode zerar o endpoint.
	 *
	 * @return void
	 */
	public function test_endpoint_filter_ignores_invalid_return(): void {
		add_filter( 'wp_recaptcha_forms_endpoint', static fn( $url, $which ) => '', 10, 2 );

		$this->assertSame( Endpoints::SITEVERIFY, Endpoints::siteverify() );
	}
}
