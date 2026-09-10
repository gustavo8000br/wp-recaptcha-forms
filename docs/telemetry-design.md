# Telemetria — desenho de arquitetura

**Autor:** @architect (Aria)
**Data:** 2026-09-09
**Status:** **PROPOSTA. Nada aqui está implementado, e nada deve ser implementado antes de aprovação humana explícita.**
**Escopo:** formato de envelope genérico para `telemetry.gustavomathias.dev` (multi-projeto) + o que este plugin enviaria + consentimento + transporte
**Contradiz, hoje:** `README.md` linha 46 e `README-EN.md` linha 45 — ver §5

---

## 0. Aviso que precede tudo

O produto hoje promete, na abertura do README, em negrito:

> **Sem telemetria, sem relatórios, sem histórico de bloqueios.** Não há tabela nova no banco.

Isso não é detalhe de documentação: é **compromisso de privacidade publicado**, na seção
"o que ele não faz, dito na abertura para você não descobrir depois". A arquitetura v1.1
§1.4 reforça, ao registrar contagem por janela como candidato v1.x explicitamente porque
"contagem é telemetria, que o PRD §6 exclui da v1".

Implementar telemetria é **mudança de escopo de produto**, não incremento técnico. Este
documento desenha *como* fazer isso sem quebrar a confiança que aquela frase construiu.
Se a resposta do dono for "não vale a pena", o desenho continua útil para os outros
projetos dele — a §1 e a §2 não têm nada de WordPress.

---

## PARTE I — O envelope genérico

## 1. O que o envelope é

Um contrato de ingestão que **qualquer** cliente do dono (WordPress, Node, CLI, worker,
mobile) preenche igual, para que a API não precise de código por projeto.

Princípio de desenho que governa o resto: **o envelope descreve a origem, o payload
descreve o assunto, e os dois versionam separadamente.** Se estiverem no mesmo esquema, o
dia em que o plugin de reCAPTCHA quiser um campo novo é o dia em que a API precisa de
migração que afeta todos os outros projetos. Separados, um projeto novo entra sem tocar em
nada — a API valida o envelope, guarda o payload como documento opaco, e só o consumidor
daquele `payload_schema` o interpreta.

### 1.1 Estrutura

```json
{
  "schema": "gm.telemetry/v1",
  "envelope_id": "0d1b1f7a-2f4c-4a01-9d0e-3a2b5c7e91aa",
  "sent_at": "2026-09-09T03:17:44Z",

  "source": {
    "project": "wp-recaptcha-forms",
    "platform": "wordpress",
    "version": "1.2.0",
    "instance_id": "b7f3c1a9e2d84f60b1c5a7d3e9f04628"
  },

  "environment": "production",

  "event": {
    "type": "usage.snapshot",
    "occurred_at": "2026-09-09T03:17:44Z",
    "window": { "from": "2026-09-02T00:00:00Z", "to": "2026-09-09T00:00:00Z" },
    "seq": 37
  },

  "payload_schema": "wp-recaptcha-forms/usage.snapshot/v1",
  "payload": { "...": "específico do projeto, ver §3" }
}
```

### 1.2 Campos, um a um

| Campo | Tipo | Obrig. | Regra |
|---|---|---|---|
| `schema` | string | sim | versão do **envelope**. `gm.telemetry/v1`. A API rejeita o que não reconhece, com 400 e corpo explicando — nunca aceita silenciosamente. |
| `envelope_id` | UUID v4 | sim | **idempotência**. O cliente gera antes de enviar e **reusa no retry**. A API trata reenvio do mesmo `envelope_id` como no-op e devolve 200. Sem isso, toda falha de rede vira dado duplicado, e a série temporal mente para cima justamente quando a rede está ruim. |
| `sent_at` | ISO-8601 UTC | sim | quando o cliente tentou enviar. Difere de `event.occurred_at` num retry — e é por isso que os dois existem. |
| `source.project` | slug | sim | identificador estável do software. Não é o nome de exibição, não muda com rebranding. |
| `source.platform` | enum | sim | `wordpress` \| `node` \| `cli` \| `browser` \| `worker` \| `other`. Enum fechado e pequeno: serve para roteamento e agregação, não para descrever o mundo. |
| `source.version` | SemVer | sim | versão do software que envia. É o campo que torna o dado acionável: "o bug X só aparece na 1.3" é a pergunta que telemetria responde melhor. |
| `source.instance_id` | hex/UUID | sim | **ver §1.3 — é o campo que decide se este desenho é honesto.** |
| `environment` | enum | sim | `production` \| `staging` \| `development` \| `unknown`. Sem isso, o dado de CI e de máquina de dev contamina a série de produção. `unknown` é valor legítimo, e obrigar a mentir seria pior. |
| `event.type` | slug pontuado | sim | `usage.snapshot`, `error.report`, `lifecycle.activated`, `lifecycle.deactivated`. Namespace genérico; o significado fino vive no payload. |
| `event.occurred_at` | ISO-8601 UTC | sim | quando o fato ocorreu. |
| `event.window` | `{from,to}` | não | obrigatório quando o payload é **agregado**. Sem janela declarada, taxa não é calculável e o número não significa nada. |
| `event.seq` | inteiro | não | contador monotônico por instância. Permite detectar **buraco** na série ("faltam os envios 12 a 19") sem que o cliente precise reportar suas próprias falhas. |
| `payload_schema` | `projeto/tipo/vN` | sim | versão do payload, **independente** do envelope. |
| `payload` | objeto | sim | opaco para o envelope. Limite duro sugerido: 64 KB. |

### 1.3 `instance_id` — a decisão que a maioria dos desenhos erra

**Regra: valor aleatório, gerado no momento do opt-in, guardado localmente, jamais
derivado de qualquer atributo da instalação.**

A tentação natural é `sha256(site_url)` ou `sha256(domínio + salt)`. É uma
pseudonimização **falsa**, e vale escrever por que, porque o erro é comum e parece
seguro:

- Sem sal, um hash de domínio é reversível por dicionário. O conjunto de domínios `.br`
  registrados é público e finito. Hashear alguns milhões de candidatos custa segundos.
- Com sal fixo embutido no software, o sal está no código-fonte de um plugin GPL. Não é
  segredo.
- Com sal por instalação, o hash já não é derivado — é aleatório. Então use aleatório
  direto, sem a aparência de que existe uma relação com o domínio.

Consequências de projeto que caem de graça de "aleatório":

- **Opt-out apaga o `instance_id`.** Não é pausa, é ruptura: ao reativar, a instalação é
  uma nova instância. O operador que desligou não pode ser recolado ao histórico dele.
- **Desinstalar apaga.** Entra no `uninstall.php` junto das demais opções.
- **Não permite contar sites únicos com precisão.** Aceito de propósito. Quem quer contar
  ativações precisa de um identificador estável de instalação, e um identificador estável
  de instalação é dado pessoal na leitura mais defensável da LGPD/GDPR quando o titular é
  pessoa física — que é o caso de boa parte dos sites WordPress.

### 1.4 O que o envelope nunca carrega — em qualquer projeto

Regra do contrato, não deste plugin: `url`, `domain`, `host`, `site_name`, `email`,
`user_id`, `ip`, `user_agent`, path de arquivo do servidor, chave de API, conteúdo
enviado por usuário. Um campo livre de texto vindo do usuário final também não — é o
vetor por onde PII entra em toda plataforma de telemetria que já vazou PII.

**Sugestão de gate no lado da API**, e vale mais que qualquer política escrita: a ingestão
**rejeita com 400** um envelope cujo payload contenha chave que case
`/(^|_)(ip|email|url|domain|host|user|token|secret|key)($|_)/`, ou valor que case regex de
e-mail, de IPv4/IPv6 ou de URL absoluta. Um cliente mal escrito é descoberto pelo servidor
na primeira tentativa, e não seis meses depois numa auditoria. É a mesma disciplina do
gate de fronteira que este plugin já aplica no CI.

### 1.5 O que a API vê e o envelope não diz — a nota de honestidade

Uma requisição HTTPS de um site para `telemetry.gustavomathias.dev` entrega ao servidor,
no nível de transporte, o **endereço IP de origem**. Isso identifica o servidor de
hospedagem, e num VPS dedicado identifica o site com alta confiança — por mais limpo que
o envelope seja.

Portanto a promessa de anonimato depende de uma decisão **do lado do servidor**, e ela
precisa estar escrita na política pública da API:

> A API não registra o endereço IP de origem, e o desliga na camada de log do proxy
> (`nginx`: `access_log off` ou máscara; Cloudflare: não repassar `CF-Connecting-IP`).

Sem isso, o texto de privacidade do plugin fica dizendo uma coisa que a infraestrutura
contradiz. É exatamente a armadilha que a arquitetura v1.1 §3.3 já nomeia no contexto do
`remoteip`: *"desligar este envio não impede que o Google receba o endereço IP, porque o
script é carregado diretamente do domínio do Google"*. A mesma disciplina, aplicada ao
próprio dono.

### 1.6 Resposta da API

```
202 Accepted   { "accepted": true, "envelope_id": "..." }
200 OK         { "accepted": true, "duplicate": true }        idempotência (§1.2)
400 Bad Request{ "error": "schema_unknown" | "pii_suspected" | "payload_too_large" }
429 Too Many   Retry-After: <segundos>
5xx            o cliente NÃO insiste — ver §6.3
```

O cliente **ignora o corpo da resposta** em operação normal. Uma API de telemetria que
devolve instrução ao cliente é uma API que pode alterar o comportamento do site de
terceiro à distância, e isso é uma superfície que este desenho recusa de propósito.

---

## PARTE II — O que este plugin enviaria

## 2. Por que este plugin é um bom primeiro cliente

Ele já tem, no desenho, exatamente uma coisa que telemetria mede bem e que nada mais
mede: **a distribuição das quatro classes de falha**. A arquitetura v1.1 §1.4 diz, sobre
detectar bypass de `CLIENT_UNREACHABLE`:

> Um site sob ataque vê `CLIENT_UNREACHABLE` subir de ~2-5% (baseline de adblock) para a
> maioria dos envios. Contagem por janela de tempo é o detector natural — mas contagem é
> telemetria, que o PRD §6 exclui da v1.

O baseline "2-5%" está lá como estimativa. Telemetria o transforma em número medido — e
esse número é o que permite escolher um limiar de alerta que não seja chute.

**Delimitação, porque a tentação é grande:** telemetria **não pode virar o controle de
segurança**. Ela é opt-in, então a maioria das instalações não a terá. Um detector de
ataque que só funciona em quem aceitou telemetria é um detector que não existe. O que a
telemetria dá é **calibração** do detector local; o detector local continua sendo local.

## 3. Payload — `wp-recaptcha-forms/usage.snapshot/v1`

```json
{
  "plugin": {
    "provider": "v3",
    "threshold_bucket": "0.5-0.7",
    "remoteip": false,
    "consent_mode": "auto",
    "policy_infra": "allow",
    "policy_client_unreachable": "allow",
    "forms_enabled": ["wp_comment", "wp_login", "woo_checkout_blocks"],
    "forms_enabled_count": 3,
    "kill_switch": false
  },

  "host": {
    "php": "8.2",
    "wp": "6.7",
    "mysql": "10.11",
    "locale": "pt_BR",
    "multisite": false,
    "woocommerce": "9.4",
    "woocommerce_blocks_checkout": true
  },

  "verdicts": {
    "total_bucket": "1k-10k",
    "distribution": {
      "allowed":            0.943,
      "rejected":           0.041,
      "infra":              0.002,
      "misconfig":          0.000,
      "client_unreachable": 0.014
    },
    "blocked_share": 0.041,
    "by_form": {
      "woo_checkout_blocks": { "share_of_total": 0.62, "client_unreachable": 0.021 }
    }
  },

  "health": {
    "misconfig_days_active": 0,
    "keypair_suspect_triggered": false
  }
}
```

### 3.1 Por que cada bloco existe

**`plugin`** — responde "qual configuração as pessoas realmente usam". Hoje isso é
opinião. Se 90% ficar em `consent_mode: off`, a §2 da v1.1 acertou o default; se
`policy_client_unreachable` for mudado para `block` por muita gente, o default fail-open
da §1.4 precisa de revisão. É a categoria de dado que muda decisão de produto.

`threshold_bucket` e não `threshold`: um float de score não é sensível, mas balde é
suficiente para a pergunta ("as pessoas mexem no default?") e mantém a disciplina de não
coletar precisão que não se usa.

**`host`** — versão **MINOR** de PHP e WP, nunca a versão de patch. `8.2`, não `8.2.11`.
Patch não muda decisão de compatibilidade e aumenta a granularidade de fingerprint. É o
dado que responde "posso subir o piso de PHP para 8.0?", que hoje é adivinhação.

**`verdicts`** — o núcleo. **Proporções, não contagens.** Contagem absoluta de submissões
é volume de negócio do operador, e ele não concordou em compartilhar volume de negócio
comigo. `total_bucket` (`<100`, `100-1k`, `1k-10k`, `10k-100k`, `>100k`) dá a ordem de
grandeza necessária para ponderar a proporção sem entregar o número.

`by_form` só inclui formulários **com pelo menos 50 avaliações na janela** — sob volume
baixo, proporção não é estatística, é o comportamento de um punhado de visitantes
identificáveis.

**`health`** — `misconfig_days_active > 0` significa que existe instalação rodando
degradada há dias sem que a escalada da §4.3 tenha resolvido, e isso é falha de UX minha,
não do operador. É o dado que mais provavelmente gera correção de produto.

### 3.2 Nunca, neste payload

IP de visitante, IP do servidor, conteúdo de formulário, e-mail, `site_url`, nome do site,
nome de usuário, User-Agent, **site key ou secret key** (nem hash — a site key é pública
mas identifica o projeto no console do Google, e o par identifica o operador), lista de
plugins instalados, tema, contagem de posts, contagem de usuários.

`forms_enabled` é lista de **`form_id` do vocabulário fechado do plugin** — nove valores
possíveis, todos definidos por nós. Não é dado do site.

### 3.3 Fonte dos números, e o custo que isso tem

Não existe hoje onde guardar isso. O que existe são os dois contadores em transient de uma
hora da v1.1 §4.4, e eles morrem antes da janela.

O desenho mínimo: **uma opção `wp_recaptcha_forms_telemetry_counters`, não-autoloaded**,
com contadores por classe e por `form_id`, zerada no envio.

Duas consequências que precisam estar na proposta e não na descoberta:

1. **É uma escrita no banco por submissão avaliada.** Um `UPDATE` numa linha indexada, no
   caminho de um POST que já vai escrever comentário/pedido/usuário. Marginal em tráfego
   normal. **Sob ataque, é amplificação de escrita exatamente no pior momento** — que é a
   mesma janela em que o dado fica interessante. Mitigação: só incrementar quando a
   telemetria está ligada (a instalação que não optou não paga nada), e agrupar as
   escritas por request.
2. **"Não há tabela nova no banco" continua verdade.** É uma opção, não uma tabela.
   Continua sendo histórico agregado, e a frase do README precisa mudar de qualquer forma
   (§5) — mas a promessa mais forte, a de não criar estrutura no banco do usuário, é
   preservável.

---

## PARTE III — Consentimento

## 4. Opt-in — mecanismo próprio, e por que **não** reusar o `ConsentGate`

A tentação é reusar `src/Consent/`. **É erro de categoria, e reusar produziria um bug de
privacidade, não economia de código.**

| | `ConsentGate` (existe) | Telemetria (proposto) |
|---|---|---|
| **Titular do dado** | o **visitante** do site | o **operador** do site |
| **Quem decide** | o visitante, via CMP | o operador, no wp-admin |
| **O que autoriza** | carregar script do Google no navegador dele | enviar dado agregado da instalação para um terceiro |
| **Como é revogado** | pelo CMP, por visitante | pelo operador, para a instalação toda |
| **Default** | `off` = carrega (§2.2 da v1.1) | **desligado = não envia. Sempre.** |

Ligar os dois faria o aceite de cookies de um visitante anônimo autorizar o envio de dados
da instalação do operador. Ninguém consentiu com isso.

**O que se reusa é o padrão, não o mecanismo:** opção de vocabulário fechado, resolução
num ponto único, filtro para o integrador, estado visível na tela de config, e a
disciplina da §3.3 de que o texto exibido reflete a configuração real.

### 4.1 A opção

```php
'telemetry' => array(
    'enabled'      => false,   // opt-in. Alterar este default é MAJOR — ver §7.
    'instance_id'  => '',      // gerado no aceite, apagado na revogação (§1.3)
    'last_sent'    => 0,
    'last_status'  => '',      // 'ok' | 'error' | '' — diagnóstico local, nunca notice
    'seq'          => 0,
),
```

Regras não negociáveis:

1. **`false` por padrão, sempre, inclusive em instalação nova.** Nunca opt-out, nunca
   "ligado durante o período de avaliação", nunca ligado por atualização.
2. **Uma atualização do plugin nunca liga.** Instalação existente que atualiza continua
   sem enviar nada, e o único efeito visível é o toggle novo, desmarcado.
3. **Sem dark pattern na primeira execução.** Nada de modal com "Ajude a melhorar o
   plugin" e botão de aceitar em destaque contra um "agora não" apagado. Um aviso
   dispensável, uma vez, ou nada.
4. **`WRF_DISABLE` também desliga telemetria**, e existe
   `define('WRF_TELEMETRY_DISABLE', true)` para desligar só a telemetria. Consistente com
   a v1.1 §5: quem administra por `wp-config.php` precisa poder desligar sem tocar no
   banco — e o operador de hospedagem gerenciada, que não é o mesmo que o dono do site,
   precisa poder desligar para toda a frota.

### 4.2 A tela de config

Seção própria, **abaixo** de tudo que afeta o funcionamento do plugin, porque não afeta:

```
┌─ Telemetria (opcional) ────────────────────────────────────────────────┐
│                                                                        │
│  [ ] Enviar estatísticas de uso anônimas para o autor do plugin        │
│                                                                        │
│  Envia, uma vez por semana: versões de PHP, WordPress e do plugin,     │
│  quais formulários você protegeu, sua configuração, e a proporção      │
│  entre envios aprovados e bloqueados.                                  │
│                                                                        │
│  Não envia: o endereço do seu site, e-mails, endereços IP, conteúdo    │
│  de formulários, suas chaves do reCAPTCHA, nem qualquer dado dos seus  │
│  visitantes ou clientes.                                               │
│                                                                        │
│  [ Ver exatamente o que será enviado ]     Política de privacidade da  │
│                                            telemetria ↗                │
│                                                                        │
│  Desligar apaga o identificador desta instalação. Ao religar, ela      │
│  passa a ser uma instalação nova, sem ligação com o histórico anterior.│
└────────────────────────────────────────────────────────────────────────┘
```

**"Ver exatamente o que será enviado" é o item que mais importa da tela**, e é barato: um
`<pre>` com o JSON real que seria enviado agora, gerado pelo mesmo código do envio — nunca
por um exemplo escrito à mão que envelhece e passa a mentir. Transparência verificável
vale mais que qualquer parágrafo de política, e a auditoria de um cético leva trinta
segundos em vez de exigir um `tcpdump`.

O botão fica **disponível com o toggle desligado**. Ninguém decide sobre um dado que só
pode ver depois de aceitar enviá-lo.

O link "Política de privacidade da telemetria" aponta para uma página pública mantida pelo
dono da API — que precisa existir **antes** do lançamento, e que precisa conter a promessa
de não registrar IP de origem da §1.5.

### 4.3 Texto de privacidade

`PrivacyNotice` (v1.1 §3) já monta o texto por configuração e já atende FR-32 ("estado de
privacidade fiel"). Ganha um bloco **condicional**:

- **Telemetria ligada:** *"Este site envia semanalmente ao autor do plugin estatísticas
  agregadas de uso, sem endereço do site, sem dados de visitantes e sem conteúdo de
  formulários."*
- **Telemetria desligada:** nenhum bloco. Não se descreve o que não acontece.

Cai de graça no gate de pseudo-locale (v1.1 §3.4), como toda string do plugin.

**Nota jurídica, sem fingir que é parecer:** o operador é controlador dos dados do site
dele; ao ligar o toggle ele autoriza um envio para o dono do plugin, que passa a ser
**operador/processador de um dado que, sendo agregado e sem identificador de site, tende a
não ser dado pessoal**. "Tende a não ser" é a formulação honesta — depende de o
compromisso da §1.5 ser cumprido no servidor. Se a API registrar IP, a análise muda, e o
plugin terá dito uma inverdade em nome do operador.

---

## PARTE IV — Transporte

## 5. Mudanças obrigatórias no README

Não é ajuste redacional: hoje o README **contradiz** este desenho. A frase atual —
"**Sem telemetria, sem relatórios, sem histórico de bloqueios.**" — está numa lista
intitulada "o que ele **não** faz, dito na abertura para você não descobrir depois".
Deixá-la de pé com telemetria implementada, ainda que opt-in, torna o próprio README a
prova de que o projeto não cumpre o critério que ele estabelece.

Redação proposta (`README.md` linha 46, e o equivalente em `README-EN.md` linha 45):

> - **Sem telemetria ligada por padrão, sem relatórios, sem histórico de bloqueios.** Não
>   há tabela nova no banco. Existe um envio opcional de estatísticas de uso, **desligado
>   de fábrica**, que só passa a ocorrer se você marcar a caixa em Configurações →
>   reCAPTCHA → Telemetria; a tela mostra o conteúdo exato antes de você decidir. Nenhuma
>   atualização do plugin liga isso por você. O que existe sem telemetria são dois
>   contadores em transient de uma hora, usados só para um aviso de diagnóstico, e que
>   morrem sozinhos.

Também mudam:

- **`readme.txt` do WP.org** (se for o destino). As diretrizes do repositório oficial
  tratam "phoning home" como item de revisão: exige divulgação clara e consentimento
  explícito, e ligado por padrão é motivo de rejeição. Este desenho satisfaz, **desde que
  a divulgação esteja no `readme.txt`, não só no `README.md`** — o revisor lê o primeiro.
- **`CHANGELOG.md`** — entrada explícita, com a palavra "telemetria" visível. Quem audita
  changelog procura por essa palavra.
- **`uninstall.php`** — apagar `instance_id`, contadores e a opção de telemetria.
- **`docs/prd-v1.md` §6**, que exclui telemetria da v1. Escopo de produto muda no PRD, não
  aqui; **@pm**.

## 6. Transporte

### 6.1 Quando

**WP-Cron, semanal, nunca no caminho de uma submissão.**

Nenhum envio acontece dentro do request de um formulário. Um POST de checkout esperando
`telemetry.gustavomathias.dev` responder é um checkout que meu servidor pode derrubar — e
o plugin existe para não perder venda. Regra absoluta.

Semanal, e não diário: o dado é configuração e proporção agregada, que muda em escala de
semanas. Diário multiplica por sete o custo de ingestão e o ruído sem responder pergunta
nova.

**Jitter obrigatório.** Um evento agendado "toda segunda às 00:00" faz toda a base bater na
API no mesmo minuto — DDoS acidental por design. O offset vem do `instance_id`:

```php
$offset = hexdec( substr( $instance_id, 0, 4 ) ) % ( 7 * DAY_IN_SECONDS );
```

Determinístico por instalação, uniformemente espalhado pela base, sem estado extra.

`wp_doing_cron()` real, não `wp_doing_ajax()`. Em site com `DISABLE_WP_CRON` e cron de
sistema, funciona igual. Em site sem tráfego, o WP-Cron não dispara — e uma instalação sem
tráfego não tem dado interessante. Perda aceita, e o `event.seq` (§1.2) faz o buraco
aparecer no lado do servidor em vez de virar silêncio.

### 6.2 Como

```php
wp_remote_post( $endpoint, array(
    'timeout'     => 5,
    'redirection' => 0,          // nenhum redirect é seguido
    'blocking'    => true,       // ver §6.3
    'sslverify'   => true,       // nunca desligar, nem "temporariamente"
    'headers'     => array(
        'Content-Type'  => 'application/json',
        'User-Agent'    => 'wp-recaptcha-forms/' . WRF_VERSION,   // sem site_url()
        'Idempotency-Key' => $envelope_id,
    ),
    'body'        => wp_json_encode( $envelope ),
) );
```

- **`timeout` 5 s**, o mesmo do `siteverify` (arquitetura v1 §4.3). É cron; ninguém está
  esperando. Mais que isso prende um worker de cron do operador por causa da minha API.
- **`redirection => 0`.** Redirect em endpoint de telemetria é ou erro meu ou sequestro de
  DNS. Não seguir é a diferença entre falhar e entregar o payload noutro lugar.
- **`User-Agent` sem `site_url()`.** O default do WordPress inclui a URL do site. É o
  vazamento mais fácil de cometer neste desenho inteiro: o envelope fica impecável e o
  domínio vai no cabeçalho. **Item obrigatório de revisão de código e candidato natural a
  gate de CI**, no espírito do gate de fronteira da v1.1 §8.2 — um `grep` que proíba
  `site_url`/`home_url`/`get_bloginfo` dentro de `src/Telemetry/`.

### 6.3 Falha

**Fire-and-forget com uma retentativa, e nada além disso.**

`blocking => false` seria fire-and-forget puro e é tentador, mas impede saber se
funcionou — e sem isso não há como decidir se retenta nem como preencher `last_status`.
Como estamos em cron, bloquear 5 s não custa a ninguém que esteja esperando.

| Situação | Comportamento |
|---|---|
| 2xx | zera contadores, incrementa `seq`, grava `last_sent` |
| `WP_Error`, timeout, 5xx | **uma** retentativa em 1 h, mesmo `envelope_id`; depois desiste e espera a próxima janela semanal |
| 429 | respeita `Retry-After`; sem retentativa antes disso |
| 400 | **desiste e não retenta.** Payload malformado não melhora repetindo. Registra `last_status` e, se for `pii_suspected`, isso é bug meu e tem de aparecer em `error_log` sob `WP_DEBUG` |
| API fora do ar por semanas | nada acontece. Sem fila, sem acúmulo, sem crescer opção no banco do usuário |

**Nunca** admin notice por falha de telemetria. A API do dono estar fora do ar não é
problema do operador, e um aviso amarelo no painel dele por causa disso seria transformar
meu incidente na ansiedade dele. Contraste deliberado com MISCONFIG (v1.1 §4.3), que é
notice não-dispensável porque **é** problema dele.

**Nunca** desligar a proteção por falha de telemetria. O envio vive num caminho que não
cruza o `Gate` em ponto nenhum. Contadores não lidos apenas continuam acumulando até a
próxima janela, e a opção que os guarda é limitada por construção (nove `form_id`, quatro
classes).

Zerar os contadores **só depois** do 2xx — mas sem fila: se o envio da semana falhou, os
contadores da semana seguinte incluem a anterior, e `event.window.from` diz isso. Janela
declarada resolve, e é para isso que ela é obrigatória em payload agregado (§1.2).

---

## 7. Versionamento e superfície nova

Pela regra do `CHANGELOG.md` e da v1.1 §8.5:

- Implementar isto é **MINOR** — opção nova retrocompatível, comportamento inalterado
  para quem não liga.
- Mudar o default de `telemetry.enabled` de `false` para `true` seria **MAJOR**, pelo
  mesmo raciocínio que torna MAJOR mudar o default de `CLIENT_UNREACHABLE`: mudança
  silenciosa de postura em instalações existentes. **Registre-se desde já que este projeto
  não pretende fazê-lo.**
- Alterar `gm.telemetry/v1` de forma incompatível é problema **da API**, não do plugin — e
  é exatamente por isso que o envelope é versionado no campo `schema` (§1.1). Clientes
  antigos continuam enviando `v1` para sempre; um plugin instalado não atualiza porque a
  API evoluiu.

Superfície nova a ser desenhada em detalhe **se e quando** houver aprovação:
`src/Telemetry/` (montagem do envelope, coleta, transporte), o gate de CI da §6.2, e o
bloco condicional em `PrivacyNotice`.

---

## 8. Decisões que voltam para o dono do produto

| # | Decisão | Por que é dele, e não minha |
|---|---|---|
| **T-1** | **Fazer telemetria?** | Reverte um compromisso publicado na abertura do README. É posicionamento de produto. |
| **T-2** | A política de privacidade pública da API existe e promete **não registrar IP de origem** (§1.5)? | Sem isso o texto do plugin mente. É pré-requisito de lançamento, não detalhe. |
| **T-3** | Proporções + balde de volume, ou contagens exatas (§3.1)? | Contagem exata expõe volume de negócio do operador. Recomendo proporção; é chamada de confiança, não técnica. |
| **T-4** | Aceita a escrita por submissão avaliada (§3.3), incluindo a amplificação sob ataque? | Custo imposto ao site do usuário em troca de dado para mim. |
| **T-5** | Destino é o WP.org? | Muda o rigor da divulgação e obriga `readme.txt` (§5). |
| **T-6** | Semanal é a cadência certa? | Recomendo semanal; é juízo de valor sobre custo de ingestão. |

**Minha recomendação, e não é neutra:** o desenho está sólido e a §1 vale para os outros
projetos independentemente. Mas para **este** plugin especificamente, o item mais valioso
do README hoje é a frase que este documento propõe reescrever. "Sem telemetria" é, num
plugin de segurança que já carrega script do Google, uma diferenciação real e verificável
— o oposto da "diferenciação inexistente" que o PRD §1 proíbe. Trocá-la por "telemetria
opt-in, desligada de fábrica" é defensável e honesto; mas é uma troca, e o que se recebe
em troca (dado de calibração de uma minoria que optou) precisa ser julgado contra o que se
entrega. Sugiro: **construa a API e o envelope agora; faça deste plugin o segundo cliente,
não o primeiro.**
