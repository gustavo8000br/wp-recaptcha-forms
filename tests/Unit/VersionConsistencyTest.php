<?php
/**
 * Consistência da versão nos três lugares que precisam concordar (Story 1.18 AC 3).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * O header do plugin, a constante de versão e a constante de build.
 *
 * Os três são escritos por `bin/stamp-version.php` a partir da tag do git. Se algum dia
 * divergirem, o sintoma é péssimo e silencioso: o WordPress compara o header para decidir
 * se há atualização, então um header parado num número antigo faz todo mundo receber
 * update infinito, e um parado num número à frente faz ninguém receber a correção
 * seguinte.
 */
final class VersionConsistencyTest extends TestCase {

	/**
	 * Conteúdo do arquivo principal do plugin.
	 *
	 * @var string
	 */
	private static $source;

	/**
	 * Lê o arquivo principal uma vez.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::$source = (string) file_get_contents( WP_RECAPTCHA_FORMS_DIR . 'wp-recaptcha-forms.php' );
	}

	/**
	 * Extrai um valor do arquivo principal.
	 *
	 * @param string $pattern Regex com um grupo de captura.
	 * @return string
	 */
	private function grab( string $pattern ): string {
		$this->assertSame( 1, preg_match( $pattern, self::$source, $m ), "padrão não encontrado: $pattern" );

		return $m[1];
	}

	/**
	 * O header, a constante de versão e o núcleo do build são o mesmo número.
	 *
	 * @return void
	 */
	public function test_header_and_constants_agree(): void {
		$header  = $this->grab( '/^ \* Version:\s+(\S+)$/m' );
		$version = $this->grab( "/define\(\s*'WP_RECAPTCHA_FORMS_VERSION',\s*'([^']+)'/" );
		$build   = $this->grab( "/define\(\s*'WP_RECAPTCHA_FORMS_BUILD',\s*'([^']+)'/" );

		$this->assertSame( $header, $version, 'header do plugin e WP_RECAPTCHA_FORMS_VERSION divergiram' );

		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+$/',
			$header,
			'o header recebe SÓ MAJOR.MINOR.PATCH: um sufixo faria o WP considerar a build de release anterior à release'
		);

		$this->assertStringStartsWith(
			'v' . $header,
			$build,
			'WP_RECAPTCHA_FORMS_BUILD tem de começar pelo mesmo núcleo do header'
		);
	}

	/**
	 * O build segue o esquema `vMAJOR.MINOR.PATCH-HHHHHHH-stage`.
	 *
	 * @return void
	 */
	public function test_build_follows_the_scheme(): void {
		$build = $this->grab( "/define\(\s*'WP_RECAPTCHA_FORMS_BUILD',\s*'([^']+)'/" );

		$this->assertMatchesRegularExpression(
			'/^v\d+\.\d+\.\d+-[0-9a-f]{7}-(alpha|beta|rc|stable)$/',
			$build
		);
	}

	/**
	 * `composer.json` declara a mesma licença do header.
	 *
	 * Story 1.19 AC 3: duas declarações de licença que discordam são pior que uma só.
	 *
	 * @return void
	 */
	public function test_license_matches_composer(): void {
		$header   = $this->grab( '/^ \* License:\s+(\S+)$/m' );
		$composer = json_decode( (string) file_get_contents( WP_RECAPTCHA_FORMS_DIR . 'composer.json' ), true );

		$this->assertSame( $header, $composer['license'] );
		$this->assertSame( 'GPL-2.0-or-later', $header );
		$this->assertFileExists( WP_RECAPTCHA_FORMS_DIR . 'LICENSE' );
	}

	/**
	 * O `CHANGELOG.md` existe e tem a seção `[Unreleased]` (Story 1.18 AC 4).
	 *
	 * @return void
	 */
	public function test_changelog_has_unreleased_section(): void {
		$changelog = (string) file_get_contents( WP_RECAPTCHA_FORMS_DIR . 'CHANGELOG.md' );

		$this->assertStringContainsString( '## [Unreleased]', $changelog );
	}
}
