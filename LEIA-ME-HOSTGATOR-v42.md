# ClyoNutri v42 · NI2-514 Fase 2: modelos de plano, busca de alimentos, editor sem recarregar e importação de pacientes (29/10/2026)

**SQL novo na v42: SIM, e ele aguarda o seu OK.** A v42 cria a migração 62 (três tabelas novas e seis colunas novas em `meal_plan_templates`). Os três arquivos estão em `database/manual/atualizacao-v42-{preflight,completa,verificacao}.sql`. **Não rodei nada em produção.** Os SQL da v39 (migração 60) e da v41 (migração 61) também continuam aguardando o seu OK, sem mudança.

A v42 é a **v41** (sha256 `35b443c980ea3d7776a327beb4625105c7edf1ff70418b95cce11ffb79eb7c3e`) com as mudanças listadas em `COMPARACAO-V41-V42.txt`. Nada da v41 foi removido. O pacote não traz `.env` nem chaves.

## Aviso clínico

Continua valendo o aviso da v41: fórmulas, faixas de atividade, fatores de Atwater e a tolerância de 5% **não foram conferidos por um nutricionista**. Na v42 entram mais três pontos que precisam da mesma conferência:

- A comparação de substituições mostra só a diferença aritmética de energia e macros entre o item e a substituição, e quantos gramas da substituição dariam a mesma energia. **Não é uma tabela de equivalência**: o ClyoNutri não traz grupos de equivalentes, porque não há fonte verificável com licença confirmada. Quem decide a troca é o profissional.
- Os nomes regionais (aipim e macaxeira = mandioca; jerimum = abóbora; mexerica e bergamota = tangerina) são só linguagem, sem dado nutricional novo.
- A forma de preparo exibida na busca (cru, cozido, grelhado...) é lida do nome do alimento no catálogo; quando o nome não informa, nada aparece.

O roteiro para a avaliação com 5 nutricionistas e o termo de liberação assinado estão em `docs/benchmark-webdiet/ROTEIRO-AVALIACAO-CLINICA-V42.md`. **As sessões ainda não foram feitas.**

## O que muda para a nutricionista

1. **Modelos de plano** (NI2-519). No plano, o botão "Salvar como modelo" guarda refeições, horários, alimentos, quantidades e substituições. O modelo **não guarda o paciente, a anamnese, a meta de energia individual nem as observações**. Em Planos alimentares > Modelos (`/modelos`) a clínica vê os próprios modelos, usa um (com ou sem paciente, sempre como rascunho novo), arquiva e escolhe um como padrão da clínica (ele já vem selecionado em Novo plano). Cada clínica só vê os próprios modelos.
2. **Substituições com comparação** (NI2-519). Ao lado de cada substituição, o editor mostra a diferença de energia e macros em relação ao item e a quantidade que daria a mesma energia.
3. **Busca de alimentos** (NI2-520). Nomes regionais, forma de preparo no resultado, e alimentos que a clínica já usou aparecem antes dentro do mesmo grupo de relevância (com um selo que explica). Em Base de Alimentos > Buscas sem resultado (`/alimentos/buscas`) a clínica vê os termos que não encontraram nada nos últimos 30 dias. Só o termo digitado é guardado, sem dado de paciente, por 90 dias.
4. **Editor sem recarregar** (NI2-521). Adicionar, editar ou remover alimentos e substituições salva sem recarregar a página: a rolagem fica onde estava e a barra de totais (energia, CHO, PTN, LIP e fibras contra a meta gravada) atualiza na hora. Os cliques do cenário "plano com um alimento" são os mesmos da v41 (6 com paciente, 5 sem).
5. **Importar pacientes por planilha** (NI2-522). Em Pacientes > Importar pacientes (`/pacientes/importar`), envie um CSV (há um modelo para baixar) ou cole o texto. Primeiro vem a prévia, linha a linha (pronto, já existe ou com erro); nada é gravado antes de confirmar. Ao confirmar, os pacientes entram com consentimento "não coletado" (registre na ficha antes de usar dados de saúde). O mesmo arquivo não cria pacientes duplicados. "Desfazer importação" remove os pacientes daquela importação que ainda não têm consultas, planos ou outros registros. Os dados brutos da planilha são apagados ao confirmar, ao descartar ou depois de 24 horas.

Permissões: só nutricionista e titular salvam e usam modelos e importam pacientes; recepção não. Todas as gravações têm CSRF, ficam na auditoria e respeitam a clínica de quem está logado.

## Banco de dados (migração 62)

- `meal_plan_templates` (já existe desde a migração 5) ganha `status`, `source_plan_id`, `created_by_user_id`, `is_clinic_default`, `archived_at` e `updated_at`. As linhas existentes ficam como `active`; nada é apagado.
- `food_search_metrics` (nova): contagem diária por termo e por clínica.
- `patient_import_batches` e `patient_import_rows` (novas): importação com prévia, relatório por linha e desfazer.
- Ids `BIGINT(20)`, InnoDB, utf8mb4_unicode_ci, **sem chaves estrangeiras**. Nenhum tipo de coluna existente muda.

## Antes de subir os arquivos

1. **Backup** do banco e dos arquivos.
2. **Ordem do SQL (depois do seu OK):** v39 (migração 60), se ainda não registrada; v41 (migração 61), se ainda não registrada; depois a v42, no phpMyAdmin: `atualizacao-v42-preflight.sql` (só lê; a coluna "situacao" deve dizer PRONTO PARA MIGRAR ou OK), `atualizacao-v42-completa.sql` e `atualizacao-v42-verificacao.sql` (deve dizer OK). Pode rodar a completa duas vezes sem efeito. `php bin/migrate.php` faz o mesmo; os dois caminhos dão a mesma estrutura.
3. **Arquivos:** envie `clyonutri-v42.zip` para `/home3/maic6824/clyonutri.com`, **Extract** (sobrescrever), apague o zip do servidor, limpe o cache e abra com Ctrl+F5. Não mexa em `.env` nem em `storage/`.
4. **Sem o SQL**, modelos, buscas sem resultado e importação não funcionam (as telas de buscas sem resultado e de importação avisam que a migração está pendente). Suba o código e rode o SQL na mesma janela.

## Conferência depois de subir (clínica TESTE)

- `https://clyonutri.com/up` responde `{"status":"ok"}`.
- Abra um plano TESTE, adicione um alimento: a página não recarrega e a barra de totais muda.
- "Salvar como modelo" e depois `/modelos` > "Usar este modelo": abre um rascunho novo com as mesmas refeições. Arquive o modelo TESTE no fim.
- No seletor de alimentos, busque "aipim": aparece mandioca.
- `/pacientes/importar`: cole duas linhas com "Paciente TESTE", confira a prévia, confirme e depois use "Desfazer importação".

## Validação e limites

- Testes novos (não vão no zip): `ni2_514_v42_units_test.php`, `ni2_514_v42_phase2_http_test.php`, `ni2_514_v42_clinical_scenarios_test.php`, `ni2_514_v42_sql_manual_test.php`; o roteiro de cliques `ni2_514_click_count.cjs` ganhou o caminho da v42.
- Suíte completa e teste de fumaça em modo produção em MySQL 5.7.44 com dados "TESTE": ver `PACOTE-V42-2026-10-29.md` e `docs/evidencias/ni2-514/SMOKE-V42-mysql57.txt`.
- SQL manual testado numa cópia **só da estrutura** do banco de produção (sem dados) em MySQL 5.7.44, na ordem v39, v41 e v42, com segunda execução e migração PHP antes e depois.
- **Não rodei nada no banco real de produção.** Sem homologação em produção.
- Não incluído: grupos de equivalentes e tabela de substituição automática (sem fonte com licença), novas bases de alimentos (TBCA com licença não confirmada), mapeamento manual de colunas na importação, importação de modelos de outros sistemas e de XLSX, nova importação de um arquivo já desfeito, antropometria, modelos de anamnese, anexos (Fase 3), sessões com 5 nutricionistas e termo assinado (NI2-523).

## O que vem no zip

- `clyonutri-v42.zip` com `vendor/`, arquivos 0644 e pastas 0755, e `clyonutri-v42.zip.sha256` ao lado.
- Na raiz: LEIA-ME v34 a v42, `COMPARACAO-V34-V35.txt` a `V41-V42`, `VALIDACAO-V35/V36.txt`, `RELEASE_INFO.json`.
- `docs/benchmark-webdiet/`: relatório da Fase 0, contagem de cliques e roteiro de avaliação clínica.
- Não vão no zip: testes, app mobile, `.git`, `.env`, dados de `storage/` e as capturas de tela.

## Voltar atrás

Extraia por cima o `clyonutri-v41.zip`. As tabelas e colunas novas podem ficar no banco sem efeito para a v41. Pacientes importados continuam cadastrados como pacientes normais; desfaça a importação antes de voltar se não quiser mantê-los.
