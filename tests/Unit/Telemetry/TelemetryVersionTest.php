<?php
/**
 * Redução de versão a MINOR (Story 1.29 AC 9).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Telemetry\Version;

/**
 * As bordas são o que importa: os sufixos de fornecedor do MySQL/MariaDB são a forma
 * mais provável de um número de patch vazar para o payload.
 */
final class TelemetryVersionTest extends TestCase {

	/**
	 * Casos.
	 *
	 * @return array<string, array{0:string, 1:string}>
	 */
	public function versions(): array {
		return array(
			'php com patch'    => array( '8.2.11', '8.2' ),
			'wp sem patch'     => array( '6.7', '6.7' ),
			'mariadb'          => array( '10.11.6-MariaDB', '10.11' ),
			'mysql com ubuntu' => array( '8.0.35-0ubuntu0.22.04.1', '8.0' ),
			'php com rc'       => array( '8.4.0RC1', '8.4' ),
			'wp com beta'      => array( '6.8-beta2', '6.8' ),
			'so major'         => array( '9', '9.0' ),
			'woo'              => array( '9.4.2', '9.4' ),
			'vazio'            => array( '', '' ),
			'sem numero'       => array( 'desconhecido', '' ),
		);
	}

	/**
	 * A versão sai sem componente de patch.
	 *
	 * @dataProvider versions
	 * @param string $full     Entrada.
	 * @param string $expected Saída esperada.
	 * @return void
	 */
	public function test_minor( string $full, string $expected ): void {
		$this->assertSame( $expected, Version::minor( $full ) );
	}
}
