<?php
/**
 * Sanitização e sonda de chave (Story 1.5 AC3, AC4, AC5).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Admin\KeyValidator;
use WpRecaptchaForms\Admin\Sanitizer;
use WpRecaptchaForms\Gate\FailurePolicy;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Tests\Support\FakeTransport;
use WpStubs;

/**
 * A tela de config é a superfície onde a persona P1 pode se machucar sozinha.
 */
final class SettingsTest extends TestCase {

	/**
	 * Zera stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
	}

	/**
	 * Salvar o campo de secret em branco MANTÉM a secret atual.
	 *
	 * Sem isto, todo save da tela desligaria a verificação — o campo nunca é ecoado em
	 * claro, então o operador não tem como reenviar o valor.
	 *
	 * @return void
	 */
	public function test_blank_secret_keeps_current(): void {
		Options::update( array_merge( Options::defaults(), array( 'secret_key' => 'secret-atual' ) ) );

		$clean = Sanitizer::sanitize( array( 'secret_key' => '' ) );

		$this->assertSame( 'secret-atual', $clean['secret_key'] );
	}

	/**
	 * Uma secret nova substitui a anterior.
	 *
	 * @return void
	 */
	public function test_new_secret_replaces(): void {
		Options::update( array_merge( Options::defaults(), array( 'secret_key' => 'secret-atual' ) ) );

		$clean = Sanitizer::sanitize( array( 'secret_key' => 'secret-nova' ) );

		$this->assertSame( 'secret-nova', $clean['secret_key'] );
	}

	/**
	 * Threshold fora da faixa volta ao default; vírgula decimal é aceita.
	 *
	 * @return void
	 */
	public function test_threshold_is_bounded(): void {
		$this->assertSame( 0.75, Sanitizer::sanitize( array( 'threshold' => '0,75' ) )['threshold'] );
		$this->assertSame( 0.6, Sanitizer::sanitize( array( 'threshold' => '9' ) )['threshold'] );
		$this->assertSame( 0.6, Sanitizer::sanitize( array( 'threshold' => '-1' ) )['threshold'] );
	}

	/**
	 * Valor de política fora do vocabulário vira o default, nunca é gravado cru.
	 *
	 * @return void
	 */
	public function test_policy_values_are_whitelisted(): void {
		$clean = Sanitizer::sanitize(
			array(
				'failure_policy_infra'              => 'talvez',
				'failure_policy_client_unreachable' => 'block',
			)
		);

		$this->assertSame( FailurePolicy::ALLOW, $clean['failure_policy_infra'] );
		$this->assertSame( FailurePolicy::BLOCK, $clean['failure_policy_client_unreachable'] );
	}

	/**
	 * Os dois eixos por formulário gravam `inherit` explicitamente, nunca null.
	 *
	 * @return void
	 */
	public function test_form_axes_store_inherit_explicitly(): void {
		$clean = Sanitizer::sanitize(
			array(
				'forms' => array(
					'wp_login' => array( 'enabled' => '1' ),
				),
			)
		);

		$this->assertTrue( $clean['forms']['wp_login']['enabled'] );
		$this->assertSame( FailurePolicy::INHERIT, $clean['forms']['wp_login']['policy_infra'] );
		$this->assertSame( FailurePolicy::INHERIT, $clean['forms']['wp_login']['policy_client_unreachable'] );
		$this->assertNotNull( $clean['forms']['wp_login']['policy_infra'] );
	}

	/**
	 * Formulários ausentes do POST não são apagados.
	 *
	 * Sem WooCommerce ativo a seção não é renderizada; apagar o que ela guardava faria a
	 * reativação do Woo perder a configuração anterior.
	 *
	 * @return void
	 */
	public function test_absent_forms_are_preserved(): void {
		Options::update(
			array_merge(
				Options::defaults(),
				array(
					'forms' => array(
						'woocommerce_checkout' => array_merge( Options::form_defaults(), array( 'enabled' => true ) ),
					),
				)
			)
		);

		$clean = Sanitizer::sanitize( array( 'forms' => array( 'wp_login' => array( 'enabled' => '1' ) ) ) );

		$this->assertTrue( $clean['forms']['woocommerce_checkout']['enabled'] );
	}

	/**
	 * Mensagem do operador é texto livre — logo, não confiável.
	 *
	 * @return void
	 */
	public function test_messages_are_filtered(): void {
		$clean = Sanitizer::sanitize(
			array(
				'messages' => array( 'rejected' => '  Tente <strong>de novo</strong>  ' ),
			)
		);

		$this->assertSame( 'Tente <strong>de novo</strong>', $clean['messages']['rejected'] );
	}

	/**
	 * A sonda distingue secret recusada, provedor inalcançável e secret boa.
	 *
	 * @return void
	 */
	public function test_key_probe_outcomes(): void {
		$invalid = ( new FakeTransport() )->will_return_json(
			array(
				'success'     => false,
				'error-codes' => array( 'invalid-input-secret' ),
			)
		);
		$this->assertSame( 'invalid_secret', KeyValidator::probe( 'x', 'v3', $invalid )['reason'] );

		$down = ( new FakeTransport() )->will_fail( 'http_request_failed' );
		$this->assertSame( 'unreachable', KeyValidator::probe( 'x', 'v3', $down )['reason'] );

		// Recusa do token de sonda é o resultado ESPERADO: prova que a secret é boa.
		$good = ( new FakeTransport() )->will_return_json(
			array(
				'success'     => false,
				'error-codes' => array( 'invalid-input-response' ),
			)
		);
		$probe = KeyValidator::probe( 'x', 'v3', $good );
		$this->assertTrue( $probe['ok'] );
		$this->assertSame( 'valid_secret', $probe['reason'] );

		$this->assertFalse( KeyValidator::probe( '', 'v3', new FakeTransport() )['ok'] );
	}
}
