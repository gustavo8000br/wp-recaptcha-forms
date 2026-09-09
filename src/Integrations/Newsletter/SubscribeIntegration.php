<?php
/**
 * Formulário de inscrição do plugin Newsletter (Stefano Lissa).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations\Newsletter;

use WpRecaptchaForms\Frontend\FieldRenderer;
use WpRecaptchaForms\Integrations\AbstractIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Só o formulário de INSCRIÇÃO (FR-10). Cancelamento e perfil não são tocados.
 *
 * O Newsletter não expõe filtro no HTML do formulário nem na validação da inscrição — a
 * checagem antispam dele é interna e sem hook. Os dois pontos de encaixe abaixo são os
 * únicos estáveis que existem, e ambos são do WordPress, não do plugin:
 *
 * - render: `do_shortcode_tag`, que entrega o HTML já montado do shortcode;
 * - validação: `newsletter_action`, disparado em `plugin.php` antes do handler próprio.
 *
 * Depender de hook do WordPress em vez de hook do Newsletter é deliberado: o contrato do
 * núcleo não muda entre versões menores do plugin de terceiro.
 */
final class SubscribeIntegration extends AbstractIntegration {

	/**
	 * Shortcodes que produzem um formulário de inscrição.
	 *
	 * @var string[]
	 */
	const FORM_SHORTCODES = array( 'newsletter_form', 'newsletter' );

	/**
	 * Ações do Newsletter que representam uma inscrição.
	 *
	 * @var string[]
	 */
	const SUBSCRIBE_ACTIONS = array( 's', 'subscribe', 'sa', 'ajaxsub' );

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'newsletter_subscribe';
	}

	/**
	 * Rótulo.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Inscrição na newsletter', 'wp-recaptcha-forms' );
	}

	/**
	 * Grupo na tela.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'newsletter';
	}

	/**
	 * O plugin Newsletter está ativo?
	 *
	 * Feature detection, sem `Requires Plugins` e sem dependência dura: sem o Newsletter
	 * instalado esta integração nem chega ao Registry (FR-13).
	 *
	 * @return bool
	 */
	public function available(): bool {
		return defined( 'NEWSLETTER_VERSION' );
	}

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'do_shortcode_tag', array( $this, 'inject_field' ), 10, 2 );
		add_action( 'newsletter_action', array( $this, 'check' ), 5, 1 );
	}

	/**
	 * Injeta os campos no HTML do formulário de inscrição.
	 *
	 * @param string $output Saída do shortcode.
	 * @param string $tag    Tag do shortcode.
	 * @return string
	 */
	public function inject_field( $output, $tag = '' ) {
		if ( ! is_string( $output ) || ! in_array( (string) $tag, self::FORM_SHORTCODES, true ) ) {
			return $output;
		}

		if ( ! $this->enabled() || false === strpos( $output, '</form>' ) ) {
			return $output;
		}

		$markup = FieldRenderer::markup( $this->id(), $this->action() );

		/*
		 * Antes do PRIMEIRO `</form>`: um shortcode pode render mais de um formulário, e
		 * `str_replace` sem limite duplicaria os campos com o mesmo `name` — o navegador
		 * enviaria os dois e o último venceria, que aqui é sempre o vazio.
		 */
		$position = strpos( $output, '</form>' );

		return substr( $output, 0, $position ) . $markup . substr( $output, $position );
	}

	/**
	 * Valida a inscrição antes de o Newsletter processá-la.
	 *
	 * Prioridade 5, antes do handler do plugin (10). Recusa com `wp_die` e link de volta —
	 * o Newsletter processa a inscrição por redirect para uma página própria, então não há
	 * hook que permita devolver o visitante ao formulário com os campos preenchidos.
	 *
	 * @param string $action Ação do Newsletter.
	 * @return void
	 */
	public function check( $action = '' ): void {
		if ( ! in_array( (string) $action, self::SUBSCRIBE_ACTIONS, true ) ) {
			return;
		}

		$verdict = $this->assess();

		if ( $verdict->is_allowed() ) {
			return;
		}

		wp_die(
			wp_kses_post( $verdict->message() ),
			esc_html__( 'Inscrição bloqueada', 'wp-recaptcha-forms' ),
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}
}
