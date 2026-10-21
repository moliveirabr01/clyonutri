# Code review: NI2-91, Perfil, consentimentos e privacidade (paciente)

- **Data:** 21/10/2026
- **Base revisada:** `~/Nutri_II`, branch `merge/v11-teleconsulta`, commit `72d703a`.
- **Status no Jira:** "Feito" (épico NI2-11). Não alterado.

**Escopo:**
- Aba "Perfil & Privacidade" do app (`mobile/src/screens/PatientHomeScreen.tsx`) e logout (`mobile/src/services/api.ts`).
- API do paciente (`public/index.php`, seção 4).
- Primeiro acesso (`PatientOnboardingService`).
- `src/Domain/Privacy/*` (`LgpdService`, `DataSubjectRequestService`, `ConsentManagementService`).
- Ações do profissional `patientPrivacyRequest` e `patientConsent` (`DashboardController`).

## Critérios da história

> Como paciente, quero gerenciar conta e direitos de privacidade.
> **Aceite:** campos permitidos; consentimentos; logout; solicitação de exportação/exclusão conforme LGPD; ações sensíveis protegidas.

## Resumo

O que a paciente tem hoje no app:
- uma aba que mostra nome e e-mail;
- um botão de sair;
- um texto sobre LGPD;
- um botão que preenche uma mensagem no chat.

Não há edição de dados, visão ou retirada de consentimento, nem pedido de exportação ou exclusão. A API do paciente não tem nenhuma rota para isso.

O fluxo de LGPD que existe é do lado do profissional, e tem defeitos:
- **exclusão:** o pedido é registrado, mas nenhuma tela ou rota o processa, então o prazo legal de 15 dias vence sem ação possível;
- **exportação:** o pacote omite chat, autorrelatos, pré-consulta e receitas;
- **anonimização:** deixa data de nascimento, gênero e hash de senha;
- **resultado do processamento:** o pedido fica "rejeitado" mesmo com o cadastro anonimizado;
- **primeiro acesso:** o cadastro da paciente no app é concluído sem apresentar nem registrar nenhum termo.

**Veredito: pedir alterações.** A NI2-91 não atende o aceite e não deveria estar em "Feito". Recomendo reabrir.

Os achados marcados **[prova]** foram reproduzidos com `tests/review_ni2_91_provas.php`:
- SQLite isolado com dados fictícios (fixture da NI2-89);
- cada linha "ACHADO" do script é um defeito confirmado no código atual.

Os demais vêm da leitura do código.

## Situação por critério

| Critério | Situação | Evidência |
|---|---|---|
| Campos permitidos | **Não atende** | A paciente só define nome, e-mail e telefone no primeiro acesso. Depois não há edição nem rota para isso [prova P1] |
| Consentimentos | **Não atende** | Não há tela nem rota para ver, dar ou retirar consentimento. O primeiro acesso não registra termo [prova P2]. Só o profissional registra ou retira, em nome da paciente |
| Logout | **Atende**, com ressalva | O servidor revoga o token (teste da NI2-89: 401 após logout). Ressalva em B1 |
| Exportação/exclusão LGPD | **Não atende** | Nada pelo lado da paciente [prova P1]. Pelo lado do profissional há defeitos: exclusão não processável [P4], exportação incompleta [P3], anonimização parcial [P7] |
| Ações sensíveis protegidas | **Não se aplica / parcial** | Não há ação sensível da paciente para proteger. No lado do profissional, a exportação baixa dados de saúde na hora, sem confirmação de identidade nem entrega ao titular [P8] |

## Problema crítico

| # | Arquivo | Problema |
|---|---|---|
| C1 | `public/index.php` (API do paciente), `PatientHomeScreen.tsx` 910–955 | **[prova P1] A funcionalidade da história não existe para a paciente.** Nenhuma das 20 rotas `/api/v1/patient/*` e `/api/v1/auth/patient-*` trata de perfil, consentimento ou LGPD. A aba "Perfil & Privacidade" é informativa: o "pedido" é uma mensagem pré-preenchida no chat, que não gera registro em `data_subject_requests`, não tem prazo nem protocolo. **Correção:** (1) `GET/PATCH /api/v1/patient/profile` com lista fechada de campos editáveis (ex.: telefone, e-mail com reconfirmação; nome e data de nascimento só pelo profissional); (2) `GET /api/v1/patient/consents` e `POST …/consents/{purpose}/revoke` usando `ConsentManagementService`; (3) `POST /api/v1/patient/privacy-requests` (export/deletion) que chama `openRequest` com canal "paciente" e devolve protocolo e prazo; (4) telas correspondentes no app; (5) testes HTTP positivos e negativos, incluindo IDOR. |

## Problemas altos

| # | Arquivo | Linha | Problema |
|---|---|---|---|
| A1 | `PatientOnboardingService.php` | 60–87 | **[prova P2] Primeiro acesso sem consentimento.** Com um termo `clinical_care` ativo, a paciente cria senha e entra no app sem ver o termo, e nenhum registro vai para `patient_consents`. O app trata dados de saúde (diário, evolução, chat). **Correção:** mostrar a versão vigente do termo no primeiro acesso, exigir a manifestação e gravar com `recordConsent(..., 'patient_app')`, ou registrar a base legal adotada, se a decisão jurídica for outra. |
| A2 | `DataSubjectRequestService.php` / `DashboardController.php` | 122 / 171–195 | **[prova P4] Pedido de exclusão nunca é processado.** `patientPrivacyRequest` abre o pedido `deletion` com prazo de 15 dias, mas `processDeletionWithLegalHold` não é chamado por nenhuma rota ou controlador. Ninguém consegue atender o pedido pelo sistema, e não há lista de pedidos pendentes nem alerta de prazo. **Correção:** tela de pedidos LGPD da clínica (pendentes, prazo, responsável), com ação de processar restrita ao dono/admin e auditoria. |
| A3 | `DataSubjectRequestService.php` | 138–158 | **[prova P6] O resultado contradiz o que foi feito.** Quando há prontuário, o pedido fica `rejected_legal_hold`, mas `anonymizePatient` já anonimizou o cadastro de forma irreversível. O titular recebe "rejeitado" e perde o acesso ao app ao mesmo tempo. **Correção:** separar os resultados (ex.: `completed_partial_legal_hold`) e registrar exatamente o que foi anonimizado e o que ficou sob guarda; confirmar com o jurídico o texto da resposta ao titular. |
| A4 | `LgpdService.php` | 26–71 | **[prova P3] Portabilidade incompleta.** O pacote não traz mensagens do chat, autorrelatos (`patient_self_metrics`), respostas da pré-consulta, receitas prescritas nem os pedidos LGPD anteriores. Também ficam de fora `meal_plan_versions` (o plano que a paciente efetivamente recebeu), `patient_documents`, `clinical_attachments`, `teleconsultation_sessions` e `health_sync_data`. **Correção:** inventário de tabelas com `patient_id` e decisão explícita (incluir ou excluir, com motivo) para cada uma; teste que falhe se surgir tabela nova com `patient_id` fora do inventário. |
| A5 | `DataSubjectRequestService.php` | 55–68 | **[prova P5] Abertura do pedido sem autor.** O `INSERT` direto em `audit_logs` grava `user_id = NULL` e `ip = NULL`. O processamento (linha ~165) faz o mesmo. **Correção:** usar `AuditLogger` (já usado no `LgpdService`) em todas as etapas do pedido. |

## Sugestões (médias e baixas)

| # | Arquivo | Linha | Sugestão | Categoria |
|---|---|---|---|---|
| M1 | `LgpdService.php` | 76–120 | **[prova P7]** A anonimização mantém `birth_date`, `gender`, `uuid` e `password_hash`. Data de nascimento com gênero e clínica é um quase-identificador. Limpar ou generalizar (ex.: só o ano), zerar credenciais e convites. Definir com o jurídico o tratamento de texto livre que pode conter nome (diário, chat, notas), hoje intocado. | Privacidade |
| M2 | `DashboardController.php` | 171–195 | **[prova P8]** A exportação pedida pelo profissional é marcada "concluída" e baixada na hora no navegador dele. O titular não recebe nada pelo sistema, não há confirmação de identidade e o arquivo com dados de saúde fica no computador do profissional. Gerar o pacote para entrega ao titular (link de uso único com expiração no app) e registrar a entrega. | LGPD / segurança |
| M3 | `PatientHomeScreen.tsx` / `api.ts` | 918–928 / 365–380 | Se a revogação no servidor falha (sem rede), a paciente não consegue sair: o app mantém a sessão e pede para tentar de novo. No portal web, o cookie vale 30 dias. Oferecer "Sair deste aparelho" (limpa a sessão local e o cookie) e reenviar a revogação depois. | Privacidade / UX |
| B1 | `PatientHomeScreen.tsx` | 937–953 | O texto diz que os direitos "podem ser solicitados diretamente ao nutricionista", mas o botão só abre o chat. Enquanto C1 não existir, deixar claro que a mensagem não é um pedido formal com prazo. | Texto |
| B2 | `DataSubjectRequestService.php` | 42 | O prazo é calculado com `date()` do processo: no script de prova saiu em UTC. Usar o fuso da aplicação, guardar o prazo em UTC explícito e exibir no fuso local. | Datas |
| B3 | testes | — | Nenhuma suíte cobre o lado da paciente desta história, e a da privacidade (`privacy_and_lgpd_governance_test`) testa os serviços isolados, não as rotas. Usar `review_ni2_91_provas.php` como base, invertendo as asserções depois das correções. | Testes |

## O que está bom

- **Retirada de consentimento pelo profissional:** exige finalidade, motivo e confirmação, com CSRF e permissão de prescritor.
- **Registro de consentimento:**
  - ligado à versão do termo;
  - recusa importação em massa como "aceite";
  - "não coletado" não grava aceite.
- **Exportação sem segredos:** não inclui hash de senha, PIN nem OTP (`array_intersect_key`), e registra auditoria via `AuditLogger`.
- **Anonimização:**
  - revoga os tokens do app na mesma transação;
  - bloqueia reativação;
  - o token deixa de valer em seguida (teste da NI2-89).
- **Logout:** revoga o token no servidor; com token já inválido, o app trata como sessão encerrada.

## Provas (`tests/review_ni2_91_provas.php`, commit `72d703a`, SQLite)

```
ACHADO P1: rotas da API do paciente para perfil, consentimento ou LGPD: nenhuma (de 20 rotas)
ACHADO P2: primeiro acesso concluído com termo ativo; consentimentos gravados para a paciente: 0
ACHADO P3: pacote de portabilidade não traz: mensagens do chat; autorrelatos (patient_self_metrics); pré-consulta (respostas); receitas prescritas; pedidos LGPD anteriores (data_subject_requests)
ACHADO P4: pedido de exclusão #1 aberto com prazo 2026-11-05 03:29:00; chamadas a processDeletionWithLegalHold em rotas/controladores: 0
ACHADO P5: auditoria da abertura do pedido: user_id=NULL, ip=NULL
ACHADO P6: status do pedido = rejected_legal_hold, mas o cadastro foi anonimizado (status do paciente = anonymized)
ACHADO P7: depois de anonimizar continuam gravados: birth_date, gender, password_hash (nome='PACIENTE ANONIMIZADO', e-mail=NULL)
ACHADO P8: pedido de exportação aberto pelo profissional é dado como concluído e baixado na hora pelo próprio profissional (sem envio ao titular)
```

## Ordem sugerida de correção

1. **Decisão jurídica e de produto.** Precisa vir antes de A1, A3 e M1:
   - base legal do app (consentimento ou outra);
   - campos que a paciente pode editar;
   - texto da resposta a pedidos com guarda legal;
   - tratamento de texto livre na anonimização.
2. **A2 + A5:** fila de pedidos LGPD da clínica, com processamento e autoria. Sem isso, nenhum pedido de exclusão é atendido.
3. **C1:** rotas e telas da paciente: perfil, consentimentos, pedidos.
4. **A1:** termo no primeiro acesso.
5. **A3, A4, M1, M2:** resultados corretos, portabilidade completa, anonimização e entrega ao titular.
6. **M3, B1–B3:** sair sem rede, textos, datas e testes.
