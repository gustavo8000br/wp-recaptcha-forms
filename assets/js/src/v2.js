/**
 * WP reCAPTCHA Forms — widget do reCAPTCHA v2 checkbox.
 *
 * O widget nasce vazio no HTML e é renderizado pelo script do provedor, então o markup
 * continua cacheável pelo mesmo motivo do v3 (arquitetura v1 §6.5).
 *
 * JS puro, sem build: `assets/js/dist/v2.js` é uma cópia idêntica.
 */
( function () {
	'use strict';

	var settings = window.wrf || {};

	window.wpRecaptchaForms = window.wpRecaptchaForms || {};
	window.wpRecaptchaForms.consentGranted = true;

	function renderWidgets() {
		var nodes = document.querySelectorAll( '.wrf-v2:not([data-wrf-rendered])' );

		Array.prototype.forEach.call( nodes, function ( node ) {
			node.setAttribute( 'data-wrf-rendered', '1' );

			window.grecaptcha.render( node, {
				sitekey: node.getAttribute( 'data-sitekey' ) || settings.siteKey,
				/*
				 * A resposta do v2 expira em ~2 minutos. Sem limpar o campo, o formulário
				 * enviaria uma resposta expirada, que o servidor recusa — e o visitante vê
				 * uma falha sem explicação. Limpando, o próprio script reexibe o desafio.
				 */
				'expired-callback': function () {
					var form = node.closest( 'form' );

					if ( ! form ) {
						return;
					}

					var response = form.querySelector( 'textarea[name^="g-recaptcha"]' );

					if ( response ) {
						response.value = '';
					}
				}
			} );
		} );
	}

	// Chamado pelo `onload` da URL do script (render=explicit).
	window.wrfV2Init = function () {
		renderWidgets();
	};

	if ( window.grecaptcha && window.grecaptcha.render ) {
		renderWidgets();
	}
} )();
