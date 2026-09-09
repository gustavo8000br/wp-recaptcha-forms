<?php
/**
 * Registrador de scripts do checkout em Blocks.
 *
 * @wrf-conditional-load
 *
 * ESTE ARQUIVO NÃO PODE SER AUTOCARREGADO. Ele `implements` uma interface do WooCommerce
 * Blocks, e o PHP resolve `implements` no momento em que a CLASSE é carregada — antes de
 * qualquer guard dentro do corpo dela poder rodar. Autoload aqui significa fatal error em
 * toda instalação sem WooCommerce.
 *
 * Por isso o arquivo mora em `conditional/`, fora do caminho que o PSR-4 procuraria para
 * `WpRecaptchaForms\Integrations\WooCommerce\BlocksCheckoutIntegration`, e só entra por
 * `require_once` explícito depois de `interface_exists()`. Há teste automatizado que
 * reprova se o arquivo voltar a ser alcançável pelo autoloader.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations\WooCommerce;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use WpRecaptchaForms\Frontend\AssetManager;
use WpRecaptchaForms\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entrega o bundle do checkout em Blocks ao registrador do Woo.
 */
final class BlocksCheckoutIntegration implements IntegrationInterface {

	/** Handle do bundle. */
	const HANDLE = 'wp-recaptcha-forms-blocks';

	/**
	 * Action nomeada do v3 usada pelo checkout.
	 *
	 * @var string
	 */
	private $action;

	/**
	 * Construtor.
	 *
	 * @param string $action Action do v3.
	 */
	public function __construct( string $action = 'wrf_woocommerce_checkout' ) {
		$this->action = $action;
	}

	/**
	 * Nome da integração.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'wp-recaptcha-forms';
	}

	/**
	 * Registra o bundle.
	 *
	 * @return void
	 */
	public function initialize() {
		AssetManager::instance()->register();

		wp_register_script(
			self::HANDLE,
			WP_RECAPTCHA_FORMS_URL . 'assets/js/dist/blocks-checkout.js',
			array_merge(
				array( AssetManager::HANDLE, 'wc-blocks-checkout-events', 'wc-settings', 'wp-data' ),
				AssetManager::provider_dependency()
			),
			WP_RECAPTCHA_FORMS_VERSION,
			true
		);
	}

	/**
	 * Handles do front.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( self::HANDLE );
	}

	/**
	 * Handles do editor. Nenhum: o bloco do checkout não muda no editor.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Dados expostos ao script.
	 *
	 * @return array
	 */
	public function get_script_data() {
		return array(
			'action'  => $this->action,
			'siteKey' => Options::site_key(),
		);
	}
}
