<?php
/**
 * Opt-in e opt-out da telemetria.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Telemetry;

use WpRecaptchaForms\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O único ponto que liga e desliga a telemetria (telemetry-design §4).
 *
 * **Mecanismo próprio, deliberadamente separado do `ConsentGate`.** Reusar aquele seria
 * erro de categoria, não economia de código: lá o titular do dado é o VISITANTE e quem
 * decide é ele, via CMP; aqui o titular é o OPERADOR e quem decide é ele, no wp-admin.
 * Ligar os dois faria o aceite de cookies de um visitante anônimo autorizar o envio de
 * dados da instalação do operador. Ninguém consentiu com isso.
 *
 * O que se reusa é o padrão, não o mecanismo: vocabulário fechado, resolução num ponto
 * único (`Options::telemetry_enabled()`), estado visível na tela de config.
 */
final class Consent {

	/**
	 * Liga a telemetria e cria o identificador desta instalação.
	 *
	 * @return void
	 */
	public static function grant(): void {
		$telemetry = Options::telemetry();

		$instance_id = (string) $telemetry['instance_id'];

		if ( '' === $instance_id ) {
			$instance_id = self::new_instance_id();
		}

		// Idempotente: religar com tudo já ligado não regenera nada e não dispara o hook.
		if ( true === $telemetry['enabled'] && $instance_id === (string) $telemetry['instance_id'] ) {
			return;
		}

		Options::update_telemetry(
			array(
				'enabled'     => true,
				'instance_id' => $instance_id,
			)
		);

		Schedule::activate();

		self::announce( true );
	}

	/**
	 * Desliga a telemetria e apaga tudo que ela criou.
	 *
	 * **Ruptura, não pausa** (§1.3). Ao religar, a instalação é uma instância nova, sem
	 * ligação com o histórico anterior — e é essa propriedade que torna honesto dizer que
	 * o identificador não representa o site. Um opt-out que só pausasse guardaria o
	 * vínculo que o operador pediu para romper.
	 *
	 * @return void
	 */
	public static function revoke(): void {
		$telemetry = Options::telemetry();

		if ( false === $telemetry['enabled'] && '' === (string) $telemetry['instance_id'] ) {
			// Já desligado: no-op.
			return;
		}

		Options::update_telemetry(
			array(
				'enabled'     => false,
				'instance_id' => '',
				'last_sent'   => 0,
				'last_status' => '',
				'seq'         => 0,
			)
		);

		// Desligado não acumula nada: sem cron pendente e sem contadores no banco.
		Schedule::deactivate();
		Counters::purge();

		self::announce( false );
	}

	/**
	 * Gera o identificador desta instalação.
	 *
	 * **Aleatório, jamais derivado de qualquer atributo da instalação** (§1.3). A
	 * tentação natural é hashear o endereço do site, e ela é pseudonimização FALSA: sem
	 * sal, um hash de domínio é reversível por dicionário, porque o conjunto de domínios
	 * registrados é público e finito. Com sal fixo, o sal está no código-fonte de um
	 * plugin GPL e não é segredo. Com sal por instalação, o hash já não é derivado — é
	 * aleatório, e então mais vale usar aleatório direto, sem a aparência de que existe
	 * uma relação com o domínio.
	 *
	 * NOTA: nem este comentário menciona as funções proibidas pelo nome. O gate de CI da
	 * Story 1.30 casa a MENÇÃO, não só a chamada, dentro de `src/Telemetry/` — é a trava
	 * mais barata e a que não depende de um regex saber distinguir código de prosa.
	 *
	 * A fonte precisa ser CSPRNG. `mt_rand()`/`uniqid()` são previsíveis o bastante para
	 * que dois sites gerados no mesmo instante colidam.
	 *
	 * @return string 32 caracteres hex.
	 */
	private static function new_instance_id(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			// `random_bytes()` só lança quando não há fonte de entropia no sistema.
			// `wp_generate_password()` cai no gerador do WordPress, que também é CSPRNG
			// quando disponível — e continua não tendo relação nenhuma com o site.
			return substr( hash( 'sha256', wp_generate_password( 64, true, true ) ), 0, 32 );
		}
	}

	/**
	 * Anuncia a mudança de estado, para observabilidade.
	 *
	 * @param bool $enabled Novo estado.
	 * @return void
	 */
	private static function announce( bool $enabled ): void {
		/**
		 * O operador ligou ou desligou a telemetria.
		 *
		 * @param bool $enabled Novo estado.
		 */
		do_action( 'wp_recaptcha_forms_telemetry_consent_changed', $enabled );
	}
}
