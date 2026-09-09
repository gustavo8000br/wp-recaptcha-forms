# wp-recaptcha-forms — Arquitetura Técnica v1

**Produto:** plugin WordPress open-source `wp-recaptcha-forms`
**Autor deste documento:** @architect (Aria)
**Data:** 2026-09-08
**Insumos:** `docs/backlog-v1.md` (@po), `docs/prd-v1.md` (@pm, incluindo §4.5 e §4.6)
**Status:** **parcialmente superado por `docs/architecture-v1.1.md`.** A v1.1 fecha BL-01 a BL-04 e o item de privacidade da revisão do @qa e **prevalece** sobre este documento nas §5.1, §5.4, §5.5, §6.2 (bloco `catch`), §8 (nova §8.6 de login) e §13 (linha "Token vazio"). Leia sempre as duas: este documento continua válido em tudo que a v1.1 não contradiz, e a v1.1 declara em §0 exatamente o que substitui.

Este documento resolve apenas o **como técnico**. Nenhuma decisão de produto é reaberta: licença GPLv2-or-later, cobertura de formulários, i18n em pt-BR/en-US/es, checkout Clássico **e** Blocks, dois fluxos de documentação do console Google, seção de contribuição de tradução e barra de progresso são entrada, não pergunta.

Duas coisas que **descobri durante o desenho e que o @pm precisa saber** — não são reabertura de decisão, são a decisão batendo na realidade da plataforma:

1. **Não existe locale `es` no WordPress.** A lista de locales tem `es_ES`, `es_MX`, `es_AR`, `es_CL`, `es_CO`, `es_PE`, `es_VE` etc., mas não um `es` puro. "Espanhol genérico" não pode ser um nome de arquivo. A intenção do PRD (um único catálogo, sem variantes regionais para manter) é preservável — a implementação está em §9.3, com um mapa de fallback que faz `es_MX`, `es_AR`, ... carregarem o mesmo catálogo. O que muda é só o nome do arquivo, não o compromisso.
2. **`en_US` é o idioma-fonte**, e um `.mo` de en_US é, por construção, uma tradução identidade. O script de progresso precisa tratá-lo como 100% por definição, senão reporta 0% e o gate S-18 falha para sempre. Detalhe em §9.5.

---

## 1. Princípios de desenho

Cinco regras que explicam quase toda decisão abaixo. Quando houver dúvida numa story, resolva por elas.

| # | Princípio | Consequência prática |
|---|---|---|
| **A1** | **Uma única decisão de bloqueio no sistema.** Existe exatamente um lugar que decide "passa ou não passa". Todo o resto é adaptador. | Clássico e Blocks, comentário e login, Woo e Newsletter compartilham `Gate::assess()`. Adaptadores só coletam token e traduzem veredito para o idioma de erro do host. |
| **A2** | **Nada do Google atravessa a fronteira.** Nome de endpoint, `error-codes`, formato de resposta e nome de campo do Google existem em um único diretório. | Trocar `siteverify` por Enterprise é reescrever uma classe, não caçar strings (mitiga R-04). Há gate de CI que verifica isso (§4.5). |
| **A3** | **Nada específico de usuário no HTML.** O plugin nunca imprime token, nonce por usuário ou dado variável no markup do formulário. | Cache de página deixa de ser um problema em vez de virar uma exclusão a documentar (resolve D-06/R-01). |
| **A4** | **Dependência opcional é opcional de verdade — e o risco não é `class_exists`, é `implements`.** | Arquivos que `extends`/`implements` classe de terceiro só são carregados dentro do guard. Sem `Requires Plugins: woocommerce` no header. §7. |
| **A5** | **A falha do plugin nunca pode ser pior que o ataque que ele previne.** | Fail-open é default para indisponibilidade do Google; erro do operador não derruba o site, mas grita no admin. §5. |

---

## 2. Visão geral em camadas

```
┌────────────────────────────────────────────────────────────────────┐
│ ADAPTADORES (integrations/)                                        │
│  nativos WP        Newsletter      WooCommerce (opcional)          │
│  comment           subscribe       review | checkout clássico      │
│  register                                 | checkout Blocks        │
│  lostpassword                             | lost password          │
│  login                          API pública (helpers p/ terceiros) │
└───────────────┬────────────────────────────────────────────────────┘
                │ FormContext { form_id, action, token, request }
                ▼
┌────────────────────────────────────────────────────────────────────┐
│ NÚCLEO — Gate (src/Gate/)                                          │
│  Gate::assess(FormContext) : Verdict                               │
│   · toggle do formulário ativo?      · memoização por token (§6.4) │
│   · threshold global (v3)            · classificação da falha (§5) │
│   · política de falha efetiva        · Verdict imutável            │
└───────────────┬────────────────────────────────────────────────────┘
                │ VerificationRequest
                ▼
┌────────────────────────────────────────────────────────────────────┐
│ FRONTEIRA GOOGLE (src/Provider/) — o único código que sabe que     │
│ existe um Google                                                   │
│  ProviderInterface  →  SiteverifyV3 | SiteverifyV2 | (Enterprise)  │
│  HttpTransport (wp_remote_post)   ProviderResponse (normalizado)   │
└────────────────────────────────────────────────────────────────────┘

     Admin (src/Admin/)          Assets (assets/)           i18n (languages/ + bin/)
     Settings API, notices,      token no submit,           .pot/.po/.mo/.l10n.php,
     Site Health, validação      nunca no HTML              progresso gerado
     de chave no save
```

Fluxo de uma submissão protegida (v3, checkout clássico como exemplo):

```
usuário clica "Finalizar compra"
  └─ JS intercepta o submit (capture), chama grecaptcha.execute(siteKey, {action})
       └─ preenche o input hidden vazio e re-dispara o submit
            └─ PHP: adaptador Woo monta FormContext e chama Gate::assess()
                 └─ Gate → Provider → siteverify → ProviderResponse
                      └─ Verdict{ALLOW|BLOCK, motivo, mensagem traduzida}
                           └─ adaptador traduz: wc_add_notice() / WP_Error / RouteException
```

---

## 3. Requisitos de plataforma — resolve D-04

| Item | Decisão | Justificativa |
|---|---|---|
| **PHP mínimo** | **7.4** | Habilita `?:` nullable types, arrow functions e propriedades tipadas — suficiente para o estilo que este código pede. Exigir 8.0 excluiria hospedagem compartilhada barata, que é exatamente onde vive a persona P1. Exigir 7.2 obrigaria a escrever num dialeto sem tipagem de propriedade por ganho marginal. |
| **WordPress mínimo** | **6.0** | Cobre o parque real sem carregar compatibilidade morta. Recursos usados acima de 6.0 são todos opcionais e degradam sozinhos: `.l10n.php` (6.5+) é ignorado por versões antigas, que caem no `.mo`. |
| **WooCommerce — checkout clássico** | **7.0+** | Hooks usados (`woocommerce_after_checkout_validation`, `woocommerce_review_order_before_submit`) são estáveis há muito mais tempo; 7.0 é piso conservador para não ter de testar arqueologia. |
| **WooCommerce — checkout Blocks** | **8.3+** | Piso separado, detectado em runtime. Abaixo disso o adaptador Blocks simplesmente não registra e a UI marca a linha como indisponível. Um piso único puxaria o clássico para cima sem necessidade. |
| **Matriz de teste (FR-18/S-08)** | PHP 7.4 / 8.1 / 8.3 × WP 6.0 / WP latest, com Woo latest no eixo de integração | 6 combinações no unitário. Não multiplicar por versões de Woo: o adaptador Woo é testado só no latest, com os pisos verificados por asserção de `version_compare`, não por matriz. |

`Requires at least: 6.0` e `Requires PHP: 7.4` vão no header (FR-20). **`Requires Plugins` fica de fora** — declarar WooCommerce ali o transformaria em dependência dura e violaria FR-13/A4.

Elevar qualquer um destes pisos é **MAJOR** pela regra do backlog §4.

---

## 4. A fronteira do Google — resolve o isolamento pedido em R-04

### 4.1 Contrato

Três tipos e uma interface. Nada mais atravessa.

```php
// src/Provider/ProviderInterface.php
interface ProviderInterface {
    /** @throws never — falha vira ProviderResponse, nunca exceção */
    public function verify( VerificationRequest $request ): ProviderResponse;
    public function version(): string;            // 'v2' | 'v3'
    public function script_url( string $site_key, string $locale ): string;
}
```

```php
// src/Provider/VerificationRequest.php   (imutável)
//   token, secret, remote_ip|null, expected_action|null, expected_hostname|null

// src/Provider/ProviderResponse.php      (imutável — a saída normalizada)
final class ProviderResponse {
    public function reached_provider(): bool;   // o Google respondeu algo inteligível?
    public function is_success(): bool;         // campo success da resposta
    public function score(): ?float;            // null em v2
    public function action(): ?string;
    public function hostname(): ?string;
    public function failure(): ?FailureClass;   // §5 — INFRA | MISCONFIG | REJECTED
    public function debug_codes(): array;       // error-codes crus, SÓ para log/admin
}
```

`debug_codes()` é a única fuga controlada de vocabulário do Google, e ela vai só para log e tela de admin — **nunca** para lógica de decisão e **nunca** para o usuário final. Se alguém escrever `if ( in_array( 'invalid-input-secret', $codes ) )` fora de `src/Provider/`, o desenho já vazou.

### 4.2 Implementações

- `SiteverifyV3` e `SiteverifyV2` compartilham `AbstractSiteverifyProvider` — mesmo transporte, mesmo parsing, diferença em score e em `expected_action`.
- `EnterpriseProvider` **não é escrito na v1** (está fora de escopo pelo PRD §6). O que a v1 entrega é o encaixe: uma interface que ele poderia implementar sem tocar em mais nada. Se o Google desligar o `siteverify` clássico (R-04), o custo é uma classe nova mais uma linha na factory.

### 4.3 Transporte e testabilidade

```php
interface TransportInterface {
    public function post( string $url, array $body, array $args ): TransportResult;
}
```

`WpHttpTransport` embrulha `wp_remote_post()` com `timeout => 5` (filtrável), `redirection => 0`, `sslverify => true` e `user-agent` identificando o plugin. Nos testes, injeta-se um `FakeTransport` — sem depender do hook global `pre_http_request`, que polui o estado entre testes e é a razão de suítes de plugin WordPress ficarem flaky. Isso é o que torna FR-18/S-08 barato.

**Timeout de 5s é decisão deliberada e é um trade-off de UX**, não um número arbitrário: o `siteverify` responde tipicamente em <300ms; 5s é margem de rede ruim sem transformar a indisponibilidade do Google em 30s de spinner no checkout. Filtrável para quem tem rede pior.

### 4.4 Ponto único de configuração de endpoint

```php
// src/Provider/Endpoints.php — o ÚNICO arquivo do repositório com esta string
const SITEVERIFY = 'https://www.google.com/recaptcha/api/siteverify';
const SCRIPT_V3  = 'https://www.google.com/recaptcha/api.js';
// filtro documentado, para quem precisa de recaptcha.net (China/bloqueios regionais)
apply_filters( 'wp_recaptcha_forms_endpoint', $url, $which );
```

O filtro `wp_recaptcha_forms_endpoint` resolve de graça um caso real de suporte (usuários em redes que bloqueiam `google.com` usam `recaptcha.net`) e é o mesmo mecanismo de escape se o Google mudar o host.

### 4.5 Gate de CI que protege a fronteira

Um princípio sem verificação vira comentário decorativo. No CI:

```bash
# falha se a string do endpoint aparecer fora de src/Provider/
! grep -rl "recaptcha/api" --include=*.php src/ | grep -v '^src/Provider/'
# falha se vocabulário de erro do Google aparecer fora da fronteira
! grep -rn "invalid-input-\|timeout-or-duplicate\|error-codes" --include=*.php src/ \
    | grep -v '^src/Provider/'
```

Custo: duas linhas de shell. Retorno: A2 continua verdadeiro daqui a dois anos, quando ninguém lembrar deste documento.

---

## 5. Taxonomia de falhas e política de bloqueio — resolve D-03 e o "como" do PRD §4.3

O PRD fixou: **configurável em dois níveis (global + override por formulário), default fail-open**. E delegou explicitamente a pergunta certa: *falha de configuração deve seguir a mesma política que falha de infraestrutura?*

**Resposta: não.** Colapsar as duas num único toggle é o erro de desenho que produz os dois piores modos de falha possíveis — ou o site cai por causa de um typo do operador, ou o site fica desprotegido em silêncio por meses. Três classes, três políticas.

### 5.1 As três classes

| Classe | O que é | Sinal técnico | Política |
|---|---|---|---|
| **REJECTED** | **O Google respondeu e reprovou.** Score abaixo do threshold, token ausente, token malformado, token já usado, action divergente, hostname divergente. | `success:false` com `missing-input-response`, `invalid-input-response`, `timeout-or-duplicate`, `bad-request`; ou `success:true` com score < threshold / action ≠ esperada | **Sempre bloqueia. Não configurável.** |
| **INFRA** | **O Google não respondeu ou não pôde responder.** Timeout, DNS, conexão recusada, 5xx, 429, JSON inválido, resposta vazia. | `WP_Error` do `wp_remote_post`, HTTP ≥500, HTTP 429, corpo não-JSON | **Configurável.** Global default fail-open; override por formulário. |
| **MISCONFIG** | **Erro do operador.** Secret vazia, secret inválida, par site/secret trocado, chave de outro tipo. | `invalid-input-secret`, `invalid-keys`, secret ausente na config | **Fail-open no front + escalada máxima no admin.** Ver §5.3. |

### 5.2 Por que REJECTED não é configurável

Este é o ponto que o toggle único apaga. `timeout-or-duplicate` e `missing-input-response` **não são indisponibilidade do Google** — são "não veio token" ou "esse token já foi usado". É exatamente o que um bot produz. Se o toggle de fail-open cobrisse esta classe, ligar fail-open (o **default**) desativaria o plugin inteiro por omissão: qualquer bot que simplesmente não enviasse o campo passaria direto.

Isso não é hipótese; é como o toggle seria naturalmente implementado por quem trata "falhou a verificação" como um bucket só. Um plugin de segurança cujo default o desliga é pior que não ter plugin, porque o operador acredita estar protegido. **Não configurável, sem filtro, sem exceção.**

### 5.3 Por que MISCONFIG é fail-open no front, e por que isso não é "fail-open silencioso"

O cenário: operador cola a secret errada. Ou o par site/secret é de projetos diferentes. O plugin **não consegue verificar nada**.

- *Fail-close* aqui derruba comentário, login, lost-password e **checkout** ao mesmo tempo, por um erro de digitação. Para a persona P1 — que não é desenvolvedor e vai descobrir pelo cliente que não conseguiu comprar — isso é o modo de falha catastrófico do S-11 ("nenhuma issue de site quebrado" é o critério que mais importa do PRD §8).
- *Fail-open silencioso* deixa o site sem proteção nenhuma sem ninguém saber. Também inaceitável.

A saída não é escolher entre os dois: é **fail-open no front + tornar impossível o operador não saber**, em quatro camadas.

1. **Validação no save (prevenção).** Ao salvar as chaves na tela de config, o plugin faz um `siteverify` de sonda com um token deliberadamente inválido. A resposta esperada é `success:false` com `invalid-input-response` — isso prova que a **secret** é válida. Se voltar `invalid-input-secret`, a tela recusa o save com erro no campo. **É aqui que 95% dos MISCONFIG morrem, antes de chegar em produção.** Um `siteverify` a mais por save é custo irrelevante.
2. **Admin notice persistente e não-dispensável.** Se um MISCONFIG ocorrer em runtime, `wp_recaptcha_forms_misconfig_since` é gravado e um notice de nível `error` aparece em **todas** as telas do admin, com o `debug_codes()` e link direto para a config. Não tem botão de dispensar — some quando o problema é corrigido, não quando o operador se irrita.
3. **Teste de Site Health.** Um teste `critical` em Ferramentas → Saúde do site, para aparecer também em monitoramento externo e em relatórios de agência (persona P2).
4. **Modo degradado visível.** A tela de config mostra o estado do plugin no topo: `Protegendo` / `Degradado — não verificando` / `Desativado`. O operador que abrir a tela vê a verdade em um segundo.

**Escape hatch para quem quer postura estrita, sem UI:**

```php
// documentado no README, sem controle na tela — é decisão de desenvolvedor, não de operador
add_filter( 'wp_recaptcha_forms_misconfig_policy', fn() => 'block' );
```

Deliberadamente fora da UI. Um operador que marque "bloquear quando a chave estiver errada" sem entender a consequência está armando uma bomba-relógio no próprio checkout; um desenvolvedor de agência escrevendo isso no `functions.php` sabe o que está fazendo. Custo do filtro: uma linha. Benefício: o caso P2 legítimo é atendido sem expor a armadilha à P1.

### 5.4 Resolução da política efetiva

```
efetiva(form) =
    REJECTED  → BLOCK                                        (constante)
    MISCONFIG → filtro misconfig_policy ?? ALLOW + escalar   (§5.3)
    INFRA     → override_do_form ?? global ?? ALLOW          (dois níveis do PRD §4.3)
```

Armazenamento do override: `'inherit' | 'allow' | 'block'` por formulário, com `inherit` como default gravado. Guardar `inherit` explicitamente, e não `null`, permite que a UI mostre "herdando: permitir" (exigência literal do PRD §4.3) sem ambiguidade entre "não escolhido" e "escolhido igual ao global".

### 5.5 Como isso vira teste (S-06)

Com `FakeTransport` (§4.3), cada célula desta matriz é um teste unitário de poucas linhas — sem rede, sem mock global:

| Cenário do fake | Classe esperada | global=allow | global=block | override=block |
|---|---|---|---|---|
| `WP_Error('http_request_failed')` | INFRA | ALLOW | BLOCK | BLOCK |
| HTTP 503 | INFRA | ALLOW | BLOCK | BLOCK |
| HTTP 429 | INFRA | ALLOW | BLOCK | BLOCK |
| `{"success":false,"error-codes":["invalid-input-secret"]}` | MISCONFIG | ALLOW + notice | ALLOW + notice | ALLOW + notice |
| `{"success":false,"error-codes":["timeout-or-duplicate"]}` | REJECTED | **BLOCK** | BLOCK | BLOCK |
| `{"success":false,"error-codes":["missing-input-response"]}` | REJECTED | **BLOCK** | BLOCK | BLOCK |
| `{"success":true,"score":0.3,"action":"login"}` (thr 0.6) | REJECTED | BLOCK | BLOCK | BLOCK |
| `{"success":true,"score":0.9,"action":"outra"}` | REJECTED | BLOCK | BLOCK | BLOCK |
| `{"success":true,"score":0.9,"action":"login"}` | — | ALLOW | ALLOW | ALLOW |

As duas células em negrito são a regressão que este desenho existe para prevenir. Se algum dia virarem ALLOW, o plugin está desligado.

**429 classificado como INFRA, não MISCONFIG**, apesar de quota ser tangencialmente configuração: é transitório, o operador não tem ação imediata, e tratá-lo como MISCONFIG dispararia notice crítico permanente por um pico de tráfego. Quota estourada **recorrente** merece aviso — mas isso é telemetria de contagem, que está fora do escopo v1 (PRD §6 exclui log/estatística). Registrado como candidato v1.x.

---

## 6. Cache de página — resolve D-06 e R-01

### 6.1 A causa real

O bug clássico não é "o token expira". É **o token estar no HTML**. Um token de 2 minutos dentro de uma página guardada por 10 horas pelo WP Rocket é servido expirado para todo visitante, e o formulário reprova gente legítima em silêncio — falha silenciosa, porque a página parece normal.

### 6.2 O desenho: HTML estático, token em tempo de submit

O plugin imprime no formulário apenas isto:

```html
<input type="hidden" name="wrf_token" value="">
<input type="hidden" name="wrf_action" value="wrf_login">
```

Valor vazio. Nada específico de usuário, nada com validade (A3). **Esse HTML é eternamente cacheável e idêntico para todo visitante.**

O JS intercepta o submit, obtém o token na hora e reenvia:

```js
form.addEventListener('submit', function (e) {
  if (form.dataset.wrfReady === '1') return;      // 2ª passada: deixa seguir
  e.preventDefault();
  grecaptcha.ready(function () {
    grecaptcha.execute(siteKey, { action: form.querySelector('[name=wrf_action]').value })
      .then(function (token) {
        form.querySelector('[name=wrf_token]').value = token;
        form.dataset.wrfReady = '1';
        if (form.requestSubmit) form.requestSubmit(submitter); else form.submit();
      })
      .catch(function () {                         // grecaptcha indisponível no cliente
        form.dataset.wrfReady = '1';               // envia vazio → servidor decide (§5)
        if (form.requestSubmit) form.requestSubmit(submitter); else form.submit();
      });
  });
});
```

Três detalhes que só aparecem em produção:

- **`requestSubmit()` e não `submit()`** quando disponível: `form.submit()` não dispara handlers de outros plugins nem validação HTML nativa, e quebra integrações de terceiros de forma difícil de diagnosticar. Fallback para `submit()` só em navegador antigo.
- **Preservar o `submitter`.** Formulários com múltiplos botões (`name=save` vs `name=publish`; Woo com "atualizar carrinho" e "finalizar") perdem qual botão foi clicado se resubmetidos sem ele. Capturado do evento `submit`.
- **O `catch` envia token vazio de propósito.** Se `grecaptcha` não carregou (adblock, rede), o cliente **não** decide nada. Ele envia vazio, o servidor classifica como REJECTED e bloqueia. A decisão de bloquear nunca é do navegador — o cliente é hostil por definição.

Isso significa que a política de INFRA do §5 cobre a indisponibilidade **do servidor para o Google**, e o cliente que não consegue falar com o Google é bloqueado. É assimétrico de propósito: a alternativa (deixar o cliente sinalizar "não consegui carregar o reCAPTCHA, me deixa passar") é um bypass trivial.

### 6.3 O que ainda precisa ser documentado como incompatibilidade

O token deixou de ser o problema. Sobra um problema diferente e real: **plugins de cache que atrasam, combinam ou adiam JavaScript** (LiteSpeed "Delay JS", WP Rocket "Delay JavaScript execution", Autoptimize "aggregate JS", Cloudflare Rocket Loader). Se `api.js` ou nosso handler forem adiados até a primeira interação, o listener de submit pode não existir quando o usuário clica.

Mitigação em três níveis:

1. **Marcar os scripts nas convenções que esses plugins respeitam** — `data-cfasync="false"`, `data-no-optimize="1"`, `data-no-defer="1"`, `data-nowprocket` — via `script_loader_tag`. Cobre a maioria das instalações sem o usuário fazer nada.
2. **Defesa em profundidade no HTML:** o botão de submit é o gatilho, mas o listener é registrado em `document` na fase de captura, não no `<form>`. Sobrevive a formulários re-renderizados por outros scripts.
3. **Documentar o resto como incompatibilidade conhecida.** Seção "Compatibilidade com plugins de cache" no README, com os nomes das opções a excluir em WP Rocket, LiteSpeed, Autoptimize e Cloudflare. Instrução nomeada; "desative o cache" não é instrução.

**Explicitamente não fazemos:** definir `DONOTCACHEPAGE` nem pedir exclusão de páginas do cache. Seria admitir que o desenho de A3 falhou, e penalizaria a performance do site inteiro por um problema que não existe mais. Isso vai escrito no README, porque é o conselho que o usuário vai encontrar em fóruns e vai querer seguir.

### 6.4 A armadilha do token de uso único

**Um token do reCAPTCHA só pode ser verificado uma vez.** A segunda chamada ao `siteverify` com o mesmo token retorna `timeout-or-duplicate` — que a §5 classifica, corretamente, como REJECTED.

Isso é uma bomba armada, porque hooks do WordPress e do WooCommerce rodam mais de uma vez com naturalidade: `woocommerce_after_checkout_validation` pode ser reexecutado, e uma story futura pode adicionar um segundo ponto de verificação no mesmo request sem perceber.

Mitigação obrigatória no `Gate`: memoização por request, chaveada por `hash('sha256', $token)`.

```php
// src/Gate/Gate.php
if ( isset( $this->memo[ $key ] ) ) {
    return $this->memo[ $key ];   // mesmo veredito, zero chamada de rede
}
```

Efeito colateral bem-vindo: economiza cota do Google. Está aqui, e não numa nota de rodapé, porque é o tipo de bug que só aparece em produção, no checkout, de forma intermitente.

### 6.5 v2 checkbox sob cache

O widget do v2 é renderizado pelo JS a partir de um `<div class="g-recaptcha" data-sitekey="...">`. A site key é pública e igual para todos → **cacheável**. O `g-recaptcha-response` nasce vazio no HTML → não há token cacheado. A resposta do v2 também expira em ~2 min, e o `api.js` já trata isso chamando `expired-callback` e reexibindo o desafio. Registramos o callback para limpar o campo, e nada mais é necessário. **O v2 é cache-safe pelo mesmo motivo que o v3, com o desenho de A3.**

### 6.6 Como isso vira teste (S-05)

Verificação sob cache não é automatizável de forma barata em CI. O gate S-05 é manual e roteirizado:

1. Instalar WP Rocket **ou** LiteSpeed Cache, com cache de página e Delay JS ligados.
2. Visitar a página do formulário como anônimo (popula o cache) e confirmar `X-Cache: HIT` na segunda visita.
3. **Esperar 5 minutos** — mais que a validade do token. É o passo que a maioria dos testes pula, e é o único que reproduz o bug.
4. Submeter. Deve passar.
5. Inspecionar o HTML cacheado e confirmar: `value=""` no `wrf_token`, nenhum nonce, nenhum dado de usuário.

O passo 5 é o mais valioso: verifica o **invariante** (A3), não só o sintoma. Vira item de checklist de release, não teste ad-hoc.

---

## 7. WooCommerce como dependência opcional — FR-12/FR-13, A4

### 7.1 A armadilha que causa o fatal error

`class_exists('WooCommerce')` é necessário e insuficiente. O erro fatal em plugins que integram com Woo quase nunca vem de chamar uma função inexistente — vem de **um arquivo de classe que `implements` uma interface do Woo ser carregado quando o Woo não está lá**. O PHP resolve `implements` no momento em que a classe é carregada, e falha com fatal antes de qualquer guard rodar.

Nosso `BlocksIntegration` precisa implementar `Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface`. Regra dura:

- Esse arquivo **não** entra no mapa de autoload incondicional.
- É carregado por `require_once` explícito, **dentro** do guard, depois do `interface_exists()`.
- Nenhum outro arquivo do plugin faz `new BlocksIntegration()` fora do guard.
- Uma anotação `@wrf-conditional-load` no topo do arquivo, com um teste em CI verificando que arquivos anotados não aparecem no autoload do Composer.

Type hints de classes do Woo em assinaturas de método são seguros (resolvidos na chamada). `extends`/`implements` não são. A distinção é a diferença entre um plugin que degrada e um que derruba o site — S-03 depende dela.

### 7.2 Bootstrap condicional

```php
// src/Integrations/Registry.php
add_action( 'plugins_loaded', [ $registry, 'boot' ], 20 );   // prio 20: depois do Woo

public function boot(): void {
    $this->register( new Core\CommentIntegration() );        // sempre
    $this->register( new Core\LoginIntegration() );
    // ...
    if ( class_exists( 'WooCommerce' ) ) {
        $this->boot_woocommerce();                           // arquivo separado
    }
    if ( defined( 'NEWSLETTER_VERSION' ) ) {
        $this->register( new Newsletter\SubscribeIntegration() );
    }
}

private function boot_woocommerce(): void {
    $this->register( new WooCommerce\ReviewIntegration() );
    $this->register( new WooCommerce\LostPasswordIntegration() );
    $this->register( new WooCommerce\ClassicCheckoutIntegration() );

    if ( version_compare( WC()->version, '8.3', '>=' )
         && interface_exists( \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface::class ) ) {
        require_once __DIR__ . '/WooCommerce/BlocksCheckoutIntegration.php';  // §7.1
        add_action( 'woocommerce_blocks_checkout_block_registration',
            fn( $registry ) => $registry->register( new WooCommerce\BlocksCheckoutIntegration() ) );
    }
}
```

Cada `Integration` declara `id()`, `label()`, `available(): bool` e `register(): void`. **A tela de config é gerada a partir do registry**, não escrita à mão — é o que faz FR-13 acontecer automaticamente: sem Woo, as integrações Woo nem existem no registry, e a seção não é renderizada. Zero código de UI condicional, zero risco de a seção aparecer vazia.

Integrações indisponíveis por versão (Blocks < 8.3) são um caso diferente: existem no registry com `available() === false`, e a UI as mostra desabilitadas com o motivo ("requer WooCommerce 8.3+"). Ocultar seria pior — o operador ficaria sem entender por que o checkout Blocks não é protegido.

### 7.3 Ativação e desativação do Woo em runtime

`plugins_loaded` resolve o carregamento, mas o estado salvo persiste. Se o Woo for desativado, os toggles Woo continuam gravados. Decisão: **não apagar**. Reativar o Woo restaura a configuração anterior, que é o comportamento esperado. O que **não** pode acontecer é o `Gate` tentar avaliar uma integração cujo host sumiu — resolvido por si só, já que sem Woo o hook nunca dispara.

`uninstall.php` apaga tudo (S-02), inclusive as opções Woo.

---

## 8. Checkout Clássico e Blocks — dois transportes, uma decisão

Este é o item de maior risco do PRD (R-02) e a §4.2 assumiu o custo. O desenho abaixo garante que o custo seja **de adaptador**, não de lógica duplicada — se a regra de bloqueio existir em dois lugares, elas divergem e o gate S-04 vira teatro.

### 8.1 O que é comum

Ambos chamam **a mesma** função:

```php
$verdict = $gate->assess( new FormContext(
    'woocommerce_checkout',      // form_id — mesmo id nos dois caminhos
    'wrf_woocommerce_checkout',  // action v3 — mesma action nos dois caminhos
    $token,
    $request_meta
) );
```

Mesmo `form_id` significa: **um único toggle e um único override de fail-open na tela de config para "Checkout do WooCommerce"**, valendo para os dois. O operador não sabe nem precisa saber que existem dois mecanismos — e não pode configurá-los de forma divergente por acidente.

O que difere é só (a) de onde vem o token e (b) como se comunica a recusa.

### 8.2 Clássico — hooks PHP

| Etapa | Mecanismo |
|---|---|
| Renderizar campo | `woocommerce_review_order_before_submit` → imprime o hidden vazio (§6.2) |
| Coletar token | `$_POST['wrf_token']`, via o mesmo JS genérico |
| Validar | `woocommerce_after_checkout_validation` ( `$data, $errors` ) |
| Recusar | `$errors->add( 'wrf', $verdict->message() )` |

`after_checkout_validation` e não `checkout_process`: recebe o objeto `WP_Error` e integra com o tratamento de erro nativo do Woo, preservando os campos preenchidos (FR-09) sem esforço.

### 8.3 Blocks — Store API + JS

Confirmado contra a documentação oficial do WooCommerce (referências no fim). É um caminho de duas pontas:

**Servidor** — declarar o campo de extensão no schema do checkout:

```php
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

woocommerce_store_api_register_endpoint_data( [
    'endpoint'        => CheckoutSchema::IDENTIFIER,
    'namespace'       => 'wp-recaptcha-forms',
    'schema_callback' => fn() => [ 'token' => [ 'type' => 'string', 'context' => [] ] ],
    'schema_type'     => ARRAY_A,
] );

add_action( 'woocommerce_store_api_checkout_update_order_from_request',
    function ( $order, $request ) use ( $gate ) {
        $token   = $request['extensions']['wp-recaptcha-forms']['token'] ?? '';
        $verdict = $gate->assess( new FormContext( 'woocommerce_checkout',
                       'wrf_woocommerce_checkout', $token ) );   // MESMA chamada do clássico
        if ( $verdict->is_blocked() ) {
            throw new RouteException( 'wrf_blocked', $verdict->message(), 400 );
        }
    }, 10, 2 );
```

`RouteException` é o idioma de erro da Store API — vira resposta 400 que o checkout em Blocks exibe como aviso na UI, em vez de erro genérico de rede.

**Cliente** — registrar um observador de validação e injetar o token no `extensionData`:

- `onCheckoutValidation` roda **depois** do submit e **antes** da requisição ir ao servidor. O observador pode retornar uma `Promise` — que é exatamente o encaixe para `grecaptcha.execute()`. **Este é o análogo do intercept de submit da §6.2, e mantém a propriedade de cache-safety: o token nasce no submit.**
- `setExtensionData( 'wp-recaptcha-forms', 'token', token )` grava no `extensionData`, que a Store API entrega em `$request['extensions']`.

O bundle JS do Blocks é um alvo de build separado (§10), com `@woocommerce/dependency-extraction-webpack-plugin` para não empacotar React nem os pacotes do Woo.

### 8.4 Fatiamento para o @sm (mitigação do R-02)

Três stories, nesta ordem, com o clássico **não** dependendo do Blocks:

| Story | Conteúdo | Depende de |
|---|---|---|
| **W-1** | `FormContext`/`Verdict` + `ClassicCheckoutIntegration` completo | núcleo |
| **W-2** | Registro no schema da Store API + validação server-side (testável via requisição REST, sem UI) | W-1 |
| **W-3** | Bundle JS do Blocks + observador de validação + build | W-2 |

W-2 é testável isoladamente por requisição à Store API com e sem `extensions.wp-recaptcha-forms.token` — sem navegador, sem React. É o que impede que o risco do Blocks fique concentrado num único bloco monolítico no fim.

Se W-3 escorregar, W-1+W-2 já entregam proteção server-side no Blocks; o que faltaria é o cliente enviar o token, o que faria o checkout Blocks bloquear tudo. **Portanto W-3 é obrigatória para o release** — mas o risco é isolado numa story de front, não espalhado.

### 8.5 Reviews e lost-password Woo

| Ponto | Render | Validação | Recusa |
|---|---|---|---|
| Review de produto | `comment_form_after_fields` filtrado para produto | `preprocess_comment` | `wp_die()` com mensagem e link de volta |
| Lost password Woo | `woocommerce_lostpassword_form` | `lostpassword_post` | `WP_Error` |

Review de produto usa o pipeline de comentário do WP; o adaptador de comentário nativo e o de review compartilham código, diferindo só no `form_id` (toggles independentes, como pede FR-12).

---

## 9. Internacionalização — FR-25 a FR-30

### 9.1 Text domain e carregamento

- Text domain: `wp-recaptcha-forms` (igual ao slug — condição para o WP resolver traduções sozinho).
- `Domain Path: /languages` no header.
- `load_plugin_textdomain()` no hook **`init`**, não em `plugins_loaded`.

O ponto do `init` não é estilo. A partir do WP 6.7, carregar tradução cedo demais dispara o notice `_load_textdomain_just_in_time` e, dependendo do caso, a string sai não traduzida. Regra derivada, que precisa entrar na revisão de código de toda story: **nenhuma chamada a `__()` no escopo de arquivo, em constante ou em propriedade de classe** — só dentro de método, executado depois do `init`. Mensagens default vivem em métodos, nunca em `const`.

### 9.2 Estrutura de `languages/`

```
languages/
├─ wp-recaptcha-forms.pot          fonte gerada (en-US), nunca editada à mão
├─ wp-recaptcha-forms-en_US.po     catálogo identidade (§9.5)
├─ wp-recaptcha-forms-en_US.mo
├─ wp-recaptcha-forms-pt_BR.po
├─ wp-recaptcha-forms-pt_BR.mo
├─ wp-recaptcha-forms-pt_BR.l10n.php
├─ wp-recaptcha-forms-es_ES.po     canônico do espanhol (§9.3)
├─ wp-recaptcha-forms-es_ES.mo
└─ wp-recaptcha-forms-es_ES.l10n.php
```

`.po` e `.mo` **entram no repositório e no zip**. Um usuário que baixa o zip do GitHub e sobe pelo painel precisa do `.mo` pronto — exigir build seria transferir trabalho de mantenedor para usuário final. O CI **recompila e compara**: se o `.mo` versionado não bater com o `.po`, o build falha. Assim o binário no repo nunca mente.

`.l10n.php` (WP 6.5+, formato de tradução mais rápido, gerado por `wp i18n make-php`) é gerado no mesmo passo. Versões < 6.5 ignoram e usam o `.mo` — degradação automática, custo zero.

### 9.3 O problema do `es`

**Não existe locale `es` no WordPress.** O PRD pediu "espanhol genérico, sem variantes regionais" — intenção correta para um mantenedor solo, mas inexprimível como nome de arquivo.

Implementação que preserva a intenção:

- **Um único catálogo mantido: `es_ES`.**
- Um filtro mapeia todos os demais `es_*` para ele:

```php
add_filter( 'load_textdomain_mofile', function ( $mofile, $domain ) {
    if ( 'wp-recaptcha-forms' !== $domain ) return $mofile;
    $locale = determine_locale();
    if ( 0 === strpos( $locale, 'es_' ) && ! file_exists( $mofile ) ) {
        return dirname( $mofile ) . '/wp-recaptcha-forms-es_ES.mo';
    }
    return $mofile;
}, 10, 2 );
```

Resultado: um site em `es_MX` ou `es_AR` recebe a tradução em espanhol em vez de cair para inglês, e o mantenedor continua com **um** arquivo. Se um dia alguém contribuir `es_MX` de fato, o `file_exists` já faz o arquivo específico ganhar precedência — sem mudar código.

Isso não altera o compromisso do PRD §6 (variantes regionais fora de escopo); ao contrário, é como esse compromisso se torna implementável.

### 9.4 Fluxo de contribuição — FR-29

Os comandos que vão literalmente no README, todos via Composer scripts (§10.2):

```bash
composer i18n:pot                   # wp i18n make-pot . languages/wp-recaptcha-forms.pot
composer i18n:new fr_FR             # msginit a partir do .pot → languages/...-fr_FR.po
# traduzir o .po no Poedit (ou qualquer editor de texto — é texto puro)
composer i18n:build                 # make-mo + make-php de todos os .po
composer i18n:progress              # regenera a tabela de progresso nos READMEs
```

Testar localmente: trocar o idioma do site em Configurações → Geral, ou `define('WPLANG','fr_FR')`, e conferir a tela de config.

**O `.mo` entra no PR?** Regra assimétrica, e ela vai escrita no README:

- **Idiomas mantidos** (pt-BR, en-US, es): `.po` **e** `.mo` no commit — o CI valida que batem.
- **Idiomas de comunidade**: só o `.po`. O contribuidor não precisa ter WP-CLI instalado, o mantenedor não revisa binário, e o CI compila no release.

### 9.5 Fluxo verificável ponta a ponta — resolve S-17

O PRD é explícito: *"instrução não verificada é instrução errada"*. Então a seção de contribuição não é documentada e conferida à mão — **ela é executada pelo CI**.

`bin/i18n-e2e.sh`, rodando em cada PR:

1. Gera o `.pot` do zero a partir do código.
2. Deriva um **pseudo-locale** `en_CA` a partir do `.pot`, traduzindo cada `msgid` para `⟦<msgid>⟧` (script determinístico, ~20 linhas).
3. Compila `.mo` e `.l10n.php`.
4. Roda um teste de integração WordPress que faz `switch_to_locale('en_CA')`, renderiza a tela de config e cada mensagem de erro do front, e afirma:
   - **toda** string visível está entre `⟦ ⟧` → prova o caminho de tradução ponta a ponta;
   - **nenhuma** string visível está fora de `⟦ ⟧` → prova que não há string hardcoded.
5. Apaga os artefatos do pseudo-locale.

Isso entrega três gates com um mecanismo: S-17 (fluxo de contribuição funciona, porque o CI acabou de percorrê-lo do `.pot` até a string na tela), FR-28/S-16 (nenhuma string hardcoded, verificado por execução e não por leitura) e uma regressão permanente contra a próxima story que esquecer um `__()`.

`en_CA` como pseudo-locale porque é um locale válido do WP que nunca será um idioma real deste projeto — não colide com contribuição legítima.

### 9.6 Indicador de progresso — FR-30/S-18

**Restrições do PRD:** derivado dos `.po` reais, nunca escrito à mão, sem dependência de serviço terceiro que possa quebrar o badge.

**Decisão: script PHP próprio (`bin/i18n-progress.php`) que gera uma tabela Markdown com barra em caracteres Unicode, escrita entre marcadores nos dois READMEs, executada no CI.**

Saída:

```markdown
<!-- i18n-progress:start — gerado por bin/i18n-progress.php, não edite à mão -->
| Idioma | Progresso | Traduzido |
|---|---|---|
| English (en_US) | `██████████` | 100% (87/87) |
| Português do Brasil (pt_BR) | `██████████` | 100% (87/87) |
| Español (es_ES) | `██████████` | 100% (87/87) |
| Français (fr_FR) | `███████░░░` | 72% (63/87) |
<!-- i18n-progress:end -->
```

**Por que PHP e não Node:** o projeto é um plugin WordPress. O CI já tem PHP e Composer; adicionar Node só para contar strings introduz `package.json`, `node_modules` e dependabot noise num repositório que, fora o bundle do Blocks (§10.1), não precisa disso. O parser de `.po` necessário são ~120 linhas sem dependência externa — menos código do que a configuração para instalar a dependência que o faria.

**Por que texto Unicode e não badge de imagem:** shields.io é serviço terceiro (exatamente o risco que o PRD proíbe), o GitHub serve imagens por proxy com cache — um badge regenerado pode ficar stale por horas —, e N idiomas seriam N requisições HTTP no topo do README. A barra em texto renderiza no GitHub, no cliente de e-mail, no `cat README.md` e no editor, é diffável no PR (a mudança de progresso fica **visível na revisão**) e não pode quebrar.

**Contagem:**
- **Total** = `msgid` não vazios e não obsoletos (`#~`) no `.pot`.
- **Traduzido** = `msgstr` não vazio **e** sem flag `fuzzy`. Fuzzy conta como não traduzido — é uma tradução automática de string parecida, não uma tradução.
- Plurais: contam como traduzidos só se **todas** as formas `msgstr[n]` estiverem preenchidas.
- **`en_US` é o idioma-fonte e vale 100% por definição.** Sem esse caso especial o script reportaria 0%, porque o catálogo identidade tem `msgstr` vazio por convenção gettext — e o gate S-18 nunca passaria. É o tipo de detalhe que só aparece quando o gate falha na véspera do release.

**Onde roda:**

| Contexto | Comando | Efeito |
|---|---|---|
| PR (CI) | `php bin/i18n-progress.php --check` | Falha se a tabela nos READMEs estiver desatualizada. Força regeneração no mesmo PR que mexe em string. |
| Tag de release (CI) | `--check --require-complete=en_US,pt_BR,es_ES` | Falha se algum dos três mantidos < 100%. **É o gate S-16/S-18.** |
| Local | `composer i18n:progress` | Reescreve a tabela nos dois READMEs. |
| Pre-commit | opcional, `--check` | Ofertado em `docs/`, não imposto. Hook obrigatório em repositório público afasta contribuidor; o CI já é a autoridade. |

Um script, três consumidores, zero dependência externa.

---

## 10. Estrutura de arquivos

```
wp-recaptcha-forms/
├── wp-recaptcha-forms.php          # header do plugin + guard de PHP + bootstrap (§11)
├── uninstall.php                   # remove opções e transients (S-02)
├── LICENSE                         # GPLv2 (FR-15)
├── README.md                       # pt-BR, com marcadores i18n-progress
├── README-EN.md                    # paridade, mesmos marcadores
├── CHANGELOG.md                    # [Unreleased] obrigatório (FR-14)
├── CONTRIBUTING.md                 # aponta para a seção de tradução dos READMEs
├── composer.json                   # autoload PSR-4 + dev deps + scripts (§10.2)
├── phpcs.xml.dist                  # WPCS (FR-19)
├── phpunit.xml.dist
├── package.json                    # SOMENTE para o bundle do Blocks (§10.1)
│
├── src/
│   ├── Plugin.php                  # container mínimo, wiring
│   ├── Options.php                 # leitura/escrita tipada; único ponto que conhece a option
│   ├── Provider/                   # ← FRONTEIRA GOOGLE (A2, §4)
│   │   ├── ProviderInterface.php
│   │   ├── AbstractSiteverifyProvider.php
│   │   ├── SiteverifyV3.php
│   │   ├── SiteverifyV2.php
│   │   ├── ProviderFactory.php
│   │   ├── VerificationRequest.php
│   │   ├── ProviderResponse.php
│   │   ├── FailureClass.php        # INFRA | MISCONFIG | REJECTED (§5)
│   │   ├── Endpoints.php           # única ocorrência das URLs
│   │   └── Transport/
│   │       ├── TransportInterface.php
│   │       ├── WpHttpTransport.php
│   │       └── TransportResult.php
│   ├── Gate/                       # ← DECISÃO ÚNICA (A1, §5)
│   │   ├── Gate.php                # assess() + memoização por token (§6.4)
│   │   ├── FormContext.php
│   │   ├── Verdict.php
│   │   └── FailurePolicy.php       # resolução global/override (§5.4)
│   ├── Frontend/
│   │   ├── FieldRenderer.php       # imprime o hidden vazio (§6.2) — nada mais
│   │   ├── AssetManager.php        # enfileiramento condicional (FR-07) + tags no-optimize (§6.3)
│   │   └── Messages.php            # defaults traduzidos (FR-27); métodos, nunca const (§9.1)
│   ├── Admin/
│   │   ├── SettingsPage.php        # gerada a partir do Registry (§7.2)
│   │   ├── SettingsFields.php
│   │   ├── Sanitizer.php
│   │   ├── KeyValidator.php        # sonda de secret no save (§5.3-1)
│   │   ├── Notices.php             # notice persistente de MISCONFIG (§5.3-2)
│   │   └── SiteHealth.php          # teste crítico (§5.3-3)
│   ├── Integrations/
│   │   ├── IntegrationInterface.php   # id, label, available, register
│   │   ├── Registry.php               # bootstrap condicional (§7.2)
│   │   ├── Core/
│   │   │   ├── CommentIntegration.php
│   │   │   ├── LoginIntegration.php
│   │   │   ├── RegisterIntegration.php
│   │   │   └── LostPasswordIntegration.php
│   │   ├── Newsletter/
│   │   │   └── SubscribeIntegration.php
│   │   └── WooCommerce/            # carregado só sob class_exists (§7)
│   │       ├── ReviewIntegration.php
│   │       ├── LostPasswordIntegration.php
│   │       ├── ClassicCheckoutIntegration.php
│   │       └── BlocksCheckoutIntegration.php   # @wrf-conditional-load (§7.1)
│   └── Api/
│       └── PublicApi.php           # helpers globais do FR-11 (§12)
│
├── assets/
│   ├── js/
│   │   ├── src/frontend.js         # intercept de submit (§6.2)
│   │   ├── src/v2.js
│   │   ├── src/blocks-checkout.js  # onCheckoutValidation + setExtensionData (§8.3)
│   │   └── dist/                   # frontend/v2 copiados; blocks-checkout compilado
│   └── css/admin.css
│
├── languages/                      # §9.2
│
├── bin/
│   ├── i18n-progress.php           # FR-30/S-18 (§9.6)
│   ├── i18n-pseudo.php             # gera pseudo-locale (§9.5)
│   ├── i18n-e2e.sh                 # S-17 (§9.5)
│   ├── check-boundary.sh           # gates de fronteira (§4.5, §7.1)
│   ├── stamp-version.php           # CI escreve header + constantes (§11)
│   └── build-zip.sh                # artefato distribuível
│
├── tests/
│   ├── Unit/                       # Provider, Gate, FailurePolicy — FakeTransport
│   ├── Integration/                # hooks WP, i18n switch_to_locale
│   └── Fixtures/siteverify/        # respostas JSON da matriz §5.5
│
└── docs/
    ├── backlog-v1.md
    ├── prd-v1.md
    ├── architecture-v1.md
    └── release-checklist.md        # passos manuais: S-04, S-05, S-10
```

### 10.1 Sobre o `package.json`

`assets/js/src/frontend.js` e `v2.js` são JS puro, sem build — copiados para `dist/`. **Só** `blocks-checkout.js` precisa de bundler, porque consome pacotes do WooCommerce Blocks e JSX. `@wordpress/scripts` + `@woocommerce/dependency-extraction-webpack-plugin` cuidam disso e mantêm React/wp/wc como dependências externas em vez de empacotados.

Consequência aceita: o repositório tem Node como dependência de **build**, não de runtime. `assets/js/dist/blocks-checkout.js` é versionado (mesma lógica dos `.mo`: quem baixa o zip precisa dele pronto), e o CI valida que o build reproduz o arquivo commitado.

### 10.2 Scripts do Composer

```json
"scripts": {
  "lint":          "phpcs",
  "lint:fix":      "phpcbf",
  "test":          "phpunit",
  "i18n:pot":      "wp i18n make-pot . languages/wp-recaptcha-forms.pot --domain=wp-recaptcha-forms --exclude=tests,bin,assets/js/dist",
  "i18n:new":      "php bin/i18n-new.php",
  "i18n:build":    "wp i18n make-mo languages && wp i18n make-php languages",
  "i18n:progress": "php bin/i18n-progress.php --write",
  "i18n:e2e":      "bash bin/i18n-e2e.sh",
  "check:boundary":"bash bin/check-boundary.sh"
}
```

Um contribuidor tem um vocabulário só. É a diferença entre a seção de contribuição do README ser lida ou ignorada.

---

## 11. Versionamento e header — confirma a proposta do @po, com um ajuste

**Confirmo a proposta do @po**, e acrescento uma trava.

O header do plugin carrega **só** `MAJOR.MINOR.PATCH`. Isso não é preferência: o WordPress compara versões de plugin com uma variante de `version_compare`, e um sufixo como `-a1b2c3d-alpha` faz `1.2.0-a1b2c3d-alpha` ser considerado **anterior** a `1.2.0` (o traço marca pré-release em SemVer). Um usuário com a build de release nunca receberia a atualização seguinte.

```php
/**
 * Plugin Name: WP reCAPTCHA Forms
 * Version:     1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:     GPL-2.0-or-later
 * Text Domain: wp-recaptcha-forms
 * Domain Path: /languages
 */

define( 'WP_RECAPTCHA_FORMS_VERSION', '1.2.0' );                      // = header. Cache-bust de asset.
define( 'WP_RECAPTCHA_FORMS_BUILD',   'v1.2.0-a1b2c3d-stable' );      // string completa. Suporte/log.
```

**Ajuste 1 — as duas constantes, não uma.** O @po propôs "constante interna" no singular. São duas com papéis distintos: `VERSION` é o número que vai em `wp_enqueue_script()` e **precisa** ser idêntico ao header; `BUILD` é a string de diagnóstico que aparece no rodapé da tela de config e no Site Health. Misturar as duas colocaria o hash na URL do asset e invalidaria cache de navegador a cada commit.

**Ajuste 2 — trava automatizada.** Três lugares com a mesma versão divergem. `bin/stamp-version.php` escreve os três a partir da tag do git no release, e um teste unitário afirma que header e `VERSION` batem. Sem isso, a regra "nunca edite a versão à mão" depende de disciplina, e disciplina não sobrevive a um hotfix às 23h.

**Ajuste 3 — critério de MAJOR, complementando o backlog §4.** Aos gatilhos já listados, acrescento dois que este desenho cria:

- alterar a assinatura ou a semântica de `wp_recaptcha_forms_verify()` / `wp_recaptcha_forms_render_field()` (§12);
- **mudar REJECTED de bloqueio incondicional para configurável** — seria uma mudança silenciosa de postura de segurança em instalações existentes, o que é mais grave que quebrar uma API.

`Stable tag` do formato `readme.txt` fica fora até D-05 ser decidido.

---

## 12. API pública — FR-11

Contrato desde a v1 (PRD §2, persona P3), logo sujeito à regra de MAJOR.

```php
/** Imprime o campo. Aceita a action; nada mais entra no HTML (A3). */
wp_recaptcha_forms_render_field( string $form_id, array $args = [] ): void

/** Verifica. Retorna true|WP_Error — idioma nativo do WordPress. */
wp_recaptcha_forms_verify( string $form_id, array $args = [] ): bool|WP_Error

/** Está configurado e operante? Para o integrador decidir se renderiza. */
wp_recaptcha_forms_is_active( string $form_id = '' ): bool
```

Retornar `WP_Error` e não lançar exceção: é o que qualquer desenvolvedor WordPress espera, e permite `is_wp_error()` sem try/catch.

Filtros documentados e estáveis:

| Hook | Uso |
|---|---|
| `wp_recaptcha_forms_should_protect` ( bool, `$form_id`, `$context` ) | desligar por contexto (ex.: usuário logado com capability) |
| `wp_recaptcha_forms_verdict` ( `Verdict`, `FormContext` ) | inspeção/override final — poder máximo, documentado como tal |
| `wp_recaptcha_forms_error_message` ( string, `$form_id`, `$reason` ) | mensagem por formulário além do que a UI oferece |
| `wp_recaptcha_forms_endpoint` ( string, `$which` ) | `recaptcha.net` e afins (§4.4) |
| `wp_recaptcha_forms_misconfig_policy` ( string ) | postura estrita sob chave inválida (§5.3) |
| `wp_recaptcha_forms_request_timeout` ( int ) | rede lenta (§4.3) |

Registrar um formulário de terceiro no registry (para ganhar toggle na tela de config, em vez de só usar os helpers) é a extensão natural de `wp_recaptcha_forms_register_integration` — **fora da v1**, porque expor a interface de integração como contrato público antes de ela estabilizar em quatro implementações internas é comprar dívida de MAJOR cedo demais.

---

## 13. Segurança e privacidade

Além do que FR-19/S-07 já exigem:

| Item | Decisão |
|---|---|
| **Secret key** | Em option, **nunca** enfileirada, nunca em `data-*`, nunca ecoada no admin (campo mostra placeholder mascarado; salvar vazio mantém o valor atual). |
| **Secret via constante** | Suporte a `WP_RECAPTCHA_FORMS_SECRET_KEY` em `wp-config.php`, com precedência sobre a option e o campo desabilitado na UI quando definida. Caso real da persona P2: chave fora do banco, deploy versionado. |
| **Nonces** | Na tela de config, obrigatórios (Settings API já provê). **Nos formulários públicos, não** — nonce em página cacheada é o outro modo de falha do cache (nonce de visitante anônimo servido a usuário logado). O WP não usa nonce no formulário de comentário pelo mesmo motivo. |
| **Capability** | `manage_options` em tudo que é admin, incluindo o AJAX de validação de chave. |
| **Token vazio** | Bloqueia **sem** chamar o `siteverify` — economiza cota e derrota bot trivial sem gastar rede. |
| **`remoteip`** | O Google recomenda enviar o IP do visitante. Isso é envio de dado pessoal a terceiro (LGPD/GDPR). Decisão: **toggle na config, default ligado**, com nota de privacidade no README e menção na política sugerida. Default ligado porque melhora a qualidade do score e é o comportamento que o operador espera de um plugin de reCAPTCHA; toggle porque quem tem exigência de conformidade precisa poder desligar sem sair do plugin. |
| **Log** | Nenhum log persistente (PRD §6). Erros de INFRA/MISCONFIG vão para `error_log()` só com `WP_DEBUG` ativo, e o texto **nunca** contém a secret. |
| **Escaping** | `esc_attr()` na site key impressa, `wp_kses` nas mensagens customizáveis do operador (que são livres, FR-27 — logo, não confiáveis). |

Sinalizo uma implicação que o @pm deve conhecer: **o `remoteip` e o próprio carregamento do `api.js` do Google fazem o plugin ser um processador de dados de terceiro para efeito de LGPD/GDPR.** Não muda o escopo v1, mas o README precisa de um parágrafo honesto sobre isso — é a mesma disciplina de "não vender diferenciação inexistente" da §1 do PRD aplicada a privacidade. Recomendo ao @pm transformar isso em requisito de documentação.

---

## 14. Decisões delegadas — quadro de fechamento

| Origem | Questão | Decisão | Seção |
|---|---|---|---|
| D-04 | Versões mínimas PHP/WP | PHP 7.4, WP 6.0; Woo 7.0 clássico / 8.3 Blocks | §3 |
| D-05 | wordpress.org | Não decidido aqui (é do dono). Desenho não bloqueia: falta só `readme.txt` e `Stable tag` | §11 |
| D-06 | Cache de página | HTML sem token; token no submit; scripts marcados no-optimize; sem exclusão de cache | §6 |
| PRD §4.3 | Política por tipo de falha | Três classes. REJECTED sempre bloqueia (não configurável); INFRA configurável em dois níveis com default fail-open; MISCONFIG fail-open + escalada máxima no admin, com escape hatch por filtro | §5 |
| PRD §5 | Header de versão | Confirmada a proposta do @po, com duas constantes e trava automatizada | §11 |
| R-04 | Isolamento HTTP do Google | `src/Provider/` com interface, transporte injetável, endpoint único, gate de CI | §4 |
| R-02 | Woo opcional + Clássico vs Blocks | Registry condicional; regra do `implements`; um `Gate::assess()`, dois adaptadores; três stories | §7, §8 |
| PRD §4.5 | i18n | Text domain no `init`, `.po`/`.mo`/`.l10n.php` versionados, `es_ES` canônico com fallback `es_*` | §9 |
| FR-30 | Barra de progresso | Script PHP próprio → tabela Markdown com barra Unicode entre marcadores, `--check` no CI | §9.6 |
| FR-29/S-17 | Fluxo de tradução testável | Pseudo-locale gerado no CI + teste de integração; prova o fluxo e a ausência de string hardcoded | §9.5 |

## 15. O que fica em aberto para outros agentes

- **@devops** — implementar os gates: `check:boundary`, `i18n:progress --check` no PR e `--require-complete` na tag, validação de `.mo`/bundle versionados vs. build, `stamp-version.php` na release, matriz PHP × WP.
- **@pm** — dois itens para registro, ambos de documentação e nenhum reabrindo decisão: (a) o nome de arquivo do espanhol é `es_ES` com fallback, não `es` (§9.3, restrição da plataforma); (b) requisito de documentação de privacidade sobre `remoteip` e carregamento do `api.js` (§13).
- **@sm** — fatiar. Ordem de dependência: `Provider` + `Gate` → integrações nativas → Newsletter/API pública → Woo (W-1 → W-2 → W-3) → gate de release. As stories de i18n de código (FR-25/FR-28) não são story própria: o teste de pseudo-locale (§9.5) as impõe transversalmente a partir do momento em que entra no CI, e por isso ele deve entrar **cedo**, junto da fundação — não na story final de tradução.

---

### Referências verificadas (2026-09-08)

- [Exposing your data — Store API / ExtendSchema](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-data/)
- [Available extensible endpoints — Store API](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/available-endpoints-to-extend/)
- [Integrating Protection with the Checkout Block](https://developer.woocommerce.com/docs/block-development/tutorials/integrating-protection-checkout-block/)
- [Checkout flow and events — `onCheckoutValidation`](https://github.com/woocommerce/woocommerce-blocks/blob/trunk/docs/internal-developers/block-client-apis/checkout/checkout-flow-and-events.md)
- [woocommerce-checkout-integration-example — `RouteException` no Store API](https://github.com/woocommerce/woocommerce-checkout-integration-example/blob/main/includes/api/class-wc-cie-store-api-integration.php)

As URLs do console do Google (FR-24) **não** foram verificadas neste passe — são gate de release e devem ser conferidas ao vivo na data do release, conforme S-10.
