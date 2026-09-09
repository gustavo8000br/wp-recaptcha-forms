<?php
/**
 * Registro das integrações disponíveis.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fonte única de verdade sobre quais formulários existem nesta instalação.
 *
 * Nesta rodada o registry nasce vazio de propósito: as integrações concretas são das
 * Stories 1.7+ e 1.11. A tela de configurações já é gerada a partir dele, então quando
 * elas chegarem não há UI a escrever.
 */
final class Registry {

	/**
	 * Instância única.
	 *
	 * @var Registry|null
	 */
	private static $instance = null;

	/**
	 * Integrações registradas, por id.
	 *
	 * @var IntegrationInterface[]
	 */
	private $integrations = array();

	/**
	 * Acessa a instância.
	 *
	 * @return Registry
	 */
	public static function instance(): Registry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registra uma integração.
	 *
	 * @param IntegrationInterface $integration Integração.
	 * @return void
	 */
	public function register( IntegrationInterface $integration ): void {
		$this->integrations[ $integration->id() ] = $integration;
	}

	/**
	 * Todas as integrações registradas.
	 *
	 * @return IntegrationInterface[]
	 */
	public function all(): array {
		return $this->integrations;
	}

	/**
	 * Integrações agrupadas por `group()`.
	 *
	 * @return array<string, IntegrationInterface[]>
	 */
	public function grouped(): array {
		$grouped = array();

		foreach ( $this->integrations as $integration ) {
			$grouped[ $integration->group() ][] = $integration;
		}

		return $grouped;
	}

	/**
	 * Uma integração pelo id.
	 *
	 * @param string $id Identificador.
	 * @return IntegrationInterface|null
	 */
	public function get( string $id ): ?IntegrationInterface {
		return $this->integrations[ $id ] ?? null;
	}

	/**
	 * Registra os hooks de todas as integrações disponíveis.
	 *
	 * @return void
	 */
	public function boot(): void {
		foreach ( $this->integrations as $integration ) {
			if ( $integration->available() ) {
				$integration->register();
			}
		}
	}
}
