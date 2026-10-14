# NI2-5 — Correções da revisão

Data: 2026-10-09. Base: 2ad82284408685f010051a439451fba32ef62458 + alterações locais não commitadas da NI2-143.

## Implementação

- Cadastro/edição/status: auditoria transacional, autor, campos alterados sem valores clínicos e atualização de updated_at. Falha de auditoria impede persistência da operação.
- Duplicidade: e-mail normalizado ou celular sem formatação na mesma clínica; direcionamento ao cadastro existente. Homônimos e contatos em clínicas diferentes são permitidos. Cadastros legados duplicados continuam editáveis quando os contatos não mudam. Contatos compartilhados precisam ser corrigidos/diferenciados; não há autorização para ignorar o alerta.
- Ativação/inativação: formulário dedicado com confirmação e CSRF. Inativação revoga tokens do paciente e convites pendentes, preservando histórico. Cadastro anonimizado não pode ser reativado.
- Lista: busca no servidor por nome (sem diferenciação de acentos), e-mail e celular; filtro de status; 25 pacientes por página; navegação mantém filtros; estados vazio, erro e envio. Aniversariantes independem dos filtros e da página.
- Perfil 360: consultas, check-ins, recebíveis/pagamentos e linha do tempo junto às informações clínicas existentes. Seções operacionais limitadas a 20 registros; timeline até 50 eventos. Permissões clínicas e tenant da sessão são exigidos na entrada.
- Privacidade: versão/data/origem e retirada de consentimento com motivo e confirmação; revogação auditada atomicamente. Exportação JSON sob demanda inclui dados clínicos/operacionais e exclui credenciais do cadastro por lista permitida de campos. Exclusão registra solicitação pendente para revisão, sem destruir prontuários. Após cadastro, CTA para iniciar anamnese.

## Verificação

PASS:

- `php tests/ni2_5_patient_management_test.php`: SQLite em memória, migrações reais, auditoria/rollback, duplicidade/homônimos/tenant, status/tokens/convites/histórico, retirada atômica, exportação sem credenciais, solicitação de exclusão, agregação/timeline, busca/filtros/paginação com 5.000 pacientes sintéticos. Consulta de volume e verificações finais ~131 ms na execução local (não é benchmark de produção).
- `node tests/ni2_5_patient_management_http_test.cjs`: servidor PHP real com banco/sessões temporários; renderização, filtros, cadastro/CTA, edição, recuperação de duplicidade, status/confirmacão/CSRF, negação entre clínicas, retirada, exportação, pedido de exclusão e negação ao recepcionista.
- `php tests/patient_edit_test.php`: persistência e isolamento da edição.
- `php tests/cross_tenant_offensive_test.php`: 20 tentativas bloqueadas.
- `node tests/ni2_143_authorization_http_test.cjs`: regressão da autorização Web/API.
- `php tests/privacy_and_lgpd_governance_test.php`: regressão da suíte existente de privacidade.
- Verificação de sintaxe PHP nos arquivos modificados e `git diff --check`.

Os bancos usados nesta rodada foram em memória ou temporários; o banco local real não foi usado pelos testes. Nenhum commit, publicação ou alteração de status para Feito realizada. Pendente: homologação visual, MySQL e aprovação dos critérios de aceite em ambiente de entrega. O workflow de revisão/atendimento de solicitações de exclusão permanece sob responsabilidade do fluxo de privacidade existente; esta mudança registra e mostra pedidos, não automatiza a eliminação.
