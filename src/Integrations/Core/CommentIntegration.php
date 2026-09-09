<?php
/**
 * Comentários nativos do WordPress.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Integrations\Core;

use WpRecaptchaForms\Integrations\AbstractCommentIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comentário de post, página ou qualquer CPT que não seja produto do WooCommerce.
 *
 * Review de produto tem toggle próprio (Story 1.15) e é atendido por
 * `WooCommerce\ReviewIntegration`; aqui ele é explicitamente devolvido.
 */
final class CommentIntegration extends AbstractCommentIntegration {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'wp_comment';
	}

	/**
	 * Rótulo.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Comentários', 'wp-recaptcha-forms' );
	}

	/**
	 * Responde por tudo que não é produto.
	 *
	 * @param int $post_id Post comentado.
	 * @return bool
	 */
	protected function handles( int $post_id ): bool {
		return 'product' !== get_post_type( $post_id );
	}
}
