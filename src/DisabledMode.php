<?php
/**
 * Modo desativado por constante (kill switch).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap curto do kill switch (arquitetura v1.1 §5.2).
 *
 * Nenhuma integração é registrada, nenhum asset é enfileirado, nenhum campo é impresso
 * e nenhuma chamada ao Google é feita. Continuam funcionando de propósito: a tela de
 * configurações (o operador precisa poder consertar a chave que o trouxe até aqui), o
 * notice não-dispensável e o teste de Site Health.
 */
final class DisabledMode {

	/**
	 * Registra o mínimo do modo desativado.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'init', array( Plugin::instance(), 'load_textdomain' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_health_test' ) );

		if ( is_admin() && class_exists( Admin\SettingsPage::class ) ) {
			// A tela de config continua acessível e editável (v1.1 §5.2).
			Admin\SettingsPage::instance()->boot();
		}
	}

	/**
	 * Notice de nível warning, presente em todo o admin e sem botão de dispensar.
	 *
	 * Sem "is-dismissible" de propósito: um site que ficou desprotegido por seis meses
	 * porque alguém esqueceu a constante é o pior desfecho possível.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'A proteção do WP reCAPTCHA Forms está desativada pela constante WRF_DISABLE em wp-config.php. Nenhum formulário está sendo verificado.', 'wp-recaptcha-forms' );
		echo '</p></div>';
	}

	/**
	 * Registra o teste de Site Health.
	 *
	 * @param array $tests Testes registrados.
	 * @return array
	 */
	public static function register_site_health_test( $tests ) {
		$tests['direct']['wp_recaptcha_forms_disabled'] = array(
			'label' => __( 'Proteção do WP reCAPTCHA Forms', 'wp-recaptcha-forms' ),
			'test'  => array( __CLASS__, 'site_health_test' ),
		);

		return $tests;
	}

	/**
	 * Resultado do teste de Site Health.
	 *
	 * Nível `recommended` e não `critical`: é estado deliberado do operador, não defeito.
	 *
	 * @return array
	 */
	public static function site_health_test(): array {
		return array(
			'label'       => __( 'A proteção do WP reCAPTCHA Forms está desativada por constante', 'wp-recaptcha-forms' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Segurança', 'wp-recaptcha-forms' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'A constante WRF_DISABLE está definida em wp-config.php. Nenhum formulário está sendo verificado pelo reCAPTCHA. Remova a constante para retomar a proteção.', 'wp-recaptcha-forms' ) . '</p>',
			'test'        => 'wp_recaptcha_forms_disabled',
		);
	}
}
