<?php
/**
 * Motivos de recusa, no nosso vocabulário.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tradução dos motivos de recusa para vocabulário nosso (princípio A2).
 *
 * O Gate precisa distinguir "token recusado" de "score baixo" — para o advisory de par
 * cruzado (v1.1 §4.4) e para a mensagem ao usuário. Sem estas constantes ele teria que
 * ler `debug_codes()`, e aí a fronteira do provedor já teria vazado.
 */
final class RejectionReason {

	/** Token ausente na requisição. */
	const MISSING_TOKEN = 'missing_token';

	/** Token presente e recusado pelo provedor. */
	const INVALID_TOKEN = 'invalid_token';

	/** Token já usado (uso único). */
	const DUPLICATE_TOKEN = 'duplicate_token';

	/** Score abaixo do threshold (v3). */
	const LOW_SCORE = 'low_score';

	/** Action devolvida diferente da esperada (v3). */
	const ACTION_MISMATCH = 'action_mismatch';

	/** Hostname devolvido diferente do esperado. */
	const HOSTNAME_MISMATCH = 'hostname_mismatch';
}
