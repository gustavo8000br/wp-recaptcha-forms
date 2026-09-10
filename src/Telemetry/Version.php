<?php
/**
 * Redução de versão a MAJOR.MINOR.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versão MINOR, nunca a de patch (telemetry-design §3.1).
 *
 * `8.2`, não `8.2.11`. Duas razões, e a segunda é a que importa: patch não muda decisão
 * de compatibilidade — a pergunta que a telemetria responde é "posso subir o piso de PHP
 * para 8.0?", e nenhuma resposta a ela depende do terceiro número. E cada dígito a mais
 * aumenta a granularidade de fingerprint da instalação. Coletar precisão que não se usa é
 * o hábito que transforma telemetria em rastreamento.
 */
final class Version {

	/**
	 * Reduz uma versão a `MAJOR.MINOR`.
	 *
	 * Aceita o que os servidores realmente devolvem, incluindo os sufixos de fornecedor
	 * do MySQL/MariaDB (`10.11.6-MariaDB`, `8.0.35-0ubuntu0.22.04.1`).
	 *
	 * @param string $full Versão completa.
	 * @return string `MAJOR.MINOR`, ou string vazia se não houver nada numérico.
	 */
	public static function minor( string $full ): string {
		if ( ! preg_match( '/^\D*(\d+)(?:\.(\d+))?/', $full, $matches ) ) {
			return '';
		}

		$major = $matches[1];
		$minor = $matches[2] ?? '0';

		return $major . '.' . $minor;
	}
}
