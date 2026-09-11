<?php
/**
 * Plugin Name:       WP reCAPTCHA Forms
 * Plugin URI:        https://github.com/gustavo8000br/wp-recaptcha-forms
 * Description:       Protege formulários do WordPress, WooCommerce e Newsletter com o reCAPTCHA do Google, com política de falha configurável e sem quebrar cache de página.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Gustavo Mathias Rocha
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       wp-recaptcha-forms
 * Domain Path:       /languages
 *
 * @package WpRecaptchaForms
 *
 * NOTA DE SINTAXE: este arquivo precisa ser interpretável por PHP antigo para que o
 * guard de versão abaixo consiga exibir a mensagem em vez de produzir erro de parse.
 * Nada além de sintaxe compatível com PHP 5.2 pode aparecer neste arquivo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versão do plugin. Espelha exatamente o header (arquitetura v1 §11).
 * Usada em wp_enqueue_script() como cache-bust de asset.
 */
define( 'WP_RECAPTCHA_FORMS_VERSION', '1.2.0' );

/**
 * String completa de build (vMAJOR.MINOR.PATCH-HHHHHHH-stage).
 * Diagnóstico apenas: rodapé da tela de config e Site Health. Nunca vai em URL de asset.
 */
define( 'WP_RECAPTCHA_FORMS_BUILD', 'v1.2.0-0000000-beta' );

define( 'WP_RECAPTCHA_FORMS_FILE', __FILE__ );
define( 'WP_RECAPTCHA_FORMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_RECAPTCHA_FORMS_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_RECAPTCHA_FORMS_MIN_PHP', '7.4' );

/**
 * Guard de versão de PHP (Story 1.1 AC3).
 * Abaixo do piso o plugin não carrega nada além de um admin notice. Sem fatal error.
 */
if ( version_compare( PHP_VERSION, WP_RECAPTCHA_FORMS_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'wp_recaptcha_forms_php_version_notice' );
	return;
}

/**
 * Notice de PHP incompatível.
 *
 * @return void
 */
function wp_recaptcha_forms_php_version_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html(
		sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'WP reCAPTCHA Forms requer PHP %1$s ou superior. Esta instalação usa PHP %2$s, então o plugin está inativo.', 'wp-recaptcha-forms' ),
			WP_RECAPTCHA_FORMS_MIN_PHP,
			PHP_VERSION
		)
	);
	echo '</p></div>';
}

require_once WP_RECAPTCHA_FORMS_DIR . 'src/Autoloader.php';
\WpRecaptchaForms\Autoloader::register();

/*
 * API pública. Carregada antes do kill switch de propósito: um tema que chame
 * `wp_recaptcha_forms_render_field()` não pode virar fatal error só porque o operador
 * desativou a proteção — as funções continuam existindo e devolvem o comportamento neutro.
 */
require_once WP_RECAPTCHA_FORMS_DIR . 'src/PublicApi.php';

/**
 * Kill switch (arquitetura v1.1 §5, Story 1.1 AC7).
 *
 * Checado ANTES de registrar qualquer hook de enforcement — não dentro deles. Um guard
 * dentro do hook deixaria o asset enfileirado, o campo impresso e a tela de config mentindo.
 *
 * @return bool
 */
function wp_recaptcha_forms_is_killed() {
	if ( defined( 'WRF_DISABLE' ) && WRF_DISABLE ) {
		return true;
	}
	if ( defined( 'WP_RECAPTCHA_FORMS_DISABLE' ) && WP_RECAPTCHA_FORMS_DISABLE ) {
		return true;
	}
	return false;
}

register_activation_hook( __FILE__, array( '\WpRecaptchaForms\Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( '\WpRecaptchaForms\Plugin', 'on_deactivate' ) );

if ( wp_recaptcha_forms_is_killed() ) {
	\WpRecaptchaForms\DisabledMode::boot();
	return;
}

\WpRecaptchaForms\Plugin::instance()->boot();
