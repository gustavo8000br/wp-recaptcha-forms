<?php
/**
 * Escreve a versão nos três lugares que precisam concordar.
 *
 * Uso:
 *   php bin/stamp-version.php v1.2.0-a1b2c3d-stable
 *   php bin/stamp-version.php            (deriva de `git describe --tags`)
 *
 * O header do plugin recebe SÓ `MAJOR.MINOR.PATCH`. Um sufixo como `-a1b2c3d-alpha`
 * faria o WordPress considerar `1.2.0-a1b2c3d-alpha` anterior a `1.2.0`, e o usuário
 * com a build de release nunca receberia a atualização seguinte (arquitetura v1 §11).
 *
 * @package WpRecaptchaForms
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput

$plugin_file = dirname( __DIR__ ) . '/wp-recaptcha-forms.php';

$build = isset( $argv[1] ) ? $argv[1] : trim( (string) shell_exec( 'git describe --tags --always 2>/dev/null' ) );

if ( '' === $build ) {
	fwrite( STDERR, "stamp-version: nenhuma versão informada e `git describe` não devolveu nada.\n" );
	exit( 1 );
}

if ( ! preg_match( '/^v?(\d+\.\d+\.\d+)/', $build, $m ) ) {
	fwrite( STDERR, "stamp-version: '$build' não contém MAJOR.MINOR.PATCH.\n" );
	exit( 1 );
}

$core = $m[1];

$source = file_get_contents( $plugin_file );

if ( false === $source ) {
	fwrite( STDERR, "stamp-version: não consegui ler $plugin_file\n" );
	exit( 1 );
}

$source = preg_replace( '/^(\s*\*\s*Version:\s+).*$/m', '${1}' . $core, $source, 1 );
$source = preg_replace( "/define\( 'WP_RECAPTCHA_FORMS_VERSION', '[^']*' \);/", "define( 'WP_RECAPTCHA_FORMS_VERSION', '$core' );", $source, 1 );
$source = preg_replace( "/define\( 'WP_RECAPTCHA_FORMS_BUILD', '[^']*' \);/", "define( 'WP_RECAPTCHA_FORMS_BUILD', '$build' );", $source, 1 );

file_put_contents( $plugin_file, $source );

echo "stamp-version: header e WP_RECAPTCHA_FORMS_VERSION = $core; WP_RECAPTCHA_FORMS_BUILD = $build\n";
