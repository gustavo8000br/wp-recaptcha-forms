# wp-recaptcha-forms — PRD v1

**Produto:** plugin WordPress open-source, gratuito, `wp-recaptcha-forms`
**Repositório:** github.com/gustavo8000br/wp-recaptcha-forms
**Dono do produto:** Gustavo
**Autor deste documento:** @pm (Morgan)
**Data:** 2026-09-08
**Insumo:** `docs/backlog-v1.md` (@po) + decisões do dono do produto de 2026-09-08
**Status:** aprovado como base para @architect (Plan) e @sm (stories)

Este PRD é deliberadamente enxuto. É um plugin open-source mantido por uma pessoa; o custo de um PRD corporativo completo excederia o valor. O que está aqui é o que muda decisão de escopo, arquitetura ou release.

---

## 1. Visão do produto

Um plugin gratuito que aplica reCAPTCHA (v3 por padrão, v2 checkbox como alternativa) a **todos** os formulários relevantes de um site WordPress — incluindo os do WooCommerce — sem paywall e sem exigir código do usuário final.

A tese é **cobertura, não sofisticação**. O levantamento do @po (backlog §1) mostra que:

- as opções gratuitas param nos formulários nativos do WP (login, registro, comentário, lost-password);
- reviews de produto, checkout e lost-password do WooCommerce estão sempre atrás de licença paga;
- nenhuma opção gratuita cobre, ao mesmo tempo, comentários + reviews Woo + checkout Woo + newsletter com v3.

O produto não tenta ser mais configurável que o mercado. Tenta cobrir mais superfície de graça. Essa honestidade vai literalmente para o README: não vender diferenciação inexistente.

**O que NÃO é a visão:** virar plataforma anti-spam, dashboard de analytics, ou abstração multi-provider (hCaptcha/Turnstile). É um plugin de reCAPTCHA que cobre os formulários que os outros deixam de fora.

---

## 2. Personas

### P1 — Dono de loja WooCommerce pequena (persona primária)
Roda uma loja de 1 a 3 pessoas. Está tomando spam em reviews de produto e/ou tentativas automatizadas no checkout e no lost-password. Procurou plugin de reCAPTCHA, descobriu que a cobertura Woo é paga em todos, e não quer assinatura recorrente para resolver um problema pontual. Não é desenvolvedor: instala pelo painel, cola duas chaves, marca toggles.

**Implicação de produto:** a tela de configurações precisa ser autoexplicativa e a documentação de obtenção de chaves precisa ser passo a passo real, não "vá ao console do Google". É desta persona que vem a maior parte do custo de suporte.

### P2 — Freelancer / agência pequena que entrega sites WordPress
Instala o mesmo stack em vários clientes. Valoriza: não ter licença por site, degradação limpa quando não há WooCommerce, e não quebrar o site do cliente quando o Google fica fora do ar. É quem tem opinião sobre fail-open vs fail-close, porque é quem recebe a ligação quando o checkout trava.

**Implicação de produto:** esta persona é a razão de o comportamento em falha ser configurável (§4.3) e de existir API pública (B-11).

### P3 — Desenvolvedor WordPress integrando formulário próprio ou de terceiros
Tem um formulário custom, ou um plugin de formulário sem integração nomeada. Quer dois helpers e hooks documentados, não uma UI. É o consumidor do B-11 e o público do README-EN.

**Implicação de produto:** a API pública é contrato desde a v1 — muda o critério de MAJOR no versionamento (backlog §4).

### Não-persona (explícito)
Grandes operações, agências enterprise, sites com requisito de compliance/auditoria de bloqueios. Log, relatórios e multisite estão fora (§6).

---

## 3. Requisitos funcionais — escopo v1

Origem: backlog §3. Nada aqui é invenção nova; a rastreabilidade está na coluna Backlog.

### 3.1 Núcleo (P0)

| ID | Requisito | Backlog |
|---|---|---|
| FR-01 | Esqueleto do plugin: header, bootstrap, ativação/desativação, desinstalação limpa, i18n com text domain `wp-recaptcha-forms` | B-01 |
| FR-02 | Camada de verificação server-side contra o `siteverify` do Google, com tratamento de timeout e erro de rede | B-02 |
| FR-03 | Suporte a reCAPTCHA v3: token por `action` nomeada por formulário, validação de score contra threshold **global** | B-03 |
| FR-04 | Suporte a reCAPTCHA v2 checkbox como alternativa selecionável nos mesmos pontos de integração | B-04 |
| FR-05 | Tela de configurações: versão (v3/v2), site key, secret key, threshold (default 0.6), toggles por formulário, mensagens de erro customizáveis | B-05 |
| FR-06 | Avisos e ajuda contextual na tela de config, com links oficiais verificados para criação de chaves | B-06 |
| FR-07 | Carregamento condicional do asset: o script do reCAPTCHA só carrega em páginas com formulário protegido ativo | B-07 |

### 3.2 Cobertura de formulários (P1)

| ID | Requisito | Backlog |
|---|---|---|
| FR-08 | Formulários nativos WP: comentários, registro, lost-password e **login**, cada um com toggle próprio | B-08 |
| FR-09 | Comportamento consistente ao reprovar: mensagem clara, preservação do conteúdo digitado onde o hook permitir, status HTTP adequado | B-09 |
| FR-10 | Integração com o plugin Newsletter (Stefano Lissa) — formulário de inscrição, sem dependência dura | B-10 |
| FR-11 | API pública para terceiros: helpers de render e verify + filtros/actions documentados | B-11 |
| FR-12 | Camada WooCommerce opcional com feature detection real: reviews de produto, checkout e lost-password Woo, toggle por ponto | B-12 |
| FR-13 | Degradação sem WooCommerce: zero erro, zero notice incômodo, seção Woo oculta ou marcada como indisponível | B-13 |
| FR-25 a FR-28 | Plugin traduzível pelo padrão de i18n do WordPress e **traduzido de fábrica** em pt-BR, en-US e es, tanto no admin quanto nas mensagens ao usuário final — detalhado em §4.5 | novo (§4.5) |
| FR-29, FR-30 | Documentação de contribuição de traduções e indicador de progresso por idioma no README — detalhado em §4.6 | novo (§4.6) |

**FR-08 inclui login** — resolve D-07 do backlog pela via mais barata: login é o formulário mais atacado de um WordPress e omiti-lo tornaria a tese de cobertura contraditória. Se surgir conflito com plugins de login social, é bug de compatibilidade a tratar, não motivo para tirar do escopo.

**FR-10 cobre apenas o formulário de inscrição** (resolve D-09). Cancelamento e perfil do Newsletter ficam fora: são fluxos de usuário já identificado, com valor anti-spam marginal.

### 3.3 Qualidade e distribuição (P2 — obrigatório antes de release público)

| ID | Requisito | Backlog |
|---|---|---|
| FR-14 | Versionamento `vMAJOR.MINOR.PATCH-HHHHHHH-stage` com CI, label de nível por PR, CHANGELOG obrigatório | B-14 |
| FR-15 | Licença **GPLv2-or-later**: arquivo `LICENSE` + header do plugin coerente | B-15 |
| FR-16 | README.md em pt-BR com motivação honesta, instalação, configuração passo a passo, matriz de formulários, FAQ | B-16 |
| FR-17 | README-EN.md em paridade com o pt-BR | B-17 |
| FR-18 | Testes: unitários da camada de verificação com HTTP mockado, integração dos hooks principais, matriz WP/PHP | B-18 |
| FR-19 | Segurança: secret key nunca no front, nonces, capability checks, escaping/sanitização, conformidade PHPCS+WPCS | B-19 |
| FR-20 | Requisitos mínimos de PHP e WP declarados em header e README | B-20 |

Em produto open-source, README e licença não são polimento. Um repositório público sem licença é juridicamente inutilizável por quem quiser adotá-lo; um README fraco é a diferença entre 5 e 500 instalações. P2 é gate de release, não backlog opcional.

---

## 4. Decisões do dono do produto incorporadas

As quatro decisões abaixo estão fechadas. Este PRD as registra como requisito; o "como" técnico, onde indicado, fica com @architect.

### 4.1 Licença — GPLv2-or-later (resolve D-02)
Decisão do dono. Coerente com a herança do core do WordPress e pré-requisito de qualquer distribuição pública. Irreversível na prática após o primeiro release público — trate como definitiva. Impacta FR-15 e o header do plugin (FR-01).

### 4.2 Checkout WooCommerce — Clássico **e** Blocos, ambos na v1 (resolve D-08)
O checkout em Blocos é o default em instalações Woo recentes. Entregar só o clássico faria de "suporta checkout Woo" uma meia-verdade — exatamente o tipo de exagero de README que a §1 se compromete a evitar.

**Consequência assumida:** são dois caminhos de integração tecnicamente distintos (PHP/hooks vs. React/Store API). O esforço de FR-12 é materialmente maior do que o backlog dimensionou, e o risco R-02 (§7) deriva daqui. Isso não foi adiado para a v1.1 por decisão explícita do dono, ciente do custo.

### 4.3 Comportamento em falha do Google — **configurável**, não fixo (resolve D-03, expandindo a recomendação do @po)

O @po recomendou política fixa (fail-open no checkout, fail-close no resto). O dono do produto expandiu o escopo: **o comportamento deve ser configurável por quem instala o plugin.**

Racional: a escolha certa depende do negócio, não do plugin. Uma loja com ticket alto prefere perder uma venda a deixar passar fraude; uma loja de volume prefere o oposto. Um site institucional com comentários abertos tem cálculo diferente de um SaaS. Fixar a política no código transfere para o autor uma decisão de risco que é do operador.

**Granularidade definida (requisito funcional):**

| Nível | Requisito |
|---|---|
| **Global** | Um controle único "quando o Google não responder: permitir o envio / bloquear o envio". É o que 90% dos usuários vão tocar. **Default: permitir (fail-open).** |
| **Por ponto de integração** | Cada formulário protegido pode **sobrescrever** o valor global. Herda o global por padrão; a UI mostra explicitamente "herdando: permitir". |

Escolhi granularidade em dois níveis, e não um toggle global puro, por três razões:

1. A tela de config já tem toggle por formulário (FR-05). O override mora ao lado do toggle que o usuário já entende — custo de UI incremental baixo.
2. O caso real que motivou a discussão é assimétrico por natureza: checkout e comentário têm consequências opostas na mesma instalação. Um toggle único força o usuário a escolher o menos ruim para os dois.
3. Sem o nível global, seriam N decisões para cada instalação nova — atrito inaceitável para a persona P1.

**Default = fail-open** porque a falha do Google é indisponibilidade de terceiro, e o modo de falha caro é derrubar o site inteiro do usuário por causa dela. Spam numa janela de indisponibilidade é recuperável; venda perdida e ticket de suporte não. Quem quiser postura estrita muda em um clique, e essa escolha precisa estar documentada no README e ter aviso na tela de config.

**Para @architect resolver o "como":** distinção entre falha de infraestrutura (timeout, 5xx, rede) e falha de configuração (chave inválida, quota estourada, resposta com `error-codes`). Não é óbvio que ambas devam seguir a mesma política — chave inválida é erro do operador e talvez deva sempre bloquear-com-aviso-no-admin, não fail-open silencioso. O PRD exige apenas: **comportamento em falha é configurável em dois níveis, com default fail-open documentado.** A taxonomia de falhas é decisão de arquitetura.

### 4.4 Documentação do console do Google — **dois fluxos** (expande D-01)

O Google está migrando a criação de chaves do console clássico (`google.com/recaptcha/admin`) para o reCAPTCHA Enterprise no Google Cloud Console. O clássico ainda funciona, mas está em depreciação. O fluxo novo exige conta GCP **com faturamento habilitado**, mesmo tendo cota gratuita.

**Isso é mudança real de escopo, não nota de rodapé.** Documentar dois fluxos de obtenção de chave significa:

- duas trilhas passo a passo no README pt-BR e no README-EN (quatro conjuntos de instruções, contando as traduções);
- dois conjuntos de screenshots, que envelhecem em ritmos diferentes;
- explicar a exigência de faturamento no GCP sem que o usuário abandone a instalação — é o ponto de maior atrito de onboarding de todo o produto, e a persona P1 não tem conta GCP;
- FR-06 (avisos na tela de config) precisa linkar para o fluxo correto, o que implica decidir qual apresentar como caminho principal;
- suporte: parte relevante das issues futuras vai ser "não consigo criar a chave", em dois sabores diferentes.

**Requisitos derivados:**

| ID | Requisito |
|---|---|
| FR-21 | README (pt-BR e EN) documenta os **dois** fluxos de obtenção de chave: console clássico e reCAPTCHA Enterprise via Google Cloud, cada um passo a passo |
| FR-22 | A documentação declara explicitamente que o fluxo Enterprise exige conta GCP com faturamento habilitado, e que o clássico está em depreciação |
| FR-23 | FR-06 (ajuda na tela de config) aponta para ambos os fluxos, com um marcado como recomendado |
| FR-24 | Todas as URLs de console citadas em código e documentação são **verificadas ao vivo** antes do release (herda D-01) |

Nota de escopo: documentar o fluxo Enterprise **não** significa suportar a API do reCAPTCHA Enterprise. A v1 consome chaves compatíveis com o `siteverify` clássico; o Enterprise entra aqui só como caminho de obtenção de chave. Suporte à API Enterprise permanece fora (§6).

### 4.5 Internacionalização — pt-BR, en-US e es prontos na v1

Decisão do dono do produto. **Não é item pós-v1**: o plugin sai traduzido nos três idiomas, no pacote.

Distinção que importa aqui: *traduzível* e *traduzido* são coisas diferentes e a v1 exige as duas. Tornar traduzível é trabalho de código (envolver toda string em função de i18n com o text domain correto). Entregar traduzido é trabalho de conteúdo, recorrente a cada string nova — e é onde o custo real mora.

**Superfícies cobertas:**

| Superfície | Exemplos |
|---|---|
| Interface administrativa | tela de configurações inteira, labels, textos de ajuda, avisos, mensagens de validação do admin |
| Mensagens ao usuário final | textos de erro do reCAPTCHA exibidos nos formulários do front (falha de verificação, score abaixo do threshold, bloqueio por indisponibilidade) |

**Requisitos derivados:**

| ID | Requisito |
|---|---|
| FR-25 | Toda string exibível passa pelas funções de i18n do WordPress com o text domain `wp-recaptcha-forms`, carregado via `load_plugin_textdomain` (já previsto em FR-01/B-01) |
| FR-26 | O pacote distribuído inclui o `.pot` gerado e os pares `.po`/`.mo` de **pt-BR, en-US e es**, prontos para uso sem ação do usuário |
| FR-27 | As mensagens de erro customizáveis pela tela de config (FR-05) partem de defaults traduzidos; o valor customizado pelo operador é livre e não passa por tradução |
| FR-28 | Nenhuma string exibível fica hardcoded — verificação faz parte do gate de release (S-16) |

Duas notas de decisão:

- **Idioma-fonte é en-US.** O text domain do WordPress usa a string em inglês como chave; adotar pt-BR como fonte criaria um `.po` en-US artificial e quebraria a expectativa de qualquer contribuidor externo. O README principal continua em pt-BR (FR-16) — são coisas independentes.
- **Espanhol como `es` genérico**, não `es_ES`/`es_MX`/`es_AR`. Um mantenedor solo não sustenta variantes regionais, e as strings deste plugin são técnicas o bastante para não sofrerem com isso. Se surgir demanda real, vira variante na v1.x.

**Custo assumido:** o §4.4 já dobrou o conteúdo de setup de chave (dois fluxos); o §4.5 multiplica as strings de interface por três. As duas decisões se compõem — ver R-08.

### 4.6 Tradução como escopo de comunidade — contribuição e indicador de progresso

Decisão do dono do produto, também dentro da v1. Vira o complemento natural do §4.5: três idiomas são compromisso do mantenedor, os demais só existem se a comunidade traduzir — e para isso o caminho precisa estar escrito.

| ID | Requisito |
|---|---|
| FR-29 | README (pt-BR e EN) tem seção **"Como contribuir com traduções"**: onde ficam os arquivos, como gerar/atualizar o `.pot`, como criar um `.po` para um idioma novo a partir dele, que ferramenta usar (Poedit ou equivalente), como testar localmente com o idioma trocado no WordPress, e como abrir o PR — incluindo se o `.mo` compilado entra no PR ou é gerado no CI |
| FR-30 | README exibe **indicador de progresso de tradução por idioma** (barra/badge com % traduzido) cobrindo pt-BR, en-US, es e qualquer idioma que a comunidade adicionar depois |

Duas notas de decisão, porque a barra de progresso é o tipo de item que parece cosmético e não é:

- **O indicador precisa ser gerado, não escrito à mão.** Um número de porcentagem digitado no README desatualiza no primeiro commit e passa a mentir — pior do que não existir, porque um contribuidor escolhe onde ajudar olhando justamente para ele. O requisito é que a porcentagem derive dos `.po` reais. O **como** (badge de serviço externo, badge estático regenerado pelo CI a cada release, ou tabela gerada por script) é decisão de @architect/@devops, com uma restrição de produto: não introduzir dependência de serviço terceiro que possa sumir e deixar um badge quebrado no README — o mesmo tipo de risco de obsolescência do R-03.

- **O indicador é o mecanismo de mitigação do R-08, não enfeite.** Ele torna visível a deriva de tradução — se pt-BR cair para 80%, aparece no README antes de aparecer numa issue de usuário. Faz par direto com o gate S-16, que exige 100% nos três idiomas no release; a barra é o monitoramento contínuo entre releases.

**Escopo desta v1, explicitamente:** en-US, pt-BR e es aparecem no indicador em 100%. Idiomas de comunidade entram no indicador conforme chegam, sem que isso os torne compromisso de manutenção (§6). Um idioma comunitário abaixo de 100% permanece listado com o percentual real — não é motivo para removê-lo nem para segurar release.

---

## 5. Decisões ainda abertas (não bloqueiam este PRD)

| ID | Questão | Dono | Bloqueia |
|---|---|---|---|
| D-04 | Versões mínimas de PHP e WordPress | @architect | FR-18, FR-20 |
| D-05 | Publicar no diretório wordpress.org ou só GitHub | Gustavo | B-24 (pós-v1). Se sim, adiciona `readme.txt`, revisão manual e compromisso de manutenção |
| D-06 | Comportamento com cache de página (tokens v3 expiram em ~2 min; HTML cacheado reprova) | @architect | FR-03, FR-07 — resolver no design, ver R-01 |
| — | Header do plugin não aceita o sufixo `-HHHHHHH-stage`. Proposta do @po: header carrega só `MAJOR.MINOR.PATCH`, string completa em constante interna e CHANGELOG | @architect | FR-14 |

D-01 está resolvido em nível de produto por §4.4; resta a verificação operacional das URLs (FR-24).

---

## 6. Fora de escopo v1 (explícito)

Declarado, não esquecido:

- **Threshold por formulário.** Um único threshold global, alinhado ao mercado. É decisão de design (backlog §1), não limitação a remover depois.
- **Integrações nomeadas** com Contact Form 7, WPForms, Gravity Forms, Elementor Forms. A v1 entrega o mecanismo genérico (FR-11); as integrações nomeadas seguem demanda real.
- **Log, estatística de bloqueios, dashboards, relatórios.**
- **Suporte à API do reCAPTCHA Enterprise**, hCaptcha, Turnstile. (Documentar o fluxo Enterprise de obtenção de chave ≠ suportar a API Enterprise — ver §4.4.)
- **Publicação no diretório wordpress.org** — pendente de D-05, escopo adicional próprio.
- **Multisite / network admin.**
- **Idiomas além de pt-BR, en-US e es**, e variantes regionais do espanhol (`es_ES`, `es_MX`...) — ver §4.5. Traduções da comunidade são bem-vindas e têm caminho documentado (§4.6), mas só três idiomas são compromisso de manutenção do autor.
- **Plataforma de tradução colaborativa** (Crowdin, Weblate, GlotPress próprio). O fluxo da v1 é `.po` + PR no GitHub, ver §4.6.
- **Tradução dos READMEs para espanhol.** A v1 mantém pt-BR e EN (FR-16/FR-17); `es` cobre a interface e as mensagens, não a documentação.
- **Whitelist de IPs e bypass por capability** (B-23).
- **Formulários de cancelamento e perfil do Newsletter** (§3.2).

Fronteiras de acoplamento (backlog §6, reafirmadas): nenhuma dependência ou código compartilhado com `tania-content-model`; WooCommerce e Newsletter nunca são dependência dura.

---

## 7. Riscos

| ID | Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|---|
| **R-01** | **Cache de página quebra o v3 silenciosamente.** Token do v3 vive ~2 min; HTML cacheado por WP Rocket/LiteSpeed/Cloudflare serve token expirado e o formulário reprova usuário legítimo | Alta | Alto | É o bug clássico e silencioso desta categoria de plugin. Resolver no design (D-06, @architect), não descobrir em produção. Gerar token no cliente em tempo de submissão, nunca embutir no HTML. Testar sob cache antes do release |
| **R-02** | **Checkout em Blocos é integração de natureza diferente** (React/Store API vs. hooks PHP) e consome mais esforço que o backlog dimensionou | Alta | Médio | Risco assumido conscientemente (§4.2). Fatiar FR-12 em duas stories distintas — clássico e Blocos — para que o clássico não fique refém do Blocos. @architect dimensiona antes do @sm quebrar |
| **R-03** | **Obsolescência rápida da documentação do console clássico.** O Google já está depreciando `recaptcha/admin`; quando desligar, metade da §4.4 vira instrução morta e a persona P1 fica sem caminho sem GCP | Alta | Alto | Tratar como risco de manutenção contínua, não item único. (a) Isolar as trilhas de setup numa seção própria e versionada do README, para reescrita barata; (b) escrever a trilha Enterprise como caminho recomendado desde já, não como alternativa secundária; (c) registrar data da última verificação de URL junto ao texto; (d) revisão programada da documentação de chaves a cada release MINOR |
| **R-04** | **Migração do Google para Enterprise afeta o `siteverify` clássico**, não só o console. Se o endpoint que a FR-02 consome for depreciado, a camada de verificação inteira precisa mudar | Média | Crítico | Isolar o cliente HTTP atrás de uma fronteira interna estreita (@architect), para que trocar o endpoint não irradie pelo plugin. Monitorar anúncios de depreciação do Google |
| **R-05** | **Exigência de faturamento no GCP afasta a persona P1** no onboarding — dono de loja pequena não abre conta com cartão para instalar plugin gratuito | Média | Médio | Manter a trilha do console clássico documentada e funcional enquanto existir; ser explícito no README sobre a exigência antes de o usuário investir tempo. É honestidade de produto, não obstáculo a esconder |
| **R-06** | **Fail-open configurável vira vetor de spam** em instalação mal configurada, e a culpa recai no plugin | Média | Baixo | Default documentado, aviso na tela de config explicando o trade-off em linguagem de operador, e a decisão registrada no README |
| **R-08** | **Deriva de tradução.** Toda string nova precisa entrar em três idiomas. Num projeto solo, o padrão é o inglês avançar e pt-BR/es congelarem, deixando a interface meio traduzida — pior que não traduzida, porque parece defeito | Alta | Médio | Gate de release exige `.po` sem entradas vazias nos três idiomas (S-16). Regenerar o `.pot` no CI e falhar quando houver string não traduzida antes de tag de release. O indicador de progresso do FR-30 é o monitoramento contínuo entre releases — torna a deriva visível antes de virar issue de usuário. Compõe com R-03: mudança no fluxo de chave do Google gera texto novo em 3 idiomas × 2 READMEs |
| **R-07** | **Manutenção solo.** Um mantenedor, superfície ampla (4 formulários nativos + 3 pontos Woo + Newsletter + API pública), matriz WP/PHP | Alta | Médio | Cobertura de teste na camada de verificação (FR-18) é o investimento de maior retorno. Escopo P3 permanece fechado até a v1 estar estável |

Sobre R-03 e R-04, que é o cluster mais perigoso: eles são correlacionados e ambos externos. Nenhuma engenharia elimina; o que se compra é **custo baixo de reação**. Por isso as mitigações são estruturais (isolar a fronteira HTTP, isolar a seção de docs) e não preventivas.

---

## 8. Critérios de sucesso da v1

### Gate de release (binário — sem isso não sai)

| # | Critério |
|---|---|
| S-01 | Todos os FR-01 a FR-30 entregues e verificados |
| S-02 | Instalação limpa: ativa e desativa em WP virgem sem notice ou warning; desinstalação remove todas as opções |
| S-03 | Sem WooCommerce instalado: zero erro fatal, zero notice, seção Woo corretamente ocultada ou marcada indisponível |
| S-04 | Checkout Woo **clássico e em Blocos** validados manualmente, convidado e logado |
| S-05 | Comportamento sob cache de página validado com pelo menos um plugin de cache real (R-01) |
| S-06 | Fail-open e fail-close verificados com o Google simulado indisponível, no nível global e no override por formulário |
| S-07 | PHPCS+WPCS sem erro; secret key comprovadamente ausente do HTML e dos assets do front |
| S-08 | Testes da camada de verificação passando com HTTP mockado, na matriz WP/PHP declarada |
| S-09 | LICENSE GPLv2-or-later presente e header coerente |
| S-10 | README pt-BR e README-EN em paridade, ambos com os dois fluxos de obtenção de chave, e todas as URLs verificadas na data do release |
| S-16 | `.pot` regenerado e `.po`/`.mo` de pt-BR, en-US e es sem entradas vazias ou fuzzy; nenhuma string exibível hardcoded; admin e mensagens de erro do front conferidos visualmente nos três idiomas |
| S-17 | Seção "Como contribuir com traduções" presente nos dois READMEs, com o fluxo testado ponta a ponta ao menos uma vez (criar `.po` de idioma novo a partir do `.pot` e vê-lo carregar) — instrução não verificada é instrução errada |
| S-18 | Indicador de progresso por idioma renderizando no README, com percentual **derivado dos `.po` reais** e marcando 100% em en-US, pt-BR e es |

### Sinais de sucesso pós-release (90 dias)

Métricas de um projeto solo gratuito. São indicadores de direção, não OKRs; nenhum deles justifica ampliar escopo sozinho.

| # | Sinal | Leitura |
|---|---|---|
| S-11 | Nenhuma issue aberta de erro fatal ou site quebrado | O critério que mais importa. Um plugin de segurança que derruba site perde a confiança de uma vez |
| S-12 | Issues de "não consigo criar a chave" abaixo de ~1/3 do total de issues | Se passar disso, a §4.4 falhou e a documentação precisa de rodada dedicada |
| S-13 | Ao menos um relato de terceiro usando a API pública (FR-11) | Valida a persona P3 e justifica o custo de manter contrato estável |
| S-14 | Ao menos um relato de instalação com WooCommerce em produção | Valida a tese central de cobertura (§1) |
| S-15 | Ambas as trilhas de obtenção de chave ainda válidas, ou a documentação atualizada em até 30 dias após mudança do Google | Mede a resposta a R-03, que é o risco de prazo mais curto |

O que **não** é critério de sucesso da v1: número de instalações, estrelas no GitHub, presença no diretório wordpress.org (pendente de D-05), ou paridade de features com os plugins pagos. A v1 tem sucesso se cobre o que prometeu e não quebra nada.

---

## 9. Próximos passos

1. **@architect** (com @devops no item de CI) — definir como o percentual do FR-30 é derivado dos `.po` sem depender de serviço terceiro que possa quebrar o badge (§4.6); resolver D-04, D-06 e o header de versão; definir a taxonomia de falhas de §4.3; isolar a fronteira HTTP (R-04); dimensionar FR-12 separando clássico de Blocos (R-02).
2. **@sm** — quebrar em stories: FR-01 a FR-07 (fundação) antes de FR-08 a FR-13 (cobertura). FR-14 a FR-24 são gate de release, não backlog opcional. FR-25/FR-28 (código traduzível) são disciplina transversal a toda story, não story própria; FR-26/FR-27 (arquivos de tradução prontos nos 3 idiomas) e FR-29/FR-30 (contribuição e indicador de progresso) rendem uma story própria no fim, junto do gate de release. FR-30 tem componente de CI — coordenar com @devops.
3. **Gustavo** — D-05 (wordpress.org) quando a v1 estiver estável; não bloqueia nada agora.
