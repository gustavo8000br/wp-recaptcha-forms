<?php
/**
 * Deriva o pseudo-locale `en_CA` do `.pot` (Story 1.20 AC 4).
 *
 * Cada `msgid` vira `⟦msgid⟧`. Os delimitadores são U+27E6/U+27E7 porque não aparecem em
 * nenhuma copy de plugin, não são produzidos por teclado nenhum por acidente, e sobrevivem
 * a `esc_html()` sem virar entidade — três propriedades que colchete comum não tem.
 *
 * Placeholders de `sprintf` são preservados dentro dos marcadores: o texto continua
 * formatável, então o gate não quebra o render (AC 3, último item).
 *
 * Uso:
 *   php bin/i18n-pseudo.php [entrada.pot] [saida.po]
 *
 * @package WpRecaptchaForms
 */

declare( strict_types = 1 );

require_once __DIR__ . '/i18n-po.php';

$wrf_root  = dirname( __DIR__ );
$wrf_in    = $argv[1] ?? $wrf_root . '/languages/wp-recaptcha-forms.pot';
$wrf_out   = $argv[2] ?? $wrf_root . '/languages/wp-recaptcha-forms-en_CA.po';
$wrf_pot   = wrf_po_parse( $wrf_in );
$wrf_lines = array(
	'# Pseudo-locale gerado por bin/i18n-pseudo.php — NÃO EDITAR, NÃO VERSIONAR.',
	'msgid ""',
	'msgstr ""',
	'"MIME-Version: 1.0\\n"',
	'"Content-Type: text/plain; charset=UTF-8\\n"',
	'"Content-Transfer-Encoding: 8bit\\n"',
	'"Language: en_CA\\n"',
	'"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
	'"X-Domain: wp-recaptcha-forms\\n"',
	'',
);

$wrf_count = 0;

foreach ( $wrf_pot as $wrf_entry ) {
	if ( '' === $wrf_entry['msgid'] ) {
		continue;
	}

	if ( null !== $wrf_entry['context'] ) {
		$wrf_lines[] = 'msgctxt "' . wrf_po_escape( $wrf_entry['context'] ) . '"';
	}

	$wrf_lines[] = 'msgid "' . wrf_po_escape( $wrf_entry['msgid'] ) . '"';

	if ( null !== $wrf_entry['plural'] ) {
		$wrf_lines[] = 'msgid_plural "' . wrf_po_escape( $wrf_entry['plural'] ) . '"';
		$wrf_lines[] = 'msgstr[0] "' . wrf_po_escape( '⟦' . $wrf_entry['msgid'] . '⟧' ) . '"';
		$wrf_lines[] = 'msgstr[1] "' . wrf_po_escape( '⟦' . $wrf_entry['plural'] . '⟧' ) . '"';
	} else {
		$wrf_lines[] = 'msgstr "' . wrf_po_escape( '⟦' . $wrf_entry['msgid'] . '⟧' ) . '"';
	}

	$wrf_lines[] = '';
	++$wrf_count;
}

file_put_contents( $wrf_out, implode( "\n", $wrf_lines ) );

printf( "%d strings pseudo-traduzidas em %s\n", $wrf_count, $wrf_out );
