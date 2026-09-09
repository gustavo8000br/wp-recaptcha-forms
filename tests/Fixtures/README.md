# Fixtures

## `siteverify/`

Respostas fixas do endpoint `siteverify` do Google, uma por arquivo, correspondendo às
linhas da matriz normativa da arquitetura v1.1 §4.5.

São **dados**, não código: o vocabulário de erro do Google vive aqui e em `src/Provider/`,
e em nenhum outro lugar — é a mesma fronteira que `bin/check-boundary.sh` protege.

Consumidas por `tests/Unit/Provider/SiteverifyFixtureTest.php`, que percorre a matriz
inteira com o provider real e um transporte falso.

**O que NÃO está aqui, de propósito:** os casos de falha de transporte (erro de rede,
HTTP 503, HTTP 429, corpo não-JSON, corpo vazio). Nenhum deles é um JSON — são formas de
uma resposta *não* ser uma resposta. Guardá-los como arquivo `.json` exigiria inventar
uma representação para "corpo vazio", e essa representação seria a única coisa testada.
Continuam construídos no `ProviderMatrixTest`, onde a forma é o objeto do teste.
