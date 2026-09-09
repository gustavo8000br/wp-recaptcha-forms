<?php
/**
 * Seleção do provider ativo.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider;

use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\Transport\TransportInterface;
use WpRecaptchaForms\Provider\Transport\WpHttpTransport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uma linha por implementação.
 *
 * É aqui que um `EnterpriseProvider` entraria se o provedor desligar o endpoint
 * clássico (R-04): uma classe nova mais uma linha, sem tocar em mais nada.
 */
final class ProviderFactory {

	/**
	 * Provider correspondente à versão configurada.
	 *
	 * @param TransportInterface|null $transport Transporte; injetado nos testes.
	 * @return ProviderInterface
	 */
	public static function create( ?TransportInterface $transport = null ): ProviderInterface {
		return self::for_version( Options::version(), $transport );
	}

	/**
	 * Provider de uma versão específica.
	 *
	 * @param string                  $version   'v2' ou 'v3'.
	 * @param TransportInterface|null $transport Transporte.
	 * @return ProviderInterface
	 */
	public static function for_version( string $version, ?TransportInterface $transport = null ): ProviderInterface {
		$transport = $transport ?? new WpHttpTransport();

		if ( 'v2' === $version ) {
			return new SiteverifyV2( $transport );
		}

		return new SiteverifyV3( $transport );
	}
}
