# Ligar o plugin à sua plataforma de consentimento

> Material de documentação. Entra no README quando ele for escrito (Stories 1.22/1.23).
> Estes trechos **não** fazem parte do código do plugin, de propósito: se a plataforma
> mudar o nome do evento amanhã, muda uma linha aqui — não uma release.

O plugin não se integra a nenhuma plataforma de consentimento pelo nome. Ele publica um
contrato; quem conhece a plataforma conecta os dois fios. A única exceção é a **WP Consent
API**, que é o contrato padrão do ecossistema (implementado por várias plataformas ao mesmo
tempo) e já vem ligada.

Primeiro, em **Configurações → WP reCAPTCHA Forms**, escolha o modo:

| Modo | Comportamento |
|---|---|
| Não exigir (padrão) | O script do Google carrega em toda página com formulário protegido. |
| Automático | Respeita a WP Consent API se ela existir; sem ela, comporta-se como "não exigir". |
| Exigir | Nunca carrega o script sem sinal explícito de consentimento. |

## WP Consent API

Nada a fazer além de escolher **Automático** ou **Exigir**. Para classificar o reCAPTCHA
numa categoria diferente de `marketing` — decisão jurídica sua, não nossa:

```php
add_filter( 'wp_recaptcha_forms_consent_category', function () {
    return 'functional';
} );
```

## Complianz

```js
document.addEventListener( 'cmplz_status_change', function () {
    if ( cmplz_has_consent( 'marketing' ) ) {
        window.wpRecaptchaForms.grantConsent();
    }
} );
```

## CookieYes

```js
document.addEventListener( 'cookieyes_consent_update', function ( event ) {
    if ( event.detail && event.detail.accepted && event.detail.accepted.includes( 'advertisement' ) ) {
        window.wpRecaptchaForms.grantConsent();
    }
} );
```

## Qualquer outra, pelo PHP

```php
add_filter( 'wp_recaptcha_forms_consent_granted', function ( $granted, $form_id ) {
    // Devolva null para deixar a decisão para o próximo passo da resolução.
    return isset( $_COOKIE['minha_cmp'] ) ? 'aceito' === $_COOKIE['minha_cmp'] : null;
}, 10, 2 );
```

Ou, sem closure:

```php
wp_recaptcha_forms_set_consent( true );
```

## O que acontece quando o consentimento não vem

O `api.js` não é carregado, o visitante envia o formulário com
`wrf_cstate = consent-pending`, e o **servidor** decide o que fazer com essa declaração,
pela política "se o navegador do visitante não conseguir carregar o reCAPTCHA". No padrão
(permitir), o envio passa sem verificação. Em "bloquear", o visitante recebe uma mensagem
específica pedindo o aceite — nunca a mensagem de robô.

Concedido o consentimento depois do carregamento da página, o script é injetado na hora: se
isso acontecer enquanto o visitante ainda preenche o formulário, o envio seguinte já leva
token e nenhum aviso aparece.
