# wp-recaptcha-forms — Arquitetura Técnica v1.1 (atualização)

**Autor:** @architect (Aria)
**Data:** 2026-09-08
**Base:** `docs/architecture-v1.md` (permanece válido em tudo que não for contradito aqui)
**Insumos novos:** `docs/qa-plan-review-v1.md` (@qa) + decisões do dono do produto sobre BL-01, BL-02, BL-03, BL-04 e o item de privacidade devolvido em §2(b)
**Status:** **este documento fecha BL-01 a BL-04 e o item (b) da revisão do @qa.** É a base para @sm quebrar em stories e para @dev implementar. Não requer nova rodada de @qa.

---

## 0. Como ler este documento

O v1 não foi reescrito. Esta v1.1 é **normativa e prevalece** onde houver divergência. Cada seção abaixo declara explicitamente qual parte do v1 ela substitui, estende ou revoga.

| Seção v1.1 | Fecha | Efeito sobre o v1 |
|---|---|---|
| §1 | BL-01 (parte comportamento) | **substitui** §5.1, §5.4, §5.5, §6.2 (`catch`), §13 (linha "Token vazio") |
| §2 | item (b) — consentimento | **estende** §6.3, §7.2, §13; **substitui** o enfileiramento incondicional do `api.js` implícito em §10 (`AssetManager`) |
| §3 | item (b) — privacidade | **novo FR**; estende §12 (API pública) e §13 |
| §4 | BL-02 | **substitui** §5.1 (linha MISCONFIG), §5.3-1, §5.5 |
| §5 | BL-03 | **estende** §7.2 e §13; novo ponto no bootstrap de §11 |
| §6 | BL-04 | **preenche a lacuna** apontada; nova subseção §8.6 do v1 |
| §7 | — | consolida a máquina de estados completa das **4 classes** |
| §8 | — | impacto em CI, gates de release, arquivos e stories |

Nada aqui reabre decisão de produto. Os cinco itens vieram decididos pelo dono; meu trabalho foi torná-los implementáveis sem quebrar A1–A5 e sem deixar contradição residual com o resto do v1.

**Um alerta de honestidade, na disciplina da §1 do PRD ("não vender diferenciação inexistente"):** a decisão de BL-01 introduz, por construção, um caminho que um bot pode percorrer. Isso não é defeito de implementação — é o preço da decisão, foi assumido conscientemente, e está quantificado na §1.4. Está escrito aqui em vez de escondido porque o operador precisa poder mudar o default sabendo o que está comprando.

---

## 1. BL-01 — a quarta classe: `CLIENT_UNREACHABLE`

### 1.1 O que muda em relação ao v1

O v1 (§6.2) decidiu que o cliente que não alcança o Google envia token vazio e o servidor bloqueia — REJECTED, incondicional, sem controle. O @qa mostrou que isso perde checkout de visitante com adblock ou CMP, e que o PRD §4.3 escolheu fail-open exatamente para não perder venda.

**Decisão do dono:** existe uma **quarta classe de falha**, `CLIENT_UNREACHABLE`, separada de REJECTED, INFRA e MISCONFIG, com **política própria configurável e default fail-open**, mais aviso amigável no formulário.

Isso revoga a frase do v1 §6.2: *"A decisão de bloquear nunca é do navegador"*. Passa a valer uma formulação mais precisa, que é o desenho real:

> **O navegador não decide o veredito. Ele declara um estado, e o servidor decide o que fazer com essa declaração — inclusive ignorá-la.**

A diferença não é retórica: a declaração do cliente é entrada, não saída. Quem a converte em ALLOW ou BLOCK continua sendo o `Gate`, num único lugar (A1 preservado).

### 1.2 O sinal de cliente

Um segundo campo hidden, ao lado dos dois da §6.2 do v1, também vazio no HTML e portanto igualmente cacheável (A3 preservado):

```html
<input type="hidden" name="wrf_token"  value="">
<input type="hidden" name="wrf_action" value="wrf_login">
<input type="hidden" name="wrf_cstate" value="">   <!-- novo -->
```

`wrf_cstate` só recebe valor quando o cliente **falha**. O vocabulário é fechado e definido por nós (não pelo Google, A2 preservado):

| Valor | Quando o JS o escreve |
|---|---|
| `""` (vazio) | caminho feliz — o token foi obtido; ou nenhum JS rodou |
| `no-script` | `window.grecaptcha` inexistente após o timeout de carregamento (§1.3) — `api.js` bloqueado por adblock, DNS, rede ou CMP |
| `consent-pending` | modo de consentimento ativo (§2) e consentimento não concedido no momento do submit |
| `exec-error` | `grecaptcha` existe mas `execute()` rejeitou (erro de site key, domínio não autorizado, quota do cliente) |

### 1.3 O JS — substitui o bloco da §6.2 do v1

```js
const CSTATE = { NONE: '', NO_SCRIPT: 'no-script', CONSENT: 'consent-pending', EXEC: 'exec-error' };
const LOAD_TIMEOUT_MS = 4000;   // filtrável via wp_localize (wrf.loadTimeout)

function markUnreachable(form, state) {
  form.querySelector('[name=wrf_cstate]').value = state;
  form.querySelector('[name=wrf_token]').value  = '';
  showNotice(form, wrf.i18n.unreachableNotice);   // §1.6
}

form.addEventListener('submit', function (e) {
  if (form.dataset.wrfReady === '1') return;
  e.preventDefault();

  waitForGrecaptcha(LOAD_TIMEOUT_MS)                 // resolve, ou rejeita por timeout
    .then(function () {
      return grecaptcha.execute(siteKey, { action: actionOf(form) })
        .then(function (token) {
          form.querySelector('[name=wrf_token]').value  = token;
          form.querySelector('[name=wrf_cstate]').value = CSTATE.NONE;
        })
        .catch(function () { markUnreachable(form, CSTATE.EXEC); });
    })
    .catch(function () {
      markUnreachable(form, consentPending() ? CSTATE.CONSENT : CSTATE.NO_SCRIPT);
    })
    .then(function () {
      form.dataset.wrfReady = '1';
      if (form.requestSubmit) form.requestSubmit(submitter); else form.submit();
    });
});
```

Preservados do v1 e **não negociáveis**: `requestSubmit()` em vez de `submit()`, e a captura do `submitter`. O `.then()` final é deliberadamente um `finally` lógico — **o formulário sempre é submetido**, com ou sem token. Quem decide é o servidor.

O timeout de 4s existe porque "grecaptcha não carregou" não é um evento: é a ausência de um. Sem prazo, o adblock produz um formulário que nunca submete — que é um modo de falha pior que qualquer um dos dois lados desta decisão. 4s < 5s do timeout de rede do servidor (§4.3 do v1), de propósito: o cliente desiste antes de o servidor desistir.

### 1.4 Classificação e política no servidor

```
token vazio  +  cstate ∈ {no-script, consent-pending, exec-error}  →  CLIENT_UNREACHABLE
token vazio  +  cstate vazio                                       →  REJECTED  (como no v1)
token presente                                                     →  fluxo normal (§7)
```

O `cstate` **só é lido quando o token está vazio**. Um token presente e reprovado pelo Google é REJECTED, e nenhuma declaração do cliente muda isso — senão o bot enviaria lixo com `cstate=no-script` e ganharia fail-open no melhor dos dois mundos.

| | Valor |
|---|---|
| **Política default** | **ALLOW (fail-open)** — decisão do dono |
| **Configurável** | Sim, dois níveis, igual a INFRA: global + override por formulário (`inherit` \| `allow` \| `block`) |
| **Filtro** | `wp_recaptcha_forms_client_unreachable_policy( string $policy, FormContext $ctx )` |
| **Visibilidade** | Sem notice de admin. Não é erro do operador nem do site. |

**O que o operador está comprando, escrito sem eufemismo** — vai na tela de config, no texto de ajuda do campo, e no README:

> Com esta opção em "permitir", um visitante cujo navegador não consegue carregar o reCAPTCHA envia o formulário sem verificação. Isso preserva a venda de clientes com bloqueador de anúncios — e também deixa passar um robô que simule a mesma condição. Em "bloquear", nenhum envio não verificado passa, e visitantes com bloqueador não conseguem comprar, comentar nem se registrar.

Escolher fail-open é defensável: o v1 §5.3 já usa exatamente esse raciocínio para MISCONFIG, e o PRD §4.3 o usa para INFRA. Um plugin de reCAPTCHA em fail-open ainda barra a maior parte do spam automatizado real, que não implementa o bypass. O que seria indefensável é o operador não saber.

**Por que não tentar tornar o sinal inforjável:** não dá, e fingir que dá seria pior. Qualquer prova que o cliente possa produzir sem falar com o Google, um script também produz. HMAC do sinal, nonce, rate limit por IP — todos custam complexidade e nenhum muda a propriedade. Por isso o desenho não gasta uma linha nisso; gasta em deixar a política explícita e o texto honesto.

**Mitigação que de fato vale a pena, e é barata:** um site sob ataque vê `CLIENT_UNREACHABLE` subir de ~2-5% (baseline de adblock) para a maioria dos envios. Contagem por janela de tempo é o detector natural — mas contagem é telemetria, que o PRD §6 exclui da v1. **Registrado como candidato v1.x**, junto do item de quota recorrente já registrado na §5.5 do v1. Não entra agora.

### 1.5 Por que uma quarta classe, e não um caso especial dentro de REJECTED

Alternativas descartadas, com o motivo:

- **Tornar REJECTED configurável.** Revogaria a §5.2 do v1, que é o achado mais importante do documento: fail-open sobre REJECTED desliga o plugin por default. Fora de questão, e a §11 do v1 já classifica isso como MAJOR de postura de segurança.
- **Reusar INFRA.** Colapsaria dois eventos com donos diferentes: INFRA é o servidor não alcançando o Google (o operador pode agir — firewall, DNS, `recaptcha.net`), CLIENT_UNREACHABLE é o visitante não alcançando (o operador não pode agir). Políticas iguais hoje, causas e mensagens diferentes sempre. Colapsar agora é garantir que a separação seja feita depois, em migração de opção — que a §11 do v1 chama de MAJOR.
- **Flag booleana dentro do `Verdict`.** É a quarta classe com nome pior e sem lugar na tabela de política.

Quatro classes, quatro políticas, uma tabela. A §7 mostra que a máquina de estados não ficou mais complexa — ganhou uma linha ortogonal às outras três.

### 1.6 O aviso no formulário

Decidido pelo dono: aviso amigável pedindo para desativar o bloqueador. Renderizado **pelo JS, no cliente, no momento do submit** — nunca no HTML impresso pelo PHP (A3: o HTML permanece idêntico para todo visitante e eternamente cacheável).

- Inserido num `<div class="wrf-notice" role="status" aria-live="polite">` criado dinamicamente, adjacente ao campo hidden.
- Texto vem de `wp_localize_script`, já traduzido pelo PHP → passa pelo pipeline de i18n normal e **é coberto pelo gate de pseudo-locale da §9.5 do v1**.
- Aparece **antes** do submit prosseguir, e o envio continua — é aviso, não bloqueio. Com política `block`, a mensagem de recusa do servidor (§1.7) é que carrega o texto acionável; o aviso do cliente explica antes.
- Default (traduzível, editável pelo operador via FR-27):
  > *Não foi possível carregar a verificação de segurança do Google. Se você usa bloqueador de anúncios ou recusou os cookies, desative-o para esta página e tente novamente.*

Quando a política é `allow`, o aviso é informativo e o envio funciona; ele existe para o caso em que o operador depois muda para `block`, e para dar ao visitante o vocabulário de suporte certo ("bloqueador", não "o site está quebrado").

### 1.7 Mensagem do servidor quando a política é `block`

`CLIENT_UNREACHABLE` bloqueado **nunca** compartilha a mensagem de REJECTED. Mensagem distinta, específica e acionável — era exatamente o item 2 do pedido do @qa em BL-01:

> *Não conseguimos verificar sua sessão porque a verificação de segurança não carregou no seu navegador. Desative o bloqueador de anúncios (ou aceite os cookies) para esta página e tente novamente.*

Sujeita a `wp_recaptcha_forms_error_message( $msg, $form_id, $reason )` — `$reason` passa a incluir `client_unreachable`.

---

## 2. Consentimento — o `api.js` vira opt-in atrás de um hook genérico

### 2.1 O problema e a forma da solução

Um CMP configurado corretamente para LGPD/GDPR segura scripts de terceiro até o aceite. Hoje o plugin enfileira o `api.js` incondicionalmente, e o CMP o bloqueia por fora — resultado: o plugin não sabe que foi bloqueado, e o comportamento resultante é o de BL-01 sem que ninguém tenha escolhido nada.

**Decisão:** o carregamento do `api.js` fica **opt-in atrás de um hook genérico de consentimento**, que qualquer CMP (Complianz, CookieYes, WP Consent API, ou um `functions.php`) possa disparar.

Restrição de desenho que governa tudo abaixo: **o plugin não integra com CMP nenhum por nome.** Integração nominal significa manter N adaptadores para N plugins que mudam de API sem avisar, e é dívida garantida num projeto de mantenedor solo. O plugin publica um contrato; quem conhece o CMP conecta os dois fios. A única exceção é a WP Consent API, porque ela **é** o contrato padrão do ecossistema (não é um plugin específico) e a adesão custa quatro linhas.

### 2.2 Três modos, e por que "opt-in" não pode significar "quebrado por default"

Se o default fosse "sem consentimento não carrega", todo site sem CMP instalaria o plugin e ele nunca funcionaria. Opt-in aqui é **do gating**, não do plugin: um site sem CMP não tem consentimento a gerenciar.

Opção `consent_mode`, três valores:

| Modo | Comportamento | Default |
|---|---|---|
| `off` | Carrega o `api.js` normalmente. Comportamento do v1. | **sim** |
| `auto` | Consulta a WP Consent API se ela estiver presente; se não estiver, comporta-se como `off`. | — |
| `required` | **Nunca** carrega o `api.js` sem sinal explícito de consentimento. Sem sinal → `cstate=consent-pending` → §1. | — |

Em `auto` e `required` a tela de config mostra o estado corrente resolvido ("consentimento: exigido — nenhum provedor detectado"), para o operador não descobrir em produção que ligou `required` sem nada para conceder.

### 2.3 O contrato — PHP

Um filtro, uma action, uma função. É o hook genérico pedido.

```php
/**
 * Filtro de decisão. Consultado no momento do enqueue e no render do campo.
 * Em consent_mode=off retorna true sem consultar (curto-circuito).
 * @param bool|null $granted  null = indeterminado (sem provedor); bool = decisão
 */
apply_filters( 'wp_recaptcha_forms_consent_granted', $granted, string $form_id );

/** Disparada quando o consentimento é concedido no lado servidor (ex.: cookie do CMP lido no PHP). */
do_action( 'wp_recaptcha_forms_consent_granted_action', string $category = 'marketing' );

/** Helper para o integrador, equivalente ao filtro sem precisar de closure. */
wp_recaptcha_forms_set_consent( bool $granted ): void
```

Resolução, na ordem — a primeira que devolver um booleano ganha:

1. `consent_mode === 'off'` → `true`, fim.
2. Retorno não-nulo do filtro `wp_recaptcha_forms_consent_granted`.
3. WP Consent API, se `function_exists('wp_has_consent')`: `wp_has_consent('marketing')`. Categoria filtrável via `wp_recaptcha_forms_consent_category` (default `marketing`; um site pode classificar reCAPTCHA como `functional` ou `statistics` — é decisão jurídica do operador, não nossa).
4. `consent_mode === 'auto'` e nada respondeu → `true` (degrada para `off`).
5. `consent_mode === 'required'` e nada respondeu → `false`.

O parâmetro `$form_id` está lá de propósito: um operador pode legitimamente exigir consentimento na loja e não no `wp-login.php`, que não é página pública de marketing. Não há UI para isso na v1 — o filtro basta.

### 2.4 O contrato — JS

Consentimento quase sempre é concedido **depois** do page load, sem recarregar a página. O lado PHP sozinho não cobre isso.

```js
// o plugin expõe:
window.wpRecaptchaForms.grantConsent();     // carrega api.js sob demanda, idempotente
window.wpRecaptchaForms.consentGranted;     // boolean, estado corrente

// e escuta, no document:
document.dispatchEvent(new CustomEvent('wp-recaptcha-forms:consent-granted'));
```

Um snippet de três linhas no `functions.php` ou no callback do CMP conecta qualquer solução:

```js
document.addEventListener('cmplz_status_change', function () {          // exemplo Complianz
  if (cmplz_has_consent('marketing')) window.wpRecaptchaForms.grantConsent();
});
```

O README traz este snippet para Complianz, CookieYes e WP Consent API — como **exemplo de documentação**, não como código do plugin. Se o CMP mudar o nome do evento amanhã, muda uma linha do README, não uma release.

### 2.5 Carregamento sob demanda

Em `required`/`auto`, `AssetManager` **não** enfileira o `api.js`. Enfileira apenas o nosso `frontend.js` (leve, sem terceiro, sem dado pessoal). Ao receber `grantConsent()`, o loader injeta a tag do `api.js` dinamicamente e resolve as promessas pendentes de `waitForGrecaptcha()` (§1.3).

Consequência boa: se o consentimento chegar **durante** o preenchimento do formulário, o submit seguinte tem token. O visitante nunca vê o aviso.

Consequência aceita: o primeiro submit imediatamente após o consentimento pode gastar o timeout de 4s esperando o `api.js` baixar. Aceitável — o alternativo é fazer o usuário esperar sem entender.

**FR-07 (carregamento condicional) permanece intocado e continua sendo a mitigação de privacidade mais efetiva do plugin**, como o @qa observou corretamente. O gating de consentimento é ortogonal: FR-07 decide *em quais páginas*, o consentimento decide *sob qual condição*.

---

## 3. FR novo — texto de aviso de privacidade entregue pronto

### 3.1 Enunciado

Registro aqui como **requisito formal**, porque o @qa está certo: requisito que só existe como recomendação num documento de outro agente é requisito que não sai. O @pm numera no PRD; a numeração provisória abaixo evita ambiguidade enquanto isso.

> **FR-31 — Texto de privacidade pronto para uso.** O plugin fornece um texto de aviso de privacidade, traduzível, cobrindo o processamento de dados do visitante pelo Google via reCAPTCHA (endereço IP, cookies, carregamento de script de domínio de terceiro), disponível ao operador por **shortcode**, por **função helper** e por **integração com a ferramenta nativa de política de privacidade do WordPress**, para que ele o cole na própria política sem redigir nada.

> **FR-32 — Estado de privacidade fiel.** O texto reflete a configuração real da instalação: se o toggle `remoteip` está ligado ou desligado, e se o `api.js` está sob gating de consentimento. Um texto que descreva um comportamento que a instalação não tem é pior que nenhum texto.

### 3.2 Superfícies

```php
/** Retorna o texto. HTML seguro, parágrafos <p>. $args: ['format' => 'html'|'plain'] */
wp_recaptcha_forms_privacy_notice( array $args = [] ): string
```

```text
[wp_recaptcha_forms_privacy_notice]
[wp_recaptcha_forms_privacy_notice format="plain"]
```

E o caminho nativo, que é o que o operador cuidadoso já usa — Configurações → Privacidade → guia de política sugerida, onde o texto aparece ao lado dos dos demais plugins, com botão de copiar:

```php
add_action( 'admin_init', function () {
    wp_add_privacy_policy_content(
        'WP reCAPTCHA Forms',
        wp_kses_post( wp_recaptcha_forms_privacy_notice() )
    );
} );
```

Ambos existem porque cobrem operadores diferentes: quem usa a ferramenta nativa nunca procuraria um shortcode, e quem escreveu a política à mão num Elementor nunca abriria Configurações → Privacidade.

### 3.3 O texto (conteúdo normativo, `Messages::privacy_notice()`)

Métodos, nunca `const` — §9.1 do v1, sob pena do `_load_textdomain_just_in_time`.

Blocos, montados conforme a configuração (FR-32):

- **Sempre:** este site usa o reCAPTCHA do Google para proteger formulários contra envios automatizados; ao interagir com um formulário protegido, o navegador **carrega um script servido pelo Google**, o que expõe ao Google o endereço IP, informações do navegador e **cookies do domínio google.com**; sujeito à Política de Privacidade e aos Termos do Google (URLs incluídas); o script é carregado **apenas nas páginas que contêm formulário protegido** (FR-07 — dito porque é verdade e é favorável).
- **Se `remoteip` ligado:** além disso, o endereço IP do visitante é enviado ao Google **junto com a verificação, no servidor**.
- **Se `remoteip` desligado:** frase que impede a crença errada, que o @qa isolou corretamente — *"desligar este envio não impede que o Google receba o endereço IP, porque o script do reCAPTCHA é carregado diretamente do domínio do Google pelo navegador do visitante."*
- **Se consentimento em `required`/`auto`:** o script só é carregado após o consentimento do visitante.

**Delimitação explícita, no topo do texto gerado e no README:** isto é um texto de partida, não aconselhamento jurídico, e não torna a instalação conforme à LGPD ou ao GDPR. Prometer conformidade seria a "diferenciação inexistente" que o PRD §1 proíbe — e, diferentemente das outras, essa tem consequência legal para o operador.

### 3.4 Testabilidade

O @qa pediu critério no gate de release. Três, todos baratos:

1. Unitário: `wp_recaptcha_forms_privacy_notice()` com `remoteip` ligado contém o bloco de envio de IP; desligado contém a frase de não-falsa-conformidade. Duas asserções.
2. O texto passa pelo pseudo-locale da §9.5 do v1 — é string do plugin, logo o gate de string hardcoded já o cobre de graça.
3. Item no `release-checklist.md`: as duas URLs do Google (política e termos) respondem 200 — mesmo passo do S-10 que já confere as URLs do console.

---

## 4. BL-02 — classificação pelo código de erro real, em toda chamada

### 4.1 A contradição do v1 e o que a substitui

O v1 prometia, em §5.1, detectar "par site/secret trocado" como MISCONFIG, e entregava, em §5.3-1, uma sonda no save que **não pode** detectar isso — o `siteverify` não recebe a site key. O @dev implementaria a tabela e consideraria o requisito cumprido.

**Decisão:** a classificação passa a ser feita pelo **código de erro real devolvido pelo Google em toda chamada de verificação**, não apenas na sonda do save. `invalid-input-secret` e `invalid-keys` são **sempre MISCONFIG, imediatamente**, com escalada visível no admin, independentemente de quando ocorram.

Isso não viola A2. A tradução de `error-codes` → `FailureClass` continua acontecendo **exclusivamente dentro de `src/Provider/`** — o `Gate` recebe `FailureClass`, nunca strings do Google. O gate de CI da §4.5 do v1 continua valendo sem alteração; é justamente ele que garante que esta regra não vaze para fora da fronteira.

### 4.2 Tabela normativa de classificação — substitui a §5.1 do v1

Aplicada em `AbstractSiteverifyProvider::classify()`, ponto único.

| Sinal do Google | Classe | Comentário |
|---|---|---|
| `WP_Error` do transporte, HTTP ≥ 500, HTTP 429, corpo não-JSON, corpo vazio | **INFRA** | inalterado |
| `invalid-input-secret` | **MISCONFIG** | agora **em qualquer chamada**, não só na sonda |
| `invalid-keys` | **MISCONFIG** | idem |
| `missing-input-secret` | **MISCONFIG** | secret vazia é erro do operador, não do visitante — corrige uma omissão do v1 |
| `bad-request` | **MISCONFIG** | **acata OB-03.** Requisição malformada ao `siteverify` é bug do plugin ou config quebrada, nunca bot. Como REJECTED bloquearia 100% dos visitantes sem escape e sem notice |
| `missing-input-response`, `invalid-input-response`, `timeout-or-duplicate` | **REJECTED** | inalterado — as células em negrito da §5.5 do v1 continuam bloqueando |
| `success:true` com score < threshold, action ≠ esperada, hostname ≠ esperado | **REJECTED** | inalterado |
| código desconhecido / não mapeado | **INFRA** | fail-safe deliberado: a política de INFRA é configurável e tem default fail-open, então um código novo do Google não derruba o site nem cria notice crítico falso. Registrado em `debug_codes()` e no `error_log` sob `WP_DEBUG` |

A última linha é desenho para o futuro: o Google pode acrescentar códigos, e o comportamento sob código desconhecido tem que ser decidido agora, por nós, e não por acidente de `switch` sem `default`.

### 4.3 Escalada de MISCONFIG em runtime

As quatro camadas da §5.3 do v1 permanecem, com o disparo corrigido — antes elas dependiam de o evento ser rotulado MISCONFIG, e o rótulo nunca chegava:

1. **Sonda no save** — mantida. Continua matando a maior parte dos casos antes da produção. **Correção de escopo honesta:** ela prova que a *secret* é válida, e **não** prova que o par site/secret é do mesmo projeto. O texto da UI e do README passa a dizer isso, em vez de "chaves validadas".
2. **Notice persistente e não-dispensável** — agora disparado por `invalid-input-secret` / `invalid-keys` / `missing-input-secret` / `bad-request` em runtime, gravando `wp_recaptcha_forms_misconfig_since` e os `debug_codes()`. Some quando o problema é corrigido.
3. **Site Health** — teste `critical`, mesma origem de dado.
4. **Estado no topo da tela de config** — `Protegendo` / `Degradado — não verificando` / `Desativado`.

O `wp_recaptcha_forms_misconfig_policy` (fail-close opt-in por filtro, §5.3 do v1) permanece e passa a cobrir também os códigos acrescentados aqui.

### 4.4 O par cruzado que ainda escapa — e por que a saída é advisory, não uma classe

Se o Google devolver `invalid-keys` para o par cruzado, §4.2 já resolve: MISCONFIG, notice, fim. Mas há um caminho residual em que o par cruzado produz `invalid-input-response` — que é, corretamente, REJECTED, porque é indistinguível de bot **numa única requisição**.

Não vou reclassificar `invalid-input-response` como MISCONFIG: isso desligaria o bloqueio do sinal mais comum de bot, que é a regressão que a §5.2 do v1 existe para prevenir. Mas também não vou deixar o operador com o site travado e a tela dizendo "Protegendo".

**Saída, e é a única barata que não muda veredito nenhum:** um sinal *advisory*, não uma classe.

- `Gate` mantém dois contadores em transient de 1 hora: total de avaliações com token **não vazio** e quantas resultaram em `invalid-input-response`.
- Se, num mínimo de 10 avaliações, **100%** deram `invalid-input-response`, grava `wp_recaptcha_forms_keypair_suspect` e dispara um notice de nível `warning` (distinto do `error` de MISCONFIG): *"Todas as verificações recentes falharam. A causa mais provável é a site key e a secret key pertencerem a projetos diferentes no console do Google. Confira as duas chaves."*
- **Nenhum veredito muda.** É diagnóstico puro. Um site legítimo sob ataque massivo pode disparar o aviso — e um aviso a mais num site sob ataque não faz mal a ninguém, enquanto um site travado sem aviso faz.
- Limiar de 100% e não 90%: com par cruzado a taxa é exatamente 100%, e o falso positivo em tráfego real fica praticamente restrito a ataque puro.

Dois contadores num transient não são a telemetria excluída pelo PRD §6 — não há histórico, não há tabela, não há relatório, e o dado morre em uma hora. Se essa fronteira for julgada frouxa, o item cai sem prejuízo do resto desta seção.

### 4.5 Matriz de teste — substitui a §5.5 do v1

`c` = valor de `wrf_cstate`. Todas com `FakeTransport`, sem rede.

| Cenário | Classe | global=allow | global=block | override=block |
|---|---|---|---|---|
| `WP_Error('http_request_failed')` | INFRA | ALLOW | BLOCK | BLOCK |
| HTTP 503 | INFRA | ALLOW | BLOCK | BLOCK |
| HTTP 429 | INFRA | ALLOW | BLOCK | BLOCK |
| corpo não-JSON | INFRA | ALLOW | BLOCK | BLOCK |
| `error-codes: ["invalid-input-secret"]` | MISCONFIG | ALLOW + notice `error` | ALLOW + notice | ALLOW + notice |
| `error-codes: ["invalid-keys"]` | MISCONFIG | ALLOW + notice | ALLOW + notice | ALLOW + notice |
| `error-codes: ["missing-input-secret"]` | MISCONFIG | ALLOW + notice | ALLOW + notice | ALLOW + notice |
| `error-codes: ["bad-request"]` | MISCONFIG | ALLOW + notice | ALLOW + notice | ALLOW + notice |
| `error-codes: ["timeout-or-duplicate"]` | REJECTED | **BLOCK** | BLOCK | BLOCK |
| `error-codes: ["missing-input-response"]` | REJECTED | **BLOCK** | BLOCK | BLOCK |
| `error-codes: ["cod-novo-do-google"]` | INFRA | ALLOW | BLOCK | BLOCK |
| `success:true, score 0.3, action ok` (thr 0.6) | REJECTED | BLOCK | BLOCK | BLOCK |
| `success:true, score 0.9, action divergente` | REJECTED | BLOCK | BLOCK | BLOCK |
| `success:true, score 0.9, action ok` | — | ALLOW | ALLOW | ALLOW |
| **token vazio, `c=""`** (bot) | **REJECTED** | **BLOCK** | **BLOCK** | **BLOCK** |
| **token vazio, `c="no-script"`** | **CLIENT_UNREACHABLE** | **ALLOW** | BLOCK | BLOCK |
| **token vazio, `c="consent-pending"`** | **CLIENT_UNREACHABLE** | **ALLOW** | BLOCK | BLOCK |
| **token vazio, `c="exec-error"`** | **CLIENT_UNREACHABLE** | **ALLOW** | BLOCK | BLOCK |
| **token não vazio + `c="no-script"`** (bot esperto) | **REJECTED** (classe pelo token) | **BLOCK** | BLOCK | BLOCK |
| 10/10 `invalid-input-response` com token não vazio | REJECTED + advisory | BLOCK + notice `warning` | idem | idem |

As células em negrito são as regressões nomeadas. Três merecem menção explícita para o @dev:

- **`token vazio, c=""` continua BLOCK sob fail-open.** É a §5.2 do v1 intacta: um bot que simplesmente não envia nada não passa.
- **`token vazio, c="no-script"` é ALLOW por default.** É a decisão do dono. Se algum dia virar BLOCK por default, é mudança de postura de segurança em instalações existentes — **MAJOR**, pela mesma regra da §11 do v1.
- **`token não vazio + c="no-script"` é REJECTED.** Se esta célula algum dia virar ALLOW, o bypass fica trivial e o plugin está desligado. É a regressão mais importante desta versão.

---

## 5. BL-03 — kill switch por constante

### 5.1 Decisão

```php
// wp-config.php
define( 'WRF_DISABLE', true );
```

Desativa **toda** a proteção imediatamente, sem desativar o plugin e sem tocar no banco.

**Nome:** `WRF_DISABLE` é o canônico, por decisão do dono. Como o v1 §13 já usa o prefixo longo em `WP_RECAPTCHA_FORMS_SECRET_KEY`, o bootstrap aceita **`WP_RECAPTCHA_FORMS_DISABLE` como alias**, com a mesma semântica. Duas linhas de código evitam que um operador que seguiu o padrão do outro trecho da documentação fique sem seu kill switch justamente no momento em que precisa dele. O README documenta `WRF_DISABLE` e menciona o alias uma vez.

### 5.2 Onde é checado

No bootstrap, **antes de registrar qualquer hook de enforcement** — não dentro deles. Um guard dentro do hook deixaria o `frontend.js` enfileirado, o campo hidden impresso e a tela de config mentindo.

```php
// wp-recaptcha-forms.php, logo após o guard de versão de PHP
if ( ( defined( 'WRF_DISABLE' ) && WRF_DISABLE )
  || ( defined( 'WP_RECAPTCHA_FORMS_DISABLE' ) && WP_RECAPTCHA_FORMS_DISABLE ) ) {
    require_once __DIR__ . '/src/DisabledMode.php';   // só admin notice + Site Health
    return;                                            // nenhuma integração é registrada
}
```

O que **continua** funcionando em modo desativado, de propósito:

- A tela de config abre e é editável — o operador precisa poder consertar a chave errada que o levou até aqui.
- Notice de nível `warning` em todo o admin: *"A proteção do WP reCAPTCHA Forms está desativada pela constante `WRF_DISABLE` em `wp-config.php`."* Não-dispensável, pelo mesmo motivo do notice de MISCONFIG: um site que ficou desprotegido por seis meses porque alguém esqueceu a constante é o pior desfecho possível.
- Site Health reporta `recommended` (não `critical` — é estado deliberado, não defeito).
- O estado no topo da tela de config mostra `Desativado`, que a §5.3-4 do v1 já previa e que só agora tem como ser alcançado.

O que **não** funciona: nenhuma integração é registrada, nenhum asset é enfileirado, nenhum campo é impresso, nenhuma chamada ao Google é feita. Todos os formulários voltam ao comportamento nativo do WordPress na requisição seguinte.

### 5.3 Precedente

É o padrão do nicho, e citá-lo importa porque significa que o operador em pânico às 23h já procura por isso: Wordfence tem `WORDFENCE_DISABLE_FILE_VIEWER` e a desativação por renomeação de `wordfence-waf.php`; iThemes/SolidWP tem `ITSEC_DISABLE_MODULES`; o próprio WordPress tem `DISALLOW_FILE_MODS` e `WP_DISABLE_FATAL_ERROR_HANDLER`. A constante em `wp-config.php` é o mecanismo que sobrevive quando o `wp-admin` está inacessível — que é exatamente o cenário de BL-03.

### 5.4 Complemento aceito do @qa

O @qa sugeriu, como complemento barato, não aplicar reCAPTCHA no login de quem já está autenticado. Acatado como regra na §6: se `is_user_logged_in()`, a integração de login não avalia. Não há bot a barrar em quem já tem sessão válida, e isso remove um caminho inteiro de lockout.

### 5.5 Teste

Integração: com a constante definida, `Registry::boot()` não registra nada (`count($registry->all()) === 0`), o `frontend.js` não está enfileirado, o formulário de login não contém `wrf_token`, e o notice está presente. Quatro asserções. Vai na **fundação**, não na story de login — o @qa está certo, e um kill switch que só existe depois das integrações é um kill switch que não existiu quando foi preciso.

---

## 6. BL-04 — superfície do login. Nova §8.6 (o v1 não tinha)

### 6.1 Regra

O reCAPTCHA no login vale **exclusivamente para o formulário HTML padrão do `wp-login.php`**. Nenhum outro caminho de autenticação é afetado.

### 6.2 Render

```php
add_action( 'login_form', [ $this, 'render_field' ] );   // dentro do <form> do wp-login.php
```

`login_form` e não `login_footer`: o campo precisa estar **dentro** do `<form>` para ser submetido. Erro comum, e silencioso — o campo aparece na página, nunca chega no `$_POST`, e todo login vira token vazio.

### 6.3 Validação — a condição de aplicabilidade

```php
add_filter( 'authenticate', [ $this, 'check' ], 30, 3 );   // depois do 20 do core

public function check( $user, $username, $password ) {
    if ( ! $this->applies() ) {
        return $user;                       // passa adiante intocado
    }
    // ... Gate::assess() → WP_Error em caso de bloqueio
}

private function applies(): bool {
    if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) return false;   // XML-RPC, Jetpack, apps
    if ( defined( 'REST_REQUEST' )   && REST_REQUEST )   return false;   // REST + application passwords
    if ( defined( 'WP_CLI' )         && WP_CLI )         return false;   // WP-CLI
    if ( wp_doing_cron() )                                return false;   // cron
    if ( wp_doing_ajax() )                                return false;   // AJAX de terceiro
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) return false;
    if ( ! isset( $_POST['wp-submit'] ) )                 return false;   // ← a condição decisiva
    if ( is_user_logged_in() )                            return false;   // §5.4
    return true;
}
```

**`isset($_POST['wp-submit'])` é o coração da regra.** `wp-submit` é o `name` do botão de envio que o WordPress imprime no formulário de login — ele só chega ao `$_POST` se o navegador submeteu **aquele formulário renderizado**, que é o mesmo que contém nosso campo. Nenhum cliente programático o envia: nem XML-RPC, nem a REST API, nem o app oficial, nem um plugin de SSO, nem um login social por redirect de OAuth.

As checagens de constante acima dele são **defesa em profundidade, não redundância**: um cliente XML-RPC malandro pode postar `wp-submit`, e o `XMLRPC_REQUEST` corta antes. E `applies()` é ordenado do mais barato e mais determinante para o mais específico, de propósito.

**Por que `authenticate` prioridade 30 e não `wp_authenticate_user`:** `authenticate` é a cadeia canônica, roda depois das checagens de credencial do core (então não gastamos uma chamada ao Google numa senha errada, o que também economiza cota), e devolver `WP_Error` ali faz o `wp-login.php` re-renderizar o formulário **com o username preservado** — que é FR-09 atendido sem código extra.

### 6.4 Recusa

`WP_Error` com código próprio por classe de falha:

| Classe | Código | Efeito |
|---|---|---|
| REJECTED | `wrf_login_blocked` | mensagem padrão de bloqueio |
| CLIENT_UNREACHABLE + `block` | `wrf_login_client_unreachable` | mensagem da §1.7, acionável |
| INFRA + `block` | `wrf_login_unavailable` | "verificação indisponível, tente novamente" |
| MISCONFIG | (não bloqueia) | fail-open + notice, §4.3 |

Códigos distintos porque `login_errors` é filtrável por temas e plugins de segurança, e um código genérico impede que o operador distinga "bot barrado" de "meu adblock quebrou meu login".

### 6.5 Alcance sobre os outros formulários de `wp-login.php`

`wp-login.php` hospeda quatro fluxos. O `login_form`/`authenticate` cobre **só** o de login. Os demais já estão contemplados no v1 com hooks próprios, e a tabela existe para que o @dev não os confunda:

| Fluxo | Render | Validação | `form_id` |
|---|---|---|---|
| Login | `login_form` | `authenticate` (§6.3) | `wp_login` |
| Registro | `register_form` | `registration_errors` | `wp_register` |
| Lost password | `lostpassword_form` | `lostpassword_post` | `wp_lostpassword` |
| Reset password | — | — | **fora de escopo v1**: chega por link com chave no e-mail, que já é o fator de autenticação |

Toggles independentes por `form_id`, como pede FR-12. Um operador pode proteger registro e não login — e depois de BL-03 e BL-04, esta é a configuração conservadora que a documentação vai recomendar para quem tem CMP.

### 6.6 Testes

1. `applies()` retorna `false` com cada uma de `XMLRPC_REQUEST`, `REST_REQUEST`, `WP_CLI`, cron, GET, ausência de `wp-submit`, e usuário logado. Sete asserções, unitárias, sem rede.
2. Integração: `wp_authenticate()` com credenciais válidas e **sem** nenhum campo do plugin, sob `XMLRPC_REQUEST` → retorna `WP_User`, não `WP_Error`. É a prova de que Jetpack e o app oficial continuam funcionando, e é o teste que faltava.
3. Integração: POST em `wp-login.php` com `wp-submit` e token vazio, `cstate` vazio → `WP_Error` com código `wrf_login_blocked`.
4. Integração: mesmo POST com `cstate=no-script` e política `allow` → autentica.

---

## 7. A máquina de estados completa — substitui §5.1 e §5.4 do v1

### 7.1 Quatro classes

| Classe | Pergunta que responde | Quem é o dono do problema | Política | Escalada no admin |
|---|---|---|---|---|
| **REJECTED** | O Google respondeu e reprovou | o visitante (ou o bot) | **BLOCK sempre. Não configurável.** | não |
| **INFRA** | O servidor não alcançou o Google | rede/infra; o operador pode agir | configurável, 2 níveis, default **ALLOW** | não (só `error_log` sob `WP_DEBUG`) |
| **MISCONFIG** | As chaves estão erradas | o operador | **ALLOW** + escalada máxima; fail-close opt-in por filtro | **sim, `error`, não-dispensável** |
| **CLIENT_UNREACHABLE** | O navegador não alcançou o Google | ninguém — é o mundo | configurável, 2 níveis, default **ALLOW** | não |

A quarta linha é **ortogonal** às três anteriores, e é por isso que a máquina não ficou mais complexa: as outras três são determinadas por **o que o Google respondeu**, e esta é determinada por **não ter havido chamada ao Google**. Não há sobreposição possível, e nenhuma regra das três antigas precisou mudar.

### 7.2 Ordem de avaliação — normativa, `Gate::assess()`

Sequencial, primeira condição que casar decide. Esta ordem é a especificação; qualquer outra produz um bug diferente.

```
0. WRF_DISABLE definido?                → nem chega aqui (§5.2, o hook não existe)
1. toggle do form_id desligado?         → ALLOW (não é falha; não conta em lugar nenhum)
2. memoização por hash do token (§6.4)  → retorna veredito idêntico, zero rede
3. secret ausente na config?            → MISCONFIG        (sem chamar o Google)
4. token vazio?
     4a. cstate ∈ {no-script,
         consent-pending, exec-error}   → CLIENT_UNREACHABLE (sem chamar o Google)
     4b. senão                          → REJECTED           (sem chamar o Google)
5. chamar o Provider → ProviderResponse
6. classificar pela tabela da §4.2      → INFRA | MISCONFIG | REJECTED | sucesso
7. aplicar política efetiva (§7.3) e montar o Verdict imutável
```

Os passos 3, 4a e 4b **não gastam requisição de rede** — preserva a otimização de cota da §13 do v1, agora estendida ao caso do cliente sem Google, que é o mais frequente dos três.

O passo 2 antes do 4 é deliberado: sob memoização, um segundo `assess()` no mesmo request devolve o mesmo veredito mesmo com token vazio, evitando que dois hooks do Woo produzam duas classificações para a mesma submissão.

### 7.3 Resolução da política efetiva — substitui §5.4 do v1

```
efetiva( form, classe ) =
    REJECTED            → BLOCK                                                (constante)
    MISCONFIG           → filtro misconfig_policy ?? ALLOW  + escalar          (§4.3)
    INFRA               → override_infra(form)  ?? global_infra  ?? ALLOW      (PRD §4.3)
    CLIENT_UNREACHABLE  → filtro client_unreachable_policy
                          ?? override_cu(form) ?? global_cu ?? ALLOW           (§1.4)
```

Armazenamento inalterado em espírito: `'inherit' | 'allow' | 'block'` por formulário, com `inherit` **gravado explicitamente** (nunca `null`), para a UI poder mostrar "herdando: permitir" sem ambiguidade — exigência literal do PRD §4.3, agora valendo para dois eixos de política em vez de um.

### 7.4 A tela de config, com dois eixos

O risco óbvio de duas políticas é dobrar a UI e confundir a persona P1. Desenho:

- **Nível global:** dois selects numa seção "Quando a verificação não for possível", com rótulos em linguagem de operador, não de arquiteto:
  - *"Se o nosso servidor não conseguir falar com o Google"* → INFRA
  - *"Se o navegador do visitante não conseguir carregar o reCAPTCHA (bloqueador de anúncios, cookies recusados)"* → CLIENT_UNREACHABLE
- **Nível por formulário:** uma única coluna "Quando falhar" com quatro opções — `Herdar` / `Permitir ambos` / `Bloquear ambos` / `Personalizado…`. Os dois selects separados só aparecem em `Personalizado`.

Isto atende ao PRD §4.3 (dois níveis, override por formulário) sem impor duas decisões por linha a quem tem nove integrações. **Progressive disclosure é decisão de UI, não de arquitetura** — registro como recomendação para @ux/@pm, não como requisito. O que é arquitetural e não negociável: o **armazenamento** tem os dois eixos independentes desde o primeiro dia, porque unificá-los depois é migração de opção, e migração de opção é MAJOR (§11 do v1).

---

## 8. Consequências no resto do plano

### 8.1 Arquivos novos e alterados (delta sobre a §10 do v1)

```
src/
├── Provider/
│   └── FailureClass.php            ALTERADO: + CLIENT_UNREACHABLE (4 casos)
│   └── AbstractSiteverifyProvider.php  ALTERADO: classify() pela tabela §4.2
├── Gate/
│   ├── Gate.php                    ALTERADO: ordem de avaliação §7.2, leitura de cstate
│   ├── FailurePolicy.php           ALTERADO: dois eixos configuráveis §7.3
│   └── ClientState.php             NOVO: vocabulário fechado do cstate (§1.2)
├── Frontend/
│   ├── FieldRenderer.php           ALTERADO: + hidden wrf_cstate
│   ├── AssetManager.php            ALTERADO: gating de consentimento §2.5
│   └── Messages.php                ALTERADO: + unreachable, + privacy_notice() §3.3
├── Consent/                        NOVO (§2)
│   ├── ConsentGate.php             resolução em 5 passos §2.3
│   └── WpConsentApiBridge.php      adesão à WP Consent API (~4 linhas úteis)
├── Privacy/                        NOVO (§3)
│   ├── PrivacyNotice.php           texto montado por configuração (FR-31/FR-32)
│   └── PrivacyShortcode.php        shortcode + wp_add_privacy_policy_content
├── Admin/
│   ├── Notices.php                 ALTERADO: + warning de keypair_suspect §4.4,
│   │                                          + warning de WRF_DISABLE §5.2
│   └── SiteHealth.php              ALTERADO: + estado desativado (recommended)
├── Integrations/Core/
│   └── LoginIntegration.php        ALTERADO/ESPECIFICADO: §6 inteira
└── DisabledMode.php                NOVO: bootstrap curto do kill switch §5.2

assets/js/src/
├── frontend.js                     ALTERADO: §1.3 + loader sob demanda §2.5 + aviso §1.6
└── blocks-checkout.js              ALTERADO: mesmo tratamento de cstate no onCheckoutValidation
```

**O Blocks precisa do mesmo tratamento e é fácil de esquecer:** `onCheckoutValidation` (§8.3 do v1) tem que enviar `extensionData.cstate` junto do `token`, e o schema da Store API ganha o segundo campo. Sem isso, o checkout Blocks bloqueia visitantes com adblock enquanto o clássico os deixa passar — divergência entre os dois caminhos, que é exatamente o que a §8.1 do v1 existe para impedir. **Item obrigatório da story W-2.**

### 8.2 Gates de CI

O gate de fronteira da §4.5 do v1 permanece **sem alteração e agora protege mais**: a tabela da §4.2 concentra ainda mais vocabulário do Google dentro de `src/Provider/`, então o grep que proíbe `invalid-input-` fora da fronteira ficou mais valioso, não menos.

Um gate novo, na mesma economia de duas linhas de shell — o vocabulário do `cstate` é nosso, e a tentação de espalhar `$_POST['wrf_cstate']` pelos adaptadores é real:

```bash
# cstate é lido em um único lugar; adaptadores nunca tocam em $_POST diretamente
! grep -rn "wrf_cstate" --include=*.php src/ | grep -v -e '^src/Gate/ClientState.php' \
                                                      -e '^src/Frontend/FieldRenderer.php'
```

Isto responde também ao **BL-06** (nome do campo de token no v2) pelo mesmo mecanismo, e por isso vale a pena declará-lo aqui embora BL-06 não estivesse no meu escopo: existe **um** coletor, `ClientState`/`TokenCollector`, que sabe se lê `wrf_token` ou `g-recaptcha-response` conforme a versão ativa, e os adaptadores recebem `FormContext` pronto. Nenhum adaptador toca em `$_POST`. O @sm deve tratar isso como parte da story de fundação do `Gate`.

### 8.3 Gate de release — itens novos no `release-checklist.md`

1. **Adblock (BL-01, item 3 do @qa):** com uBlock Origin e lista anti-Google ativos, submeter um formulário protegido. Com política `allow`: passa, e o aviso da §1.6 aparece. Com política `block`: recusa com a mensagem da §1.7, não com a de REJECTED. **Este é o passo que prova que a quarta classe existe de verdade.**
2. **CMP:** com Complianz instalado e `consent_mode=required`, antes do aceite o `api.js` **não** está no DOM; após o aceite via `grantConsent()`, o submit seguinte tem token.
3. **Kill switch:** `define('WRF_DISABLE', true)` → nenhum campo `wrf_*` no HTML de login e de checkout, notice presente, login funciona.
4. **XML-RPC:** `wp.getUsersBlogs` com credenciais válidas e proteção de login ligada → sucesso.
5. **Privacidade:** o shortcode renderiza; o texto aparece em Configurações → Privacidade; o bloco muda ao alternar o `remoteip`; as URLs do Google respondem 200.

### 8.4 Impacto no fatiamento — nota para o @sm

- **Fundação cresce**, e é o lugar certo para isso crescer: `FailureClass` com quatro casos, `ClientState`/coletor único, `FailurePolicy` de dois eixos e o **kill switch** entram na fundação. Nada disso é retrofit barato depois de nove integrações escritas.
- **`ConsentGate` e `PrivacyNotice` são stories independentes** e paralelizáveis — não bloqueiam nem são bloqueadas pelas integrações. `PrivacyNotice` é a candidata natural a primeira story de quem estiver entrando no projeto: superfície pequena, testabilidade alta, zero acoplamento.
- **A story de login (§6) deixou de ser ambígua.** Tem hook nomeado, condição de aplicabilidade fechada, mecanismo de recusa e seis testes especificados. Era a story de maior risco de escolha errada silenciosa; agora é uma das mais roteirizadas.
- **W-2 (Store API) ganha escopo:** o campo `cstate` no schema e no `onCheckoutValidation`, conforme §8.1.
- **Ordem inalterada em relação à §15 do v1**, com o kill switch e o coletor único puxados para a primeira story de fundação.

### 8.5 Impacto no versionamento (§11 do v1)

Aos gatilhos de MAJOR já listados, acrescento dois que esta versão cria:

- mudar o **default** de `CLIENT_UNREACHABLE` de ALLOW para BLOCK — é mudança silenciosa de postura em instalações existentes, a mesma razão pela qual tornar REJECTED configurável já era MAJOR;
- unificar os dois eixos de política de falha num só, ou alterar o vocabulário fechado do `cstate` — ambos são migração de formato de opção.

### 8.6 O que continua aberto (não regride nada desta versão)

Fora do escopo destas quatro decisões, e mantido como estava no encaminhamento do @qa: **BL-05** (critério do pseudo-locale), **BL-06** (endereçado em espírito pela §8.2, mas a story precisa escrevê-lo), **BL-07** (operacional, ownership do repo e `.git` sob o webroot), condição (a)-1 do espanhol (`.l10n.php` no fallback `es_*`), **OB-01** (status HTTP do FR-09), **OB-02** (cardinalidade das mensagens), **OB-04**, **OB-05**, **OB-06**, e **R-09** (versionamento de schema de opções — que ficou mais urgente, porque esta versão acrescenta opções novas: `consent_mode`, os dois eixos de política, `wp_recaptcha_forms_keypair_suspect`).

`OB-03` foi **acatado e fechado** na §4.2 (`bad-request` → MISCONFIG).

---

## 9. Quadro de fechamento dos bloqueios

| Bloqueio | Decisão do dono | Onde está implementada | Status |
|---|---|---|---|
| **BL-01** | 4ª classe `CLIENT_UNREACHABLE`, política própria configurável, default fail-open, aviso amigável no formulário | §1 (sinal, JS, classificação, política, mensagens), §7 (máquina de estados), §8.3-1 (gate de release) | **fechado** |
| **BL-01 / privacidade — `api.js` opt-in** | gating de consentimento por hook genérico, sem integração nominal com CMP | §2 (modos, contrato PHP e JS, carregamento sob demanda) | **fechado** |
| **BL-01 / privacidade — texto pronto** | FR novo, formal: shortcode + helper + ferramenta nativa do WP | §3 (FR-31/FR-32, superfícies, conteúdo, testes) | **fechado** |
| **BL-02** | classificar pelo código real em toda chamada; `invalid-input-secret`/`invalid-keys` → MISCONFIG imediato com aviso visível | §4 (tabela normativa, escalada, advisory de par cruzado, matriz de teste) | **fechado** |
| **BL-03** | `define('WRF_DISABLE', true)` checado no bootstrap antes de qualquer hook | §5 (constante, ponto de checagem, modo desativado, precedente, teste) | **fechado** |
| **BL-04** | escopo limitado ao formulário HTML; XML-RPC, REST, WP-CLI e cron explicitamente fora | §6 (`login_form` + `authenticate` com `applies()`, recusa por classe, testes) | **fechado** |

---

## 10. Recado curto para cada agente

- **@sm** — pode fatiar. A fundação cresceu (§8.4) e é intencional. As duas stories novas e independentes são `ConsentGate` e `PrivacyNotice`.
- **@dev** — três coisas que só aparecem em produção: `login_form` e não `login_footer` (§6.2); o `.then()` final do JS é um `finally` lógico, o formulário **sempre** submete (§1.3); e o Blocks precisa do `cstate` no schema, senão diverge do clássico (§8.1).
- **@pm** — numerar FR-31/FR-32 no PRD (§3.1) e registrar os dois novos gatilhos de MAJOR (§8.5). A cardinalidade das mensagens (OB-02) segue pendente e agora tem uma razão a mais: `client_unreachable` é uma quarta `$reason`.
- **@devops** — um gate de grep novo (§8.2) e cinco itens no `release-checklist.md` (§8.3).
- **@qa** — BL-01 a BL-04 fechados aqui, com resposta escrita para cada um. BL-05, BL-06 e BL-07 seguem com você e com o @dev, sem interferência desta versão.
