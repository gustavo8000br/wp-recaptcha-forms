<?php
/**
 * Leitor/escritor mínimo de PO, compartilhado pelos scripts de i18n.
 *
 * Não é um parser de gettext completo e não pretende ser: cobre exatamente o subconjunto
 * que os catálogos deste plugin usam (msgctxt, msgid, msgid_plural, msgstr, msgstr[n],
 * strings multi-linha, `#, fuzzy`, `#~` obsoleto). Um parser completo seria uma dependência
 * a mais em CI para ganhar nada.
 *
 * @package WpRecaptchaForms
 */

declare( strict_types = 1 );

/**
 * Lê um `.po`/`.pot`.
 *
 * @param string $path Caminho.
 * @return array<int, array{msgid:string, plural:?string, context:?string, msgstr:string[], fuzzy:bool, refs:string[]}>
 */
function wrf_po_parse( string $path ): array {
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Catálogo não encontrado: $path\n" );
		exit( 1 );
	}

	$entries = array();
	$current = wrf_po_blank();
	$field   = null;
	$index   = 0;

	foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $line ) {
		$line = rtrim( $line, "\r" );

		if ( '' === trim( $line ) ) {
			if ( null !== $current['msgid'] ) {
				$entries[] = wrf_po_finalize( $current );
			}

			$current = wrf_po_blank();
			$field   = null;
			continue;
		}

		// Entrada obsoleta: gettext a ignora, e a contagem de progresso também.
		if ( 0 === strpos( $line, '#~' ) ) {
			$current = wrf_po_blank();
			$field   = null;
			continue;
		}

		if ( 0 === strpos( $line, '#,' ) ) {
			$current['fuzzy'] = false !== strpos( $line, 'fuzzy' );
			continue;
		}

		if ( 0 === strpos( $line, '#:' ) ) {
			$current['refs'][] = trim( substr( $line, 2 ) );
			continue;
		}

		if ( '#' === $line[0] ) {
			continue;
		}

		if ( preg_match( '/^msgctxt\s+(.*)$/', $line, $m ) ) {
			$current['context'] = wrf_po_unescape( $m[1] );
			$field              = 'context';
			continue;
		}

		if ( preg_match( '/^msgid\s+(.*)$/', $line, $m ) ) {
			$current['msgid'] = wrf_po_unescape( $m[1] );
			$field            = 'msgid';
			continue;
		}

		if ( preg_match( '/^msgid_plural\s+(.*)$/', $line, $m ) ) {
			$current['plural'] = wrf_po_unescape( $m[1] );
			$field             = 'plural';
			continue;
		}

		if ( preg_match( '/^msgstr\[(\d+)\]\s+(.*)$/', $line, $m ) ) {
			$index                      = (int) $m[1];
			$current['msgstr'][ $index ] = wrf_po_unescape( $m[2] );
			$field                      = 'msgstr';
			continue;
		}

		if ( preg_match( '/^msgstr\s+(.*)$/', $line, $m ) ) {
			$index               = 0;
			$current['msgstr'][0] = wrf_po_unescape( $m[1] );
			$field               = 'msgstr';
			continue;
		}

		// Continuação multi-linha.
		if ( '"' === $line[0] && null !== $field ) {
			$chunk = wrf_po_unescape( $line );

			if ( 'msgstr' === $field ) {
				$current['msgstr'][ $index ] .= $chunk;
			} elseif ( 'plural' === $field ) {
				$current['plural'] .= $chunk;
			} elseif ( 'context' === $field ) {
				$current['context'] .= $chunk;
			} else {
				$current['msgid'] .= $chunk;
			}
		}
	}

	if ( null !== $current['msgid'] ) {
		$entries[] = wrf_po_finalize( $current );
	}

	return $entries;
}

/**
 * Entrada vazia.
 *
 * @return array
 */
function wrf_po_blank(): array {
	return array(
		'msgid'   => null,
		'plural'  => null,
		'context' => null,
		'msgstr'  => array(),
		'fuzzy'   => false,
		'refs'    => array(),
	);
}

/**
 * Normaliza uma entrada lida.
 *
 * @param array $entry Entrada crua.
 * @return array
 */
function wrf_po_finalize( array $entry ): array {
	ksort( $entry['msgstr'] );

	return array(
		'msgid'   => (string) $entry['msgid'],
		'plural'  => $entry['plural'],
		'context' => $entry['context'],
		'msgstr'  => array_values( $entry['msgstr'] ),
		'fuzzy'   => (bool) $entry['fuzzy'],
		'refs'    => $entry['refs'],
	);
}

/**
 * Remove as aspas e as escapes de um valor PO.
 *
 * @param string $raw Valor cru, entre aspas.
 * @return string
 */
function wrf_po_unescape( string $raw ): string {
	$raw = trim( $raw );

	if ( '"' === substr( $raw, 0, 1 ) ) {
		$raw = substr( $raw, 1 );
	}

	if ( '"' === substr( $raw, -1 ) ) {
		$raw = substr( $raw, 0, -1 );
	}

	return str_replace(
		array( '\\n', '\\t', '\\r', '\\"', '\\\\' ),
		array( "\n", "\t", "\r", '"', '\\' ),
		$raw
	);
}

/**
 * Escapa um valor para o formato PO.
 *
 * @param string $text Texto.
 * @return string
 */
function wrf_po_escape( string $text ): string {
	return str_replace(
		array( '\\', '"', "\n", "\t", "\r" ),
		array( '\\\\', '\\"', '\\n', '\\t', '\\r' ),
		$text
	);
}

/**
 * Uma entrada está traduzida?
 *
 * Plurais só contam com TODAS as formas preenchidas (Story 1.21 AC 7): uma tradução com
 * o plural em branco é uma tradução quebrada, não meia tradução.
 *
 * @param array $entry Entrada.
 * @return bool
 */
function wrf_po_is_translated( array $entry ): bool {
	if ( $entry['fuzzy'] ) {
		return false;
	}

	$expected = null === $entry['plural'] ? 1 : 2;

	if ( count( $entry['msgstr'] ) < $expected ) {
		return false;
	}

	for ( $i = 0; $i < $expected; $i++ ) {
		if ( '' === trim( $entry['msgstr'][ $i ] ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Chave única de uma entrada (contexto + msgid).
 *
 * @param array $entry Entrada.
 * @return string
 */
function wrf_po_key( array $entry ): string {
	return ( null === $entry['context'] ? '' : $entry['context'] . "\4" ) . $entry['msgid'];
}
