<?php
/**
 * Envio do envelope e tratamento de falha.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Telemetry;

use WpRecaptchaForms\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O envio (telemetry-design §6.2, §6.3).
 *
 * Duas regras governam este arquivo inteiro, e as duas são sobre o site do OPERADOR, não
 * sobre o dado:
 *
 * 1. **Nenhum envio parte do request de um formulário.** Só de cron. Um POST de checkout
 *    esperando a API do autor responder é um checkout que o autor pode derrubar.
 * 2. **Uma falha minha nunca vira problema dele.** Sem admin notice, sem fila que cresça
 *    no banco dele, e sem tocar no `Gate` em ponto nenhum. Se a API ficar fora do ar por
 *    semanas, o efeito observável na instalação é exatamente nenhum.
 */
final class Transport {

	/** Transiente com o `envelope_id` do envio que falhou, para o retry. */
	const TRANSIENT_RETRY_ID = 'wp_recaptcha_forms_telemetry_envelope';

	/** Janela do retry padrão (§6.3). */
	const RETRY_DELAY = HOUR_IN_SECONDS;

	/**
	 * Registra os hooks de cron.
	 *
	 * Chamado só quando a telemetria está ligada. Note que NENHUM hook de submissão
	 * aparece aqui — é o que torna a regra 1 verificável em vez de prometida.
	 *
	 * O schedule customizado em si é registrado pelo `Plugin`, incondicionalmente: ele
	 * precisa existir ANTES do primeiro opt-in, senão `wp_schedule_event()` recusa a
	 * recorrência e a instalação fica ligada sem nunca enviar.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( Schedule::HOOK, array( __CLASS__, 'run' ) );
		add_action( Schedule::HOOK_RETRY, array( __CLASS__, 'run_retry' ) );

		/*
		 * Cura de agendamento perdido. O evento pode sumir por desativação/reativação do
		 * plugin, por um `wp cron event delete`, ou por um opt-in que aconteceu antes
		 * desta correção. Sem isto, a telemetria fica ligada na tela e muda para sempre.
		 * `activate()` é no-op quando já existe evento.
		 */
		Schedule::activate();
	}

	/**
	 * Handler do envio semanal.
	 *
	 * @return void
	 */
	public static function run(): void {
		self::dispatch( null );
	}

	/**
	 * Handler da retentativa. Reusa o `envelope_id` que falhou.
	 *
	 * @return void
	 */
	public static function run_retry(): void {
		$previous = get_transient( self::TRANSIENT_RETRY_ID );

		self::dispatch( is_string( $previous ) && '' !== $previous ? $previous : null, true );
	}

	/**
	 * Monta e envia.
	 *
	 * @param string|null $envelope_id `envelope_id` a reusar (retry).
	 * @param bool        $is_retry    Se já é a retentativa — não agenda outra.
	 * @return void
	 */
	private static function dispatch( ?string $envelope_id, bool $is_retry = false ): void {
		/*
		 * Guarda de contexto: só cron REAL. `wp_doing_cron()`, nunca `wp_doing_ajax()`.
		 * Em site com DISABLE_WP_CRON e cron de sistema isto continua verdadeiro. Em site
		 * sem tráfego o WP-Cron não dispara — e uma instalação sem tráfego não tem dado
		 * interessante; o buraco aparece no `event.seq` do lado do servidor.
		 */
		if ( ! wp_doing_cron() ) {
			return;
		}

		/*
		 * Guarda de estado: cobre a corrida "o cron disparou depois do opt-out".
		 * Aborta em silêncio, sem reagendar fora do ciclo.
		 */
		if ( ! Options::telemetry_enabled() ) {
			return;
		}

		$envelope = Envelope::build( $envelope_id );

		if ( array() === $envelope ) {
			// O PiiGuard recusou. É bug do autor do plugin, não do operador: registra o
			// diagnóstico local e desiste. Repetir não melhora um payload malformado.
			self::record_status( 'pii_suspected' );

			return;
		}

		$response = self::send( $envelope );

		self::handle( $response, $envelope['envelope_id'], $is_retry );
	}

	/**
	 * Faz a requisição.
	 *
	 * @param array $envelope Envelope.
	 * @return array|\WP_Error Resposta do WordPress.
	 */
	public static function send( array $envelope ) {
		return wp_remote_post(
			Endpoints::ingest(),
			array(
				// É cron; ninguém está esperando. Mais que isso prenderia um worker de
				// cron do operador por causa da API do autor.
				'timeout'     => 5,
				// Redirect em endpoint de telemetria é ou erro meu ou sequestro de DNS.
				// Não seguir é a diferença entre falhar e entregar o payload noutro lugar.
				'redirection' => 0,
				// `false` seria fire-and-forget puro, mas impede saber se funcionou — e
				// sem isso não há como decidir retry nem preencher `last_status`.
				'blocking'    => true,
				// NUNCA desligar, nem "temporariamente".
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type'    => 'application/json',

					/*
					 * User-Agent SEM o endereço do site. O default do WordPress inclui a
					 * URL da instalação — é o vazamento mais fácil de cometer no desenho
					 * inteiro, e o motivo do gate de CI da Story 1.30. Isto é uma
					 * constante concatenada, não uma chamada de função.
					 */
					'User-Agent'      => 'wp-recaptcha-forms/' . WP_RECAPTCHA_FORMS_VERSION,
					'Idempotency-Key' => $envelope['envelope_id'],
				),
				'body'        => wp_json_encode( $envelope ),
			)
		);
	}

	/**
	 * Máquina de resposta do §6.3.
	 *
	 * @param array|\WP_Error $response    Resposta.
	 * @param string          $envelope_id Id enviado.
	 * @param bool            $is_retry    Se já era a retentativa.
	 * @return void
	 */
	private static function handle( $response, string $envelope_id, bool $is_retry ): void {
		if ( is_wp_error( $response ) ) {
			self::maybe_retry( $envelope_id, $is_retry, self::RETRY_DELAY );

			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			self::succeed();

			return;
		}

		if ( 429 === $code ) {
			$after = (int) wp_remote_retrieve_header( $response, 'retry-after' );

			self::maybe_retry( $envelope_id, $is_retry, $after > 0 ? $after : self::RETRY_DELAY );

			return;
		}

		if ( $code >= 400 && $code < 500 ) {
			/*
			 * 4xx: desiste e NÃO retenta. Payload malformado não melhora repetindo, e
			 * insistir contra um 400 é como um cliente mal escrito vira abuso.
			 */
			$error = self::error_code( $response );

			self::record_status( '' !== $error ? $error : 'error' );

			if ( 'pii_suspected' === $error && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Bug do autor do plugin: o envelope saiu com aparência de PII apesar do
				// PiiGuard local. Tem de aparecer no log de quem depura.
				error_log( 'wp-recaptcha-forms: a API recusou o envelope de telemetria por suspeita de PII. Isto é bug do plugin.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return;
		}

		// 5xx e qualquer outra coisa: uma retentativa e pronto.
		self::maybe_retry( $envelope_id, $is_retry, self::RETRY_DELAY );
	}

	/**
	 * 2xx: a janela fechou.
	 *
	 * @return void
	 */
	private static function succeed(): void {
		$telemetry = Options::telemetry();

		Options::update_telemetry(
			array(
				'seq'         => (int) $telemetry['seq'] + 1,
				'last_sent'   => time(),
				'last_status' => 'ok',
			)
		);

		// Só aqui, e só depois do 2xx (§6.3).
		Counters::reset();

		delete_transient( self::TRANSIENT_RETRY_ID );
	}

	/**
	 * Agenda UMA retentativa, com o mesmo `envelope_id`.
	 *
	 * Sem fila, sem acúmulo, sem crescer nada no banco do operador: se a retentativa
	 * também falhar, o envio espera a próxima janela semanal. Os contadores continuam
	 * acumulando e `event.window.from` declara a janela real.
	 *
	 * @param string $envelope_id Id a reusar.
	 * @param bool   $is_retry    Se já era a retentativa.
	 * @param int    $delay       Segundos até a retentativa.
	 * @return void
	 */
	private static function maybe_retry( string $envelope_id, bool $is_retry, int $delay ): void {
		self::record_status( 'error' );

		if ( $is_retry ) {
			// Já foi a segunda tentativa. Desiste e espera a janela semanal.
			delete_transient( self::TRANSIENT_RETRY_ID );

			return;
		}

		// A idempotência da API (§1.2) depende de o retry usar o MESMO envelope_id: sem
		// isso, toda falha de rede vira dado duplicado, e a série temporal mente para cima
		// justamente quando a rede está ruim.
		set_transient( self::TRANSIENT_RETRY_ID, $envelope_id, 2 * $delay );

		wp_schedule_single_event( time() + $delay, Schedule::HOOK_RETRY );
	}

	/**
	 * Grava o diagnóstico local.
	 *
	 * `last_status` é consumido pela tela de config e por mais nada. **Nunca** vira admin
	 * notice: a API do autor estar fora do ar não é problema do operador, e um aviso
	 * amarelo no painel dele seria transformar o incidente do autor na ansiedade dele.
	 * Contraste deliberado com MISCONFIG (v1.1 §4.3), que é notice não-dispensável
	 * porque É problema dele.
	 *
	 * @param string $status Status.
	 * @return void
	 */
	private static function record_status( string $status ): void {
		Options::update_telemetry( array( 'last_status' => $status ) );
	}

	/**
	 * Lê o campo `error` do corpo da resposta.
	 *
	 * O cliente ignora o resto do corpo em operação normal: uma API de telemetria que
	 * devolve instrução ao cliente é uma API que pode alterar o comportamento do site de
	 * terceiro à distância, e este desenho recusa essa superfície de propósito.
	 *
	 * @param array $response Resposta.
	 * @return string
	 */
	private static function error_code( array $response ): string {
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['error'] ) || ! is_string( $body['error'] ) ) {
			return '';
		}

		$known = array( 'schema_unknown', 'pii_suspected', 'payload_too_large' );

		return in_array( $body['error'], $known, true ) ? $body['error'] : '';
	}
}
