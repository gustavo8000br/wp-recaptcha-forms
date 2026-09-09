# Checklist de release — wp-recaptcha-forms

Passos que **não** são automatizáveis de forma barata em CI e por isso são roteirizados
aqui. Cada um tem um critério binário; "testei e pareceu ok" não é resultado.

> Esta lista cresce a cada rodada de stories. Nesta versão ela cobre a fundação
> (Stories 1.1 a 1.6). Os itens de WooCommerce, login, consentimento e i18n entram com as
> stories correspondentes.

---

## S-05 — Verificação sob cache de página (arquitetura v1 §6.6)

O bug que afunda plugin desta categoria. O passo 3 é o que a maioria dos testes pula, e é
o único que reproduz o problema.

1. Instalar **WP Rocket** ou **LiteSpeed Cache**, com cache de página **e** "Delay JS"
   ligados.
2. Visitar a página do formulário como visitante anônimo (popula o cache) e confirmar
   `X-Cache: HIT` (ou equivalente do plugin) na segunda visita.
3. **Esperar 5 minutos.** Mais que a validade de um token do reCAPTCHA.
4. Submeter o formulário. **Deve passar.**
5. Inspecionar o HTML **cacheado** e confirmar:
   - `<input type="hidden" name="wrf_token" value="">` — valor vazio;
   - `<input type="hidden" name="wrf_cstate" value="">` — valor vazio;
   - nenhum nonce dentro do bloco `.wrf-field`;
   - nenhum dado específico de usuário.

O passo 5 é o mais valioso: verifica o **invariante** (A3), não o sintoma.

**Critério:** os 5 passos passam. Falha em qualquer um bloqueia o release.

---

## Carregamento condicional (FR-07)

1. Visitar uma página **sem** formulário protegido (home, um post com comentários
   desligados). **Nenhum** script do Google no HTML.
2. Visitar uma página **com** formulário protegido. Os dois scripts aparecem, e ambos
   carregam `data-cfasync="false"`, `data-no-optimize="1"`, `data-no-defer="1"` e
   `data-nowprocket`.

---

## Kill switch (arquitetura v1.1 §5, item 3 da v1.1 §8.3)

1. `define( 'WRF_DISABLE', true );` no `wp-config.php`.
2. Nenhum campo `wrf_*` no HTML de login nem de checkout; nenhum script do Google.
3. Notice de aviso presente em todo o admin, sem botão de dispensar.
4. Site Health reporta `recommended` (não `critical`).
5. A tela de configurações abre e salva normalmente.
6. **O login funciona.**

---

## Adblock — a quarta classe (v1.1 §8.3-1)

**Este é o passo que prova que `CLIENT_UNREACHABLE` existe de verdade.**

1. Com uBlock Origin e lista anti-Google ativos, submeter um formulário protegido.
2. Política em **permitir**: o envio passa, e o aviso amigável (`.wrf-notice`) aparece no
   formulário.
3. Política em **bloquear**: o envio é recusado com a mensagem de bloqueador — **não** com
   a mensagem de "não foi possível confirmar que você não é um robô".

---

## Instalação a partir do zip (BL-07 item 2)

O ambiente de desenvolvimento monta o repositório inteiro em `wp-content/plugins/`, o que
significa que ele **nunca exercita o artefato distribuído**. O gate de ativação limpa só é
significativo sobre o zip real.

1. Gerar o zip de distribuição.
2. Instalar num WordPress virgem pelo painel.
3. Ativar: **nenhum** notice, warning ou erro de log.
4. Desinstalar: nenhuma option com prefixo `wp_recaptcha_forms_` ou `wrf_` sobra no banco.

---

## URLs externas (FR-24, S-10)

Conferir **ao vivo, na data do release**, que respondem 200:

- console clássico do reCAPTCHA (link "recomendado" da tela de configurações);
- console do Google Cloud / reCAPTCHA Enterprise.

---

## Gates automatizados que precisam estar verdes

```bash
composer test              # suíte unitária
composer check:boundary    # 4 gates de fronteira
```
