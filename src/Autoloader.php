<?php
/**
 * Autoloader PSR-4 sem dependência do Composer.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mapeia o namespace \WpRecaptchaForms\ para src/.
 *
 * O plugin é distribuído como zip e instalado por painel: exigir `composer install` do
 * usuário final seria transferir trabalho de mantenedor para operador. O composer.json
 * declara o mesmo mapa PSR-4 para as ferramentas de desenvolvimento.
 */
final class Autoloader {

	const PREFIX = 'WpRecaptchaForms\\';

	/**
	 * Registra o autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Carrega uma classe do namespace do plugin.
	 *
	 * @param string $class_name Nome totalmente qualificado.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$path     = WP_RECAPTCHA_FORMS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
