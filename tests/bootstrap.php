<?php
/**
 * Bootstrap da suíte unitária.
 *
 * Sem WordPress carregado de propósito: o núcleo (Provider, Gate, FailurePolicy) é
 * testável com um punhado de stubs, e a suíte roda em milissegundos. Os testes que
 * precisam de WordPress de verdade vivem em `tests/Integration/` e rodam por WP-CLI.
 *
 * @package WpRecaptchaForms
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_RECAPTCHA_FORMS_DIR', dirname( __DIR__ ) . '/' );
define( 'WP_RECAPTCHA_FORMS_URL', 'https://example.test/wp-content/plugins/wp-recaptcha-forms/' );
define( 'WP_RECAPTCHA_FORMS_VERSION', '0.1.0' );
define( 'WP_RECAPTCHA_FORMS_BUILD', 'v0.1.0-test-alpha' );
define( 'WP_RECAPTCHA_FORMS_FILE', dirname( __DIR__ ) . '/wp-recaptcha-forms.php' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

define( 'ARRAY_A', 'ARRAY_A' );

require_once __DIR__ . '/Support/wp-stubs.php';
require_once __DIR__ . '/Support/woo-stubs.php';
require_once dirname( __DIR__ ) . '/src/Autoloader.php';

WpRecaptchaForms\Autoloader::register();

// A API pública é um arquivo de funções: não é autocarregável, nem em produção nem aqui.
require_once dirname( __DIR__ ) . '/src/PublicApi.php';

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WpRecaptchaForms\\Tests\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$path = __DIR__ . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
