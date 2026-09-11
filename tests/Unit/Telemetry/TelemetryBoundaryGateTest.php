<?php
/**
 * Gate de CI de `src/Telemetry/` (Story 1.30).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;

/**
 * Um gate que nunca foi visto reprovando é um gate que ninguém sabe se funciona.
 *
 * O script aceita um diretório alternativo justamente para poder ser exercitado sobre um
 * fixture temporário, sem que o teste precise plantar um arquivo dentro de `src/` — o que
 * deixaria lixo no repositório se a suíte fosse interrompida no meio.
 */
final class TelemetryBoundaryGateTest extends TestCase {

	/**
	 * Raiz do plugin.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Diretório do fixture.
	 *
	 * @var string
	 */
	private $fixture = '';

	/**
	 * Preparação.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->root = dirname( __DIR__, 3 );

		if ( ! is_readable( $this->root . '/bin/check-boundary.sh' ) ) {
			$this->markTestSkipped( 'gate de fronteira ausente' );
		}

		$this->fixture = sys_get_temp_dir() . '/wrf-boundary-' . uniqid();
		mkdir( $this->fixture );
	}

	/**
	 * Limpeza.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( '' === $this->fixture || ! is_dir( $this->fixture ) ) {
			return;
		}

		foreach ( (array) glob( $this->fixture . '/*' ) as $file ) {
			unlink( (string) $file );
		}

		rmdir( $this->fixture );
	}

	/**
	 * Roda o gate sobre o fixture.
	 *
	 * @param string $php Conteúdo do arquivo plantado.
	 * @return int Código de saída.
	 */
	private function run_gate( string $php ): int {
		file_put_contents( $this->fixture . '/Leak.php', "<?php\n" . $php . "\n" );

		$command = 'cd ' . escapeshellarg( $this->root )
			. ' && bash bin/check-boundary.sh ' . escapeshellarg( $this->fixture ) . ' 2>&1';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- teste de suíte, não código de plugin: o objeto sob teste É um script shell, e a única forma honesta de verificar um gate de CI é executá-lo.
		exec( $command, $output, $code );

		return (int) $code;
	}

	/**
	 * Cada padrão proibido reprova.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function leaks(): array {
		return array(
			'home_url'          => array( '$ua = "wrf/1.0 (" . home_url() . ")";' ),
			'site_url'          => array( '$x = site_url();' ),
			'get_bloginfo'      => array( '$x = get_bloginfo( "name" );' ),
			'network_site_url'  => array( '$x = network_site_url();' ),
			'network_home_url'  => array( '$x = network_home_url();' ),
			'option siteurl'    => array( '$x = get_option( "siteurl" );' ),
			'option blogname'   => array( '$x = get_option( \'blogname\' );' ),
			'option adminmail'  => array( '$x = get_option( "admin_email" );' ),
			'HTTP_HOST'         => array( '$x = $_SERVER["HTTP_HOST"];' ),
			'SERVER_NAME'       => array( '$x = $_SERVER[\'SERVER_NAME\'];' ),
			'em comentario'     => array( '// nunca use site_url() aqui.' ),
			'DOCUMENT_ROOT'     => array( '$x = $_SERVER["DOCUMENT_ROOT"];' ),
			'SERVER_ADDR'       => array( '$x = $_SERVER[\'SERVER_ADDR\'];' ),
			'REMOTE_ADDR'       => array( '$x = $_SERVER["REMOTE_ADDR"];' ),
			'ABSPATH concat'    => array( '$p = ABSPATH . "wp-load.php";' ),
			'WP_CONTENT_DIR'    => array( '$p = WP_CONTENT_DIR;' ),
			'WP_CONTENT_URL'    => array( '$p = WP_CONTENT_URL;' ),
			'WP_PLUGIN_DIR'     => array( '$p = WP_PLUGIN_DIR;' ),
			'php_uname'         => array( '$x = php_uname();' ),
			'gethostname'       => array( '$x = gethostname();' ),
			'gethostbyname'     => array( '$x = gethostbyname( "localhost" );' ),
			'__DIR__'           => array( '$p = __DIR__ . "/x.php";' ),
			'__FILE__'          => array( '$p = __FILE__;' ),
			'plugin_dir_path'   => array( '$p = plugin_dir_path( "x" );' ),
			'wp_upload_dir'     => array( '$p = wp_upload_dir();' ),
			'wp_get_upload_dir' => array( '$p = wp_get_upload_dir();' ),
		);
	}

	/**
	 * O gate reprova, com código de saída diferente de zero.
	 *
	 * @dataProvider leaks
	 * @param string $php Trecho plantado.
	 * @return void
	 */
	public function test_gate_rejects_leaks( string $php ): void {
		$this->assertNotSame( 0, $this->run_gate( $php ), 'o gate deixou passar: ' . $php );
	}

	/**
	 * `get_locale()`/`determine_locale()` NÃO reprovam: locale não é URL e não
	 * identifica o site. É a exceção nomeada no §6.2, e o bloco `host` depende dela.
	 *
	 * @return void
	 */
	public function test_locale_is_not_a_false_positive(): void {
		$this->assertSame( 0, $this->run_gate( '$l = get_locale(); $d = determine_locale();' ) );
	}

	/**
	 * O User-Agent real do transporte passa: é constante, não função.
	 *
	 * @return void
	 */
	public function test_real_user_agent_passes(): void {
		$this->assertSame( 0, $this->run_gate( '$ua = "wp-recaptcha-forms/" . WP_RECAPTCHA_FORMS_VERSION;' ) );
	}

	/**
	 * O guard `if ( ! defined( 'ABSPATH' ) )` no topo de cada arquivo é o único uso
	 * legítimo de `ABSPATH` e NÃO pode reprovar — senão o gate barra o próprio padrão
	 * de segurança do WordPress.
	 *
	 * @return void
	 */
	public function test_abspath_guard_is_not_a_false_positive(): void {
		$this->assertSame( 0, $this->run_gate( "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}" ) );
	}

	/**
	 * E o `src/Telemetry/` de verdade passa — o gate é trava, não dívida.
	 *
	 * @return void
	 */
	public function test_real_telemetry_directory_passes(): void {
		$command = 'cd ' . escapeshellarg( $this->root ) . ' && bash bin/check-boundary.sh 2>&1';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- idem.
		exec( $command, $output, $code );

		$this->assertSame( 0, (int) $code, implode( "\n", $output ) );
		$this->assertStringContainsString( 'não revela identidade do site', implode( "\n", $output ) );
	}
}
