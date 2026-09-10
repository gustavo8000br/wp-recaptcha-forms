<?php
/**
 * Schema da opção `telemetry` e chaves de desligamento (Story 1.26).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Options;
use WpStubs;

/**
 * A regra que este arquivo protege é uma só: nenhuma instalação envia dado sem opt-in
 * explícito, e quem administra por `wp-config.php` desliga sem tocar no banco.
 */
final class TelemetryOptionTest extends TestCase {

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
	}

	/**
	 * Instalação nova nasce desligada.
	 *
	 * @return void
	 */
	public function test_default_is_off(): void {
		$telemetry = Options::telemetry();

		$this->assertFalse( $telemetry['enabled'] );
		$this->assertSame( '', $telemetry['instance_id'] );
		$this->assertSame( 0, $telemetry['last_sent'] );
		$this->assertSame( '', $telemetry['last_status'] );
		$this->assertSame( 0, $telemetry['seq'] );
		$this->assertFalse( Options::telemetry_enabled() );
	}

	/**
	 * Opção gravada sem a chave `telemetry` (instalação anterior à feature) devolve os
	 * cinco campos com default, nunca um sub-array parcial.
	 *
	 * @return void
	 */
	public function test_missing_subarray_falls_back_to_defaults(): void {
		$stored = Options::defaults();
		unset( $stored['telemetry'] );
		update_option( Options::OPTION, $stored );
		Options::flush_cache();

		$this->assertSame(
			array( 'enabled', 'instance_id', 'last_sent', 'last_status', 'seq' ),
			array_keys( Options::telemetry() )
		);
	}

	/**
	 * Sub-array parcial ou inválido também é completado.
	 *
	 * @return void
	 */
	public function test_partial_or_invalid_subarray_is_merged(): void {
		$stored              = Options::defaults();
		$stored['telemetry'] = array( 'enabled' => true );
		update_option( Options::OPTION, $stored );
		Options::flush_cache();

		$this->assertSame( 0, Options::telemetry()['seq'] );

		$stored['telemetry'] = 'lixo';
		update_option( Options::OPTION, $stored );
		Options::flush_cache();

		$this->assertFalse( Options::telemetry()['enabled'] );
	}

	/**
	 * `enabled = true` sem `instance_id` não é telemetria ligada: sem identificador não
	 * há envelope válido, e um "ligado" que não pode enviar é estado mentiroso.
	 *
	 * @return void
	 */
	public function test_enabled_without_instance_id_is_off(): void {
		Options::update_telemetry( array( 'enabled' => true ) );

		$this->assertFalse( Options::telemetry_enabled() );
	}

	/**
	 * Com os dois, liga.
	 *
	 * @return void
	 */
	public function test_enabled_with_instance_id_is_on(): void {
		Options::update_telemetry(
			array(
				'enabled'     => true,
				'instance_id' => str_repeat( 'a', 32 ),
			)
		);

		$this->assertTrue( Options::telemetry_enabled() );
		$this->assertSame( str_repeat( 'a', 32 ), Options::telemetry_instance_id() );
	}

	/**
	 * `WRF_TELEMETRY_DISABLE` desliga só a telemetria, mesmo com a opção ligada.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_dedicated_constant_disables_telemetry(): void {
		define( 'WRF_TELEMETRY_DISABLE', true );

		Options::update_telemetry(
			array(
				'enabled'     => true,
				'instance_id' => str_repeat( 'b', 32 ),
			)
		);

		$this->assertFalse( Options::telemetry_enabled() );
		$this->assertTrue( Options::telemetry_disabled_by_constant() );
	}

	/**
	 * `WRF_DISABLE` desliga o plugin inteiro, portanto também a telemetria.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_plugin_kill_switch_disables_telemetry(): void {
		define( 'WRF_DISABLE', true );

		Options::update_telemetry(
			array(
				'enabled'     => true,
				'instance_id' => str_repeat( 'c', 32 ),
			)
		);

		$this->assertFalse( Options::telemetry_enabled() );
	}

	/**
	 * Migração de instalação existente: acrescenta `telemetry` desligada e preserva o
	 * resto. Uma atualização do plugin nunca liga (§4.1 regra 2).
	 *
	 * @return void
	 */
	public function test_upgrade_adds_telemetry_without_enabling_it(): void {
		$legacy = Options::defaults();
		unset( $legacy['telemetry'] );
		$legacy['site_key']   = 'chave-preservada';
		$legacy['threshold']  = 0.8;
		$legacy['forms']      = array( 'wp_login' => array( 'enabled' => true ) );

		update_option( Options::OPTION, $legacy );
		update_option( Options::OPTION_SCHEMA_VERSION, 1 );
		Options::flush_cache();

		Options::maybe_upgrade();

		$stored = get_option( Options::OPTION );

		$this->assertSame( Options::SCHEMA_VERSION, Options::schema_version() );
		$this->assertFalse( $stored['telemetry']['enabled'] );
		$this->assertSame( '', $stored['telemetry']['instance_id'] );
		$this->assertSame( 'chave-preservada', $stored['site_key'] );
		$this->assertSame( 0.8, $stored['threshold'] );
		$this->assertTrue( $stored['forms']['wp_login']['enabled'] );
		$this->assertFalse( Options::telemetry_enabled() );
	}

	/**
	 * Migração não sobrescreve uma telemetria já ligada por quem optou.
	 *
	 * @return void
	 */
	public function test_upgrade_preserves_existing_telemetry(): void {
		$stored              = Options::defaults();
		$stored['telemetry'] = array(
			'enabled'     => true,
			'instance_id' => str_repeat( 'd', 32 ),
			'last_sent'   => 123,
			'last_status' => 'ok',
			'seq'         => 7,
		);

		update_option( Options::OPTION, $stored );
		update_option( Options::OPTION_SCHEMA_VERSION, 1 );
		Options::flush_cache();

		Options::maybe_upgrade();

		$this->assertSame( 7, Options::telemetry()['seq'] );
		$this->assertTrue( Options::telemetry_enabled() );
	}
}
