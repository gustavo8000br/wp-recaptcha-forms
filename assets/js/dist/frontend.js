/**
 * WP reCAPTCHA Forms — intercepto de submit (reCAPTCHA v3).
 *
 * O token nasce no submit, nunca no HTML (arquitetura v1 §6.2, v1.1 §1.3).
 *
 * Este arquivo é JS puro, sem build: `assets/js/dist/frontend.js` é uma cópia idêntica.
 */
( function () {
	'use strict';

	var CSTATE = {
		NONE: '',
		NO_SCRIPT: 'no-script',
		CONSENT: 'consent-pending',
		EXEC: 'exec-error'
	};

	var settings = window.wrf || {};
	var LOAD_TIMEOUT_MS = settings.loadTimeout || 4000;

	var api = window.wpRecaptchaForms || {};
	window.wpRecaptchaForms = api;
	// `wp_localize_script` entrega tudo como string: o PHP manda '1' ou '0'.
	api.consentGranted = '0' !== String( settings.consentGranted );

	function field( form, name ) {
		return form.querySelector( '[name="' + name + '"]' );
	}

	function isProtected( form ) {
		return !! ( form && form.querySelector && field( form, 'wrf_token' ) );
	}

	function consentPending() {
		return api.consentGranted === false;
	}

	/**
	 * "grecaptcha não carregou" não é um evento: é a ausência de um. Sem prazo, o
	 * bloqueador de anúncios produziria um formulário que nunca submete — modo de falha
	 * pior que qualquer um dos dois lados desta decisão.
	 *
	 * 4s é menor que o timeout de rede do servidor, de propósito: o cliente desiste
	 * antes de o servidor desistir.
	 */
	function waitForGrecaptcha( timeoutMs ) {
		return new Promise( function ( resolve, reject ) {
			var started = Date.now();

			( function poll() {
				if ( window.grecaptcha && window.grecaptcha.execute ) {
					resolve();
					return;
				}

				if ( Date.now() - started >= timeoutMs ) {
					reject();
					return;
				}

				window.setTimeout( poll, 100 );
			} )();
		} );
	}

	/**
	 * Obtém um token, ou o estado do cliente que explica por que não obteve.
	 *
	 * Ponto único de aquisição: o intercepto de submit e o checkout em Blocks passam os
	 * dois por aqui. Duplicar a lógica faria os dois caminhos divergirem — que é
	 * exatamente o modo de falha que o desenho do `cstate` existe para impedir.
	 *
	 * NUNCA rejeita. O resultado é sempre `{ token, cstate }`, e quem decide o veredito é
	 * o servidor.
	 */
	function obtainToken( actionValue ) {
		return waitForGrecaptcha( LOAD_TIMEOUT_MS )
			.then( function () {
				return window.grecaptcha
					.execute( settings.siteKey, { action: actionValue } )
					.then( function ( token ) {
						return { token: token, cstate: CSTATE.NONE };
					} )
					.catch( function () {
						return { token: '', cstate: CSTATE.EXEC };
					} );
			} )
			.catch( function () {
				return {
					token: '',
					cstate: consentPending() ? CSTATE.CONSENT : CSTATE.NO_SCRIPT
				};
			} );
	}

	api.obtainToken = obtainToken;

	function showNotice( form ) {
		var text = ( settings.i18n && settings.i18n.unreachableNotice ) || '';

		if ( ! text || form.querySelector( '.wrf-notice' ) ) {
			return;
		}

		var notice = document.createElement( 'div' );
		notice.className = 'wrf-notice';
		notice.setAttribute( 'role', 'status' );
		notice.setAttribute( 'aria-live', 'polite' );
		notice.textContent = text;

		var anchor = form.querySelector( '.wrf-field' ) || form;
		anchor.parentNode.insertBefore( notice, anchor );
	}

	function markUnreachable( form, state ) {
		var cstate = field( form, 'wrf_cstate' );
		var token = field( form, 'wrf_token' );

		if ( cstate ) {
			cstate.value = state;
		}

		if ( token ) {
			token.value = '';
		}

		showNotice( form );
	}

	function resubmit( form, submitter ) {
		form.dataset.wrfReady = '1';

		/*
		 * requestSubmit() e não submit(): form.submit() não dispara handlers de outros
		 * plugins nem a validação HTML nativa, e quebra integrações de terceiros de forma
		 * difícil de diagnosticar. E o submitter precisa ser preservado — formulários com
		 * mais de um botão perdem qual foi clicado se resubmetidos sem ele.
		 */
		if ( form.requestSubmit ) {
			form.requestSubmit( submitter || undefined );
			return;
		}

		if ( submitter && submitter.name ) {
			var carry = document.createElement( 'input' );
			carry.type = 'hidden';
			carry.name = submitter.name;
			carry.value = submitter.value || '';
			form.appendChild( carry );
		}

		form.submit();
	}

	function onSubmit( event ) {
		var form = event.target;

		if ( ! isProtected( form ) || form.dataset.wrfReady === '1' ) {
			return;
		}

		event.preventDefault();

		var submitter = event.submitter || null;
		var action = field( form, 'wrf_action' );
		var actionValue = action ? action.value : 'wrf_submit';

		obtainToken( actionValue )
			.then( function ( result ) {
				if ( result.token ) {
					field( form, 'wrf_token' ).value = result.token;
					field( form, 'wrf_cstate' ).value = CSTATE.NONE;
				} else {
					markUnreachable( form, result.cstate );
				}
			} )
			.then( function () {
				// finally lógico: o formulário SEMPRE é submetido, com ou sem token.
				// Quem decide o veredito é o servidor, nunca o navegador.
				resubmit( form, submitter );
			} );
	}

	/*
	 * Listener em `document`, na fase de captura: sobrevive a formulários re-renderizados
	 * por outros scripts, que é o caso comum em checkout e em temas com AJAX.
	 */
	document.addEventListener( 'submit', onSubmit, true );

	/**
	 * Contrato de consentimento (arquitetura v1.1 §2.4).
	 *
	 * Consentimento quase sempre é concedido DEPOIS do page load, sem recarregar a página
	 * — o lado PHP sozinho não cobre isso. Daí o carregamento sob demanda: ao receber o
	 * sinal, injetamos a tag do `api.js`, e as promessas pendentes de `waitForGrecaptcha`
	 * resolvem sozinhas no próximo ciclo do poll.
	 *
	 * Consequência boa: se o consentimento chegar DURANTE o preenchimento, o submit
	 * seguinte tem token e o visitante nunca vê o aviso.
	 *
	 * Consequência aceita: o primeiro submit imediatamente após o consentimento pode
	 * gastar o timeout esperando o download. Aceitável — o alternativo é o usuário esperar
	 * sem entender.
	 */
	var providerRequested = false;

	function loadProviderScript() {
		if ( providerRequested || ! settings.providerUrl ) {
			return;
		}

		if ( window.grecaptcha ) {
			providerRequested = true;
			return;
		}

		providerRequested = true;

		var tag = document.createElement( 'script' );
		tag.src = settings.providerUrl;
		tag.async = true;
		tag.defer = true;
		// Mesmas marcações do enfileiramento normal: plugins de cache que adiam JS não
		// podem adiar justamente o script que o visitante acabou de autorizar.
		tag.setAttribute( 'data-cfasync', 'false' );
		tag.setAttribute( 'data-no-optimize', '1' );
		tag.setAttribute( 'data-no-defer', '1' );
		tag.setAttribute( 'data-nowprocket', '' );

		document.head.appendChild( tag );
	}

	api.grantConsent = function () {
		var alreadyGranted = api.consentGranted === true && providerRequested;

		api.consentGranted = true;
		loadProviderScript();

		if ( alreadyGranted ) {
			// Idempotente: chamar duas vezes não injeta duas tags nem dispara dois eventos.
			return;
		}

		document.dispatchEvent( new CustomEvent( 'wp-recaptcha-forms:consent-granted' ) );
	};

	document.addEventListener( 'wp-recaptcha-forms:consent-granted', loadProviderScript );

	if ( api.consentGranted ) {
		// Sem gating o script já veio enfileirado pelo PHP; marcar evita uma segunda tag.
		providerRequested = true;
	}
} )();
