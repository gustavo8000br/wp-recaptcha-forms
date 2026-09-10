<?php
/**
 * Endereços da API de telemetria.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Um único arquivo conhece a URL da API, no mesmo espírito de `Provider/Endpoints.php`.
 *
 * TODO(T-2): os dois valores abaixo são PLACEHOLDER até o dono do produto confirmar a URL
 * final. A confirmação é pré-requisito de lançamento, não detalhe — a Story 1.35 (issue
 * #37) a rastreia, e o `release-checklist.md` tem o item bloqueante. Nada disso trava a
 * implementação nem os testes, que interceptam a requisição por `pre_http_request`.
 *
 * A política pública precisa existir e precisa conter a promessa de **não registrar o IP
 * de origem** (telemetry-design §1.5). Sem ela, o texto que a tela de config mostra ao
 * operador é contradito pela infraestrutura, e o plugin terá dito uma inverdade em nome
 * dele.
 */
final class Endpoints {

	/** Ingestão de envelopes. */
	const INGEST = 'https://telemetry.gustavomathias.dev/v1/ingest';

	/** Política pública de privacidade da telemetria. */
	const PRIVACY = 'https://telemetry.gustavomathias.dev/privacy';

	/**
	 * URL de ingestão efetiva.
	 *
	 * O filtro existe para ambiente de teste do autor, não como ponto de extensão de
	 * terceiro: apontar a telemetria de um site para outro servidor é exatamente o tipo
	 * de coisa que precisa ser deliberada.
	 *
	 * @return string
	 */
	public static function ingest(): string {
		/**
		 * Filtra o endpoint de ingestão.
		 *
		 * @param string $url URL padrão.
		 */
		return (string) apply_filters( 'wp_recaptcha_forms_telemetry_endpoint', self::INGEST );
	}

	/**
	 * URL da política pública.
	 *
	 * @return string
	 */
	public static function privacy(): string {
		/**
		 * Filtra a URL da política de privacidade da telemetria.
		 *
		 * @param string $url URL padrão.
		 */
		return (string) apply_filters( 'wp_recaptcha_forms_telemetry_privacy_url', self::PRIVACY );
	}
}
