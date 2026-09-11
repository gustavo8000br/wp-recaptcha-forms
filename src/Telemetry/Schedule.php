<?php
/**
 * Agendamento do envio semanal.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Telemetry;

use WpRecaptchaForms\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quando o envio acontece (telemetry-design §6.1).
 *
 * **WP-Cron, semanal, nunca no caminho de uma submissão.** Regra absoluta: um POST de
 * checkout esperando a API de telemetria responder é um checkout que o autor do plugin
 * pode derrubar, e o plugin existe para não perder venda.
 */
final class Schedule {

	/** Evento cron do envio semanal. */
	const HOOK = 'wp_recaptcha_forms_telemetry_send';

	/** Evento cron da retentativa única (§6.3). */
	const HOOK_RETRY = 'wp_recaptcha_forms_telemetry_retry';

	/** Nome do schedule customizado. */
	const RECURRENCE = 'wp_recaptcha_forms_weekly';

	/**
	 * Registra o schedule customizado.
	 *
	 * `weekly` não é garantido em todo o range de compatibilidade do plugin (WP 6.0+),
	 * então o intervalo é declarado por nós em vez de assumido.
	 *
	 * @param mixed $schedules Schedules registrados.
	 * @return array
	 */
	public static function register( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		$schedules[ self::RECURRENCE ] = array(
			'interval' => 7 * DAY_IN_SECONDS,
			'display'  => 'WP reCAPTCHA Forms — semanal',
		);

		return $schedules;
	}

	/**
	 * Agenda o envio semanal com jitter.
	 *
	 * O jitter não é refinamento: um evento "toda segunda às 00:00" faz a base inteira
	 * bater na API no mesmo minuto — DDoS acidental por design. O offset vem dos quatro
	 * primeiros hex do `instance_id`: determinístico por instalação (o mesmo site sempre
	 * cai no mesmo ponto da semana), uniformemente espalhado pela base, e sem uma linha
	 * de estado a mais no banco.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$instance_id = Options::telemetry_instance_id();

		if ( '' === $instance_id ) {
			return;
		}

		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$offset = hexdec( substr( $instance_id, 0, 4 ) ) % ( 7 * DAY_IN_SECONDS );

		wp_schedule_event( time() + $offset, self::RECURRENCE, self::HOOK );
	}

	/**
	 * Agenda um envio único, para "agora" (v1.3, pedido do dono).
	 *
	 * **Continua sendo cron, não um envio síncrono no request do opt-in** — a regra do
	 * `Transport` ("nenhum envio parte de um request de submissão") não fala do request
	 * de opt-in, mas manter TODO envio atrás de `wp_doing_cron()` é a única forma de a
	 * regra continuar verificável em código em vez de virar exceção decorada por
	 * comentário. `wp_schedule_single_event()` para "agora" faz o WP-Cron pseudo-cron
	 * disparar no próximo acesso ao site — na prática, imediato, sem bloquear o
	 * `admin-post` do save.
	 *
	 * Chamado só por `Consent::grant()`, na transição real de opt-in — nunca pela cura de
	 * agendamento perdido do `Transport::boot()`, que roda em toda request com telemetria
	 * ligada e reagendaria um envio "imediato" a cada request se chamasse isto também.
	 *
	 * @return void
	 */
	public static function send_now(): void {
		wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * Desagenda tudo. Chamado no opt-out.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK_RETRY );
	}

	/**
	 * O offset de jitter de um `instance_id`. Exposto para teste e diagnóstico.
	 *
	 * @param string $instance_id Identificador.
	 * @return int
	 */
	public static function offset_for( string $instance_id ): int {
		if ( '' === $instance_id ) {
			return 0;
		}

		return (int) ( hexdec( substr( $instance_id, 0, 4 ) ) % ( 7 * DAY_IN_SECONDS ) );
	}
}
