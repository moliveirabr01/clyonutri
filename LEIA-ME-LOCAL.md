# Execução local

A codebase canônica é `/Users/moliveira/Nutri_II`.

```sh
cd /Users/moliveira/Nutri_II
./bin/serve-local.sh
```

Abra http://127.0.0.1:8081/dashboard. Encerre com Ctrl+C.
Para outra porta: `CLYONUTRI_PORT=8082 ./bin/serve-local.sh`.
O comando usa o PHP instalado e o `.env` existente, sem migrar, semear ou substituir o banco.

O dashboard e o menu da v54.58 foram integrados nesta codebase preservando alterações locais compatíveis. Identidade: `v54.58-local`, distinta do pacote imutável. Cópias dos arquivos anteriores: `storage/backups/integracao-v54-58-20261111/antes`. Evidências: `docs/validation/integracao-v54-58`.
