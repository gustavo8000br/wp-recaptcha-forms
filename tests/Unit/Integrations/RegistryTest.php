<?php
/**
 * Registry e degradação sem WooCommerce (Story 1.11).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Integrations\Core\CommentIntegration;
use WpRecaptchaForms\Integrations\Newsletter\SubscribeIntegration;
use WpRecaptchaForms\Integrations\Registry;
use WpRecaptchaForms\Integrations\WooCommerce\CheckoutIntegration;
use WpRecaptchaForms\Integrations\WooCommerce\Support;
use WpRecaptchaForms\Options;
use WpStubs;

/**
 * A tela de configurações é GERADA a partir do registry: o que não está registrado não
 * aparece, e por isso a degradação sem WooCommerce acontece sozinha, sem uma linha de UI
 * condicional.
 */
final class RegistryTest extends TestCase {

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
	}

	/**
	 * Sem WooCommerce instalado, `class_exists('WooCommerce')` é falso e nada do Woo
	 * chega ao registry. O ambiente de teste unitário roda exatamente nessa condição.
	 *
	 * @return void
	 */
	public function test_woocommerce_is_absent_in_a_plain_install(): void {
		$this->assertFalse( Support::is_active() );
		$this->assertFalse( Support::blocks_supported() );
	}

	/**
	 * Indisponível não é oculto: a linha aparece COM o motivo, senão o operador fica sem
	 * entender por que aquele formulário não está protegido.
	 *
	 * @return void
	 */
	public function test_blocks_unavailable_reason_is_explicit(): void {
		$this->assertStringContainsString( '8.3', Support::blocks_unavailable_reason() );
	}

	/**
	 * Sem o plugin Newsletter, a integração se declara indisponível — e o Plugin nem a
	 * registra.
	 *
	 * @return void
	 */
	public function test_newsletter_requires_the_plugin(): void {
		$this->assertFalse( ( new SubscribeIntegration() )->available() );
	}

	/**
	 * `boot()` registra hooks só do que está disponível.
	 *
	 * @return void
	 */
	public function test_boot_registers_only_available_integrations(): void {
		$registry = new Registry();

		$registry->register( new CommentIntegration() );
		$registry->register( new SubscribeIntegration() );

		$registry->boot();

		$this->assertTrue( has_action( 'comment_form' ) );
		$this->assertFalse( has_action( 'newsletter_action' ) );
	}

	/**
	 * Agrupamento por seção da tela.
	 *
	 * @return void
	 */
	public function test_grouped_by_section(): void {
		$registry = new Registry();

		$registry->register( new CommentIntegration() );
		$registry->register( new CheckoutIntegration() );

		$grouped = $registry->grouped();

		$this->assertArrayHasKey( 'core', $grouped );
		$this->assertArrayHasKey( 'woocommerce', $grouped );
	}

	/**
	 * Desativar o WooCommerce não pode apagar os toggles dele: reativar restaura a
	 * configuração anterior. Quem apaga tudo é o uninstall, e só ele.
	 *
	 * @return void
	 */
	public function test_woocommerce_toggles_survive_deactivation(): void {
		Options::update(
			array_merge(
				Options::defaults(),
				array(
					'forms' => array(
						'woocommerce_checkout' => array( 'enabled' => true ),
					),
				)
			)
		);

		// Sanitiza um POST vindo de uma tela SEM a seção Woo (Woo desativado).
		$clean = \WpRecaptchaForms\Admin\Sanitizer::sanitize(
			array(
				'forms' => array(
					'wp_comment' => array( 'enabled' => '1' ),
				),
			)
		);

		$this->assertTrue( $clean['forms']['woocommerce_checkout']['enabled'] );
	}
}
