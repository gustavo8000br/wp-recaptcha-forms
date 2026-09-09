<?php
/**
 * Desinstalação: remove toda a pegada do plugin no banco (S-02).
 *
 * @package WpRecaptchaForms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'wp_recaptcha_forms_uninstall_purge_current_site' ) ) {
	/**
	 * Apaga options e transients do site corrente, varrendo por prefixo.
	 *
	 * OB-05 pedia um inventário nomeado para que "remove todas as opções" fosse
	 * testável. Uma lista literal é testável e esquece a chave nova de cada story; a
	 * varredura por prefixo é testável pela mesma asserção e não esquece nada. As duas
	 * famílias de prefixo existem porque o kill switch canônico usa `wrf_` e o resto usa
	 * o prefixo longo.
	 *
	 * @return void
	 */
	function wp_recaptcha_forms_uninstall_purge_current_site() {
		global $wpdb;

		$prefixes = array( 'wp_recaptcha_forms_', 'wrf_' );

		$transient_scopes = array(
			'',
			'_transient_',
			'_transient_timeout_',
			'_site_transient_',
			'_site_transient_timeout_',
		);

		foreach ( $prefixes as $prefix ) {
			foreach ( $transient_scopes as $scope ) {
				$like = $wpdb->esc_like( $scope . $prefix ) . '%';

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- desinstalação varre a tabela de options por prefixo; não há API do WP para isso.
				$names = $wpdb->get_col(
					$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
				);

				foreach ( (array) $names as $name ) {
					delete_option( $name );
				}
			}
		}
	}
}

wp_recaptcha_forms_uninstall_purge_current_site();

// Multisite: as mesmas famílias podem existir por site.
if ( is_multisite() ) {
	foreach ( (array) get_sites( array( 'fields' => 'ids' ) ) as $wp_recaptcha_forms_site_id ) {
		switch_to_blog( $wp_recaptcha_forms_site_id );
		wp_recaptcha_forms_uninstall_purge_current_site();
		restore_current_blog();
	}

	unset( $wp_recaptcha_forms_site_id );
}
