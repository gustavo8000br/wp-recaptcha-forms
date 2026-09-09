<?php
/**
 * Contrato de uma integração de formulário.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cada ponto de formulário protegido implementa isto (arquitetura v1 §7.2).
 *
 * A tela de configurações é GERADA a partir do registry — é o mecanismo que faz a
 * degradação sem WooCommerce acontecer sozinha, sem uma linha de UI condicional.
 */
interface IntegrationInterface {

	/**
	 * Identificador estável, usado como chave de opção.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Rótulo traduzido para a tela de configurações.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Grupo na tela ('core', 'woocommerce', 'newsletter', ...).
	 *
	 * @return string
	 */
	public function group(): string;

	/**
	 * A integração pode operar nesta instalação?
	 *
	 * Falso não significa ocultar: uma integração indisponível por versão aparece
	 * desabilitada COM o motivo. Ocultar deixaria o operador sem entender por que
	 * aquele formulário não está protegido.
	 *
	 * @return bool
	 */
	public function available(): bool;

	/**
	 * Motivo da indisponibilidade, traduzido. Vazio quando disponível.
	 *
	 * @return string
	 */
	public function unavailable_reason(): string;

	/**
	 * Registra os hooks da integração.
	 *
	 * @return void
	 */
	public function register(): void;
}
