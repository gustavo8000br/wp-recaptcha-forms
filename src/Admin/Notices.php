<?php
/**
 * Avisos persistentes do admin.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Admin;

use WpRecaptchaForms\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Escalada visível de MISCONFIG e do advisory de par cruzado.
 *
 * Nenhum dos dois é dispensável: some quando o problema é corrigido, não quando o
 * operador se irrita (arquitetura v1 §5.3-2).
 */
final class Notices {

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
	}

	/**
	 * Imprime os avisos aplicáveis.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$since = get_option( Options::OPTION_MISCONFIG_SINCE, '' );

		if ( $since ) {
			$codes = (array) get_option( Options::OPTION_MISCONFIG_CODES, array() );

			echo '<div class="notice notice-error"><p><strong>';
			echo esc_html__( 'WP reCAPTCHA Forms: as chaves foram recusadas pelo Google.', 'wp-recaptcha-forms' );
			echo '</strong> ';
			echo esc_html__( 'Os formulários estão sendo enviados SEM verificação até que isso seja corrigido.', 'wp-recaptcha-forms' );
			echo ' <a href="' . esc_url( admin_url( 'options-general.php?page=' . SettingsPage::SLUG ) ) . '">';
			echo esc_html__( 'Abrir as configurações', 'wp-recaptcha-forms' );
			echo '</a>';

			if ( ! empty( $codes ) ) {
				echo '<br><code>' . esc_html( implode( ', ', array_map( 'strval', $codes ) ) ) . '</code>';
			}

			echo '</p></div>';
		}

		if ( get_option( Options::OPTION_KEYPAIR_SUSPECT, '' ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'WP reCAPTCHA Forms: todas as verificações recentes falharam. A causa mais provável é a site key e a secret key pertencerem a projetos diferentes no console do Google. Confira as duas chaves.', 'wp-recaptcha-forms' );
			echo '</p></div>';
		}
	}
}
