<?php
/**
 * API pública para formulários de terceiros (FR-11, arquitetura v1 §12).
 *
 * Funções globais, não classe: é o idioma que um `functions.php` e um plugin de terceiro
 * já falam. Este arquivo é carregado por `require_once` no bootstrap — funções não podem
 * ser autocarregadas.
 *
 * O contrato abaixo é tratado como estável desde a v1: mudar qualquer assinatura é MAJOR.
 *
 * @package WpRecaptchaForms
 */

use WpRecaptchaForms\Consent\ConsentGate;
use WpRecaptchaForms\Frontend\FieldRenderer;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Privacy\PrivacyNotice;
use WpRecaptchaForms\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_recaptcha_forms_render_field' ) ) {
	/**
	 * Imprime os campos de verificação dentro de um formulário próprio.
	 *
	 * Imprime apenas hidden fields vazios: nada específico de usuário, nada com validade,
	 * logo o HTML resultante continua cacheável (princípio A3).
	 *
	 * @param string $form_id Identificador do formulário. Livre, mas estável.
	 * @param array  $args    `action` => action nomeada do v3 (default `wrf_{form_id}`).
	 * @return void
	 */
	function wp_recaptcha_forms_render_field( string $form_id, array $args = array() ): void {
		if ( wp_recaptcha_forms_is_killed() ) {
			return;
		}

		$action = isset( $args['action'] ) ? (string) $args['action'] : 'wrf_' . $form_id;

		FieldRenderer::render( $form_id, $action );
	}
}

if ( ! function_exists( 'wp_recaptcha_forms_verify' ) ) {
	/**
	 * Verifica a submissão corrente.
	 *
	 * Devolve `WP_Error` em vez de lançar exceção — é o idioma nativo do WordPress, e
	 * quem chama já tem `is_wp_error()` no dedo. Um `try/catch` obrigatório num plugin de
	 * formulário seria a fonte de meia dúzia de fatal errors alheios.
	 *
	 * @param string $form_id Identificador do formulário.
	 * @param array  $args    `action` => action do v3.
	 * @return bool|WP_Error `true` quando o envio pode prosseguir.
	 */
	function wp_recaptcha_forms_verify( string $form_id, array $args = array() ) {
		if ( wp_recaptcha_forms_is_killed() ) {
			return true;
		}

		$action  = isset( $args['action'] ) ? (string) $args['action'] : 'wrf_' . $form_id;
		$context = Runtime::collector()->collect( $form_id, $action );
		$verdict = Runtime::gate()->assess( $context );

		if ( $verdict->is_allowed() ) {
			return true;
		}

		return new WP_Error(
			'wrf_' . (string) $verdict->failure(),
			$verdict->message(),
			array(
				'form_id' => $form_id,
				'reason'  => $verdict->reason(),
			)
		);
	}
}

if ( ! function_exists( 'wp_recaptcha_forms_is_active' ) ) {
	/**
	 * A proteção está ativa — globalmente, ou para um formulário específico?
	 *
	 * @param string $form_id Identificador; vazio pergunta pelo plugin como um todo.
	 * @return bool
	 */
	function wp_recaptcha_forms_is_active( string $form_id = '' ): bool {
		if ( wp_recaptcha_forms_is_killed() ) {
			return false;
		}

		if ( '' === Options::site_key() || '' === Options::secret_key() ) {
			return false;
		}

		if ( '' === $form_id ) {
			return true;
		}

		return Options::form_enabled( $form_id );
	}
}

if ( ! function_exists( 'wp_recaptcha_forms_set_consent' ) ) {
	/**
	 * Declara o consentimento no lado servidor, sem precisar de closure no filtro.
	 *
	 * @param bool $granted Se o consentimento foi concedido.
	 * @return void
	 */
	function wp_recaptcha_forms_set_consent( bool $granted ): void {
		ConsentGate::set( $granted );
	}
}

if ( ! function_exists( 'wp_recaptcha_forms_privacy_notice' ) ) {
	/**
	 * Texto de aviso de privacidade, fiel à configuração desta instalação (FR-31/FR-32).
	 *
	 * @param array $args `format` => 'html'|'plain'.
	 * @return string
	 */
	function wp_recaptcha_forms_privacy_notice( array $args = array() ): string {
		return PrivacyNotice::render( $args );
	}
}

/*
 * Filtros públicos, todos já implementados e documentados no ponto onde são aplicados:
 *
 * - `wp_recaptcha_forms_should_protect( bool $enabled, string $form_id, FormContext $ctx )`
 *   Desliga a proteção por contexto. Exemplo útil: desligar num ambiente de staging —
 *
 *       add_filter( 'wp_recaptcha_forms_should_protect', function ( $enabled ) {
 *           return wp_get_environment_type() === 'production' ? $enabled : false;
 *       } );
 *
 *   (Bypass por capability de usuário logado NÃO é o exemplo recomendado: usuário logado
 *   não é o vetor que este plugin cobre, e o exemplo induziria a uma falsa sensação de
 *   controle sobre um caminho fora de escopo.)
 *
 * - `wp_recaptcha_forms_verdict( Verdict $verdict, FormContext $ctx )` — poder máximo.
 * - `wp_recaptcha_forms_error_message( string $msg, string $form_id, string $reason )`.
 * - `wp_recaptcha_forms_client_unreachable_policy( string $policy, FormContext $ctx )`.
 * - `wp_recaptcha_forms_misconfig_policy( string $policy )`.
 * - `wp_recaptcha_forms_consent_granted( ?bool $granted, string $form_id )`.
 * - `wp_recaptcha_forms_consent_category( string $category )`.
 * - `wp_recaptcha_forms_endpoint( string $url )` e
 *   `wp_recaptcha_forms_request_timeout( int $seconds )` — fronteira do provedor.
 * - `wp_recaptcha_forms_remote_ip( string $ip )` — para quem roda atrás de CDN.
 * - `wp_recaptcha_forms_load_timeout( int $ms )` — prazo do cliente para o script.
 *
 * Registrar um formulário de terceiro no Registry (para ganhar toggle na tela) está
 * explicitamente FORA da v1.
 */
