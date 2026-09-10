# Changelog

Todas as mudanças relevantes deste plugin são registradas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o
versionamento segue o esquema `vMAJOR.MINOR.PATCH-HHHHHHH-stage` descrito em
`docs/architecture-v1.md` §11.

Regra de bump (resumida — a íntegra está na Story 1.18):

- **MAJOR** — quebra de contrato público (hook, filtro, helper), mudança de formato de
  opção sem migração, elevação de piso PHP/WP, ou mudança nomeada de postura de
  segurança (ex.: default de `CLIENT_UNREACHABLE` deixar de ser `allow`).
- **MINOR** — novo ponto de integração ou nova opção retrocompatível.
- **PATCH** — correção de bug sem mudança de contrato.

## [Unreleased]

### Adicionado

- **Telemetria** opt-in, **desligada de fábrica**. Envio semanal e agregado de
  estatísticas de uso para o autor do plugin: versões de PHP/WordPress/plugin, quais
  formulários estão protegidos, a configuração, e a **proporção** entre envios aprovados e
  bloqueados — proporções e faixas de volume, nunca contagens absolutas. Não envia o
  endereço do site, e-mails, IPs, conteúdo de formulários nem as chaves do reCAPTCHA.
  - Nova seção **Configurações → reCAPTCHA → Telemetria**, com o botão **"Ver exatamente
    o que será enviado"**, que mostra o JSON real gerado pelo mesmo código do envio e
    funciona com o toggle desligado.
  - Nova constante `WRF_TELEMETRY_DISABLE` para desligar só a telemetria por
    `wp-config.php`; `WRF_DISABLE` continua desligando tudo, telemetria inclusa.
  - Nenhuma atualização do plugin liga isso. Desligar apaga o identificador aleatório da
    instalação, os contadores e o agendamento — é ruptura de vínculo, não pausa.
  - Continua sem tabela nova no banco: os contadores agregados vivem numa `option`
    não-autocarregada, e a desinstalação os remove.
  - Classificação: **MINOR** — opção nova retrocompatível, comportamento inalterado para
    quem não liga (Stories 1.26–1.34). Mudar o default de `telemetry.enabled` para `true`
    seria **MAJOR**, pelo mesmo raciocínio que torna MAJOR mudar o default de
    `CLIENT_UNREACHABLE`; **este projeto não pretende fazê-lo.**
- Medição de cobertura de código: script `composer test:coverage` (Clover + HTML + resumo
  em texto) e job dedicado no CI em PHP 8.3 com PCOV, publicando o relatório como
  artefato. Publica sem gate — o limiar bloqueante segue como decisão em aberto do dono
  do produto, registrada em `docs/architecture-coverage.md` §6 (issue #27).
- Licença GPLv2-or-later em `LICENSE`, coerente com o header do plugin e com o
  `composer.json` (Story 1.19).
- Workflow de CI (`.github/workflows/ci.yml`) rodando testes, gate de fronteira, gate de
  i18n e PHPCS em todo push e PR para `main` (Story 1.18).
- Gate de label de versão e validação de `CHANGELOG.md` em pull request (Story 1.18).
- Catálogo `.pot` e catálogos traduzidos `pt_BR`, `en_US` e `es_ES`, com fallback
  documentado de `es_*` para `es_ES` (Story 1.21).
- `bin/i18n-pot.php`, `bin/i18n-pseudo.php`, `bin/i18n-progress.php` e `bin/i18n-e2e.sh`:
  geração de catálogo, pseudo-locale `en_CA` e indicador de progresso sem dependência de
  serviço externo (Stories 1.20 e 1.21).
- `phpcs.xml.dist` com WPCS; `composer lint` e `composer lint:fix` funcionais (Story 1.25).
- `docs/compatibility-matrix.md` com a matriz testada e as lacunas conhecidas (Story 1.24).
- `README.md` (pt-BR) e `README-EN.md` completos, em paridade, com screenshots reais da
  tela de configurações (Stories 1.22 e 1.23).
- `CONTRIBUTING.md`, incluindo a regra de paridade entre os dois READMEs (Story 1.23).
- `src/I18n.php`: fallback de variantes regionais (`es_MX` → `es_ES` e mais dezenove),
  cobrindo tanto `.mo` quanto `.l10n.php` do WP 6.5+ (Story 1.21).
- `tests/Fixtures/siteverify/`: as respostas normativas do Google como dado, não como
  código (Story 1.24).

### Corrigido

- `uninstall.php` declarava dez variáveis no escopo global sem prefixo, passíveis de
  colisão com outro plugin no mesmo request de desinstalação. Agora é uma função prefixada,
  sem variável global nenhuma (Story 1.25).
- A tela de configurações concatenava o atributo `disabled` cru no HTML; passa a usar
  `disabled()` do core (Story 1.25).
- `render_forms_section()` recebia as opções por parâmetro e não as usava — uma segunda
  fonte de verdade esperando divergir (Story 1.25).

### Notas

- A auditoria de i18n da Story 1.20 **não encontrou strings hardcoded**: as 102 strings
  visíveis já estavam corretamente marcadas com o text domain `wp-recaptcha-forms`. O gate
  de pseudo-locale entra como prevenção, não como correção.

[Unreleased]: https://github.com/gustavo8000br/wp-recaptcha-forms/commits/main
