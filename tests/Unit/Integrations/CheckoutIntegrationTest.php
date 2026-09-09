<?php
/**
 * Checkout do WooCommerce: clássico e Store API (Stories 1.12 e 1.13).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Integrations;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Gate\ClientState;
use WpRecaptchaForms\Gate\Gate;
use WpRecaptchaForms\Integrations\WooCommerce\CheckoutIntegration;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\ProviderResponse;
use WpRecaptchaForms\Runtime;
use WpRecaptchaForms\Tests\Support\FakeProvider;
use WpStubs;

/**
 * O risco central desta área é a DIVERGÊNCIA entre os dois caminhos: um checkout que
 * bloqueia no Blocks e passa no clássico (ou o contrário) é pior que qualquer um dos dois
 * comportamentos aplicado consistentemente, porque ninguém consegue reproduzir.
 *
 * Por isso os testes abaixo exercitam os dois lados com as MESMAS entradas.
 */
final class CheckoutIntegrationTest extends TestCase {

	/**
	 * Integração sob teste.
	 *
	 * @var CheckoutIntegration
	 */
	private $integration;

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();

		$this->integration = new CheckoutIntegration();

		Options::update(
			array_merge(
				Options::defaults(),
				array(
					'site_key'   => 'site',
					'secret_key' => 'secret',
					'forms'      => array( 'woocommerce_checkout' => array( 'enabled' => true ) ),
				)
			)
		);
	}

	/**
	 * Um formulário, um toggle: o `form_id` e a `action` são os mesmos nos dois caminhos.
	 *
	 * @return void
	 */
	public function test_classic_and_blocks_share_form_id_and_action(): void {
		$this->assertSame( 'woocommerce_checkout', $this->integration->id() );
		$this->assertSame( 'wrf_woocommerce_checkout', $this->integration->action() );
	}

	/**
	 * O schema declara os DOIS campos. Só o token faria o Blocks bloquear visitantes com
	 * bloqueador enquanto o clássico os deixa passar.
	 *
	 * @return void
	 */
	public function test_store_api_schema_declares_token_and_cstate(): void {
		$schema = $this->integration->store_api_schema();

		$this->assertArrayHasKey( 'token', $schema );
		$this->assertArrayHasKey( 'cstate', $schema );
		$this->assertSame( 'string', $schema['cstate']['type'] );
	}

	/**
	 * Clássico: sem token e sem declaração, o erro entra no WP_Error do Woo — que é o que
	 * preserva os campos preenchidos.
	 *
	 * @return void
	 */
	public function test_classic_blocks_without_token(): void {
		$errors = new \WP_Error();

		$this->integration->check_classic( array(), $errors );

		$this->assertSame( 'wrf_checkout_blocked', $errors->get_error_code() );
	}

	/**
	 * Clássico com token bom: nenhum erro.
	 *
	 * @return void
	 */
	public function test_classic_allows_valid_token(): void {
		$_POST['wrf_token'] = 'token-bom';

		Runtime::set_gate( new Gate( new FakeProvider( new ProviderResponse( true, true, null, 0.9 ) ) ) );

		$errors = new \WP_Error();

		$this->integration->check_classic( array(), $errors );

		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * Store API: sem extensão nenhuma na requisição, bloqueia com 400.
	 *
	 * @return void
	 */
	public function test_store_api_blocks_without_extension_data(): void {
		try {
			$this->integration->check_store_api( new \WC_Order(), array( 'extensions' => array() ) );
			$this->fail( 'esperava RouteException' );
		} catch ( RouteException $exception ) {
			$this->assertSame( 'wrf_checkout_blocked', $exception->getErrorCode() );
			$this->assertSame( 400, $exception->getStatusCode() );
		}
	}

	/**
	 * Store API com `cstate` de bloqueador e política padrão: passa, igual ao clássico.
	 *
	 * @return void
	 */
	public function test_store_api_allows_client_unreachable_under_default_policy(): void {
		$this->integration->check_store_api(
			new \WC_Order(),
			array(
				'extensions' => array(
					'wp-recaptcha-forms' => array(
						'token'  => '',
						'cstate' => ClientState::NO_SCRIPT,
					),
				),
			)
		);

		$this->assertTrue( true, 'nenhuma exceção: o envio passou' );
	}

	/**
	 * Sob política de bloqueio, a mensagem é a do bloqueador, não a de bot — e o código
	 * de erro também.
	 *
	 * @return void
	 */
	public function test_store_api_blocks_client_unreachable_with_distinct_code(): void {
		$options                                      = Options::all();
		$options['failure_policy_client_unreachable'] = 'block';
		Options::update( $options );

		try {
			$this->integration->check_store_api(
				new \WC_Order(),
				array(
					'extensions' => array(
						'wp-recaptcha-forms' => array(
							'token'  => '',
							'cstate' => ClientState::CONSENT_PENDING,
						),
					),
				)
			);
			$this->fail( 'esperava RouteException' );
		} catch ( RouteException $exception ) {
			$this->assertSame( 'wrf_checkout_client_unreachable', $exception->getErrorCode() );
			$this->assertStringContainsString( 'bloqueador', $exception->getMessage() );
		}
	}

	/**
	 * Token válido na Store API: passa.
	 *
	 * @return void
	 */
	public function test_store_api_allows_valid_token(): void {
		Runtime::set_gate( new Gate( new FakeProvider( new ProviderResponse( true, true, null, 0.9 ) ) ) );

		$this->integration->check_store_api(
			new \WC_Order(),
			array(
				'extensions' => array(
					'wp-recaptcha-forms' => array(
						'token'  => 'token-bom',
						'cstate' => '',
					),
				),
			)
		);

		$this->assertTrue( true, 'nenhuma exceção: o pedido segue' );
	}

	/**
	 * Um `cstate` inventado não compra fail-open: cai em token vazio sem declaração, que
	 * é o que um bot produz.
	 *
	 * @return void
	 */
	public function test_store_api_rejects_unknown_client_state(): void {
		$this->expectException( RouteException::class );

		$this->integration->check_store_api(
			new \WC_Order(),
			array(
				'extensions' => array(
					'wp-recaptcha-forms' => array(
						'token'  => '',
						'cstate' => 'inventado',
					),
				),
			)
		);
	}
}
