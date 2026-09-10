<?php
/**
 * Seção de telemetria na tela de config (Story 1.32).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Admin\SettingsPage;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Telemetry\Counters;
use WpRecaptchaForms\Telemetry\Endpoints;
use WpRecaptchaForms\Telemetry\Schedule;
use WpStubs;

/**
 * O botão de pré-visualização é o coração da story: transparência verificável vale mais
 * que qualquer parágrafo de política, e por isso ele funciona com o toggle desligado.
 */
final class TelemetrySettingsTest extends TestCase {

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
		Counters::reset_runtime();

		WpStubs::$capabilities = array( 'manage_options' => true );

		$_GET = array();
	}

	/**
	 * Limpeza.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET = array();
	}

	/**
	 * Renderiza a tela.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		( new SettingsPage() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * Salva pela Settings API.
	 *
	 * @param array $input Entrada.
	 * @return array
	 */
	private function save( array $input ): array {
		$clean = ( new SettingsPage() )->sanitize( $input );

		Options::update( $clean );

		return $clean;
	}

	/**
	 * A seção existe e vem DEPOIS de todas as que afetam o funcionamento.
	 *
	 * @return void
	 */
	public function test_section_renders_last(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Telemetria (opcional)', $html );

		$telemetry = strpos( $html, 'Telemetria (opcional)' );

		foreach ( array( 'Chaves e versão', 'Quando a verificação não for possível', 'Formulários protegidos', 'Mensagens' ) as $earlier ) {
			$this->assertLessThan( $telemetry, strpos( $html, $earlier ), $earlier . ' deveria vir antes da telemetria' );
		}
	}

	/**
	 * Os três textos do §4.2 estão na tela.
	 *
	 * @return void
	 */
	public function test_section_text(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Enviar estatísticas de uso anônimas para o autor do plugin', $html );
		$this->assertStringContainsString( 'uma vez por semana', $html );
		$this->assertStringContainsString( 'Não envia:', $html );
		$this->assertStringContainsString( 'suas chaves do reCAPTCHA', $html );
		$this->assertStringContainsString( 'passa a ser uma instalação nova', $html );
		$this->assertStringContainsString( Endpoints::PRIVACY, $html );
	}

	/**
	 * Salvar marcado passa por `Consent::grant()`: gera identificador e agenda o cron.
	 *
	 * @return void
	 */
	public function test_saving_checked_grants_consent(): void {
		$this->save( array( 'telemetry' => array( 'enabled' => '1' ) ) );

		$this->assertTrue( Options::telemetry_enabled() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Options::telemetry_instance_id() );
		$this->assertArrayHasKey( Schedule::HOOK, WpStubs::$cron );
	}

	/**
	 * Salvar desmarcado passa por `Consent::revoke()`: apaga identificador, cron e
	 * contadores.
	 *
	 * @return void
	 */
	public function test_saving_unchecked_revokes_consent(): void {
		$this->save( array( 'telemetry' => array( 'enabled' => '1' ) ) );
		update_option( Options::OPTION_TELEMETRY_COUNTERS, array( 'total' => 10 ) );

		$this->save( array() );

		$this->assertFalse( Options::telemetry_enabled() );
		$this->assertSame( '', Options::telemetry_instance_id() );
		$this->assertArrayNotHasKey( Schedule::HOOK, WpStubs::$cron );
		$this->assertArrayNotHasKey( Options::OPTION_TELEMETRY_COUNTERS, WpStubs::$options );
	}

	/**
	 * Um save que não mexe na telemetria PRESERVA o identificador.
	 *
	 * Sem isso, qualquer edição de threshold romperia o vínculo da instalação e a série
	 * temporal do operador começaria do zero sem que ele tivesse pedido nada.
	 *
	 * @return void
	 */
	public function test_unrelated_save_preserves_the_instance_id(): void {
		$this->save( array( 'telemetry' => array( 'enabled' => '1' ) ) );

		$id = Options::telemetry_instance_id();

		$this->save(
			array(
				'telemetry' => array( 'enabled' => '1' ),
				'threshold' => '0.8',
			)
		);

		$this->assertSame( $id, Options::telemetry_instance_id() );
		$this->assertSame( 0.8, Options::threshold() );
	}

	/**
	 * O botão de pré-visualização NUNCA vem `disabled`, nem com o toggle desligado.
	 *
	 * @return void
	 */
	public function test_preview_button_is_never_disabled(): void {
		$html = $this->render();

		$this->assertFalse( Options::telemetry_enabled() );
		$this->assertStringContainsString( 'Ver exatamente o que será enviado', $html );

		$position = strpos( $html, 'Ver exatamente o que será enviado' );
		$anchor   = substr( $html, (int) $position - 250, 300 );

		$this->assertStringNotContainsString( 'disabled', $anchor );
	}

	/**
	 * A pré-visualização sai do MESMO código do envio, funciona com a telemetria
	 * desligada, e não tem efeito colateral.
	 *
	 * @return void
	 */
	public function test_preview_uses_the_real_builder_without_side_effects(): void {
		$_GET['wrf_preview'] = '1';
		$_GET['_wpnonce']    = wp_create_nonce( SettingsPage::PREVIEW_ACTION );

		$before = WpStubs::$options;

		$html = $this->render();

		$this->assertStringContainsString( '<pre class="wrf-telemetry-preview">', $html );
		$this->assertStringContainsString( 'gm.telemetry/v1', $html );
		$this->assertStringContainsString( 'wp-recaptcha-forms/usage.snapshot/v1', $html );
		$this->assertStringContainsString( 'usage.snapshot', $html );

		$this->assertEquals( $before, WpStubs::$options, 'a pré-visualização não pode escrever nada' );
	}

	/**
	 * Sem nonce, não há pré-visualização.
	 *
	 * @return void
	 */
	public function test_preview_requires_a_nonce(): void {
		$_GET['wrf_preview'] = '1';

		$this->assertStringNotContainsString( 'wrf-telemetry-preview', $this->render() );

		$_GET['_wpnonce'] = 'nonce-errado';

		$this->assertStringNotContainsString( 'wrf-telemetry-preview', $this->render() );
	}

	/**
	 * Sem capability, não há pré-visualização — nem tela.
	 *
	 * @return void
	 */
	public function test_preview_requires_the_capability(): void {
		WpStubs::$capabilities = array( 'manage_options' => false );

		$_GET['wrf_preview'] = '1';
		$_GET['_wpnonce']    = wp_create_nonce( SettingsPage::PREVIEW_ACTION );

		$this->expectException( \WpDieException::class );

		try {
			$this->render();
		} finally {
			// `wp_die()` vira exceção nos stubs e escapa antes do `ob_get_clean()`.
			// Sem isto o buffer vaza e o PHPUnit marca o teste como risky.
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Sem o parâmetro, a tela normal não carrega o `<pre>`.
	 *
	 * @return void
	 */
	public function test_no_preview_by_default(): void {
		$this->assertStringNotContainsString( 'wrf-telemetry-preview', $this->render() );
	}

	/**
	 * Nenhum notice promocional: a tela de config é o ÚNICO lugar onde a telemetria
	 * aparece. Sem dark pattern, sem modal na primeira execução.
	 *
	 * @return void
	 */
	public function test_no_promotional_notice(): void {
		$this->render();

		$this->assertArrayNotHasKey( 'admin_notices', WpStubs::$filters );
		$this->assertArrayNotHasKey( 'network_admin_notices', WpStubs::$filters );
	}

	/**
	 * Com `WRF_TELEMETRY_DISABLE`, o checkbox vem `disabled` e com a nota — e um POST
	 * forjado não liga a telemetria pelas costas da constante.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_constant_disables_the_checkbox(): void {
		define( 'WRF_TELEMETRY_DISABLE', true );

		WpStubs::reset();
		WpStubs::$capabilities = array( 'manage_options' => true );

		ob_start();
		( new SettingsPage() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Desativado por constante em wp-config.php.', $html );
		$this->assertStringContainsString( 'disabled', $html );

		// POST forjado tentando ligar.
		Options::update( ( new SettingsPage() )->sanitize( array( 'telemetry' => array( 'enabled' => '1' ) ) ) );

		$this->assertSame( '', Options::telemetry_instance_id() );
		$this->assertFalse( Options::telemetry_enabled() );
	}

	/**
	 * `last_status` de erro vira uma linha discreta DENTRO da seção, nunca um notice.
	 *
	 * @return void
	 */
	public function test_local_diagnostic_line(): void {
		Options::update_telemetry(
			array(
				'enabled'     => true,
				'instance_id' => str_repeat( 'a', 32 ),
				'last_status' => 'error',
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Último envio: não concluído', $html );
		$this->assertStringContainsString( 'não afeta a proteção', $html );
		$this->assertArrayNotHasKey( 'admin_notices', WpStubs::$filters );
	}

	/**
	 * Sem erro, nenhuma linha de diagnóstico.
	 *
	 * @return void
	 */
	public function test_no_diagnostic_line_when_healthy(): void {
		Options::update_telemetry(
			array(
				'enabled'     => true,
				'instance_id' => str_repeat( 'a', 32 ),
				'last_status' => 'ok',
			)
		);

		$this->assertStringNotContainsString( 'Último envio: não concluído', $this->render() );
	}
}
