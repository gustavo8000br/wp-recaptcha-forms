<?php
/**
 * Resolução do consentimento (Story 1.16, arquitetura v1.1 §2.3).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Consent\ConsentGate;
use WpRecaptchaForms\Frontend\AssetManager;
use WpRecaptchaForms\Options;
use WpStubs;

/**
 * Cinco passos, ordem fixa, primeira resposta booleana ganha.
 *
 * O caso que mais importa é o do `off`: se o default gatilhasse o gating, todo site sem
 * plataforma de consentimento instalaria o plugin e ele nunca funcionaria. O opt-in aqui é
 * do gating, não do plugin.
 */
final class ConsentGateTest extends TestCase {

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
	}

	/**
	 * Configura o modo.
	 *
	 * @param string $mode Modo.
	 * @return void
	 */
	private function mode( string $mode ): void {
		Options::update( array_merge( Options::defaults(), array( 'consent_mode' => $mode ) ) );
	}

	/**
	 * Default é `off` e carrega sempre.
	 *
	 * @return void
	 */
	public function test_off_is_the_default_and_grants(): void {
		$this->assertSame( ConsentGate::MODE_OFF, ConsentGate::mode() );
		$this->assertTrue( ConsentGate::granted() );
	}

	/**
	 * Em `off` o filtro nem é consultado — é curto-circuito, não coincidência.
	 *
	 * @return void
	 */
	public function test_off_short_circuits_the_filter(): void {
		$this->mode( ConsentGate::MODE_OFF );

		add_filter(
			'wp_recaptcha_forms_consent_granted',
			static function () {
				return false;
			}
		);

		$this->assertTrue( ConsentGate::granted() );
	}

	/**
	 * Em `required` sem provedor nenhum, nega.
	 *
	 * @return void
	 */
	public function test_required_without_provider_denies(): void {
		$this->mode( ConsentGate::MODE_REQUIRED );

		$this->assertFalse( ConsentGate::granted() );
	}

	/**
	 * Em `auto` sem provedor nenhum, degrada para `off`.
	 *
	 * @return void
	 */
	public function test_auto_without_provider_behaves_like_off(): void {
		$this->mode( ConsentGate::MODE_AUTO );

		$this->assertTrue( ConsentGate::granted() );
	}

	/**
	 * O filtro decide, e recebe o formulário — um operador pode exigir consentimento na
	 * loja e não no wp-login.php.
	 *
	 * @return void
	 */
	public function test_filter_decides_and_receives_form_id(): void {
		$this->mode( ConsentGate::MODE_REQUIRED );

		$seen = null;

		add_filter(
			'wp_recaptcha_forms_consent_granted',
			static function ( $granted, $form_id ) use ( &$seen ) {
				$seen = $form_id;

				return 'wp_login' === $form_id;
			},
			10,
			2
		);

		$this->assertTrue( ConsentGate::granted( 'wp_login' ) );
		$this->assertSame( 'wp_login', $seen );
		$this->assertFalse( ConsentGate::granted( 'woocommerce_checkout' ) );
	}

	/**
	 * O helper do integrador vale o mesmo que o filtro, sem closure.
	 *
	 * @return void
	 */
	public function test_helper_forces_the_decision(): void {
		$this->mode( ConsentGate::MODE_REQUIRED );

		wp_recaptcha_forms_set_consent( true );

		$this->assertTrue( ConsentGate::granted() );

		wp_recaptcha_forms_set_consent( false );

		$this->assertFalse( ConsentGate::granted() );
	}

	/**
	 * Sob gating negado, o script do provedor NÃO é dependência do nosso bundle: é isso
	 * que o impede de entrar no DOM no page load.
	 *
	 * @return void
	 */
	public function test_provider_is_not_enqueued_without_consent(): void {
		$this->mode( ConsentGate::MODE_REQUIRED );

		$this->assertSame( array(), AssetManager::provider_dependency() );

		wp_recaptcha_forms_set_consent( true );

		$this->assertSame( array( AssetManager::HANDLE_PROVIDER ), AssetManager::provider_dependency() );
	}

	/**
	 * O estado resolvido aparece na tela para o operador não descobrir em produção que
	 * ligou `required` sem nada para conceder.
	 *
	 * @return void
	 */
	public function test_status_text_reports_missing_provider(): void {
		$this->mode( ConsentGate::MODE_REQUIRED );

		$this->assertStringContainsString( 'nenhum provedor detectado', ConsentGate::status_text() );
	}
}
