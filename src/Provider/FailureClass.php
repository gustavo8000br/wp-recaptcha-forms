<?php
/**
 * Taxonomia de falhas.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * As quatro classes de falha (arquitetura v1 §5, v1.1 §7.1).
 *
 * Não é enum porque o piso é PHP 7.4. As constantes são o vocabulário fechado; nenhum
 * outro valor de classe existe no sistema.
 */
final class FailureClass {

	/** O Google respondeu e reprovou. Bloqueia sempre; não configurável. */
	const REJECTED = 'rejected';

	/** O servidor não alcançou o Google. Configurável, default fail-open. */
	const INFRA = 'infra';

	/** As chaves estão erradas. Fail-open + escalada máxima no admin. */
	const MISCONFIG = 'misconfig';

	/** O navegador do visitante não alcançou o Google. Configurável, default fail-open. */
	const CLIENT_UNREACHABLE = 'client_unreachable';

	/**
	 * Todas as classes.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::REJECTED, self::INFRA, self::MISCONFIG, self::CLIENT_UNREACHABLE );
	}

	/**
	 * O valor é uma classe conhecida?
	 *
	 * @param mixed $value Candidato.
	 * @return bool
	 */
	public static function is_valid( $value ): bool {
		return is_string( $value ) && in_array( $value, self::all(), true );
	}
}
