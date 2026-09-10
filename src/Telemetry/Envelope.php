<?php
/**
 * Montagem do envelope e do payload.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Telemetry;

use WpRecaptchaForms\Consent\ConsentGate;
use WpRecaptchaForms\Integrations\WooCommerce\Support;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\FailureClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produz o JSON exato do envio (telemetry-design §1.1, §3).
 *
 * **É puro.** Lê estado, devolve array. Não faz rede, não escreve opção, não incrementa
 * `seq`. É essa propriedade que permite à tela de config chamar `build()` com o toggle
 * DESLIGADO para mostrar "o que será enviado" sem nenhum efeito colateral — e o botão de
 * pré-visualização é, segundo o desenho §4.2, o item que mais importa daquela tela.
 * Transparência verificável vale mais que qualquer parágrafo de política.
 *
 * O mesmo código gera o que a tela mostra e o que o cron envia. Um exemplo escrito à mão
 * envelhece e passa a mentir; este não pode.
 */
final class Envelope {

	/** Versão do envelope genérico. */
	const SCHEMA = 'gm.telemetry/v1';

	/** Versão do payload deste projeto, independente do envelope (§1.1). */
	const PAYLOAD_SCHEMA = 'wp-recaptcha-forms/usage.snapshot/v1';

	/** Slug estável do software. Não muda com rebranding. */
	const PROJECT = 'wp-recaptcha-forms';

	/** Mínimo de avaliações para um formulário aparecer em `by_form` (§3.1). */
	const BY_FORM_MIN_SAMPLE = 50;

	/**
	 * Vocabulário FECHADO de `form_id`.
	 *
	 * Declarado aqui, e não lido do `Registry`, de propósito: o que entra no payload tem
	 * de ser um valor que nós definimos, nunca um rótulo que veio da instalação. O
	 * `Registry` aceita registro por filtro de terceiro (`wp_recaptcha_forms_register_integrations`),
	 * e um plugin de terceiro poderia registrar um id derivado do nome do site. Esta lista
	 * é a interseção que impede isso.
	 *
	 * @var string[]
	 */
	const FORM_IDS = array(
		'wp_comment',
		'wp_login',
		'wp_register',
		'wp_lostpassword',
		'newsletter_subscribe',
		'woocommerce_checkout',
		'woocommerce_review',
		'woocommerce_lostpassword',
	);

	/**
	 * Monta o envelope completo.
	 *
	 * @param string|null $envelope_id Reusado no retry (§1.2 — idempotência). `null` gera
	 *                                 um novo, que é o caso da pré-visualização.
	 * @return array Envelope, ou array vazio se o `PiiGuard` recusou.
	 */
	public static function build( ?string $envelope_id = null ): array {
		$now       = time();
		$telemetry = Options::telemetry();
		$counters  = Counters::snapshot();

		$window_from = (int) $counters['window_from'];

		if ( 0 === $window_from ) {
			// Nunca contou. Janela é sempre declarada: sem ela, taxa não é calculável e o
			// número não significa nada (§1.2).
			$window_from = $now - ( 7 * DAY_IN_SECONDS );
		}

		$envelope = array(
			'schema'         => self::SCHEMA,
			'envelope_id'    => null !== $envelope_id && '' !== $envelope_id ? $envelope_id : wp_generate_uuid4(),
			'sent_at'        => self::iso( $now ),
			'source'         => array(
				'project'     => self::PROJECT,
				// phpcs:ignore WordPress.WP.CapitalPDangit --  enum fechado do envelope (§1.2): wordpress|node|cli|browser|worker|other. Minúsculo por especificação; `phpcbf` já tentou "corrigir" isto uma vez.
				'platform'    => 'wordpress',
				'version'     => WP_RECAPTCHA_FORMS_VERSION,
				'instance_id' => $telemetry['instance_id'],
			),
			'environment'    => self::environment(),
			'event'          => array(
				'type'        => 'usage.snapshot',
				'occurred_at' => self::iso( $now ),
				'window'      => array(
					'from' => self::iso( $window_from ),
					'to'   => self::iso( $now ),
				),
				// O `seq` do PRÓXIMO envio. O incremento só acontece pós-2xx, no
				// Transport — build() é puro.
				'seq'         => (int) $telemetry['seq'] + 1,
			),
			'payload_schema' => self::PAYLOAD_SCHEMA,
			'payload'        => array(
				'plugin'   => self::plugin_block(),
				'host'     => self::host_block(),
				'verdicts' => self::verdicts_block( $counters ),
				'health'   => self::health_block( $now ),
			),
		);

		if ( '' !== PiiGuard::assert_clean( $envelope ) ) {
			return array();
		}

		return $envelope;
	}

	/**
	 * Bloco `plugin`: qual configuração as pessoas realmente usam.
	 *
	 * @return array
	 */
	private static function plugin_block(): array {
		$enabled = array();

		foreach ( self::FORM_IDS as $form_id ) {
			if ( Options::form_enabled( $form_id ) ) {
				$enabled[] = $form_id;
			}
		}

		return array(
			'provider'                  => Options::version(),
			// Balde, nunca o float. Um score não é sensível, mas o balde já responde a
			// pergunta ("as pessoas mexem no default?") e mantém a disciplina de não
			// coletar precisão que não se usa (§3.1).
			'threshold_bucket'          => self::threshold_bucket( Options::threshold() ),
			'remoteip'                  => Options::send_remote_ip(),
			'consent_mode'              => ConsentGate::mode(),
			'policy_infra'              => (string) Options::get( 'failure_policy_infra', 'allow' ),
			'policy_client_unreachable' => (string) Options::get( 'failure_policy_client_unreachable', 'allow' ),
			'forms_enabled'             => $enabled,
			'forms_enabled_count'       => count( $enabled ),
			'kill_switch'               => defined( 'WRF_DISABLE' ) && WRF_DISABLE,
		);
	}

	/**
	 * Bloco `host`: o ambiente, em versão MINOR.
	 *
	 * @return array
	 */
	private static function host_block(): array {
		$block = array(
			'php'                         => Version::minor( PHP_VERSION ),
			'wp'                          => Version::minor( self::wp_version() ),
			'mysql'                       => Version::minor( self::db_version() ),
			// `locale` não é URL e não identifica o site — é a exceção explícita citada
			// no gate da Story 1.30.
			'locale'                      => get_locale(),
			'multisite'                   => is_multisite(),
			'woocommerce_blocks_checkout' => Support::blocks_supported(),
		);

		$woo = Version::minor( Support::version() );

		if ( '' !== $woo ) {
			$block['woocommerce'] = $woo;
		}

		return $block;
	}

	/**
	 * Bloco `verdicts`: o núcleo. **Proporções e baldes, jamais contagens.**
	 *
	 * Contagem absoluta de submissões é volume de negócio do operador, e ele não
	 * concordou em compartilhar volume de negócio com o autor do plugin. `total_bucket`
	 * dá a ordem de grandeza necessária para ponderar a proporção sem entregar o número.
	 *
	 * @param array $counters Snapshot dos contadores.
	 * @return array
	 */
	private static function verdicts_block( array $counters ): array {
		$total = (int) $counters['total'];

		$distribution = array();

		foreach ( array( 'allowed', FailureClass::REJECTED, FailureClass::INFRA, FailureClass::MISCONFIG, FailureClass::CLIENT_UNREACHABLE ) as $class ) {
			$distribution[ $class ] = self::share( (int) ( $counters['by_class'][ $class ] ?? 0 ), $total );
		}

		$by_form = array();

		foreach ( (array) $counters['by_form'] as $form_id => $counts ) {
			// Interseção com o vocabulário fechado: nada que venha da instalação passa.
			if ( ! in_array( (string) $form_id, self::FORM_IDS, true ) ) {
				continue;
			}

			$form_total = (int) ( $counts['total'] ?? 0 );

			/*
			 * Corte de amostra (§3.1): sob volume baixo, proporção não é estatística —
			 * é o comportamento de um punhado de visitantes identificáveis.
			 */
			if ( $form_total < self::BY_FORM_MIN_SAMPLE ) {
				continue;
			}

			$by_form[ $form_id ] = array(
				'share_of_total'     => self::share( $form_total, $total ),
				'client_unreachable' => self::share( (int) ( $counts['client_unreachable'] ?? 0 ), $form_total ),
			);
		}

		return array(
			'total_bucket'  => self::total_bucket( $total ),
			'distribution'  => $distribution,
			'blocked_share' => self::share( (int) $counters['blocked'], $total ),
			'by_form'       => $by_form,
		);
	}

	/**
	 * Bloco `health`: o dado que mais provavelmente gera correção de produto.
	 *
	 * `misconfig_days_active > 0` significa que existe instalação rodando degradada há
	 * dias sem que a escalada tenha resolvido — falha de UX do plugin, não do operador.
	 *
	 * @param int $now Agora.
	 * @return array
	 */
	private static function health_block( int $now ): array {
		$since = (int) get_option( Options::OPTION_MISCONFIG_SINCE, 0 );

		return array(
			'misconfig_days_active'     => $since > 0 ? (int) floor( ( $now - $since ) / DAY_IN_SECONDS ) : 0,
			'keypair_suspect_triggered' => (bool) get_option( Options::OPTION_KEYPAIR_SUSPECT, false ),
		);
	}

	/**
	 * Proporção com três casas. Zero quando não há amostra — nunca divisão por zero,
	 * nunca `null` (que a API teria de tratar como caso especial).
	 *
	 * @param int $part  Parte.
	 * @param int $total Total.
	 * @return float
	 */
	private static function share( int $part, int $total ): float {
		if ( $total <= 0 ) {
			return 0.0;
		}

		return round( $part / $total, 3 );
	}

	/**
	 * Balde do threshold.
	 *
	 * @param float $threshold Valor configurado.
	 * @return string
	 */
	private static function threshold_bucket( float $threshold ): string {
		if ( $threshold < 0.3 ) {
			return '<0.3';
		}

		if ( $threshold < 0.5 ) {
			return '0.3-0.5';
		}

		if ( $threshold < 0.7 ) {
			return '0.5-0.7';
		}

		if ( $threshold <= 0.9 ) {
			return '0.7-0.9';
		}

		return '>0.9';
	}

	/**
	 * Balde de volume.
	 *
	 * @param int $total Total de avaliações na janela.
	 * @return string
	 */
	private static function total_bucket( int $total ): string {
		if ( $total < 100 ) {
			return '<100';
		}

		if ( $total < 1000 ) {
			return '100-1k';
		}

		if ( $total < 10000 ) {
			return '1k-10k';
		}

		if ( $total < 100000 ) {
			return '10k-100k';
		}

		return '>100k';
	}

	/**
	 * Ambiente declarado.
	 *
	 * `unknown` é valor legítimo, e obrigar a mentir seria pior: sem este campo o dado de
	 * CI e de máquina de dev contamina a série de produção (§1.2).
	 *
	 * @return string
	 */
	private static function environment(): string {
		$type = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : '';

		switch ( $type ) {
			case 'production':
			case 'staging':
			case 'development':
				return $type;

			case 'local':
				return 'development';

			default:
				return 'unknown';
		}
	}

	/**
	 * Versão do WordPress, sem passar por funções que revelam o site.
	 *
	 * @return string
	 */
	private static function wp_version(): string {
		if ( isset( $GLOBALS['wp_version'] ) ) {
			return (string) $GLOBALS['wp_version'];
		}

		return '';
	}

	/**
	 * Versão do servidor de banco.
	 *
	 * @return string
	 */
	private static function db_version(): string {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'db_version' ) ) {
			return (string) $GLOBALS['wpdb']->db_version();
		}

		return '';
	}

	/**
	 * Timestamp em ISO-8601 UTC.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	private static function iso( int $timestamp ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
