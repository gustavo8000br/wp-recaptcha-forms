<?php
/**
 * Formulários nativos: comentário, registro e lost-password (Story 1.7).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Gate\ClientState;
use WpRecaptchaForms\Gate\Gate;
use WpRecaptchaForms\Integrations\Core\CommentIntegration;
use WpRecaptchaForms\Integrations\Core\LostPasswordIntegration;
use WpRecaptchaForms\Integrations\Core\RegisterIntegration;
use WpRecaptchaForms\Integrations\WooCommerce\LostPasswordIntegration as WooLostPasswordIntegration;
use WpRecaptchaForms\Integrations\WooCommerce\ReviewIntegration;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\ProviderResponse;
use WpRecaptchaForms\Runtime;
use WpRecaptchaForms\Tests\Support\FakeProvider;
use WpDieException;
use WpStubs;

/**
 * Cada adaptador traduz o mesmo `Verdict` para o idioma de erro do seu host, e nenhum
 * deles reavalia nada.
 */
final class NativeFormsTest extends TestCase {

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
					'forms'      => array(
						'wp_comment'               => array( 'enabled' => true ),
						'wp_register'              => array( 'enabled' => true ),
						'wp_lostpassword'          => array( 'enabled' => true ),
						'woocommerce_review'       => array( 'enabled' => true ),
						'woocommerce_lostpassword' => array( 'enabled' => true ),
					),
				)
			)
		);

		// Post 1 é post comum; post 2 é produto.
		WpStubs::$post_types = array(
			1 => 'post',
			2 => 'product',
		);
	}

	/**
	 * Token ausente e sem declaração do cliente: comentário bloqueado com wp_die.
	 *
	 * @return void
	 */
	public function test_comment_blocked_without_token(): void {
		$this->expectException( WpDieException::class );

		( new CommentIntegration() )->check( array( 'comment_post_ID' => 1 ) );
	}

	/**
	 * Comentário em PRODUTO não é do adaptador de comentário: é review, com toggle próprio.
	 *
	 * Sem esta separação os dois adaptadores rodariam sobre a mesma submissão e o
	 * `wp_die` sairia duas vezes.
	 *
	 * @return void
	 */
	public function test_comment_integration_ignores_products(): void {
		$data = array( 'comment_post_ID' => 2 );

		$this->assertSame( $data, ( new CommentIntegration() )->check( $data ) );
	}

	/**
	 * E o de review ignora tudo que não é produto.
	 *
	 * @return void
	 */
	public function test_review_integration_ignores_non_products(): void {
		$data = array( 'comment_post_ID' => 1 );

		$this->assertSame( $data, ( new ReviewIntegration() )->check( $data ) );
	}

	/**
	 * Review sem token bloqueia pelo mesmo pipeline do comentário.
	 *
	 * @return void
	 */
	public function test_review_blocked_without_token(): void {
		$this->expectException( WpDieException::class );

		( new ReviewIntegration() )->check( array( 'comment_post_ID' => 2 ) );
	}

	/**
	 * Token válido: o comentário segue para o WordPress intocado.
	 *
	 * @return void
	 */
	public function test_comment_allowed_with_valid_token(): void {
		$_POST['wrf_token'] = 'token-bom';

		Runtime::set_gate( new Gate( new FakeProvider( new ProviderResponse( true, true, null, 0.9 ) ) ) );

		$data = array( 'comment_post_ID' => 1 );

		$this->assertSame( $data, ( new CommentIntegration() )->check( $data ) );
	}

	/**
	 * Toggle desligado: passa sem chamar o provedor.
	 *
	 * @return void
	 */
	public function test_comment_toggle_off_does_not_call_provider(): void {
		$options          = Options::all();
		$options['forms'] = array( 'wp_comment' => array( 'enabled' => false ) );
		Options::update( $options );

		$provider = new FakeProvider( new ProviderResponse( true, true, null, 0.9 ) );
		Runtime::set_gate( new Gate( $provider ) );

		$data = array( 'comment_post_ID' => 1 );

		$this->assertSame( $data, ( new CommentIntegration() )->check( $data ) );
		$this->assertSame( 0, $provider->calls );
	}

	/**
	 * Registro: o erro entra no WP_Error do hook, que é o que preserva login e e-mail
	 * digitados no reload do wp-login.php.
	 *
	 * @return void
	 */
	public function test_registration_adds_error(): void {
		$errors = new \WP_Error();

		$result = ( new RegisterIntegration() )->check( $errors, 'fulano', 'fulano@example.test' );

		$this->assertTrue( $result->has_errors() );
		$this->assertSame( 'wrf_register_blocked', $result->get_error_code() );
	}

	/**
	 * Registro com bloqueador e política padrão: nenhum erro acrescentado.
	 *
	 * @return void
	 */
	public function test_registration_allows_client_unreachable(): void {
		$_POST[ ClientState::FIELD ] = ClientState::NO_SCRIPT;

		$errors = ( new RegisterIntegration() )->check( new \WP_Error(), 'fulano', 'fulano@example.test' );

		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * Lost-password nativo bloqueia.
	 *
	 * @return void
	 */
	public function test_lostpassword_adds_error(): void {
		$errors = new \WP_Error();

		( new LostPasswordIntegration() )->check( $errors );

		$this->assertSame( 'wrf_lostpassword_blocked', $errors->get_error_code() );
	}

	/**
	 * O MESMO `lostpassword_post` é disparado pelo WooCommerce. O campo `wc_reset_password`
	 * é o discriminador: sem ele, os dois adaptadores acusariam a mesma submissão.
	 *
	 * @return void
	 */
	public function test_native_lostpassword_defers_to_woocommerce(): void {
		$_POST['wc_reset_password'] = 'true';

		$errors = new \WP_Error();

		( new LostPasswordIntegration() )->check( $errors );

		$this->assertFalse( $errors->has_errors() );

		$woo = new \WP_Error();

		( new WooLostPasswordIntegration() )->check( $woo );

		$this->assertSame( 'wrf_wc_lostpassword_blocked', $woo->get_error_code() );
	}

	/**
	 * E o do Woo não toca no fluxo do wp-login.php.
	 *
	 * @return void
	 */
	public function test_woocommerce_lostpassword_ignores_native_form(): void {
		$errors = new \WP_Error();

		( new WooLostPasswordIntegration() )->check( $errors );

		$this->assertFalse( $errors->has_errors() );
	}
}
