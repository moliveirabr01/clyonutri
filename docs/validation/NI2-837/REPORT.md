# NI2-837 — execução em 14/11/2026

Resultado geral: PARCIAL. Épico e histórias não concluídos; nenhum PASS global.

## Ambiente e integridade
Codebase canônica: /Users/moliveira/Nutri_II. AGENTS.md lido. Escrita comprovada criando/lendo/removendo .codex-write-probe. Árvore inicialmente limpa. Base: 0b8d891066f73bdffdebfbfb8788314ee8fbad89; mudanças locais ainda sem commit. PHP 8.5.11, Node 26.7.0, Expo web e Chrome headless. API de teste iniciada com ./bin/serve-local.sh (CLYONUTRI_PORT=8098); Expo 8099 com EXPO_PUBLIC_API_URL=http://127.0.0.1:8098. SQLite isolado /tmp/ni2-95-ux837.sqlite, dados sintéticos, sem substituir banco, .env, uploads ou sessões existentes. A sessão de navegador dos testes é independente.

O NI2-837 atual é o épico de UX do paciente, não o agente de IA da conversa referenciada. Foram lidas integralmente suas seis histórias NI2-838 a NI2-843.

## Alterações reais
- NI2-838: mensagem contextual para conteúdo histórico incompleto; mantida recuperação no chat e recusa segura. A API já abria conteúdo completo corretamente. Acrescentados testes da rota explícita prescription_id usada pelo app. Há versões locais antigas sem ingredients/allergens; não foram reconstruídas a partir da receita atual nem alteradas no banco. A receita originalmente reportada continua dependendo de revisão profissional; não está restaurada por esta alteração.
- NI2-839: indicação derivada das preferências persistidas, aviso quando nenhum canal externo está habilitado, ação explícita para habilitar e-mail, explicação de silêncio e check-in já concluído. Nenhum canal ligado silenciosamente.
- NI2-840: troca de seção reinicia rolagem e foca título na web, anuncia seção no nativo; estado de rascunhos mantido na tela. Cancelar continua apenas fechando o editor.
- NI2-841: descrição real permanece; registros legados sem detalhes (incluindo texto genérico antigo) mostram a limitação, preservando data/hora/autoria e comentário. O registro conforme plano já persistia snapshot textual; validado sem inferir pelo plano atual.
- NI2-842: abrir receita usa botão comum do tema, saída secundária com alvo de 48 px, Atualizar sublinhado e identificável. Componentes e tokens existentes preservados.
- NI2-843: erro junto ao grupo obrigatório, aria-describedby, alert/live region, foco na primeira resposta, anúncio nativo e remoção do erro ao escolher adesão. Respostas opcionais preservadas.
- Fixtures de regressão ajustadas: consent_terms deixa de impor ID 1 que colidia com migrations; plano sintético da fixture de notificações usa autorização clínica explícita exigida pelo serviço atual. Nenhuma regra de produção foi relaxada.

## Testes executados
PASS — npm --prefix mobile run typecheck (evidence/typecheck-final.log).
PASS — node tests/patient_recipe_prescription_http_test.cjs: prescrição real via profissional, onboarding, lista, porções/ingredientes/preparo, rota explícita histórica completa, sessão inválida, prescription_id inexistente, snapshot incompleto recusado com 404/code prescription_content_unavailable e isolamento (evidence/recipes-final.log).
PASS — node tests/recipe_detail_render_http_test.cjs: seis receitas de exemplo renderizadas, sem diagnósticos PHP (evidence/recipe-render-regression.log).
PASS — tests/ni2_837_patient_ux_browser_test.cjs: check-in vazio sem escrita, erro/foco/observação preservada; falha real de conexão simulada por aborto da requisição e reenvio contra API real, uma linha persistida e recarga; seis seções em 1280/390/360 px, início/foco e ausência de overflow horizontal; aviso sem canal, habilitação explícita e recarga; consumo conforme plano persistido (evidence/browser-final.log).
PASS — tests/ni2_837_patient_api_test.cjs: autenticação, adesão inválida/ausente, consumo diferente, repetição idempotente, horário inválido, persistência e isolamento por paciente/consultório (evidence/patient-api.log).
PASS — tests/ni2_837_patient_ux_regression_test.cjs: consumo diferente e legado na UI, comentário preservado, erro de rede ao salvar preferências sem mudança falsa do switch; alvo de saída medido 48 px (evidence/browser-regression.log).
PASS — git diff --check; detector mecânico retornou [] (não equivale a aceite de acessibilidade).
FAIL — node tests/ni2_95_notifications_real_test.cjs: 61 passaram, 13 falharam. Falhas em configurações e avisos web do profissional (respostas 200/302/403 divergentes dos asserts); suíte inteira NÃO aprovada (evidence/notifications-http-rerun.log). Preparação inicial falhou por nova regra de adequação, depois corrigida na fixture. Log inicial preservado.

## Pendências e limites de aceite
NI2-838: BLOCKED para recuperar conteúdo histórico ausente: revisão/nova prescrição pelo nutricionista; não há fonte histórica completa para reconstrução automática segura. Conteúdo válido e negativas acima passaram.
NI2-839: BLOCKED entrega externa real: testes usam MAIL_DRIVER=log e push não disponível; não comprovam caixa de e-mail nem aparelho. Silêncio/agendador em suíte maior tem falhas; não há PASS global de entrega.
NI2-840: NOT TESTED sequência integral de cancelar consumo e voltar ao rascunho por teclado; validação web de início/foco das seis seções passou.
NI2-841: testes positivos/negativos e persistência acima passaram; nenhum dado histórico real foi alterado.
NI2-842: NOT TESTED zoom real 200%, medição completa de contraste e estados de todos os controles nas seis seções. Capturas de perfil inspecionadas em desktop e 390 px; 360 px testado automaticamente.
NI2-843: NOT TESTED leitor de tela real e app nativo (VoiceOver/TalkBack); sem declarar acessibilidade completa aprovada. Sem dados de paciente real nas evidências.
Regressão maior de notificações permanece FAIL (13 cenários). Sem deploy, release ou transição para Concluído.

## Reprodução
1. Preparar banco novo com DB_CONNECTION=sqlite DB_DATABASE=/tmp/ni2-95-<identificador>.sqlite php tests/support/ni2_95_fixture.php prepare.
2. Iniciar API nessa configuração com CLYONUTRI_PORT=8098 ./bin/serve-local.sh; iniciar mobile com EXPO_PUBLIC_API_URL=http://127.0.0.1:8098 CI=1 npx expo start --web --port 8099. O Expo tentou abrir Chrome via AppleScript e ficou aguardando; encerrar apenas esse subprocesso permitiu iniciar o bundler.
3. Com PLAYWRIGHT_MODULE apontando para playwright instalado, executar os testes browser, API e browser_regression nessa ordem, com DB_DATABASE do banco isolado. O teste browser requer check-in e preferências iniciais; para repetir, preparar outro banco. Os testes usam credenciais sintéticas da fixture.
4. Os dois testes de receitas criam e removem seus próprios bancos temporários.

Capturas: evidence/profile-1280.png, profile-390.png, profile-360.png, diary-390.png, reminder-390.png. Manifesto SHA-256: evidence/manifest.json. Os PASS se limitam estritamente aos cenários executados acima.
