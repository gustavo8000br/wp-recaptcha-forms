<?php
/**
 * Registro de usuário do wp-login.php.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations\Core;

use WpRecaptchaForms\Integrations\AbstractIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fluxo de registro (arquitetura v1.1 §6.5).
 */
final class RegisterIntegration extends AbstractIntegration {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'wp_register';
	}

	/**
	 * Rótulo.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Registro de usuário', 'wp-recaptcha-forms' );
	}

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'register_form', array( $this, 'render_field' ) );
		add_filter( 'registration_errors', array( $this, 'check' ), 10, 3 );
	}

	/**
	 * Valida a submissão.
	 *
	 * Devolver o erro por `registration_errors` — e não bloquear por `wp_die` — é o que
	 * faz o `wp-login.php` re-renderizar o formulário com login e e-mail preservados
	 * (FR-09), sem uma linha de código para isso.
	 *
	 * @param \WP_Error $errors               Erros acumulados.
	 * @param string    $sanitized_user_login Login.
	 * @param string    $user_email           E-mail.
	 * @return \WP_Error
	 */
	public function check( $errors, $sanitized_user_login = '', $user_email = '' ) {
		if ( ! $errors instanceof \WP_Error ) {
			return $errors;
		}

		$verdict = $this->assess();

		if ( $verdict->is_blocked() ) {
			$errors->add( $this->error_code( $verdict, 'wrf_register' ), $verdict->message() );
		}

		return $errors;
	}
}
