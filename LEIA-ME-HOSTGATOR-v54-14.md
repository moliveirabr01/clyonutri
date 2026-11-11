# Clyo Nutri v54.14: NI2-661, NI2-662, NI2-623 e NI2-629

- **Pacote:** `clyonutri-v54-14-hostgator.zip`
- **Data:** 03/11/2026
- **Base:** v54.13, a versão em produção desde 03/11/2026.
- **Banco:** sem mudança. **Não há SQL para rodar.**

## NI2-661: o mesmo cabeçalho do paciente em todas as telas clínicas
O cabeçalho da v54.13 aparece agora em todas as telas do paciente. Ele traz o nome, o selo de alergias, a situação do atendimento, **uma** ação principal, "Mais ações" e as 7 abas.

| Tela | Aba marcada |
|---|---|
| Prontuário | Atendimento |
| Anamnese | Anamnese |
| Evolução antropométrica | Evolução |
| Lista de exames, solicitação e nova solicitação | Exames |
| Plano alimentar com paciente | Plano |

- O título de cada tela vira subtítulo, abaixo do nome do paciente.
- Saíram os links que repetiam as abas: "← Voltar ao perfil", "Ver anamnese →" no prontuário e "Prontuário →" na anamnese.
- Paciente de outra clínica não mostra cabeçalho nem dado nenhum.
- O cabeçalho é montado num só lugar (`src/Application/View/PatientHeader.php`). Se der erro, a página abre sem ele em vez de quebrar.

## NI2-623: etapas do atendimento conferidas no banco
Com atendimento em andamento, aparece abaixo das abas a faixa **Anamnese → Avaliação → Plano → Revisão**.
- **Onde aparece:** perfil, telas internas, plano e na própria tela da consulta.
- **Estado de cada etapa:** Feito, Em andamento, Revisar ou A fazer, conferido no que foi **gravado**. "Neste atendimento" quer dizer gravado a partir do dia da consulta.
- **Ordem:** é só sugestão. Toda etapa é um link e destaca "Próximo: …", a primeira ainda não feita.
- **Exemplos de detalhe:** "Rascunho v2 gravado em 03/11", "Vigente: v1 de 20/10", "Plano em edição (rascunho)", "Revisar e publicar o rascunho".
- **Recarregar a página** mostra o estado que de fato ficou salvo.
- **As travas clínicas da publicação** (adequação, alergias) continuam iguais.

## NI2-629: contexto clínico sem trocar de página
**"Contexto clínico"** fica recolhido abaixo das abas, para proteger a privacidade em tela pequena ou compartilhada. Abre com clique ou teclado e mostra:
- **Alergias:** a avaliação, a origem (anamnese vN, data e autor) e o aviso de revisão da NI2-624.
- **Restrições, metas e objetivos.**
- **Anamnese vigente:** só a concluída. O rascunho aparece como "Alteração em andamento (vN): ainda não vale".
- **Última avaliação física:** data, peso e IMC.
- **Consulta:** em andamento, hoje ou próxima, com link.

Dado ausente aparece como "Não registrado", nunca omitido.

**Onde aparece:** perfil, telas internas, plano, exames e tela da consulta. Na tela da consulta aparecem só as etapas e o contexto, sem repetir as abas.

**Exames:** a solicitação continua mostrando o paciente e o atendimento de origem antes de "Revisar e emitir PDF".

## NI2-662: portão de release cobre mais três jornadas (fora do zip)
O cenário `p5416`:
- **Antropometria pelo perfil,** com profissional e paciente de mesmo ID (caso do paciente 9448): grava só no paciente certo, e um envio com o paciente trocado é recusado.
- **Exames de ponta a ponta:** rascunho → emissão, com número, paciente e hash do PDF conferidos no banco, e o PDF baixando.
- **Portal do paciente:**
  - a paciente TESTE entra e vê o plano **publicado**;
  - não vê rascunho nem dado de outro paciente;
  - não consegue apagar registro de outro paciente;
  - sem login, a API recusa.

**Também nesta versão:** os testes de agenda passaram a usar a data do próprio seed, em vez de uma data fixa.

## Arquivos
**Novos:**
- `src/Application/View/PatientHeader.php`
- `src/Domain/Appointment/CareWorkflow.php`
- `views/patients/_allergy_badge.php`, `_care_workflow.php`, `_clinical_context.php`

**Alterados:**
- `DashboardController.php`: perfil, prontuário, anamnese, evolução, plano e consulta.
- `views/patients/exams.php`, `exam_show.php`, `exam_new.php`, `_profile_header.php`
- `clinical-journeys.css`
- `views/layouts/professional-shell.php`: rodapé v54.14

## Como foi testado
- **Portão de release: GO.**
  - PHP: 15 suítes, com a falha conhecida da v53.
  - Navegador: 14 jornadas.
    - Já existentes: v541 30/30, p607 14/14, p609 9/9, p610 31/31, p613c 8/8, p5410 25/25, p5411 32/32, p5412 15/15, p5413 15/15, p5414 18/18, tenant 15/15.
    - Novas: p5415 16/16, p5416 14/14, p5417 23/23.
  - 0 violações de CSP e 0 erros de JavaScript.
- **p5415 (NI2-661):** cinco telas com cabeçalho, 7 abas, a aba certa marcada, 1 ação principal e 1 título principal; troca de aba; 375, 656, 1280 e 1440 px; outra clínica.
- **p5417 (NI2-623/629):**
  - etapas antes e depois de concluir a anamnese e gravar a avaliação, com o próximo passo mudando;
  - contexto recolhido por padrão e aberto pelo teclado;
  - alergia com origem, "Não registrado", última avaliação e rascunho marcado como "ainda não vale";
  - 375, 656 e 1440 px.

## Instalação
1. Suba os arquivos como nas versões anteriores. Não há SQL.
2. Confira o rodapé "v54.14".
3. Abra um paciente **TESTE**: o cabeçalho e as abas devem aparecer também em Anamnese, Evolução, Exames e no plano.

## Rollback
Restaure os arquivos da v54.13. Não há mudança de banco.
