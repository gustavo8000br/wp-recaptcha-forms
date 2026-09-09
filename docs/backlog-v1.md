# wp-recaptcha-forms — Backlog v1

**Produto:** plugin WordPress open-source, gratuito, `wp-recaptcha-forms`
**Repositório alvo:** github.com/gustavo8000br/wp-recaptcha-forms
**Autor/dono:** Gustavo
**Status:** escopo inicial definido pelo @po — pendente de refino com @pm/@architect
**Data:** 2026-09-08

---

## 1. Por que este plugin existe

Levantamento do ecossistema atual de plugins de reCAPTCHA para WordPress:

- As opções gratuitas cobrem apenas formulários **nativos** do WordPress: login, registro, comentário e lost-password.
- Cobertura de **WooCommerce** (reviews de produto, checkout, lost-password do Woo) está sempre atrás de licença paga.
- Nenhuma opção gratuita cobre, ao mesmo tempo, comentários de post + reviews Woo + checkout Woo + formulários de newsletter/terceiros com reCAPTCHA v3.
- Nenhum plugin do mercado — pago ou gratuito — permite threshold de score do v3 **por formulário**; o valor é sempre global por instalação.

Consequências diretas para o escopo v1:

1. A lacuna que justifica o produto é **cobertura**, não granularidade de threshold.
2. Adotamos deliberadamente **um único threshold global**, alinhado ao comportamento de mercado. Não é limitação a ser removida depois; é decisão de design.
3. WooCommerce é o diferencial mais visível e precisa estar em v1, mas como camada **opcional detectada em runtime**.

Essa motivação vai literalmente para o README — o texto deve ser honesto sobre o que já existe no mercado, não vender diferenciação inexistente.

---

## 2. Escopo v1 — dentro e fora

### Dentro

| Área | Itens |
|---|---|
| Núcleo | verificação server-side v3 e v2, tela de configurações, chaves site/secret, threshold global |
| Formulários nativos WP | comentários de post, registro, lost-password, login |
| Terceiros | plugin Newsletter (Stefano Lissa) — form de inscrição |
| Terceiros genérico | API pública (hooks/helpers) para qualquer form, sem acoplar a plugin específico |
| WooCommerce (opcional) | reviews de produto, checkout (convidado e logado), lost-password Woo |
| Distribuição | README pt-BR + README-EN.md, licença, versionamento com CI |

### Fora de v1 (declarado, não esquecido)

- Threshold por formulário (decisão de design, ver seção 1).
- Integrações dedicadas a Contact Form 7, WPForms, Gravity Forms, Elementor Forms etc. — v1 entrega o mecanismo genérico; integrações nomeadas ficam para v1.x conforme demanda real.
- Log/estatística de bloqueios, dashboards, relatórios.
- reCAPTCHA Enterprise, hCaptcha, Turnstile.
- Publicação no diretório oficial wordpress.org (ver B-16, é um item de decisão, não de código).
- Multisite/network admin.

---

## 3. Backlog priorizado

Prioridade: **P0** = sem isso não existe v1. **P1** = v1 fica incompleto/sem diferencial. **P2** = qualidade e distribuição, entra antes do release público. **P3** = pós-v1.

### P0 — Fundação

| ID | Item | Descrição | Aceite resumido |
|---|---|---|---|
| B-01 | Esqueleto do plugin | Header do plugin, bootstrap, autoload, ativação/desativação, desinstalação limpa, i18n (`load_plugin_textdomain`), text domain `wp-recaptcha-forms` | Plugin ativa e desativa em WP limpo sem notice/warning; desinstalação remove opções |
| B-02 | Camada de verificação | Cliente do endpoint `siteverify` do Google, tratamento de timeout/erro de rede, normalização de resposta, política de falha (ver D-03) | Retorno tipado com sucesso/score/action/erros; falha de rede não derruba o site |
| B-03 | Suporte a v3 | Enfileiramento do script, geração de token por `action` nomeada por formulário, validação de score contra threshold global | Token válido passa; score abaixo do threshold bloqueia com mensagem clara |
| B-04 | Suporte a v2 checkbox | Renderização do widget, validação de resposta | Alternativa selecionável, funcional nos mesmos pontos de integração |
| B-05 | Tela de configurações | Menu admin, seleção de versão (v3/v2), site key, secret key, threshold (default **0.6**), toggles por formulário, mensagens de erro customizáveis | Settings API, nonce, `manage_options`, sanitização de todos os campos |
| B-06 | Avisos e ajuda na tela de config | Aviso ao escolher v2 recomendando gerar chave v3 nova; links oficiais do Google para criação de chaves | URL oficial **verificada** antes de escrever (ver D-01) |
| B-07 | Carregamento condicional de asset | Script do reCAPTCHA só carrega nas páginas que têm formulário protegido ativo | Home sem formulário não carrega o script |

### P1 — Cobertura de formulários

| ID | Item | Descrição |
|---|---|---|
| B-08 | Formulários nativos WP | Comentários (`comment_form`/`preprocess_comment`), registro, lost-password, login. Cada um com toggle próprio |
| B-09 | Fallback de bloqueio | Comportamento consistente ao reprovar: mensagem, preservação do conteúdo digitado quando o hook permitir, status HTTP adequado |
| B-10 | Integração Newsletter (Lissa) | Detecção do plugin ativo; injeção no form de inscrição; validação antes do subscribe. Sem hard dependency |
| B-11 | API pública para terceiros | Helpers `wp_recaptcha_forms_render_field()` / `wp_recaptcha_forms_verify()` + filtros/actions documentados, para qualquer form arbitrário |
| B-12 | Camada WooCommerce opcional | Feature detection real (`class_exists('WooCommerce')`); reviews de produto, checkout convidado e logado, lost-password Woo. Toggle individual por ponto |
| B-13 | Degradação sem WooCommerce | Sem Woo instalado: zero erro, zero notice incômodo, seção Woo da tela de config oculta ou claramente marcada como indisponível |

### P2 — Qualidade e distribuição

| ID | Item | Descrição |
|---|---|---|
| B-14 | Versionamento + CI | Esquema `vMAJOR.MINOR.PATCH-HHHHHHH-stage` (hash e stage injetados pelo CI, nunca à mão), adaptado ao contexto de plugin — ver seção 4. Gate de label de nível em PR, CHANGELOG obrigatório |
| B-15 | Licença | Arquivo de licença + header do plugin coerente — ver D-02 |
| B-16 | README.md (pt-BR) | Motivação honesta (seção 1), instalação, configuração passo a passo, obtenção de chaves, matriz de formulários suportados, FAQ, screenshots. Link para README-EN no topo |
| B-17 | README-EN.md | Tradução completa e mantida em paridade com o pt-BR |
| B-18 | Testes | Testes unitários da camada de verificação com HTTP mockado; testes de integração dos hooks principais; matriz de versões WP/PHP |
| B-19 | Segurança e hardening | Secret key nunca exposta no front, nonces, capability checks, escaping de saída, sanitização de entrada, revisão contra WordPress Coding Standards (PHPCS + WPCS) |
| B-20 | Compat e requisitos | Definir versão mínima de PHP e WP e declarar em header e README — ver D-04 |

### P3 — Pós-v1

| ID | Item |
|---|---|
| B-21 | Integrações nomeadas (CF7, WPForms, Gravity Forms, Elementor Forms) sobre a API do B-11 |
| B-22 | Log opcional de eventos bloqueados com retenção configurável |
| B-23 | Whitelist de IPs / bypass para usuários logados com capability |
| B-24 | Submissão ao diretório wordpress.org (se decidido em D-05) |

---

## 4. Versionamento — proposta para este produto

Formato herdado: `vMAJOR.MINOR.PATCH-HHHHHHH-stage`, com hash curto e stage injetados pelo CI. Regras de bump adaptadas ao contexto de plugin WordPress solo, já que aqui não existe marco de negócio (loja/gateway):

| Nível | Critério |
|---|---|
| **MAJOR** | Quebra de compatibilidade para quem usa o plugin: remoção/renomeação de hook, filtro ou helper público; mudança no formato das opções salvas sem migração automática; elevação do requisito mínimo de PHP ou WP |
| **MINOR** | Novo ponto de integração de formulário, nova opção de configuração, suporte a novo plugin de terceiros — tudo retrocompatível |
| **PATCH** | Correção de bug, ajuste de texto, correção de segurança sem mudança de contrato |

Regras operacionais mantidas do projeto de referência: toda PR carrega label de nível (`version:major|minor|patch|none`), o bump vale no merge para a branch principal, a string de versão nunca é editada à mão, e `CHANGELOG.md` recebe entrada em `[Unreleased]`.

Ponto aberto: a versão publicada no header do plugin WordPress precisa ser um número simples (`1.2.0`) — o sufixo `-HHHHHHH-stage` não pode ir no header, sob risco de quebrar comparação de versão do WP. Proposta: header carrega só o núcleo `MAJOR.MINOR.PATCH`; a string completa fica em constante interna e no CHANGELOG. Confirmar com @architect.

---

## 5. Ambiguidades e decisões pendentes (antes de ir pro @pm)

| ID | Questão | Impacto | Quem decide |
|---|---|---|---|
| **D-01** | URL oficial atual do console de criação de chaves do Google. O valor citado (`https://www.google.com/recaptcha/admin/create`) precisa ser **verificado ao vivo**; o Google migrou parte do fluxo para o Google Cloud Console e a URL pode redirecionar | Link errado na tela de config e no README é atrito imediato para todo usuário novo | @analyst verifica, @po confirma |
| **D-02** | Licença específica. "Open-source" foi dito, mas não a licença. Plugins WordPress distribuídos publicamente são, na prática, GPLv2-or-later por herança do core | Bloqueia B-15 e o header do plugin; e é irreversível na prática após publicação | Gustavo (dono) |
| **D-03** | Política de fail-open vs fail-close quando o Google não responde (timeout, quota, rede). Fail-open deixa passar spam numa janela de indisponibilidade; fail-close pode travar checkout do WooCommerce e derrubar venda | Decisão de produto com impacto financeiro direto no caso do checkout. Recomendação do @po: **fail-open no checkout, fail-close nos demais**, configurável | Gustavo, com input de @architect |
| **D-04** | Versões mínimas de PHP e WordPress suportadas | Define esforço de compat e matriz de teste (B-18, B-20) | @architect |
| **D-05** | Publicar no diretório oficial wordpress.org ou distribuir só pelo GitHub | Diretório exige `readme.txt` no formato deles, revisão manual e compromisso de manutenção — vira escopo adicional, não só um upload | Gustavo |
| **D-06** | Comportamento com cache de página (WP Rocket, LiteSpeed, Cloudflare). Tokens do v3 têm validade de ~2 min; HTML cacheado com token embutido reprova | Bug clássico e silencioso desse tipo de plugin. Precisa ser resolvido no design, não descoberto em produção | @architect |
| **D-07** | Login nativo do WP: escopo confirmado? O briefing lista comentário, lost-password e registro explicitamente, e login apenas de forma implícita ("todos os formulários onde reCAPTCHA se aplica") | Um formulário a mais ou a menos no escopo P1 | Gustavo |
| **D-08** | Suporte a checkout em blocos do WooCommerce (Blocks/React) além do checkout clássico shortcode. São dois caminhos de integração distintos; o Blocks é o default em instalações Woo recentes | Se ficar de fora, "suporta checkout Woo" é meia-verdade no README | Gustavo + @architect |
| **D-09** | Interação com o plugin Newsletter: só o form de inscrição, ou também o formulário de cancelamento/perfil? | Escopo de B-10 | Gustavo |

**D-01, D-02, D-03 e D-08 são bloqueantes.** D-02 trava o release, D-03 trava o design da camada de verificação (B-02, item P0), D-08 trava o dimensionamento de B-12, e D-01 é barato de resolver mas erra a primeira impressão do produto se for ignorado.

---

## 6. Fronteiras explícitas

- `tania-content-model` é exclusivo do site taniapimentha. Este plugin **não** tem dependência, código compartilhado ou acoplamento com ele. Projetos separados, repositórios separados, ciclos de release separados.
- WooCommerce nunca é dependência dura. Toda referência a classes/funções do Woo passa por feature detection.
- O plugin Newsletter é integração opcional pelo mesmo critério.

---

## 7. Sequência sugerida

1. Resolver D-01, D-02, D-03, D-08.
2. @pm formaliza requisitos e escreve a spec (o produto é novo e tem integrações externas — passa por Spec Pipeline, não vai direto pra story).
3. @architect responde D-04, D-06 e o ponto aberto do header de versão.
4. @sm quebra B-01..B-07 em stories (fundação primeiro, cobertura depois).
5. B-14 a B-20 antes de qualquer release público — README e licença não são polimento opcional num produto open-source.
