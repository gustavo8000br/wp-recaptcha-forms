<?php
/**
 * Transporte: cron, jitter, envio e falha (Story 1.31).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Gate\FormContext;
use WpRecaptchaForms\Gate\Gate;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\ProviderResponse;
use WpRecaptchaForms\Tests\Support\FakeProvider;
use WpRecaptchaForms\Telemetry\Consent;
use WpRecaptchaForms\Telemetry\Counters;
use WpRecaptchaForms\Telemetry\Endpoints;
use WpRecaptchaForms\Telemetry\Schedule;
use WpRecaptchaForms\Telemetry\Transport;
use WpStubs;

/**
 * Duas regras governam este arquivo, e as duas são sobre o site do OPERADOR: nenhum envio
 * parte de um request de formulário, e uma falha do autor do plugin nunca vira problema
 * dele.
 */
final class TelemetryTransportTest extends TestCase {

	/**
	 * Preparação: telemetria ligada, contexto de cron.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
		Counters::reset_runtime();

		Options::update_telemetry(
			array(
				'enabled'     => true,
				'instance_id' => 'b7f3c1a9e2d84f60b1c5a7d3e9f04628',
			)
		);

		WpStubs::$env['doing_cron'] = true;

		Transport::boot();
	}

	/**
	 * Intercepta a requisição com uma resposta fixa.
	 *
	 * @param int    $code    Código HTTP.
	 * @param string $body    Corpo.
	 * @param array  $headers Cabeçalhos.
	 * @return void
	 */
	private function respond_with( int $code, string $body = '{}', array $headers = array() ): void {
		add_filter(
			'pre_http_request',
			static function () use ( $code, $body, $headers ) {
				return array(
					'response' => array( 'code' => $code ),
					'body'     => $body,
					'headers'  => $headers,
				);
			}
		);
	}

	/**
	 * Popula os contadores.
	 *
	 * @return void
	 */
	private function seed_counters(): void {
		update_option(
			Options::OPTION_TELEMETRY_COUNTERS,
			array(
				'window_from' => time() - DAY_IN_SECONDS,
				'total'       => 500,
				'blocked'     => 20,
				'by_class'    => array(
					'allowed'  => 480,
					'rejected' => 20,
				),
				'by_form'     => array(),
			)
		);
	}

	/**
	 * 200: contadores zerados, `seq` +1, `last_sent` gravado.
	 *
	 * @return void
	 */
	public function test_success_resets_counters_and_bumps_seq(): void {
		$this->seed_counters();
		Options::update_telemetry( array( 'seq' => 4 ) );

		$this->respond_with( 202, '{"accepted":true}' );

		Transport::run();

		$telemetry = Options::telemetry();

		$this->assertSame( 5, $telemetry['seq'] );
		$this->assertGreaterThan( 0, $telemetry['last_sent'] );
		$this->assertSame( 'ok', $telemetry['last_status'] );
		$this->assertSame( 0, Counters::snapshot()['total'] );
		$this->assertArrayNotHasKey( Schedule::HOOK_RETRY, WpStubs::$cron );
	}

	/**
	 * A requisição sai com os parâmetros do §6.2 — e o User-Agent NÃO carrega o endereço
	 * do site, que é o vazamento mais fácil de cometer no desenho inteiro.
	 *
	 * @return void
	 */
	public function test_request_shape(): void {
		$this->respond_with( 202 );

		Transport::run();

		$this->assertCount( 1, WpStubs::$http );

		$request = WpStubs::$http[0];
		$args    = $request['args'];

		$this->assertSame( Endpoints::INGEST, $request['url'] );
		$this->assertSame( 5, $args['timeout'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['blocking'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( 'wp-recaptcha-forms/' . WP_RECAPTCHA_FORMS_VERSION, $args['headers']['User-Agent'] );
		$this->assertStringNotContainsString( 'example.test', $args['headers']['User-Agent'] );

		$body = json_decode( $args['body'], true );

		$this->assertSame( $body['envelope_id'], $args['headers']['Idempotency-Key'] );
	}

	/**
	 * 500: UMA retentativa, com o MESMO `envelope_id`, e contadores intactos.
	 *
	 * @return void
	 */
	public function test_server_error_schedules_one_retry_with_the_same_id(): void {
		$this->seed_counters();
		$this->respond_with( 503 );

		Transport::run();

		$sent = json_decode( WpStubs::$http[0]['args']['body'], true );

		$this->assertArrayHasKey( Schedule::HOOK_RETRY, WpStubs::$cron );
		$this->assertSame( $sent['envelope_id'], get_transient( Transport::TRANSIENT_RETRY_ID ) );
		$this->assertSame( 500, Counters::snapshot()['total'], 'contadores não podem zerar sem 2xx' );
		$this->assertSame( 'error', Options::telemetry()['last_status'] );

		// A retentativa reusa o id: sem isso, toda falha de rede vira dado duplicado.
		Transport::run_retry();

		$retried = json_decode( WpStubs::$http[1]['args']['body'], true );

		$this->assertSame( $sent['envelope_id'], $retried['envelope_id'] );
	}

	/**
	 * E depois da retentativa falhar, desiste: espera a janela semanal, sem fila.
	 *
	 * @return void
	 */
	public function test_gives_up_after_the_single_retry(): void {
		$this->respond_with( 500 );

		Transport::run();
		WpStubs::$cron = array();

		Transport::run_retry();

		$this->assertArrayNotHasKey( Schedule::HOOK_RETRY, WpStubs::$cron );
		$this->assertFalse( get_transient( Transport::TRANSIENT_RETRY_ID ) );
	}

	/**
	 * `WP_Error` (timeout, DNS) também rende uma retentativa.
	 *
	 * @return void
	 */
	public function test_wp_error_schedules_a_retry(): void {
		// Sem filtro `pre_http_request`, o stub devolve WP_Error.
		Transport::run();

		$this->assertArrayHasKey( Schedule::HOOK_RETRY, WpStubs::$cron );
		$this->assertSame( 'error', Options::telemetry()['last_status'] );
	}

	/**
	 * 429: respeita `Retry-After`.
	 *
	 * @return void
	 */
	public function test_429_respects_retry_after(): void {
		$this->respond_with( 429, '{}', array( 'Retry-After' => '3600' ) );

		$before = time();

		Transport::run();

		$scheduled = WpStubs::$cron[ Schedule::HOOK_RETRY ][0]['timestamp'];

		$this->assertGreaterThanOrEqual( $before + 3600, $scheduled );
		$this->assertLessThan( $before + 3610, $scheduled );
	}

	/**
	 * 400: desiste, não retenta, e grava o motivo. Payload malformado não melhora
	 * repetindo — e insistir contra um 400 é como um cliente mal escrito vira abuso.
	 *
	 * @return void
	 */
	public function test_400_does_not_retry_and_records_the_reason(): void {
		$this->seed_counters();
		$this->respond_with( 400, '{"error":"pii_suspected"}' );

		Transport::run();

		$this->assertArrayNotHasKey( Schedule::HOOK_RETRY, WpStubs::$cron );
		$this->assertSame( 'pii_suspected', Options::telemetry()['last_status'] );
		$this->assertSame( 500, Counters::snapshot()['total'] );
	}

	/**
	 * O corpo desconhecido não vira `last_status` arbitrário: o vocabulário é fechado.
	 *
	 * Uma API de telemetria que devolve instrução ao cliente é uma API que pode alterar o
	 * comportamento do site de terceiro à distância. Este desenho recusa a superfície.
	 *
	 * @return void
	 */
	public function test_unknown_error_body_is_not_trusted(): void {
		$this->respond_with( 400, '{"error":"<script>alert(1)</script>"}' );

		Transport::run();

		$this->assertSame( 'error', Options::telemetry()['last_status'] );
	}

	/**
	 * Fora de cron, nada acontece. Nenhum envio jamais parte de um request de formulário.
	 *
	 * @return void
	 */
	public function test_never_sends_outside_cron(): void {
		WpStubs::$env['doing_cron'] = false;
		WpStubs::$env['doing_ajax'] = true;

		$this->respond_with( 202 );

		Transport::run();

		$this->assertSame( array(), WpStubs::$http );
	}

	/**
	 * Opt-out no meio: o cron que disparar depois aborta em silêncio.
	 *
	 * @return void
	 */
	public function test_aborts_after_opt_out(): void {
		Consent::revoke();

		$this->respond_with( 202 );

		Transport::run();

		$this->assertSame( array(), WpStubs::$http );
		$this->assertArrayNotHasKey( Schedule::HOOK, WpStubs::$cron );
	}

	/**
	 * O handler não está registrado em nenhum hook de submissão, e `assess()` nunca
	 * dispara rede de telemetria.
	 *
	 * @return void
	 */
	public function test_assess_never_triggers_telemetry_network(): void {
		$this->respond_with( 202 );

		$all                       = Options::all();
		$all['secret_key']         = 'secret';
		$all['forms']['wp_login']  = array_merge( Options::form_defaults(), array( 'enabled' => true ) );
		Options::update( $all );

		$gate = new Gate( new FakeProvider( new ProviderResponse( true, true, null, 0.9, 'wrf_wp_login', 'example.test' ) ) );
		$gate->assess( new FormContext( 'wp_login', 'login', 'token-1' ) );

		do_action( 'shutdown' );

		$this->assertSame( array(), WpStubs::$http, 'assess() não pode fazer rede de telemetria' );

		foreach ( array( 'wp_authenticate', 'preprocess_comment', 'register_post', 'lostpassword_post', 'woocommerce_checkout_process' ) as $submission_hook ) {
			$this->assertArrayNotHasKey( $submission_hook, WpStubs::$filters );
		}
	}

	/**
	 * NUNCA admin notice por falha de telemetria. A API do autor estar fora do ar não é
	 * problema do operador. Contraste deliberado com MISCONFIG (v1.1 §4.3).
	 *
	 * @return void
	 */
	public function test_never_registers_an_admin_notice(): void {
		$this->respond_with( 500 );

		Transport::run();
		Transport::run_retry();

		$this->assertArrayNotHasKey( 'admin_notices', WpStubs::$filters );
		$this->assertArrayNotHasKey( 'network_admin_notices', WpStubs::$filters );
	}

	/**
	 * NUNCA desliga a proteção por falha de telemetria: o envio não cruza o `Gate` em
	 * ponto nenhum.
	 *
	 * @return void
	 */
	public function test_total_transport_failure_leaves_the_gate_intact(): void {
		$all                      = Options::all();
		$all['secret_key']        = 'secret';
		$all['forms']['wp_login'] = array_merge( Options::form_defaults(), array( 'enabled' => true ) );
		Options::update( $all );

		// Falha total: sem filtro, o stub devolve WP_Error nas duas tentativas.
		Transport::run();
		Transport::run_retry();

		$gate    = new Gate( new FakeProvider( new ProviderResponse( true, true, null, 0.9, 'wrf_wp_login', 'example.test' ) ) );
		$allowed = $gate->assess( new FormContext( 'wp_login', 'login', 'token-1' ) );
		$blocked = $gate->assess( new FormContext( 'wp_login', 'login', '' ) );

		$this->assertTrue( $allowed->is_allowed() );
		$this->assertTrue( $blocked->is_blocked(), 'a proteção continua bloqueando token vazio' );
	}

	/**
	 * O schedule semanal é registrado com sete dias.
	 *
	 * @return void
	 */
	public function test_weekly_schedule_is_registered(): void {
		$schedules = apply_filters( 'cron_schedules', array() );

		$this->assertArrayHasKey( Schedule::RECURRENCE, $schedules );
		$this->assertSame( 7 * DAY_IN_SECONDS, $schedules[ Schedule::RECURRENCE ]['interval'] );
	}

	/**
	 * O endpoint está numa constante única e é filtrável para o ambiente de teste do
	 * autor.
	 *
	 * @return void
	 */
	public function test_endpoint_is_filterable(): void {
		add_filter(
			'wp_recaptcha_forms_telemetry_endpoint',
			static function () {
				return 'https://exemplo.test/ingest';
			}
		);

		$this->respond_with( 202 );

		Transport::run();

		$this->assertSame( 'https://exemplo.test/ingest', WpStubs::$http[0]['url'] );
	}
}
