<?php
/**
 * Checkout do WooCommerce — clássico e Blocks.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations\WooCommerce;

use WpRecaptchaForms\Integrations\AbstractIntegration;
use WpRecaptchaForms\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Um formulário, dois caminhos de submissão (arquitetura v1 §8.1).
 *
 * Clássico (shortcode, POST) e Blocks (Store API, REST) compartilham `form_id` e `action`
 * de propósito: são o mesmo checkout para o operador, e dois toggles significariam uma
 * loja protegida pela metade sem ninguém perceber qual metade. A divergência entre os dois
 * caminhos é o risco central desta área — por isso o `cstate` entra no schema da Store API
 * junto do token (v1.1 §8.1), e não só o token.
 */
final class CheckoutIntegration extends AbstractIntegration {

	/** Namespace da extensão na Store API. */
	const EXTENSION_NAMESPACE = 'wp-recaptcha-forms';

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'woocommerce_checkout';
	}

	/**
	 * Rótulo.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Checkout (clássico e Blocks)', 'wp-recaptcha-forms' );
	}

	/**
	 * Grupo na tela.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'woocommerce';
	}

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Clássico.
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_field' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'check_classic' ), 10, 2 );

		// Blocks (servidor e cliente), só onde houver suporte.
		if ( ! Support::blocks_supported() ) {
			return;
		}

		$this->register_store_api_schema();

		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'check_store_api' ), 10, 2 );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_blocks_script' ) );
	}

	/**
	 * Valida o checkout clássico.
	 *
	 * `woocommerce_after_checkout_validation` e não `checkout_process`: aqui recebemos o
	 * `WP_Error` do próprio Woo, o erro entra no tratamento nativo e os campos preenchidos
	 * são preservados sem esforço extra (FR-09).
	 *
	 * @param array     $data   Dados do checkout.
	 * @param \WP_Error $errors Erros acumulados.
	 * @return void
	 */
	public function check_classic( $data, $errors = null ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		$verdict = $this->assess();

		if ( $verdict->is_blocked() ) {
			$errors->add( $this->error_code( $verdict, 'wrf_checkout' ), $verdict->message() );
		}
	}

	/**
	 * Declara os dois campos de extensão no schema do checkout da Store API.
	 *
	 * @return void
	 */
	private function register_store_api_schema(): void {
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'checkout',
				'namespace'       => self::EXTENSION_NAMESPACE,
				'schema_callback' => array( $this, 'store_api_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Schema dos campos de extensão.
	 *
	 * @return array
	 */
	public function store_api_schema(): array {
		return array(
			'token'  => array(
				'description' => __( 'Token de verificação obtido no navegador.', 'wp-recaptcha-forms' ),
				'type'        => 'string',
				'context'     => array(),
				'arg_options' => array(
					'validate_callback' => static function ( $value ) {
						return is_string( $value );
					},
				),
			),
			'cstate' => array(
				'description' => __( 'Estado declarado pelo navegador quando a verificação não pôde ser obtida.', 'wp-recaptcha-forms' ),
				'type'        => 'string',
				'context'     => array(),
				'arg_options' => array(
					'validate_callback' => static function ( $value ) {
						return is_string( $value );
					},
				),
			),
		);
	}

	/**
	 * Valida o checkout em Blocks.
	 *
	 * @param \WC_Order        $order   Pedido em construção.
	 * @param \WP_REST_Request $request Requisição da Store API.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Quando bloqueado.
	 */
	public function check_store_api( $order, $request = null ): void {
		$extension = array();

		if ( is_array( $request ) || $request instanceof \ArrayAccess ) {
			$extensions = isset( $request['extensions'] ) ? $request['extensions'] : array();
			$extension  = isset( $extensions[ self::EXTENSION_NAMESPACE ] ) ? (array) $extensions[ self::EXTENSION_NAMESPACE ] : array();
		}

		$context = Runtime::collector()->from_values(
			$this->id(),
			$this->action(),
			$extension['token'] ?? '',
			$extension['cstate'] ?? ''
		);

		$verdict = Runtime::gate()->assess( $context );

		if ( $verdict->is_allowed() ) {
			return;
		}

		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			esc_attr( $this->error_code( $verdict, 'wrf_checkout' ) ),
			wp_kses_post( $verdict->message() ),
			400
		);
	}

	/**
	 * Registra o bundle do checkout em Blocks.
	 *
	 * O registrador do Woo exige uma classe que `implements` uma interface DELE. Por isso
	 * a classe mora fora do caminho do autoloader e só é carregada aqui, depois de
	 * `interface_exists()` — carregá-la antes seria fatal error em toda loja sem Blocks.
	 *
	 * @param object $registry Registro de integrações do checkout em Blocks.
	 * @return void
	 */
	public function register_blocks_script( $registry ): void {
		if ( ! $this->enabled() || ! Support::blocks_supported() ) {
			return;
		}

		Support::load_conditional( 'BlocksCheckoutIntegration.php' );

		$registry->register( new BlocksCheckoutIntegration( $this->action() ) );
	}
}
