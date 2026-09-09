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

	window.wpRecaptchaForms = window.wpRecaptchaForms || {};
	window.wpRecaptchaForms.consentGranted = true;

	function field( form, name ) {
		return form.querySelector( '[name="' + name + '"]' );
	}

	function isProtected( form ) {
		return !! ( form && form.querySelector && field( form, 'wrf_token' ) );
	}

	function consentPending() {
		return window.wpRecaptchaForms.consentGranted === false;
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

		waitForGrecaptcha( LOAD_TIMEOUT_MS )
			.then( function () {
				return window.grecaptcha
					.execute( settings.siteKey, { action: actionValue } )
					.then( function ( token ) {
						field( form, 'wrf_token' ).value = token;
						field( form, 'wrf_cstate' ).value = CSTATE.NONE;
					} )
					.catch( function () {
						markUnreachable( form, CSTATE.EXEC );
					} );
			} )
			.catch( function () {
				markUnreachable( form, consentPending() ? CSTATE.CONSENT : CSTATE.NO_SCRIPT );
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
	 * Contrato de consentimento (arquitetura v1.1 §2.4). O carregamento sob demanda em
	 * si é da story do ConsentGate; aqui fica só a superfície que o CMP chama, para que
	 * o snippet documentado no README já funcione.
	 */
	window.wpRecaptchaForms.grantConsent = function () {
		window.wpRecaptchaForms.consentGranted = true;
		document.dispatchEvent( new CustomEvent( 'wp-recaptcha-forms:consent-granted' ) );
	};
} )();
