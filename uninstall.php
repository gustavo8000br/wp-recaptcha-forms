<?php
/**
 * Desinstalação: remove toda a pegada do plugin no banco (S-02).
 *
 * @package WpRecaptchaForms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Varre por prefixo, e não por lista de chaves.
 *
 * OB-05 pedia um inventário nomeado para que "remove todas as opções" fosse testável.
 * Uma lista literal é testável e esquece a chave nova de cada story; a varredura por
 * prefixo é testável pela mesma asserção e não esquece nada. As duas famílias de
 * prefixo existem porque o kill switch canônico usa `wrf_` e o resto usa o prefixo longo.
 */
$wrf_prefixes = array( 'wp_recaptcha_forms_', 'wrf_' );

global $wpdb;

foreach ( $wrf_prefixes as $wrf_prefix ) {
	$wrf_like = $wpdb->esc_like( $wrf_prefix ) . '%';

	// Options (inclui _schema_version, misconfig_since, keypair_suspect).
	$wrf_option_names = $wpdb->get_col(
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wrf_like )
	);

	foreach ( (array) $wrf_option_names as $wrf_option_name ) {
		delete_option( $wrf_option_name );
	}

	// Transients (e seus timeouts), inclusive os contadores do advisory de par cruzado.
	foreach ( array( '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ) as $wrf_transient_prefix ) {
		$wrf_transient_like = $wpdb->esc_like( $wrf_transient_prefix . $wrf_prefix ) . '%';

		$wrf_transient_names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wrf_transient_like )
		);

		foreach ( (array) $wrf_transient_names as $wrf_transient_name ) {
			delete_option( $wrf_transient_name );
		}
	}
}

// Multisite: as mesmas famílias podem existir por site.
if ( is_multisite() ) {
	$wrf_site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( (array) $wrf_site_ids as $wrf_site_id ) {
		switch_to_blog( $wrf_site_id );

		foreach ( $wrf_prefixes as $wrf_prefix ) {
			$wrf_like  = $wpdb->esc_like( $wrf_prefix ) . '%';
			$wrf_names = $wpdb->get_col(
				$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wrf_like )
			);

			foreach ( (array) $wrf_names as $wrf_name ) {
				delete_option( $wrf_name );
			}
		}

		restore_current_blog();
	}
}
