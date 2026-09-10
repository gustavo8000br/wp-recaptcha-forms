<?php
/**
 * Stubs mínimos das funções do WordPress usadas pelo núcleo.
 *
 * @package WpRecaptchaForms
 */

// phpcs:disable WordPress.NamingConventions, Squiz.Commenting, WordPress.WP.GlobalVariablesOverride

/**
 * Estado global dos stubs. Zerado entre testes por WpStubs::reset().
 */
final class WpStubs {

	/** @var array<string, array<int, callable[]>> */
	public static $filters = array();

	/** @var array<string, mixed> */
	public static $options = array();

	/** @var array<string, string> Valor de `autoload` com que cada option foi gravada. */
	public static $autoload = array();

	/**
	 * Quantas escritas cada option recebeu. Permite afirmar sobre AMPLIFICAÇÃO de
	 * escrita, que é o custo que a telemetria impõe ao site do operador (§3.3).
	 *
	 * @var array<string, int>
	 */
	public static $option_writes = array();

	/** @var array<string, mixed> */
	public static $transients = array();

	/**
	 * Eventos de cron agendados: hook => lista de `{timestamp, recurrence, args}`.
	 *
	 * @var array<string, array>
	 */
	public static $cron = array();

	/** @var array<int, array{url:string, args:array}> Requisições HTTP tentadas. */
	public static $http = array();

	/** @var array<int, array{0:string,1:array}> */
	public static $actions_fired = array();

	/** @var array<string, mixed> Contexto de requisição controlável pelos testes. */
	public static $env = array();

	/** @var array<int, string> Tipo de cada post, por id. */
	public static $post_types = array();

	/** @var array<int, array{0:string,1:string}> Shortcodes registrados. */
	public static $shortcodes = array();

	/** @var array<int, array> Blocos entregues a wp_add_privacy_policy_content(). */
	public static $privacy_content = array();

	/** @var array<string, array> Scripts registrados/enfileirados. */
	public static $scripts = array();

	/**
	 * Capabilities do usuário corrente. `null` = tudo liberado (default histórico dos
	 * stubs); array = só o que estiver com `true` passa.
	 *
	 * @var array<string,bool>|null
	 */
	public static $capabilities = null;

	/**
	 * Catálogo de tradução ativo. `null` = stub identidade.
	 *
	 * @var array<string,string>|null
	 */
	public static $catalog = null;

	/**
	 * Chamadas registradas da Settings API.
	 *
	 * @var array[]
	 */
	public static $settings_calls = array();

	/**
	 * Erros registrados via `add_settings_error()`.
	 *
	 * @var array[]
	 */
	public static $settings_errors = array();

	public static function reset(): void {
		self::$filters         = array();
		self::$options         = array();
		self::$autoload        = array();
		self::$option_writes   = array();
		self::$transients      = array();
		self::$cron            = array();
		self::$http            = array();
		self::$actions_fired   = array();
		self::$env             = array();
		self::$post_types      = array();
		self::$shortcodes      = array();
		self::$privacy_content = array();
		self::$scripts         = array();
		self::$capabilities    = null;
		self::$catalog         = null;
		self::$settings_calls  = array();
		self::$settings_errors = array();

		$_POST = array();

		if ( class_exists( '\WpRecaptchaForms\Options' ) ) {
			\WpRecaptchaForms\Options::flush_cache();
		}

		if ( class_exists( '\WpRecaptchaForms\Telemetry\Counters' ) ) {
			\WpRecaptchaForms\Telemetry\Counters::reset_runtime();
		}

		if ( class_exists( '\WpRecaptchaForms\Runtime' ) ) {
			\WpRecaptchaForms\Runtime::reset();
		}

		if ( class_exists( '\WpRecaptchaForms\Consent\ConsentGate' ) ) {
			\WpRecaptchaForms\Consent\ConsentGate::set( null );
		}
	}

	/** @param mixed $default */
	public static function env( string $key, $default = false ) {
		return array_key_exists( $key, self::$env ) ? self::$env[ $key ] : $default;
	}
}

/**
 * `wp_die()` interrompe a requisição. Nos testes ele vira exceção para que a asserção
 * possa acontecer — o comportamento observável ("parou aqui, com esta mensagem") é o
 * mesmo.
 */
final class WpDieException extends \Exception {}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	WpStubs::$filters[ $hook ][ $priority ][] = $callback;
	ksort( WpStubs::$filters[ $hook ] );

	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value, ...$args ) {
	if ( empty( WpStubs::$filters[ $hook ] ) ) {
		return $value;
	}

	foreach ( WpStubs::$filters[ $hook ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}
	}

	return $value;
}

function do_action( $hook, ...$args ) {
	WpStubs::$actions_fired[] = array( $hook, $args );

	if ( empty( WpStubs::$filters[ $hook ] ) ) {
		return;
	}

	foreach ( WpStubs::$filters[ $hook ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$callback( ...$args );
		}
	}
}

function has_action( $hook, $callback = false ) {
	return ! empty( WpStubs::$filters[ $hook ] );
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, WpStubs::$options ) ? WpStubs::$options[ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	WpStubs::$options[ $name ]       = $value;
	WpStubs::$option_writes[ $name ] = ( WpStubs::$option_writes[ $name ] ?? 0 ) + 1;

	if ( null !== $autoload ) {
		WpStubs::$autoload[ $name ] = $autoload;
	}

	return true;
}

function add_option( $name, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, WpStubs::$options ) ) {
		return false;
	}

	WpStubs::$autoload[ $name ] = $autoload;

	return update_option( $name, $value );
}

function delete_option( $name ) {
	unset( WpStubs::$options[ $name ], WpStubs::$autoload[ $name ] );

	return true;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	WpStubs::$cron[ $hook ][] = array(
		'timestamp'  => (int) $timestamp,
		'recurrence' => $recurrence,
		'args'       => (array) $args,
	);

	return true;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	WpStubs::$cron[ $hook ][] = array(
		'timestamp'  => (int) $timestamp,
		'recurrence' => false,
		'args'       => (array) $args,
	);

	return true;
}

function wp_next_scheduled( $hook, $args = array() ) {
	if ( empty( WpStubs::$cron[ $hook ] ) ) {
		return false;
	}

	return WpStubs::$cron[ $hook ][0]['timestamp'];
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$count = isset( WpStubs::$cron[ $hook ] ) ? count( WpStubs::$cron[ $hook ] ) : 0;

	unset( WpStubs::$cron[ $hook ] );

	return $count;
}

function wp_remote_post( $url, $args = array() ) {
	WpStubs::$http[] = array(
		'url'  => $url,
		'args' => $args,
	);

	$response = apply_filters( 'pre_http_request', false, $args, $url );

	if ( false !== $response ) {
		return $response;
	}

	return new WP_Error( 'http_request_failed', 'sem transporte nos testes' );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}

function wp_remote_retrieve_header( $response, $name ) {
	if ( ! is_array( $response ) || ! isset( $response['headers'] ) ) {
		return '';
	}

	$headers = array_change_key_case( (array) $response['headers'] );

	return $headers[ strtolower( (string) $name ) ] ?? '';
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	return substr( str_repeat( 'aA1!bB2@', (int) ceil( $length / 8 ) ), 0, (int) $length );
}

function wp_generate_uuid4() {
	return sprintf(
		'%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0x0fff ),
		wp_rand( 0, 0x3fff ) | 0x8000,
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff )
	);
}

function wp_rand( $min = 0, $max = 0 ) {
	return random_int( (int) $min, 0 === (int) $max ? PHP_INT_MAX : (int) $max );
}

function get_transient( $name ) {
	return array_key_exists( $name, WpStubs::$transients ) ? WpStubs::$transients[ $name ] : false;
}

function set_transient( $name, $value, $expiration = 0 ) {
	WpStubs::$transients[ $name ] = $value;

	return true;
}

function delete_transient( $name ) {
	unset( WpStubs::$transients[ $name ] );

	return true;
}

/**
 * Tradução.
 *
 * Com `WpStubs::$catalog` nulo (default) o stub é identidade, como sempre foi. Com um
 * catálogo carregado, ele traduz de verdade — é isso que permite ao gate de
 * pseudo-locale da Story 1.20 provar que a string passou pelo pipeline de i18n em vez de
 * ter sido impressa direto.
 *
 * @param string $text   Texto.
 * @param string $domain Text domain.
 * @return string
 */
function __( $text, $domain = 'default' ) {
	if ( null === WpStubs::$catalog ) {
		return $text;
	}

	return WpStubs::$catalog[ (string) $text ] ?? $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return __( $text, $domain );
}

function esc_attr__( $text, $domain = 'default' ) {
	return __( $text, $domain );
}

function _x( $text, $context, $domain = 'default' ) {
	if ( null === WpStubs::$catalog ) {
		return $text;
	}

	return WpStubs::$catalog[ $context . "\4" . $text ] ?? WpStubs::$catalog[ (string) $text ] ?? $text;
}

function _e( $text, $domain = 'default' ) {
	echo esc_html( __( $text, $domain ) );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return $url;
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function wp_kses_post( $text ) {
	return $text;
}

function wp_kses( $text, $allowed = array() ) {
	return $text;
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function home_url( $path = '/' ) {
	return 'https://example.test' . $path;
}

function determine_locale() {
	return 'pt_BR';
}

function get_locale() {
	return (string) WpStubs::env( 'locale', 'pt_BR' );
}

function is_multisite() {
	return (bool) WpStubs::env( 'multisite', false );
}

function wp_get_environment_type() {
	return (string) WpStubs::env( 'environment_type', 'production' );
}

function wp_doing_ajax() {
	return (bool) WpStubs::env( 'doing_ajax', false );
}

function wp_doing_cron() {
	return (bool) WpStubs::env( 'doing_cron', false );
}

function is_user_logged_in() {
	return (bool) WpStubs::env( 'logged_in', false );
}

function get_post_type( $post = 0 ) {
	return WpStubs::$post_types[ (int) $post ] ?? 'post';
}

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new WpDieException( is_string( $message ) ? $message : '' );
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function add_shortcode( $tag, $callback ) {
	WpStubs::$shortcodes[ $tag ] = $callback;

	return true;
}

function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();

	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}

	return $out;
}

function wp_add_privacy_policy_content( $plugin_name, $policy_text ) {
	WpStubs::$privacy_content[] = array( $plugin_name, $policy_text );
}

function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $args = false ) {
	WpStubs::$scripts[ $handle ] = array(
		'src'      => $src,
		'deps'     => (array) $deps,
		'enqueued' => WpStubs::$scripts[ $handle ]['enqueued'] ?? false,
		'data'     => WpStubs::$scripts[ $handle ]['data'] ?? array(),
	);

	return true;
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = false ) {
	if ( ! isset( WpStubs::$scripts[ $handle ] ) ) {
		wp_register_script( $handle, $src, $deps, $ver, $args );
	}

	WpStubs::$scripts[ $handle ]['enqueued'] = true;

	return true;
}

function wp_script_is( $handle, $status = 'enqueued' ) {
	if ( ! isset( WpStubs::$scripts[ $handle ] ) ) {
		return false;
	}

	return 'registered' === $status ? true : (bool) WpStubs::$scripts[ $handle ]['enqueued'];
}

function wp_localize_script( $handle, $object_name, $data ) {
	if ( ! isset( WpStubs::$scripts[ $handle ] ) ) {
		return false;
	}

	WpStubs::$scripts[ $handle ]['data'][ $object_name ] = $data;

	return true;
}

function plugin_basename( $file ) {
	return 'wp-recaptcha-forms/' . basename( (string) $file );
}

function __return_true() {
	return true;
}

function is_admin() {
	return false;
}

function current_user_can( $cap ) {
	if ( null === WpStubs::$capabilities ) {
		return true;
	}

	return ! empty( WpStubs::$capabilities[ $cap ] );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_recaptcha_forms_is_killed() {
	return ( defined( 'WRF_DISABLE' ) && WRF_DISABLE )
		|| ( defined( 'WP_RECAPTCHA_FORMS_DISABLE' ) && WP_RECAPTCHA_FORMS_DISABLE );
}

class WP_Error {

	private $errors = array();

	private $data = array();

	public function __construct( $code = '', $message = '', $data = null ) {
		if ( '' !== $code ) {
			$this->add( $code, $message, $data );
		}
	}

	public function add( $code, $message = '', $data = null ) {
		$this->errors[ $code ][] = $message;

		if ( null !== $data ) {
			$this->data[ $code ] = $data;
		}
	}

	public function has_errors() {
		return ! empty( $this->errors );
	}

	public function get_error_codes() {
		return array_keys( $this->errors );
	}

	public function get_error_code() {
		$codes = $this->get_error_codes();

		return $codes ? $codes[0] : '';
	}

	public function get_error_message( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;

		return $this->errors[ $code ][0] ?? '';
	}

	public function get_error_data( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;

		return $this->data[ $code ] ?? null;
	}
}

class WC_Order {}

class WP_User {

	public $ID = 1;

	public $user_login = 'admin';
}

/**
 * Stubs da Settings API usados pela tela de config.
 *
 * A Settings API é quem carrega nonce e capability no save; nos testes ela só precisa
 * não explodir e registrar o que foi chamado, para o teste poder afirmar sobre isso.
 *
 * @return void
 */
function settings_errors( $setting = '', $sanitize = false, $hide_on_update = false ) {
	WpStubs::$settings_calls[] = array( 'settings_errors', $setting );
}

function settings_fields( $option_group ) {
	WpStubs::$settings_calls[] = array( 'settings_fields', $option_group );

	echo '<input type="hidden" name="option_page" value="' . esc_attr( (string) $option_group ) . '">';
}

function do_settings_sections( $page ) {
	WpStubs::$settings_calls[] = array( 'do_settings_sections', $page );
}

function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other = null ) {
	echo '<button type="submit" class="button button-primary">' . esc_html( (string) ( $text ?? 'Salvar' ) ) . '</button>';
}

function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	WpStubs::$settings_errors[] = array(
		'setting' => $setting,
		'code'    => $code,
		'message' => $message,
		'type'    => $type,
	);
}

/**
 * Helpers de atributo do core. Devolvem string vazia ou o atributo, nunca escapam nada
 * além do próprio nome do atributo — é literalmente o que o core faz.
 *
 * @return string
 */
function checked( $checked, $current = true, $display = true ) {
	return __checked_selected_helper( $checked, $current, $display, 'checked' );
}

function selected( $selected, $current = true, $display = true ) {
	return __checked_selected_helper( $selected, $current, $display, 'selected' );
}

function disabled( $disabled, $current = true, $display = true ) {
	return __checked_selected_helper( $disabled, $current, $display, 'disabled' );
}

function __checked_selected_helper( $helper, $current, $display, $type ) {
	$result = ( (string) $helper === (string) $current ) ? " $type='$type'" : '';

	if ( $display ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $result;
}

/**
 * URLs do admin. Nos testes só precisam ser determinísticas.
 *
 * @param string $path Caminho relativo.
 * @return string
 */
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function self_admin_url( $path = '' ) {
	return admin_url( $path );
}

function get_admin_url( $blog_id = null, $path = '' ) {
	return admin_url( $path );
}

/**
 * `wp_json_encode` sem as opções do core (a suíte não precisa delas).
 *
 * @param mixed $data Dados.
 * @return string
 */
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return (string) json_encode( $data, (int) $options, (int) $depth );
}
