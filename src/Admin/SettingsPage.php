<?php
/**
 * Tela de configurações.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Admin;

use WpRecaptchaForms\Gate\FailurePolicy;
use WpRecaptchaForms\Integrations\Registry;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu, Settings API e render (arquitetura v1 §7.2, v1.1 §7.4).
 *
 * A matriz de formulários é GERADA a partir do `Registry`. Sem WooCommerce, as
 * integrações Woo nem existem no registry e a seção não é renderizada — zero código de
 * UI condicional, zero risco de a seção aparecer vazia (FR-13).
 */
final class SettingsPage {

	/** Slug da página. */
	const SLUG = 'wp-recaptcha-forms';

    /** Grupo de opções da Settings API. */
	const GROUP = 'wp_recaptcha_forms_group';

	/**
	 * Instância única.
	 *
	 * @var SettingsPage|null
	 */
	private static $instance = null;

	/**
	 * Acessa a instância.
	 *
	 * @return SettingsPage
	 */
	public static function instance(): SettingsPage {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registra os hooks de admin.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_filter( 'admin_footer_text', array( $this, 'filter_footer_text' ) );
		add_filter( 'update_footer', array( $this, 'filter_version_footer' ), 11 );
		add_filter(
			'plugin_action_links_' . plugin_basename( WP_RECAPTCHA_FORMS_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Remove o rodapé padrão do WordPress ("Obrigado por criar com o WordPress...")
	 * só na tela deste plugin — não mexe no rodapé do resto do admin.
	 *
	 * @param string $text Texto original do rodapé.
	 * @return string
	 */
	public function filter_footer_text( $text ) {
		$screen = get_current_screen();

		if ( $screen && 'settings_page_' . self::SLUG === $screen->id ) {
			return '';
		}

		return $text;
	}

	/**
	 * Remove o "Version X.Y" (canto direito do rodapé) só na tela deste plugin —
	 * mesmo par de hooks que o filtro do texto (admin_footer_text/update_footer),
	 * o WordPress só permite personalizar os dois juntos.
	 *
	 * @param string $text Texto original ("Version 7.1").
	 * @return string
	 */
	public function filter_version_footer( $text ) {
		$screen = get_current_screen();

		if ( $screen && 'settings_page_' . self::SLUG === $screen->id ) {
			return '';
		}

		return $text;
	}

	/**
	 * Adiciona o link "Configurações" na linha do plugin em Plugins > Instalados,
	 * ao lado de "Ativar"/"Desativar" — leva quem instala direto pra tela de config.
	 *
	 * @param array $links Links existentes.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ),
			esc_html__( 'Configurações', 'wp-recaptcha-forms' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * CSS da tela, só na tela.
	 *
	 * @param string $hook Hook da tela corrente.
	 * @return void
	 */
	public function enqueue_styles( $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-recaptcha-forms-admin',
			WP_RECAPTCHA_FORMS_URL . 'assets/css/admin.css',
			array(),
			WP_RECAPTCHA_FORMS_VERSION
		);
	}

	/**
	 * Registra o item de menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'WP reCAPTCHA Forms', 'wp-recaptcha-forms' ),
			__( 'WP reCAPTCHA Forms', 'wp-recaptcha-forms' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Registra a option na Settings API (nonce e capability vêm dela).
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Options::defaults(),
			)
		);
	}

	/**
	 * Sanitiza e valida a secret antes de gravar.
	 *
	 * @param mixed $input Entrada crua.
	 * @return array
	 */
	public function sanitize( $input ): array {
		$previous = Options::all();
		$clean    = Sanitizer::sanitize( $input );

		$secret_changed = ( $clean['secret_key'] ?? '' ) !== ( $previous['secret_key'] ?? '' );

		if ( $secret_changed && '' !== $clean['secret_key'] && ! Options::secret_is_constant() ) {
			$probe = KeyValidator::probe( $clean['secret_key'], $clean['version'] );

			if ( ! $probe['ok'] && 'invalid_secret' === $probe['reason'] ) {
				// Recusa o save DO CAMPO: mantém a secret anterior e explica.
				$clean['secret_key'] = (string) ( $previous['secret_key'] ?? '' );

				add_settings_error(
					Options::OPTION,
					'wrf_invalid_secret',
					__( 'A secret key foi recusada pelo Google e não foi salva. Confira se você copiou a secret key (não a site key) do mesmo projeto no console.', 'wp-recaptcha-forms' ),
					'error'
				);
			} elseif ( ! $probe['ok'] && 'unreachable' === $probe['reason'] ) {
				add_settings_error(
					Options::OPTION,
					'wrf_probe_unreachable',
					__( 'A secret key foi salva, mas não foi possível validá-la agora: o servidor não conseguiu falar com o Google. A validação acontece de novo na primeira verificação real.', 'wp-recaptcha-forms' ),
					'warning'
				);
			} else {
				// Secret boa: o estado degradado anterior deixou de valer.
				\WpRecaptchaForms\Gate\Gate::clear_misconfig();

				add_settings_error(
					Options::OPTION,
					'wrf_secret_ok',
					__( 'Secret key validada com o Google. (Esta checagem não prova que a site key pertence ao mesmo projeto — isso só aparece em uma verificação real.)', 'wp-recaptcha-forms' ),
					'success'
				);
			}
		}

		return $clean;
	}

	/**
	 * Render da tela.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'wp-recaptcha-forms' ) );
		}

		$options = Options::all();

		echo '<div class="wrap wrf-settings">';
		echo '<h1>' . esc_html__( 'WP reCAPTCHA Forms', 'wp-recaptcha-forms' ) . '</h1>';

		$this->render_state_banner();

		settings_errors( Options::OPTION );

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );

		$this->render_keys_section( $options );
		$this->render_failure_section( $options );
		$this->render_forms_section( $options );
		$this->render_messages_section( $options );

		submit_button();
		echo '</form>';

		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: build string. */ __( 'Build: %s', 'wp-recaptcha-forms' ), WP_RECAPTCHA_FORMS_BUILD ) ) . '</p>';
		echo '</div>';
	}

	/**
	 * Estado do plugin no topo da tela (arquitetura v1 §5.3-4, v1.1 §5.2).
	 *
	 * @return void
	 */
	private function render_state_banner(): void {
		switch ( Plugin::state() ) {
			case Plugin::STATE_DISABLED:
				$class = 'notice notice-warning';
				$text  = __( 'Desativado — a constante WRF_DISABLE está definida em wp-config.php e nenhum formulário está sendo verificado.', 'wp-recaptcha-forms' );
				break;

			case Plugin::STATE_DEGRADED:
				$class = 'notice notice-error';
				$text  = __( 'Degradado — não verificando. As chaves foram recusadas pelo Google e os formulários estão passando sem verificação.', 'wp-recaptcha-forms' );
				break;

			case Plugin::STATE_UNCONFIGURED:
				$class = 'notice notice-info';
				$text  = __( 'Ainda não configurado — informe a site key e a secret key para começar a proteger os formulários.', 'wp-recaptcha-forms' );
				break;

			default:
				$class = 'notice notice-success';
				$text  = __( 'Protegendo — as verificações estão funcionando.', 'wp-recaptcha-forms' );
				break;
		}

		echo '<div class="' . esc_attr( $class ) . ' inline"><p><strong>' . esc_html( $text ) . '</strong></p></div>';
	}

	/**
	 * Seção de chaves e versão.
	 *
	 * @param array $options Opções.
	 * @return void
	 */
	private function render_keys_section( array $options ): void {
		$name = Options::OPTION;

		echo '<h2>' . esc_html__( 'Chaves e versão', 'wp-recaptcha-forms' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		// Versão.
		echo '<tr><th scope="row">' . esc_html__( 'Versão do reCAPTCHA', 'wp-recaptcha-forms' ) . '</th><td>';
		echo '<label><input type="radio" name="' . esc_attr( $name ) . '[version]" value="v3" ' . checked( 'v3', $options['version'], false ) . '> ' . esc_html__( 'v3 (invisível, por score) — recomendado', 'wp-recaptcha-forms' ) . '</label><br>';
		echo '<label><input type="radio" name="' . esc_attr( $name ) . '[version]" value="v2" ' . checked( 'v2', $options['version'], false ) . '> ' . esc_html__( 'v2 (caixa "Não sou um robô")', 'wp-recaptcha-forms' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'As chaves do v2 e do v3 não são intercambiáveis. Ao trocar de versão, gere um par de chaves novo no console do Google para a versão escolhida.', 'wp-recaptcha-forms' ) . '</p>';
		echo '</td></tr>';

		// Site key.
		echo '<tr><th scope="row"><label for="wrf-site-key">' . esc_html__( 'Site key', 'wp-recaptcha-forms' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="wrf-site-key" name="' . esc_attr( $name ) . '[site_key]" value="' . esc_attr( $options['site_key'] ) . '" autocomplete="off">';
		echo '</td></tr>';

		// Secret key.
		echo '<tr><th scope="row"><label for="wrf-secret-key">' . esc_html__( 'Secret key', 'wp-recaptcha-forms' ) . '</label></th><td>';

		if ( Options::secret_is_constant() ) {
			echo '<input type="text" class="regular-text" id="wrf-secret-key" value="' . esc_attr( $this->mask( Options::secret_key() ) ) . '" disabled>';
			echo '<p class="description">' . esc_html__( 'Definida pela constante WP_RECAPTCHA_FORMS_SECRET_KEY em wp-config.php, que tem precedência sobre este campo.', 'wp-recaptcha-forms' ) . '</p>';
		} else {
			echo '<input type="password" class="regular-text" id="wrf-secret-key" name="' . esc_attr( $name ) . '[secret_key]" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== $options['secret_key'] ? $this->mask( $options['secret_key'] ) : __( 'ainda não configurada', 'wp-recaptcha-forms' ) ) . '">';
			echo '<p class="description">' . esc_html__( 'Deixe em branco para manter a secret atual. Ao salvar uma secret nova, ela é validada com o Google — o que prova que a secret está correta, mas não prova que a site key é do mesmo projeto.', 'wp-recaptcha-forms' ) . '</p>';
		}

		echo '</td></tr>';

		// Threshold.
		echo '<tr><th scope="row"><label for="wrf-threshold">' . esc_html__( 'Threshold de score (v3)', 'wp-recaptcha-forms' ) . '</label></th><td>';
		echo '<input type="number" step="0.05" min="0" max="1" id="wrf-threshold" name="' . esc_attr( $name ) . '[threshold]" value="' . esc_attr( (string) $options['threshold'] ) . '" class="small-text">';
		echo '<p class="description">' . esc_html__( 'Envios com score abaixo deste valor são bloqueados. 0.6 é um ponto de partida conservador. Só vale para o v3.', 'wp-recaptcha-forms' ) . '</p>';
		echo '</td></tr>';

		// remoteip.
		echo '<tr><th scope="row">' . esc_html__( 'Privacidade', 'wp-recaptcha-forms' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '[remoteip]" value="1" ' . checked( true, (bool) $options['remoteip'], false ) . '> ' . esc_html__( 'Enviar o endereço IP do visitante ao Google junto com a verificação', 'wp-recaptcha-forms' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Melhora a qualidade do score. Desligar não impede que o Google receba o IP: o script do reCAPTCHA é carregado do domínio do Google pelo navegador do visitante.', 'wp-recaptcha-forms' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		// Os dois fluxos de obtenção de chave (FR-24). URLs conferidas ao vivo no release.
		echo '<p class="description">';
		echo esc_html__( 'Onde obter as chaves:', 'wp-recaptcha-forms' ) . ' ';
		echo '<a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener noreferrer">' . esc_html__( 'console clássico do reCAPTCHA (recomendado)', 'wp-recaptcha-forms' ) . '</a>';
		echo ' · ';
		echo '<a href="https://console.cloud.google.com/security/recaptcha" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Cloud / reCAPTCHA Enterprise', 'wp-recaptcha-forms' ) . '</a>';
		echo '</p>';
	}

	/**
	 * Seção dos dois eixos de política de falha.
	 *
	 * Rótulos em linguagem de operador, não de arquiteto (v1.1 §7.4).
	 *
	 * @param array $options Opções.
	 * @return void
	 */
	private function render_failure_section( array $options ): void {
		$name = Options::OPTION;

		echo '<h2>' . esc_html__( 'Quando a verificação não for possível', 'wp-recaptcha-forms' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wrf-policy-infra">' . esc_html__( 'Se o nosso servidor não conseguir falar com o Google', 'wp-recaptcha-forms' ) . '</label></th><td>';
		$this->render_policy_select( $name . '[failure_policy_infra]', 'wrf-policy-infra', (string) $options['failure_policy_infra'], false );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="wrf-policy-cu">' . esc_html__( 'Se o navegador do visitante não conseguir carregar o reCAPTCHA (bloqueador de anúncios, cookies recusados)', 'wp-recaptcha-forms' ) . '</label></th><td>';
		$this->render_policy_select( $name . '[failure_policy_client_unreachable]', 'wrf-policy-cu', (string) $options['failure_policy_client_unreachable'], false );
		echo '<p class="description">';
		echo esc_html__( 'Com esta opção em "permitir", um visitante cujo navegador não consegue carregar o reCAPTCHA envia o formulário sem verificação. Isso preserva a venda de clientes com bloqueador de anúncios — e também deixa passar um robô que simule a mesma condição. Em "bloquear", nenhum envio não verificado passa, e visitantes com bloqueador não conseguem comprar, comentar nem se registrar.', 'wp-recaptcha-forms' );
		echo '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'Envios que o Google verificou e reprovou são sempre bloqueados — isso não é configurável, porque torná-lo configurável desligaria o plugin por padrão.', 'wp-recaptcha-forms' ) . '</p>';
	}

	/**
	 * Select de política.
	 *
	 * @param string $field    Nome do campo.
	 * @param string $id       Id.
	 * @param string $value    Valor atual.
	 * @param bool   $inherit  Inclui a opção "herdar"?
	 * @return void
	 */
	private function render_policy_select( string $field, string $id, string $value, bool $inherit ): void {
		echo '<select name="' . esc_attr( $field ) . '" id="' . esc_attr( $id ) . '">';

		if ( $inherit ) {
			echo '<option value="' . esc_attr( FailurePolicy::INHERIT ) . '" ' . selected( FailurePolicy::INHERIT, $value, false ) . '>' . esc_html__( 'Herdar do global', 'wp-recaptcha-forms' ) . '</option>';
		}

		echo '<option value="' . esc_attr( FailurePolicy::ALLOW ) . '" ' . selected( FailurePolicy::ALLOW, $value, false ) . '>' . esc_html__( 'Permitir o envio', 'wp-recaptcha-forms' ) . '</option>';
		echo '<option value="' . esc_attr( FailurePolicy::BLOCK ) . '" ' . selected( FailurePolicy::BLOCK, $value, false ) . '>' . esc_html__( 'Bloquear o envio', 'wp-recaptcha-forms' ) . '</option>';
		echo '</select>';
	}

	/**
	 * Matriz de formulários, gerada a partir do Registry.
	 *
	 * @param array $options Opções.
	 * @return void
	 */
	private function render_forms_section( array $options ): void {
		$grouped = Registry::instance()->grouped();

		echo '<h2>' . esc_html__( 'Formulários protegidos', 'wp-recaptcha-forms' ) . '</h2>';

		if ( empty( $grouped ) ) {
			echo '<p>' . esc_html__( 'Nenhuma integração de formulário está registrada nesta instalação.', 'wp-recaptcha-forms' ) . '</p>';

			return;
		}

		$name = Options::OPTION;

		foreach ( $grouped as $group => $integrations ) {
			echo '<h3>' . esc_html( $this->group_label( (string) $group ) ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Formulário', 'wp-recaptcha-forms' ) . '</th>';
			echo '<th>' . esc_html__( 'Proteger', 'wp-recaptcha-forms' ) . '</th>';
			echo '<th>' . esc_html__( 'Se o servidor não alcançar o Google', 'wp-recaptcha-forms' ) . '</th>';
			echo '<th>' . esc_html__( 'Se o navegador não carregar o reCAPTCHA', 'wp-recaptcha-forms' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $integrations as $integration ) {
				$id        = $integration->id();
				$form      = Options::form( $id );
				$available = $integration->available();
				$disabled  = $available ? '' : ' disabled';

				echo '<tr>';
				echo '<td><strong>' . esc_html( $integration->label() ) . '</strong>';

				if ( ! $available ) {
					echo '<br><span class="description">' . esc_html( $integration->unavailable_reason() ) . '</span>';
				}

				echo '</td>';

				echo '<td><label><input type="checkbox" name="' . esc_attr( $name ) . '[forms][' . esc_attr( $id ) . '][enabled]" value="1" ' . checked( true, (bool) $form['enabled'], false ) . $disabled . '></label></td>';

				echo '<td>';
				$this->render_policy_select( $name . '[forms][' . $id . '][policy_infra]', 'wrf-' . $id . '-infra', (string) $form['policy_infra'], true );
				echo '</td>';

				echo '<td>';
				$this->render_policy_select( $name . '[forms][' . $id . '][policy_client_unreachable]', 'wrf-' . $id . '-cu', (string) $form['policy_client_unreachable'], true );
				echo '</td>';

				echo '</tr>';
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * Mensagens customizáveis.
	 *
	 * @param array $options Opções.
	 * @return void
	 */
	private function render_messages_section( array $options ): void {
		$name = Options::OPTION;

		$fields = array(
			'rejected'           => __( 'Envio reprovado pelo Google', 'wp-recaptcha-forms' ),
			'infra'              => __( 'Verificação indisponível para o servidor', 'wp-recaptcha-forms' ),
			'client_unreachable' => __( 'O navegador do visitante não carregou o reCAPTCHA', 'wp-recaptcha-forms' ),
			'unreachable_notice' => __( 'Aviso exibido no próprio formulário', 'wp-recaptcha-forms' ),
		);

		echo '<h2>' . esc_html__( 'Mensagens', 'wp-recaptcha-forms' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Deixe em branco para usar o texto padrão, já traduzido.', 'wp-recaptcha-forms' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $fields as $key => $label ) {
			echo '<tr><th scope="row"><label for="wrf-msg-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<textarea class="large-text" rows="2" id="wrf-msg-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '[messages][' . esc_attr( $key ) . ']">' . esc_textarea( (string) ( $options['messages'][ $key ] ?? '' ) ) . '</textarea>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Rótulo de um grupo.
	 *
	 * @param string $group Grupo.
	 * @return string
	 */
	private function group_label( string $group ): string {
		switch ( $group ) {
			case 'woocommerce':
				return __( 'WooCommerce', 'wp-recaptcha-forms' );

			case 'newsletter':
				return __( 'Newsletter', 'wp-recaptcha-forms' );

			default:
				return __( 'WordPress', 'wp-recaptcha-forms' );
		}
	}

	/**
	 * Máscara de exibição de uma chave. A secret nunca é ecoada em claro.
	 *
	 * @param string $value Valor.
	 * @return string
	 */
	private function mask( string $value ): string {
		$len = strlen( $value );

		if ( $len <= 4 ) {
			return str_repeat( '•', max( $len, 4 ) );
		}

		return str_repeat( '•', 8 ) . substr( $value, -4 );
	}
}
