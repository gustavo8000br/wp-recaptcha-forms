#!/usr/bin/env bash
#
# Gate de i18n ponta a ponta (Story 1.20 AC 5).
#
# Faz o percurso inteiro do fluxo de tradução, do zero, na ordem em que um contribuidor o
# faria — e é isso que responde ao S-17 da Story 1.21: "instrução não verificada é
# instrução errada". Se o README diz "rode `composer i18n:pot`, traduza o `.po`, rode
# `composer i18n:build`", este script prova que esses três passos funcionam de verdade,
# em vez de terem funcionado uma vez na máquina de quem escreveu a documentação.
#
#   1. gera o .pot do zero a partir do código;
#   2. confere que o .pot versionado está em dia com o código (deriva de string);
#   3. deriva o pseudo-locale en_CA;
#   4. compila o .mo (mesmo caminho de código dos catálogos de verdade);
#   5. roda o gate de pseudo-locale sobre as superfícies visíveis;
#   6. apaga tudo que criou.

set -euo pipefail

cd "$(dirname "$0")/.."

TMP="$(mktemp -d)"
POT_VERSIONADO="languages/wp-recaptcha-forms.pot"
PSEUDO="languages/wp-recaptcha-forms-en_CA.po"

cleanup() {
	rm -rf "$TMP"
	rm -f "$PSEUDO" "${PSEUDO%.po}.mo"
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# 1 e 2. O .pot versionado bate com o código?
# ---------------------------------------------------------------------------
php bin/i18n-pot.php "$TMP/gerado.pot" > /dev/null

# Comentários de referência `#: arquivo:linha` mudam quando alguém só move código; o AC 6
# da Story 1.20 manda ignorá-los na comparação, senão todo PR de refatoração falha o gate
# por um motivo que não é o que o gate existe para vigiar.
sem_refs() {
	grep -v '^#:' "$1" | grep -v '^# Copyright' | grep -v 'POT-Creation-Date'
}

if [ ! -f "$POT_VERSIONADO" ]; then
	printf 'FALHOU: %s não existe. Rode `composer i18n:pot`.\n' "$POT_VERSIONADO" >&2
	exit 1
fi

if ! diff -u <(sem_refs "$POT_VERSIONADO") <(sem_refs "$TMP/gerado.pot") > "$TMP/diff.txt"; then
	printf 'FALHOU: o .pot versionado não bate com as strings do código.\n\n' >&2
	head -40 "$TMP/diff.txt" >&2
	printf '\nRode `composer i18n:pot` e comite o resultado.\n' >&2
	exit 1
fi

printf 'ok: .pot versionado em dia com o código\n'

# ---------------------------------------------------------------------------
# 3 e 4. Pseudo-locale e compilação.
# ---------------------------------------------------------------------------
php bin/i18n-pseudo.php "$POT_VERSIONADO" "$PSEUDO" > /dev/null
php bin/i18n-build.php "$PSEUDO" > /dev/null

printf 'ok: pseudo-locale en_CA derivado e compilado\n'

# ---------------------------------------------------------------------------
# 5. O gate.
# ---------------------------------------------------------------------------
php tests/i18n/pseudo-locale-gate.php

printf '\nGate de i18n aprovado.\n'
