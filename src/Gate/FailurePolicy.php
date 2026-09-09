<?php
/**
 * Resolução da política efetiva de falha.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Gate;

use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\FailureClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dois eixos independentes de política (arquitetura v1.1 §7.3).
 *
 * O armazenamento tem os dois eixos separados desde o primeiro dia porque unificá-los
 * depois é migração de opção, e migração de opção é MAJOR.
 */
final class FailurePolicy {

	/** Permitir o envio. */
	const ALLOW = 'allow';

	/** Bloquear o envio. */
	const BLOCK = 'block';

	/** Herdar do nível global. */
	const INHERIT = 'inherit';

	/**
	 * Resolve a política efetiva para uma classe de falha.
	 *
	 * @param string      $failure Classe de falha.
	 * @param FormContext $context Contexto do formulário.
	 * @return string ALLOW ou BLOCK.
	 */
	public static function resolve( string $failure, FormContext $context ): string {
		switch ( $failure ) {
			case FailureClass::REJECTED:
				/*
				 * Constante, sem filtro, sem exceção (arquitetura v1 §5.2).
				 *
				 * Se esta linha algum dia virar configurável, ligar fail-open — que é o
				 * default — desligaria o plugin inteiro: qualquer bot que simplesmente
				 * não enviasse o campo passaria direto. Um plugin de segurança cujo
				 * default o desliga é pior que não ter plugin.
				 */
				return self::BLOCK;

			case FailureClass::MISCONFIG:
				/**
				 * Postura estrita sob chave inválida. Deliberadamente fora da UI: é decisão
				 * de desenvolvedor, não de operador (arquitetura v1 §5.3).
				 *
				 * @param string $policy Política default.
				 */
				$policy = apply_filters( 'wp_recaptcha_forms_misconfig_policy', self::ALLOW );

				return self::BLOCK === $policy ? self::BLOCK : self::ALLOW;

			case FailureClass::INFRA:
				return self::from_axis( 'policy_infra', 'failure_policy_infra', $context );

			case FailureClass::CLIENT_UNREACHABLE:
				$policy = self::from_axis( 'policy_client_unreachable', 'failure_policy_client_unreachable', $context );

				/**
				 * Política para o visitante que não alcança o provedor.
				 *
				 * @param string      $policy  Política resolvida por override/global.
				 * @param FormContext $context Contexto.
				 */
				$policy = apply_filters( 'wp_recaptcha_forms_client_unreachable_policy', $policy, $context );

				return self::BLOCK === $policy ? self::BLOCK : self::ALLOW;
		}

		// Classe desconhecida: fail-safe na mesma direção do resto do desenho.
		return self::ALLOW;
	}

	/**
	 * Resolve um eixo: override do formulário, senão global, senão ALLOW.
	 *
	 * @param string      $form_key   Chave no array do formulário.
	 * @param string      $global_key Chave global.
	 * @param FormContext $context    Contexto.
	 * @return string
	 */
	private static function from_axis( string $form_key, string $global_key, FormContext $context ): string {
		$form     = Options::form( $context->form_id() );
		$override = isset( $form[ $form_key ] ) ? (string) $form[ $form_key ] : self::INHERIT;

		if ( self::ALLOW === $override || self::BLOCK === $override ) {
			return $override;
		}

		$global = (string) Options::get( $global_key, self::ALLOW );

		return self::BLOCK === $global ? self::BLOCK : self::ALLOW;
	}

	/**
	 * Valores aceitos no nível global.
	 *
	 * @return string[]
	 */
	public static function global_values(): array {
		return array( self::ALLOW, self::BLOCK );
	}

	/**
	 * Valores aceitos no nível do formulário.
	 *
	 * @return string[]
	 */
	public static function form_values(): array {
		return array( self::INHERIT, self::ALLOW, self::BLOCK );
	}
}
