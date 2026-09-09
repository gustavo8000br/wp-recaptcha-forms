/**
 * WP reCAPTCHA Forms — checkout em Blocks (WooCommerce).
 *
 * O clássico intercepta o submit; o Blocks não tem submit para interceptar. O encaixe
 * equivalente é `onCheckoutValidation`, que aceita um observador que devolve uma Promise:
 * o Woo espera por ela antes de mandar o pedido para a Store API.
 *
 * O que este arquivo NÃO faz, de propósito: decidir. Ele obtém o token (ou o estado que
 * explica a ausência dele), grava os dois em `extensionData` e devolve `true`. O bloqueio
 * é do servidor, em `Gate::assess()`, exatamente como no checkout clássico — senão os dois
 * caminhos divergiriam, que é o risco central desta área (arquitetura v1 §8.1).
 *
 * Sem build: as dependências (`wc-blocks-checkout-events`, `wp-data`) já expõem tudo que
 * é necessário como global. O bundle de `@wordpress/scripts` existiria para importar
 * módulos ES — e este arquivo não importa nenhum.
 */
( function () {
	'use strict';

	var settings = window.wrf || {};
	var blockData = ( window.wcSettings && window.wcSettings[ 'wp-recaptcha-forms_data' ] ) || {};
	var NAMESPACE = 'wp-recaptcha-forms';

	var events = window.wc && window.wc.blocksCheckoutEvents;
	var data = window.wp && window.wp.data;
	var api = window.wpRecaptchaForms;

	if ( ! events || ! events.checkoutEvents || ! data || ! api || ! api.obtainToken ) {
		return;
	}

	var actionValue = blockData.action || 'wrf_woocommerce_checkout';

	if ( blockData.siteKey && ! settings.siteKey ) {
		settings.siteKey = blockData.siteKey;
	}

	events.checkoutEvents.onCheckoutValidation( function () {
		return api.obtainToken( actionValue ).then( function ( result ) {
			/*
			 * `setExtensionData( namespace, data )` faz merge por default, então os dois
			 * campos vão juntos numa chamada. O `cstate` é obrigatório aqui: sem ele o
			 * checkout em Blocks bloquearia visitantes com bloqueador de anúncios enquanto
			 * o clássico os deixa passar (v1.1 §8.1).
			 */
			data.dispatch( 'wc/store/checkout' ).setExtensionData( NAMESPACE, {
				token: result.token,
				cstate: result.cstate
			} );

			// Sempre válido do ponto de vista do cliente. Quem recusa é a Store API.
			return true;
		} );
	} );
} )();
