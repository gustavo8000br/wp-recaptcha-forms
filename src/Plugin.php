<?php
/**
 * Container mínimo e wiring do plugin.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap do plugin em modo normal (kill switch ausente).
 */
final class Plugin {

	/** Estado: verificando normalmente. */
	const STATE_PROTECTING = 'protecting';

	/** Estado: chaves erradas, fail-open no front (v1.1 §4.3). */
	const STATE_DEGRADED = 'degraded';

	/** Estado: kill switch por constante (v1.1 §5). */
	const STATE_DISABLED = 'disabled';

	/** Estado: sem chaves configuradas ainda. */
	const STATE_UNCONFIGURED = 'unconfigured';

	/**
	 * Instância única.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Acessa a instância.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registra os hooks do plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( Options::class, 'maybe_upgrade' ), 5 );

		// Prioridade 20: depois do WooCommerce, para que o registry veja o que existe.
		add_action( 'plugins_loaded', array( $this, 'boot_integrations' ), 20 );

		Consent\WpConsentApiBridge::boot();
		Privacy\PrivacyShortcode::boot();

		/*
		 * Telemetria: só existe no request de quem optou (telemetry-design §3.3).
		 * Sem opt-in, nem o listener de contagem nem o handler de envio são registrados —
		 * a instalação que não optou não paga nada, nem uma leitura de option a mais.
		 */
		if ( Options::telemetry_enabled() ) {
			Telemetry\Counters::boot();
			Telemetry\Transport::boot();
		}

		Admin\SiteHealth::boot();

		if ( is_admin() ) {
			Admin\SettingsPage::instance()->boot();
			Admin\Notices::boot();
		}
	}

	/**
	 * Registra as integrações disponíveis.
	 *
	 * Prioridade 20 em `plugins_loaded`: depois do WooCommerce, para que a detecção veja
	 * o que existe de verdade. Integrações nativas entram sempre; Newsletter e WooCommerce
	 * só sob detecção real — e o que não entra no registry não aparece na tela de
	 * configurações, sem uma linha de UI condicional (FR-13).
	 *
	 * @return void
	 */
	public function boot_integrations(): void {
		$registry = Integrations\Registry::instance();

		$registry->register( new Integrations\Core\CommentIntegration() );
		$registry->register( new Integrations\Core\LoginIntegration() );
		$registry->register( new Integrations\Core\RegisterIntegration() );
		$registry->register( new Integrations\Core\LostPasswordIntegration() );

		$newsletter = new Integrations\Newsletter\SubscribeIntegration();

		if ( $newsletter->available() ) {
			$registry->register( $newsletter );
		}

		if ( Integrations\WooCommerce\Support::is_active() ) {
			$registry->register( new Integrations\WooCommerce\CheckoutIntegration() );
			$registry->register( new Integrations\WooCommerce\ReviewIntegration() );
			$registry->register( new Integrations\WooCommerce\LostPasswordIntegration() );
		}

		/**
		 * Permite registrar integrações antes do boot.
		 *
		 * @param Integrations\Registry $registry Registry.
		 */
		do_action( 'wp_recaptcha_forms_register_integrations', $registry );

		$registry->boot();

		Frontend\AssetManager::instance()->boot();
	}

	/**
	 * Carrega as traduções.
	 *
	 * No hook `init`, nunca em `plugins_loaded` nem em escopo de arquivo: a partir do
	 * WP 6.7 carregar cedo demais dispara `_load_textdomain_just_in_time` e a string
	 * pode sair não traduzida (arquitetura v1 §9.1).
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		I18n::boot();

		load_plugin_textdomain(
			'wp-recaptcha-forms',
			false,
			dirname( plugin_basename( WP_RECAPTCHA_FORMS_FILE ) ) . '/languages'
		);
	}

	/**
	 * Estado corrente do plugin, consumido pela tela de config e pelo Site Health.
	 *
	 * @return string Uma das constantes STATE_*.
	 */
	public static function state(): string {
		if ( wp_recaptcha_forms_is_killed() ) {
			return self::STATE_DISABLED;
		}

		if ( get_option( Options::OPTION_MISCONFIG_SINCE, '' ) ) {
			return self::STATE_DEGRADED;
		}

		if ( '' === Options::site_key() || '' === Options::secret_key() ) {
			return self::STATE_UNCONFIGURED;
		}

		return self::STATE_PROTECTING;
	}

	/**
	 * Hook de ativação.
	 *
	 * Não imprime nada e não chama função que produza saída: qualquer byte emitido aqui
	 * vira o clássico "the plugin generated N characters of unexpected output".
	 *
	 * @return void
	 */
	public static function on_activate(): void {
		Options::maybe_upgrade();
	}

	/**
	 * Hook de desativação.
	 *
	 * Não apaga configuração: desativar não é desinstalar. A limpeza total é do
	 * uninstall.php (S-02).
	 *
	 * @return void
	 */
	public static function on_deactivate(): void {
		delete_transient( 'wp_recaptcha_forms_keypair_total' );
		delete_transient( 'wp_recaptcha_forms_keypair_invalid' );
	}
}
