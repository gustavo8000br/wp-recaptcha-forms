<?php
/**
 * Purga de desinstalação, com foco nos artefatos de telemetria (Story 1.27 AC 5).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Options;
use WpStubs;

/**
 * "Desinstalar apaga o identificador" (§1.3) é uma promessa publicada. A varredura por
 * prefixo do `uninstall.php` a cumpre sem lista literal — e é justamente por isso que ela
 * precisa de teste: uma varredura é fácil de acreditar e fácil de errar.
 *
 * O `uninstall.php` real fala com `$wpdb`, que a suíte unitária não carrega. O que este
 * teste exercita é a MESMA regra (todo artefato do plugin cai sob um dos dois prefixos
 * declarados em `Options::OPTION_PREFIXES`), aplicada ao inventário de options que a
 * telemetria cria de fato.
 */
final class UninstallTest extends TestCase {

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
	}

	/**
	 * Purga por prefixo, espelhando o `LIKE` do `uninstall.php`.
	 *
	 * @return int Quantas options foram removidas.
	 */
	private function purge_by_prefix(): int {
		$removed = 0;

		$scopes = array( '', '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' );

		foreach ( array_keys( WpStubs::$options ) as $name ) {
			foreach ( Options::OPTION_PREFIXES as $prefix ) {
				foreach ( $scopes as $scope ) {
					if ( 0 === strpos( (string) $name, $scope . $prefix ) ) {
						delete_option( $name );
						++$removed;

						continue 3;
					}
				}
			}
		}

		return $removed;
	}

	/**
	 * Os três artefatos de telemetria somem: o identificador (dentro da option de
	 * settings), os contadores e o transiente de retentativa.
	 *
	 * @return void
	 */
	public function test_purge_removes_every_telemetry_artifact(): void {
		Options::update_telemetry(
			array(
				'enabled'     => true,
				'instance_id' => str_repeat( 'a', 32 ),
			)
		);

		update_option( Options::OPTION_TELEMETRY_COUNTERS, array( 'total' => 12 ) );
		update_option( '_transient_wp_recaptcha_forms_telemetry_envelope', 'algum-uuid' );

		// Uma option de outro plugin, para provar que a varredura não é indiscriminada.
		update_option( 'algum_outro_plugin_option', 'preservar' );

		$this->assertNotSame( '', Options::telemetry_instance_id() );

		$this->purge_by_prefix();
		Options::flush_cache();

		$this->assertArrayNotHasKey( Options::OPTION, WpStubs::$options );
		$this->assertArrayNotHasKey( Options::OPTION_TELEMETRY_COUNTERS, WpStubs::$options );
		$this->assertArrayNotHasKey( '_transient_wp_recaptcha_forms_telemetry_envelope', WpStubs::$options );
		$this->assertSame( 'preservar', get_option( 'algum_outro_plugin_option' ) );

		// E o identificador some junto com a option que o carregava.
		$this->assertSame( '', Options::telemetry_instance_id() );
	}

	/**
	 * O `uninstall.php` nomeia os artefatos de telemetria em comentário.
	 *
	 * Rastreabilidade para o auditor: quem procura "instance_id" no arquivo precisa
	 * encontrar a resposta, não o silêncio de uma varredura genérica.
	 *
	 * @return void
	 */
	public function test_uninstall_documents_telemetry_artifacts(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );

		$this->assertStringContainsString( 'instance_id', $source );
		$this->assertStringContainsString( Options::OPTION_TELEMETRY_COUNTERS, $source );
	}

	/**
	 * Os eventos de WP-Cron da telemetria vivem na option agregada `cron`, fora do
	 * alcance da varredura por prefixo — o `uninstall.php` tem de limpá-los à parte
	 * com `wp_clear_scheduled_hook()`, senão sobra agendamento órfão depois de apagar
	 * o plugin (M-1 da revisão de QA da telemetria).
	 *
	 * @return void
	 */
	public function test_uninstall_clears_telemetry_cron_events(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );

		$this->assertStringContainsString( "wp_clear_scheduled_hook( 'wp_recaptcha_forms_telemetry_send' )", $source );
		$this->assertStringContainsString( "wp_clear_scheduled_hook( 'wp_recaptcha_forms_telemetry_retry' )", $source );
	}
}
