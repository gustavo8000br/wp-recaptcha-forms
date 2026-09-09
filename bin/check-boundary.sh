#!/usr/bin/env bash
#
# Gates de fronteira (arquitetura v1 §4.5, v1.1 §8.2).
#
# Um princípio arquitetural sem verificação automatizada vira comentário decorativo.
# Este script é o que mantém A2 verdadeiro daqui a dois anos, quando ninguém lembrar
# do documento.
#
# Sobre OB-06 (armadilha de shell): o padrão original
#
#     ! grep -rl ... | grep -v '^src/Provider/'
#
# passa por acidente sob `set -o pipefail` — o código de saída avaliado é o do último
# grep, e um gate que passa por acidente é pior que gate nenhum. Aqui o script roda
# COM `pipefail` ligado de propósito e captura a saída numa variável com `|| true`,
# testando o conteúdo em vez do código de saída. É robusto nas duas configurações.

set -euo pipefail

cd "$(dirname "$0")/.."

FAILED=0

fail() {
	printf 'FALHOU: %s\n' "$1" >&2
	printf '%s\n' "$2" >&2
	FAILED=1
}

pass() {
	printf 'ok: %s\n' "$1"
}

# ---------------------------------------------------------------------------
# 1. A URL do provedor existe em um único arquivo.
# ---------------------------------------------------------------------------
HITS="$(grep -rn --include='*.php' -e 'recaptcha/api' src/ | grep -v '^src/Provider/Endpoints.php:' || true)"

if [ -n "$HITS" ]; then
	fail 'a URL do provedor aparece fora de src/Provider/Endpoints.php' "$HITS"
else
	pass 'URL do provedor confinada a src/Provider/Endpoints.php'
fi

# ---------------------------------------------------------------------------
# 2. O vocabulário de erro do provedor não atravessa a fronteira.
# ---------------------------------------------------------------------------
HITS="$(grep -rn --include='*.php' -E 'invalid-input-|invalid-keys|missing-input-|timeout-or-duplicate|error-codes|g-recaptcha-response' src/ | grep -v '^src/Provider/' || true)"

if [ -n "$HITS" ]; then
	fail 'vocabulário do provedor aparece fora de src/Provider/' "$HITS"
else
	pass 'vocabulário do provedor confinado a src/Provider/'
fi

if [ "$FAILED" -ne 0 ]; then
	printf '\nGate de fronteira reprovado.\n' >&2
	exit 1
fi

printf '\nGate de fronteira aprovado.\n'
