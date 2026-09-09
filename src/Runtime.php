<?php
/**
 * Objetos de runtime compartilhados por adaptadores e API pública.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms;

use WpRecaptchaForms\Gate\Gate;
use WpRecaptchaForms\Gate\TokenCollector;
use WpRecaptchaForms\Provider\ProviderFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Um `Gate` e um `TokenCollector` por request, compartilhados.
 *
 * A memoização por hash do token vive dentro do `Gate` (arquitetura v1.1 §7.2, passo 2).
 * Se cada adaptador construísse o seu, dois hooks do WooCommerce sobre a mesma submissão
 * produziriam duas chamadas ao provedor — e a segunda seria recusada, porque o token é de
 * uso único. O checkout passaria a bloquear compra legítima, e o sintoma apareceria só em
 * produção.
 */
final class Runtime {

	/**
	 * Gate compartilhado.
	 *
	 * @var Gate|null
	 */
	private static $gate = null;

	/**
	 * Coletor compartilhado.
	 *
	 * @var TokenCollector|null
	 */
	private static $collector = null;

	/**
	 * O Gate do request.
	 *
	 * @return Gate
	 */
	public static function gate(): Gate {
		if ( null === self::$gate ) {
			self::$gate = new Gate( ProviderFactory::create() );
		}

		return self::$gate;
	}

	/**
	 * O coletor do request.
	 *
	 * @return TokenCollector
	 */
	public static function collector(): TokenCollector {
		if ( null === self::$collector ) {
			self::$collector = new TokenCollector( ProviderFactory::create() );
		}

		return self::$collector;
	}

	/**
	 * Injeta um Gate.
	 *
	 * Existe para os testes e para quem quiser um provider próprio sem passar pela
	 * factory. Não é ponto de extensão documentado: quem troca o provider troca a
	 * factory, que é uma linha (arquitetura v1 §4.1).
	 *
	 * @param Gate|null $gate Gate.
	 * @return void
	 */
	public static function set_gate( ?Gate $gate ): void {
		self::$gate = $gate;
	}

	/**
	 * Injeta um coletor.
	 *
	 * @param TokenCollector|null $collector Coletor.
	 * @return void
	 */
	public static function set_collector( ?TokenCollector $collector ): void {
		self::$collector = $collector;
	}

	/**
	 * Descarta o estado. Usado em testes e quando a configuração muda no mesmo request.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$gate      = null;
		self::$collector = null;
	}
}
