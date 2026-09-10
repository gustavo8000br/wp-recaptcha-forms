<?php
/**
 * Mensagens ao usuário final.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Frontend;

use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\FailureClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Textos default, traduzíveis e sobrescrevíveis pelo operador (FR-27).
 *
 * Todos os textos vivem em MÉTODOS, nunca em `const` nem em propriedade de classe:
 * `__()` em escopo de arquivo dispara `_load_textdomain_just_in_time` a partir do
 * WP 6.7 e a string pode sair não traduzida (arquitetura v1 §9.1).
 */
final class Messages {

	/**
	 * Mensagem para uma classe de falha bloqueada.
	 *
	 * @param string      $failure Classe de falha.
	 * @param string      $form_id Formulário.
	 * @param string|null $reason  Motivo no vocabulário do plugin.
	 * @return string
	 */
	public static function for_failure( string $failure, string $form_id, ?string $reason = null ): string {
		switch ( $failure ) {
			case FailureClass::CLIENT_UNREACHABLE:
				$key     = 'client_unreachable';
				$default = self::client_unreachable();
				break;

			case FailureClass::INFRA:
				$key     = 'infra';
				$default = self::infra();
				break;

			case FailureClass::MISCONFIG:
				// MISCONFIG não bloqueia por default; quando o filtro endurece, reusa INFRA.
				$key     = 'infra';
				$default = self::infra();
				break;

			default:
				$key     = 'rejected';
				$default = self::rejected();
				break;
		}

		$custom  = self::custom( $key );
		$message = '' !== $custom ? $custom : $default;

		/**
		 * Filtra a mensagem de recusa.
		 *
		 * @param string      $message Mensagem.
		 * @param string      $form_id Formulário.
		 * @param string|null $reason  Motivo (inclui 'client_unreachable' como classe).
		 */
		return (string) apply_filters(
			'wp_recaptcha_forms_error_message',
			$message,
			$form_id,
			null !== $reason ? $reason : $failure
		);
	}

	/**
	 * Recusa padrão: o provedor respondeu e reprovou.
	 *
	 * @return string
	 */
	public static function rejected(): string {
		return __( 'Não foi possível confirmar que você não é um robô. Recarregue a página e tente novamente.', 'wp-recaptcha-forms' );
	}

	/**
	 * Verificação indisponível para o servidor.
	 *
	 * @return string
	 */
	public static function infra(): string {
		return __( 'A verificação de segurança está temporariamente indisponível. Tente novamente em instantes.', 'wp-recaptcha-forms' );
	}

	/**
	 * Cliente não alcançou o provedor, e a política é bloquear (arquitetura v1.1 §1.7).
	 *
	 * Mensagem distinta da de recusa de propósito: acionável, e dá ao visitante o
	 * vocabulário de suporte certo ("bloqueador"), não "o site está quebrado".
	 *
	 * @return string
	 */
	public static function client_unreachable(): string {
		return __( 'Não conseguimos verificar sua sessão porque a verificação de segurança não carregou no seu navegador. Desative o bloqueador de anúncios (ou aceite os cookies) para esta página e tente novamente.', 'wp-recaptcha-forms' );
	}

	/**
	 * Aviso mostrado no próprio formulário pelo JS (arquitetura v1.1 §1.6).
	 *
	 * @return string
	 */
	public static function unreachable_notice(): string {
		$custom = self::custom( 'unreachable_notice' );

		if ( '' !== $custom ) {
			return $custom;
		}

		return __( 'Não foi possível carregar a verificação de segurança do Google. Se você usa bloqueador de anúncios ou recusou os cookies, desative-o para esta página e tente novamente.', 'wp-recaptcha-forms' );
	}

	/**
	 * Bloco de privacidade da telemetria (telemetry-design §4.3).
	 *
	 * Só entra no aviso quando a telemetria está efetivamente ligada. Quando está
	 * desligada não há bloco nenhum: não se descreve o que não acontece.
	 *
	 * A nota jurídica do §4.3 é análise para o dono do plugin, não copy — ela não entra
	 * aqui. O que o operador publica é esta frase e mais nada.
	 *
	 * @return string
	 */
	public static function telemetry_notice(): string {
		return __( 'Este site envia semanalmente ao autor do plugin estatísticas agregadas de uso, sem endereço do site, sem dados de visitantes e sem conteúdo de formulários.', 'wp-recaptcha-forms' );
	}

	/**
	 * Mensagem customizada pelo operador, se houver.
	 *
	 * @param string $key Chave.
	 * @return string
	 */
	private static function custom( string $key ): string {
		$messages = Options::get( 'messages', array() );

		if ( ! is_array( $messages ) || empty( $messages[ $key ] ) ) {
			return '';
		}

		return trim( (string) $messages[ $key ] );
	}
}
