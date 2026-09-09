<?php
/**
 * Detecção de WooCommerce e do checkout em Blocks.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Único lugar do plugin que pergunta "tem WooCommerce aqui?".
 *
 * Toda detecção é feita por `class_exists`/`interface_exists`/`function_exists` em tempo
 * de execução. Nenhuma classe deste namespace `implements` ou `extends` algo do
 * WooCommerce — quando isso é necessário (o registrador de scripts do Blocks), o arquivo
 * fica fora do caminho do autoloader e é carregado por `require_once` explícito dentro do
 * guard. É a armadilha da arquitetura v1 §7.1: `implements` é resolvido pelo PHP no LOAD
 * da classe, antes de qualquer `class_exists` no corpo dela poder rodar.
 */
final class Support {

	/** Versão mínima do WooCommerce para o checkout em Blocks. */
	const MIN_BLOCKS_VERSION = '8.3';

	/** Interface que o registrador de scripts do Blocks exige. */
	const BLOCKS_INTERFACE = 'Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface';

	/**
	 * O WooCommerce está ativo?
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Versão do WooCommerce, ou string vazia.
	 *
	 * @return string
	 */
	public static function version(): string {
		if ( defined( 'WC_VERSION' ) ) {
			return (string) WC_VERSION;
		}

		if ( function_exists( 'WC' ) && isset( WC()->version ) ) {
			return (string) WC()->version;
		}

		return '';
	}

	/**
	 * O checkout em Blocks pode ser integrado nesta instalação?
	 *
	 * @return bool
	 */
	public static function blocks_supported(): bool {
		if ( ! self::is_active() ) {
			return false;
		}

		$version = self::version();

		if ( '' === $version || version_compare( $version, self::MIN_BLOCKS_VERSION, '<' ) ) {
			return false;
		}

		return function_exists( 'woocommerce_store_api_register_endpoint_data' )
			&& interface_exists( self::BLOCKS_INTERFACE );
	}

	/**
	 * Motivo traduzido da indisponibilidade do Blocks. Vazio quando suportado.
	 *
	 * Motivo, e não ocultamento: uma linha que some deixa o operador sem entender por que
	 * aquele formulário não está protegido (arquitetura v1 §7.2).
	 *
	 * @return string
	 */
	public static function blocks_unavailable_reason(): string {
		if ( self::blocks_supported() ) {
			return '';
		}

		return sprintf(
			/* translators: %s: minimum WooCommerce version. */
			__( 'O checkout em Blocks requer WooCommerce %s ou superior. O checkout clássico continua protegido.', 'wp-recaptcha-forms' ),
			self::MIN_BLOCKS_VERSION
		);
	}

	/**
	 * Carrega uma classe do diretório de carga condicional.
	 *
	 * @param string $file Nome do arquivo em `conditional/`.
	 * @return void
	 */
	public static function load_conditional( string $file ): void {
		require_once __DIR__ . '/conditional/' . $file;
	}
}
