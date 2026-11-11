# ClyoNutri v41 · NI2-514 Fase 1: meta de energia no plano, medidas caseiras da clínica e plano sem paciente (29/10/2026)

**SQL novo na v41: SIM, e ele aguarda o seu OK.** A v41 cria a migração 61 (duas tabelas novas e `meal_plans.patient_id` passa a aceitar vazio). Os três arquivos estão em `database/manual/atualizacao-v41-{preflight,completa,verificacao}.sql`. **Não rodei nada em produção.** O SQL da v39 (migração 60) também continua aguardando o seu OK, sem mudança.

A v41 é a **v40** (sha256 `0ee397979e5ffe5cc9cbf6076b2af0ac464f0f6b2c606d3134fde46d449441d5`) com as mudanças listadas em `COMPARACAO-V40-V41.txt`. Nada da v40 foi removido. O pacote não traz `.env` nem chaves.

## Aviso clínico

As fórmulas (Mifflin-St Jeor, Harris-Benedict revisada, Cunningham), as faixas de fator de atividade (FAO/OMS/UNU 2001), os fatores de Atwater (4/4/9) e a tolerância de 5% entre meta e plano **foram implementados a partir das referências citadas, mas não foram conferidos por um nutricionista**. Antes de usar com pacientes, o nutricionista responsável precisa conferir as fórmulas, os limites e os textos (gate 5 da NI2-514: checklist assinado). O sistema calcula, registra e avisa; quem decide a meta é o profissional.

## O que muda para a nutricionista

1. **Meta de energia no plano.** Na tela do plano há o cartão "Energia e metas do plano": fórmula, sexo, idade, peso, altura (ou massa livre de gordura na Cunningham), fator de atividade, objetivo e ajuste (kcal ou % do GET), e macros em % do VET ou em g/kg. O sistema mostra TMB, GET e VET, compara com o que o plano tem hoje e avisa quando a soma dos macros não fecha com o VET. Tudo fica gravado no plano com a fórmula, a referência e a versão do cálculo (`v41-1`), e aparece na impressão. Avisos, não bloqueios.
2. **Medidas caseiras da clínica** (Base de Alimentos > Medidas caseiras, `/medidas-caseiras`). A clínica cadastra quanto pesa a colher, a fatia ou a unidade de cada alimento, **sempre com a fonte**, ou importa por CSV (tudo ou nada, com relatório por linha). Valem só para a clínica e entram no seletor do plano. **O ClyoNutri não traz valores novos de medida caseira**: nada foi inventado. As poucas medidas globais que já existiam continuam, marcadas como de origem não documentada.
3. **Plano sem paciente.** Em Planos alimentares > Novo plano, o paciente é opcional, o título vem preenchido e as 6 refeições padrão já vêm marcadas. O plano fica como rascunho com "Paciente: a definir" e um aviso de que as alergias não foram avaliadas; **publicar só é possível depois de atribuir o paciente**. Versão, alerta de alergia e consentimento continuam como antes.
4. **Menos cliques** no cenário "plano com um alimento": 9 na v40, 6 na v41 com paciente e 5 sem paciente (`docs/benchmark-webdiet/CONTAGEM-DE-CLIQUES-NI2-514.md`). A meta de "até 6 cliques" ainda precisa ser validada com nutricionistas.

Permissões: só nutricionista e titular gravam meta e medidas; recepção não. Cada clínica só vê e usa as próprias medidas. Todas as gravações têm CSRF e ficam na auditoria. A meta entra na exportação LGPD do paciente.

## Banco de dados (migração 61)

- `meal_plan_energy_targets` (nova): uma meta por plano.
- `clinic_household_measures` (nova): medidas da clínica, com fonte.
- `meal_plans.patient_id` passa a aceitar vazio (a coluna mantém o tipo que já tem em produção).
- Ids `BIGINT(20)`, InnoDB, utf8mb4_unicode_ci, **sem chaves estrangeiras**, como a estrutura real de produção. Nada é apagado nem alterado em dados existentes.

## Antes de subir os arquivos

1. **Backup** do banco e dos arquivos.
2. **Ordem do SQL (depois do seu OK):** se a migração 60 ainda não estiver registrada, rode antes o SQL da v39 (`atualizacao-v39-*`). Depois, no phpMyAdmin: `atualizacao-v41-preflight.sql` (só lê; a coluna "situacao" deve dizer PRONTO PARA MIGRAR ou OK), `atualizacao-v41-completa.sql`, e `atualizacao-v41-verificacao.sql` (deve dizer OK). Pode rodar a completa duas vezes sem efeito. Se preferir, `php bin/migrate.php` faz o mesmo; os dois caminhos dão a mesma estrutura.
3. **Arquivos:** envie `clyonutri-v41.zip` para `/home3/maic6824/clyonutri.com`, **Extract** (sobrescrever), apague o zip do servidor, limpe o cache e abra com Ctrl+F5. Não mexa em `.env` nem em `storage/`.
4. **Sem o SQL**, as telas novas não funcionam (as tabelas não existem). Suba o código e rode o SQL na mesma janela.

## Conferência depois de subir (clínica TESTE)

- `https://clyonutri.com/up` responde `{"status":"ok"}`.
- Planos alimentares > Novo plano, sem paciente: abre o plano com "Paciente: a definir", 6 refeições e o botão "Publicar (falta o paciente)".
- No cartão de energia: Mifflin-St Jeor, homem, 35 anos, 80 kg, 180 cm, fator 1,55, redução de 500 kcal deve dar TMB 1.755, GET 2.720 e VET 2.220 kcal.
- `/medidas-caseiras`: cadastre uma medida com fonte "TESTE" e veja que ela aparece no seletor de alimentos do plano. Depois remova.

## Validação e limites

- Testes novos (não vão no zip): `ni2_514_energy_and_planless_plans_test.php`, `ni2_514_energy_http_test.php`, `ni2_514_clinic_measures_test.php`, `ni2_514_sql_manual_test.php` e o roteiro de cliques `ni2_514_click_count.cjs`.
- Suíte completa e teste de fumaça em modo produção em MySQL 5.7.44 com dados "TESTE": ver `PACOTE-V41-2026-10-29.md` e `docs/evidencias/ni2-514/SMOKE-V41-mysql57.txt`.
- SQL manual testado numa cópia **só da estrutura** do banco de produção (sem dados) em MySQL 5.7.44: como está hoje, depois do SQL da v39, segunda execução, migração PHP depois do SQL e o contrário.
- **Não rodei nada no banco real de produção.** Sem homologação em produção.
- Não incluído (fases 2 e 3 da NI2-514): biblioteca de modelos de plano, substituições e equivalências automáticas, novas bases de alimentos, migração de concorrentes, antropometria, modelos de anamnese, anexos, teste de usabilidade com 5 nutricionistas e checklist assinado.

## O que vem no zip

- `clyonutri-v41.zip` com `vendor/`, arquivos 0644 e pastas 0755, e `clyonutri-v41.zip.sha256` ao lado.
- Na raiz: LEIA-ME v34 a v41, `COMPARACAO-V34-V35.txt` a `V40-V41`, `VALIDACAO-V35/V36.txt`, `RELEASE_INFO.json`.
- `docs/benchmark-webdiet/`: relatório da Fase 0 e contagem de cliques.
- Não vão no zip: testes, app mobile, `.git`, `.env`, dados de `storage/` e as capturas de tela.

## Voltar atrás

Extraia por cima o `clyonutri-v40.zip`. As tabelas novas podem ficar no banco sem efeito para a v40. Atenção: a v40 espera paciente em todo plano; se algum plano foi criado sem paciente, atribua o paciente (ou apague esse rascunho) antes de voltar.
