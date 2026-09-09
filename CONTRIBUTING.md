# Como contribuir

Obrigado pelo interesse. Este é um projeto de mantenedor solo, então as regras abaixo
existem menos por burocracia e mais para que uma contribuição não vire trabalho de revisão
maior que o próprio patch.

---

## Antes de escrever código

Abra uma issue descrevendo o problema **antes** do PR, salvo em correção trivial. Metade das
decisões deste plugin está documentada em `docs/architecture-v1.md` e
`docs/architecture-v1.1.md`, e várias coisas que parecem faltar são omissões deliberadas com
justificativa escrita — vale conferir antes de implementá-las.

---

## Rodando localmente

```bash
composer install

composer test              # suíte unitária: sem rede, sem WordPress carregado
composer lint              # PHPCS com o ruleset WPCS do projeto
composer lint:fix          # corrige o que é auto-corrigível
composer check:boundary    # gates de fronteira
composer i18n:e2e          # gate de pseudo-locale
```

Os quatro comandos precisam sair limpos. São exatamente os que o CI roda.

Para um WordPress de verdade (necessário só para os testes de integração):

```bash
cd test-env && docker compose up -d      # http://localhost:8081 — admin / admin123
```

---

## Regras que o CI aplica

### 1. Label de versão na PR

Toda PR precisa de **uma** label, e não existe default:

| Label | Quando |
|---|---|
| `version:major` | Quebra de contrato público (hook, filtro, helper), mudança de formato de opção sem migração, elevação de piso PHP/WP, ou mudança nomeada de postura de segurança |
| `version:minor` | Novo ponto de integração ou nova opção retrocompatível |
| `version:patch` | Correção de bug sem mudança de contrato |
| `version:none` | Documentação, testes, CI |

`version:major` exige também uma linha `MAJOR-JUSTIFICATIVA:` no corpo da PR. O nível **não**
é derivado do prefixo do commit: `feat:` e `fix:` não sabem se a mudança quebra contrato
público.

### 2. `CHANGELOG.md`

PR que toca `src/`, `assets/`, `bin/`, `wp-recaptcha-forms.php` ou `uninstall.php` precisa de
entrada em `## [Unreleased]`. PR só de documentação ou teste, não.

### 3. Versão nunca é editada à mão

`Version:` no header, `WP_RECAPTCHA_FORMS_VERSION` e `WP_RECAPTCHA_FORMS_BUILD` são escritos
por `bin/stamp-version.php` a partir da tag do git, no workflow de release. Um teste falha se
os três divergirem.

### 4. Paridade entre os dois READMEs

**Toda mudança em `README.md` precisa da mudança correspondente em `README-EN.md`, na mesma
PR.** Não é tradução literal — o conteúdo tem de ser tecnicamente equivalente —, mas as duas
versões não podem divergir em fatos, seções ou avisos. Um README em inglês desatualizado é
pior que nenhum: ele afirma com confiança coisas que deixaram de ser verdade.

A tabela de progresso de tradução dos dois arquivos é **gerada**, nunca editada à mão. Rode
`composer i18n:progress`.

### 5. Gates de fronteira

Dois princípios de arquitetura são verificados por script, não por revisão:

- O vocabulário de erro do Google (`invalid-input-secret`, `error-codes`,
  `g-recaptcha-response`) e a URL do provedor só existem dentro de `src/Provider/`.
- Nenhum adaptador lê `$_POST` direto: existe um coletor único, e os adaptadores recebem um
  `FormContext` pronto.

Se o seu patch precisa violar um desses, é sinal de que o desenho precisa mudar primeiro —
abra a discussão na issue.

### 6. Nenhuma string visível fora do i18n

Todo texto exibido passa por `__()`/`esc_html__()` com o text domain `wp-recaptcha-forms`, e
sempre **dentro de método**, nunca em escopo de arquivo, `const` ou propriedade de classe
(carregar cedo demais dispara `_load_textdomain_just_in_time` a partir do WP 6.7).

O gate `composer i18n:e2e` ativa um pseudo-locale e afirma que toda string visível aparece
entre marcadores. Se você acrescentou um nome próprio novo, ele vai para
`languages/i18n-allowlist.txt` — **com justificativa explícita na PR**, porque cada linha
acrescentada ali é um pedaço da tela que o gate deixa de vigiar.

---

## Traduções

O fluxo completo está na seção "Traduções" do [README](README.md#traduções). Resumo:

- Idioma **de comunidade**: entra só com o `.po`.
- Idioma **mantido** (`pt_BR`, `en_US`, `es_ES`): entra com `.po` **e** `.mo`, e o CI
  reconstrói o `.mo` e compara byte a byte.

---

## Estilo de código

WordPress Coding Standards, com as exceções listadas e justificadas em `phpcs.xml.dist`.
Rode `composer lint:fix` antes do PR.

Comentário explica **por quê**, não o quê. O código já diz o que faz; o que se perde com o
tempo é a razão de uma decisão ter sido tomada, e principalmente a razão de uma alternativa
óbvia ter sido descartada.

---

## Testes

- Bug corrigido entra com teste que falha antes do patch.
- Nada de rede em teste: o `FakeTransport` cobre o provider.
- Piso de versão (PHP, WP, WooCommerce) é testado por **injeção de versão**, nunca por ter a
  versão antiga instalada — o ambiente local roda versões acima de todos os pisos, e um teste
  que dependa do ambiente passa por ausência.

---

## Segurança

Não abra issue pública para vulnerabilidade. Use o
[reporte privado do GitHub](https://github.com/gustavo8000br/wp-recaptcha-forms/security/advisories/new).

---

## Licença

Ao contribuir, você concorda em licenciar a sua contribuição sob a
[GPL-2.0-or-later](LICENSE), a mesma do projeto.
