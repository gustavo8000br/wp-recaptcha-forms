<?php
/**
 * Recuperação de senha da página Minha conta do WooCommerce.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations\WooCommerce;

use WpRecaptchaForms\Gate\TokenCollector;
use WpRecaptchaForms\Integrations\AbstractIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * O Woo tem formulário próprio de "perdeu a senha", e ele dispara o MESMO
 * `lostpassword_post` do núcleo (`WC_Shortcode_My_Account::retrieve_password()`).
 *
 * O discriminador é o campo `wc_reset_password`, que só existe no template do Woo: sem
 * ele, este adaptador e o do núcleo avaliariam a mesma submissão, e o visitante veria a
 * mensagem duas vezes.
 */
final class LostPasswordIntegration extends AbstractIntegration {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'woocommerce_lostpassword';
	}

	/**
	 * Rótulo.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Recuperação de senha (Minha conta)', 'wp-recaptcha-forms' );
	}

	/**
	 * Grupo na tela.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'woocommerce';
	}

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_lostpassword_form', array( $this, 'render_field' ) );
		add_action( 'lostpassword_post', array( $this, 'check' ), 10, 2 );
	}

	/**
	 * Valida a submissão.
	 *
	 * @param \WP_Error $errors    Erros acumulados.
	 * @param \WP_User  $user_data Usuário encontrado.
	 * @return void
	 */
	public function check( $errors, $user_data = null ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		if ( ! TokenCollector::submitted( 'wc_reset_password' ) ) {
			return;
		}

		$verdict = $this->assess();

		if ( $verdict->is_blocked() ) {
			$errors->add( $this->error_code( $verdict, 'wrf_wc_lostpassword' ), $verdict->message() );
		}
	}
}
