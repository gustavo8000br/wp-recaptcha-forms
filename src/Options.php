<?php
/**
 * Leitura e escrita tipada das opções do plugin.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Único ponto do plugin que conhece o nome das options (arquitetura v1 §10).
 *
 * Todo o resto do código pede valores tipados a esta classe. Isso é o que torna a
 * migração de schema (R-09) possível sem caçar `get_option()` pelo repositório.
 */
final class Options {

	/** Option principal: array de configuração. */
	const OPTION = 'wp_recaptcha_forms_settings';

	/** Versão do schema gravado (R-09). */
	const OPTION_SCHEMA_VERSION = 'wp_recaptcha_forms_schema_version';

	/** Timestamp do primeiro MISCONFIG em runtime ainda não resolvido (v1 §5.3-2). */
	const OPTION_MISCONFIG_SINCE = 'wp_recaptcha_forms_misconfig_since';

	/** Últimos debug codes do MISCONFIG, para a tela de admin. */
	const OPTION_MISCONFIG_CODES = 'wp_recaptcha_forms_misconfig_codes';

	/** Advisory de par de chaves cruzado (v1.1 §4.4). */
	const OPTION_KEYPAIR_SUSPECT = 'wp_recaptcha_forms_keypair_suspect';

	/** Schema corrente. Incrementar SEMPRE que o formato do array mudar. */
	const SCHEMA_VERSION = 1;

	/** Prefixos que o uninstall varre. */
	const OPTION_PREFIXES = array( 'wp_recaptcha_forms_', 'wrf_' );

	/**
	 * Cache do array de opções dentro do request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Defaults do schema. Método, nunca const: valores podem depender de filtros.
	 *
	 * Nenhuma string traduzida aqui — mensagens default vivem em Frontend\Messages,
	 * por causa do `_load_textdomain_just_in_time` (arquitetura v1 §9.1).
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'version'                           => 'v3',
			'site_key'                          => '',
			'secret_key'                        => '',
			'threshold'                         => 0.6,
			'remoteip'                          => true,
			'consent_mode'                      => 'off',
			// Eixos de política de falha, independentes desde o primeiro dia (v1.1 §7.3).
			'failure_policy_infra'              => 'allow',
			'failure_policy_client_unreachable' => 'allow',
			// Mensagens customizáveis do operador (FR-27). Vazio = usa o default traduzido.
			'messages'                          => array(
				'rejected'           => '',
				'infra'              => '',
				'client_unreachable' => '',
				'unreachable_notice' => '',
			),
			// Estado por formulário. Populado pelo Registry (Story 1.11).
			'forms'                             => array(),
		);
	}

	/**
	 * Defaults de uma linha de formulário.
	 *
	 * `inherit` é gravado explicitamente, nunca null: a UI precisa distinguir
	 * "não escolhido" de "escolhido igual ao global" (v1.1 §7.3).
	 *
	 * @return array
	 */
	public static function form_defaults(): array {
		return array(
			'enabled'                   => false,
			'policy_infra'              => 'inherit',
			'policy_client_unreachable' => 'inherit',
		);
	}

	/**
	 * Todas as opções, já mescladas com os defaults.
	 *
	 * @return array
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			$merged = array_merge( self::defaults(), $stored );

			$merged['messages'] = array_merge( self::defaults()['messages'], is_array( $merged['messages'] ?? null ) ? $merged['messages'] : array() );
			$merged['forms']    = is_array( $merged['forms'] ?? null ) ? $merged['forms'] : array();

			self::$cache = $merged;
		}

		return self::$cache;
	}

	/**
	 * Um valor de topo.
	 *
	 * @param string $key     Chave.
	 * @param mixed  $default Valor de fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Grava o array inteiro (já sanitizado por Admin\Sanitizer).
	 *
	 * @param array $values Valores.
	 * @return void
	 */
	public static function update( array $values ): void {
		self::$cache = null;
		update_option( self::OPTION, $values );
	}

	/**
	 * Grava uma chave de topo.
	 *
	 * @param string $key   Chave.
	 * @param mixed  $value Valor.
	 * @return void
	 */
	public static function set( string $key, $value ): void {
		$all         = self::all();
		$all[ $key ] = $value;
		self::update( $all );
	}

	/**
	 * Descarta o cache de request. Usado em testes e depois de escrita externa.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Versão do reCAPTCHA ativa.
	 *
	 * @return string 'v3'|'v2'
	 */
	public static function version(): string {
		return 'v2' === self::get( 'version' ) ? 'v2' : 'v3';
	}

	/**
	 * Site key configurada.
	 *
	 * @return string
	 */
	public static function site_key(): string {
		return (string) self::get( 'site_key', '' );
	}

	/**
	 * Secret key efetiva.
	 *
	 * A constante `WP_RECAPTCHA_FORMS_SECRET_KEY` do wp-config.php tem precedência
	 * sobre a option (arquitetura v1 §13): chave fora do banco, deploy versionado.
	 *
	 * @return string
	 */
	public static function secret_key(): string {
		if ( self::secret_is_constant() ) {
			return (string) WP_RECAPTCHA_FORMS_SECRET_KEY;
		}

		return (string) self::get( 'secret_key', '' );
	}

	/**
	 * A secret vem de constante?
	 *
	 * @return bool
	 */
	public static function secret_is_constant(): bool {
		return defined( 'WP_RECAPTCHA_FORMS_SECRET_KEY' ) && '' !== (string) WP_RECAPTCHA_FORMS_SECRET_KEY;
	}

	/**
	 * Threshold de score do v3.
	 *
	 * @return float
	 */
	public static function threshold(): float {
		$value = (float) self::get( 'threshold', 0.6 );

		if ( $value < 0.0 || $value > 1.0 ) {
			return 0.6;
		}

		return $value;
	}

	/**
	 * Enviar o IP do visitante ao Google? (v1 §13)
	 *
	 * @return bool
	 */
	public static function send_remote_ip(): bool {
		return (bool) self::get( 'remoteip', true );
	}

	/**
	 * Configuração de um formulário, já mesclada com os defaults.
	 *
	 * @param string $form_id Identificador do formulário.
	 * @return array
	 */
	public static function form( string $form_id ): array {
		$forms  = self::get( 'forms', array() );
		$stored = isset( $forms[ $form_id ] ) && is_array( $forms[ $form_id ] ) ? $forms[ $form_id ] : array();

		return array_merge( self::form_defaults(), $stored );
	}

	/**
	 * O formulário está protegido?
	 *
	 * @param string $form_id Identificador do formulário.
	 * @return bool
	 */
	public static function form_enabled( string $form_id ): bool {
		$form = self::form( $form_id );

		return ! empty( $form['enabled'] );
	}

	/**
	 * Versão de schema gravada na instalação.
	 *
	 * @return int
	 */
	public static function schema_version(): int {
		return (int) get_option( self::OPTION_SCHEMA_VERSION, 0 );
	}

	/**
	 * Executa migrações pendentes e carimba o schema corrente (R-09).
	 *
	 * Chamado na ativação e em todo boot: uma instalação atualizada por FTP nunca
	 * dispara o hook de ativação, e sem esta chamada ficaria com schema antigo para sempre.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$from = self::schema_version();

		if ( self::SCHEMA_VERSION === $from ) {
			return;
		}

		if ( 0 === $from ) {
			// Primeira instalação: grava os defaults e carimba.
			if ( false === get_option( self::OPTION, false ) ) {
				add_option( self::OPTION, self::defaults() );
			}
		}

		/**
		 * Ponto de extensão das migrações futuras.
		 *
		 * Cada incremento de SCHEMA_VERSION acrescenta um bloco aqui, jamais reescreve
		 * os anteriores — uma instalação pode saltar várias versões de uma vez.
		 *
		 * Exemplo do formato esperado:
		 *   if ( $from < 2 ) { ... transforma o array ... }
		 */
		do_action( 'wp_recaptcha_forms_upgrade_schema', $from, self::SCHEMA_VERSION );

		update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION );
		self::flush_cache();
	}
}
