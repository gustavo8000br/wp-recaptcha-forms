# Revisão de QA — Telemetria (Stories 1.26–1.34)

**Revisor:** @qa (Quinn)
**Data:** 2026-09-10
**Escopo:** 10 commits em `main` local (`783cf78`..`278b656`), não empurrados
**Base do desenho:** `docs/telemetry-design.md` Partes II/III/IV
**Fora de escopo:** Story 1.35 / issue #37 (`docs/prd-v1.md` §6, território @pm)

## Veredito

**GO.** Nada de PII vaza. Os 4 gates passam com execução minha, não com a palavra do @dev.
Os dois achados MEDIUM abaixo são de higiene e de cobertura de gate — nenhum deles é
vazamento, nenhum deles justifica segurar o push.

---

## 1. Gates — re-executados, não aceitos por relato

Não havia PHP nem Composer na máquina; rodei tudo em `php:8.2-cli` sobre o repositório
montado, o que também prova que a suíte não depende do ambiente local.

| Gate | Resultado |
|---|---|
| `phpunit` | **OK — 324 testes, 738 asserções** |
| `bin/check-boundary.sh` | **7/7 aprovados**, incluindo `ok: src/Telemetry/ não revela identidade do site` |
| `phpcs` (WPCS) | **104 arquivos, 0 erros** |
| `bin/i18n-e2e.sh` | **aprovado** — 9 superfícies, 115 strings no catálogo, canário disparou |

Os números do @dev batem exatamente.

---

## 2. Foco 1 — o envelope nunca carrega identidade

Não me contentei com os testes unitários nem com a alegação de busca no JSON do WP real.
Montei uma instalação hostil de propósito e varri o **JSON serializado**:

- `siteurl`, `home`, `blogname`, `admin_email` populados com marcadores;
- `$_SERVER` com `HTTP_HOST`, `SERVER_NAME`, `SERVER_ADDR`, `REMOTE_ADDR`, `HTTP_USER_AGENT`;
- `site_key` e `secret_key` com marcadores;
- **um `form_id` de terceiro derivado do nome do site** (`MARCADOR_BLOGNAME_form`) e outro
  que era literalmente uma URL, ambos com volume acima do corte de 50 — o cenário em que um
  plugin de terceiro registra integração pelo filtro `wp_recaptcha_forms_register_integrations`;
- contadores com números redondos e rastreáveis (5000/4800/3000/200) para detectar
  contagem absoluta vazando.

**Resultado: nenhum marcador no JSON.** Os dois `form_id` hostis foram descartados pela
interseção com `Envelope::FORM_IDS`, e nenhum dos números absolutos apareceu.

A escolha de declarar `FORM_IDS` como constante em vez de ler do `Registry` é o que faz
isso funcionar, e o comentário no código nomeia exatamente essa razão. É a decisão mais
importante do arquivo.

## 3. Foco 2 — User-Agent sem `site_url()`

Interceptei a requisição real por `pre_http_request` e inspecionei os argumentos inteiros:

```
URL: https://telemetry.gustavomathias.dev/v1/ingest
timeout: 5 | redirection: 0 | blocking: true | sslverify: true
header User-Agent: wp-recaptcha-forms/1.1.0
header Idempotency-Key: abc-123
```

O domínio da instalação não aparece em lugar nenhum da requisição — nem cabeçalho, nem
corpo, nem URL. O UA é constante concatenada, não chamada de função, então o gate de CI não
precisa de exceção para ele. **Dupla trava confirmada:** o gate 6 pega `site_url`/`home_url`/
`get_bloginfo`/`network_*`/`get_option('siteurl'|'home'|'blogname'|'admin_email')`/
`$_SERVER['HTTP_HOST'|'SERVER_NAME']` por **menção**, não só por chamada — o que fecha
inclusive o caso do comentário que "só explica" a armadilha.

## 4. Foco 3 — proporções, nunca contagem (T-3)

`verdicts` carrega `total_bucket` (`<100`/`100-1k`/`1k-10k`/`10k-100k`/`>100k`),
`distribution` em proporção de 3 casas, `blocked_share`, e `by_form` com
`share_of_total`/`client_unreachable` — também proporções. `threshold` vira balde, nunca o
float. `forms_enabled_count` é contagem de **formulários habilitados** (vocabulário fechado
nosso, máximo 8), não de submissões: não é volume de negócio.

O corte de amostra de 50 avaliações em `by_form` está implementado e testado.

## 5. Foco 4 — opt-in de verdade

Verificado por execução:

| Verificação | Resultado |
|---|---|
| Default em instalação nova | `enabled: false` |
| Migração de schema 1→2 (instalação que atualiza) | acrescenta o sub-array com `enabled: false` |
| `WRF_DISABLE` | desliga |
| `WRF_TELEMETRY_DISABLE` | desliga, com o resto do plugin intacto |
| Sob constante, mesmo com a opção `true` no banco | **não envia** |
| POST forjado sob constante | `Sanitizer::apply_telemetry_consent()` retorna antes de tocar no `Consent` |

`Options::telemetry_enabled()` é resolução única e exige `instance_id` não-vazio — uma linha
adulterada à mão no banco com `enabled=true` e id vazio não envia nada. Defesa boa.

Sem dark pattern na tela: checkbox desmarcado, sem modal, sem primeira execução, sem
hierarquia visual empurrando o aceite. A seção fica por último na página, e o código
justifica: não afeta o funcionamento do plugin.

## 6. Foco 5 — `instance_id`

`bin2hex(random_bytes(16))`, com fallback para `wp_generate_password(64,true,true)` hasheado
— as duas fontes são CSPRNG e nenhuma toca em atributo da instalação. Gerei 200 ids em
sequência: **200 únicos**.

Opt-out é ruptura, não pausa — confirmado por execução: `instance_id` volta a `''`,
`enabled` a `false`, `seq`/`last_sent`/`last_status` zerados, a opção de contadores é
**apagada** (`delete_option`, não zerada) e o cron é desagendado. Desinstalação varre por
prefixo e cobre os três artefatos (ver M-1 para a ressalva).

## 7. Foco 6 — transporte não quebra o site

`Transport::boot()` registra **apenas** hooks de cron; nenhum hook de submissão aparece no
arquivo, e é isso que torna a regra verificável em vez de prometida. `dispatch()` tem duas
guardas em série: `wp_doing_cron()` (não `wp_doing_ajax()`) e revalidação de
`telemetry_enabled()`, que cobre a corrida "cron disparou depois do opt-out".

`timeout: 5`, `redirection: 0`, `sslverify: true`, `blocking: true` — todos conferidos na
requisição real. Máquina de falha do §6.3 implementada por inteiro: 2xx fecha a janela; 4xx
desiste sem retentar; 429 respeita `Retry-After`; 5xx/`WP_Error` fazem **uma** retentativa
com o mesmo `envelope_id`. Sem fila, sem acúmulo.

`last_status` é consumido só pela tela de config. **Nenhum `add_action('admin_notices')` em
`src/Telemetry/`**, e nenhum caminho cruza o `Gate`. Uma falha de telemetria não desliga
proteção nem aparece para o operador.

## 8. Foco 7 — "Ver exatamente o que será enviado"

Sai de `Envelope::build()`, o mesmo código do envio. `build()` é puro por construção — não
faz rede, não escreve opção, não incrementa `seq` — e é essa pureza que permite ao botão
funcionar com o toggle **desligado**, sem `disabled()`. Protegido por `manage_options` +
nonce. O aviso na tela explica que `envelope_id` e `seq` mudam a cada visualização, o que
evita a pergunta óbvia de quem audita.

Este é o item que transforma a promessa da tela em algo verificável em trinta segundos. Está
certo.

## 9. Foco 8 — spot-check dos 5 bugs corrigidos

| Bug | Correção | Meu veredito |
|---|---|---|
| Cron nunca agendado no primeiro opt-in (1.32) | `cron_schedules` registrado **incondicionalmente** no `Plugin` (antes do primeiro opt-in), `Consent::grant()` → `Schedule::activate()`, mais cura em `Transport::boot()` para agendamento perdido | **Sólida.** Executei: opt-in em instalação limpa agenda o evento, e o offset de jitter cai dentro da semana. A cura cobre desativação/reativação e `wp cron event delete` — e cobre também as instalações que optaram antes da correção, que era o buraco real |
| Regex IPv6 casando hora ISO-8601 (1.29) | limite subiu para 3+ grupos, mais as duas alternativas comprimidas | **Sólida.** `2026-09-10T20:10:02Z`, `2026-09-03T00:00:00Z` e `12:34:56` passam; `2001:0db8:...:7334`, `fe80::1`, `::1`, `2001:db8::ff00:42:8329` são recusados, junto de IPv4, e-mail e URL. Sem falso positivo, sem falso negativo no conjunto real |
| `by_form` alternando `[]`/`{}` (278b656) | cast `(object)` no `Envelope` | Correta, e a consequência foi tratada — ver linha seguinte |
| Ponto cego do `PiiGuard` em nós objeto (278b656) | `walk()` converte objeto em array antes de varrer | **É a correção mais importante das cinco.** Sem ela o cast `(object)` teria criado um buraco silencioso no guard, e um guard com ponto cego produz confiança sem cobertura |
| `Sanitizer` desfazendo o `Consent` (1.32) | releitura de `Options::telemetry()` para dentro de `$clean` após o `Consent` escrever | Correta. O toggle passa pelo `Consent`, nunca por escrita direta — que era o jeito de ficar "ligado sem identificador e sem cron" |

---

## Achados

### M-1 (MEDIUM) — a desinstalação deixa o evento de cron no banco

`uninstall.php` varre `options` por prefixo, mas os eventos agendados vivem na opção `cron`,
que não casa nenhum dos dois prefixos. Depois de desinstalar, `wp_recaptcha_forms_telemetry_send`
(e possivelmente `_retry`) continuam na fila do WP-Cron **para sempre**, disparando semanalmente
sem handler.

Não é vazamento e não quebra nada. É contradição com o que o próprio arquivo afirma no
cabeçalho — *"remove toda a pegada do plugin no banco (S-02)"* — e com a linha do
`CHANGELOG.md` que diz que a desinstalação remove os artefatos de telemetria. Num plugin cujo
argumento de venda é justamente não deixar rastro, a frase precisa ser verdadeira.

Agrava: **não existe `register_deactivation_hook` no projeto**, então desativar o plugin
também deixa o evento fantasma.

**Correção sugerida:** `wp_clear_scheduled_hook()` para os dois hooks em `uninstall.php`, e um
`register_deactivation_hook` que faça o mesmo. Duas linhas, mais um teste em `UninstallTest`.

**RESOLVIDO (2026-09-10).** `uninstall.php` agora limpa `wp_recaptcha_forms_telemetry_send` e
`_retry` com `wp_clear_scheduled_hook()` (nomes literais — autoloader não roda no contexto de
uninstall). O `register_deactivation_hook` já existia (`Plugin::on_deactivate`); passou a chamar
`Telemetry\Schedule::deactivate()`. Testes: `UninstallTest::test_uninstall_clears_telemetry_cron_events`
(asserção de fonte) e `TelemetryTransportTest::test_deactivation_clears_the_telemetry_cron`
(comportamental).

### M-2 (MEDIUM) — o gate 6 não cobre path de servidor nem IP do servidor

O bloco 7 do `check-boundary.sh` fecha domínio e nome de host, que é de longe o vetor mais
provável. Mas a lista de proibições do desenho §1.4 inclui **path de arquivo do servidor**, e
o gate não pega:

`ABSPATH`, `WP_CONTENT_DIR`, `__DIR__`, `wp_get_upload_dir`, `$_SERVER['DOCUMENT_ROOT']`,
`$_SERVER['SERVER_ADDR']`, `$_SERVER['REMOTE_ADDR']`, `php_uname`, `gethostname`.

**Hoje não há vazamento** — confirmei na varredura da §2, e `php_uname` sequer aparece em
`src/Telemetry/`. O problema é de cobertura, e importa pelo motivo que o próprio script
declara na abertura: *"um princípio arquitetural sem verificação automatizada vira comentário
decorativo"*. O gate existe para continuar verdadeiro daqui a dois anos, quando alguém
acrescentar um campo `host.server` de boa-fé. `SERVER_ADDR` e `REMOTE_ADDR` são o caso que
mais me preocupa: são IP, e o `PiiGuard` os pegaria em runtime, mas só depois do envelope
montado — o gate é a trava barata que impede a linha de ser escrita.

**Correção sugerida:** estender os padrões do bloco 7. É edição de uma lista, sem mudança de
lógica.

**RESOLVIDO (2026-09-10).** O gate 6 (bloco `src/Telemetry/`) do `check-boundary.sh` ganhou os
padrões: `$_SERVER['DOCUMENT_ROOT'|'SERVER_ADDR'|'REMOTE_ADDR']`, `ABSPATH`, `WP_CONTENT_DIR`,
`WP_CONTENT_URL`, `WP_PLUGIN_DIR`, `__DIR__`/`__FILE__`, `plugin_dir_path`, `wp_upload_dir`/
`wp_get_upload_dir`, `php_uname`, `gethostname`, `gethostbyname`. O guard
`if ( ! defined( 'ABSPATH' ) )` é filtrado para não virar falso-positivo. `TelemetryBoundaryGateTest`
cobre cada padrão novo + o guard como não-falso-positivo. `src/Telemetry/` real segue passando.

### L-1 (LOW) — o filtro de endpoint aceita qualquer URL

`wp_recaptcha_forms_telemetry_endpoint` não valida esquema. O comentário diz, com razão, que
existe para o ambiente de teste do autor e não como ponto de extensão de terceiro — mas um
plugin hostil pode apontar o envio para `http://` e o envelope sai em claro. O envelope não
tem PII, então o impacto é baixo; ainda assim, `0 !== strpos($url, 'https://')` → volta para a
constante custa uma linha e alinha com a disciplina de `sslverify` que o arquivo já aplica.

### L-2 (LOW) — pré-visualização sob `WP_DEBUG` pode virar fatal na tela

`Envelope::build()` chama `PiiGuard::assert_clean()`, que **lança** sob `WP_DEBUG`. Em cron
isso é o comportamento desejado e está justificado no código. Na tela de config, o mesmo
caminho transforma um bug de payload em página branca do wp-admin em vez do `[]` renderizado.
Só afeta ambiente de desenvolvimento e só se já houver bug de PII, mas vale um `try/catch` no
`render_telemetry_preview()` que mostre a razão da recusa — que é, afinal, informação útil
para quem está depurando.

### L-3 (LOW) — divergência de contagem no desenho

`Envelope::FORM_IDS` tem 8 entradas e bate exatamente com o `Registry` real. Vários comentários
e o `telemetry-design.md` §3.2 falam em "nove `form_id`", e o exemplo do §3 usa
`woo_checkout_blocks`, que não é um id real (blocks reusa `woocommerce_checkout`). O **código
está certo**; é o documento que envelheceu. Ajuste editorial.

---

## Bloqueios de release já rastreados (não bloqueiam o push)

Estão corretamente registrados em `docs/release-checklist.md` §"Bloqueantes desta feature", e
os cito só para que não se percam:

1. `Endpoints::INGEST` e `::PRIVACY` são **placeholder** (`TODO(T-2)`). URL final por confirmar.
2. A política pública da API precisa existir **e conter a promessa de não registrar o IP de
   origem** (§1.5). Sem ela, o texto que a tela mostra ao operador é contradito pela
   infraestrutura, e o plugin terá dito uma inverdade em nome dele. O link na tela hoje aponta
   para uma página que não existe.
3. `docs/prd-v1.md` §6 continua excluindo telemetria do escopo — Story 1.35 / issue #37, @pm.

O item 2 é o único que muda a natureza do que o produto promete, e é decisão do dono, não do
código. O código fez a parte dele.

---

## O que o @devops pode empurrar

Os 10 commits, do `783cf78` ao `278b656`, sem ressalva:

| Commit | Conteúdo |
|---|---|
| `783cf78` | stories 1.26–1.35 |
| `7dc2fde` | opção `telemetry` opt-in e chaves de desligamento |
| `24e6a91` | contadores agregados por classe e por formulário |
| `ea29cdf` | bloco condicional no aviso de privacidade |
| `2cd1f27` | ciclo de vida do `instance_id` e agendamento semanal |
| `41db8c2` | montagem do envelope `gm.telemetry/v1` e do payload |
| `c72ce4e` | gate de CI que proíbe revelar a identidade do site |
| `c732722` | transporte por WP-Cron com desistência limpa |
| `1b1a266` | seção de telemetria na tela de config com pré-visualização |
| `28bd9f8` | READMEs, CHANGELOG, checklist e nota de `readme.txt` |
| `278b656` | `by_form` sempre objeto JSON e `PiiGuard` varrendo objetos |

Classificação de versão: **MINOR**, como o `CHANGELOG.md` já registra — opção nova
retrocompatível, comportamento inalterado para quem não liga.

**Confirmação de privacidade:** verificado por execução, sobre o JSON serializado e sobre a
requisição HTTP montada, que o envio não carrega `url`, `domain`, `host`, `site_name`,
`email`, `user_id`, `ip`, `user_agent`, path de servidor, site key ou secret key (nem hash),
lista de plugins, tema, contagem de posts ou de usuários — nem contagem absoluta de
submissões. Nada disso sai.

M-1 e M-2 podem virar uma story de follow-up; não são condição para o push.

**Atualização 2026-09-10:** M-1 e M-2 corrigidos nesta mesma sessão (ver as notas RESOLVIDO
acima). Suíte: 324 → 342 testes, tudo verde; `check:boundary` e `phpcs` limpos. L-1 e L-2
seguem em aberto (são LOW e não bloqueiam).
