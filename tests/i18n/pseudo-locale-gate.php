<?php
/**
 * Gate de pseudo-locale (Story 1.20 AC 3 e AC 5, fecha BL-05).
 *
 * Um mecanismo, dois problemas. Ativando um catálogo `en_CA` em que toda string conhecida
 * vira `⟦string⟧`, qualquer texto visível que apareça **fora** dos marcadores só pode ter
 * duas causas: ou foi impresso sem passar por `__()`, ou passou com o text domain errado.
 * Nos dois casos é bug, e nos dois casos ele nunca chegaria ao usuário traduzido.
 *
 * Escopo (AC 3): o gate avalia o **texto visível** produzido pelas superfícies do plugin,
 * não o HTML inteiro. Tags, atributos, nomes de campo e valores de opção ficam de fora por
 * construção — `wp_strip_all_tags()` é o delimitador, e ele é mais estável que qualquer
 * tentativa de recortar containers do DOM.
 *
 * Não usa PHPUnit de propósito: roda em `bin/i18n-e2e.sh`, precisa montar o catálogo antes
 * do primeiro `__()` e reporta em linguagem de gate, não de asserção.
 *
 * @package WpRecaptchaForms
 */

declare( strict_types = 1 );

require_once dirname( __DIR__, 2 ) . '/bin/i18n-po.php';
require_once dirname( __DIR__ ) . '/bootstrap.php';

use WpRecaptchaForms\Admin\Notices;
use WpRecaptchaForms\Admin\SettingsPage;
use WpRecaptchaForms\Admin\SiteHealth;
use WpRecaptchaForms\DisabledMode;
use WpRecaptchaForms\Frontend\Messages;
use WpRecaptchaForms\Integrations\Registry;
use WpRecaptchaForms\Options;
use WpRecaptchaForms\Privacy\PrivacyNotice;
use WpRecaptchaForms\Provider\FailureClass;

$root = dirname( __DIR__, 2 );

// ---------------------------------------------------------------------------
// Catálogo pseudo-traduzido.
// ---------------------------------------------------------------------------

$catalog = array();

foreach ( wrf_po_parse( $root . '/languages/wp-recaptcha-forms-en_CA.po' ) as $entry ) {
	if ( '' === $entry['msgid'] || array() === $entry['msgstr'] ) {
		continue;
	}

	$catalog[ wrf_po_key( $entry ) ] = $entry['msgstr'][0];
}

if ( count( $catalog ) < 50 ) {
	fwrite( STDERR, "FALHOU: catálogo pseudo vazio ou raso — gere o .pot antes.\n" );
	exit( 1 );
}

// ---------------------------------------------------------------------------
// Allowlist.
// ---------------------------------------------------------------------------

$allowlist = array();

foreach ( file( $root . '/languages/i18n-allowlist.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	$line = trim( $line );

	if ( '' === $line || '#' === $line[0] ) {
		continue;
	}

	$allowlist[] = $line;
}

usort(
	$allowlist,
	static function ( $a, $b ) {
		return strlen( $b ) <=> strlen( $a );
	}
);

// ---------------------------------------------------------------------------
// Superfícies visíveis.
// ---------------------------------------------------------------------------

WpStubs::reset();
WpStubs::$catalog      = $catalog;
WpStubs::$capabilities = array( 'manage_options' => true );

Options::update(
	array_merge(
		Options::defaults(),
		array(
			'site_key'   => 'site-key',
			'secret_key' => 'secret-key',
			'forms'      => array(
				'wp_login' => array_merge( Options::form_defaults(), array( 'enabled' => true ) ),
			),
		)
	)
);

Registry::instance()->boot();

/**
 * Captura a saída de um callable.
 *
 * @param callable $callback Callback.
 * @return string
 */
function wrf_gate_capture( callable $callback ): string {
	ob_start();

	try {
		$callback();
	} catch ( \Throwable $e ) {
		ob_end_clean();

		fwrite( STDERR, 'FALHOU: superfície explodiu sob o pseudo-locale: ' . $e->getMessage() . "\n" );
		exit( 1 );
	}

	return (string) ob_get_clean();
}

$surfaces = array();

$surfaces['tela de configurações'] = wrf_gate_capture(
	static function () {
		( new SettingsPage() )->render();
	}
);

$surfaces['notice de admin'] = wrf_gate_capture(
	static function () {
		update_option( 'wp_recaptcha_forms_misconfig_since', time() );
		update_option( 'wp_recaptcha_forms_misconfig_codes', array( 'invalid-input-secret' ) );
		update_option( 'wp_recaptcha_forms_keypair_suspect', 1 );

		Notices::render();
	}
);

$surfaces['notice do kill switch'] = wrf_gate_capture(
	static function () {
		DisabledMode::render_notice();
	}
);

$surfaces['mensagens do front'] = implode(
	' | ',
	array(
		Messages::rejected(),
		Messages::infra(),
		Messages::client_unreachable(),
		Messages::unreachable_notice(),
		Messages::for_failure( FailureClass::REJECTED, 'wp_login' ),
		Messages::for_failure( FailureClass::INFRA, 'wp_login' ),
		Messages::for_failure( FailureClass::CLIENT_UNREACHABLE, 'wp_login' ),
		Messages::for_failure( FailureClass::MISCONFIG, 'wp_login' ),
	)
);

$surfaces['aviso de privacidade'] = PrivacyNotice::render();

$surfaces['aviso de privacidade (telemetria ligada)'] = ( static function () {
	Options::update_telemetry(
		array(
			'enabled'     => true,
			'instance_id' => str_repeat( 'a', 32 ),
		)
	);

	$text = PrivacyNotice::render();

	Options::update_telemetry(
		array(
			'enabled'     => false,
			'instance_id' => '',
		)
	);

	return $text;
} )();

$surfaces['aviso de privacidade (remoteip off)'] = ( static function () {
	Options::update( array_merge( Options::all(), array( 'remoteip' => false ) ) );

	$text = PrivacyNotice::render();

	Options::update( array_merge( Options::all(), array( 'remoteip' => true ) ) );

	return $text;
} )();

/**
 * Só os campos que um humano lê. `status`, `color` e `test` são vocabulário de API do
 * Site Health, não copy — serializar o array inteiro faria o gate acusar as chaves.
 *
 * @param array $test Resultado de um teste do Site Health.
 * @return string
 */
function wrf_gate_site_health_text( array $test ): string {
	return implode(
		' ',
		array(
			(string) ( $test['label'] ?? '' ),
			(string) ( $test['badge']['label'] ?? '' ),
			(string) ( $test['description'] ?? '' ),
			(string) ( $test['actions'] ?? '' ),
		)
	);
}

$surfaces['Site Health'] = wrf_gate_site_health_text( SiteHealth::run() )
	. ' ' . wrf_gate_site_health_text( DisabledMode::site_health_test() );

$labels = array();

foreach ( Registry::instance()->all() as $integration ) {
	$labels[] = $integration->label();
	$labels[] = $integration->unavailable_reason();
}

$surfaces['rótulos das integrações'] = implode( ' | ', $labels );

// ---------------------------------------------------------------------------
// Verificação.
// ---------------------------------------------------------------------------

/**
 * Palavras visíveis que NÃO passaram pelo pipeline de i18n.
 *
 * @param string   $html      HTML ou texto da superfície.
 * @param string[] $allowlist Termos nunca traduzidos.
 * @return string[]
 */
function wrf_gate_leaks( string $html, array $allowlist ): array {
	// 1. Só texto visível: tags e atributos saem de cena.
	$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );

	// 2. Tudo que está corretamente traduzido é removido, marcadores inclusive.
	$rest = preg_replace( '/⟦.*?⟧/us', ' ', $text );

	// 3. Allowlist.
	foreach ( $allowlist as $term ) {
		$rest = preg_replace( '/(?<![\p{L}\p{N}_])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}_])/ui', ' ', (string) $rest );
	}

	// 4. Valores que não são copy do plugin e portanto não são traduzíveis:
	// - chaves e ids que o operador digitou ou que nomeiam formulários;
	// - códigos de erro crus devolvidos pelo Google, exibidos no notice de MISCONFIG
	// para o operador copiar num chamado de suporte. Traduzir `invalid-input-secret`
	// seria ativamente prejudicial — é o literal que ele vai buscar na documentação.
	// Isto é o AC 3 ("valores de opção do operador são excluídos da varredura"), não
	// uma frouxidão do gate: nenhuma frase inteira cabe nestes padrões.
	$rest = preg_replace( '/\b(site-key|secret-key|wp_login|wrf_[a-z_]+|v[23])\b/ui', ' ', (string) $rest );
	$rest = preg_replace( '/\b(invalid|missing|bad|timeout)-[a-z-]+\b/ui', ' ', (string) $rest );

	// 5. Sobrou palavra de verdade? Cada uma é uma string que não passou por i18n.
	preg_match_all( '/[\p{L}][\p{L}\p{M}\'’-]{3,}/u', (string) $rest, $matches );

	return array_values( array_unique( $matches[0] ) );
}

/*
 * Canário: um gate que nunca reprovou pode estar passando por acidente — é a mesma
 * armadilha que `bin/check-boundary.sh` documenta em OB-06. Antes de confiar no verde,
 * o gate prova que reprovaria uma string hardcoded, e prova que NÃO reprova a mesma
 * frase quando ela está corretamente marcada.
 */
$canary_leak = wrf_gate_leaks( '<p>Esta frase nunca passou por gettext.</p>', $allowlist );
$canary_ok   = wrf_gate_leaks( '<p>⟦Esta frase nunca passou por gettext.⟧</p>', $allowlist );

if ( array() === $canary_leak || array() !== $canary_ok ) {
	fwrite( STDERR, "FALHOU: o gate perdeu a capacidade de detectar (canário não disparou).\n" );
	exit( 1 );
}

$leaks = array();

foreach ( $surfaces as $name => $html ) {
	$words = wrf_gate_leaks( $html, $allowlist );

	if ( array() !== $words ) {
		$leaks[ $name ] = $words;
	}
}

if ( array() !== $leaks ) {
	fwrite( STDERR, "\nFALHOU: texto visível fora do pipeline de i18n.\n\n" );

	foreach ( $leaks as $name => $words ) {
		fwrite( STDERR, sprintf( "  %s:\n    %s\n", $name, implode( ', ', $words ) ) );
	}

	fwrite(
		STDERR,
		"\nCada palavra acima foi impressa sem passar por __()/esc_html__() com o domain\n"
		. "wp-recaptcha-forms, ou é um nome próprio que falta na allowlist\n"
		. "(languages/i18n-allowlist.txt — acrescentar exige justificativa no PR).\n"
	);

	exit( 1 );
}

printf(
	"ok: %d superfícies visíveis, todas as strings sob i18n (%d no catálogo, canário disparou)\n",
	count( $surfaces ),
	count( $catalog )
);
