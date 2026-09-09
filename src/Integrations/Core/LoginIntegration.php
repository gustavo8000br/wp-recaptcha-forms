<?php
/**
 * Formulário de login do wp-login.php.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations\Core;

use WpRecaptchaForms\Gate\TokenCollector;
use WpRecaptchaForms\Integrations\AbstractIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vale EXCLUSIVAMENTE para o formulário HTML do `wp-login.php` (arquitetura v1.1 §6).
 *
 * Nenhum outro caminho de autenticação é afetado: XML-RPC, REST API, application
 * passwords, WP-CLI, cron e AJAX de terceiro saem intactos por `applies()`.
 */
final class LoginIntegration extends AbstractIntegration {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'wp_login';
	}

	/**
	 * Rótulo.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Login', 'wp-recaptcha-forms' );
	}

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		/*
		 * `login_form` e NÃO `login_footer`: o campo precisa estar DENTRO do <form> para
		 * ser submetido. O erro é silencioso — o campo aparece na página, nunca chega ao
		 * POST, e todo login vira token vazio, logo REJECTED, logo ninguém entra.
		 */
		add_action( 'login_form', array( $this, 'render_field' ) );

		/*
		 * Prioridade 30, depois do 20 do core: não gastamos chamada ao provedor (nem cota)
		 * numa senha errada, e devolver WP_Error aqui faz o wp-login.php re-renderizar o
		 * formulário com o username preservado — FR-09 sem código extra.
		 */
		add_filter( 'authenticate', array( $this, 'check' ), 30, 3 );
	}

	/**
	 * Valida a submissão.
	 *
	 * @param \WP_User|\WP_Error|null $user     Resultado da cadeia até aqui.
	 * @param string                  $username Username informado.
	 * @param string                  $password Senha informada.
	 * @return \WP_User|\WP_Error|null
	 */
	public function check( $user, $username = '', $password = '' ) {
		if ( ! $this->applies() ) {
			return $user;
		}

		if ( is_wp_error( $user ) || null === $user ) {
			// A credencial já falhou por outro motivo; não há o que acrescentar.
			return $user;
		}

		$verdict = $this->assess();

		if ( $verdict->is_allowed() ) {
			return $user;
		}

		return new \WP_Error( $this->error_code( $verdict, 'wrf_login' ), $verdict->message() );
	}

	/**
	 * A proteção se aplica a esta requisição?
	 *
	 * Ordenado do mais barato e mais determinante para o mais específico, de propósito.
	 *
	 * A presença de `wp-submit` na submissão é o coração da regra: `wp-submit` é o nome do
	 * botão
	 * que o WordPress imprime no formulário de login, e só chega ao POST se o navegador
	 * submeteu AQUELE formulário renderizado — o mesmo que contém nosso campo. Nenhum
	 * cliente programático o envia. As checagens de constante acima dele são defesa em
	 * profundidade, não redundância: um cliente XML-RPC malandro pode postar `wp-submit`,
	 * e `XMLRPC_REQUEST` corta antes.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( wp_doing_cron() ) {
			return false;
		}

		if ( wp_doing_ajax() ) {
			return false;
		}

		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return false;
		}

		if ( ! TokenCollector::submitted( 'wp-submit' ) ) {
			return false;
		}

		// Não há bot a barrar em quem já tem sessão válida, e isto remove um caminho
		// inteiro de lockout (arquitetura v1.1 §5.4).
		if ( is_user_logged_in() ) {
			return false;
		}

		return true;
	}
}
