# Revisão de release v1 (alpha) — QA

**Data:** 2026-09-09
**Revisor:** @qa (Quinn)
**Escopo:** 9 commits em `main` local (`6c467d5`..`40ec178`), stories 1.18–1.25
**Artefato avaliado:** primeira release pública, marcada como alpha

---

## Veredito

**NEEDS_WORK — com autorização parcial.**

| Ação | Situação |
|---|---|
| `@devops` empurrar os 9 commits para `main` | **LIBERADO** |
| Fechar as issues #18–#25 | **LIBERADO** após o push |
| Criar a tag `v0.1.0*` e publicar a release | **BLOQUEADO** até B-01 ser corrigido |

Separo as três coisas de propósito. `release.yml` dispara **só por tag**
(`on.push.tags: v[0-9]+.[0-9]+.[0-9]+*`), então empurrar commits para `main` não produz
release nenhuma. O código está sólido; o que está quebrado é o **artefato de
distribuição** — e ele só é montado quando a tag existe. Não há razão para segurar o push
por causa disso, e há toda razão para segurar a tag.

---

## 1. Verificação independente dos gates

Não aceitei o relatório do `@dev` por confiança. Reproduzi o método: `git archive HEAD`
para uma árvore limpa (sem `vendor/`, sem estado de container de desenvolvimento) e rodei
os quatro comandos em containers descartáveis `php:7.4-cli`, `php:8.1-cli`, `php:8.3-cli`.

| Gate | PHP 7.4 | PHP 8.1 | PHP 8.3 |
|---|---|---|---|
| `composer test` | exit 0 — 174 testes, 397 asserções | exit 0 — idem | exit 0 — idem |
| `composer lint` | exit 0 — 0 erros / 0 avisos, 85 arquivos | exit 0 | exit 0 |
| `composer check:boundary` | exit 0 — 6/6 gates | exit 0 | exit 0 |
| `composer i18n:e2e` | exit 0 — `.pot` em dia, 8 superfícies, 102 strings | exit 0 | exit 0 |

**Confirmado.** O relatório do `@dev` estava correto nos quatro comandos e nas três
versões. Registro de honestidade: as contagens no Dev Agent Record da Story 1.25 dizem
"141 testes, 282 asserções" e "73 arquivos" — hoje são 174/397 e 85. É defasagem de
redação de uma rodada anterior, não divergência de resultado.

Observo também que o `check-boundary.sh` foi escrito para ser robusto sob `pipefail` (o
comentário OB-06 no topo do arquivo), capturando saída em variável em vez de confiar em
código de saída de pipe. É a diferença entre um gate e um gate que passa por acidente.

---

## 2. Bloqueio real

### B-01 (HIGH) — o zip de distribuição embarca 22 MB de ferramental de desenvolvimento

**Este é o único bloqueio.** Não é preferência de estilo; é o artefato que o usuário
baixa estando errado.

`.distignore` não lista `vendor/`. O job de release roda `composer install` **com** as
dev dependencies (necessário, porque os passos seguintes são `composer test` e
`composer lint`), e depois empacota com:

```bash
rsync -a --exclude-from='.distignore' ./ "$STAGE_DIR/"
```

Reproduzi o comando exato, com o `.distignore` exato do commit:

```
top-level do zip simulado:
assets  CHANGELOG.md  languages  LICENSE  README-EN.md  README.md
src  uninstall.php  vendor  wp-recaptcha-forms.php

vendor/ = 22 MB
conteúdo: phpunit, squizlabs/php_codesniffer, wp-coding-standards/wpcs,
          nikic/php-parser, doctrine, myclabs, phar-io, sebastian, theseer
artefato total = 23 MB
```

O plugin **não referencia `vendor/` em runtime** — ele tem o próprio
`src/Autoloader.php`, e `grep` por `vendor` em `wp-recaptcha-forms.php`, `src/` e
`uninstall.php` não retorna nada. São 22 MB de peso morto que vão para
`wp-content/plugins/wp-recaptcha-forms/vendor/` em toda instalação.

**Calibração honesta da severidade.** Verifiquei especificamente se o vetor clássico
estava presente e **não está**: não há `eval-stdin.php` (removido no PHPUnit 9.x; a
CVE-2017-9841 vivia no PHPUnit 4/5) e não há `.phar` no pacote. Então **não** estou
reportando RCE. O que estou reportando é:

1. 23 MB de download e de disco para entregar um plugin de 6.425 linhas de `src/`.
2. Ferramental de desenvolvimento dentro do webroot, ampliando superfície de ataque e
   fingerprint de scanner para código que nunca deveria estar em produção.
3. Qualquer CVE futura em PHPUnit/PHPCS/php-parser passa a ser uma CVE **deste plugin**
   nas instalações dos usuários, sem que o plugin use uma linha desse código.
4. É rejeição automática na revisão do WP.org, se esse for o destino.

O passo "Checagem mínima do zip" não pega isso — ele só confere que o arquivo principal
existe e que o header bate com a tag.

**Correção:** uma linha — acrescentar `vendor/` ao `.distignore`. Como não há referência
de runtime, é seguro e não exige tocar no `release.yml`. Sugiro, junto, uma asserção no
passo de checagem do zip (`unzip -l "$ZIP" | grep -q 'vendor/' && exit 1`), pelo mesmo
princípio que o próprio projeto já aplica: princípio de arquitetura sem verificação
automatizada vira comentário decorativo.

**Autoridade:** a correção é do `@dev`; `.distignore` e `release.yml` são território do
`@devops`. Eu não alterei nenhum dos dois.

---

## 3. Respostas às quatro decisões solicitadas

### 3.1 Desvio da Story 1.21 — `pt_BR` como idioma-fonte em vez de `en_US`

**Aceito. Não precisa voltar ao `@pm`.**

O AC 4 dizia "`en_US` tratado como idioma-fonte e 100% por definição". Os `msgid` reais
do código estão em português — é assim que o plugin foi escrito. Seguir o AC ao pé da
letra produziria um README anunciando 100% de cobertura em inglês sobre um catálogo
identidade vazio: exatamente o indicador falso que o FR-30 existe para não ter.

O que me faz aceitar não é o raciocínio, é o resultado verificável: `en_US` e `es_ES`
chegaram a 102/102 **por tradução real**, não por definição. O desvio *reduz* a
quantidade de afirmação não-verificada no produto. Um AC que, cumprido literalmente,
viola o requisito que o originou, é um AC errado — e o `@dev` documentou o desvio em vez
de silenciá-lo, que é o comportamento correto.

Ressalva de processo, sem bloquear: isto é uma correção factual sobre o estado do código,
não uma mudança de política de produto. A decisão do PRD sobre *quais* idiomas o produto
mantém (pt-BR, en-US, es) permanece intacta — mudou só qual deles é o catálogo
identidade. Por isso não escalo. O AC 4 da 1.21 deve ser **reescrito** para dizer
`pt_BR`, não marcado como cumprido como está.

### 3.2 Fechamento das issues #18–#25

**Liberado assim que o `@devops` empurrar.** Nada mais pendente antes disso.

O motivo do `@dev` para deixar em `InReview` estava certo — não se fecha issue contra
código que só existe numa máquina. Resolvido o push, resolve-se a objeção.

Ressalva sobre duas issues, que **não** bloqueiam o fechamento mas precisam virar
follow-up rastreado, senão viram dívida invisível:

| Issue | Tarefa em aberto na story | Encaminhamento |
|---|---|---|
| #21 (Story 1.21) | AC 9 — gate assimétrico `.po`/`.mo` no CI | O `ci.yml` **tem** o passo ".po e .mo dos idiomas mantidos batem", então o AC está materialmente atendido; a checkbox é que ficou para trás. Marcar e fechar. |
| #24 (Story 1.24) | AC 6 — relatório de cobertura no CI, **não entregue** | Entrega genuinamente ausente. Fechar #24 exige ou entregar, ou abrir issue de follow-up e registrar como escopo diferido. |

Recomendo: fechar #18, #19, #20, #21, #22, #23, #25 no push; fechar #24 junto **desde
que** uma issue nova cubra a cobertura de código. O que não pode acontecer é #24 fechar
silenciosamente com um AC não entregue dentro.

### 3.3 Gaps conhecidos — e o AC 5 da Story 1.25

Sobre o AC 5, que é a pergunta que importa: **é um AC mal escrito, sem impacto de
segurança. Não esconde lacuna.** Verifiquei diretamente, não por leitura do relatório:

```
grep -rn "wp_ajax\|admin-ajax" src/   -> nenhuma ocorrência
grep -rn "probe(" src/               -> src/Admin/SettingsPage.php:190 (único chamador)
```

Não existe endpoint AJAX de validação de chave. `KeyValidator::probe()` roda dentro do
`sanitize_callback` registrado via `register_setting()`, no mesmo request do save. Esse
caminho passa por `options.php` do core, que já impõe nonce e capability do option group;
a tela é registrada com `add_options_page( ..., 'manage_options', ... )` e `render()`
ainda faz `current_user_can( 'manage_options' )` explícito com `wp_die()`.

Ou seja: não há capability faltando, porque não há superfície. O AC descreve código que
nunca foi escrito. A conclusão do `@dev` está certa e a evidência é reproduzível. **O AC 5
deve ser reescrito**, não marcado como cumprido — um AC que descreve arquitetura
inexistente é uma armadilha para o próximo auditor, que vai procurar o `wp_ajax_` e
concluir que alguém o removeu.

Os demais gaps — **aceitos para alpha**, porque estão declarados em
`docs/compatibility-matrix.md` §3 em vez de escondidos:

| Gap | Por que não bloqueia um alpha |
|---|---|
| WP 6.0 sem execução real | O caminho que o 6.0 exercitaria (`load_textdomain_mofile`) tem cobertura unitária. Risco é de i18n, não de segurança. |
| Faixa WP 6.5–6.7 sem execução (R-11) | Mesma natureza. É exatamente o risco que a etiqueta alpha existe para comunicar. |
| Cobertura de código não gerada | Ausência de métrica, não de teste. 174 testes reais rodando. |
| README-EN sem revisão de nativo | Risco editorial. |

Um alpha cujas lacunas estão escritas num documento versionado é um alpha honesto. O que
tornaria isto NO-GO seria o inverso: matriz declarando cobertura que não existe.

### 3.4 Sonda de secret key e o par cruzado — está documentado?

**Sim, confirmado, e em mais lugares do que o pedido.** Este achado da rodada anterior foi
tratado corretamente:

- **README.md** (linhas 137–151), seção *"Um aviso honesto sobre a validação de chave"*:
  diz explicitamente que a sonda pega o erro grosseiro (`invalid-input-secret`) e que
  **"NÃO detecta um par site key / secret key vindo de projetos diferentes"**, com o
  motivo técnico (o endpoint nem recebe a site key).
- **README-EN.md** (linha 143): mesma delimitação — *"the screen says 'secret validated'
  and never 'keys validated'"*.
- **`src/Admin/KeyValidator.php`** (linhas 22–25): a delimitação está no docblock da
  classe, onde quem for alterar o código vai lê-la.
- **Mensagem de UI** (`SettingsPage.php:216`): *"Esta checagem não prova que a site key
  pertence ao mesmo projeto"*. A limitação chega ao operador, não só ao leitor de README.
- **Mitigação implementada, não só documentada:** `Gate::record_keypair_sample()` — dois
  transients de 1 hora; com mínimo de 10 avaliações e 100% de recusa, grava
  `OPTION_KEYPAIR_SUSPECT`, que `Admin/Notices.php` exibe. Nenhum veredito muda; é
  diagnóstico puro, com o trade-off ("um site sob ataque massivo pode disparar o aviso")
  escrito no comentário.

Isto é o padrão que eu gostaria de ver em todo achado: limitação declarada no código, na
documentação dos dois idiomas, na UI, e com mitigação de runtime. Nada a fazer.

---

## 4. Observações que não bloqueiam

| # | Severidade | Observação |
|---|---|---|
| O-01 | MEDIUM | `release.yml` roda `composer test`, `check:boundary` e `lint`, mas **não** `composer i18n:e2e` — justamente o gate que a Story 1.20 AC 7 declara bloqueante. Uma tag de hotfix empurrada sem PR não passaria por ele. O `ci.yml` cobre push/PR em `main`, então a exposição é estreita, mas a assimetria entre os dois workflows é acidental, não projetada. |
| O-02 | LOW | Não há `readme.txt` (formato WP.org). Irrelevante para release por GitHub; bloqueante se o destino for o diretório de plugins. Decidir antes do beta. |
| O-03 | LOW | Contagens defasadas no Dev Agent Record da Story 1.25 (141/282/73 vs. 174/397/85 reais). |
| O-04 | LOW | `docs/` fora do zip faz as imagens do README não resolverem dentro do pacote. Já é decisão consciente, comentada no `.distignore`. |

---

## 5. O que eu explicitamente não fiz

- Não alterei código-fonte, `.distignore` nem workflows.
- Não commitei nem empurrei nada.
- Não editei as stories fora deste documento.

---

## 6. Caminho para GO

1. `@dev` acrescenta `vendor/` ao `.distignore` (B-01).
2. `@devops` acrescenta a asserção anti-`vendor/` no passo de checagem do zip e, de
   quebra, `composer i18n:e2e` ao `release.yml` (O-01).
3. `@pm`/`@po` reescrevem o AC 4 da Story 1.21 (`pt_BR`) e o AC 5 da Story 1.25 (remover a
   referência ao AJAX inexistente).
4. Issue de follow-up para a cobertura de código (AC 6 da 1.24).
5. Tag `v0.1.0-alpha` e release.

Feito o item 1, o veredito vira GO. Os itens 2–4 são higiene que não precisa segurar a
tag, desde que estejam rastreados.
