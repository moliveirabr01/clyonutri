# Roteiro de avaliação clínica e de uso da prescrição (NI2-523) · v42

**Situação: roteiro pronto, sessões não realizadas.** Este documento é o instrumento da NI2-523. Ele não substitui a avaliação: a liberação só acontece depois das sessões com nutricionistas reais e da assinatura no fim deste arquivo.

**Pendente de revisão por nutricionista.** Os cenários, as metas de tempo e de cliques e os critérios abaixo foram escritos pela equipe de produto a partir das histórias NI2-514 a NI2-523. Um nutricionista responsável precisa conferir tudo antes da primeira sessão.

**Sem dados reais.** Use só a clínica de demonstração com pacientes e planos fictícios marcados "TESTE". Nenhum participante deve digitar dados de paciente de verdade, nem trazer planilhas reais para a importação.

## 1. Quem participa

- Pelo menos **5 nutricionistas**, sendo pelo menos **2 que usam ou usaram o WebDiet** nos últimos 12 meses.
- Misturar perfis: consultório individual, clínica com recepção, atendimento esportivo ou materno-infantil.
- Cada participante assina o termo de participação da sessão (modelo da empresa) antes de começar. A sessão pode ser gravada só com autorização.

## 2. Preparação (antes de cada sessão)

1. Ambiente de demonstração com a v42+, clínica `"Clínica TESTE Avaliação"`, um usuário nutricionista por participante (`p1.avaliacao@teste.clyonutri.local` a `p5.avaliacao@teste.clyonutri.local`) e uma conta de recepção (`recepcao.avaliacao@teste.clyonutri.local`), provisionados de forma idempotente por `php bin/preparar_avaliacao_clinica_ni2_523.php`.
2. Cinco pacientes TESTE já cadastrados com antropometria e anamnese finalizada: adulto com objetivo de redução de peso; adulto com alergia severa a amendoim; gestante (2º trimestre); idoso com 72 anos; adolescente atleta de 15 anos.
3. Uma planilha CSV TESTE com 10 pacientes fictícios (8 válidos + 2 com erro proposital: sem nome na linha 4 e data inválida `31/02/1990` na linha 7) em `docs/benchmark-webdiet/pacientes_teste_ni2_523.csv`.
4. Cronômetro, a folha de registro (seção 5) e o contador de cliques (observador anota; não usar gravação de tela sem autorização).

## 3. Cenários

Cada cenário tem um resultado esperado. O participante faz sozinho; o observador só intervém se ele travar por mais de 3 minutos (conta como falha assistida).

| # | Cenário | Resultado esperado | Meta (a validar) |
|---|---------|--------------------|------------------|
| C1 | Plano com 6 refeições para o adulto em redução de peso, com meta de energia calculada (fórmula à escolha) | Plano em rascunho com meta gravada, fórmula e versão visíveis, totais do dia na barra | até 12 min |
| C2 | Mesmo cenário para a paciente gestante | O participante avalia se as fórmulas e avisos atendem; registrar o que falta | registro qualitativo |
| C3 | Plano para o paciente com alergia a amendoim incluindo um alimento com amendoim | O alerta de alergia aparece antes de publicar | 100% dos participantes veem o alerta |
| C4 | Adicionar um alimento usando medida caseira da clínica (colher, fatia) | Quantidade convertida em gramas com a fonte da medida | até 6 cliques |
| C5 | Buscar "aipim" e "macaxeira" no seletor | Mandioca aparece; forma de preparo visível | encontra na 1ª busca |
| C6 | Salvar o plano do C1 como modelo e usar o modelo para o idoso | Rascunho novo com as mesmas refeições e sem a meta individual | até 5 min |
| C7 | Trocar um alimento por uma substituição e conferir a diferença de energia e macros | O participante interpreta a diferença corretamente; o aviso de que não é equivalência foi entendido | 100% entendem o aviso |
| C8 | Publicar uma nova versão do plano e comparar com a anterior | Versão nova publicada, anterior preservada | sem perda de dado |
| C9 | Imprimir o plano | Impressão legível com meta, refeições e orientações | aprovado pelo participante |
| C10 | Importar a planilha CSV TESTE, conferir a prévia, confirmar e desfazer | 8 cadastrados, 2 recusados com motivo; desfazer remove os 8 | sem paciente duplicado |
| C11 | Privacidade: confirmar que o modelo não guarda paciente e que a recepção não vê o plano | Participante confirma | 100% |

## 4. Perguntas depois de cada cenário (escala de 1 a 5)

1. Foi fácil concluir? 2. Confiou no resultado (números e avisos)? 3. Faria assim na rotina do consultório?
Para ex-usuários do WebDiet: 4. Comparado ao WebDiet, foi mais rápido, igual ou mais lento? 5. O que faltou que o WebDiet tem?

No fim da sessão: nota geral de 0 a 10 (recomendaria a um colega?) e as três maiores dificuldades.

## 5. Registro por participante

| Campo | Como medir |
|-------|-----------|
| Concluiu sem ajuda | sim / com ajuda / não, por cenário |
| Tempo | do primeiro clique até o resultado esperado, em minutos |
| Cliques | contagem do observador, mesma convenção de `CONTAGEM-DE-CLIQUES-NI2-514.md` |
| Falhas | erro do sistema, dado errado na tela, alerta que não apareceu, texto que confundiu |
| Satisfação | respostas da seção 4 |
| Observações clínicas | o que o participante considera inseguro ou incorreto |

Guarde as folhas sem nome do participante (use P1 a P5). Dados pessoais dos participantes ficam fora do sistema.

## 6. Critérios de liberação (gate)

A prescrição só é liberada para uso com pacientes quando **todos** os itens abaixo estiverem marcados:

- [ ] Pelo menos 5 sessões feitas, com pelo menos 2 ex-usuários do WebDiet.
- [ ] C1, C3, C6, C8 e C10 concluídos sem ajuda por pelo menos 4 de 5 participantes.
- [ ] Nenhuma falha de segurança clínica aberta (alerta de alergia que não apareceu, número errado, perda de dado).
- [ ] Fórmulas, faixas de atividade, fatores de Atwater, tolerância de 5% e textos de aviso conferidos pelo nutricionista responsável.
- [ ] Média de satisfação de pelo menos 4 nas perguntas 1 e 2.
- [ ] Lista das falhas encontradas registrada no Jira (uma tarefa por falha), com decisão de corrigir antes ou depois.

## 7. Decisão assinada

| | |
|---|---|
| Nutricionista responsável (nome e CRN) | |
| Data | |
| Decisão | ( ) liberar  ( ) liberar com restrições  ( ) não liberar |
| Restrições ou pendências | |
| Assinatura | |
| Responsável pelo produto (Maicon) | |
| Assinatura | |

## 8. O que já existe de forma automática

Os testes abaixo cobrem as regras do sistema, os cálculos e os alertas das 5 populações da NI2-523, mas não substituem o julgamento e a assinatura das sessões presenciais com nutricionistas reais.

- `bin/preparar_avaliacao_clinica_ni2_523.php`: prepara de forma idempotente a `"Clínica TESTE Avaliação"`, 5 contas `P1`–`P5`, 1 conta de recepção e os 5 pacientes TESTE com antropometria e anamnese finalizada.
- `docs/benchmark-webdiet/pacientes_teste_ni2_523.csv`: planilha oficial com 10 linhas fictícias TESTE (8 válidas e 2 com erro proposital: linha 4 sem nome e linha 7 com data `31/02/1990`).
- `tests/ni2_514_v42_clinical_scenarios_test.php` (25 verificações): executa de ponta a ponta todos os 11 cenários (`C1` a `C11`) e as 5 populações (adulto em redução de peso, gestante 2º trimestre com aviso obstétrico/IOM, paciente com alergia a amendoim bloqueado antes de publicar e liberado apenas com justificativa auditada, idoso 72 anos com aviso geriátrico/protéico, e adolescente atleta 15 anos com aviso hebiátrico inclusive em Cunningham), além de impressão legível, importação CSV `8 criadas / 2 recusadas / rollback dos 8` e isolamento de privacidade/RBAC.
- `tests/ni2_514_v42_phase2_http_test.php` (12 verificações) e `tests/ni2_514_v42_units_test.php` (15 verificações): cobrem contratos de unidades/medidas, edição inline de refeições e substituições, reimportação CSV após rollback 100% e fluxos HTTP da Fase 2.
- `docs/benchmark-webdiet/RELATORIO-AVALIACAO-CLINICA-NI2-523.md`: relatório consolidado de homologação técnica dos 11 cenários (`C1`–`C11`), conferência matemática das 4 equações energéticas e ficha pronta para coleta das 5 sessões humanas (Gate 4) e assinatura do nutricionista responsável (Gate 5).
