<?php
/**
 * Última barreira antes do envelope sair.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recusa qualquer envelope com aparência de PII (telemetry-design §1.4, §3.2).
 *
 * O desenho sugere este gate no lado da API, e vale mais que qualquer política escrita:
 * um cliente mal escrito é descoberto na primeira tentativa, e não seis meses depois numa
 * auditoria. Aqui ele existe também no lado do cliente, pelo mesmo raciocínio aplicado um
 * passo antes — o dado nem sai da instalação do operador.
 *
 * É deliberadamente burro e paranoico. Um falso positivo custa um envelope descartado;
 * um falso negativo custa a promessa que a tela de config faz ao operador.
 */
final class PiiGuard {

	/**
	 * Chaves cuja FORMA sugere identificador. §1.4 do desenho, literal.
	 */
	const KEY_PATTERN = '/(^|_)(ip|email|url|domain|host|user|token|secret|key)($|_)/';

	/**
	 * Chaves legítimas que colidem com a regex acima.
	 *
	 * Allowlist curta e comentada de propósito: cada entrada é uma exceção que alguém
	 * precisou justificar, e uma lista que cresce é o sinal de que o payload está indo
	 * para o lugar errado.
	 *
	 * - `host` — nome do BLOCO que descreve o ambiente de execução (PHP, WP, MySQL,
	 *   locale). Não contém, e não pode conter, nome de máquina nem domínio. O conteúdo
	 *   dele continua sendo validado normalmente, chave por chave e valor por valor.
	 *
	 * `site_key` e `secret_key` NÃO estão aqui, e não podem estar: elas não existem no
	 * payload. A site key é pública, mas identifica o projeto no console do Google, e o
	 * par identifica o operador (§3.2).
	 */
	const KEY_ALLOWLIST = array( 'host' );

	/** E-mail. */
	const VALUE_EMAIL = '/[\w.+-]+@[\w-]+\.[\w.-]+/';

	/** IPv4 com os quatro octetos. */
	const VALUE_IPV4 = '/\b(?:\d{1,3}\.){3}\d{1,3}\b/';

	/**
	 * IPv6: três ou mais grupos hex separados por `:`, ou a forma comprimida `::`.
	 *
	 * O limite de TRÊS não é arbitrário. Com dois, a regex casa com toda hora ISO-8601:
	 * em `2026-09-09T03:17:44Z`, `03:` e `17:` são dois grupos hex válidos seguidos de
	 * `44`. Isso reprovaria `sent_at`, `occurred_at` e a janela — ou seja, TODO envelope.
	 * Nenhum endereço IPv6 real tem menos de dois `:`, e a forma comprimida está coberta
	 * pelas duas alternativas seguintes.
	 */
	const VALUE_IPV6 = '/(?:[0-9a-f]{1,4}:){3,}[0-9a-f]{0,4}|::[0-9a-f]{1,4}|[0-9a-f]{1,4}::/i';

	/** URL absoluta. Exige esquema: `gm.telemetry/v1` não é URL. */
	const VALUE_URL = '#\b(?:https?|ftp)://\S+#i';

	/**
	 * O envelope está limpo?
	 *
	 * @param array $envelope Envelope montado.
	 * @return string Motivo da recusa, ou string vazia quando limpo.
	 */
	public static function inspect( array $envelope ): string {
		return self::walk( $envelope );
	}

	/**
	 * Recusa um envelope sujo.
	 *
	 * Sob `WP_DEBUG` lança: um payload com PII é bug do autor do plugin, e bug do autor
	 * tem de doer no ambiente do autor. Em produção não lança — derrubar o cron de um
	 * site de terceiro por causa de um bug meu seria transformar o meu problema no dele,
	 * que é exatamente o que o §6.3 proíbe.
	 *
	 * @param array $envelope Envelope.
	 * @return string Motivo da recusa, ou string vazia.
	 * @throws \RuntimeException Sob WP_DEBUG, quando o envelope tem aparência de PII.
	 */
	public static function assert_clean( array $envelope ): string {
		$reason = self::inspect( $envelope );

		if ( '' === $reason ) {
			return '';
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// $reason é construído aqui mesmo, a partir de nomes de chave do nosso
			// vocabulário fechado — não há entrada de usuário nele. A exceção também
			// nunca chega ao navegador: só existe sob WP_DEBUG, em cron.
			throw new \RuntimeException( esc_html( 'wp-recaptcha-forms: envelope de telemetria recusado pelo PiiGuard (' . $reason . ')' ) );
		}

		return $reason;
	}

	/**
	 * Percorre o array recursivamente.
	 *
	 * @param mixed  $node Nó.
	 * @param string $path Caminho até aqui, para a mensagem.
	 * @return string Motivo da recusa, ou string vazia.
	 */
	private static function walk( $node, string $path = '' ): string {
		if ( is_array( $node ) ) {
			foreach ( $node as $key => $value ) {
				$here = '' === $path ? (string) $key : $path . '.' . $key;

				if ( is_string( $key ) && ! in_array( $key, self::KEY_ALLOWLIST, true ) && preg_match( self::KEY_PATTERN, $key ) ) {
					return 'chave suspeita: ' . $here;
				}

				$reason = self::walk( $value, $here );

				if ( '' !== $reason ) {
					return $reason;
				}
			}

			return '';
		}

		if ( ! is_string( $node ) ) {
			return '';
		}

		foreach ( array(
			'e-mail' => self::VALUE_EMAIL,
			'IPv4'   => self::VALUE_IPV4,
			'IPv6'   => self::VALUE_IPV6,
			'URL'    => self::VALUE_URL,
		) as $label => $pattern ) {
			if ( preg_match( $pattern, $node ) ) {
				return 'valor com aparência de ' . $label . ' em ' . $path;
			}
		}

		return '';
	}
}
