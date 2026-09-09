# wp-recaptcha-forms — Revisão de plano v1 (@qa)

**Autor:** @qa (Quinn)
**Data:** 2026-09-08
**Escopo:** revisão da cadeia @po (`backlog-v1.md`) → @pm (`prd-v1.md`) → @architect (`architecture-v1.md`)
**Natureza:** revisão de plano pré-implementação. Não é QA gate de código — não existe código.
**Verificações feitas ao vivo:** ambiente `test-env` (WP 7.1, PHP 8.3, Woo 11.1.0, Twenty Twenty-One), estado do repositório, exposição HTTP do mount.

---

## Veredito

# GO CONDICIONAL

Traduzido para binário, porque a decisão precisa ser acionável:

| Fatia | Decisão |
|---|---|
| **Fundação** — `Provider/`, `Transport/`, `Endpoints`, `Options`, esqueleto do `Gate`, bootstrap do plugin, gates de CI (`check:boundary`, i18n) | **GO.** @sm pode quebrar e @dev pode começar amanhã. |
| **Taxonomia de falhas completa + política efetiva** (§5 da arquitetura) | **NO-GO** até BL-01 e BL-02 |
| **Integrações nativas — login em particular** | **NO-GO** até BL-03 e BL-04 |
| **Integrações WooCommerce (W-1/W-2/W-3)** | **NO-GO** até BL-01 |
| **Story de i18n / gate de pseudo-locale** | **GO com condição** — BL-05 antes de o teste virar gate bloqueante de CI |

O plano é de qualidade acima da média. A arquitetura resolve corretamente os dois bugs clássicos da categoria (token cacheado, `implements` de classe ausente do Woo), a fronteira do Google é isolada com gate de CI executável, e a matriz de §5.5 é uma especificação de teste pronta para uso — raro num documento de arquitetura. Os bloqueios abaixo não são objeções ao desenho; são furos concretos que produzem site quebrado em produção, que é literalmente o critério S-11 que o próprio PRD elegeu como o mais importante.

---

## 1. Bloqueios

### BL-01 — HIGH — O cliente que não alcança o Google é sempre bloqueado, inclusive no checkout

**Onde:** arquitetura §6.2 (o `catch` envia token vazio de propósito) + §13 (token vazio bloqueia sem chamar `siteverify`) + §5.1 (token ausente ⇒ REJECTED ⇒ bloqueio incondicional).

O raciocínio do @architect está correto no plano de segurança: deixar o navegador sinalizar "não consegui carregar o reCAPTCHA, me deixa passar" é bypass trivial. Não estou pedindo para inverter isso.

O problema é que a consequência prática nunca foi apresentada ao dono do produto. Quando `api.js` não carrega — uBlock/AdGuard com lista anti-Google, rede corporativa ou regional que bloqueia `google.com`, ou **plugin de consentimento de cookies que segura scripts de terceiro até o aceite** — o resultado é:

- checkout Woo bloqueado, para aquele visitante, 100% das vezes;
- sem mensagem que ajude o operador a diagnosticar (o servidor vê "token vazio", indistinguível de bot);
- **sem qualquer controle configurável**, porque REJECTED é explicitamente não configurável (§5.2) — o fail-open do PRD §4.3 não alcança este caso.

Isso contradiz o racional que o próprio PRD usou para escolher fail-open como default (§4.3: *"venda perdida e ticket de suporte não são recuperáveis"*). O PRD cobriu a indisponibilidade Google↔servidor e deixou descoberta a indisponibilidade Google↔cliente, que é a mais frequente das duas.

Agrava: o filtro `wp_recaptcha_forms_endpoint` (§4.4) resolve `recaptcha.net` para o `siteverify` **e** para `SCRIPT_V3`, o que mitiga o caso "rede bloqueia google.com" — mas não o caso CMP nem o caso adblock, e isso não está escrito em lugar nenhum.

**O que precisa antes de codar §5 e as stories Woo:**
1. Decisão do dono (via @pm) sobre o trade-off, com o número na mesa: qualquer % de visitantes com CMP ou adblock agressivo é % de checkout perdido.
2. Se a decisão for manter o bloqueio (defensável), então isto vira **requisito**, não nota: mensagem de erro distinta para "token ausente" que instrua o usuário final (`"não foi possível carregar a verificação de segurança; desative o bloqueador de anúncios para esta página"`), seção de incompatibilidade no README ao lado da de cache (§6.3), e aviso na tela de config.
3. Item novo no gate de release: submeter um formulário protegido com `api.js` bloqueado no navegador e verificar que a mensagem é acionável (hoje S-06 só cobre o Google indisponível **para o servidor**).

### BL-02 — HIGH — Par site key / secret key cruzado é classificado como REJECTED e bloqueia o site inteiro em silêncio

**Onde:** arquitetura §5.1 e §5.3-1, em contradição interna.

§5.1 lista, na linha MISCONFIG, *"par site/secret trocado"*. §5.3-1 descreve a sonda de validação no save: envia um token deliberadamente inválido e espera `invalid-input-response` como prova de que a secret é boa.

A sonda funciona para o que promete, mas **o `siteverify` não recebe a site key** — ele não tem como saber que a site key configurada pertence a outro projeto. Consequência em runtime:

- tokens gerados pela site key do projeto A, verificados com a secret do projeto B, retornam `invalid-input-response`;
- §5.1 classifica `invalid-input-response` como **REJECTED**;
- REJECTED bloqueia sempre, não é configurável, e **não dispara notice de admin nem Site Health**;
- resultado: login, comentário, lost-password e checkout bloqueados para 100% dos visitantes, com a tela de config exibindo "Protegendo" (§5.3-4) e o save tendo passado na validação.

Este é exatamente o modo de falha catastrófico que a §5.3 foi escrita para eliminar, entrando pela porta que a própria seção deixou aberta. As quatro camadas de escalada não pegam nada porque o evento nunca é rotulado MISCONFIG.

**O que precisa:** o @architect fecha a lacuna antes de o `Gate` e o `FailurePolicy` serem implementados. Duas saídas plausíveis (a escolha é dele):
- (a) tratar `invalid-input-response` de forma sensível ao contexto — token não-vazio, bem-formado e recém-gerado que retorna `invalid-input-response` é sinal de par cruzado, não de bot; o token vazio já é bloqueado antes da rede (§13) e não polui esta contagem;
- (b) adicionar validação de site key no save via um caminho que a exercite de fato (carregar `api.js` com a site key na tela de config e executar um `grecaptcha.execute` real, verificando o token resultante contra a secret — valida o par inteiro num único ato).

O que **não** serve é deixar como está, porque a §5.1 hoje promete uma detecção que a §5.3 não entrega, e o @dev vai implementar a tabela e considerar o requisito cumprido.

### BL-03 — HIGH — Não existe kill switch de emergência, num plugin que protege o formulário de login

Nenhum dos três documentos prevê um desligamento de emergência. O único escape hatch é `wp_recaptcha_forms_misconfig_policy` (§5.3), que vai na direção oposta — endurece.

Cenários que trancam o operador fora do próprio site, todos alcançáveis sem má configuração:
- `api.js` indisponível para o cliente (BL-01) + login protegido = ninguém entra no `wp-admin`, incluindo quem consertaria;
- bug no `frontend.js` que quebre o intercept de submit em algum tema;
- operador com CMP no próprio site, que também segura o `api.js` no `wp-login.php`.

O caminho de recuperação hoje é renomear a pasta do plugin por FTP/SSH. Para a persona P1 — dono de loja, não desenvolvedor, hospedagem compartilhada — isso é um chamado de suporte de site fora do ar, que é o critério S-11.

**O que precisa:** constante em `wp-config.php`, no mesmo espírito de `WP_RECAPTCHA_FORMS_SECRET_KEY` (§13), do tipo `define('WP_RECAPTCHA_FORMS_DISABLE', true)` desligando todas as integrações sem desativar o plugin. Custo: poucas linhas no `Registry::boot()`. Precisa estar no README, na seção de solução de problemas, e ser um caso de teste. Deve entrar já na fundação, não na story de login.

Complemento barato e de alto retorno: manter o login protegido **não** aplicável quando o usuário já está autenticado, e considerar não bloquear quando `is_user_logged_in()` no `wp-login.php`.

### BL-04 — HIGH — A integração de login não define fronteira com XML-RPC, REST e app móvel

**Onde:** lacuna. §7.2 registra `Core\LoginIntegration()`, §8 detalha checkout e §8.5 detalha review e lost-password. O login — que o PRD §3.2 trouxe para dentro do escopo por decisão explícita — nunca é detalhado: não há hook nomeado, não há mecanismo de recusa, não há delimitação de superfície.

Isto importa muito mais do que parece. Se o bloqueio for implementado em `authenticate` / `wp_authenticate_user` — que é a escolha natural e o que a maioria dos plugins faz — ele passa a valer também para:
- XML-RPC (Jetpack, apps de publicação, backup remoto);
- autenticação da REST API com application passwords;
- o app oficial do WordPress;
- qualquer plugin de login social ou SSO.

Nenhum desses caminhos passa pelo formulário HTML, logo nenhum envia `wrf_token`, logo todos recebem token vazio ⇒ REJECTED ⇒ bloqueio incondicional (§5.2) ⇒ integrações do cliente param de funcionar sem mensagem inteligível. O PRD §3.2 antecipou "conflito com plugins de login social" como *"bug de compatibilidade a tratar"* — mas isso não é bug de compatibilidade, é consequência direta do desenho, e é previsível agora.

**O que precisa:** §8 ganha uma subseção de login com (a) o hook escolhido, (b) a condição explícita de aplicabilidade — só quando a requisição é POST no `wp-login.php` com o campo do plugin presente, e não `XMLRPC_REQUEST`, não `REST_REQUEST`, não `wp_doing_cron()` — e (c) o mecanismo de recusa (`WP_Error` no `authenticate` preserva o username digitado, atendendo FR-09). Sem isso o @dev escolhe o hook por conta e a escolha errada só aparece no site de um cliente.

### BL-05 — MEDIUM-HIGH — O critério do pseudo-locale `en_CA` é vago o bastante para virar disputa

**Onde:** arquitetura §9.5, passos 4a e 4b.

O mecanismo é excelente e resolve três gates com um artefato. O problema é o critério de aceite literal:

> *"**toda** string visível está entre `⟦ ⟧`"* / *"**nenhuma** string visível está fora de `⟦ ⟧`"*

Renderizar a tela de config produz, inevitavelmente, strings visíveis que **não** são do plugin e nunca estarão entre `⟦ ⟧`:
- chrome do admin do WordPress (botão "Save Changes" da Settings API, títulos de wrapper, mensagens do core);
- nomes próprios e marcas que não devem ser traduzidos: "WooCommerce", "reCAPTCHA", "Google", "Newsletter", "wp-recaptcha-forms";
- valores de opção digitados pelo operador (as mensagens customizáveis do FR-27 são explicitamente livres e não passam por tradução);
- números, percentuais, URLs, chaves mascaradas.

Do jeito que está escrito, o teste falha no primeiro dia. A saída que o @dev vai tomar sozinho, sob prazo, é uma allowlist ad-hoc crescendo por tentativa e erro — e uma allowlist frouxa neutraliza o gate FR-28/S-16 exatamente onde ele deveria morder.

**O que precisa, antes de o teste virar gate bloqueante:**
1. Delimitar o escopo do DOM avaliado: apenas nós renderizados pelo plugin (envolver a saída do plugin num container identificável, ou avaliar as strings retornadas pelos métodos do plugin em vez do HTML renderizado inteiro — a segunda é mais barata e mais estável).
2. Definir a allowlist como **lista fechada e versionada**, com regra de alteração: acrescentar entrada exige justificativa no PR. Se a allowlist puder crescer livremente, o gate é decorativo.
3. Definir o comportamento para strings interpoladas (`sprintf` com `%s`) e para plurais — o pseudo-locale precisa preservar os placeholders, senão quebra o render.

Mesma observação, menor, para o `--check` do `i18n-progress` (§9.6): não está definido se a comparação ignora os comentários de referência `#: arquivo:linha`, que mudam a cada edição de código sem que nenhuma string mude. Sem essa definição, o gate falhará em PRs que só movem linhas.

### BL-06 — MEDIUM — O contrato de nome do campo de token não cobre v2

**Onde:** §6.2 define `wrf_token` / `wrf_action`. §8.2 coleta `$_POST['wrf_token']`. §8.3 lê `$request['extensions']['wp-recaptcha-forms']['token']`.

No v2 checkbox, o campo é gerado pelo `api.js` do Google com o nome `g-recaptcha-response` (§6.5 confirma isso ao falar do campo nascendo vazio). Nenhuma seção define como o adaptador — que é o mesmo código para v2 e v3, por A1 — descobre de onde ler o token.

É pequeno e é fundação: o `FormContext` é construído por todos os adaptadores, e trocar a origem do token depois de nove integrações escritas é retrabalho evitável. Resolver com uma linha de contrato (por exemplo: um coletor único que, conforme a versão ativa, lê `wrf_token` ou `g-recaptcha-response`, e os adaptadores nunca tocam em `$_POST` diretamente). Ganho colateral: o gate de CI da §4.5 poderia proibir `g-recaptcha-response` fora da fronteira, pelo mesmo princípio A2.

### BL-07 — MEDIUM — Bloqueio operacional real no ambiente: git inoperante e mount expondo `.git` por HTTP

Verificado agora:

```
$ ls -ld .          → drwxrwxrwx  www-data www-data
$ git status        → fatal: detected dubious ownership
$ curl -o /dev/null -w '%{http_code}' \
    http://localhost:8081/wp-content/plugins/wp-recaptcha-forms/.git/config   → 200
$ ... /docs/prd-v1.md                                                          → 200
```

Duas coisas distintas:

1. **A raiz do repositório e o `.git` pertencem a `www-data`**, provavelmente efeito do bind mount do container. Git recusa operar. O @dev não consegue commitar amanhã sem resolver isso — é o bloqueio mais barato desta lista e o único puramente operacional (`git config --global --add safe.directory` mais correção de ownership).

2. **O compose monta `../` inteiro em `wp-content/plugins/wp-recaptcha-forms`**, o que coloca `.git/`, `docs/` e o futuro `bin/`, `tests/`, `composer.json` sob o webroot. Além do `.git` exposto (200 confirmado), isso significa que **o ambiente de teste nunca exercita o artefato que será distribuído** — o `build-zip.sh` da §10 produz outra coisa. O gate S-02 ("ativa e desativa em WP virgem sem notice; desinstalação remove opções") só é significativo se rodar sobre o zip real. Recomendo: manter o mount atual para o loop de desenvolvimento, e acrescentar ao `docs/release-checklist.md` um passo de instalação do zip gerado num WordPress limpo.

---

## 2. Os dois itens devolvidos pelo @architect ao @pm

### (a) `es` → `es_ES` canônico com fallback `es_*` — **ACEITÁVEL**, com duas condições

A decisão está certa e bem fundamentada: não existe locale `es` no WordPress, a intenção do PRD §4.5 (um catálogo, sem variantes regionais para manter) é integralmente preservada, e o `file_exists` deixa a porta aberta para uma contribuição `es_MX` futura sem mudança de código. Não precisa de nova rodada de decisão de produto — é restrição de plataforma, não reabertura.

Duas condições técnicas, ambas para o @architect/@dev, não para o dono:

1. **O filtro `load_textdomain_mofile` não cobre o `.l10n.php`.** A §9.2 adota `.l10n.php` (WP 6.5+) como formato preferencial e a §9.6 o gera junto do `.mo`. Em WP 6.5+, o carregamento de tradução tenta primeiro o `.l10n.php`; o filtro escrito na §9.3 só intercepta o caminho do `.mo`. Existe risco concreto de um site em `es_MX` num WP recente não receber o fallback — exatamente o cenário que o mecanismo existe para atender, falhando só nas versões novas do WordPress, que é onde ninguém testa o caso antigo. Precisa de tratamento do hook equivalente para o formato PHP, ou de decisão explícita de não gerar `.l10n.php` para o espanhol.
2. **Teste de integração obrigatório:** `switch_to_locale('es_MX')` carrega o catálogo `es_ES`, verificado **tanto no WP mínimo (6.0) quanto no WP latest**. É um teste de poucas linhas e é a única prova de que o mecanismo funciona nas duas eras de carregamento de tradução. Hoje a matriz de teste da §3 não tem nenhuma célula que exercite isso.

Cosmético, sem ação necessária: a tabela de progresso (§9.6) exibirá "Español (es_ES)". Um operador em `es_MX` verá a interface traduzida e o README dizendo `es_ES`. É irrelevante e não vale linha de código.

### (b) Documentação de privacidade LGPD/GDPR (`remoteip` + `api.js`) — **NÃO ACEITÁVEL como está.** Precisa de uma rodada curta com @pm/dono

Este item foi devolvido corretamente pelo @architect, mas ficou pendurado: **não virou requisito**. Não existe FR para ele no PRD, não existe critério no gate de release (S-10 cobre paridade de README e URLs, não privacidade), e a única menção viva é um parágrafo no fim da §13 da arquitetura, endereçado a um agente que ainda não agiu sobre ele. Requisito que só existe como recomendação num documento de outro agente é requisito que não sai.

Três coisas precisam de decisão, e duas delas não são do @architect:

1. **O default do toggle `remoteip` é decisão do dono do produto, não de arquitetura.** A §13 fixou "default ligado" com justificativa técnica razoável (melhora o score). Mas enviar o IP do visitante a um terceiro por padrão é postura de privacidade, e postura de privacidade é do dono. Pode muito bem ser confirmada como está — só precisa ser confirmada por quem tem a autoridade.

2. **O toggle sozinho dá falsa sensação de conformidade, e isso precisa estar escrito.** Desligar o `remoteip` não muda o fato de que o `api.js` é carregado do domínio do Google em toda página com formulário protegido — o que já entrega IP, user agent e cookies do Google, independentemente do toggle. Um operador que desliga o `remoteip` acreditando ter resolvido a conformidade está pior do que antes, porque agora tem uma crença errada. O parágrafo honesto que o @architect pediu precisa dizer isso explicitamente, e não apenas mencionar o `remoteip`.

   Nota lateral que vale para o README: **FR-07 (carregamento condicional do asset) é, por acidente, a mitigação de privacidade mais efetiva do plugin** — ele impede que o `api.js` carregue em páginas sem formulário protegido. Vale ser apresentado assim, porque é verdade e é vendável sem exagero.

3. **Interação com plugins de consentimento de cookies não foi tratada por nenhum dos três agentes.** É a face operacional do mesmo tema e conecta direto com BL-01: um CMP configurado corretamente para LGPD segura o `api.js` até o aceite, o `grecaptcha` não existe, o `catch` da §6.2 envia token vazio e o formulário bloqueia. O plugin precisa, no mínimo, documentar isso; idealmente, tratar `api.js` ausente de forma distinguível de token ausente por bot.

**Encaminhamento:** @pm registra como FR numerado (privacidade é requisito de documentação **e** de comportamento configurável), acrescenta um critério ao gate de release, e leva o item 1 ao dono. É meia hora de trabalho e não bloqueia a fundação — mas bloqueia o release, e é mais barato agora do que depois de o README estar escrito em dois idiomas.

---

## 3. Testabilidade dos mecanismos citados

| Mecanismo | Verificável objetivamente? | Ressalva |
|---|---|---|
| **Taxonomia de 3 classes** (§5) | **Sim, e é o melhor pedaço do plano.** A matriz §5.5 é uma especificação de teste executável: cenário do fake → classe → veredito por configuração. `FakeTransport` injetado (§4.3) em vez de `pre_http_request` elimina a flakiness que arruína suítes de plugin. As duas células em negrito são regressões nomeadas. | Faltam linhas para `bad-request` (ver OB-03) e para o cenário do par cruzado (BL-02). Acrescentadas essas duas, a matriz vira o critério de aceite completo da fundação. |
| **Progresso de tradução via CI** (§9.6) | **Sim.** `--check` no PR e `--require-complete=en_US,pt_BR,es_ES` na tag são binários e sem ambiguidade. Contagem definida com precisão incomum: fuzzy conta como não traduzido, plural só conta com todas as formas, `en_US` vale 100% por definição — este último detalhe evita um gate impossível, e foi antecipado corretamente. | Não está definido se o `--check` ignora os comentários `#: arquivo:linha` do `.pot`. Sem isso, PRs que só movem código falham o gate. Uma frase resolve. |
| **Pseudo-locale `en_CA`** (§9.5) | **Não como está escrito.** Ver BL-05. | O mecanismo é bom; o critério de aceite é que está vago. É o item desta lista com maior chance de virar disputa de interpretação em janeiro. |
| **Gate de fronteira por grep** (§4.5) | **Sim**, com uma armadilha de shell. | Ver OB-06. |
| **S-05 cache** (§6.6) | **Sim**, roteiro manual bem escrito. O passo 3 (esperar 5 minutos) e o passo 5 (inspecionar o HTML cacheado e verificar o invariante A3, não o sintoma) são o que separa este roteiro dos que não pegam o bug. | Nenhuma. Vai para o `release-checklist.md` como está. |

---

## 4. Riscos que nenhum dos três agentes endereçou

Além dos que viraram bloqueio acima (CMP/adblock em BL-01, lockout de login em BL-03, XML-RPC/REST em BL-04):

| ID | Risco | Avaliação |
|---|---|---|
| **R-09** | **Não existe versionamento de schema das opções nem rotina de upgrade.** §11 estabelece corretamente que mudar o formato das opções sem migração é MAJOR — mas nenhuma seção prevê o mecanismo que tornaria a migração possível. Um plugin sem `db_version` e sem rotina de upgrade só descobre isso na primeira mudança de formato, quando já existem instalações. | Fundação. Custo agora: uma option `_schema_version` e um `maybe_upgrade()` no bootstrap. Custo depois: uma release inteira. Recomendo incorporar à story de `Options.php`. |
| **R-10** | **Impacto do `api.js` em Core Web Vitals.** O script do reCAPTCHA v3 é pesado e roda em toda página com formulário protegido — o que, com comentários habilitados, é todo post do site. Nenhum documento menciona performance. | FR-07 já mitiga bastante (não carrega onde não há formulário). Vale uma linha honesta no README, no mesmo espírito da §1 do PRD. Não bloqueia. |
| **R-11** | **A matriz de teste não cobre a faixa do WP onde o comportamento de i18n muda.** §3 define WP 6.0 e WP latest. Mas §9.2 depende de `.l10n.php` (6.5+) e §9.1 depende do `_load_textdomain_just_in_time` (6.7+). A faixa 6.5–6.7 — que é onde vive uma parcela relevante do parque e onde essas transições acontecem — não é exercitada por nenhuma célula. | Acrescentar WP 6.6 ou 6.7 ao eixo, ou justificar a ausência por escrito. Interage com a condição (a)-2 do espanhol. |
| **R-12** | **O ambiente de teste local não cobre nenhum dos pisos declarados.** Verificado: WP 7.1, PHP 8.3, Woo 11.1.0 — enquanto a §3 declara pisos de WP 6.0, PHP 7.4, Woo 7.0 (clássico) e 8.3 (Blocks). Todo desenvolvimento acontecerá no topo da matriz. As asserções de `version_compare` da §3 e o caminho "Blocks indisponível, linha desabilitada na UI" (§7.2) **nunca serão exercitados manualmente**. | Não bloqueia o início. Mas o teste de `available() === false` precisa ser unitário com versão injetada, e não depender do ambiente — senão S-03 e o comportamento de degradação por versão só serão validados em produção alheia. |
| **R-13** | **Concorrência com outros plugins de reCAPTCHA.** Muitos sites já têm um. Dois plugins injetando `api.js` com site keys diferentes na mesma página produzem falha confusa. | Detecção barata (`wp_script_is('google-recaptcha')` e similares) mais notice de admin. Candidato a v1.x, não a bloqueio — mas merece uma linha no README, porque é fonte previsível de issue. |

---

## 5. Observações não bloqueantes

| ID | Item |
|---|---|
| **OB-01** | **FR-09 não tem tratamento explícito em nenhuma seção da arquitetura.** O requisito pede "mensagem clara, preservação do conteúdo digitado onde o hook permitir, **status HTTP adequado**". §8.2 atende bem para o checkout clássico (`after_checkout_validation` preserva campos), §8.5 usa `wp_die()` para review — que perde o comentário digitado. E "status HTTP adequado" nunca é definido em lugar nenhum: 403? 200 com erro na página? Critério indefinido vira disputa na revisão da story. Definir uma vez, na §8, valendo para todos os adaptadores. |
| **OB-02** | **A cardinalidade das mensagens customizáveis (FR-05/FR-27) é indefinida.** Uma mensagem global? Uma por formulário? Uma por classe de falha? O filtro `wp_recaptcha_forms_error_message(string, $form_id, $reason)` sugere `form × reason`, mas a tela de config não é especificada nesse nível. Com 9 pontos de integração e 3 classes de falha, a diferença entre uma decisão e outra é entre 1 e 27 campos na UI. @sm precisa disso para dimensionar a story da tela de config. |
| **OB-03** | **`bad-request` classificado como REJECTED** (§5.1) é discutível. `bad-request` indica requisição malformada ao `siteverify` — se acontecer, é bug do plugin ou configuração quebrada, não bot. Como REJECTED, bloqueia 100% dos visitantes sem escape e sem notice. MISCONFIG seria a classificação correta, com o mesmo raciocínio da §5.3. |
| **OB-04** | **`wp_recaptcha_forms_should_protect` colide com escopo declarado fora.** O PRD §6 exclui explicitamente "whitelist de IPs e bypass por capability (B-23)"; §12 documenta o filtro com o exemplo *"desligar por contexto (ex.: usuário logado com capability)"*. Um hook não é a feature, e a distinção é defensável — mas o exemplo escolhido é literalmente o item excluído, e vai gerar issue pedindo a UI. Trocar o exemplo resolve. |
| **OB-05** | **`uninstall.php` precisa de lista explícita de chaves para S-02 ser verificável.** §7.3 e §10 dizem "remove opções e transients"; §5.3 introduz `wp_recaptcha_forms_misconfig_since`; R-09 introduziria `_schema_version`. Sem inventário nomeado, "desinstalação remove todas as opções" não é testável — vira inspeção manual que esquece a chave nova de cada story. Um teste que ativa, salva tudo, desinstala e afirma que nenhuma option com o prefixo sobrou resolve permanentemente. |
| **OB-06** | **Armadilha de shell nos gates da §4.5.** `! grep -rl ... \| grep -v '^src/Provider/'` funciona como escrito, mas quebra silenciosamente sob `set -o pipefail`, que é exatamente o que alguém acrescentará ao `check-boundary.sh` por higiene. O script precisa ou dispensar `pipefail` com um comentário explicando por quê, ou ser reescrito de forma robusta. Um gate de CI que passa por acidente é pior que gate nenhum — e este protege o princípio A2, que é a mitigação inteira do R-04. |
| **OB-07** | **Rastreabilidade dos FR de i18n está frouxa.** O PRD §3.2 agrupa "FR-25 a FR-28" e "FR-29, FR-30" em duas linhas de tabela sem enunciado individual; os enunciados vivem em §4.5/§4.6. S-01 exige "FR-01 a FR-30 entregues e verificados". Funciona, mas o @sm terá que caçar. Custo baixo de arrumar agora, custo recorrente de não arrumar. |

---

## 6. O que a cadeia acertou

Registro porque revisão que só lista defeito distorce a decisão de quem lê.

- **A2 + gate de CI (§4.5).** Isolar a fronteira do Google é a mitigação óbvia do R-04; o que não é óbvio é ter percebido que um princípio arquitetural sem verificação automatizada é comentário decorativo. Duas linhas de shell compram a validade de A2 daqui a dois anos.
- **A3 e §6.2.** O bug de token cacheado é o que mais afunda plugin desta categoria. Tratá-lo removendo o token do HTML — em vez de documentar exclusões de cache — transforma o problema em não-problema. Os três detalhes de produção (`requestSubmit` em vez de `submit`, preservar o `submitter`, `catch` que envia vazio) são coisas que normalmente se aprende em issue de usuário.
- **§6.4, memoização por token.** Token de uso único mais hooks do Woo que reexecutam é bug intermitente de checkout em produção. Foi antecipado antes de existir código.
- **§7.1, a distinção `implements` vs type hint.** É a diferença entre plugin que degrada e plugin que derruba o site, e quase nunca é explicitada. A anotação `@wrf-conditional-load` com verificação em CI é a mesma disciplina do item 1.
- **§5.2.** Perceber que um toggle único de fail-open desligaria o plugin por default é o achado mais valioso do documento inteiro. Sem isso, o produto sairia com o pior defeito possível — operador acreditando estar protegido, sem estar.
- **§9.5, o pseudo-locale.** Três gates com um mecanismo, e a interpretação correta da exigência do PRD ("instrução não verificada é instrução errada") como *executar* o fluxo no CI em vez de conferi-lo à mão. O critério de aceite precisa de aperto (BL-05), mas a ideia está certa.
- **§8.1.** Mesmo `form_id` e mesma `action` para Clássico e Blocks, produzindo um único toggle. É o que impede que o gate S-04 vire teatro e que o operador configure divergência por acidente.

---

## 7. Encaminhamento

| Quem | O quê | Trava o quê |
|---|---|---|
| **@architect** | BL-02 (par cruzado), BL-04 (fronteira do login), BL-06 (campo de token no v2), BL-05 (critério do pseudo-locale), condição (a)-1 (`.l10n.php` no fallback `es_*`), OB-01, OB-03, R-09 | §5, integrações nativas, story de i18n |
| **@pm + dono** | BL-01 (trade-off do cliente sem Google), item devolvido (b) — FR de privacidade numerado, default do `remoteip`, CMP | §5, stories Woo, gate de release |
| **@pm** | OB-02 (cardinalidade das mensagens), OB-07 (rastreabilidade dos FR de i18n) | story da tela de config |
| **@dev / operacional** | BL-07 — ownership do repositório e `.git` sob o webroot | qualquer commit |
| **@devops** | OB-06 (`pipefail`), R-11 (célula WP 6.6/6.7 na matriz), passo de instalação do zip no `release-checklist.md` | gate de release |
| **@sm** | Pode quebrar a fundação agora, na ordem da §15 da arquitetura. Segurar as stories de integração até os itens acima fecharem. | — |

**Reavaliação:** este documento vira `qa-plan-review-v2.md` quando BL-01 a BL-04 tiverem resposta escrita. Os demais itens podem ser absorvidos nas stories.
