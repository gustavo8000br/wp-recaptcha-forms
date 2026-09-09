# Matriz de compatibilidade

**Story 1.24 · Atualizado em 2026-09-09**

Este documento separa três coisas que costumam ser confundidas num README: o que é
**declarado** como suportado, o que foi **verificado**, e o que é **lacuna conhecida**.
A terceira lista é a que dá valor às duas primeiras.

---

## 1. Pisos declarados

| Componente | Piso | Onde está declarado |
|---|---|---|
| PHP | 7.4 | `Requires PHP` no header, `composer.json`, guard de bootstrap |
| WordPress | 6.0 | `Requires at least` no header, `minimum_wp_version` no `phpcs.xml.dist` |
| WooCommerce (checkout clássico) | 7.0 | `Support::MIN_CLASSIC`, verificado por `version_compare` |
| WooCommerce (checkout Blocks) | 8.3 | `Support::MIN_BLOCKS`; abaixo disso a linha aparece desabilitada na tela |

O guard de PHP no arquivo principal é escrito em sintaxe compatível com PHP 5.2, de
propósito: um `arrow function` ali produziria erro de parse antes de a mensagem poder ser
exibida, e o operador veria uma tela branca em vez da instrução.

---

## 2. O que foi verificado, e como

### 2.1 Automatizado — roda em toda execução de `composer test`

| Eixo | Cobertura | Mecanismo |
|---|---|---|
| Classificação do `siteverify` (v1.1 §4.2 e §4.5) | 12 respostas normativas + v2 | `tests/Fixtures/siteverify/*.json` via `SiteverifyFixtureTest` |
| Falhas de transporte (rede, 503, 429, corpo não-JSON, corpo vazio) | 5 formas | `FakeTransport`, sem rede |
| Máquina de estados das 4 classes, dois eixos de política | matriz completa | `Gate/GateTest` |
| Pisos do WooCommerce | 7.0 e 8.3 | `version_compare` com **versão injetada** — nunca com o Woo instalado |
| `available() === false` em Blocks < 8.3 | sim | idem, unitário |
| Carga condicional (nada que `implements` do Woo é autocarregável) | sim | `ConditionalLoadTest` + `bin/check-boundary.sh` |
| Fronteira de vocabulário do Google e de `$_POST` | sim | `bin/check-boundary.sh` |
| i18n: nenhuma string visível fora do pipeline | 8 superfícies | `bin/i18n-e2e.sh` |
| Consistência de versão/licença/CHANGELOG | sim | `VersionConsistencyTest` |

**Por que os pisos do Woo são testados com versão injetada e não com Woo antigo instalado
(R-12):** o ambiente local roda WooCommerce 11.1, acima de todos os pisos declarados. Um
teste que dependesse de ter o Woo 7.0 instalado não rodaria em lugar nenhum e passaria por
ausência. Injetar a versão testa exatamente a decisão que o código toma.

### 2.2 Manual — executado nesta rodada, num ambiente só

| Item | Ambiente | Resultado |
|---|---|---|
| Catálogos `pt_BR`/`en_US`/`es_ES` carregando | WP 7.1 / PHP 8.3 / Docker local | 12 asserções passando |
| Fallback `es_MX` e `es_AR` → `es_ES` | WP 7.1 / PHP 8.3 | passa (`tests/Integration/i18n-locale-test.php`) |
| `.mo` gerado por `bin/i18n-build.php` aceito pelo WordPress | WP 7.1 | passa — `load_textdomain()` traduziu |
| Suíte a partir de checkout limpo (`composer install && composer test`) | PHP 8.1 e PHP 7.4, containers descartáveis | passa |
| PHPCS/WPCS | PHP 8.1 | 0 erros, 0 avisos |

### 2.3 Versões de PHP em que a suíte foi efetivamente executada

| PHP | Suíte | Lint | Gate de i18n | Gate de fronteira |
|---|---|---|---|---|
| 7.4 | sim | sim | sim | sim |
| 8.1 | sim | sim | sim | sim |
| 8.3 | via container do WordPress (testes de integração) | — | — | — |

---

## 3. Lacunas conhecidas

Cada linha aqui é uma afirmação que **não** temos direito de fazer hoje.

| Lacuna | Por quê | Risco | Encaminhamento |
|---|---|---|---|
| **WordPress 6.0 não foi executado** | O ambiente local só tem o WP mais recente; subir um 6.0 exigiria um segundo compose | O `load_translation_file` não existe no 6.0, então lá vale o caminho do `.mo` — coberto por teste unitário, mas não exercitado num WP 6.0 de verdade | Matriz de CI multi-versão (Story 1.18 AC 6) — **não entregue nesta rodada**, ver §4 |
| **Faixa WP 6.5–6.7 não exercitada (R-11)** | Mesma razão | É justamente a faixa onde `.l10n.php` e `_load_textdomain_just_in_time` mudam de comportamento | Idem |
| **WooCommerce 7.0 e 8.3 reais nunca instalados** | Decisão consciente (R-12): o piso é testado por injeção de versão | Um piso declarado errado só apareceria com o Woo antigo instalado | Aceito; a injeção testa a decisão, não a integração |
| **Adblock / uBlock Origin (gate de release §8.3-1)** | Exige navegador com extensão, fora do alcance de CI | É o passo que prova que `CLIENT_UNREACHABLE` existe de verdade | Continua manual no `release-checklist.md` |
| **CMP real (Complianz) com `consent_mode=required`** | Idem | O contrato é testado por unidade; a integração com um CMP concreto não | Manual no gate de release |
| **Multisite** | Nunca executado | `uninstall.php` varre por site, mas isso não foi exercitado | Registrar como story futura |
| **Relatório de cobertura (AC 6 da Story 1.24)** | `phpunit --coverage` exige Xdebug ou PCOV, ausentes nos containers usados | Nenhum — é visibilidade, não correção | Plugar quando a matriz de CI existir |

---

## 4. O que a Story 1.18 AC 6 pede e o que foi entregue

O AC pede matriz `PHP 7.4/8.1/8.3 × WP 6.0/latest/6.6-6.7`, mais um eixo de integração com
WooCommerce.

**Entregue:** um workflow de CI (`.github/workflows/ci.yml`) que roda a suíte, o lint e os
dois gates em **PHP 7.4, 8.1, 8.3**. O eixo de WordPress **não** foi entregue: rodá-lo
exige instalar um WordPress por célula e um banco por job, e a suíte unitária deste plugin
foi desenhada exatamente para não carregar WordPress — a matriz de WP só faria sentido para
os testes de integração, que hoje são três arquivos executados por WP-CLI.

Isso é uma decisão de escopo, não um esquecimento, e está registrada aqui em vez de ficar
implícita num AC marcado como concluído. O eixo de WordPress é a próxima coisa a fazer
neste arquivo.

---

## 5. Como reproduzir

```bash
# suíte, lint e gates, do zero, sem nada instalado além de PHP e Composer
composer install
composer test
composer lint
composer check:boundary
composer i18n:e2e

# testes que precisam de WordPress de verdade
cd test-env && docker compose up -d
docker compose exec -T wpcli wp eval-file \
  wp-content/plugins/wp-recaptcha-forms/tests/Integration/i18n-locale-test.php \
  --path=/var/www/html --allow-root
docker compose exec -T wpcli wp eval-file \
  wp-content/plugins/wp-recaptcha-forms/tests/Integration/uninstall-test.php \
  --path=/var/www/html --allow-root
```
