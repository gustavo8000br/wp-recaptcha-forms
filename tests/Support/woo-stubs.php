<?php
/**
 * Stubs mínimos do WooCommerce usados pelos testes do checkout.
 *
 * Arquivo separado porque estas classes vivem em namespaces do Woo, e um arquivo PHP não
 * pode misturar código global com uma declaração `namespace` posterior.
 *
 * @package WpRecaptchaForms
 */

namespace Automattic\WooCommerce\StoreApi\Exceptions;

// phpcs:disable Squiz.Commenting, WordPress.NamingConventions

/**
 * O adaptador só precisa que a classe exista e carregue código, mensagem e status — é o
 * contrato que a Store API converte numa resposta 400 exibida na UI do Blocks.
 */
class RouteException extends \Exception {

	protected $error_code;

	protected $status;

	public function __construct( $error_code = '', $message = '', $http_status_code = 400, $additional_data = array() ) {
		parent::__construct( (string) $message, 0 );

		$this->error_code = $error_code;
		$this->status     = $http_status_code;
	}

	public function getErrorCode() {
		return $this->error_code;
	}

	public function getStatusCode() {
		return $this->status;
	}
}
