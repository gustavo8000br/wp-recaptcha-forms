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

	/** @var array<string, mixed> */
	public static $transients = array();

	/** @var array<int, array{0:string,1:array}> */
	public static $actions_fired = array();

	public static function reset(): void {
		self::$filters       = array();
		self::$options       = array();
		self::$transients    = array();
		self::$actions_fired = array();

		if ( class_exists( '\WpRecaptchaForms\Options' ) ) {
			\WpRecaptchaForms\Options::flush_cache();
		}
	}
}

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

function update_option( $name, $value ) {
	WpStubs::$options[ $name ] = $value;

	return true;
}

function add_option( $name, $value ) {
	if ( array_key_exists( $name, WpStubs::$options ) ) {
		return false;
	}

	return update_option( $name, $value );
}

function delete_option( $name ) {
	unset( WpStubs::$options[ $name ] );

	return true;
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

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
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

function wp_doing_ajax() {
	return false;
}

function wp_doing_cron() {
	return false;
}

function is_user_logged_in() {
	return false;
}

function is_admin() {
	return false;
}

function current_user_can( $cap ) {
	return true;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_recaptcha_forms_is_killed() {
	return ( defined( 'WRF_DISABLE' ) && WRF_DISABLE )
		|| ( defined( 'WP_RECAPTCHA_FORMS_DISABLE' ) && WP_RECAPTCHA_FORMS_DISABLE );
}

class WP_Error {

	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}
