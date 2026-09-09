<?php
/**
 * Aviso de privacidade (Story 1.17, FR-31/FR-32).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Consent\ConsentGate;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Privacy\PrivacyNotice;
use WpRecaptchaForms\Privacy\PrivacyShortcode;
use WpStubs;

/**
 * FR-32 é a parte que importa: um texto que descreva um comportamento que a instalação não
 * tem é pior que nenhum texto.
 */
final class PrivacyNoticeTest extends TestCase {

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
	}

	/**
	 * Grava opções.
	 *
	 * @param array $values Sobrescritas.
	 * @return void
	 */
	private function configure( array $values ): void {
		Options::update( array_merge( Options::defaults(), $values ) );
	}

	/**
	 * Com o envio de IP ligado, o texto diz que o servidor envia o IP.
	 *
	 * @return void
	 */
	public function test_mentions_server_side_ip_when_enabled(): void {
		$this->configure( array( 'remoteip' => true ) );

		$text = PrivacyNotice::render( array( 'format' => 'plain' ) );

		$this->assertStringContainsString( 'enviado ao Google pelo servidor', $text );
	}

	/**
	 * Com o envio desligado, entra a frase que impede a crença errada — desligar não
	 * esconde o visitante do Google, porque o script vem do domínio dele.
	 *
	 * @return void
	 */
	public function test_states_non_compliance_when_ip_is_disabled(): void {
		$this->configure( array( 'remoteip' => false ) );

		$text = PrivacyNotice::render( array( 'format' => 'plain' ) );

		$this->assertStringNotContainsString( 'enviado ao Google pelo servidor', $text );
		$this->assertStringContainsString( 'não impede que o Google receba o endereço IP', $text );
	}

	/**
	 * Sob gating de consentimento, o texto diz que o script só carrega depois do aceite.
	 *
	 * @return void
	 */
	public function test_mentions_consent_when_gating_is_on(): void {
		$this->configure( array( 'consent_mode' => ConsentGate::MODE_REQUIRED ) );

		$this->assertStringContainsString( 'depois que o visitante concede consentimento', PrivacyNotice::render( array( 'format' => 'plain' ) ) );

		$this->configure( array( 'consent_mode' => ConsentGate::MODE_OFF ) );

		$this->assertStringNotContainsString( 'depois que o visitante concede consentimento', PrivacyNotice::render( array( 'format' => 'plain' ) ) );
	}

	/**
	 * A delimitação vem primeiro, e não escondida no fim: prometer conformidade seria a
	 * "diferenciação inexistente" que o PRD proíbe — e esta tem consequência legal para o
	 * operador.
	 *
	 * @return void
	 */
	public function test_disclaimer_comes_first(): void {
		$paragraphs = PrivacyNotice::paragraphs();

		$this->assertStringContainsString( 'não é aconselhamento jurídico', $paragraphs[0] );
	}

	/**
	 * As duas URLs do Google estão no texto.
	 *
	 * @return void
	 */
	public function test_includes_google_urls(): void {
		$text = PrivacyNotice::render( array( 'format' => 'plain' ) );

		$this->assertStringContainsString( PrivacyNotice::URL_PRIVACY, $text );
		$this->assertStringContainsString( PrivacyNotice::URL_TERMS, $text );
	}

	/**
	 * HTML sai em parágrafos; plain sai sem tag nenhuma.
	 *
	 * @return void
	 */
	public function test_formats(): void {
		$this->assertStringContainsString( '<p>', PrivacyNotice::render() );
		$this->assertStringNotContainsString( '<p>', PrivacyNotice::render( array( 'format' => 'plain' ) ) );
	}

	/**
	 * O shortcode entrega o mesmo texto, e respeita o atributo `format`.
	 *
	 * @return void
	 */
	public function test_shortcode(): void {
		$this->assertStringContainsString( '<p>', PrivacyShortcode::render( array() ) );
		$this->assertStringNotContainsString( '<p>', PrivacyShortcode::render( array( 'format' => 'plain' ) ) );
	}

	/**
	 * E a ferramenta nativa de privacidade recebe o texto.
	 *
	 * @return void
	 */
	public function test_registers_native_policy_content(): void {
		PrivacyShortcode::register_policy_content();

		$this->assertCount( 1, WpStubs::$privacy_content );
		$this->assertStringContainsString( 'reCAPTCHA', WpStubs::$privacy_content[0][1] );
	}

	/**
	 * O helper público é a superfície que o operador chama do tema.
	 *
	 * @return void
	 */
	public function test_public_helper(): void {
		$this->assertStringContainsString( '<p>', wp_recaptcha_forms_privacy_notice() );
	}
}
