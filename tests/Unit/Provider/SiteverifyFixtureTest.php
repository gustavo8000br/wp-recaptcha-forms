<?php
/**
 * Matriz normativa da arquitetura v1.1 §4.5, dirigida por fixtures (Story 1.24 AC 2).
 *
 * A matriz aqui é **dado**, não código: cada linha é um arquivo em
 * `tests/Fixtures/siteverify/`. Isso importa por dois motivos.
 *
 * Primeiro, uma resposta do Google guardada como JSON é conferível contra a documentação
 * do Google por quem não lê PHP — inclusive por mim daqui a um ano.
 *
 * Segundo, mantém o vocabulário do provedor confinado a `src/Provider/` e a este
 * diretório de dados, que é o que `bin/check-boundary.sh` protege.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\FailureClass;
use WpRecaptchaForms\Provider\SiteverifyV2;
use WpRecaptchaForms\Provider\SiteverifyV3;
use WpRecaptchaForms\Provider\VerificationRequest;
use WpRecaptchaForms\Tests\Support\FakeTransport;
use WpStubs;

/**
 * Toda linha da tabela §4.5 que é, de fato, uma resposta JSON do Google.
 */
final class SiteverifyFixtureTest extends TestCase {

	/**
	 * Zera stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();

		Options::update(
			array_merge(
				Options::defaults(),
				array(
					'site_key'   => 'site',
					'secret_key' => 'secret',
					'threshold'  => 0.6,
				)
			)
		);
	}

	/**
	 * Carrega uma fixture.
	 *
	 * @param string $name Nome do arquivo, sem extensão.
	 * @return array
	 */
	private function fixture( string $name ): array {
		$path = WP_RECAPTCHA_FORMS_DIR . 'tests/Fixtures/siteverify/' . $name . '.json';

		$this->assertFileExists( $path, 'fixture ausente — a matriz é dado, e o dado sumiu' );

		return (array) json_decode( (string) file_get_contents( $path ), true );
	}

	/**
	 * Cada fixture produz a classe de falha prevista pela §4.2.
	 *
	 * @dataProvider provide_matrix
	 *
	 * @param string      $fixture  Nome da fixture.
	 * @param bool        $allowed  Se a resposta é um sucesso de verificação.
	 * @param string|null $failure  FailureClass esperada, ou null em caso de sucesso.
	 * @return void
	 */
	public function test_matrix( string $fixture, bool $allowed, ?string $failure ): void {
		$transport = ( new FakeTransport() )->will_return_json( $this->fixture( $fixture ) );
		$provider  = new SiteverifyV3( $transport );

		$response = $provider->verify( new VerificationRequest( 'token-qualquer', 'secret', null, 'wrf_wp_login', null, 0.6 ) );

		$this->assertTrue( $response->reached_provider(), 'toda fixture é uma resposta HTTP 200 com JSON válido' );
		$this->assertSame( $allowed, null === $response->failure(), 'sucesso e falha são exclusivos' );
		$this->assertSame( $failure, $response->failure(), 'fixture: ' . $fixture );
	}

	/**
	 * A matriz.
	 *
	 * @return array<string, array{0:string, 1:bool, 2:?string}>
	 */
	public function provide_matrix(): array {
		return array(
			'score alto, action certa'  => array( 'success-v3-high-score', true, null ),
			'score abaixo do threshold' => array( 'success-v3-low-score', false, FailureClass::REJECTED ),
			'action divergente'         => array( 'success-v3-wrong-action', false, FailureClass::REJECTED ),
			'secret invalida'           => array( 'error-invalid-input-secret', false, FailureClass::MISCONFIG ),
			'par de chaves invalido'    => array( 'error-invalid-keys', false, FailureClass::MISCONFIG ),
			'secret ausente'            => array( 'error-missing-input-secret', false, FailureClass::MISCONFIG ),
			'requisicao malformada'     => array( 'error-bad-request', false, FailureClass::MISCONFIG ),
			'token ausente'             => array( 'error-missing-input-response', false, FailureClass::REJECTED ),
			'token invalido'            => array( 'error-invalid-input-response', false, FailureClass::REJECTED ),
			'token ja usado'            => array( 'error-timeout-or-duplicate', false, FailureClass::REJECTED ),
			'codigo novo do Google'     => array( 'error-unknown-code', false, FailureClass::INFRA ),
			'falha sem codigo'          => array( 'error-no-codes', false, FailureClass::INFRA ),
		);
	}

	/**
	 * A fixture do v2 passa pelo provider do v2 — que não tem score nem action.
	 *
	 * Existe para impedir a regressão de alguém acrescentar checagem de score ao caminho
	 * do v2: o Google não devolve `score` no v2, e comparar `null` com o threshold
	 * bloquearia todo mundo.
	 *
	 * @return void
	 */
	public function test_v2_success_has_no_score_requirement(): void {
		Options::update( array_merge( Options::all(), array( 'version' => 'v2' ) ) );

		$transport = ( new FakeTransport() )->will_return_json( $this->fixture( 'success-v2' ) );
		$provider  = new SiteverifyV2( $transport );

		$response = $provider->verify( new VerificationRequest( 'token-qualquer', 'secret' ) );

		$this->assertTrue( $response->reached_provider() );
		$this->assertNull( $response->failure() );
	}

	/**
	 * Toda fixture no diretório é exercitada por algum teste.
	 *
	 * Fixture órfã é pior que fixture ausente: parece cobertura e não é.
	 *
	 * @return void
	 */
	public function test_every_fixture_is_used(): void {
		$arquivos = glob( WP_RECAPTCHA_FORMS_DIR . 'tests/Fixtures/siteverify/*.json' );

		$usadas = array_column( $this->provide_matrix(), 0 );
		$usadas[] = 'success-v2';

		foreach ( (array) $arquivos as $arquivo ) {
			$nome = basename( $arquivo, '.json' );

			$this->assertContains( $nome, $usadas, "fixture órfã: $nome" );
		}

		$this->assertSame( count( $usadas ), count( (array) $arquivos ), 'toda fixture usada existe no disco' );
	}
}
