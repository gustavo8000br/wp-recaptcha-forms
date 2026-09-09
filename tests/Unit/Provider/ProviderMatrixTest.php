<?php
/**
 * Matriz da arquitetura v1.1 §4.5, ponta a ponta (Story 1.4 AC6/AC7, Story 1.3 AC10).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Gate\FailurePolicy;
use WpRecaptchaForms\Gate\FormContext;
use WpRecaptchaForms\Gate\Gate;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\FailureClass;
use WpRecaptchaForms\Provider\ProviderFactory;
use WpRecaptchaForms\Provider\RejectionReason;
use WpRecaptchaForms\Tests\Support\FakeTransport;
use WpStubs;

/**
 * Cada célula da matriz: cenário do fake → classe → veredito por configuração.
 *
 * Sem rede e sem o hook global `pre_http_request` — que é o que torna esta suíte
 * determinística em vez de flaky.
 */
final class ProviderMatrixTest extends TestCase {

	/**
	 * Zera stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
		$this->configure();
	}

	/**
	 * Configura a instalação.
	 *
	 * @param array $overrides Sobrescritas.
	 * @return void
	 */
	private function configure( array $overrides = array() ): void {
		Options::update(
			array_merge(
				Options::defaults(),
				array(
					'site_key'   => 'site',
					'secret_key' => 'secret',
					'threshold'  => 0.6,
					'forms'      => array(
						'wp_login' => array_merge( Options::form_defaults(), array( 'enabled' => true ) ),
					),
				),
				$overrides
			)
		);
	}

	/**
	 * Contexto padrão.
	 *
	 * @param string $token Token.
	 * @return FormContext
	 */
	private function context( string $token = 'tok' ): FormContext {
		return new FormContext( 'wp_login', 'wrf_wp_login', $token, '', '203.0.113.10' );
	}

	// -----------------------------------------------------------------------
	// Linhas de INFRA.
	// -----------------------------------------------------------------------

	/**
	 * Cada cenário de indisponibilidade vira INFRA, e INFRA segue a política.
	 *
	 * @dataProvider provide_infra_scenarios
	 *
	 * @param callable $arrange Prepara o fake.
	 * @return void
	 */
	public function test_infra_scenarios( callable $arrange ): void {
		$fake = new FakeTransport();
		$arrange( $fake );

		$gate    = new Gate( ProviderFactory::for_version( 'v3', $fake ) );
		$verdict = $gate->assess( $this->context() );

		$this->assertTrue( $verdict->is_allowed(), 'fail-open é o default de INFRA' );
		$this->assertSame( FailureClass::INFRA, $verdict->failure() );

		// Mesmo cenário sob política de bloqueio.
		$this->configure( array( 'failure_policy_infra' => FailurePolicy::BLOCK ) );

		$fake2 = new FakeTransport();
		$arrange( $fake2 );

		$gate2 = new Gate( ProviderFactory::for_version( 'v3', $fake2 ) );
		$this->assertTrue( $gate2->assess( $this->context() )->is_blocked() );
	}

	/**
	 * Cenários de INFRA.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public function provide_infra_scenarios(): array {
		return array(
			'erro de transporte'  => array( static fn( FakeTransport $f ) => $f->will_fail( 'http_request_failed' ) ),
			'HTTP 503'            => array( static fn( FakeTransport $f ) => $f->will_return_raw( 503, '' ) ),
			'HTTP 429'            => array( static fn( FakeTransport $f ) => $f->will_return_raw( 429, '' ) ),
			'corpo nao-JSON'      => array( static fn( FakeTransport $f ) => $f->will_return_raw( 200, '<html>oops</html>' ) ),
			'corpo vazio'         => array( static fn( FakeTransport $f ) => $f->will_return_raw( 200, '' ) ),
			'codigo desconhecido' => array(
				static fn( FakeTransport $f ) => $f->will_return_json(
					array(
						'success'     => false,
						'error-codes' => array( 'cod-novo-do-google' ),
					)
				),
			),
		);
	}

	// -----------------------------------------------------------------------
	// Linhas de MISCONFIG: fail-open em qualquer política, sempre com escalada.
	// -----------------------------------------------------------------------

	/**
	 * MISCONFIG é fail-open no front e grava o dado da escalada, inclusive com a
	 * política global em "bloquear".
	 *
	 * @dataProvider provide_misconfig_codes
	 *
	 * @param string $code Código.
	 * @return void
	 */
	public function test_misconfig_codes( string $code ): void {
		$this->configure(
			array(
				'failure_policy_infra'              => FailurePolicy::BLOCK,
				'failure_policy_client_unreachable' => FailurePolicy::BLOCK,
			)
		);

		$fake = ( new FakeTransport() )->will_return_json(
			array(
				'success'     => false,
				'error-codes' => array( $code ),
			)
		);

		$gate    = new Gate( ProviderFactory::for_version( 'v3', $fake ) );
		$verdict = $gate->assess( $this->context() );

		$this->assertTrue( $verdict->is_allowed() );
		$this->assertSame( FailureClass::MISCONFIG, $verdict->failure() );
		$this->assertNotEmpty( get_option( Options::OPTION_MISCONFIG_SINCE ) );
		$this->assertContains( $code, $verdict->debug_codes(), 'debug_codes vai para admin e log' );
	}

	/**
	 * Códigos de MISCONFIG.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_misconfig_codes(): array {
		return array(
			'secret invalida'       => array( 'invalid-input-secret' ),
			'par de chaves'         => array( 'invalid-keys' ),
			'secret ausente'        => array( 'missing-input-secret' ),
			'requisicao malformada' => array( 'bad-request' ),
		);
	}

	// -----------------------------------------------------------------------
	// Linhas de REJECTED: bloqueiam mesmo sob fail-open total.
	// -----------------------------------------------------------------------

	/**
	 * As células em negrito da matriz: bloqueiam com toda política em "permitir".
	 *
	 * Se estas virarem ALLOW, o plugin está desligado.
	 *
	 * @dataProvider provide_rejected_codes
	 *
	 * @param string      $code   Código.
	 * @param string|null $reason Motivo esperado.
	 * @return void
	 */
	public function test_rejected_codes_block_under_full_fail_open( string $code, ?string $reason ): void {
		$this->configure(
			array(
				'failure_policy_infra'              => FailurePolicy::ALLOW,
				'failure_policy_client_unreachable' => FailurePolicy::ALLOW,
			)
		);

		$fake = ( new FakeTransport() )->will_return_json(
			array(
				'success'     => false,
				'error-codes' => array( $code ),
			)
		);

		$gate    = new Gate( ProviderFactory::for_version( 'v3', $fake ) );
		$verdict = $gate->assess( $this->context() );

		$this->assertTrue( $verdict->is_blocked() );
		$this->assertSame( FailureClass::REJECTED, $verdict->failure() );
		$this->assertSame( $reason, $verdict->reason() );
	}

	/**
	 * Códigos de recusa.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public function provide_rejected_codes(): array {
		return array(
			'token ja usado' => array( 'timeout-or-duplicate', RejectionReason::DUPLICATE_TOKEN ),
			'token ausente'  => array( 'missing-input-response', RejectionReason::MISSING_TOKEN ),
			'token invalido' => array( 'invalid-input-response', RejectionReason::INVALID_TOKEN ),
		);
	}

	// -----------------------------------------------------------------------
	// Threshold e action do v3.
	// -----------------------------------------------------------------------

	/**
	 * Score contra o threshold, incluindo o limite exato.
	 *
	 * @dataProvider provide_scores
	 *
	 * @param float $score   Score devolvido.
	 * @param bool  $allowed Esperado.
	 * @return void
	 */
	public function test_threshold( float $score, bool $allowed ): void {
		$fake = ( new FakeTransport() )->will_return_json(
			array(
				'success'  => true,
				'score'    => $score,
				'action'   => 'wrf_wp_login',
				'hostname' => 'example.test',
			)
		);

		$gate    = new Gate( ProviderFactory::for_version( 'v3', $fake ) );
		$verdict = $gate->assess( $this->context() );

		$this->assertSame( $allowed, $verdict->is_allowed() );

		if ( ! $allowed ) {
			$this->assertSame( FailureClass::REJECTED, $verdict->failure() );
			$this->assertSame( RejectionReason::LOW_SCORE, $verdict->reason() );
		}
	}

	/**
	 * Scores em torno do threshold de 0.6.
	 *
	 * @return array<string, array{0: float, 1: bool}>
	 */
	public function provide_scores(): array {
		return array(
			'acima do limite'      => array( 0.9, true ),
			'exatamente no limite' => array( 0.6, true ),
			'logo abaixo'          => array( 0.59, false ),
			'bem abaixo'           => array( 0.1, false ),
		);
	}

	/**
	 * Action divergente com score alto é recusa.
	 *
	 * @return void
	 */
	public function test_action_mismatch_is_rejected(): void {
		$fake = ( new FakeTransport() )->will_return_json(
			array(
				'success' => true,
				'score'   => 0.9,
				'action'  => 'outra_action',
			)
		);

		$gate    = new Gate( ProviderFactory::for_version( 'v3', $fake ) );
		$verdict = $gate->assess( $this->context() );

		$this->assertTrue( $verdict->is_blocked() );
		$this->assertSame( RejectionReason::ACTION_MISMATCH, $verdict->reason() );
	}

	/**
	 * Caminho feliz.
	 *
	 * @return void
	 */
	public function test_happy_path_allows(): void {
		$fake = ( new FakeTransport() )->will_return_json(
			array(
				'success' => true,
				'score'   => 0.9,
				'action'  => 'wrf_wp_login',
			)
		);

		$gate = new Gate( ProviderFactory::for_version( 'v3', $fake ) );

		$this->assertTrue( $gate->assess( $this->context() )->is_allowed() );
	}

	// -----------------------------------------------------------------------
	// v2.
	// -----------------------------------------------------------------------

	/**
	 * O v2 não tem score e não olha action: só `success` e hostname.
	 *
	 * @return void
	 */
	public function test_v2_ignores_score_and_action(): void {
		$this->configure( array( 'version' => 'v2' ) );

		$fake = ( new FakeTransport() )->will_return_json(
			array(
				'success'  => true,
				'hostname' => 'example.test',
			)
		);

		$provider = ProviderFactory::create( $fake );

		$this->assertSame( 'v2', $provider->version() );
		$this->assertSame( 'g-recaptcha-response', $provider->token_field() );

		$gate = new Gate( $provider );

		$this->assertTrue( $gate->assess( $this->context() )->is_allowed() );
	}

	/**
	 * A factory devolve o provider da versão salva.
	 *
	 * @return void
	 */
	public function test_factory_follows_saved_version(): void {
		$this->assertSame( 'v3', ProviderFactory::create( new FakeTransport() )->version() );
		$this->assertSame( 'wrf_token', ProviderFactory::create( new FakeTransport() )->token_field() );

		$this->configure( array( 'version' => 'v2' ) );

		$this->assertSame( 'v2', ProviderFactory::create( new FakeTransport() )->version() );
	}

	// -----------------------------------------------------------------------
	// script_url por versão, respeitando o filtro de endpoint.
	// -----------------------------------------------------------------------

	/**
	 * URLs de script e o filtro de endpoint.
	 *
	 * @return void
	 */
	public function test_script_urls(): void {
		$v3 = ProviderFactory::for_version( 'v3', new FakeTransport() )->script_url( 'SITEKEY', 'pt_BR' );
		$this->assertStringContainsString( 'render=SITEKEY', $v3 );
		$this->assertStringContainsString( 'hl=pt-BR', $v3 );

		$v2 = ProviderFactory::for_version( 'v2', new FakeTransport() )->script_url( 'SITEKEY', 'pt_BR' );
		$this->assertStringContainsString( 'render=explicit', $v2 );
		$this->assertStringNotContainsString( 'render=SITEKEY', $v2 );

		add_filter(
			'wp_recaptcha_forms_endpoint',
			static fn( $url, $which ) => str_replace( 'www.google.com', 'www.recaptcha.net', $url ),
			10,
			2
		);

		$this->assertStringContainsString(
			'www.recaptcha.net',
			ProviderFactory::for_version( 'v3', new FakeTransport() )->script_url( 'SITEKEY', '' )
		);
	}

	/**
	 * O IP do visitante só é enviado quando o operador mantém o toggle ligado.
	 *
	 * @return void
	 */
	public function test_remote_ip_toggle(): void {
		$fake = ( new FakeTransport() )->will_return_json(
			array(
				'success' => true,
				'score'   => 0.9,
				'action'  => 'wrf_wp_login',
			)
		);
		( new Gate( ProviderFactory::for_version( 'v3', $fake ) ) )->assess( $this->context() );
		$this->assertArrayHasKey( 'remoteip', $fake->calls[0]['body'] );

		$this->configure( array( 'remoteip' => false ) );

		$fake2 = ( new FakeTransport() )->will_return_json(
			array(
				'success' => true,
				'score'   => 0.9,
				'action'  => 'wrf_wp_login',
			)
		);
		( new Gate( ProviderFactory::for_version( 'v3', $fake2 ) ) )->assess( $this->context() );
		$this->assertArrayNotHasKey( 'remoteip', $fake2->calls[0]['body'] );
	}

	/**
	 * A secret nunca vaza para o diagnóstico exposto no admin.
	 *
	 * @return void
	 */
	public function test_secret_never_reaches_debug_codes(): void {
		$fake = ( new FakeTransport() )->will_return_json(
			array(
				'success'     => false,
				'error-codes' => array( 'invalid-input-secret' ),
			)
		);

		$verdict = ( new Gate( ProviderFactory::for_version( 'v3', $fake ) ) )->assess( $this->context() );

		$this->assertStringNotContainsString( 'secret', implode( ',', array_filter( $verdict->debug_codes(), static fn( $c ) => 'invalid-input-secret' !== $c ) ) );
		$this->assertSame( 'secret', $fake->calls[0]['body']['secret'], 'a secret vai no corpo da requisição, e só' );
	}
}
