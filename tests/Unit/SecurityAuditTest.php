<?php
/**
 * Auditoria de segurança executável (Story 1.25).
 *
 * A Story 1.25 é majoritariamente auditoria, e auditoria que só existe em prosa apodrece
 * na primeira refatoração. Cada AC verificável virou asserção aqui.
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Admin\SettingsPage;
use WpRecaptchaForms\Gate\ClientState;
use WpRecaptchaForms\Gate\FormContext;
use WpRecaptchaForms\Gate\Gate;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Provider\SiteverifyV3;
use WpRecaptchaForms\Tests\Support\FakeTransport;
use WpStubs;

/**
 * O plugin não pode ser, ele mesmo, superfície de ataque.
 */
final class SecurityAuditTest extends TestCase {

	/**
	 * Zera stubs e configura um par de chaves.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();

		Options::update(
			array_merge(
				Options::defaults(),
				array(
					'site_key'   => 'site-key-publica',
					'secret_key' => 'secret-key-ultra-secreta',
					'forms'      => array(
						'wp_login' => array_merge( Options::form_defaults(), array( 'enabled' => true ) ),
					),
				)
			)
		);
	}

	/**
	 * AC 6 — token vazio bloqueia SEM tocar na rede.
	 *
	 * O `GateTest` já afirma `calls === 0` sobre um `FakeProvider`, o que prova que o
	 * Gate não chamou o provider. Aqui a asserção desce uma camada: provider REAL,
	 * transporte falso, e a prova é que o transporte nunca foi invocado. É o que separa
	 * "não chamamos o objeto" de "nenhum pacote sairia da máquina".
	 *
	 * @return void
	 */
	public function test_empty_token_never_reaches_the_transport(): void {
		$transport = new FakeTransport();
		$gate      = new Gate( new SiteverifyV3( $transport ) );

		$verdict = $gate->assess( new FormContext( 'wp_login', 'wrf_wp_login', '', ClientState::NONE, '203.0.113.10' ) );

		$this->assertTrue( $verdict->is_blocked() );
		$this->assertSame( 0, $transport->call_count(), 'token vazio não pode gastar rede nem cota' );
	}

	/**
	 * AC 6 (segunda metade) — o mesmo vale para a declaração de cliente inalcançável.
	 *
	 * @return void
	 */
	public function test_client_unreachable_never_reaches_the_transport(): void {
		$transport = new FakeTransport();
		$gate      = new Gate( new SiteverifyV3( $transport ) );

		$verdict = $gate->assess( new FormContext( 'wp_login', 'wrf_wp_login', '', ClientState::NO_SCRIPT, '203.0.113.10' ) );

		$this->assertTrue( $verdict->is_allowed() );
		$this->assertSame( 0, $transport->call_count() );
	}

	/**
	 * AC 2 — a secret nunca é ecoada em claro na tela de config.
	 *
	 * O campo é `type="password"` com `value=""`: o placeholder mostra a máscara, e o
	 * valor real não trafega de volta para o navegador em nenhuma forma.
	 *
	 * @return void
	 */
	public function test_secret_is_never_echoed_in_the_settings_screen(): void {
		WpStubs::$capabilities = array( 'manage_options' => true );

		$html = $this->render_settings();

		$this->assertStringNotContainsString( 'secret-key-ultra-secreta', $html, 'a secret vazou para o HTML do admin' );
		$this->assertStringContainsString( 'id="wrf-secret-key"', $html );
		$this->assertStringContainsString( 'type="password"', $html );
	}

	/**
	 * AC 2 — a site key é impressa escapada; a secret não aparece no payload do JS.
	 *
	 * @return void
	 */
	public function test_secret_is_never_localized_to_javascript(): void {
		WpStubs::$capabilities = array( 'manage_options' => true );

		$html = $this->render_settings();

		$this->assertStringNotContainsString( 'secret-key-ultra-secreta', $html );

		foreach ( WpStubs::$scripts as $script ) {
			$payload = wp_json_encode( $script['data'] ?? array() );

			$this->assertStringNotContainsString( 'secret-key-ultra-secreta', (string) $payload );
			$this->assertStringNotContainsString( 'secret', strtolower( (string) $payload ) );
		}
	}

	/**
	 * AC 5 — sem `manage_options`, a tela de config morre antes de imprimir qualquer coisa.
	 *
	 * @return void
	 */
	public function test_settings_screen_requires_manage_options(): void {
		WpStubs::$capabilities = array( 'manage_options' => false );

		$this->expectException( \WpDieException::class );

		$this->render_settings();
	}

	/**
	 * AC 3 — com a constante definida, o campo da secret fica desabilitado e sem `name`.
	 *
	 * Sem `name`, o campo não é submetido: mesmo que alguém remova o `disabled` pelo
	 * DevTools, não há como sobrescrever a constante pela tela. E o `Sanitizer` grava
	 * string vazia, para a option nunca virar uma segunda fonte de verdade que passe a
	 * valer no dia em que a constante sair do `wp-config.php`.
	 *
	 * Roda em processo separado porque define uma constante global: definida no processo
	 * da suíte, ela vazaria para todos os outros testes de secret.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_secret_from_constant_disables_the_field(): void {
		define( 'WP_RECAPTCHA_FORMS_SECRET_KEY', 'secret-da-constante' );

		WpStubs::reset();
		WpStubs::$capabilities = array( 'manage_options' => true );

		Options::update( array_merge( Options::defaults(), array( 'secret_key' => 'secret-do-banco' ) ) );

		$this->assertTrue( Options::secret_is_constant() );
		$this->assertSame( 'secret-da-constante', Options::secret_key() );

		$html = $this->render_settings();

		$this->assertStringContainsString( 'id="wrf-secret-key"', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( 'name="' . Options::OPTION . '[secret_key]"', $html );
		$this->assertStringNotContainsString( 'secret-da-constante', $html );

		$clean = \WpRecaptchaForms\Admin\Sanitizer::sanitize( array( 'secret_key' => 'tentativa-de-sobrescrever' ) );

		$this->assertSame( '', $clean['secret_key'], 'a option nunca compete com a constante' );
	}

	/**
	 * AC 8 — o único `error_log` do plugin nunca carrega a secret.
	 *
	 * A asserção é sobre o código, não sobre o runtime: o argumento do `error_log` é
	 * montado a partir de `debug_codes()`, e um `grep` que casasse `secret_key()` na
	 * mesma linha seria a regressão. Barato, e pega o erro real (alguém "melhorando" a
	 * mensagem de debug com a chave junto).
	 *
	 * @return void
	 */
	public function test_debug_log_line_cannot_carry_the_secret(): void {
		$source = (string) file_get_contents( WP_RECAPTCHA_FORMS_DIR . 'src/Gate/Gate.php' );

		preg_match_all( '/error_log\((.*)$/m', $source, $matches );

		$this->assertNotEmpty( $matches[1], 'o teste precisa achar a chamada para poder auditá-la' );

		foreach ( $matches[1] as $argument ) {
			$this->assertStringNotContainsString( 'secret_key', $argument );
			$this->assertStringNotContainsString( '$secret', $argument );
		}
	}

	/**
	 * AC 4 — nenhum formulário público imprime nonce.
	 *
	 * Nonce em página cacheada é o modo de falha que A3 existe para impedir: o HTML é
	 * idêntico para todo visitante, então um nonce impresso seria servido expirado (ou,
	 * pior, o de outro usuário). A ausência é decisão, e por isso é testada.
	 *
	 * @return void
	 */
	public function test_public_field_carries_no_nonce(): void {
		$html = \WpRecaptchaForms\Frontend\FieldRenderer::markup( 'wp_login', 'wrf_wp_login' );

		$this->assertStringNotContainsString( 'nonce', strtolower( $html ) );
		$this->assertStringContainsString( 'wrf_token', $html );
	}

	/**
	 * Captura o HTML da tela de config.
	 *
	 * @return string
	 */
	private function render_settings(): string {
		ob_start();

		try {
			( new SettingsPage() )->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}
}
