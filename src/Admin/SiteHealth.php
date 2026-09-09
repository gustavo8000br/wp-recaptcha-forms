<?php
/**
 * Teste de Saúde do site.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Admin;

use WpRecaptchaForms\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Terceira camada da escalada (arquitetura v1 §5.3-3).
 *
 * Existe para aparecer também em monitoramento externo e em relatório de agência —
 * público que nunca vai abrir a tela do plugin.
 */
final class SiteHealth {

	/**
	 * Registra o teste.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'site_status_tests', array( __CLASS__, 'register' ) );
	}

	/**
	 * Acrescenta o teste à lista.
	 *
	 * @param array $tests Testes.
	 * @return array
	 */
	public static function register( $tests ) {
		$tests['direct']['wp_recaptcha_forms_status'] = array(
			'label' => __( 'Proteção do WP reCAPTCHA Forms', 'wp-recaptcha-forms' ),
			'test'  => array( __CLASS__, 'run' ),
		);

		return $tests;
	}

	/**
	 * Executa o teste.
	 *
	 * @return array
	 */
	public static function run(): array {
		$badge = array(
			'label' => __( 'Segurança', 'wp-recaptcha-forms' ),
			'color' => 'blue',
		);

		switch ( Plugin::state() ) {
			case Plugin::STATE_DEGRADED:
				return array(
					'label'       => __( 'O WP reCAPTCHA Forms não está verificando os formulários', 'wp-recaptcha-forms' ),
					'status'      => 'critical',
					'badge'       => $badge,
					'description' => '<p>' . esc_html__( 'As chaves do reCAPTCHA foram recusadas pelo Google. Os formulários continuam funcionando, mas sem verificação alguma.', 'wp-recaptcha-forms' ) . '</p>',
					'actions'     => '<a href="' . esc_url( admin_url( 'options-general.php?page=' . SettingsPage::SLUG ) ) . '">' . esc_html__( 'Abrir as configurações', 'wp-recaptcha-forms' ) . '</a>',
					'test'        => 'wp_recaptcha_forms_status',
				);

			case Plugin::STATE_UNCONFIGURED:
				return array(
					'label'       => __( 'O WP reCAPTCHA Forms ainda não foi configurado', 'wp-recaptcha-forms' ),
					'status'      => 'recommended',
					'badge'       => $badge,
					'description' => '<p>' . esc_html__( 'Informe a site key e a secret key para que os formulários passem a ser verificados.', 'wp-recaptcha-forms' ) . '</p>',
					'test'        => 'wp_recaptcha_forms_status',
				);

			default:
				return array(
					'label'       => __( 'O WP reCAPTCHA Forms está protegendo os formulários', 'wp-recaptcha-forms' ),
					'status'      => 'good',
					'badge'       => $badge,
					'description' => '<p>' . esc_html__( 'As verificações estão funcionando.', 'wp-recaptcha-forms' ) . '</p>',
					'test'        => 'wp_recaptcha_forms_status',
				);
		}
	}
}
