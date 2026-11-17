# Suíte de testes — perfis e como rodar (NI2-884, v54.76)

| Perfil | Comando | O que roda | Tempo (box, 17/11/2026) |
|---|---|---|---|
| core (gate de release) | `bin/run-tests core` | lint + schema + `tests/core_modules.txt` (isoladas) + lista `CORE_TESTS` | ~1 min |
| completa | `bin/run-tests full` | todo `tests/*.php`, cada arquivo num SQLite próprio | ~3 min |
| infra | `bin/run-tests infra` | navegador, MySQL, Apache (`tests/run_release_gate.sh --browser`) | depende do ambiente |

## Banco de teste da suíte completa
`tests/run_full_suite.php` monta **uma vez** um banco modelo e copia para cada arquivo:
1. `php bin/migrate.php` (todas as migrações);
2. `php bin/seed.php` (base da instalação) e `php bin/seed.php --demo` (consultório 1 / usuário 1);
3. `tests/_full_suite_base_fixture.php` (consultórios 2 e 3 com titular; só com `APP_ENV=testing`).

Cada teste roda com `APP_ENV=testing`, chave de cifragem de teste, e-mail em log e asserções ligadas.
Nenhum teste usa o banco do `.env`. Para rodar um arquivo só: `php tests/run_full_suite.php nome_do_arquivo`.
Para rodar uma lista: `php tests/run_full_suite.php @tests/core_modules.txt`.

## Arquivos de controle
- `tests/suite_manifest.tsv`: o que **não** roda na completa e por quê (fixture, wrapper com script próprio, exige MySQL/Apache/replatform). Aparece como `NAO_EXECUTADO`.
- `tests/known_failures_full.tsv`: falhas conhecidas, com a decisão (DESATUALIZADO-TESTE, FIXTURE, INVESTIGAR, DECISAO-MAICON, NI2-885, AMBIENTE, DEPENDE-DA-DATA). Aparecem como `KNOWN` e **continuam deixando a saída em 1**. Nenhuma delas virou Jira: precisa do OK do Maicon.
- Relatório de cada execução: `storage/test-reports/full-AAAAMMDD-HHMMSS.tsv` (fora do git e do zip).

## Regra
Teste novo que toca prontuário, isolamento, agenda, assinatura ou autenticação entra no core
(em `CORE_TESTS` se tiver banco próprio, ou em `tests/core_modules.txt` se depender do banco modelo).
