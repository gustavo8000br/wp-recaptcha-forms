<?php
/**
 * Enfileiramento condicional dos scripts.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Frontend;

use WpRecaptchaForms\Consent\ConsentGate;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\ProviderFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FR-07: o script do provedor só carrega em página que tem formulário protegido.
 *
 * É a decisão de performance do plugin e, por acidente feliz, também a sua mitigação de
 * privacidade mais efetiva — em páginas sem formulário protegido o navegador do visitante
 * nunca fala com o Google.
 */
final class AssetManager {

	/** Handle do nosso script. */
	const HANDLE = 'wp-recaptcha-forms';

	/** Handle do script do provedor. */
	const HANDLE_PROVIDER = 'wp-recaptcha-forms-provider';

	/**
	 * Instância única.
	 *
	 * @var AssetManager|null
	 */
	private static $instance = null;

	/**
	 * Algum formulário protegido foi renderizado nesta página?
	 *
	 * @var bool
	 */
	private $requested = false;

	/**
	 * Os handles já foram registrados neste request?
	 *
	 * O registro acontece por dois caminhos (o enqueue normal e o registrador do checkout
	 * em Blocks). Sem esta trava, `wp_localize_script` roda duas vezes e o `var wrf` sai
	 * duplicado no HTML.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Acessa a instância.
	 *
	 * @return AssetManager
	 */
	public static function instance(): AssetManager {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'register' ) );
		add_filter( 'script_loader_tag', array( $this, 'mark_tag' ), 10, 2 );
	}

	/**
	 * Registra os handles — sem enfileirar.
	 *
	 * Enfileirar aqui significaria carregar o script do provedor na home, no arquivo e em
	 * toda página sem formulário. O enfileiramento só acontece quando um campo é impresso.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( '' === Options::site_key() || $this->registered ) {
			return;
		}

		$this->registered = true;

		$provider = ProviderFactory::create();
		$locale   = function_exists( 'determine_locale' ) ? determine_locale() : '';

		wp_register_script(
			self::HANDLE_PROVIDER,
			$provider->script_url( Options::site_key(), (string) $locale ),
			array(),
			// A URL do provedor já carrega a própria versão; acrescentar `ver` quebraria o cache dele.
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			true
		);

		$file = 'v2' === Options::version() ? 'v2.js' : 'frontend.js';

		wp_register_script(
			self::HANDLE,
			WP_RECAPTCHA_FORMS_URL . 'assets/js/dist/' . $file,
			self::provider_dependency(),
			WP_RECAPTCHA_FORMS_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'wrf',
			array(
				'siteKey'        => Options::site_key(),
				'version'        => Options::version(),
				'loadTimeout'    => (int) apply_filters( 'wp_recaptcha_forms_load_timeout', 4000 ),
				// Sob gating, o script do provedor não é enfileirado: o JS o injeta sob
				// demanda quando o CMP conceder (v1.1 §2.5). A URL vem daqui porque só o
				// Provider sabe montá-la.
				'providerUrl'    => $provider->script_url( Options::site_key(), (string) $locale ),
				// '1'/'0' e não booleano: `wp_localize_script` converte todo valor em
				// string, e `false` viraria "" — que em JavaScript não é `false`. O gating
				// de consentimento deixaria de existir no cliente, em silêncio.
				'consentGranted' => ConsentGate::granted() ? '1' : '0',
				'i18n'           => array(
					'unreachableNotice' => Messages::unreachable_notice(),
				),
			)
		);

		if ( $this->requested ) {
			$this->enqueue();
		}
	}

	/**
	 * Marca que esta página tem formulário protegido e enfileira.
	 *
	 * Chamado pelo `FieldRenderer` no momento em que o campo é impresso — que é o critério
	 * exato de "página com formulário protegido", sem lista de páginas para manter.
	 *
	 * @return void
	 */
	public function request(): void {
		$this->requested = true;

		$this->enqueue();
	}

	/**
	 * Enfileira os handles registrados.
	 *
	 * @return void
	 */
	private function enqueue(): void {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			// Contexto sem `wp_enqueue_scripts` (ex.: um formulário impresso muito cedo).
			$this->register();
		}

		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			foreach ( self::provider_dependency() as $handle ) {
				wp_enqueue_script( $handle );
			}

			wp_enqueue_script( self::HANDLE );
		}
	}

	/**
	 * O handle do provedor, quando ele pode ser carregado no page load.
	 *
	 * Sob `consent_mode` em `auto`/`required` sem consentimento, devolve array vazio: o
	 * `frontend.js` (leve, sem terceiro, sem dado pessoal) continua carregando, e o
	 * `api.js` só entra no DOM depois de `grantConsent()`.
	 *
	 * FR-07 permanece intocado e continua sendo a mitigação de privacidade mais efetiva do
	 * plugin: ele decide EM QUAIS PÁGINAS o script carrega; o consentimento decide SOB QUAL
	 * CONDIÇÃO. Os dois são ortogonais.
	 *
	 * @return string[]
	 */
	public static function provider_dependency(): array {
		return ConsentGate::granted() ? array( self::HANDLE_PROVIDER ) : array();
	}

	/**
	 * Marca as tags nas convenções que os plugins de cache respeitam.
	 *
	 * O token deixou de ser o problema (A3), mas sobra um problema real: plugins que
	 * atrasam, combinam ou adiam JavaScript. Se o script do provedor ou o nosso handler
	 * forem adiados até a primeira interação, o listener pode não existir quando o
	 * usuário clica. Estes atributos cobrem a maioria das instalações sem o operador
	 * fazer nada (arquitetura v1 §6.3).
	 *
	 * @param string $tag    Tag do script.
	 * @param string $handle Handle.
	 * @return string
	 */
	public function mark_tag( $tag, $handle ) {
		if ( ! in_array( $handle, array( self::HANDLE, self::HANDLE_PROVIDER ), true ) ) {
			return $tag;
		}

		return str_replace(
			'<script ',
			'<script data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-nowprocket ',
			$tag
		);
	}
}
