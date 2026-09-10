<?php
/**
 * Coerência da documentação com a feature entregue (Story 1.34).
 *
 * @package WpRecaptchaForms
 */

namespace WpRecaptchaForms\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use WpRecaptchaForms\Options;

/**
 * A frase "Sem telemetria" abria o README, em negrito, numa lista intitulada "o que ele
 * NÃO faz, dito na abertura para você não descobrir depois". Deixá-la de pé com
 * telemetria implementada tornaria o próprio README a prova de que o projeto não cumpre o
 * critério que ele mesmo estabelece.
 *
 * Este arquivo é o que impede a frase de voltar num merge distraído.
 */
final class TelemetryDocsTest extends TestCase {

	/**
	 * Lê um arquivo da raiz do plugin.
	 *
	 * @param string $relative Caminho relativo.
	 * @return string
	 */
	private function read( string $relative ): string {
		$path = dirname( __DIR__, 3 ) . '/' . $relative;

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	/**
	 * Colapsa quebras de linha e espaços, para comparar prosa sem depender da largura da
	 * coluna em que ela foi quebrada.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private function squash( string $text ): string {
		return (string) preg_replace( '/\s+/u', ' ', $text );
	}

	/**
	 * A promessa antiga não existe mais, e a nova está lá.
	 *
	 * @return void
	 */
	public function test_readme_no_longer_claims_there_is_no_telemetry(): void {
		$readme = $this->read( 'README.md' );

		$this->assertStringNotContainsString( 'Sem telemetria,', $this->squash( $readme ) );
		$this->assertStringContainsString( 'Sem telemetria ligada por padrão', $this->squash( $readme ) );
		$this->assertStringContainsString( 'desligado de fábrica', $this->squash( $readme ) );
		$this->assertStringContainsString( 'Nenhuma atualização do plugin liga isso', $this->squash( $readme ) );
	}

	/**
	 * Idem no README em inglês.
	 *
	 * @return void
	 */
	public function test_english_readme_matches(): void {
		$readme = $this->read( 'README-EN.md' );

		$this->assertStringNotContainsString( 'No telemetry,', $this->squash( $readme ) );
		$this->assertStringContainsString( 'No telemetry enabled by default', $this->squash( $readme ) );
		$this->assertStringContainsString( 'off by default', $this->squash( $readme ) );
	}

	/**
	 * Os dois READMEs documentam a constante de desligamento e o botão de transparência.
	 *
	 * @return void
	 */
	public function test_readmes_document_the_kill_switch_and_the_preview(): void {
		foreach ( array( 'README.md', 'README-EN.md' ) as $file ) {
			$readme = $this->read( $file );

			$this->assertStringContainsString( 'WRF_TELEMETRY_DISABLE', $readme, $file );
			$this->assertStringContainsString( 'WRF_DISABLE', $readme, $file );
		}

		/*
		 * Comparação insensível a quebra de linha: os READMEs são quebrados em 100
		 * colunas, e uma asserção sobre a frase inteira falharia por causa da largura da
		 * coluna, não por causa do conteúdo — que é o oposto do que este teste vigia.
		 */
		$this->assertStringContainsString( 'Ver exatamente o que será', $this->squash( $this->read( 'README.md' ) ) );
		$this->assertStringContainsString( 'See exactly what will be sent', $this->squash( $this->read( 'README-EN.md' ) ) );
	}

	/**
	 * O CHANGELOG traz a palavra "telemetria" visível — quem audita changelog procura por
	 * ela — e classifica a mudança como MINOR, com o registro do que seria MAJOR.
	 *
	 * @return void
	 */
	public function test_changelog_mentions_telemetry_and_classifies_it(): void {
		$changelog = $this->read( 'CHANGELOG.md' );

		$this->assertStringContainsString( 'Telemetria', $changelog );
		$this->assertStringContainsString( 'MINOR', $changelog );
		$this->assertStringContainsString( 'não pretende fazê-lo', $this->squash( $changelog ) );
		$this->assertStringContainsString( 'WRF_TELEMETRY_DISABLE', $changelog );
	}

	/**
	 * Todo artefato de telemetria cai sob um dos prefixos que o `uninstall.php` varre.
	 *
	 * Se algum dia um deles ficar fora, este teste falha e o `uninstall.php` precisa de
	 * uma entrada explícita — que é exatamente o que a AC 5 pede.
	 *
	 * @return void
	 */
	public function test_every_telemetry_artifact_is_under_a_swept_prefix(): void {
		$artifacts = array(
			Options::OPTION,
			Options::OPTION_TELEMETRY_COUNTERS,
			\WpRecaptchaForms\Telemetry\Transport::TRANSIENT_RETRY_ID,
		);

		foreach ( $artifacts as $name ) {
			$covered = false;

			foreach ( Options::OPTION_PREFIXES as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$covered = true;

					break;
				}
			}

			$this->assertTrue( $covered, $name . ' está fora dos prefixos varridos pelo uninstall.php' );
		}
	}

	/**
	 * O checklist de release tem os itens bloqueantes do T-2.
	 *
	 * @return void
	 */
	public function test_release_checklist_blocks_on_the_public_policy(): void {
		$checklist = $this->read( 'docs/release-checklist.md' );

		$checklist = $this->squash( $checklist );

		$this->assertStringContainsString( 'não registrar o IP', $checklist );
		$this->assertStringContainsString( 'Bloqueia o release', $checklist );
		$this->assertStringContainsString( 'readme.txt', $checklist );
	}

	/**
	 * O plano de submissão ao WP.org registra que a divulgação tem de estar no
	 * `readme.txt` — o revisor lê esse arquivo, não o `README.md`.
	 *
	 * @return void
	 */
	public function test_submission_plan_requires_disclosure_in_readme_txt(): void {
		$plan = $this->read( 'docs/wordpress-org-submission-plan.md' );

		$plan = $this->squash( $plan );

		$this->assertStringContainsString( 'phoning home', $plan );
		$this->assertStringContainsString( '`readme.txt`', $plan );
		$this->assertStringContainsString( 'opt-in e vem desligado', $plan );
	}
}
