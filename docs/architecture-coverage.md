# wp-recaptcha-forms — Medição de cobertura de código

**Autor:** @architect (Aria)
**Data:** 2026-09-09
**Fecha:** issue #27 (escopo diferido do AC 6 da Story 1.24, #24)
**Insumos:** `docs/qa-release-review-v1.md` §3.2/§3.3, `docs/architecture-v1.1.md`
**Status:** **implementado e verificado**, exceto o item da §6, que é decisão do dono do produto.

---

## 0. O que este documento decide, e o que deliberadamente não decide

Decide: qual driver, onde ele roda, o que é gerado, onde é publicado, e o que acontece
quando a medição falha silenciosamente.

Não decide: **se existe limiar mínimo bloqueante e qual é** (§6). A issue #27 já
classificou isso como decisão de produto, e concordo pelo motivo escrito lá: um limiar
escolhido por quem escreve o YAML é ou teatro (baixo demais para reprovar qualquer coisa)
ou incentivo a teste-para-a-métrica (alto demais para ser atingido honestamente). Fica
publicado sem gate até haver decisão.

Também não está no escopo escrever teste novo. Isto mede o que existe.

---

## 1. Estado de partida

174 testes, 397 asserções, exit 0 em PHP 7.4, 8.1 e 8.3 — verificado pelo @qa em árvore
limpa. `phpunit.xml.dist` já tinha um bloco `<coverage><include>src</include></coverage>`
desde sempre; o que faltava era **driver e relatório**. O bloco existia sem nada que o
consumisse: cobertura configurada e nunca medida é a pior das duas metades.

Nenhum `--coverage-*` no `composer test`, `coverage: none` nos três jobs da matriz de CI.

---

## 2. Decisão de driver — PCOV

**Escolhido: PCOV.** Xdebug fica como driver aceito, não recomendado.

### 2.1 O argumento da issue não sobreviveu à medição

A issue #27 antecipou que "PCOV tende a ser a escolha certa" por ser mais rápido. Medi, e
o argumento é fraco **neste projeto**. Suíte inteira, container `php:8.3-cli` descartável:

| Cenário | Tempo real |
|---|---|
| `phpunit` sem driver (baseline) | **0,29 s** |
| `phpunit --coverage-*` com **PCOV** | **0,59 s** |
| `phpunit --coverage-*` com **Xdebug** (`XDEBUG_MODE=coverage`) | **0,75 s** |

PCOV é ~21% mais rápido que Xdebug aqui. Em termos absolutos: **160 milissegundos**. Num
job de CI que gasta mais tempo baixando o Composer do que rodando teste, isso não decide
nada, e vender velocidade como razão seria exatamente o tipo de afirmação não-verificada
que a §3.1 da revisão do @qa elogia o projeto por evitar.

Registro o número em vez de repetir a premissa porque ela vai reaparecer: se a suíte
crescer 50×, os 160 ms viram 8 s e o argumento passa a valer sozinho. Hoje não vale.

### 2.2 As razões que de fato decidem

1. **PCOV só sabe fazer uma coisa.** Xdebug é debugger, profiler, tracer e *step debugger*
   que também mede cobertura, selecionado por `XDEBUG_MODE`. Um runner com Xdebug
   instalado e `XDEBUG_MODE` mal configurado degrada a suíte inteira sem avisar, e o modo
   `develop` mudando formato de mensagem de erro já quebrou asserção de teste em projeto
   alheio mais de uma vez. PCOV não tem outro modo para ligar por engano.
2. **Cobertura de branch não tem consumidor neste projeto.** Seria o único argumento real
   a favor do Xdebug. Mas a suíte não declara `@covers` sistematicamente, ninguém lê
   branch coverage hoje, e o PHPUnit só reporta branch/path quando explicitamente
   configurado. Comprar o driver mais pesado por um dado que ninguém consome é dívida.
3. **Um comando no CI.** `shivammathur/setup-php` aceita `coverage: pcov` — sem `pecl`,
   sem `docker-php-ext-enable`, sem `ini` extra.

### 2.3 O achado que obriga a fixar UM driver

Os dois drivers **discordam sobre o que é uma linha executável**, medindo a mesma árvore:

| Driver | Linhas cobertas / total | Percentual |
|---|---|---|
| PCOV | 931 / **1408** | **66,12%** |
| Xdebug | 931 / **1395** | **66,74%** |

Mesmo numerador, denominador diferente, 0,62 ponto de diferença. É pequeno e é
suficiente: **um limiar calibrado sob um driver não é portátil para o outro**. Se um dia
existir gate (§6), ele tem de nomear o driver junto do número, ou o mesmo commit passa
numa máquina e reprova na outra por razão que nenhum log explica.

Por isso o job de CI fixa o driver, e por isso este documento registra o par
(driver, número) sempre junto.

### 2.4 Xdebug continua funcionando

O script `test:coverage` faz `@putenv XDEBUG_MODE=coverage` antes de chamar o PHPUnit.
Um mantenedor que já tenha Xdebug na máquina roda o mesmo comando sem configurar nada, e
sem `XDEBUG_MODE` o PHPUnit apenas avisaria que não há driver e sairia com 0 — o modo de
falha da §4.2. Com PCOV a variável é inerte.

---

## 3. O que é gerado

```bash
composer test:coverage
```

```
build/coverage/clover.xml     legível por máquina  (Codecov, Coveralls, SonarQube, IDE)
build/coverage/html/          legível por humano   (navegável, linha a linha)
build/coverage/cache/         cache de análise estática do PHPUnit
<stdout>                      resumo por classe, no log do job
```

**Clover e não Cobertura:** os dois são aceitos por praticamente todo consumidor, e a
issue deixou a escolha aberta. Clover é o formato nativo do PHPUnit desde sempre, é o que
o ecossistema PHP assume por default e é o que qualquer serviço de cobertura detecta sem
configuração. Cobertura tem vantagem no ecossistema Jenkins/Java, que não é este. Trocar
depois é mudar uma flag.

**HTML e não só texto:** o texto responde "quanto"; o HTML responde "onde", que é a única
pergunta acionável. O resumo em texto no log cobre o caso de quem só quer o número sem
baixar artefato.

`build/` já estava no `.gitignore` **e** no `.distignore` antes desta mudança — nenhum
relatório entra no repositório nem no zip de distribuição.

---

## 4. Integração no CI

Job **novo e separado**, `coverage`, em `.github/workflows/ci.yml`.

### 4.1 Por que job separado, e não um passo dentro da matriz

A issue pede medir em um job da matriz, não nos três. Concordo com o objetivo (custo) e
divirjo do mecanismo. Um `if: matrix.php == '8.3'` dentro da matriz produz **o mesmo job
com duas naturezas** — dois jobs que apenas rodam teste, um que também mede — e a matriz
existe hoje com `coverage: none` explícito nos três. Job separado mantém a matriz
homogênea, deixa a cobertura visível como linha própria na lista de checks, e permite que
ela falhe sem confundir o leitor sobre qual PHP quebrou.

Custo: um runner a mais, com a mesma suíte de 0,6 s. O eixo de custo real aqui é
`composer install`, e ele já é pago três vezes na matriz.

**PHP 8.3, não 7.4.** O número tem de descrever o alvo do projeto, não o piso de
compatibilidade. Rodar no piso também tende a expor divergência de driver por versão de
motor, que é ruído contra a pergunta que a métrica responde.

### 4.2 Os dois guards, e por que eles não são paranoia

O modo de falha caro de uma medição não é falhar — é **passar verde publicando nada**.

Sem driver de cobertura, o PHPUnit **avisa e sai com código 0**. O job ficaria verde, o
artefato sairia vazio ou ausente, e o número que todo mundo passaria a citar viria de uma
execução que nunca mediu. Dois passos fecham isso:

```yaml
- name: Driver de cobertura presente
  run: php -r 'exit(extension_loaded("pcov") || extension_loaded("xdebug") ? 0 : 1);'

- name: Relatórios foram gerados
  run: |
    test -s build/coverage/clover.xml
    test -s build/coverage/html/index.html
```

Verificados nos dois sentidos, que é o critério do projeto (o gate tem de reprovar a
entrada ruim, não só aprovar a boa):

| Entrada | Resultado |
|---|---|
| container com PCOV | exit 0 |
| container `php:8.3-cli` cru, sem driver | **exit 1** |
| após `composer test:coverage` | `clover.xml` 117 KB, `html/index.html` presente |

É o mesmo princípio que o @qa aplicou em B-01 ao pedir a asserção `unzip -l | grep -q
'vendor/'`: princípio de arquitetura sem verificação automatizada vira comentário
decorativo.

### 4.3 Resumo na execução

Um passo lê o `clover.xml` e escreve uma tabela em `$GITHUB_STEP_SUMMARY`, para o número
custar zero clique. Verificado contra o Clover real:

```
| Métrica | Cobertura |
|---|---|
| Linhas | 66.12% (931/1408) |
| Métodos | 57.89% (143/247) |
```

Bate exatamente com o `--coverage-text` da mesma execução.

### 4.4 Artefato

`actions/upload-artifact@v4`, nome `cobertura-php83`, `clover.xml` + `html/`, retenção de
**14 dias**, `if-no-files-found: error`.

14 dias e não 90: o relatório é diagnóstico de um commit, não registro histórico. Quem
precisa de série temporal precisa de um serviço de cobertura, não de um zip antigo — e
isso não está em escopo.

---

## 5. A exclusão do `conditional/`, e por que ela não é maquiagem

`processUncoveredFiles="true"` foi ligado no `phpunit.xml.dist`. Sem isso, um arquivo de
`src/` que nenhum teste sequer carrega **desaparece da conta** em vez de contar como 0% —
a métrica premiaria justamente o arquivo sobre o qual ela existe para avisar.

Ligar isso quebrou de imediato, e a quebra é informativa:

```
Generating code coverage report in Clover XML format ...
Interface "Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface" not found
```

`processUncoveredFiles` **carrega** todo arquivo incluído, e
`src/Integrations/WooCommerce/conditional/BlocksCheckoutIntegration.php` é, por decisão
de arquitetura documentada no topo dele próprio, um arquivo que **não pode ser
autocarregado**: ele `implements` uma interface do WooCommerce Blocks, e o PHP resolve
`implements` no momento em que a classe é carregada — antes de qualquer guard no corpo
dela poder rodar. É a armadilha nomeada na §7.1 da arquitetura v1.

A saída é excluir **aquele diretório**, com o motivo escrito no XML:

```xml
<coverage processUncoveredFiles="true" cacheDirectory="build/coverage/cache">
    <include><directory suffix=".php">src</directory></include>
    <exclude><directory suffix=".php">src/Integrations/WooCommerce/conditional</directory></exclude>
</coverage>
```

Três coisas que essa exclusão **não** é:

- **Não é seletiva por conveniência.** É um diretório inteiro, nomeado, cuja razão de
  existir é justamente "código que só carrega com WooCommerce presente".
- **Não deixa o código sem verificação.** Ele é coberto pelos testes de integração via
  WP-CLI, que rodam com o Woo carregado. O que a suíte unitária não pode medir, ela não
  finge medir.
- **Não infla o número.** O arquivo excluído sai do numerador e do denominador. Se
  estivesse incluído e carregável, entraria como 0% e o percentual **cairia**.

Se algum dia alguém quiser acrescentar linha ao `<exclude>`, o teste é este: a exclusão
é por impossibilidade técnica demonstrável, ou porque o número ficaria feio? Só a
primeira passa.

---

## 6. **DECISÃO EM ABERTO — limiar mínimo bloqueante**

**Isto volta para o dono do produto. Não implementei gate, e não vou escolher número.**

O que existe hoje, medido e verificável, sem gate:

| Métrica | Valor real (PCOV, PHP 8.3, HEAD) |
|---|---|
| **Linhas** | **66,12%** (931/1408) |
| **Métodos** | 57,89% (143/247) |
| **Classes** | 17,39% (8/46) |

Antes de decidir, três observações que mudam a leitura desses números:

**(a) "Classes: 17,39%" não quer dizer que 83% do código não é testado.** A métrica de
classe do PHPUnit conta apenas classes com **100%** dos métodos cobertos. Uma classe com
9 de 10 métodos cobertos conta como não coberta. É a métrica menos informativa das três e
a pior candidata a virar gate.

**(b) O número baixo está concentrado onde era esperado.** As classes de decisão — as que
a arquitetura v1.1 trata como críticas — estão altas: `AbstractSiteverifyProvider` 95,7%,
`VerificationRequest` 100%, `PrivacyNotice` 100%, `Endpoints` 100%, `FailureClass` 100%,
`SiteverifyV2` 90%, `SiteverifyV3` 88,2%. O que puxa para baixo é a camada que fala com o
WordPress: `WpHttpTransport` 0%, `Plugin` 11,6%, `Notices` 0%, adaptadores de integração
entre 40% e 65%. É exatamente o desenho da suíte, que por decisão explícita **não carrega
o WordPress**.

Isso importa para o gate: um limiar global de linha pressiona a escrever teste unitário
para código cuja única razão de existir é chamar função do WordPress — que é o teste de
menor valor e maior custo de manutenção do projeto. Se houver gate, a discussão honesta é
**limiar por diretório**, não global.

**(c) Não anunciar no README antes de decidir.** A issue #27 já pede isso e a disciplina
já existe no projeto (§3.1 da revisão do @qa). O número acima é reprodutível hoje, mas
publicá-lo no README institui uma promessa cuja manutenção ninguém combinou.

### As opções, com o que cada uma custa

| Opção | O que acontece | Custo real |
|---|---|---|
| **A — sem gate (estado atual)** | mede, publica, não reprova | o número pode cair sem ninguém notar; depende de disciplina humana |
| **B — gate global no valor atual** (ex.: linhas ≥ 66%) | catraca: impede regressão, não exige melhora | pressiona a testar a camada WordPress, que é o teste de pior custo-benefício aqui; e o número não é portátil entre drivers (§2.3) |
| **C — gate só no núcleo** (ex.: `src/Provider/` e `src/Gate/` ≥ 90%) | protege onde a decisão de segurança mora | exige ferramenta extra para limiar por caminho; mais trabalho de montagem |
| **D — gate de não-regressão** (comparar com o `main`) | reprova a queda, seja qual for o patamar | exige guardar o número entre execuções (serviço externo ou artefato/branch de estado) |

**Minha recomendação, sem executá-la:** **A agora, C depois**, quando houver uma ou duas
releases de dado para calibrar. B é a tentação óbvia e é a que produz o pior incentivo
neste projeto especificamente, pelo motivo de (b). D é a mais correta conceitualmente e a
única que precisa de infraestrutura que hoje não existe.

Decidido isto, é uma alteração pequena no job — e é território do **@devops**, não meu.

---

## 7. O que foi verificado, e como

Método: `git archive HEAD` para árvore limpa, arquivos alterados copiados por cima,
containers `php:*-cli` descartáveis — o mesmo método que o @qa usou na revisão de release,
de propósito.

| Verificação | Resultado |
|---|---|
| `composer test:coverage` com PCOV, PHP 8.3 | OK, 174 testes / 397 asserções, relatórios gerados |
| `composer test` (sem cobertura), PHP 7.4 | **OK, 174/397** — sem regressão pela mudança no `phpunit.xml.dist` |
| `composer test` (sem cobertura), PHP 8.1 | **OK, 174/397** |
| guard de driver, com PCOV | exit 0 |
| guard de driver, container cru | exit 1 |
| guard de artefato, após a medição | `clover.xml` 117 KB, `html/index.html` presente |
| snippet do `$GITHUB_STEP_SUMMARY` contra o Clover real | tabela idêntica ao `--coverage-text` |
| `ci.yml` parseado como YAML | válido — 5 jobs, 9 passos no `coverage` (na primeira tentativa **não** era: um `: ` dentro de escalar simples quebrava o arquivo inteiro; corrigido para bloco `\|`) |
| os 4 passos de `run` do job extraídos do YAML e executados **literalmente** em container | exit 0 nos quatro; `summary.md` gerado com a tabela correta |
| PCOV × Xdebug | 0,59 s × 0,75 s; 1408 × 1395 linhas executáveis |

O terceiro e o quarto item da tabela são o que importa mais: `phpunit.xml.dist` é lido
pelos **três** jobs da matriz, não só pelo de cobertura. Alterar aquele arquivo sem rodar
a suíte no piso de PHP seria mudar o gate de todo mundo às cegas.

O que **não** foi verificado, e não tem como ser localmente: o job rodando no GitHub
Actions. `setup-php` com `coverage: pcov`, `upload-artifact@v4` e `$GITHUB_STEP_SUMMARY`
são contratos do runner. A primeira execução em PR é o teste real; se o `setup-php`
falhar em prover o PCOV, o guard da §4.2 reprova em vez de publicar vazio, que é
precisamente para o que ele existe.

---

## 8. Arquivos alterados

```
composer.json          + script test:coverage e sua descrição
phpunit.xml.dist       processUncoveredFiles, cacheDirectory, exclude do conditional/
.github/workflows/ci.yml   + job `coverage` (PHP 8.3, PCOV, 2 guards, resumo, artefato)
CHANGELOG.md           entrada em [Unreleased]
docs/architecture-coverage.md   este documento
```

Sem alteração em `src/`, `tests/`, `bin/` ou `.distignore`. Nenhum teste novo, conforme o
escopo da issue.

---

## 9. Recado para cada agente

- **@devops** — o job novo é território seu a partir daqui; e a §6 vira alteração sua no
  YAML **depois** que o dono decidir. Não há gate hoje, e a ausência é deliberada.
- **@dev** — `composer test` continua sem cobertura e continua a 0,3 s; a medição é um
  comando à parte. Se você tem Xdebug na máquina, `composer test:coverage` funciona sem
  configurar nada, mas o número vai divergir ~0,6 ponto do CI (§2.3).
- **@qa** — o AC 6 da Story 1.24 tem entrega agora, exceto o limiar, que a §6 devolve
  como decisão de produto em vez de inventar. O número real está na §6 e é reprodutível.
- **@pm / dono do produto** — uma decisão sua na §6, e uma orientação: não anunciar o
  percentual no README antes dela.
