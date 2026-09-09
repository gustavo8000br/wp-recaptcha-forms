<?php
/**
 * Teste de integração dos catálogos (Story 1.21 AC 2 e AC 3).
 *
 * Precisa de um WordPress de verdade: o que está sendo testado é o carregamento de
 * textdomain do core, `switch_to_locale()` e a cadeia de filtros que o WP usa para
 * resolver o arquivo de tradução. Nada disso existe no ambiente unitário, e simular
 * provaria apenas que a simulação funciona.
 *
 *   docker compose exec -T wpcli wp eval-file \
 *     wp-content/plugins/wp-recaptcha-forms/tests/Integration/i18n-locale-test.php \
 *     --path=/var/www/html --allow-root
 *
 * @package WpRecaptchaForms
 */

/*
 * `$GLOBALS` e não `global $falhas`: o `wp eval-file` executa este arquivo DENTRO de uma
 * função, então `$falhas` no corpo do script é uma variável local, e `global $falhas`
 * dentro da função de asserção aponta para outra coisa. O efeito é um teste que imprime
 * FAIL e termina dizendo que passou — que foi exatamente o que aconteceu na primeira
 * execução desta rodada.
 */
$GLOBALS['wrf_falhas'] = 0;

/**
 * Afirma.
 *
 * @param bool   $condicao Condição.
 * @param string $rotulo   Descrição.
 * @param string $detalhe  Detalhe em caso de falha.
 * @return void
 */
function wrf_afirma( $condicao, $rotulo, $detalhe = '' ) {
	if ( $condicao ) {
		echo "PASS $rotulo\n";

		return;
	}

	++$GLOBALS['wrf_falhas'];

	echo "FAIL $rotulo" . ( '' === $detalhe ? '' : " — $detalhe" ) . "\n";
}

/**
 * Carrega o catálogo do plugin num locale específico.
 *
 * Três armadilhas, todas encontradas na depuração desta story, todas silenciosas:
 *
 * 1. **`switch_to_locale()` atrapalha em vez de ajudar.** `determine_locale()` retorna
 *    cedo, SEM aplicar o filtro `determine_locale`, sempre que há uma troca ativa. Com a
 *    troca ligada, todo locale resolvia para o `get_locale()` do site e o catálogo
 *    espanhol nunca era procurado.
 * 2. **`unload_textdomain( $domain )` sem o segundo argumento** marca o domain como não
 *    recarregável: o `load_plugin_textdomain()` seguinte devolve `true` e não carrega nada.
 * 3. **O filtro precisa continuar ativo durante o `__()`.** No WP 6.5+ quem serve a busca
 *    em tempo de execução é o `WP_Translation_Controller`, e ele também consulta
 *    `determine_locale()`. Removendo o filtro logo após o load, o catálogo certo fica
 *    carregado e `__()` responde pelo balde do locale do site.
 *
 * Por isso o locale forçado é um estado global que permanece: é o que reproduz um site de
 * verdade servindo uma página em espanhol.
 *
 * @param string $locale Locale.
 * @return void
 */
function wrf_carrega_locale( $locale ) {
	$GLOBALS['wrf_locale_forcado'] = $locale;

	if ( class_exists( 'WP_Translation_Controller' ) ) {
		WP_Translation_Controller::get_instance()->set_locale( $locale );
	}

	unload_textdomain( 'wp-recaptcha-forms', true );
	load_plugin_textdomain( 'wp-recaptcha-forms', false, 'wp-recaptcha-forms/languages' );
}

add_filter(
	'determine_locale',
	static function ( $locale ) {
		return $GLOBALS['wrf_locale_forcado'] ?? $locale;
	},
	99
);

echo 'INFO WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . "\n";

// O plugin registra os filtros em `load_textdomain`, no hook `init`, que o WP-CLI já
// disparou ao carregar o ambiente. Reforçamos aqui para o teste não depender da ordem.
\WpRecaptchaForms\I18n::boot();

/*
 * Duas strings por locale, e nenhuma delas é acidental:
 *
 * - "Segurança" é o caso decisivo. "Comentários" → "Comentarios" difere por um acento, e
 *   uma asserção de substring passaria com o catálogo NÃO carregado. "Segurança" →
 *   "Seguridad" → "Security" não tem essa ambiguidade.
 * - `pt_BR` é catálogo identidade: `msgid === msgstr`. Ele passa mesmo sem catálogo
 *   nenhum, então sozinho não prova nada — está aqui só para provar que o fallback não
 *   estragou o idioma-fonte.
 */
$locales = array(
	'es_ES' => array( 'Comentarios', 'Seguridad' ),
	'es_MX' => array( 'Comentarios', 'Seguridad' ),
	'es_AR' => array( 'Comentarios', 'Seguridad' ),
	'en_US' => array( 'Comments', 'Security' ),
	'pt_BR' => array( 'Comentários', 'Segurança' ),
);

foreach ( $locales as $locale => $esperados ) {
	wrf_carrega_locale( $locale );

	foreach ( array( 'Comentários', 'Segurança' ) as $indice => $origem ) {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- o teste itera sobre msgids de propósito; é a natureza de um teste de catálogo.
		$obtido   = __( $origem, 'wp-recaptcha-forms' );
		$esperado = $esperados[ $indice ];

		wrf_afirma(
			$esperado === $obtido,
			sprintf( '%s traduz "%s" como "%s"', $locale, $origem, $esperado ),
			sprintf( 'obtido: "%s"', $obtido )
		);
	}
}

// O fallback tem de valer para uma segunda string, senão o teste acima poderia estar
// passando por coincidência de acento.
wrf_carrega_locale( 'es_MX' );

wrf_afirma(
	'Acceso' === __( 'Login', 'wp-recaptcha-forms' ),
	'es_MX traduz "Login" pelo catálogo es_ES',
	sprintf( 'obtido: "%s"', __( 'Login', 'wp-recaptcha-forms' ) )
);

wrf_afirma(
	'Privacidad' === __( 'Privacidade', 'wp-recaptcha-forms' ),
	'es_MX traduz "Privacidade" como "Privacidad"',
	sprintf( 'obtido: "%s"', __( 'Privacidade', 'wp-recaptcha-forms' ) )
);

unload_textdomain( 'wp-recaptcha-forms', true );

$wrf_falhas = (int) $GLOBALS['wrf_falhas'];

echo $wrf_falhas > 0
	? "\nFALHOU: $wrf_falhas asserção(ões).\n"
	: "\nTodas as asserções passaram.\n";

exit( $wrf_falhas > 0 ? 1 : 0 );
