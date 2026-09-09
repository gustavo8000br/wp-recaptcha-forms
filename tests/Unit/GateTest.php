<?php
/**
 * Ordem de avaliação, memoização e política efetiva (Story 1.3 AC4, AC5, AC10).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Gate\ClientState;
use WpRecaptchaForms\Gate\FailurePolicy;
use WpRecaptchaForms\Gate\FormContext;
use WpRecaptchaForms\Gate\Gate;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\FailureClass;
use WpRecaptchaForms\Provider\ProviderResponse;
use WpRecaptchaForms\Provider\RejectionReason;
use WpRecaptchaForms\Tests\Support\FakeProvider;
use WpStubs;

/**
 * A metade da matriz da v1.1 §4.5 que o Gate possui: o que ele faz com uma classe de
 * falha já determinada, e o que ele decide sem gastar rede.
 */
final class GateTest extends TestCase {

	/**
	 * Zera stubs e configura uma instalação protegida.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
		$this->configure();
	}

	/**
	 * Grava opções.
	 *
	 * @param array $overrides Sobrescritas.
	 * @return void
	 */
	private function configure( array $overrides = array() ): void {
		$values = array_merge(
			Options::defaults(),
			array(
				'site_key'   => 'site',
				'secret_key' => 'secret',
				'forms'      => array(
					'wp_login' => array_merge( Options::form_defaults(), array( 'enabled' => true ) ),
				),
			),
			$overrides
		);

		Options::update( $values );
	}

	/**
	 * Contexto de submissão.
	 *
	 * @param string $token Token.
	 * @param string $state Estado do cliente.
	 * @return FormContext
	 */
	private function context( string $token = 'tok', string $state = ClientState::NONE ): FormContext {
		return new FormContext( 'wp_login', 'wrf_wp_login', $token, $state, '203.0.113.10' );
	}

	/**
	 * Gate com resposta fixa do provider.
	 *
	 * @param ProviderResponse $response Resposta.
	 * @return array{0: Gate, 1: FakeProvider}
	 */
	private function gate( ProviderResponse $response ): array {
		$provider = new FakeProvider( $response );

		return array( new Gate( $provider ), $provider );
	}

	/**
	 * Resposta de sucesso.
	 *
	 * @return ProviderResponse
	 */
	private function success(): ProviderResponse {
		return new ProviderResponse( true, true, null, 0.9, 'wrf_wp_login', 'example.test' );
	}

	/**
	 * Resposta de falha.
	 *
	 * @param string      $failure Classe.
	 * @param string|null $reason  Motivo.
	 * @return ProviderResponse
	 */
	private function failure( string $failure, ?string $reason = null ): ProviderResponse {
		return new ProviderResponse( FailureClass::INFRA !== $failure, false, $failure, null, null, null, array(), $reason );
	}

	// -----------------------------------------------------------------------
	// Passo 1 — toggle desligado.
	// -----------------------------------------------------------------------

	/**
	 * Formulário desligado passa sem gastar rede e sem contar como falha.
	 *
	 * @return void
	 */
	public function test_disabled_form_allows_without_network(): void {
		$this->configure( array( 'forms' => array() ) );

		list( $gate, $provider ) = $this->gate( $this->success() );

		$verdict = $gate->assess( $this->context() );

		$this->assertTrue( $verdict->is_allowed() );
		$this->assertNull( $verdict->failure() );
		$this->assertSame( 0, $provider->calls );
	}

	// -----------------------------------------------------------------------
	// Passo 2 — memoização.
	// -----------------------------------------------------------------------

	/**
	 * Duas avaliações do mesmo token no mesmo request gastam uma chamada só.
	 *
	 * Sem isto, o segundo `siteverify` com o mesmo token devolve token-já-usado, que é
	 * corretamente REJECTED — e o checkout quebra de forma intermitente, em produção.
	 *
	 * @return void
	 */
	public function test_same_token_is_memoized_within_request(): void {
		list( $gate, $provider ) = $this->gate( $this->success() );

		$first  = $gate->assess( $this->context() );
		$second = $gate->assess( $this->context() );

		$this->assertTrue( $first->is_allowed() );
		$this->assertTrue( $second->is_allowed() );
		$this->assertSame( 1, $provider->calls );
	}

	// -----------------------------------------------------------------------
	// Passo 3 — secret ausente.
	// -----------------------------------------------------------------------

	/**
	 * Sem secret: MISCONFIG, fail-open, sem rede, com o dado da escalada gravado.
	 *
	 * @return void
	 */
	public function test_missing_secret_is_misconfig_without_network(): void {
		$this->configure( array( 'secret_key' => '' ) );

		list( $gate, $provider ) = $this->gate( $this->success() );

		$verdict = $gate->assess( $this->context() );

		$this->assertTrue( $verdict->is_allowed(), 'MISCONFIG é fail-open no front' );
		$this->assertSame( FailureClass::MISCONFIG, $verdict->failure() );
		$this->assertSame( 0, $provider->calls );
		$this->assertNotEmpty( get_option( Options::OPTION_MISCONFIG_SINCE ) );
	}

	/**
	 * O filtro de postura estrita endurece MISCONFIG, sem UI.
	 *
	 * @return void
	 */
	public function test_misconfig_policy_filter_can_block(): void {
		$this->configure( array( 'secret_key' => '' ) );

		add_filter( 'wp_recaptcha_forms_misconfig_policy', static fn( $policy ) => FailurePolicy::BLOCK );

		list( $gate ) = $this->gate( $this->success() );

		$this->assertTrue( $gate->assess( $this->context() )->is_blocked() );
	}

	// -----------------------------------------------------------------------
	// Passo 4 — token vazio. As células em negrito da matriz.
	// -----------------------------------------------------------------------

	/**
	 * Token vazio sem declaração é o que um bot produz: BLOCK mesmo sob fail-open.
	 *
	 * REGRESSÃO NOMEADA. Se esta célula virar ALLOW, o plugin está desligado.
	 *
	 * @return void
	 */
	public function test_empty_token_without_cstate_is_rejected_even_under_fail_open(): void {
		$this->configure(
			array(
				'failure_policy_infra'              => FailurePolicy::ALLOW,
				'failure_policy_client_unreachable' => FailurePolicy::ALLOW,
			)
		);

		list( $gate, $provider ) = $this->gate( $this->success() );

		$verdict = $gate->assess( $this->context( '', ClientState::NONE ) );

		$this->assertTrue( $verdict->is_blocked() );
		$this->assertSame( FailureClass::REJECTED, $verdict->failure() );
		$this->assertSame( RejectionReason::MISSING_TOKEN, $verdict->reason() );
		$this->assertSame( 0, $provider->calls, 'não gasta rede nem cota' );
		$this->assertNotSame( '', $verdict->message() );
	}

	/**
	 * Token vazio com declaração de cliente inalcançável: ALLOW por default.
	 *
	 * REGRESSÃO NOMEADA — é a decisão do dono (v1.1 §1.4). Mudar este default é MAJOR.
	 *
	 * @dataProvider provide_unreachable_states
	 *
	 * @param string $state Estado declarado.
	 * @return void
	 */
	public function test_client_unreachable_is_allowed_by_default( string $state ): void {
		list( $gate, $provider ) = $this->gate( $this->success() );

		$verdict = $gate->assess( $this->context( '', $state ) );

		$this->assertTrue( $verdict->is_allowed() );
		$this->assertSame( FailureClass::CLIENT_UNREACHABLE, $verdict->failure() );
		$this->assertSame( 0, $provider->calls );
	}

	/**
	 * Sob política `block`, o mesmo caso é recusado com mensagem PRÓPRIA.
	 *
	 * @dataProvider provide_unreachable_states
	 *
	 * @param string $state Estado declarado.
	 * @return void
	 */
	public function test_client_unreachable_blocks_with_its_own_message( string $state ): void {
		$this->configure( array( 'failure_policy_client_unreachable' => FailurePolicy::BLOCK ) );

		list( $gate ) = $this->gate( $this->success() );

		$verdict = $gate->assess( $this->context( '', $state ) );

		$this->assertTrue( $verdict->is_blocked() );
		$this->assertSame( FailureClass::CLIENT_UNREACHABLE, $verdict->failure() );
		$this->assertStringContainsString( 'bloqueador', $verdict->message() );
		$this->assertStringNotContainsString( 'robô', $verdict->message() );
	}

	/**
	 * Estados de cliente inalcançável.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_unreachable_states(): array {
		return array(
			'script bloqueado'       => array( ClientState::NO_SCRIPT ),
			'consentimento pendente' => array( ClientState::CONSENT_PENDING ),
			'execucao falhou'        => array( ClientState::EXEC_ERROR ),
		);
	}

	/**
	 * Bot esperto: token não vazio recusado pelo provedor + declaração de inalcançável.
	 *
	 * A classe vem do TOKEN, não da declaração. REGRESSÃO MAIS IMPORTANTE da v1.1:
	 * se esta célula virar ALLOW, o bypass fica trivial.
	 *
	 * @return void
	 */
	public function test_present_token_ignores_client_declaration(): void {
		$this->configure( array( 'failure_policy_client_unreachable' => FailurePolicy::ALLOW ) );

		list( $gate ) = $this->gate( $this->failure( FailureClass::REJECTED, RejectionReason::INVALID_TOKEN ) );

		$verdict = $gate->assess( $this->context( 'lixo', ClientState::NO_SCRIPT ) );

		$this->assertTrue( $verdict->is_blocked() );
		$this->assertSame( FailureClass::REJECTED, $verdict->failure() );
	}

	/**
	 * Valor inventado de cstate não vira passe livre: cai em token vazio sem declaração.
	 *
	 * @return void
	 */
	public function test_unknown_cstate_value_falls_back_to_rejected(): void {
		list( $gate ) = $this->gate( $this->success() );

		$verdict = $gate->assess( new FormContext( 'wp_login', 'wrf_wp_login', '', 'me-deixa-passar' ) );

		$this->assertTrue( $verdict->is_blocked() );
		$this->assertSame( FailureClass::REJECTED, $verdict->failure() );
	}

	// -----------------------------------------------------------------------
	// Passo 7 — política efetiva nos dois eixos.
	// -----------------------------------------------------------------------

	/**
	 * INFRA segue global e override, e os dois eixos são independentes.
	 *
	 * @return void
	 */
	public function test_infra_policy_axes(): void {
		list( $gate ) = $this->gate( $this->failure( FailureClass::INFRA ) );
		$this->assertTrue( $gate->assess( $this->context() )->is_allowed(), 'global allow' );

		$this->configure( array( 'failure_policy_infra' => FailurePolicy::BLOCK ) );
		list( $gate ) = $this->gate( $this->failure( FailureClass::INFRA ) );
		$this->assertTrue( $gate->assess( $this->context() )->is_blocked(), 'global block' );

		// Override do formulário vence o global nos dois sentidos.
		$this->configure(
			array(
				'failure_policy_infra' => FailurePolicy::BLOCK,
				'forms'                => array(
					'wp_login' => array_merge(
						Options::form_defaults(),
						array(
							'enabled'      => true,
							'policy_infra' => FailurePolicy::ALLOW,
						)
					),
				),
			)
		);
		list( $gate ) = $this->gate( $this->failure( FailureClass::INFRA ) );
		$this->assertTrue( $gate->assess( $this->context() )->is_allowed(), 'override allow vence global block' );

		// E o eixo do cliente não foi afetado por nada disso.
		$this->assertTrue( $gate->assess( $this->context( '', ClientState::NO_SCRIPT ) )->is_allowed() );
	}

	/**
	 * REJECTED nunca é configurável — nem por opção, nem por override, nem por filtro.
	 *
	 * @return void
	 */
	public function test_rejected_is_never_configurable(): void {
		$this->configure(
			array(
				'failure_policy_infra'              => FailurePolicy::ALLOW,
				'failure_policy_client_unreachable' => FailurePolicy::ALLOW,
				'forms'                             => array(
					'wp_login' => array_merge(
						Options::form_defaults(),
						array(
							'enabled'                   => true,
							'policy_infra'              => FailurePolicy::ALLOW,
							'policy_client_unreachable' => FailurePolicy::ALLOW,
						)
					),
				),
			)
		);

		add_filter( 'wp_recaptcha_forms_misconfig_policy', static fn( $p ) => FailurePolicy::ALLOW );

		list( $gate ) = $this->gate( $this->failure( FailureClass::REJECTED, RejectionReason::DUPLICATE_TOKEN ) );

		$this->assertTrue( $gate->assess( $this->context() )->is_blocked() );
	}

	/**
	 * O filtro de política do cliente inalcançável funciona nos dois sentidos.
	 *
	 * @return void
	 */
	public function test_client_unreachable_filter(): void {
		add_filter( 'wp_recaptcha_forms_client_unreachable_policy', static fn( $p, $ctx ) => FailurePolicy::BLOCK, 10, 2 );

		list( $gate ) = $this->gate( $this->success() );

		$this->assertTrue( $gate->assess( $this->context( '', ClientState::NO_SCRIPT ) )->is_blocked() );
	}

	// -----------------------------------------------------------------------
	// Advisory de par cruzado.
	// -----------------------------------------------------------------------

	/**
	 * 10 avaliações, 100% token inválido: grava o advisory, sem mudar veredito nenhum.
	 *
	 * @return void
	 */
	public function test_keypair_advisory_after_ten_invalid(): void {
		$response = $this->failure( FailureClass::REJECTED, RejectionReason::INVALID_TOKEN );

		for ( $i = 0; $i < 10; $i++ ) {
			$gate    = new Gate( new FakeProvider( $response ) );
			$verdict = $gate->assess( $this->context( 'token-' . $i ) );
			$this->assertTrue( $verdict->is_blocked(), 'o advisory não muda veredito' );
		}

		$this->assertNotEmpty( get_option( Options::OPTION_KEYPAIR_SUSPECT ) );
	}

	/**
	 * Uma única verificação boa no meio desarma o advisory: não é par cruzado.
	 *
	 * @return void
	 */
	public function test_keypair_advisory_not_raised_when_some_succeed(): void {
		$bad = $this->failure( FailureClass::REJECTED, RejectionReason::INVALID_TOKEN );

		for ( $i = 0; $i < 9; $i++ ) {
			( new Gate( new FakeProvider( $bad ) ) )->assess( $this->context( 'token-' . $i ) );
		}

		( new Gate( new FakeProvider( $this->success() ) ) )->assess( $this->context( 'token-ok' ) );

		for ( $i = 0; $i < 5; $i++ ) {
			( new Gate( new FakeProvider( $bad ) ) )->assess( $this->context( 'token-b-' . $i ) );
		}

		$this->assertFalse( get_option( Options::OPTION_KEYPAIR_SUSPECT ) );
	}

	/**
	 * Uma verificação bem-sucedida limpa o estado de MISCONFIG.
	 *
	 * @return void
	 */
	public function test_success_clears_misconfig_state(): void {
		update_option( Options::OPTION_MISCONFIG_SINCE, time() );

		list( $gate ) = $this->gate( $this->success() );
		$gate->assess( $this->context() );

		$this->assertFalse( get_option( Options::OPTION_MISCONFIG_SINCE ) );
	}
}
