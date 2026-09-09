<?php
/**
 * Recuperação de senha do wp-login.php.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations\Core;

use WpRecaptchaForms\Gate\TokenCollector;
use WpRecaptchaForms\Integrations\AbstractIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fluxo de lost password do `wp-login.php` (arquitetura v1.1 §6.5).
 *
 * O reset por link de e-mail continua fora de escopo: ele chega com uma chave que já é o
 * fator de autenticação.
 */
final class LostPasswordIntegration extends AbstractIntegration {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'wp_lostpassword';
	}

	/**
	 * Rótulo.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Recuperação de senha (WordPress)', 'wp-recaptcha-forms' );
	}

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'lostpassword_form', array( $this, 'render_field' ) );
		add_action( 'lostpassword_post', array( $this, 'check' ), 10, 2 );
	}

	/**
	 * Valida a submissão.
	 *
	 * O WooCommerce dispara o MESMO `lostpassword_post` a partir da página Minha conta
	 * (`WC_Shortcode_My_Account::retrieve_password()`). Sem o discriminador abaixo, os dois
	 * adaptadores avaliariam a mesma submissão e o visitante veria a mensagem duplicada —
	 * e o toggle do formulário do Woo deixaria de significar alguma coisa.
	 *
	 * @param \WP_Error $errors    Erros acumulados.
	 * @param \WP_User  $user_data Usuário encontrado.
	 * @return void
	 */
	public function check( $errors, $user_data = null ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		if ( TokenCollector::submitted( 'wc_reset_password' ) ) {
			return;
		}

		$verdict = $this->assess();

		if ( $verdict->is_blocked() ) {
			$errors->add( $this->error_code( $verdict, 'wrf_lostpassword' ), $verdict->message() );
		}
	}
}
