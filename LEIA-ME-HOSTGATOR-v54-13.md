# Clyo Nutri v54.13: NI2-624 (alergia histórica contraditória) e NI2-622 (perfil do paciente)

- **Pacote:** `clyonutri-v54-13-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão em produção.
- **Conteúdo:** traz tudo da v54.12, que ainda não foi instalada, e acrescenta estas duas histórias.
- **Banco:** nada novo nesta versão. O único SQL continua sendo o da v54.12 (migração 78); se ele já foi rodado, não há mais nada a rodar.

## NI2-624: alergia histórica contraditória exige revisão e continua protegendo
**O problema:** a anamnese v1 concluída registrava "amendoim" e a v2 concluída dizia "nega alergias". O sistema passava a tratar o paciente como sem alergia e **descartava o risco em silêncio**. O mesmo acontecia quando o cadastro do paciente registrava alergia e a anamnese a negava.

**A regra nova (conservadora):** uma alergia que aparece numa versão concluída anterior, ou no cadastro, e não aparece na vigente:
- **continua valendo** na trava de alérgenos do plano, das receitas, da lista de compras e da busca de alimentos;
- aparece no perfil como **"Alergia exige revisão clínica"**, dizendo qual versão registrou o quê e quando ("A anamnese v1 (20/10/2026) registrou 'Amendoim (urticária)'; a anamnese vigente não registra amendoim.");
- o selo do perfil mostra "Alergia em revisão: amendoim" em vez de "Nega alergias";
- o resumo clínico do plano ganha o alerta crítico `ALERGIA_REVISAO`.

**Como a revisão termina:**
- **A alergia continua:** registre-a de novo na anamnese. Botão "Confirmar na anamnese".
- **Foi descartada clinicamente:** o nutricionista registra o motivo, com no mínimo 5 letras.
  - Exemplo de motivo: "teste de provocação oral negativo".
  - O registro fica em `audit_logs` (`ALLERGY_HISTORY_DISMISSED`), com grupo, versão vigente, autor e motivo.
  - Se uma versão posterior voltar a registrar a alergia e outra a omitir, a revisão reabre.

**O que não muda:**
- **Nada é apagado.** As versões da anamnese ficam como estão.
- **Rascunho continua sem poder tirar alergia** (regra da NI2-605): ele não cria nem resolve contradição.
- **"Antes negava, agora registra"** é atualização normal, sem revisão.
- **Sem anamnese concluída,** o cadastro é a fonte principal, também sem revisão.

**Permissões:**
- Só nutricionista da clínica registra o descarte.
- Outra clínica não lê nem grava nada.

## NI2-622: perfil do paciente com uma ação principal e navegação única
**Cabeçalho:**
- Mostra nome, selo de alergias, idade, sexo e a situação do atendimento numa linha: "Próxima consulta: 04/11/2026 às 10:00", "Atendimento em andamento…" ou "Nenhuma consulta agendada".
- Telefone e e-mail saíram do topo; continuam em "Dados essenciais".

**Uma única ação principal, conforme o estado:**

| Estado | Ação |
|---|---|
| Sem consulta | **Novo atendimento** (abre a agenda já com o paciente) |
| Consulta futura | **Preparar consulta** (anamnese) |
| Consulta hoje | **Iniciar atendimento** |
| Em andamento | **Continuar atendimento** (volta à mesma consulta, com o rascunho preservado) |

**Mais ações** (menu): reagendar, ver na agenda, editar cadastro, chat, nova avaliação física, convite para o app.

**Abas:** Resumo · Atendimento · Plano · Anamnese · Evolução · Exames · Documentos.
- Substituem as duas fileiras antigas (botões + atalhos), que repetiam destinos.
- Ponto discreto quando há pendência: atendimento em andamento, plano em rascunho, alteração de anamnese em andamento, solicitação de exame a concluir. O leitor de tela lê o motivo.
- "Documentos" é uma seção nova do perfil: manipulados, prontuário para impressão, exportação e consentimentos.

**Outros ajustes:**
- O cartão "Próximo passo" foi absorvido pelo cabeçalho.
- O aviso de alergia em revisão fica logo abaixo do cabeçalho, com botões secundários, sem competir com a ação principal.
- **Celular:** a ação principal ocupa a linha, o menu abre dentro da tela e as abas rolam dentro da própria faixa, sem rolagem lateral na página.

**Ainda não feito:** as páginas internas (prontuário, anamnese, exames…) ainda não repetem o cabeçalho nem as abas. É o próximo passo para a navegação ficar igual em todo o caminho clínico.

## Arquivos
**Novos:**
- `src/Domain/Clinical/AllergyHistoryReview.php`
- `views/patients/_profile_header.php`

**Alterados:**
- `AllergenSafetyValidator.php`, `AnamnesisService.php`, `RecipeService.php`
- `DashboardController.php`: perfil, e a ação `dismissAllergyHistory`
- `public/index.php`: rota `POST /pacientes/{id}/alergias/revisao`
- `clinical-journeys.css`
- `views/layouts/professional-shell.php`: rodapé v54.13

## Como foi testado
- **Portão de release** (`tests/run_release_gate.sh`): **GO**.
  - 14 suítes PHP; a única falha é a já conhecida da v53.
  - 11 jornadas no navegador: v541 30/30, p607 14/14, p609 9/9, p610 31/31, p613c 8/8, p5410 25/25, p5411 32/32, p5412 15/15, p5413 15/15, p5414 18/18, tenant 15/15.
  - 0 violações de CSP e 0 erros de JavaScript.
- **NI2-624:** 17 testes de domínio, mais 15 verificações no navegador.
  - Domínio: contradição entre versões, alergia trocada, sem contradição, cadastro × anamnese, rascunho, descarte com motivo, reabertura, outra clínica.
  - Navegador: aviso, selo, alerta no resumo do plano, busca de alimentos, teclado, motivo curto recusado, descarte, outra clínica bloqueada, 375 px.
- **NI2-622:** 18 verificações no navegador: ação principal nos quatro estados, abas sem repetição, indicadores, menu pelo teclado, Documentos e larguras de 375, 656, 1280 e 1440 px.

## Instalação
1. Siga o LEIA-ME da v54.12. Se o SQL dela ainda não foi rodado, rode `database/manual/atualizacao-v54-12-ni2-635.sql`.
2. Confira o rodapé "v54.13".
3. Abra o perfil de um paciente **TESTE**: deve aparecer uma só ação principal e as sete abas.

## Rollback
Restaure os arquivos da v54.1. Os descartes registrados ficam em `audit_logs` e não atrapalham a versão antiga.
