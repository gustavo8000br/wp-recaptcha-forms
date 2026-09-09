<?php
/**
 * Estado declarado pelo cliente.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vocabulário fechado do `cstate` (arquitetura v1.1 §1.2).
 *
 * O navegador não decide o veredito: ele declara um estado, e o servidor decide o que
 * fazer com essa declaração — inclusive ignorá-la. A declaração é entrada, não saída.
 *
 * Vocabulário nosso, não do provedor (A2 preservado).
 */
final class ClientState {

	/** Caminho feliz: token obtido, ou nenhum JS rodou. */
	const NONE = '';

	/** O script do provedor não existia após o timeout de carregamento. */
	const NO_SCRIPT = 'no-script';

	/** Modo de consentimento ativo e consentimento não concedido no submit. */
	const CONSENT_PENDING = 'consent-pending';

	/** O script existe mas a execução rejeitou. */
	const EXEC_ERROR = 'exec-error';

	/** Nome do campo no POST. */
	const FIELD = 'wrf_cstate';

	/**
	 * Valores que caracterizam cliente inalcançável.
	 *
	 * @return string[]
	 */
	public static function unreachable_values(): array {
		return array( self::NO_SCRIPT, self::CONSENT_PENDING, self::EXEC_ERROR );
	}

	/**
	 * Normaliza um valor cru para o vocabulário fechado.
	 *
	 * Qualquer coisa fora da lista vira NONE. Um cliente hostil que invente um valor
	 * novo não ganha nada com isso: cai no caminho de token vazio sem declaração, que
	 * é REJECTED.
	 *
	 * @param mixed $raw Valor cru.
	 * @return string
	 */
	public static function normalize( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return self::NONE;
		}

		$value = trim( $raw );

		return in_array( $value, self::unreachable_values(), true ) ? $value : self::NONE;
	}

	/**
	 * Lê o estado do cliente na requisição corrente.
	 *
	 * Único lugar do plugin que toca em `$_POST['wrf_cstate']` — verificado por
	 * `bin/check-boundary.sh` (v1.1 §8.2).
	 *
	 * @return string
	 */
	public static function from_request(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- valor não confiável por construção, normalizado abaixo; nonce em formulário público é o outro modo de falha do cache (v1 §13).
		$raw = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : '';

		return self::normalize( $raw );
	}

	/**
	 * O estado declara cliente inalcançável?
	 *
	 * @param string $state Estado normalizado.
	 * @return bool
	 */
	public static function is_unreachable( string $state ): bool {
		return in_array( $state, self::unreachable_values(), true );
	}
}
