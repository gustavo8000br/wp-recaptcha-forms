<?php
/**
 * Pipeline compartilhado dos formulários de comentário.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comentário de post e review de produto são o MESMO formulário do WordPress.
 *
 * Os dois passam por `comment_form` e por `preprocess_comment`; o que muda é só o tipo do
 * post comentado — e, por consequência, o `form_id` e o toggle. Duplicar o pipeline faria
 * os dois adaptadores rodarem sobre a mesma submissão e o `wp_die` sair duas vezes.
 *
 * A subclasse responde uma única pergunta: este post é meu?
 */
abstract class AbstractCommentIntegration extends AbstractIntegration {

	/**
	 * Este adaptador responde pelo post comentado?
	 *
	 * @param int $post_id Post comentado.
	 * @return bool
	 */
	abstract protected function handles( int $post_id ): bool;

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'comment_form', array( $this, 'maybe_render' ), 10, 1 );
		add_filter( 'preprocess_comment', array( $this, 'check' ), 10, 1 );
	}

	/**
	 * Imprime o campo quando o post comentado é deste adaptador.
	 *
	 * `comment_form` (e não `comment_form_after_fields`) porque o segundo não roda para
	 * visitante logado: o WordPress só imprime os campos de identificação para anônimo, e
	 * o campo sairia ausente exatamente para metade dos visitantes.
	 *
	 * @param int|string $post_id Post comentado.
	 * @return void
	 */
	public function maybe_render( $post_id = 0 ): void {
		if ( ! $this->handles( (int) $post_id ) ) {
			return;
		}

		$this->render_field();
	}

	/**
	 * Valida a submissão.
	 *
	 * @param array $commentdata Dados do comentário.
	 * @return array
	 */
	public function check( $commentdata ) {
		if ( ! is_array( $commentdata ) ) {
			return $commentdata;
		}

		$post_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;

		if ( ! $this->handles( $post_id ) ) {
			return $commentdata;
		}

		$verdict = $this->assess();

		if ( $verdict->is_allowed() ) {
			return $commentdata;
		}

		/*
		 * `wp_die` com link de volta é o comportamento nativo do WordPress para recusa em
		 * `preprocess_comment` — o núcleo faz o mesmo em `wp_die( __( 'Erro: ...' ) )`.
		 * O conteúdo digitado é perdido; é regressão conhecida do hook, não deste plugin,
		 * e está documentada na story em vez de escondida.
		 */
		wp_die(
			wp_kses_post( $verdict->message() ),
			esc_html__( 'Comentário bloqueado', 'wp-recaptcha-forms' ),
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}
}
