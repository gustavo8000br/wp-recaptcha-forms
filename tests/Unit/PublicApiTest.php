<?php
/**
 * API pública para terceiros (Story 1.10, arquitetura v1 §12).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Gate\ClientState;
use WpRecaptchaForms\Gate\Gate;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\ProviderResponse;
use WpRecaptchaForms\Runtime;
use WpRecaptchaForms\Tests\Support\FakeProvider;
use WpStubs;

/**
 * Contrato estável desde a v1: qualquer mudança de assinatura aqui é MAJOR.
 */
final class PublicApiTest extends TestCase {

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();

		Options::update(
			array_merge(
				Options::defaults(),
				array(
					'site_key'   => 'site',
					'secret_key' => 'secret',
					'forms'      => array( 'meu_form' => array( 'enabled' => true ) ),
				)
			)
		);
	}

	/**
	 * O campo impresso é hidden e VAZIO: nada específico de usuário, nada com validade,
	 * logo o HTML continua cacheável.
	 *
	 * @return void
	 */
	public function test_render_field_prints_empty_hidden_fields(): void {
		ob_start();
		wp_recaptcha_forms_render_field( 'meu_form' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="wrf_token" value=""', $html );
		$this->assertStringContainsString( 'name="wrf_action" value="wrf_meu_form"', $html );
		$this->assertStringContainsString( 'name="wrf_cstate" value=""', $html );
	}

	/**
	 * A `action` do v3 é sobrescrevível.
	 *
	 * @return void
	 */
	public function test_render_field_accepts_custom_action(): void {
		ob_start();
		wp_recaptcha_forms_render_field( 'meu_form', array( 'action' => 'wrf_custom' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="wrf_custom"', $html );
	}

	/**
	 * Recusa devolve `WP_Error`, nunca exceção: é o idioma nativo do WordPress, e quem
	 * chama já tem `is_wp_error()` no dedo.
	 *
	 * @return void
	 */
	public function test_verify_returns_wp_error_without_try_catch(): void {
		$result = wp_recaptcha_forms_verify( 'meu_form' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'wrf_rejected', $result->get_error_code() );
	}

	/**
	 * Token verificado: `true`.
	 *
	 * @return void
	 */
	public function test_verify_returns_true_for_valid_token(): void {
		$_POST['wrf_token'] = 'token-bom';

		Runtime::set_gate( new Gate( new FakeProvider( new ProviderResponse( true, true, null, 0.9 ) ) ) );

		$this->assertTrue( wp_recaptcha_forms_verify( 'meu_form' ) );
	}

	/**
	 * Bloqueador de anúncios com a política padrão: passa.
	 *
	 * @return void
	 */
	public function test_verify_allows_client_unreachable(): void {
		$_POST[ ClientState::FIELD ] = ClientState::NO_SCRIPT;

		$this->assertTrue( wp_recaptcha_forms_verify( 'meu_form' ) );
	}

	/**
	 * Estado do plugin e do formulário.
	 *
	 * @return void
	 */
	public function test_is_active(): void {
		$this->assertTrue( wp_recaptcha_forms_is_active() );
		$this->assertTrue( wp_recaptcha_forms_is_active( 'meu_form' ) );
		$this->assertFalse( wp_recaptcha_forms_is_active( 'outro_form' ) );
	}

	/**
	 * Sem chaves configuradas, nada está ativo.
	 *
	 * @return void
	 */
	public function test_is_active_without_keys(): void {
		Options::update( Options::defaults() );

		$this->assertFalse( wp_recaptcha_forms_is_active() );
	}

	/**
	 * O filtro documentado desliga a proteção por contexto — o exemplo canônico é
	 * ambiente de staging, não bypass por capability de usuário logado.
	 *
	 * @return void
	 */
	public function test_should_protect_filter_disables_verification(): void {
		add_filter(
			'wp_recaptcha_forms_should_protect',
			static function () {
				return false;
			}
		);

		$this->assertTrue( wp_recaptcha_forms_verify( 'meu_form' ) );
	}

	/**
	 * O filtro de mensagem alcança a recusa entregue por esta API.
	 *
	 * @return void
	 */
	public function test_error_message_filter_applies(): void {
		add_filter(
			'wp_recaptcha_forms_error_message',
			static function () {
				return 'texto do operador';
			}
		);

		$result = wp_recaptcha_forms_verify( 'meu_form' );

		$this->assertSame( 'texto do operador', $result->get_error_message() );
	}
}
