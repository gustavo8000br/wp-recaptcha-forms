<?php
/**
 * Compila `.po` em `.mo` (Story 1.21 AC 1 e AC 9).
 *
 * Sem `msgfmt`: o CI teria de instalar o pacote `gettext` só para isto, e um passo de
 * `apt-get` é mais frágil que quarenta linhas de `pack()`. O formato MO é público e
 * estável desde 1995.
 *
 * Uso:
 *   php bin/i18n-build.php               # compila todo languages/*.po
 *   php bin/i18n-build.php arquivo.po    # compila um só
 *
 * @package WpRecaptchaForms
 */

declare( strict_types = 1 );

require_once __DIR__ . '/i18n-po.php';

/**
 * Escreve um `.mo` a partir das entradas de um `.po`.
 *
 * @param array  $entries Entradas.
 * @param string $output  Caminho de saída.
 * @return int Quantas entradas foram gravadas.
 */
function wrf_mo_write( array $entries, string $output ): int {
	$pairs = array();

	foreach ( $entries as $entry ) {
		// Cabeçalho do catálogo: msgid vazio, msgstr com os metadados.
		if ( '' === $entry['msgid'] ) {
			if ( isset( $entry['msgstr'][0] ) && '' !== $entry['msgstr'][0] ) {
				$pairs[''] = $entry['msgstr'][0];
			}

			continue;
		}

		if ( ! wrf_po_is_translated( $entry ) ) {
			continue;
		}

		$key = $entry['msgid'];

		if ( null !== $entry['context'] ) {
			$key = $entry['context'] . "\4" . $key;
		}

		if ( null !== $entry['plural'] ) {
			$key  .= "\0" . $entry['plural'];
			$value = implode( "\0", $entry['msgstr'] );
		} else {
			$value = $entry['msgstr'][0];
		}

		$pairs[ $key ] = $value;
	}

	ksort( $pairs );

	$count       = count( $pairs );
	$keys        = array_keys( $pairs );
	$values      = array_values( $pairs );
	$header_size = 28;
	$table_size  = $count * 8;

	// Offsets: cabeçalho, tabela de originais, tabela de traduções, dados.
	$originals_offset    = $header_size;
	$translations_offset = $originals_offset + $table_size;
	$data_offset         = $translations_offset + $table_size;

	$originals    = '';
	$translations = '';
	$data         = '';
	$cursor       = $data_offset;

	foreach ( $keys as $key ) {
		$originals .= pack( 'VV', strlen( $key ), $cursor );
		$data      .= $key . "\0";
		$cursor    += strlen( $key ) + 1;
	}

	foreach ( $values as $value ) {
		$translations .= pack( 'VV', strlen( $value ), $cursor );
		$data         .= $value . "\0";
		$cursor       += strlen( $value ) + 1;
	}

	$mo = pack(
		'VVVVVVV',
		0x950412de, // Magic little-endian.
		0,          // Revisão.
		$count,
		$originals_offset,
		$translations_offset,
		0,          // Tamanho da hash table: zero, o gettext do PHP não precisa dela.
		0           // Offset da hash table.
	);

	file_put_contents( $output, $mo . $originals . $translations . $data );

	return $count;
}

$wrf_root  = dirname( __DIR__ );
$wrf_files = isset( $argv[1] ) ? array( $argv[1] ) : glob( $wrf_root . '/languages/*.po' );

if ( array() === $wrf_files || false === $wrf_files ) {
	echo "Nenhum .po para compilar.\n";
	exit( 0 );
}

foreach ( $wrf_files as $wrf_po ) {
	$wrf_mo    = substr( $wrf_po, 0, -3 ) . '.mo';
	$wrf_count = wrf_mo_write( wrf_po_parse( $wrf_po ), $wrf_mo );

	printf( "%s -> %s (%d entradas)\n", basename( $wrf_po ), basename( $wrf_mo ), $wrf_count );
}
