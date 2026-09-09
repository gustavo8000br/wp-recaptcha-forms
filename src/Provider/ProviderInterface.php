<?php
/**
 * Contrato do provedor de verificação.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A fronteira (arquitetura v1 §4.1).
 *
 * Trocar `siteverify` por Enterprise é escrever uma classe nova que implementa isto,
 * mais uma linha na factory. Nada fora de `src/Provider/` sabe que existe um Google.
 */
interface ProviderInterface {

	/**
	 * Verifica um token.
	 *
	 * Nunca lança exceção: qualquer falha — rede, JSON quebrado, chave errada — vira
	 * `ProviderResponse` com a `FailureClass` correspondente.
	 *
	 * @param VerificationRequest $request Requisição.
	 * @return ProviderResponse
	 */
	public function verify( VerificationRequest $request ): ProviderResponse;

	/**
	 * Versão implementada.
	 *
	 * @return string 'v2' ou 'v3'.
	 */
	public function version(): string;

	/**
	 * URL do script a enfileirar no front.
	 *
	 * @param string $site_key Site key.
	 * @param string $locale   Locale do site.
	 * @return string
	 */
	public function script_url( string $site_key, string $locale ): string;

	/**
	 * Nome do campo de onde o token vem no POST.
	 *
	 * Quarto método além dos três da arquitetura v1 §4.1, e é aqui de propósito: no v2 o
	 * campo é gerado pelo script do provedor com um nome que é vocabulário DELE. Se o
	 * coletor conhecesse esse nome, a fronteira A2 já teria vazado — e BL-06 pedia
	 * exatamente um coletor único que não soubesse disso. O provider responde, o coletor
	 * pergunta.
	 *
	 * @return string
	 */
	public function token_field(): string;
}
