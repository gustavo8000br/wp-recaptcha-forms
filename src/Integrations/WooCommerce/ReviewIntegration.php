<?php
/**
 * Avaliações de produto do WooCommerce.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations\WooCommerce;

use WpRecaptchaForms\Integrations\AbstractCommentIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Review de produto é um comentário: mesmo `comment_form`, mesmo `preprocess_comment`.
 *
 * Só o `form_id` e o toggle mudam. O pipeline vem inteiro de `AbstractCommentIntegration`,
 * então corrigir um bug de comentário corrige review no mesmo commit.
 */
final class ReviewIntegration extends AbstractCommentIntegration {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'woocommerce_review';
	}

	/**
	 * Rótulo.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Avaliações de produto', 'wp-recaptcha-forms' );
	}

	/**
	 * Grupo na tela.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'woocommerce';
	}

	/**
	 * Responde só por produtos.
	 *
	 * @param int $post_id Post comentado.
	 * @return bool
	 */
	protected function handles( int $post_id ): bool {
		return 'product' === get_post_type( $post_id );
	}
}
