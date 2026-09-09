<?php
/**
 * Isolamento das classes de carga condicional (Story 1.11 AC3).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Autoloader;

/**
 * O fatal error clássico de plugin de WooCommerce não vem de `class_exists` insuficiente:
 * vem de `implements`, que o PHP resolve no LOAD da classe, antes de qualquer guard dentro
 * dela poder rodar.
 *
 * Este teste é o que impede a regressão silenciosa — alguém "organiza" o arquivo de volta
 * para o diretório normal, o autoloader passa a alcançá-lo, e toda instalação sem
 * WooCommerce quebra na próxima release.
 */
final class ConditionalLoadTest extends TestCase {

	/**
	 * Arquivos anotados.
	 *
	 * @return string[]
	 */
	private function annotated_files(): array {
		$found    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( WP_RECAPTCHA_FORMS_DIR . 'src' ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			if ( false !== strpos( (string) file_get_contents( $file->getPathname() ), '@wrf-conditional-load' ) ) {
				$found[] = $file->getPathname();
			}
		}

		return $found;
	}

	/**
	 * Existe ao menos um arquivo anotado — senão o teste passaria por vacuidade.
	 *
	 * @return void
	 */
	public function test_there_is_at_least_one_conditional_file(): void {
		$this->assertNotEmpty( $this->annotated_files() );
	}

	/**
	 * Nenhum arquivo anotado está no caminho que o PSR-4 procuraria.
	 *
	 * @return void
	 */
	public function test_conditional_files_are_not_autoloadable(): void {
		$root = dirname( __DIR__, 2 ) . '/src/';

		foreach ( $this->annotated_files() as $path ) {
			$source = (string) file_get_contents( $path );

			preg_match( '/^namespace\s+([^;]+);/m', $source, $namespace );
			preg_match( '/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $class );

			$this->assertNotEmpty( $namespace, "sem namespace: {$path}" );
			$this->assertNotEmpty( $class, "sem classe: {$path}" );

			$relative = str_replace( '\\', '/', substr( trim( $namespace[1] ), strlen( 'WpRecaptchaForms\\' ) ) );
			$expected = $root . $relative . '/' . $class[1] . '.php';

			$this->assertNotSame( realpath( $expected ), realpath( $path ), "o autoloader alcançaria {$path}" );
			$this->assertFileDoesNotExist( $expected, "existe um gêmeo autocarregável de {$path}" );
		}
	}

	/**
	 * E o autoloader, provocado com o nome da classe, não a carrega.
	 *
	 * @return void
	 */
	public function test_autoloader_does_not_resolve_the_blocks_class(): void {
		$class = 'WpRecaptchaForms\\Integrations\\WooCommerce\\BlocksCheckoutIntegration';

		Autoloader::load( $class );

		$this->assertFalse( class_exists( $class, false ) );
	}
}
