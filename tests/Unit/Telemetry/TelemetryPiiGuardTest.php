<?php
/**
 * Barreira de PII (Story 1.29 AC 5).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Telemetry\Envelope;
use WpRecaptchaForms\Telemetry\PiiGuard;
use WpStubs;

/**
 * Um falso positivo custa um envelope descartado; um falso negativo custa a promessa que
 * a tela de config faz ao operador. Os dois lados têm teste.
 */
final class TelemetryPiiGuardTest extends TestCase {

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WpStubs::reset();
	}

	/**
	 * O envelope real passa limpo.
	 *
	 * @return void
	 */
	public function test_real_envelope_is_clean(): void {
		$this->assertSame( '', PiiGuard::inspect( Envelope::build() ) );
	}

	/**
	 * Chave `site_url` adulterada no payload é recusada.
	 *
	 * @return void
	 */
	public function test_rejects_forbidden_key(): void {
		$envelope                            = Envelope::build();
		$envelope['payload']['host']['site_url'] = 'exemplo';

		$this->assertStringContainsString( 'chave suspeita', PiiGuard::inspect( $envelope ) );
	}

	/**
	 * Chaves proibidas, uma a uma.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function forbidden_keys(): array {
		return array(
			'ip'      => array( 'ip' ),
			'email'   => array( 'email' ),
			'url'     => array( 'url' ),
			'domain'  => array( 'domain' ),
			'user'    => array( 'user_id' ),
			'token'   => array( 'token' ),
			'secret'  => array( 'secret_key' ),
			'key'     => array( 'site_key' ),
			'sufixo'  => array( 'visitor_ip' ),
			'no_meio' => array( 'admin_email_hash' ),
		);
	}

	/**
	 * Cada uma reprova.
	 *
	 * @dataProvider forbidden_keys
	 * @param string $key Chave.
	 * @return void
	 */
	public function test_forbidden_keys( string $key ): void {
		$this->assertNotSame( '', PiiGuard::inspect( array( 'payload' => array( $key => 'x' ) ) ) );
	}

	/**
	 * Valores com aparência de PII são recusados.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function forbidden_values(): array {
		return array(
			'e-mail'     => array( 'operador@exemplo.com.br' ),
			'ipv4'       => array( '203.0.113.42' ),
			'ipv6'       => array( '2001:0db8:85a3:0000:0000:8a2e:0370:7334' ),
			'ipv6 curto' => array( 'fe80::1' ),
			'url'        => array( 'https://exemplo.com.br/loja' ),
		);
	}

	/**
	 * Cada um reprova.
	 *
	 * @dataProvider forbidden_values
	 * @param string $value Valor.
	 * @return void
	 */
	public function test_forbidden_values( string $value ): void {
		$this->assertNotSame( '', PiiGuard::inspect( array( 'payload' => array( 'campo' => $value ) ) ) );
	}

	/**
	 * As chaves legítimas do payload real NÃO reprovam.
	 *
	 * `host` é o bloco de ambiente e está na allowlist; `remoteip` e `keypair_suspect_triggered`
	 * colidiriam com a regex se ela casasse subcadeia em vez de token.
	 *
	 * @return void
	 */
	public function test_legitimate_keys_pass(): void {
		$clean = array(
			'host'                      => array( 'php' => '8.2' ),
			'remoteip'                  => true,
			'keypair_suspect_triggered' => false,
			'kill_switch'               => false,
			'threshold_bucket'          => '0.5-0.7',
		);

		$this->assertSame( '', PiiGuard::inspect( $clean ) );
	}

	/**
	 * O caso que quase passou despercebido: uma hora ISO-8601 tem dois grupos hex
	 * separados por `:`, e uma regex de IPv6 frouxa a trataria como endereço — o que
	 * reprovaria TODO envelope, já que `sent_at` sempre existe.
	 *
	 * @return void
	 */
	public function test_iso_timestamps_are_not_mistaken_for_ipv6(): void {
		$this->assertSame( '', PiiGuard::inspect( array( 'sent_at' => '2026-09-09T03:17:44Z' ) ) );
		$this->assertSame( '', PiiGuard::inspect( array( 'window' => array( 'from' => '2026-12-31T23:59:59Z' ) ) ) );
	}

	/**
	 * Os identificadores de schema não são URL: não têm esquema.
	 *
	 * @return void
	 */
	public function test_schema_identifiers_are_not_urls(): void {
		$this->assertSame(
			'',
			PiiGuard::inspect(
				array(
					'schema'         => 'gm.telemetry/v1',
					'payload_schema' => 'wp-recaptcha-forms/usage.snapshot/v1',
				)
			)
		);
	}

	/**
	 * Sob `WP_DEBUG`, um envelope sujo LANÇA: bug do autor do plugin tem de doer no
	 * ambiente do autor.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_throws_under_wp_debug(): void {
		define( 'WP_DEBUG', true );

		$this->expectException( \RuntimeException::class );

		PiiGuard::assert_clean( array( 'payload' => array( 'user_email' => 'a@b.com' ) ) );
	}

	/**
	 * Sem `WP_DEBUG`, devolve o motivo e não lança: derrubar o cron de um site de
	 * terceiro por causa de um bug meu é o que o §6.3 proíbe.
	 *
	 * @return void
	 */
	public function test_does_not_throw_without_wp_debug(): void {
		$this->assertNotSame( '', PiiGuard::assert_clean( array( 'payload' => array( 'user_email' => 'a@b.com' ) ) ) );
	}
}
