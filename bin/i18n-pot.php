<?php
/**
 * Gera `languages/wp-recaptcha-forms.pot` a partir do código-fonte.
 *
 * Por que não `wp i18n make-pot`: o gate da Story 1.20 roda em CI, e o CI só tem PHP e
 * Composer. Depender do WP-CLI ali significaria instalar um WordPress inteiro para
 * extrair strings de um plugin que não carrega WordPress nos testes. O extrator abaixo
 * usa `token_get_all()` — o mesmo mecanismo que o WP-CLI usa por baixo — e cabe num
 * arquivo.
 *
 * Uso:
 *   php bin/i18n-pot.php [caminho/de/saida.pot]
 *
 * @package WpRecaptchaForms
 */

declare( strict_types = 1 );

const WRF_TEXT_DOMAIN = 'wp-recaptcha-forms';

/**
 * Assinaturas do gettext do WordPress.
 *
 * Cada entrada diz em que posição (base 0) estão o singular, o plural, o contexto e o
 * text domain. `null` = a função não tem aquele argumento.
 */
const WRF_GETTEXT_FUNCTIONS = array(
	'__'         => array(
		'single'  => 0,
		'plural'  => null,
		'context' => null,
		'domain'  => 1,
	),
	'_e'         => array(
		'single'  => 0,
		'plural'  => null,
		'context' => null,
		'domain'  => 1,
	),
	'esc_html__' => array(
		'single'  => 0,
		'plural'  => null,
		'context' => null,
		'domain'  => 1,
	),
	'esc_html_e' => array(
		'single'  => 0,
		'plural'  => null,
		'context' => null,
		'domain'  => 1,
	),
	'esc_attr__' => array(
		'single'  => 0,
		'plural'  => null,
		'context' => null,
		'domain'  => 1,
	),
	'esc_attr_e' => array(
		'single'  => 0,
		'plural'  => null,
		'context' => null,
		'domain'  => 1,
	),
	'_x'         => array(
		'single'  => 0,
		'plural'  => null,
		'context' => 1,
		'domain'  => 2,
	),
	'_ex'        => array(
		'single'  => 0,
		'plural'  => null,
		'context' => 1,
		'domain'  => 2,
	),
	'esc_html_x' => array(
		'single'  => 0,
		'plural'  => null,
		'context' => 1,
		'domain'  => 2,
	),
	'esc_attr_x' => array(
		'single'  => 0,
		'plural'  => null,
		'context' => 1,
		'domain'  => 2,
	),
	'_n'         => array(
		'single'  => 0,
		'plural'  => 1,
		'context' => null,
		'domain'  => 3,
	),
	'_nx'        => array(
		'single'  => 0,
		'plural'  => 1,
		'context' => 3,
		'domain'  => 4,
	),
	'_n_noop'    => array(
		'single'  => 0,
		'plural'  => 1,
		'context' => null,
		'domain'  => 2,
	),
	'_nx_noop'   => array(
		'single'  => 0,
		'plural'  => 1,
		'context' => 2,
		'domain'  => 3,
	),
);

/**
 * Diretórios varridos, relativos à raiz do projeto.
 *
 * `tests/` e `bin/` ficam de fora: nada ali é exibido a um usuário final.
 */
const WRF_SCAN = array( 'src', 'wp-recaptcha-forms.php', 'uninstall.php' );

/**
 * Lista os arquivos PHP a varrer.
 *
 * @param string $root Raiz do projeto.
 * @return string[]
 */
function wrf_pot_files( string $root ): array {
	$files = array();

	foreach ( WRF_SCAN as $target ) {
		$path = $root . '/' . $target;

		if ( is_file( $path ) ) {
			$files[] = $path;
			continue;
		}

		if ( ! is_dir( $path ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}
	}

	sort( $files );

	return $files;
}

/**
 * Extrai as entradas de um arquivo.
 *
 * @param string $file Caminho absoluto.
 * @param string $root Raiz do projeto (para a referência relativa).
 * @return array<string, array{msgid:string, plural:?string, context:?string, refs:string[], comments:string[]}>
 */
function wrf_pot_extract( string $file, string $root ): array {
	$entries = array();
	$tokens  = token_get_all( (string) file_get_contents( $file ) );
	$count   = count( $tokens );
	$rel     = ltrim( str_replace( $root, '', $file ), '/' );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}

		$name = $token[1];

		if ( ! isset( WRF_GETTEXT_FUNCTIONS[ $name ] ) ) {
			continue;
		}

		// `$obj->__(...)` e `Classe::__(...)` não são o gettext do WordPress.
		$before = wrf_pot_prev_significant( $tokens, $i );

		if ( is_array( $before ) && in_array( $before[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}

		$args = wrf_pot_arguments( $tokens, $i, $count );

		if ( null === $args ) {
			continue;
		}

		$spec   = WRF_GETTEXT_FUNCTIONS[ $name ];
		$domain = $args[ $spec['domain'] ] ?? null;

		// Domain ausente ou de outro plugin: não é string nossa.
		if ( WRF_TEXT_DOMAIN !== $domain ) {
			if ( null === $domain ) {
				fwrite( STDERR, sprintf( "AVISO: %s:%d — %s() sem text domain.\n", $rel, $token[2], $name ) );
			}

			continue;
		}

		$msgid = $args[ $spec['single'] ] ?? null;

		if ( null === $msgid ) {
			fwrite( STDERR, sprintf( "AVISO: %s:%d — %s() com argumento não literal; não extraível.\n", $rel, $token[2], $name ) );
			continue;
		}

		$context = null === $spec['context'] ? null : ( $args[ $spec['context'] ] ?? null );
		$plural  = null === $spec['plural'] ? null : ( $args[ $spec['plural'] ] ?? null );
		$key     = ( null === $context ? '' : $context . "\4" ) . $msgid;

		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'msgid'   => $msgid,
				'plural'  => $plural,
				'context' => $context,
				'refs'    => array(),
			);
		}

		$entries[ $key ]['refs'][] = $rel . ':' . $token[2];
	}

	return $entries;
}

/**
 * Token significativo anterior (pula espaço e comentário).
 *
 * @param array $tokens Tokens.
 * @param int   $index  Índice corrente.
 * @return array|string|null
 */
function wrf_pot_prev_significant( array $tokens, int $index ) {
	for ( $i = $index - 1; $i >= 0; $i-- ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		return $tokens[ $i ];
	}

	return null;
}

/**
 * Lê os argumentos literais de uma chamada.
 *
 * Só resolve strings literais simples: concatenação, variável ou constante devolvem
 * `null` naquela posição — o que é correto, porque gettext também não as resolveria.
 *
 * @param array $tokens Tokens.
 * @param int   $index  Índice do nome da função.
 * @param int   $count  Total de tokens.
 * @return array<int, ?string>|null
 */
function wrf_pot_arguments( array $tokens, int $index, int $count ): ?array {
	$i = $index + 1;

	while ( $i < $count && is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
		++$i;
	}

	if ( $i >= $count || '(' !== $tokens[ $i ] ) {
		return null;
	}

	$depth    = 0;
	$args     = array();
	$position = 0;
	$literal  = '';
	$simple   = true;

	for ( ; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( '(' === $token ) {
			++$depth;

			if ( 1 === $depth ) {
				continue;
			}
		}

		if ( ')' === $token ) {
			--$depth;

			if ( 0 === $depth ) {
				$args[ $position ] = $simple ? $literal : null;

				return $args;
			}
		}

		if ( 1 === $depth && ',' === $token ) {
			$args[ $position ] = $simple ? $literal : null;
			++$position;
			$literal = '';
			$simple  = true;
			continue;
		}

		if ( $depth < 1 ) {
			continue;
		}

		if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
			continue;
		}

		if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			$literal .= wrf_pot_unquote( $token[1] );
			continue;
		}

		// Qualquer outra coisa (variável, concatenação, chamada aninhada) torna o
		// argumento não literal.
		$simple = false;
	}

	return null;
}

/**
 * Converte um literal PHP em texto.
 *
 * @param string $raw Literal com aspas.
 * @return string
 */
function wrf_pot_unquote( string $raw ): string {
	$quote = $raw[0];
	$body  = substr( $raw, 1, -1 );

	if ( "'" === $quote ) {
		return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $body );
	}

	return stripcslashes( $body );
}

/**
 * Escapa um texto para o formato PO.
 *
 * @param string $text Texto.
 * @return string
 */
function wrf_pot_escape( string $text ): string {
	return str_replace(
		array( '\\', '"', "\n", "\t", "\r" ),
		array( '\\\\', '\\"', '\\n', '\\t', '\\r' ),
		$text
	);
}

// ---------------------------------------------------------------------------
// Execução.
// ---------------------------------------------------------------------------

$wrf_root   = dirname( __DIR__ );
$wrf_output = $argv[1] ?? $wrf_root . '/languages/wp-recaptcha-forms.pot';
$wrf_all    = array();

foreach ( wrf_pot_files( $wrf_root ) as $wrf_file ) {
	foreach ( wrf_pot_extract( $wrf_file, $wrf_root ) as $wrf_key => $wrf_entry ) {
		if ( isset( $wrf_all[ $wrf_key ] ) ) {
			$wrf_all[ $wrf_key ]['refs'] = array_merge( $wrf_all[ $wrf_key ]['refs'], $wrf_entry['refs'] );
			continue;
		}

		$wrf_all[ $wrf_key ] = $wrf_entry;
	}
}

ksort( $wrf_all );

$wrf_lines = array(
	'# Copyright (C) ' . gmdate( 'Y' ) . ' Gustavo Mathias Rocha',
	'# This file is distributed under the GPL-2.0-or-later license.',
	'msgid ""',
	'msgstr ""',
	'"Project-Id-Version: WP reCAPTCHA Forms\\n"',
	'"Report-Msgid-Bugs-To: https://github.com/gustavo8000br/wp-recaptcha-forms/issues\\n"',
	'"MIME-Version: 1.0\\n"',
	'"Content-Type: text/plain; charset=UTF-8\\n"',
	'"Content-Transfer-Encoding: 8bit\\n"',
	'"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
	'"X-Generator: bin/i18n-pot.php\\n"',
	'"X-Domain: ' . WRF_TEXT_DOMAIN . '\\n"',
	'',
);

foreach ( $wrf_all as $wrf_entry ) {
	foreach ( $wrf_entry['refs'] as $wrf_ref ) {
		$wrf_lines[] = '#: ' . $wrf_ref;
	}

	if ( null !== $wrf_entry['context'] ) {
		$wrf_lines[] = 'msgctxt "' . wrf_pot_escape( $wrf_entry['context'] ) . '"';
	}

	$wrf_lines[] = 'msgid "' . wrf_pot_escape( $wrf_entry['msgid'] ) . '"';

	if ( null !== $wrf_entry['plural'] ) {
		$wrf_lines[] = 'msgid_plural "' . wrf_pot_escape( $wrf_entry['plural'] ) . '"';
		$wrf_lines[] = 'msgstr[0] ""';
		$wrf_lines[] = 'msgstr[1] ""';
	} else {
		$wrf_lines[] = 'msgstr ""';
	}

	$wrf_lines[] = '';
}

if ( ! is_dir( dirname( $wrf_output ) ) ) {
	mkdir( dirname( $wrf_output ), 0755, true );
}

file_put_contents( $wrf_output, implode( "\n", $wrf_lines ) );

printf( "%d strings extraídas para %s\n", count( $wrf_all ), $wrf_output );
