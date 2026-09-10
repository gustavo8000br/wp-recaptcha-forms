<?php
/**
 * Ciclo de vida do `instance_id` (Story 1.27).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Telemetry\Consent;
use WpRecaptchaForms\Telemetry\Counters;
use WpRecaptchaForms\Telemetry\Schedule;
use WpStubs;

/**
 * O `instance_id` é o campo que decide se o desenho é honesto (§1.3). Este arquivo prova
 * as duas propriedades que sustentam a promessa: é aleatório, e desligar rompe o vínculo.
 */
final class TelemetryConsentTest extends TestCase {

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
		Counters::reset_runtime();
	}

	/**
	 * `grant()` gera 32 hex de fonte criptográfica.
	 *
	 * @return void
	 */
	public function test_grant_generates_32_hex_chars(): void {
		Consent::grant();

		$id = Options::telemetry_instance_id();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $id );
		$this->assertTrue( Options::telemetry_enabled() );
	}

	/**
	 * Entropia real: dois `grant()` depois de um `revoke()` produzem valores diferentes.
	 *
	 * @return void
	 */
	public function test_instance_id_has_real_entropy(): void {
		Consent::grant();
		$first = Options::telemetry_instance_id();

		Consent::revoke();
		Consent::grant();
		$second = Options::telemetry_instance_id();

		$this->assertNotSame( $first, $second );
	}

	/**
	 * O identificador NÃO é derivado de atributo da instalação.
	 *
	 * A asserção é sobre o código, e não sobre um spy, de propósito: o que precisa ser
	 * verdade daqui a dois anos é que o arquivo não CONTÉM a derivação — um spy só
	 * provaria que o caminho testado não a usou hoje.
	 *
	 * @return void
	 */
	public function test_generation_does_not_derive_from_site_attributes(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Telemetry/Consent.php' );

		// Nem menção: o gate de CI da Story 1.30 casa o nome da função dentro de
		// `src/Telemetry/`, e um comentário que a cite reprovaria o gate. O comentário do
		// arquivo explica a armadilha sem nomeá-la.
		foreach ( array( 'site_url', 'home_url', 'get_bloginfo', 'network_site_url' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $source );
		}

		foreach ( array( 'DB_NAME', 'AUTH_SALT', "get_option( 'siteurl'", 'HTTP_HOST' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $source );
		}

		$this->assertStringContainsString( 'random_bytes(', $source );
	}

	/**
	 * `grant()` agenda o cron com jitter derivado do `instance_id`.
	 *
	 * @return void
	 */
	public function test_grant_schedules_cron(): void {
		Consent::grant();

		$this->assertArrayHasKey( Schedule::HOOK, WpStubs::$cron );
		$this->assertSame( Schedule::RECURRENCE, WpStubs::$cron[ Schedule::HOOK ][0]['recurrence'] );
	}

	/**
	 * O jitter é determinístico por instalação: a mesma instalação cai sempre no mesmo
	 * ponto da semana, e a base fica espalhada.
	 *
	 * @return void
	 */
	public function test_jitter_is_deterministic(): void {
		$this->assertSame( Schedule::offset_for( 'b7f3c1a9e2d84f60b1c5a7d3e9f04628' ), Schedule::offset_for( 'b7f3c1a9e2d84f60b1c5a7d3e9f04628' ) );
		$this->assertSame( (int) ( hexdec( 'b7f3' ) % ( 7 * DAY_IN_SECONDS ) ), Schedule::offset_for( 'b7f3c1a9e2d84f60b1c5a7d3e9f04628' ) );
		$this->assertLessThan( 7 * DAY_IN_SECONDS, Schedule::offset_for( str_repeat( 'f', 32 ) ) );
		$this->assertSame( 0, Schedule::offset_for( '' ) );
	}

	/**
	 * `revoke()` é ruptura, não pausa: apaga o identificador, zera o diagnóstico,
	 * desagenda o cron e apaga os contadores.
	 *
	 * @return void
	 */
	public function test_revoke_breaks_the_link(): void {
		Consent::grant();

		Options::update_telemetry(
			array(
				'last_sent'   => 123456,
				'last_status' => 'ok',
				'seq'         => 9,
			)
		);
		update_option( Options::OPTION_TELEMETRY_COUNTERS, array( 'total' => 40 ) );

		Consent::revoke();

		$telemetry = Options::telemetry();

		$this->assertFalse( $telemetry['enabled'] );
		$this->assertSame( '', $telemetry['instance_id'] );
		$this->assertSame( 0, $telemetry['last_sent'] );
		$this->assertSame( '', $telemetry['last_status'] );
		$this->assertSame( 0, $telemetry['seq'] );
		$this->assertArrayNotHasKey( Schedule::HOOK, WpStubs::$cron );
		$this->assertArrayNotHasKey( Options::OPTION_TELEMETRY_COUNTERS, WpStubs::$options );
		$this->assertFalse( Options::telemetry_enabled() );
	}

	/**
	 * `grant()` com telemetria já ligada não regenera o `instance_id` — o jitter depende
	 * dele ser estável enquanto a telemetria fica ligada (AC 6).
	 *
	 * @return void
	 */
	public function test_grant_is_idempotent(): void {
		Consent::grant();
		$first = Options::telemetry_instance_id();

		Consent::grant();

		$this->assertSame( $first, Options::telemetry_instance_id() );
	}

	/**
	 * `revoke()` com telemetria já desligada é no-op — não dispara o hook nem escreve.
	 *
	 * @return void
	 */
	public function test_revoke_is_idempotent(): void {
		Consent::revoke();

		$fired = array_filter(
			WpStubs::$actions_fired,
			static function ( $entry ) {
				return 'wp_recaptcha_forms_telemetry_consent_changed' === $entry[0];
			}
		);

		$this->assertSame( array(), $fired );
	}

	/**
	 * O hook de observabilidade carrega o novo estado.
	 *
	 * @return void
	 */
	public function test_consent_changed_hook(): void {
		$states = array();

		add_action(
			'wp_recaptcha_forms_telemetry_consent_changed',
			static function ( $enabled ) use ( &$states ) {
				$states[] = $enabled;
			}
		);

		Consent::grant();
		Consent::revoke();

		$this->assertSame( array( true, false ), $states );
	}
}
