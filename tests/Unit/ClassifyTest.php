<?php
/**
 * Tabela normativa de classificação (Story 1.3 AC3, arquitetura v1.1 §4.2).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Provider\AbstractSiteverifyProvider;
use WpRecaptchaForms\Provider\FailureClass;
use WpStubs;

/**
 * O único lugar do sistema que traduz vocabulário do provedor em FailureClass.
 */
final class ClassifyTest extends TestCase {

	/**
	 * Zera stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
	}

	/**
	 * Cada linha da tabela normativa.
	 *
	 * @dataProvider provide_codes
	 *
	 * @param string[] $codes    Códigos.
	 * @param string   $expected Classe esperada.
	 * @return void
	 */
	public function test_classification_table( array $codes, string $expected ): void {
		$this->assertSame( $expected, AbstractSiteverifyProvider::classify( $codes ) );
	}

	/**
	 * Casos da tabela.
	 *
	 * @return array<string, array{0: string[], 1: string}>
	 */
	public function provide_codes(): array {
		return array(
			'secret invalida'                => array( array( 'invalid-input-secret' ), FailureClass::MISCONFIG ),
			'par de chaves invalido'         => array( array( 'invalid-keys' ), FailureClass::MISCONFIG ),
			'secret ausente'                 => array( array( 'missing-input-secret' ), FailureClass::MISCONFIG ),
			// OB-03: requisição malformada é bug do plugin ou config quebrada, nunca bot.
			// Como REJECTED bloquearia 100% dos visitantes sem escape e sem notice.
			'requisicao malformada'          => array( array( 'bad-request' ), FailureClass::MISCONFIG ),
			'token ausente'                  => array( array( 'missing-input-response' ), FailureClass::REJECTED ),
			'token invalido'                 => array( array( 'invalid-input-response' ), FailureClass::REJECTED ),
			'token ja usado'                 => array( array( 'timeout-or-duplicate' ), FailureClass::REJECTED ),
			// Fail-safe deliberado: um código novo do provedor não pode derrubar o site
			// nem criar notice crítico falso.
			'codigo desconhecido'            => array( array( 'cod-novo-do-google' ), FailureClass::INFRA ),
			'sem codigo nenhum'              => array( array(), FailureClass::INFRA ),
			// MISCONFIG tem precedência: é o que o operador precisa ver.
			'misconfig junto de rejected'    => array( array( 'invalid-input-response', 'invalid-input-secret' ), FailureClass::MISCONFIG ),
			'rejected junto de desconhecido' => array( array( 'cod-novo', 'timeout-or-duplicate' ), FailureClass::REJECTED ),
		);
	}
}
