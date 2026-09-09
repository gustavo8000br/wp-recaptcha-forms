<?php
/**
 * Teste de integração da desinstalação (Story 1.1 AC5, OB-05).
 *
 * Não usa PHPUnit de propósito: precisa de um WordPress carregado de verdade e roda
 * pelo WP-CLI do ambiente Docker.
 *
 *   docker compose exec -T wpcli wp eval-file \
 *     wp-content/plugins/wp-recaptcha-forms/tests/Integration/uninstall-test.php \
 *     --path=/var/www/html --allow-root
 *
 * @package WpRecaptchaForms
 */

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.Security.EscapeOutput

update_option( 'wp_recaptcha_forms_settings', array( 'site_key' => 'x' ) );
update_option( 'wp_recaptcha_forms_schema_version', 1 );
update_option( 'wp_recaptcha_forms_misconfig_since', time() );
update_option( 'wp_recaptcha_forms_misconfig_codes', array( 'invalid-input-secret' ) );
update_option( 'wp_recaptcha_forms_keypair_suspect', 1 );
update_option( 'wrf_legacy_probe', 'x' );
set_transient( 'wp_recaptcha_forms_keypair_total', 5, HOUR_IN_SECONDS );
set_transient( 'wp_recaptcha_forms_keypair_invalid', 5, HOUR_IN_SECONDS );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'wp-recaptcha-forms/wp-recaptcha-forms.php' );
}

require WP_PLUGIN_DIR . '/wp-recaptcha-forms/uninstall.php';

global $wpdb;

$left = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	 WHERE option_name LIKE '%wp\\_recaptcha\\_forms\\_%'
	    OR option_name LIKE '%wrf\\_%'"
);

if ( empty( $left ) ) {
	echo "PASS uninstall: nenhuma option com prefixo do plugin sobrou\n";
} else {
	echo 'FAIL uninstall: ' . implode( ', ', $left ) . "\n";
	exit( 1 );
}

// Restaura o schema para o ambiente seguir utilizável depois do teste.
\WpRecaptchaForms\Options::maybe_upgrade();
echo "INFO opções recriadas pelo maybe_upgrade()\n";
