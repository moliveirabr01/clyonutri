# ClyoNutri v43 · NI2-519 e NI2-520: modelos com aprovação e versões, equivalência por regra da clínica e busca com fonte (29/10/2026)

**SQL novo na v43: SIM, e ele aguarda o seu OK.** A v43 cria a migração 63 (três tabelas novas, 16 colunas novas e 6 índices). Os três arquivos estão em `database/manual/atualizacao-v43-{preflight,completa,verificacao}.sql`. **Não rodei nada em produção.** Os SQL da v39 (60), v41 (61) e v42 (62) não mudaram e também aguardam o seu OK.

A v43 é a **v42** (sha256 `0f3ac4a9f36fa8a20806bfe0b62ca507d230abb6c0fdd983eee79e191b4c9775`) com as mudanças listadas em `COMPARACAO-V42-V43.txt`. Nada da v42 foi removido. O pacote não traz `.env` nem chaves.

## Aviso clínico

Nada do que entra na v43 foi conferido por um nutricionista. Precisam dessa conferência:

- **Equivalência.** O ClyoNutri não traz tabela de equivalentes pronta: não há fonte com licença que permita uso comercial. A clínica cadastra as próprias regras (grupos de alimentos, tolerância de energia e de macros e a fonte citada). A regra nasce pendente e só vale depois que um nutricionista com CRN a marca como revisada. Sem regra revisada, a substituição mostra só a diferença de nutrientes e diz que não há regra.
- **Preparo.** "Cru", "Cozido" e os demais rótulos são lidos do nome do alimento na fonte.
- **USDA.** O importador do USDA FoodData Central está pronto, mas não foi aplicado. Os valores são de alimentos americanos e os nomes ficam em inglês.

Fontes e licenças conferidas: `docs/benchmark-webdiet/FONTES-E-LICENCAS-V43.md`.

## O que muda para a nutricionista

1. **Modelos pessoais, da clínica e aprovados** (NI2-519). Ao salvar um plano como modelo, escolha "Pessoal (só eu)" ou "Toda a clínica". O modelo pode guardar a meta do plano como referência (sem dados do paciente). Quem criou pode abrir um modelo pessoal para a clínica. Titular ou gestor aprova modelos da clínica; a lista mostra o selo "Aprovado", quem aprovou e o método. Modelos de uma clínica nunca aparecem para outra.
2. **Usar modelo cria uma cópia** (NI2-519). O plano novo é um rascunho editável que guarda de qual modelo veio e a meta de referência. Editar a cópia não muda o modelo.
3. **Publicar gera versão** (NI2-519). Publicar grava um retrato do plano em `meal_plan_versions`. Plano publicado não aceita edição direta: "Criar nova versão para editar" abre um rascunho novo e o retrato anterior fica como estava.
4. **Método do plano** (NI2-519): por alimentos, por equivalentes ou qualitativo. Plano qualitativo só publica com orientação escrita.
5. **Substituição com veredito** (NI2-519). Cada substituição mostra a diferença de energia e macros. Com regra revisada dos mesmos grupos, mostra "Equivalente pela regra da clínica" ou "Fora da tolerância da regra" e o nome da regra. Com grupos diferentes ou sem regra, diz isso. O editor sugere trocas dentro da regra, só com alimentos da base geral ou da própria clínica.
6. **Regras de equivalência** em Base de Alimentos > Equivalências (`/alimentos/equivalencias`). Recepção não cria nem revisa.
7. **Busca com fonte e preparo** (NI2-520). Cada resultado mostra fonte e edição (por exemplo "Fonte: TACO 4ª ed. rev. e ampl. (2011)") e o preparo. "arroz integral" traz o cozido e o cru, cada um com o rótulo. Favoritos da clínica aparecem antes. Nomes regionais continuam valendo (aipim traz mandioca antes de bolo de aipim).
8. **Métricas de busca** (NI2-520). Em Buscas sem resultado (`/alimentos/buscas`) entram também as correções: quando alguém escolhe um alimento e logo troca por outro. Só termo, alimento e contagem por dia; nenhum dado de paciente.

Todas as gravações têm CSRF, ficam na auditoria e respeitam a clínica de quem está logado.

## Banco de dados (migração 63)

- `foods` ganha `source_version`, `source_code`, `source_imported_at`, `preparation` e `is_current` (padrão 1). O SQL preenche edição, código e data nas linhas TACO que ainda não têm versão. **Nenhum valor nutricional muda.** Índice único por fonte, versão e código: o mesmo alimento da mesma edição não entra duas vezes.
- `food_household_measures` ganha `source` e `source_version`.
- `meal_plans` ganha `template_id`, `plan_method` e `reference_target_json`.
- `meal_plan_templates` ganha `visibility` (padrão clinica), `approval_status`, `approved_by_user_id`, `approved_at`, `plan_method` e `target_json`. Os modelos existentes ficam "Toda a clínica", não aprovados.
- Tabelas novas: `food_favorites`, `food_search_selections` e `food_equivalence_rules`.
- Ids `BIGINT(20)`, InnoDB, utf8mb4_unicode_ci, **sem chaves estrangeiras**. Nenhum tipo de coluna existente muda e nada é apagado.

## Antes de subir os arquivos

1. **Backup** do banco e dos arquivos.
2. **Ordem do SQL (depois do seu OK):** v39 (60), v41 (61) e v42 (62), cada um só se a migração ainda não estiver registrada; depois a v43 no phpMyAdmin: `atualizacao-v43-preflight.sql` (só lê; "situacao" deve começar com OK), `atualizacao-v43-completa.sql` e `atualizacao-v43-verificacao.sql` (deve dizer OK). A completa pode rodar duas vezes sem efeito. `php bin/migrate.php` dá a mesma estrutura.
3. **Arquivos:** envie `clyonutri-v43.zip` para `/home3/maic6824/clyonutri.com`, **Extract** (sobrescrever), apague o zip do servidor, limpe o cache e abra com Ctrl+F5. Não mexa em `.env` nem em `storage/`.
4. Sem o SQL, as telas novas avisam que a migração está pendente e o restante segue como na v42. Suba o código e rode o SQL na mesma janela.
5. **USDA (opcional, só se você decidir):** depois da migração, `php bin/importar_usda_fdc.php --dir=<pasta do CSV> --dry-run` mostra as contagens; `--apply` grava.

## Conferência depois de subir (clínica TESTE)

- `https://clyonutri.com/up` responde `{"status":"ok"}`.
- No seletor de alimentos, busque "arroz integral": aparecem cozido e cru, cada um com preparo e "Fonte: TACO 4ª ed.".
- `/modelos`: salve um modelo TESTE pessoal, abra para a clínica, aprove como titular, use. O rascunho novo aceita edição e o modelo fica igual. Publique e confira a versão.
- `/alimentos/equivalencias`: crie uma regra TESTE com fonte, revise com o CRN e veja o veredito numa substituição. Arquive a regra no fim.

## Validação e limites

- Testes novos (não vão no zip): `ni2_519_520_v43_units_test.php` (35), `ni2_519_520_v43_http_test.php` (82) e `ni2_519_520_v43_sql_manual_test.php` (57), todos passando. Suíte completa sem falha nova em relação à v42.
- Teste de fumaça em modo produção em MySQL 5.7.44 com dados TESTE: `docs/evidencias/ni2-519-520/SMOKE-V43-mysql57.txt`.
- SQL manual testado em MySQL 5.7.44 sobre o instalador da v42 com o catálogo TACO, com segunda execução, e comparado com a migração PHP.
- **Não rodei nada no banco real de produção.** Sem homologação em produção.
- Não incluído: TBCA (licença sem uso comercial), medidas caseiras do IBGE (sem licença de reuso), tabela de equivalentes pronta, aplicação do USDA (aguarda decisão).

## Voltar atrás

Extraia por cima o `clyonutri-v42.zip`. As colunas e tabelas novas podem ficar no banco sem efeito para a v42.
