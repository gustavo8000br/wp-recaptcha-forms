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
- `README.md` (pt-BR) e `README-EN.md` completos (Stories 1.22 e 1.23).

### Corrigido

- Strings visíveis que haviam escapado do pipeline de i18n passam a usar o text domain
  `wp-recaptcha-forms` (Story 1.20).

[Unreleased]: https://github.com/gustavo8000br/wp-recaptcha-forms/commits/main
