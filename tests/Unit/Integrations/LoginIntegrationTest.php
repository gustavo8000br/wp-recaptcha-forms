<?php
/**
 * Fronteira do login (Story 1.8, arquitetura v1.1 §6.3 e §6.6).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Gate\ClientState;
use WpRecaptchaForms\Gate\Gate;
use WpRecaptchaForms\Integrations\Core\LoginIntegration;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\FailureClass;
use WpRecaptchaForms\Provider\ProviderResponse;
use WpRecaptchaForms\Runtime;
use WpRecaptchaForms\Tests\Support\FakeProvider;
use WpStubs;

/**
 * A story de maior risco de escolha errada silenciosa: um `applies()` frouxo tranca
 * Jetpack, o app oficial e o WP-CLI para fora sem nenhum sintoma no formulário HTML.
 *
 * As constantes `XMLRPC_REQUEST`, `REST_REQUEST` e `WP_CLI` não podem ser desdefinidas
 * dentro do mesmo processo, então cada uma roda em processo separado.
 */
final class LoginIntegrationTest extends TestCase {

	/**
	 * Integração sob teste.
	 *
	 * @var LoginIntegration
	 */
	private $integration;

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();

		$this->integration = new LoginIntegration();

		Options::update(
			array_merge(
				Options::defaults(),
				array(
					'site_key'   => 'site',
					'secret_key' => 'secret',
					'forms'      => array( 'wp_login' => array( 'enabled' => true ) ),
				)
			)
		);

		// Requisição que SATISFAZ todas as condições: cada teste derruba uma só.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['wp-submit']        = 'Acessar';
	}

	/**
	 * Limpeza.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_METHOD'], $_POST['wp-submit'] );
	}

	/**
	 * A linha de base: POST do formulário HTML, visitante anônimo.
	 *
	 * @return void
	 */
	public function test_applies_to_the_html_login_form(): void {
		$this->assertTrue( $this->integration->applies() );
	}

	/**
	 * Sem `wp-submit` não é o formulário renderizado. É a condição decisiva.
	 *
	 * @return void
	 */
	public function test_does_not_apply_without_wp_submit(): void {
		unset( $_POST['wp-submit'] );

		$this->assertFalse( $this->integration->applies() );
	}

	/**
	 * GET nunca é submissão de login.
	 *
	 * @return void
	 */
	public function test_does_not_apply_to_get(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertFalse( $this->integration->applies() );
	}

	/**
	 * Cron.
	 *
	 * @return void
	 */
	public function test_does_not_apply_during_cron(): void {
		WpStubs::$env['doing_cron'] = true;

		$this->assertFalse( $this->integration->applies() );
	}

	/**
	 * AJAX de terceiro.
	 *
	 * @return void
	 */
	public function test_does_not_apply_during_ajax(): void {
		WpStubs::$env['doing_ajax'] = true;

		$this->assertFalse( $this->integration->applies() );
	}

	/**
	 * Quem já tem sessão não é bot a barrar — e isto remove um caminho de lockout.
	 *
	 * @return void
	 */
	public function test_does_not_apply_to_logged_in_user(): void {
		WpStubs::$env['logged_in'] = true;

		$this->assertFalse( $this->integration->applies() );
	}

	/**
	 * XML-RPC: Jetpack e o app oficial.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_does_not_apply_to_xmlrpc(): void {
		define( 'XMLRPC_REQUEST', true );

		$this->assertFalse( $this->integration->applies() );
	}

	/**
	 * REST API e application passwords.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_does_not_apply_to_rest(): void {
		define( 'REST_REQUEST', true );

		$this->assertFalse( $this->integration->applies() );
	}

	/**
	 * WP-CLI.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_does_not_apply_to_wp_cli(): void {
		define( 'WP_CLI', true );

		$this->assertFalse( $this->integration->applies() );
	}

	/**
	 * Token vazio e nenhuma declaração do cliente: é o que um bot produz.
	 *
	 * @return void
	 */
	public function test_blocks_with_empty_token_and_no_client_state(): void {
		$user   = new \WP_User();
		$result = $this->integration->check( $user, 'admin', 'senha' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wrf_login_blocked', $result->get_error_code() );
	}

	/**
	 * Visitante com bloqueador, política padrão (permitir): autentica.
	 *
	 * @return void
	 */
	public function test_allows_client_unreachable_under_default_policy(): void {
		$_POST[ ClientState::FIELD ] = ClientState::NO_SCRIPT;

		$user = new \WP_User();

		$this->assertSame( $user, $this->integration->check( $user, 'admin', 'senha' ) );
	}

	/**
	 * Mesmo caso sob política de bloqueio: código PRÓPRIO, não o de bot.
	 *
	 * Um código genérico impediria o operador de distinguir "bot barrado" de "meu
	 * bloqueador quebrou meu login".
	 *
	 * @return void
	 */
	public function test_blocks_client_unreachable_with_its_own_code(): void {
		$_POST[ ClientState::FIELD ] = ClientState::NO_SCRIPT;

		$options                                      = Options::all();
		$options['failure_policy_client_unreachable'] = 'block';
		Options::update( $options );

		$result = $this->integration->check( new \WP_User(), 'admin', 'senha' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wrf_login_client_unreachable', $result->get_error_code() );
	}

	/**
	 * Token verificado e aprovado: passa adiante intocado.
	 *
	 * @return void
	 */
	public function test_allows_verified_token(): void {
		$_POST['wrf_token'] = 'token-bom';

		Runtime::set_gate( new Gate( new FakeProvider( new ProviderResponse( true, true, null, 0.9, 'wrf_wp_login', 'example.test' ) ) ) );

		$user = new \WP_User();

		$this->assertSame( $user, $this->integration->check( $user, 'admin', 'senha' ) );
	}

	/**
	 * Toggle desligado: passa sem avaliar nada.
	 *
	 * @return void
	 */
	public function test_disabled_toggle_short_circuits(): void {
		$options                   = Options::all();
		$options['forms']          = array( 'wp_login' => array( 'enabled' => false ) );
		Options::update( $options );

		$provider = new FakeProvider( new ProviderResponse( false, false, FailureClass::INFRA ) );
		Runtime::set_gate( new Gate( $provider ) );

		$user = new \WP_User();

		$this->assertSame( $user, $this->integration->check( $user, 'admin', 'senha' ) );
		$this->assertSame( 0, $provider->calls );
	}

	/**
	 * Credencial já reprovada pelo core: não gastamos cota do provedor com senha errada.
	 *
	 * @return void
	 */
	public function test_leaves_previous_error_untouched(): void {
		$error = new \WP_Error( 'incorrect_password', 'errada' );

		$this->assertSame( $error, $this->integration->check( $error, 'admin', 'errada' ) );
	}
}
