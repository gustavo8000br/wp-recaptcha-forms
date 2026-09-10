<?php
/**
 * Montagem do envelope e do payload (Story 1.29).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Telemetry\Counters;
use WpRecaptchaForms\Telemetry\Envelope;
use WpStubs;

/**
 * A decisão T-3 do dono — proporções e baldes, nunca contagens exatas — é a regra que
 * este arquivo existe para tornar irreversível.
 */
final class TelemetryEnvelopeTest extends TestCase {

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
		Counters::reset_runtime();

		Options::update_telemetry(
			array(
				'enabled'     => true,
				'instance_id' => str_repeat( 'a', 32 ),
			)
		);
	}

	/**
	 * Grava contadores diretamente.
	 *
	 * @param array $values Valores.
	 * @return void
	 */
	private function counters( array $values ): void {
		update_option( Options::OPTION_TELEMETRY_COUNTERS, $values );
	}

	/**
	 * O envelope tem todas as chaves obrigatórias de §1.1, com os tipos de §1.2.
	 *
	 * @return void
	 */
	public function test_envelope_shape(): void {
		$envelope = Envelope::build();

		$this->assertSame( 'gm.telemetry/v1', $envelope['schema'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $envelope['envelope_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $envelope['sent_at'] );

		$this->assertSame( 'wp-recaptcha-forms', $envelope['source']['project'] );
		// phpcs:ignore WordPress.WP.CapitalPDangit --  valor de enum do contrato de ingestão (§1.2), minúsculo por especificação. Não é o nome do software.
		$this->assertSame( 'wordpress', $envelope['source']['platform'] );
		$this->assertSame( WP_RECAPTCHA_FORMS_VERSION, $envelope['source']['version'] );
		$this->assertSame( str_repeat( 'a', 32 ), $envelope['source']['instance_id'] );

		$this->assertContains( $envelope['environment'], array( 'production', 'staging', 'development', 'unknown' ) );

		$this->assertSame( 'usage.snapshot', $envelope['event']['type'] );
		$this->assertArrayHasKey( 'from', $envelope['event']['window'] );
		$this->assertArrayHasKey( 'to', $envelope['event']['window'] );

		$this->assertSame( 'wp-recaptcha-forms/usage.snapshot/v1', $envelope['payload_schema'] );
		$this->assertSame( array( 'plugin', 'host', 'verdicts', 'health' ), array_keys( $envelope['payload'] ) );
	}

	/**
	 * `seq` no envelope é o PRÓXIMO — e `build()` não o incrementa, porque é puro.
	 *
	 * @return void
	 */
	public function test_seq_is_next_and_build_is_pure(): void {
		Options::update_telemetry( array( 'seq' => 36 ) );

		$before = WpStubs::$options;

		$envelope = Envelope::build();

		$this->assertSame( 37, $envelope['event']['seq'] );
		$this->assertSame( 36, Options::telemetry()['seq'], 'build() não pode incrementar seq' );
		$this->assertEquals( $before, WpStubs::$options, 'build() não pode escrever nada' );
	}

	/**
	 * `build( $id )` preserva o `envelope_id` passado — é disso que depende a
	 * idempotência da API no retry (§1.2).
	 *
	 * @return void
	 */
	public function test_build_preserves_given_envelope_id(): void {
		$id = '0d1b1f7a-2f4c-4a01-9d0e-3a2b5c7e91aa';

		$this->assertSame( $id, Envelope::build( $id )['envelope_id'] );
		$this->assertNotSame( $id, Envelope::build()['envelope_id'] );
	}

	/**
	 * Threshold vira balde, e o float não aparece em lugar nenhum do envelope.
	 *
	 * @return void
	 */
	public function test_threshold_becomes_a_bucket_and_the_float_disappears(): void {
		$all              = Options::all();
		$all['threshold'] = 0.62;
		Options::update( $all );

		$envelope = Envelope::build();

		$this->assertSame( '0.5-0.7', $envelope['payload']['plugin']['threshold_bucket'] );
		$this->assertStringNotContainsString( '0.62', wp_json_encode( $envelope ) );
	}

	/**
	 * Baldes de threshold nas bordas.
	 *
	 * @return array<string, array{0:float, 1:string}>
	 */
	public function thresholds(): array {
		return array(
			'muito baixo' => array( 0.1, '<0.3' ),
			'borda 0.3'   => array( 0.3, '0.3-0.5' ),
			'borda 0.5'   => array( 0.5, '0.5-0.7' ),
			'default'     => array( 0.6, '0.5-0.7' ),
			'borda 0.7'   => array( 0.7, '0.7-0.9' ),
			'borda 0.9'   => array( 0.9, '0.7-0.9' ),
			'acima'       => array( 0.95, '>0.9' ),
		);
	}

	/**
	 * Cada borda cai no balde certo.
	 *
	 * @dataProvider thresholds
	 * @param float  $value    Threshold.
	 * @param string $expected Balde.
	 * @return void
	 */
	public function test_threshold_buckets( float $value, string $expected ): void {
		$all              = Options::all();
		$all['threshold'] = $value;
		Options::update( $all );

		$this->assertSame( $expected, Envelope::build()['payload']['plugin']['threshold_bucket'] );
	}

	/**
	 * Baldes de volume nas bordas.
	 *
	 * @return array<string, array{0:int, 1:string}>
	 */
	public function volumes(): array {
		return array(
			'vazio'  => array( 0, '<100' ),
			'99'     => array( 99, '<100' ),
			'100'    => array( 100, '100-1k' ),
			'999'    => array( 999, '100-1k' ),
			'1000'   => array( 1000, '1k-10k' ),
			'9999'   => array( 9999, '1k-10k' ),
			'10000'  => array( 10000, '10k-100k' ),
			'100000' => array( 100000, '>100k' ),
		);
	}

	/**
	 * Cada borda cai no balde certo.
	 *
	 * @dataProvider volumes
	 * @param int    $total    Total.
	 * @param string $expected Balde.
	 * @return void
	 */
	public function test_total_buckets( int $total, string $expected ): void {
		$this->counters( array( 'total' => $total ) );

		$this->assertSame( $expected, Envelope::build()['payload']['verdicts']['total_bucket'] );
	}

	/**
	 * PHP, WP e MySQL saem sem componente de patch.
	 *
	 * @return void
	 */
	public function test_host_versions_have_no_patch_component(): void {
		$GLOBALS['wp_version'] = '6.7.2';

		$host = Envelope::build()['payload']['host'];

		$this->assertSame( '6.7', $host['wp'] );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $host['php'] );
		$this->assertSame( 'pt_BR', $host['locale'] );
		$this->assertFalse( $host['multisite'] );

		unset( $GLOBALS['wp_version'] );
	}

	/**
	 * `distribution` soma ~1.0 e carrega as cinco classes.
	 *
	 * @return void
	 */
	public function test_distribution_sums_to_one(): void {
		$this->counters(
			array(
				'window_from' => time() - DAY_IN_SECONDS,
				'total'       => 1000,
				'blocked'     => 41,
				'by_class'    => array(
					'allowed'            => 943,
					'rejected'           => 41,
					'infra'              => 2,
					'misconfig'          => 0,
					'client_unreachable' => 14,
				),
				'by_form'     => array(),
			)
		);

		$verdicts = Envelope::build()['payload']['verdicts'];

		$this->assertEqualsWithDelta( 1.0, array_sum( $verdicts['distribution'] ), 0.001 );
		$this->assertSame( 0.943, $verdicts['distribution']['allowed'] );
		$this->assertSame( 0.014, $verdicts['distribution']['client_unreachable'] );
		$this->assertSame( 0.041, $verdicts['blocked_share'] );
	}

	/**
	 * `by_form` omite formulário com 49 avaliações e inclui com 50: sob volume baixo,
	 * proporção não é estatística, é o comportamento de um punhado de visitantes
	 * identificáveis (§3.1).
	 *
	 * @return void
	 */
	public function test_by_form_sample_cutoff(): void {
		$this->counters(
			array(
				'total'    => 1000,
				'by_class' => array(),
				'by_form'  => array(
					'wp_login'   => array(
						'total'              => 49,
						'client_unreachable' => 1,
					),
					'wp_comment' => array(
						'total'              => 50,
						'client_unreachable' => 5,
					),
				),
			)
		);

		$by_form = Envelope::build()['payload']['verdicts']['by_form'];

		$this->assertArrayNotHasKey( 'wp_login', $by_form );
		$this->assertArrayHasKey( 'wp_comment', $by_form );
		$this->assertSame( 0.05, $by_form['wp_comment']['share_of_total'] );
		$this->assertSame( 0.1, $by_form['wp_comment']['client_unreachable'] );
	}

	/**
	 * `by_form` descarta qualquer id fora do vocabulário fechado — inclusive um que um
	 * plugin de terceiro tenha registrado pelo filtro de integrações.
	 *
	 * @return void
	 */
	public function test_by_form_rejects_ids_outside_the_closed_vocabulary(): void {
		$this->counters(
			array(
				'total'   => 1000,
				'by_form' => array(
					'formulario_do_meu_site' => array(
						'total'              => 500,
						'client_unreachable' => 0,
					),
				),
			)
		);

		$this->assertSame( array(), Envelope::build()['payload']['verdicts']['by_form'] );
	}

	/**
	 * `forms_enabled` só contém `form_id` do vocabulário fechado, e o contador bate.
	 *
	 * @return void
	 */
	public function test_forms_enabled_is_closed_vocabulary(): void {
		$all          = Options::all();
		$all['forms'] = array(
			'wp_login'             => array_merge( Options::form_defaults(), array( 'enabled' => true ) ),
			'woocommerce_checkout' => array_merge( Options::form_defaults(), array( 'enabled' => true ) ),
			'wp_comment'           => array_merge( Options::form_defaults(), array( 'enabled' => false ) ),
			'formulario_inventado' => array_merge( Options::form_defaults(), array( 'enabled' => true ) ),
		);
		Options::update( $all );

		$plugin = Envelope::build()['payload']['plugin'];

		$this->assertSame( array( 'wp_login', 'woocommerce_checkout' ), $plugin['forms_enabled'] );
		$this->assertSame( 2, $plugin['forms_enabled_count'] );

		foreach ( $plugin['forms_enabled'] as $form_id ) {
			$this->assertContains( $form_id, Envelope::FORM_IDS );
		}
	}

	/**
	 * Nenhuma contagem absoluta chega ao payload.
	 *
	 * A varredura ignora `forms_enabled_count`, `misconfig_days_active` e `event.seq`,
	 * que são os três inteiros legítimos — e nenhum deles é volume de negócio.
	 *
	 * @return void
	 */
	public function test_no_absolute_counts_leak_into_the_payload(): void {
		$this->counters(
			array(
				'total'    => 87456,
				'blocked'  => 1234,
				'by_class' => array(
					'allowed'  => 86222,
					'rejected' => 1234,
				),
				'by_form'  => array(
					'wp_login' => array(
						'total'              => 5000,
						'client_unreachable' => 321,
					),
				),
			)
		);

		$envelope = Envelope::build();

		$this->assertSame( 0, $this->count_big_integers( $envelope['payload'] ) );

		// E os números crus não aparecem nem como string.
		$json = wp_json_encode( $envelope );

		foreach ( array( '87456', '1234', '86222', '5000', '321' ) as $raw ) {
			$this->assertStringNotContainsString( $raw, $json );
		}
	}

	/**
	 * Conta inteiros > 100 fora das chaves legítimas.
	 *
	 * @param mixed  $node Nó.
	 * @param string $key  Chave do nó.
	 * @return int
	 */
	private function count_big_integers( $node, string $key = '' ): int {
		$allowed = array( 'forms_enabled_count', 'misconfig_days_active', 'seq' );

		if ( is_array( $node ) ) {
			$found = 0;

			foreach ( $node as $child_key => $child ) {
				$found += $this->count_big_integers( $child, (string) $child_key );
			}

			return $found;
		}

		if ( is_int( $node ) && $node > 100 && ! in_array( $key, $allowed, true ) ) {
			return 1;
		}

		return 0;
	}

	/**
	 * Sem contagem nenhuma, a janela ainda é declarada: `from` é `to` menos sete dias.
	 *
	 * @return void
	 */
	public function test_window_is_always_declared(): void {
		$envelope = Envelope::build();

		$from = strtotime( $envelope['event']['window']['from'] );
		$to   = strtotime( $envelope['event']['window']['to'] );

		$this->assertSame( 7 * DAY_IN_SECONDS, $to - $from );
		$this->assertSame( '<100', $envelope['payload']['verdicts']['total_bucket'] );
	}

	/**
	 * A janela declarada acompanha `window_from` dos contadores — inclusive quando o
	 * envio da semana anterior falhou e a janela ficou maior que sete dias (§6.3).
	 *
	 * @return void
	 */
	public function test_window_follows_the_counters(): void {
		$from = time() - ( 14 * DAY_IN_SECONDS );

		$this->counters(
			array(
				'window_from' => $from,
				'total'       => 10,
			)
		);

		$envelope = Envelope::build();

		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $from ), $envelope['event']['window']['from'] );
	}

	/**
	 * `environment` traduz `local` para `development` e desconhecido para `unknown`.
	 *
	 * @return void
	 */
	public function test_environment_mapping(): void {
		WpStubs::$env['environment_type'] = 'local';
		$this->assertSame( 'development', Envelope::build()['environment'] );

		WpStubs::$env['environment_type'] = 'staging';
		$this->assertSame( 'staging', Envelope::build()['environment'] );

		WpStubs::$env['environment_type'] = 'homologacao';
		$this->assertSame( 'unknown', Envelope::build()['environment'] );
	}

	/**
	 * `health` conta os dias de MISCONFIG ativo.
	 *
	 * @return void
	 */
	public function test_health_block(): void {
		update_option( Options::OPTION_MISCONFIG_SINCE, time() - ( 3 * DAY_IN_SECONDS ) );
		update_option( Options::OPTION_KEYPAIR_SUSPECT, time() );

		$health = Envelope::build()['payload']['health'];

		$this->assertSame( 3, $health['misconfig_days_active'] );
		$this->assertTrue( $health['keypair_suspect_triggered'] );
	}

	/**
	 * Instalação limpa: zero dias, sem advisory.
	 *
	 * @return void
	 */
	public function test_health_block_when_clean(): void {
		$health = Envelope::build()['payload']['health'];

		$this->assertSame( 0, $health['misconfig_days_active'] );
		$this->assertFalse( $health['keypair_suspect_triggered'] );
	}

	/**
	 * O envelope cabe folgado no limite de 64 KB do §1.2.
	 *
	 * @return void
	 */
	public function test_envelope_is_far_below_the_size_limit(): void {
		$this->assertLessThan( 64 * 1024, strlen( (string) wp_json_encode( Envelope::build() ) ) );
	}
}
