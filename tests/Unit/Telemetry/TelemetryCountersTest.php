<?php
/**
 * Coleta de contadores no Gate (Story 1.28).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Gate\ClientState;
use WpRecaptchaForms\Gate\FormContext;
use WpRecaptchaForms\Gate\Gate;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\FailureClass;
use WpRecaptchaForms\Provider\ProviderResponse;
use WpRecaptchaForms\Tests\Support\FakeProvider;
use WpRecaptchaForms\Telemetry\Counters;
use WpStubs;

/**
 * As duas mitigações do §3.3 são o que este arquivo protege: custo zero para quem não
 * optou, e uma escrita por request para quem optou.
 */
final class TelemetryCountersTest extends TestCase {

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
		Counters::reset_runtime();
	}

	/**
	 * Liga a telemetria e o listener.
	 *
	 * @return void
	 */
	private function enable_telemetry(): void {
		Options::update_telemetry(
			array(
				'enabled'     => true,
				'instance_id' => str_repeat( 'a', 32 ),
			)
		);

		Counters::boot();
	}

	/**
	 * Protege um formulário.
	 *
	 * @param string $form_id Formulário.
	 * @return void
	 */
	private function protect( string $form_id ): void {
		$all                    = Options::all();
		$all['secret_key']      = 'secret';
		$all['forms'][ $form_id ] = array_merge( Options::form_defaults(), array( 'enabled' => true ) );

		Options::update( $all );
	}

	/**
	 * Resposta de sucesso limpo do provedor.
	 *
	 * @return ProviderResponse
	 */
	private function ok(): ProviderResponse {
		return new ProviderResponse( true, true, null, 0.9, 'wrf_wp_login', 'example.test' );
	}

	/**
	 * Dispara `shutdown` como o WordPress faria.
	 *
	 * @return void
	 */
	private function shutdown(): void {
		do_action( 'shutdown' );
	}

	/**
	 * Telemetria desligada: nenhuma opção criada, nenhuma escrita, `assess()` inalterado.
	 *
	 * @return void
	 */
	public function test_disabled_telemetry_writes_nothing(): void {
		$this->protect( 'wp_login' );

		$gate    = new Gate( new FakeProvider( $this->ok() ) );
		$verdict = $gate->assess( new FormContext( 'wp_login', 'login', 'token-abc' ) );

		$this->shutdown();

		$this->assertTrue( $verdict->is_allowed() );
		$this->assertArrayNotHasKey( Options::OPTION_TELEMETRY_COUNTERS, WpStubs::$options );
	}

	/**
	 * Três avaliações de classes diferentes num request gravam UMA vez.
	 *
	 * @return void
	 */
	public function test_groups_writes_once_per_request(): void {
		$this->enable_telemetry();
		$this->protect( 'wp_login' );
		$this->protect( 'wp_comment' );
		$this->protect( 'wp_register' );

		$gate = new Gate( new FakeProvider( $this->ok() ) );

		// Sucesso limpo.
		$gate->assess( new FormContext( 'wp_login', 'login', 'token-1' ) );

		// Token vazio sem cstate: REJECTED.
		$gate->assess( new FormContext( 'wp_comment', 'comment', '' ) );

		/*
		 * Token vazio com cstate declarado: CLIENT_UNREACHABLE.
		 *
		 * Instância NOVA de propósito. A memoização do Gate é chaveada só pelo hash do
		 * token (v1.1 §7.2, e é assim por segurança), então duas submissões de token
		 * vazio na MESMA instância colidem qualquer que seja o formulário. Isso é
		 * comportamento existente e correto do Gate; forçá-lo aqui só provaria que o
		 * teste sabe contornar a si mesmo. O que importa para esta story é que o
		 * acumulador é estático por request, não por instância — e é isso que a
		 * asserção de UMA escrita abaixo verifica.
		 */
		$gate_b = new Gate( new FakeProvider( $this->ok() ) );
		$gate_b->assess( new FormContext( 'wp_register', 'register', '', ClientState::NO_SCRIPT ) );

		$this->assertArrayNotHasKey( Options::OPTION_TELEMETRY_COUNTERS, WpStubs::$options, 'nada é gravado antes do shutdown' );

		$this->shutdown();

		$snapshot = Counters::snapshot();

		// A mitigação inteira do §3.3 em uma asserção: três avaliações, UMA escrita.
		$this->assertSame( 1, WpStubs::$option_writes[ Options::OPTION_TELEMETRY_COUNTERS ] ?? 0 );

		$this->assertSame( 3, $snapshot['total'] );
		$this->assertSame( 1, $snapshot['by_class']['allowed'] );
		$this->assertSame( 1, $snapshot['by_class'][ FailureClass::REJECTED ] );
		$this->assertSame( 1, $snapshot['by_class'][ FailureClass::CLIENT_UNREACHABLE ] );
		$this->assertGreaterThan( 0, $snapshot['window_from'] );

		// REJECTED bloqueia sempre; CLIENT_UNREACHABLE é fail-open por default.
		$this->assertSame( 1, $snapshot['blocked'] );
	}

	/**
	 * A opção é gravada com `autoload = 'no'`.
	 *
	 * @return void
	 */
	public function test_option_is_not_autoloaded(): void {
		$this->enable_telemetry();
		$this->protect( 'wp_login' );

		$gate = new Gate( new FakeProvider( $this->ok() ) );
		$gate->assess( new FormContext( 'wp_login', 'login', 'token-1' ) );
		$this->shutdown();

		$this->assertSame( 'no', WpStubs::$autoload[ Options::OPTION_TELEMETRY_COUNTERS ] ?? null );
	}

	/**
	 * A submissão memoizada não é recontada: o incremento acompanha o `remember()`, não
	 * cada chamada de `assess()`.
	 *
	 * @return void
	 */
	public function test_memoized_submission_is_not_counted_twice(): void {
		$this->enable_telemetry();
		$this->protect( 'wp_login' );

		$gate    = new Gate( new FakeProvider( $this->ok() ) );
		$context = new FormContext( 'wp_login', 'login', 'token-repetido' );

		$gate->assess( $context );
		$gate->assess( $context );

		$this->shutdown();

		$this->assertSame( 1, Counters::snapshot()['total'] );
	}

	/**
	 * Formulário com o toggle desligado não conta em lugar nenhum.
	 *
	 * @return void
	 */
	public function test_unprotected_form_is_not_counted(): void {
		$this->enable_telemetry();

		$gate = new Gate( new FakeProvider( $this->ok() ) );
		$gate->assess( new FormContext( 'wp_login', 'login', 'token-1' ) );

		$this->shutdown();

		$this->assertArrayNotHasKey( Options::OPTION_TELEMETRY_COUNTERS, WpStubs::$options );
	}

	/**
	 * `by_form` incrementa `client_unreachable` só na classe certa.
	 *
	 * @return void
	 */
	public function test_by_form_counts_client_unreachable_only_for_that_class(): void {
		$this->enable_telemetry();
		$this->protect( 'wp_login' );

		$gate = new Gate( new FakeProvider( $this->ok() ) );
		$gate->assess( new FormContext( 'wp_login', 'login', 'token-1' ) );
		$gate->assess( new FormContext( 'wp_login', 'login', '', ClientState::EXEC_ERROR ) );

		$this->shutdown();

		$by_form = Counters::snapshot()['by_form']['wp_login'];

		$this->assertSame( 2, $by_form['total'] );
		$this->assertSame( 1, $by_form['client_unreachable'] );
	}

	/**
	 * Duas requisições somam na mesma janela, e `window_from` não se move.
	 *
	 * @return void
	 */
	public function test_second_request_accumulates_without_moving_the_window(): void {
		$this->enable_telemetry();
		$this->protect( 'wp_login' );

		$gate = new Gate( new FakeProvider( $this->ok() ) );
		$gate->assess( new FormContext( 'wp_login', 'login', 'token-1' ) );
		$this->shutdown();

		$window = Counters::snapshot()['window_from'];

		Counters::reset_runtime();

		$gate2 = new Gate( new FakeProvider( $this->ok() ) );
		$gate2->assess( new FormContext( 'wp_login', 'login', 'token-2' ) );
		$this->shutdown();

		$snapshot = Counters::snapshot();

		$this->assertSame( 2, $snapshot['total'] );
		$this->assertSame( $window, $snapshot['window_from'] );
	}

	/**
	 * `reset()` zera e recarimba a janela.
	 *
	 * @return void
	 */
	public function test_reset_zeroes_and_restamps_the_window(): void {
		$this->enable_telemetry();
		$this->protect( 'wp_login' );

		$gate = new Gate( new FakeProvider( $this->ok() ) );
		$gate->assess( new FormContext( 'wp_login', 'login', 'token-1' ) );
		$this->shutdown();

		Counters::reset();

		$snapshot = Counters::snapshot();

		$this->assertSame( 0, $snapshot['total'] );
		$this->assertSame( 0, $snapshot['blocked'] );
		$this->assertSame( array(), $snapshot['by_form'] );
		$this->assertSame( 0, $snapshot['by_class']['allowed'] );
		$this->assertGreaterThan( 0, $snapshot['window_from'] );
	}

	/**
	 * `purge()` apaga a opção inteira: desligado não acumula nada.
	 *
	 * @return void
	 */
	public function test_purge_removes_the_option(): void {
		$this->enable_telemetry();
		$this->protect( 'wp_login' );

		$gate = new Gate( new FakeProvider( $this->ok() ) );
		$gate->assess( new FormContext( 'wp_login', 'login', 'token-1' ) );
		$this->shutdown();

		Counters::purge();

		$this->assertArrayNotHasKey( Options::OPTION_TELEMETRY_COUNTERS, WpStubs::$options );
	}
}
