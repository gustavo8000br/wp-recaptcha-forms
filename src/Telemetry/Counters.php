<?php
/**
 * Contadores agregados que alimentam o payload semanal.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Telemetry;

use WpRecaptchaForms\Gate\FormContext;
use WpRecaptchaForms\Gate\Verdict;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\FailureClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O único ponto do plugin que toca o caminho quente de submissão por causa de telemetria
 * (telemetry-design §3.3).
 *
 * Duas mitigações são o motivo desta classe existir em vez de um `update_option()` solto
 * dentro do `Gate`:
 *
 * 1. **Custo zero para quem não optou.** Sem telemetria ligada, `boot()` nem registra o
 *    listener: `assess()` não cria acumulador, não lê e não escreve a opção.
 * 2. **Uma escrita por request, não uma por avaliação.** O acumulador vive em memória e é
 *    persistido uma única vez em `shutdown`. Um request que avalia cinco formulários faz
 *    UMA escrita. Sob ataque — que é a mesma janela em que o dado fica interessante — a
 *    amplificação de escrita fica limitada a 1 por request, e a estrutura da opção é
 *    limitada por construção (5 classes + até 9 `form_id`).
 *
 * "Não há tabela nova no banco" continua verdade: isto é uma `option` não-autoloaded.
 */
final class Counters {

	/**
	 * Acumulador do request. `null` = nada foi contado ainda.
	 *
	 * @var array|null
	 */
	private static $pending = null;

	/**
	 * O flush já foi agendado neste request?
	 *
	 * @var bool
	 */
	private static $flush_hooked = false;

	/**
	 * Liga a coleta.
	 *
	 * Chamado pelo `Plugin` só quando `Options::telemetry_enabled()` é `true`.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'wp_recaptcha_forms_assessed', array( __CLASS__, 'record' ), 10, 2 );
	}

	/**
	 * Contabiliza uma avaliação.
	 *
	 * Escuta `wp_recaptcha_forms_assessed`, que o `Gate` dispara em `remember()` — ou
	 * seja, uma vez por submissão avaliada. A submissão memoizada (mesmo token, segundo
	 * hook do mesmo request) retorna antes do `remember()` e portanto não é recontada.
	 *
	 * @param Verdict     $verdict Veredito.
	 * @param FormContext $context Contexto.
	 * @return void
	 */
	public static function record( Verdict $verdict, FormContext $context ): void {
		// Guarda redundante com o boot, de propósito: um opt-out no meio do request não
		// pode deixar uma escrita órfã em shutdown.
		if ( ! Options::telemetry_enabled() ) {
			return;
		}

		if ( null === self::$pending ) {
			self::$pending = self::empty_accumulator();
		}

		$failure = $verdict->failure();
		$bucket  = FailureClass::is_valid( $failure ) ? $failure : 'allowed';

		++self::$pending['total'];
		++self::$pending['by_class'][ $bucket ];

		if ( $verdict->is_blocked() ) {
			++self::$pending['blocked'];
		}

		/*
		 * `form_id` vem do vocabulário fechado do FormContext (nove valores, todos
		 * definidos por nós). Nunca um rótulo livre do tema ou de plugin de terceiro —
		 * é o que impede o `by_form` de virar dado do site.
		 */
		$form_id = $context->form_id();

		if ( ! isset( self::$pending['by_form'][ $form_id ] ) ) {
			self::$pending['by_form'][ $form_id ] = array(
				'total'              => 0,
				'client_unreachable' => 0,
			);
		}

		++self::$pending['by_form'][ $form_id ]['total'];

		if ( FailureClass::CLIENT_UNREACHABLE === $bucket ) {
			++self::$pending['by_form'][ $form_id ]['client_unreachable'];
		}

		if ( ! self::$flush_hooked ) {
			self::$flush_hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 100 );
		}
	}

	/**
	 * Persiste o acumulado do request na opção, somando ao que já estava lá.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( null === self::$pending || 0 === self::$pending['total'] ) {
			return;
		}

		$stored = self::stored();

		if ( 0 === (int) $stored['window_from'] ) {
			// Primeira contagem depois de um reset carimba o início da janela. Não muda
			// até o próximo reset: é o `event.window.from` do envelope (§1.2, §6.3).
			$stored['window_from'] = time();
		}

		$stored['total']   += self::$pending['total'];
		$stored['blocked'] += self::$pending['blocked'];

		foreach ( self::$pending['by_class'] as $class => $count ) {
			$stored['by_class'][ $class ] = (int) ( $stored['by_class'][ $class ] ?? 0 ) + $count;
		}

		foreach ( self::$pending['by_form'] as $form_id => $counts ) {
			$stored['by_form'][ $form_id ]['total']              = (int) ( $stored['by_form'][ $form_id ]['total'] ?? 0 ) + $counts['total'];
			$stored['by_form'][ $form_id ]['client_unreachable'] = (int) ( $stored['by_form'][ $form_id ]['client_unreachable'] ?? 0 ) + $counts['client_unreachable'];
		}

		self::$pending = null;

		self::write( $stored );
	}

	/**
	 * Estado gravado, já normalizado.
	 *
	 * @return array
	 */
	public static function snapshot(): array {
		return self::stored();
	}

	/**
	 * Zera os contadores e recarimba a janela.
	 *
	 * Chamado APENAS pelo `Transport` depois de um 2xx (§6.3). Se o envio da semana
	 * falhou, os contadores da semana seguinte incluem a anterior e `event.window.from`
	 * diz a verdade sobre a janela — janela declarada resolve, fila não é necessária.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$pending = null;

		$fresh                = self::empty_accumulator();
		$fresh['window_from'] = time();

		self::write( $fresh );
	}

	/**
	 * Apaga a opção inteira. Usado no opt-out: desligado não acumula nada.
	 *
	 * @return void
	 */
	public static function purge(): void {
		self::$pending = null;

		delete_option( Options::OPTION_TELEMETRY_COUNTERS );
	}

	/**
	 * Descarta o acumulador em memória. Só para testes.
	 *
	 * @return void
	 */
	public static function reset_runtime(): void {
		self::$pending      = null;
		self::$flush_hooked = false;
	}

	/**
	 * Lê e normaliza a opção.
	 *
	 * @return array
	 */
	private static function stored(): array {
		$stored = get_option( Options::OPTION_TELEMETRY_COUNTERS, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$base = self::empty_accumulator();

		$stored['window_from'] = (int) ( $stored['window_from'] ?? 0 );
		$stored['total']       = (int) ( $stored['total'] ?? 0 );
		$stored['blocked']     = (int) ( $stored['blocked'] ?? 0 );
		$stored['by_class']    = array_merge(
			$base['by_class'],
			array_intersect_key( is_array( $stored['by_class'] ?? null ) ? $stored['by_class'] : array(), $base['by_class'] )
		);
		$stored['by_form']     = is_array( $stored['by_form'] ?? null ) ? $stored['by_form'] : array();

		return $stored;
	}

	/**
	 * Grava a opção com `autoload = 'no'`.
	 *
	 * `update_option()` com o quarto parâmetro só define o autoload na CRIAÇÃO da linha;
	 * numa linha já existente ele o atualiza a partir do WP 6.4 e é ignorado antes disso.
	 * Por isso a criação passa por `add_option()` explícito — é o caminho em que o
	 * `autoload = 'no'` vale em todo o range de compatibilidade do plugin.
	 *
	 * @param array $values Valores.
	 * @return void
	 */
	private static function write( array $values ): void {
		if ( false === get_option( Options::OPTION_TELEMETRY_COUNTERS, false ) ) {
			add_option( Options::OPTION_TELEMETRY_COUNTERS, $values, '', 'no' );

			return;
		}

		update_option( Options::OPTION_TELEMETRY_COUNTERS, $values, 'no' );
	}

	/**
	 * Forma canônica dos contadores.
	 *
	 * `blocked` é o único campo além do que o desenho §3.3 lista, e existe porque
	 * `blocked_share` do payload (§3) não é derivável de `by_class`: sob fail-open,
	 * `infra`/`client_unreachable` contam na classe e mesmo assim o envio passou.
	 * Sem este contador, `blocked_share` seria um chute.
	 *
	 * @return array
	 */
	private static function empty_accumulator(): array {
		return array(
			'window_from' => 0,
			'total'       => 0,
			'blocked'     => 0,
			'by_class'    => array(
				'allowed'                        => 0,
				FailureClass::REJECTED           => 0,
				FailureClass::INFRA              => 0,
				FailureClass::MISCONFIG          => 0,
				FailureClass::CLIENT_UNREACHABLE => 0,
			),
			'by_form'     => array(),
		);
	}
}
