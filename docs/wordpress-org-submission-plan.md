# Plano de submissão ao repositório oficial do WordPress.org

Levantado em 2026-09-09, lendo `br.wordpress.org/plugins/developers/add/` + as duas páginas
linkadas nela (diretrizes detalhadas e FAQ do desenvolvedor). Nada disso foi executado
ainda — é o checklist pra próxima sessão.

## 0. Conta (você, não eu)

- [ ] Tentar recuperar a conta antiga do WordPress.org, ou criar uma nova em
      `https://login.wordpress.org/register`.
- [ ] Decidir se a conta é pessoal ou de uma organização — o FAQ recomenda usar a conta
      "oficial" de quem vai manter o plugin a longo prazo.

## 1. Achado que muda o plano técnico: SVN, não GitHub

O WordPress.org não puxa do GitHub. Depois de aprovado, você recebe um repositório SVN
próprio; o código vivo é o que está em `trunk/`, e cada release vira uma cópia em
`tags/X.Y.Z/`. Nosso `release.yml` atual só gera artefato no GitHub — **não publica
sozinho no WP.org**.

- [ ] Decidir: publicar manualmente via `svn commit` a cada release, ou automatizar com
      uma GitHub Action de ponte (ex.: `10up/action-wordpress-plugin-deploy`, que sincroniza
      um repo Git com o SVN do WP.org automaticamente a cada tag).
- [ ] Se automatizar: vai precisar guardar usuário/senha do SVN como secret do GitHub —
      nunca colar isso no chat, gerar e confirmar via CLI/API como sempre.

## 2. `readme.txt` — arquivo novo, formato específico do WP.org

Diferente do `README.md` que já temos (esse é pro GitHub). O WP.org exige `readme.txt`
próprio, com seções e cabeçalho padronizados (`Stable tag`, `Tested up to`,
`Requires at least`, `Requires PHP`, `Tags` — **máximo 5**, `License`, etc.). Documentação
completa: `developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/`
(ainda não lida em detalhe — ler antes de escrever o arquivo).

- [ ] Escrever `readme.txt` seguindo o formato oficial.
- [ ] Manter só a versão atual + 1 anterior no changelog do `readme.txt`; histórico
      completo continua no `CHANGELOG.md` do repo.

## 3. Assets visuais — ainda não existem

Iconve/banner do plugin na listagem do WP.org (pasta `assets/` do SVN, fora do `trunk/`):

- [ ] Ícone 128×128 e 256×256 (`icon-128x128.png`, `icon-256x256.png`).
- [ ] Banner 772×250 e 1544×500 (`banner-772x250.png`, `banner-1544x500.png`).
- [ ] Nenhum logo oficial do Google/WordPress sem autorização — branding próprio.

## 4. Checklist de conformidade contra as 18 diretrizes (já lidas)

A maioria já bate com o que o `@dev`/`@qa` implementaram, mas revisar explicitamente:

- [ ] **GPLv2-or-later** — já temos (`LICENSE`, header do plugin). OK.
- [ ] **Sem trialware/bloqueio por pagamento** — não se aplica, plugin é 100% grátis. OK.
- [ ] **Script externo do Google (`api.js`)** — a diretriz proíbe CDN pra JS/CSS em geral,
      mas permite integração com serviços de terceiros (categoria "SaaS"). Preparar uma nota
      clara na submissão explicando que carregar `api.js` direto do Google é inerente ao
      funcionamento do reCAPTCHA (não dá pra hospedar o script proprietário do Google
      localmente) — mesma lógica de plugins de pagamento (Stripe.js, PayPal SDK) já aceitos
      no repositório.
- [ ] **Rastreamento sem consentimento ("phoning home")** — o plugin **tem** telemetria
      desde as Stories 1.26-1.34, e isso muda o rigor desta linha. Ela é opt-in e
      desligada de fábrica, o que satisfaz a guideline #7 — **desde que a divulgação
      esteja no `readme.txt`**, e não apenas no `README.md`: o revisor do WP.org lê o
      primeiro. A seção de descrição do `readme.txt` precisa dizer, de forma explícita:
      - que existe um envio de estatísticas de uso para o autor do plugin;
      - que ele é **opt-in e vem desligado**, e que nenhuma atualização o liga;
      - o que é enviado e o que **não** é (sem endereço do site, sem IP, sem dados de
        visitante, sem as chaves do reCAPTCHA);
      - o link da política de privacidade da API de telemetria;
      - como desligar por `wp-config.php` (`WRF_TELEMETRY_DISABLE`).

      Dependência: a URL final da API e a política pública são decisão do dono (T-2,
      rastreada na issue #37) e precisam estar resolvidas antes da submissão.
- [ ] **Nonces, sanitização, escaping** — já implementado e testado (`SecurityAuditTest.php`,
      Settings API). Revisar mais uma vez como checklist final, não como trabalho novo.
- [ ] **Slug do plugin** — `wp-recaptcha-forms` não começa com termo de marca (regra é sobre
      o INÍCIO do slug), mas o nome de exibição "WP reCAPTCHA Forms" contém "reCAPTCHA"
      (marca do Google). Uso nominativo/descritivo costuma ser aceito (há vários plugins
      assim no repositório oficial), mas vale conferir se não precisa de disclaimer.
- [ ] **Tags do `readme.txt`**: máximo 5 — escolher as mais relevantes (ex.: recaptcha,
      security, spam, woocommerce, comments).

## 5. Processo de submissão

- [ ] Ler as diretrizes de segurança linkadas na página (escaping, sanitização, nonces —
      `developer.wordpress.org/apis/security/*`) como revisão final, não implementação nova.
- [ ] Gerar o zip de submissão (já temos o pipeline pronto — o artefato do `release.yml`
      já é enxuto, ~160KB, bem abaixo do limite de 10MB).
- [ ] Login em `wordpress.org/plugins/developers/add/`, enviar o zip.
- [ ] Aguardar e-mail de confirmação de recebimento.
- [ ] Prazo normal: até 14 dias. Se vier e-mail "Review in Progress" pedindo ajuste,
      **responder no mesmo thread de e-mail**, nunca reenviar do zero.
- [ ] Atenção: rejeição automática se ficar 3 meses parado em revisão sem resposta.

## 6. Depois de aprovado

- [ ] Slug fica travado pra sempre — não dá pra renomear depois.
- [ ] Primeiro `svn commit` publica o plugin AO VIVO imediatamente, sem botão de pausa —
      só fechar de vez depois. Preparar exatamente o que vai pro `trunk/` antes de commitar.
- [ ] Manter só as últimas 1-2 tags no SVN (não é histórico completo — isso é papel do
      GitHub).

---

**Estado real hoje (2026-09-09)**: nada disso foi feito. Nenhuma conta, nenhum readme.txt,
nenhum asset visual, nenhuma decisão sobre automação SVN. É só o mapa do caminho.
