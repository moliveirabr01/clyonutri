# ClyoNutri II — Backlog de implementação UX/UI para NI2

Data de avaliação: **08/10/2026 (America/Sao_Paulo)**. Fontes: árvore de trabalho existente, navegação real em `127.0.0.1:8080` e instância isolada `localhost:8081`, banco SQLite novo com as 21 migrações. Nenhuma alteração de código da aplicação ou de registros no Jira.

## Importação futura

Projeto alvo: **NI2**. Estes identificadores PX são locais; não são issues Jira existentes. Nenhum registro foi criado ou alterado. Cada bloco abaixo está pronto para detalhamento/importação como Story ou Bug; estimativa depende de refinamento técnico e não é prazo prometido. P0 bloqueia jornada; P1 compromete significativamente uso; P2 reduz qualidade. Severidade técnica de segurança não é a mesma escala de prioridade de produto.

## Ordem de entrega

1. **Gate de integridade e acesso:** PX-01, 02, 03, 04, 05, 06, 16, 19, 20, 23, 24, 25, 26, 29; validar sem dependência de redesign.
2. **Fundação de experiência:** PX-07, 08, 09, 13, 18, 21; shell, formulários e estados compartilhados.
3. **Produtividade:** PX-10, 14, 15, 17, 27; workspace contextual, busca e edição.
4. **Operação e promessa:** PX-11, 12, 28. PX-22 acompanha todas as ondas e é gate de publicação.

P0 deve ser resolvido antes de anunciar disponibilidade do app paciente. P1 clínico, segurança, erros e recuperação são condições de lançamento comercial, mesmo quando classificados como severidade técnica média. Recursos comerciais ainda incompletos precisam de escopo e comunicação honestos; removê-los do menu sem explicação não resolve o finding.

## PX-01 — Cliente móvel não consegue autenticar com seu contrato atual

**Project:** NI2 · **Issue type:** Bug · **Prioridade:** P0 · **Labels sugeridas:** product-experience, audit-2026-10, px-01

**Contexto e problema:** Bloqueia entrada pelo cliente entregue; prejudica ativação e continuidade de tratamento. login(credentials) tipado somente com email/cpf; senha/PIN/OTP não fazem parte da interface pública.

**História:** Como paciente, preciso que cliente móvel não consegue autenticar com seu contrato atual seja resolvido para executar o fluxo de API / app paciente com clareza e segurança.

**Evidência:** [mobile/src/services/api.ts:165](/Users/moliveira/Nutri_II/mobile/src/services/api.ts:165); [public/index.php:265](/Users/moliveira/Nutri_II/public/index.php:265); [HTTP real](evidence/runtime-api-and-persistence.json): somente e-mail → 401; e-mail+senha → 200.

**Solução / orientação técnica:** Alinhar cliente e servidor em autenticação forte, com entrada e recuperação de credencial reais; jamais relaxar API para aceitar identificador.

**Critérios de aceite verificáveis:**

- Cliente permite credencial forte; paciente ativo entra, acessa plano vigente e encerra sessão; entrada inválida não gera token.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** E2E HTTP com cliente real compilado; senha/PIN/OTP conforme mecanismo escolhido; token expirado; logout; falha de rede.

**Dependências:** Definição de provisionamento e recuperação de credenciais.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-02 — Evolução falha após instalação limpa

**Project:** NI2 · **Issue type:** Bug · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-02 · **Status:** Resolvido

**Contexto e problema:** Impede consultar evolução; cliente oculta falha e pode mostrar ausência de avaliações. Serviço depende de tabela ausente nas migrações atuais.

**História:** Como paciente, preciso que evolução falha após instalação limpa seja resolvido para executar o fluxo de /api/v1/patient/evolution com clareza e segurança.

**Evidência:** [src/Domain/PatientPortal/PatientPortalService.php:236](/Users/moliveira/Nutri_II/src/Domain/PatientPortal/PatientPortalService.php:236); [resposta real](evidence/runtime-api-and-persistence.json): no such table: patient_self_metrics; 21 migrações aplicadas.

**Solução / orientação técnica:** Criar migração compatível e contrato de evolução; validar instalação limpa e upgrade.

**Critérios de aceite verificáveis:**

- Após migrar banco vazio, endpoint retorna JSON válido com listas vazias; registro de autorrelato persiste; sem DDL de teste fora das migrações.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Instalação limpa; upgrade; GET vazio e preenchido; POST self-metrics; tenant cruzado.

**Dependências:** Contrato de métricas e origem clínica/autorrelato.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-03 — Onboarding grava nascimento e sexo não informados

**Project:** NI2 · **Issue type:** Bug · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-03

**Contexto e problema:** Dados biográficos falsos podem contaminar idade, protocolos e confiança clínica; correção exige retrabalho. Data fixa no formulário e primeira opção selecionada; fallback female no serviço.

**História:** Como nutricionista e paciente, preciso que onboarding grava nascimento e sexo não informados seja resolvido para executar o fluxo de /onboarding?passo=4 com clareza e segurança.

**Evidência:** [src/Application/Controllers/OnboardingController.php:366](/Users/moliveira/Nutri_II/src/Application/Controllers/OnboardingController.php:366); [src/Domain/Onboarding/OnboardingService.php:220](/Users/moliveira/Nutri_II/src/Domain/Onboarding/OnboardingService.php:220); [persistência](evidence/persistence-final.json): 1995-04-10/male, sem edição desses campos.

**Solução / orientação técnica:** Iniciar campos vazios; distinguir sexo relevante ao cálculo de identidade de gênero; exigir somente onde necessário e explicar finalidade.

**Critérios de aceite verificáveis:**

- Nome isolado não grava data/sexo presumidos; campos opcionais persistem null/não informado; cálculo dependente exige confirmação.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Cadastro por onboarding e modal sem dados opcionais; datas futuras/inválidas; cálculo com informação ausente.

**Dependências:** Nenhuma.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-04 — Adesão fictícia de 94% em ausência de check-ins

**Project:** NI2 · **Issue type:** Bug · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-04

**Contexto e problema:** Cria confiança em desempenho inexistente e distorce prioridades de acompanhamento. Fallback ilustrativo em indicador clínico operacional.

**História:** Como nutricionista, preciso que adesão fictícia de 94% em ausência de check-ins seja resolvido para executar o fluxo de /dashboard com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:3249](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:3249); dashboard de tenant novo com zero pacientes também exibe 94.0%.

**Solução / orientação técnica:** Representar Sem dados; informar período, número de respostas e população elegível.

**Critérios de aceite verificáveis:**

- Zero check-ins → Sem dados; valores só derivam dos registros elegíveis; período e amostra visíveis.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Tenant vazio; todos null; poucos check-ins; limites de período; arredondamento; isolamento.

**Dependências:** Nenhuma.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-05 — Consultas do dia divergem entre indicador e tabela

**Project:** NI2 · **Issue type:** Bug · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-05

**Contexto e problema:** Profissional não sabe qual leitura representa sua agenda; perde confiança e pode ignorar atendimento. Fronteiras de dia em fusos diferentes: PHP America/Sao_Paulo versus data UTC do SQLite.

**História:** Como nutricionista, preciso que consultas do dia divergem entre indicador e tabela seja resolvido para executar o fluxo de /dashboard e /agenda com clareza e segurança.

**Evidência:** [src/Domain/Appointment/AppointmentService.php:588](/Users/moliveira/Nutri_II/src/Domain/Appointment/AppointmentService.php:588); listByDay recebe data PHP enquanto estatística usa CURRENT_DATE; observado 0 no indicador e 1 concluída na tabela em 08/10.

**Solução / orientação técnica:** Compartilhar intervalo temporal calculado no fuso do consultório em todas as consultas; evitar funções dependentes do fuso do banco.

**Critérios de aceite verificáveis:**

- Indicador, agenda e pendências concordam inclusive entre 21h e meia-noite de São Paulo.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Relógio controlado em virada UTC/local; SQLite e banco de produção; consulta agendada/concluída/cancelada.

**Dependências:** Nenhuma.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-06 — Visualizar versão histórica abre a versão mais recente

**Project:** NI2 · **Issue type:** Bug · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-06

**Contexto e problema:** Revisão clínica incorreta; profissional pensa consultar documento anterior e vê o atual. Parâmetros históricos emitidos por links não são consumidos como seleção da versão.

**História:** Como nutricionista, preciso que visualizar versão histórica abre a versão mais recente seja resolvido para executar o fluxo de /pacientes/{id}/anamnese com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:1376](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:1376); controller sempre getLatestAnamnesis; link view_version=1 observado mostra Última Versão v2; editor usa ainda parâmetro version=2.

**Solução / orientação técnica:** Contrato único para versão; leitura histórica explícita e somente leitura, com ação separada para nova revisão.

**Critérios de aceite verificáveis:**

- v1 e v2 diferentes exibem conteúdo correto, data/autor/estado; versão inexistente tem erro claro; links do plano preservam origem.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Duas versões com textos distintos; acesso por tabela e plano; comparação; tenant cruzado.

**Dependências:** Nenhuma.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-07 — Campos clínicos sem nome acessível associado

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-07

**Contexto e problema:** Leitor de tela não identifica campo; preenchimento clínico depende de contexto visual. Rótulos visuais sem for/id; sem associação semântica.

**História:** Como nutricionista, preciso que campos clínicos sem nome acessível associado seja resolvido para executar o fluxo de Anamnese e editores com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:1493](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:1493); inspeção DOM: dez campos de coleta e dois selects comparativos sem labels associados nem aria-label.

**Solução / orientação técnica:** Componente Field com label/id, instruções e erro descritos; agrupar seções com fieldset/legend quando aplicável.

**Critérios de aceite verificáveis:**

- Cada controle tem nome acessível único e erro associado; ordem de leitura acompanha tarefa.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Inspeção DOM automatizada; teclado e leitor de tela em anamnese, plano, receita e agenda.

**Dependências:** Componente de formulário.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-08 — Modais clínicos não gerenciam foco de modo consistente

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-08

**Contexto e problema:** Teclado percorre conteúdo de fundo; decisão crítica perde contexto e aumenta erro. Implementações locais alternam classList e style.display; sem primitiva comum.

**História:** Como nutricionista, preciso que modais clínicos não gerenciam foco de modo consistente seja resolvido para executar o fluxo de Perfil, prontuário e planos com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:247](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:247); ao abrir Novo Plano e +Alimento o foco permanece no acionador; sem role dialog/aria-modal no runtime inspecionado. Cadastro de paciente possui foco inicial, mas não prova contenção.

**Solução / orientação técnica:** Unificar diálogo com nome, foco inicial, contenção, Escape seguro, retorno ao acionador e fundo inerte.

**Critérios de aceite verificáveis:**

- Todos os diálogos seguem o mesmo contrato; fechar formulário alterado informa consequência; foco sempre retorna.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Tab/Shift+Tab/Escape; leitor de tela; scroll móvel; submissão inválida e fechamento com alteração.

**Dependências:** Primitiva de diálogo acessível.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-09 — Dashboard transborda e posterga tarefas no celular

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-09

**Contexto e problema:** Rolagem horizontal e grande distância até consultas/prioridades; rotina móvel ineficiente. Grid local fixo e navegação extensa em linhas; regra móvel não cobre toda composição.

**História:** Como nutricionista, preciso que dashboard transborda e posterga tarefas no celular seja resolvido para executar o fluxo de /dashboard a 390px com clareza e segurança.

**Evidência:** [Captura 390px](evidence/dashboard-390.jpg); medição DOM: viewport 390px, documento 461px. [src/Application/Controllers/DashboardController.php:1552](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:1552); shell ocupa cerca de 257px no topo da captura.

**Solução / orientação técnica:** Shell compacto com menu acessível; agenda primeiro; empilhar grid; manter tabelas com overflow local justificado.

**Critérios de aceite verificáveis:**

- Sem overflow global em 320/390/768/1024/1440; próxima ação acessível sem atravessar métricas; foco não encoberto.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Screenshots e DOM em larguras; 200% zoom; orientação; teclado virtual; tabela extensa.

**Dependências:** Shell responsivo.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-10 — Chat rompe identidade e não tem entrada funcional para iniciar contato

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-10

**Contexto e problema:** Comunicação fica escondida e fluxo termina sem próxima ação executável. Shell próprio com emoji, fonte/paleta locais; ligação entre perfil e conversa ausente na interface observada.

**História:** Como nutricionista, preciso que chat rompe identidade e não tem entrada funcional para iniciar contato seja resolvido para executar o fluxo de /chat e perfil do paciente com clareza e segurança.

**Evidência:** [src/Application/Controllers/ChatController.php:197](/Users/moliveira/Nutri_II/src/Application/Controllers/ChatController.php:197); [captura](evidence/chat-desktop.jpg); sidebar oficial não inclui Chat; vazio pede iniciar pela ficha, mas perfil inspecionado não oferece ação.

**Solução / orientação técnica:** Usar shell compartilhado e ação Mensagem no paciente que resolve/cria conversa com ownership; exibir canal na navegação.

**Critérios de aceite verificáveis:**

- De perfil sem conversa, ação abre thread correta; vazio tem CTA útil; identidade preservada.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Paciente novo/existente; criação idempotente; envio/retry; permissão; não enviar mensagens reais em homologação.

**Dependências:** Contrato de criação de conversa.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-11 — Configurações são somente texto declarativo

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-11

**Contexto e problema:** Não permite gerenciar consultório, equipe, privacidade ou conta; promete mais controle do que entrega. Tela implementada como resumo estático, confundindo governança com estado operacional.

**História:** Como nutricionista / dono da clínica, preciso que configurações são somente texto declarativo seja resolvido para executar o fluxo de /configuracoes com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:3204](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:3204); UI exibe perfil, códigos hex e LGPD Ativa com Auditoria Criptográfica; nenhum campo ou ação.

**Solução / orientação técnica:** Separar perfil, consultório, acesso/equipe, preferências e dados/privacidade; mostrar apenas funções implementadas e afirmações comprováveis.

**Critérios de aceite verificáveis:**

- Perfil e preferências editáveis com validação; matriz de permissões explícita; solicitações de dados têm canal rastreável; sem selo genérico de conformidade.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Salvar/reabrir; erros; RBAC; trilha; solicitações e estados pendentes.

**Dependências:** Escopo real de gestão de conta e revisão de governança.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-12 — Financeiro vazio não explica como começar

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P2 · **Labels sugeridas:** product-experience, audit-2026-10, px-12

**Contexto e problema:** Profissional não consegue converter atendimento em operação financeira pela tela; valor percebido inferior ao título. Listagem sem entrada operacional no runtime web.

**História:** Como nutricionista empreendedor, preciso que financeiro vazio não explica como começar seja resolvido para executar o fluxo de /financeiro com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:3143](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:3143); estado Nenhum recebível financeiro registrado sem CTA.

**Solução / orientação técnica:** Definir mínimo real de recebíveis e recibos ou informar limite funcional com próximo passo legítimo.

**Critérios de aceite verificáveis:**

- Vazio explica origem dos lançamentos; ação leva a fluxo real; status/valor/vencimento traduzidos; nenhuma simulação.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Primeiro lançamento, edição, baixa/reversão conforme escopo; moeda; permissões; vazio.

**Dependências:** Decisão do escopo financeiro; nenhum pagamento real nesta auditoria.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-13 — Jargão técnico e enums chegam ao usuário

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P2 · **Labels sugeridas:** product-experience, audit-2026-10, px-13

**Contexto e problema:** Eleva carga cognitiva, parece ambiente de desenvolvimento e enfraquece profissionalismo. Modelo de implementação usado diretamente como conteúdo de interface.

**História:** Como nutricionista e paciente, preciso que jargão técnico e enums chegam ao usuário seja resolvido para executar o fluxo de Dashboard, agenda, prontuário e perfil com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:1072](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:1072); first_visit/completed/male/published; NI2-64 e hashes em headings observados.

**Solução / orientação técnica:** Mapa central de rótulos; Registro preservado, autoria e data como linguagem principal; detalhes de integridade disponíveis sob demanda.

**Critérios de aceite verificáveis:**

- Nenhum enum/código de ticket em texto operacional; estados claros e consistentes em português.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Varredura de strings renderizadas; cada estado de agenda/plano; traduções e fallbacks.

**Dependências:** Nenhuma.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-14 — Gestão de pacientes não tem busca, filtros ou paginação visíveis

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-14

**Contexto e problema:** Escala com carteira extensa exige varredura manual e aumenta seleção errada. ListAll renderizado diretamente sem instrumentos de recuperação.

**História:** Como nutricionista, preciso que gestão de pacientes não tem busca, filtros ou paginação visíveis seja resolvido para executar o fluxo de /pacientes com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:3451](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:3451); lista inspecionada tem apenas Cadastrar Paciente e tabela.

**Solução / orientação técnica:** Busca por nome e identificador autorizado; filtros ativos/arquivados; paginação; preservação de consulta ao retornar.

**Critérios de aceite verificáveis:**

- Localizar paciente pelo nome; retorno mantém busca e posição; nomes iguais têm desambiguação adequada sem exposição excessiva.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** 100+ pacientes sintéticos; acentos; duplicados; nenhuma correspondência; tenant; teclado.

**Dependências:** Nenhuma.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-15 — Seleção alimentar não escala ao catálogo

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-15

**Contexto e problema:** Alimento fora dos primeiros 250 fica inacessível; prescrições repetitivas demandam varredura. Limite arbitrário, ausência de busca contextual e governança de fontes.

**História:** Como nutricionista, preciso que seleção alimentar não escala ao catálogo seja resolvido para executar o fluxo de /alimentos e /planos/{id} com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:2568](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:2568); editor usa select; catálogo é tabela sem busca; lista do ambiente existente apresenta nomes duplicados.

**Solução / orientação técnica:** Combobox com busca de servidor, origem/preparo/unidade, resultados paginados e identidade estável.

**Critérios de aceite verificáveis:**

- Item depois da posição 250 é localizável; duplicatas identificadas por fonte/preparo; teclado e ausência de resultados resolvidos.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Catálogo >250; acentos e sinônimos; lenta resposta; paginação; seleção por teclado.

**Dependências:** Catálogo com proveniência e índices.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-16 — Tabela confunde porção e referência nutricional

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-16

**Contexto e problema:** Leitura pode induzir quantidade/energia incorretas; reduz segurança da interpretação. Massa de porção concatenada com nome da medida; energia não escalada nem rotulada por 100g.

**História:** Como nutricionista, preciso que tabela confunde porção e referência nutricional seja resolvido para executar o fluxo de /alimentos com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:3626](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:3626); [DOM](evidence/foods-dom.txt): Aveia 30 colher de sopa /394 kcal; leite 200 ml /35 kcal; valores apresentados são referência do catálogo por 100g.

**Solução / orientação técnica:** Distinguir referência por 100g, massa/volume e medida caseira; calcular porção quando exibida; evitar conversão g/ml sem densidade válida.

**Critérios de aceite verificáveis:**

- Cada valor tem base explícita; medida caseira tem quantidade e equivalência coerentes; dados desconhecidos não inferidos.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Aveia/leite/azeite; 100g versus porção; densidade ausente; validação por nutricionista.

**Dependências:** Contrato de unidades e catálogo.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-17 — Editar item do plano exige remover e reinserir

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-17

**Contexto e problema:** Revisão frequente demanda retrabalho e risco de perder substituições ou observações. Rotas de inserção/remoção sem fluxo de atualização do item.

**História:** Como nutricionista, preciso que editar item do plano exige remover e reinserir seja resolvido para executar o fluxo de /planos/{id} com clareza e segurança.

**Evidência:** [public/index.php:172](/Users/moliveira/Nutri_II/public/index.php:172); editor observado mostra +Substituição e remover, sem editar quantidade/unidade/nota.

**Solução / orientação técnica:** Edição contextual de item do rascunho; publicado gera nova versão preservando linhagem.

**Critérios de aceite verificáveis:**

- Alterar porção conserva identificador/contexto/substituições e recalcula; versão publicada não muda retroativamente.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Editar porção e nota; falha de persistência; duas abas; rascunho versus publicado.

**Dependências:** Contrato de atualização e controle de versão.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-18 — Formulário clínico longo sem recuperação de alterações não salvas

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-18

**Contexto e problema:** Risco de perda de trabalho por sessão expirada, navegação e validação; impacto ainda não quantificado com usuários. Estado reside no DOM até POST; erros redirecionam sem repor payload na lógica revisada.

**História:** Como nutricionista, preciso que formulário clínico longo sem recuperação de alterações não salvas seja resolvido para executar o fluxo de Anamnese e evolução com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:1670](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:1670); submissão por formulário completo; inspeção não encontrou autosave/beforeunload/recuperação no fluxo.

**Solução / orientação técnica:** Rascunho persistido seguro, estado Salvo/Salvando/Falha, prevenção de saída e preservação do payload em erro.

**Critérios de aceite verificáveis:**

- Falha e sessão expirada preservam entrada sem vazamento; trocar paciente não mistura rascunhos; finalização é explícita.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Falha de rede, POST inválido, expiração, duas abas, retorno, usuário/tenant distintos.

**Dependências:** Modelo de rascunho e decisão de armazenamento protegido.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-19 — Ausência de anamnese parece ausência de alergias

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-19

**Contexto e problema:** Falsa tranquilidade ao prescrever; não prova prescrição danosa ou falha de detector. Estado desconhecido e ausência confirmada compartilham representação.

**História:** Como nutricionista, preciso que ausência de anamnese parece ausência de alergias seja resolvido para executar o fluxo de Editor alimentar com clareza e segurança.

**Evidência:** [src/Application/Controllers/DashboardController.php:2635](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:2635); paciente sem anamnese aparece ALERGIAS [Nenhuma relatada].

**Solução / orientação técnica:** Estados explícitos Não avaliado / Nega alergias / Alergia registrada; origem/data e revisão antes da publicação.

**Critérios de aceite verificáveis:**

- Sem coleta → Não avaliado; negativo somente com registro explícito; revisão aponta pendências sem inventar diagnóstico.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Sem anamnese; rascunho; negativa explícita; alergia positiva; atualização após publicação.

**Dependências:** Contrato estruturado de segurança clínica.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-20 — Meta hídrica exibida ao paciente diverge da coleta clínica

**Project:** NI2 · **Issue type:** Bug · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-20

**Contexto e problema:** Orientações conflitantes entre consultório e paciente; quebra continuidade de cuidado. Valor fixo no payload de home, sem referência ao plano/objetivo clínico.

**História:** Como paciente e nutricionista, preciso que meta hídrica exibida ao paciente diverge da coleta clínica seja resolvido para executar o fluxo de API home e anamnese com clareza e segurança.

**Evidência:** [public/index.php:357](/Users/moliveira/Nutri_II/public/index.php:357); [persistência](evidence/persistence-final.json): water_intake_ml=2000; [API](evidence/runtime-api-and-persistence.json): water_goal_ml=2500.

**Solução / orientação técnica:** Definir semântica de ingestão habitual versus meta prescrita e fonte única para meta; ausência é ausência.

**Critérios de aceite verificáveis:**

- Meta informada pelo profissional é a mesma na API/UI; se campo for ingestão habitual, não o rotular como meta; sem default clínico silencioso.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Meta ausente/alterada; plano vigente; unidades; zero/inválido; paciente/tenant.

**Dependências:** Decisão clínica sobre semântica do campo.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-21 — Tokens e variantes divergem da referência e entre módulos

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P2 · **Labels sugeridas:** product-experience, audit-2026-10, px-21

**Contexto e problema:** Ações e estados parecem de produtos distintos; manutenção de consistência fica cara. Referência com status Proposta, sem governança de aprovação; estilos locais dominam composição.

**História:** Como todos, preciso que tokens e variantes divergem da referência e entre módulos seja resolvido para executar o fluxo de Design System com clareza e segurança.

**Evidência:** [CLYONUTRI_DESIGN_SYSTEM_PREMIUM.md:1](/Users/moliveira/Nutri_II/CLYONUTRI_DESIGN_SYSTEM_PREMIUM.md:1); [public/assets/css/clinical-botanical.css:8](/Users/moliveira/Nutri_II/public/assets/css/clinical-botanical.css:8); referência #0F2747/#E67A3F versus CSS #0F2A44/#E67E3A; btn-outline e badge-danger sem definição no CSS compartilhado.

**Solução / orientação técnica:** Ratificar paleta com comparação documentada; aliases semânticos versionados; registrar variantes e remover divergências por migração.

**Critérios de aceite verificáveis:**

- Uma fonte de tokens/variantes por plataforma; todas classes usadas existem; diferenças intencionais documentadas.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Inventário de tokens/classes; regressão visual em estados e módulos; contraste.

**Dependências:** Ratificação da referência de marca.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-22 — Testes homologam mais do que efetivamente verificam

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-22

**Contexto e problema:** Go-live pode liberar quebra de instalação, login e mídia apesar de testes verdes. Fronteira testada diferente da integração implantada; nomes E2E ampliam interpretação.

**História:** Como equipe e usuários, preciso que testes homologam mais do que efetivamente verificam seja resolvido para executar o fluxo de Qualidade / teleconsulta / API com clareza e segurança.

**Evidência:** [tests/teleconsultation_online_clinic_e2e_test.php:301](/Users/moliveira/Nutri_II/tests/teleconsultation_online_clinic_e2e_test.php:301); log diz integração funcional com Daily comprovada usando classe simulada; teste mobile registra rotas próprias no harness.

**Solução / orientação técnica:** Separar domínio, contrato HTTP do entrypoint real e E2E de navegador/provedor; relatório mostra blocked/skipped sem sucesso substituto.

**Critérios de aceite verificáveis:**

- Pipeline de banco limpo cobre public/index.php; mídia só aprovada com provedor real e dois participantes; mocks não contam como homologação.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Banco limpo; API real; browser; provedor indisponível; run com credenciais sandbox.

**Dependências:** Ambiente de homologação e credenciais autorizadas.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-23 — Revogação de profissional não invalida sessão existente

**Project:** NI2 · **Issue type:** Bug · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-23

**Contexto e problema:** Profissional suspenso pode conservar acesso a dados e operações; confiança e controle de equipe comprometidos. Autorização reconstruída da sessão sem revalidar user/clinic_members.status.

**História:** Como clínica / pacientes, preciso que revogação de profissional não invalida sessão existente seja resolvido para executar o fluxo de Autorização web com clareza e segurança.

**Evidência:** [public/index.php:39](/Users/moliveira/Nutri_II/public/index.php:39); [public/index.php:111](/Users/moliveira/Nutri_II/public/index.php:111); revisão Codex Security: severidade técnica média.

**Solução / orientação técnica:** Revalidar identidade/vínculo/clínica e versionar ou revogar sessões ao alterar acesso.

**Critérios de aceite verificáveis:**

- Após suspensão/remover vínculo, próxima leitura e mutação falham 401/403; usuário permitido mantém sessão válida.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Dois usuários, vínculo suspenso, clínica inativa, sessão prévia, autorização concorrente.

**Dependências:** Política de sessão e RBAC.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-24 — Token de paciente permanece após inativação/anonimização

**Project:** NI2 · **Issue type:** Bug · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-24

**Contexto e problema:** Janela de acesso até expiração de 30 dias; estado de privacidade não corresponde ao acesso efetivo. Consulta Bearer não filtra status; transição de privacidade não revoga tokens.

**História:** Como paciente / clínica, preciso que token de paciente permanece após inativação/anonimização seja resolvido para executar o fluxo de Autorização API / LGPD com clareza e segurança.

**Evidência:** [public/index.php:231](/Users/moliveira/Nutri_II/public/index.php:231); [src/Domain/Privacy/LgpdService.php:69](/Users/moliveira/Nutri_II/src/Domain/Privacy/LgpdService.php:69); revisão técnica média.

**Solução / orientação técnica:** Revalidar status paciente/clínica e revogar tokens atomicamente na transição.

**Critérios de aceite verificáveis:**

- Paciente inativo/anonimizado não usa token anterior; falha transacional não deixa estado parcial.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Token antes/depois de inativação e anonimização; rollback; token expirado.

**Dependências:** Contrato de retenção e transições de privacidade.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-25 — Paciente pode falsificar recibo de leitura de outra conversa da clínica

**Project:** NI2 · **Issue type:** Bug · **Prioridade:** P2 · **Labels sugeridas:** product-experience, audit-2026-10, px-25

**Contexto e problema:** Mensagens parecem lidas por destinatário errado; prejudica acompanhamento e expectativa de resposta. UPDATE verifica clínica, mas não participante/readerId.

**História:** Como paciente e profissional, preciso que paciente pode falsificar recibo de leitura de outra conversa da clínica seja resolvido para executar o fluxo de API chat/read com clareza e segurança.

**Evidência:** [public/index.php:509](/Users/moliveira/Nutri_II/public/index.php:509); [src/Domain/Chat/ChatService.php:357](/Users/moliveira/Nutri_II/src/Domain/Chat/ChatService.php:357); revisão técnica baixa: sem leitura de conteúdo ou escape de tenant.

**Solução / orientação técnica:** Validar participante antes de atualizar recibos; identidade vem do token.

**Critérios de aceite verificáveis:**

- Paciente A não marca conversa de B no mesmo tenant; própria conversa funciona.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Dois pacientes na mesma clínica; outra clínica; ID inexistente; repetição idempotente.

**Dependências:** Ownership uniforme de conversa.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-26 — Falha de API devolve HTML com HTTP 200 e cliente a oculta

**Project:** NI2 · **Issue type:** Bug · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-26

**Contexto e problema:** Erro é interpretado como falta de dados; suporte perde sinal e usuário recebe feedback enganoso. Sem handler global de exceções; cliente ignora falhas de diário/evolução.

**História:** Como paciente e operação, preciso que falha de api devolve html com http 200 e cliente a oculta seja resolvido para executar o fluxo de Evolução / mobile com clareza e segurança.

**Evidência:** [mobile/src/screens/PatientHomeScreen.tsx:47](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:47); resposta evolution 200 com Fatal error e caminho interno em [evidência](evidence/runtime-api-and-persistence.json).

**Solução / orientação técnica:** Envelope JSON e status corretos, identificador de correlação, logs restritos; falhas parciais locais com repetir e data de atualização.

**Critérios de aceite verificáveis:**

- Exceção gera 5xx JSON sem stack; app distingue vazio, desatualizado, offline e falha parcial.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Falha SQL/rede; JSON inválido; 401; diário falha com home válida; leitor de tela.

**Dependências:** Middleware de erros e estados por recurso.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-27 — Biblioteca não oferece edição de receita no runtime web

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P2 · **Labels sugeridas:** product-experience, audit-2026-10, px-27

**Contexto e problema:** Corrigir preparo/ingrediente exige duplicação ou caminho inexistente; biblioteca se fragmenta. Serviço e versões não se convertem em jornada de manutenção pela interface.

**História:** Como nutricionista, preciso que biblioteca não oferece edição de receita no runtime web seja resolvido para executar o fluxo de /receitas com clareza e segurança.

**Evidência:** [public/index.php:214](/Users/moliveira/Nutri_II/public/index.php:214); rotas atuais: lista/criar/ver/prescrever/duplicar, sem editar; receita nova usa selects sem busca.

**Solução / orientação técnica:** Edição versionada contextual, preservando receitas já prescritas; busca de ingredientes compartilhada.

**Critérios de aceite verificáveis:**

- Editar receita privada gera versão auditada; prescrição anterior mantém snapshot; receita sem acesso não é alterada.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Criar/editar/duplicar/prescrever; alergênicos e rendimento; receita pública e privada.

**Dependências:** Decisão de versionamento e componente de alimento.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-28 — Ajuda descreve recursos e entradas não disponíveis no fluxo observado

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P2 · **Labels sugeridas:** product-experience, audit-2026-10, px-28

**Contexto e problema:** Usuário procura funções sem caminho real; suporte vira compensação de descoberta incompleta. Conteúdo editorial desacoplado do inventário de capacidades publicado.

**História:** Como nutricionista, preciso que ajuda descreve recursos e entradas não disponíveis no fluxo observado seja resolvido para executar o fluxo de /ajuda com clareza e segurança.

**Evidência:** [src/Domain/Helpdesk/KnowledgeBaseService.php:1](/Users/moliveira/Nutri_II/src/Domain/Helpdesk/KnowledgeBaseService.php:1); categorias citam recuperação, convites, assinatura e personalização; várias com zero artigos; ficha não oferece convite.

**Solução / orientação técnica:** Inventário de capacidades para ajuda contextual; artigos com passos executáveis e limites por versão.

**Critérios de aceite verificáveis:**

- Cada ação documentada corresponde a rota/UI disponível; categoria vazia tem alternativa útil; beta explícito.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Percorrer artigos por tarefas e papel; links; pesquisa sem resultado; revisão por versão.

**Dependências:** Inventário runtime.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

## PX-29 — Recuperação de acesso depende de e-mail ao suporte

**Project:** NI2 · **Issue type:** Story · **Prioridade:** P1 · **Labels sugeridas:** product-experience, audit-2026-10, px-29

**Contexto e problema:** Bloqueio de trabalho depende de atendimento humano e prazo desconhecido. password_resets existe como tabela sem jornada HTTP operacional identificada.

**História:** Como nutricionista, preciso que recuperação de acesso depende de e-mail ao suporte seja resolvido para executar o fluxo de /login com clareza e segurança.

**Evidência:** [views/auth/login.php:100](/Users/moliveira/Nutri_II/views/auth/login.php:100); login inspecionado oferece Ajuda com acesso por mailto; nenhuma rota de recuperação no inventário runtime.

**Solução / orientação técnica:** Recuperação segura por token expirável, com mensagens não enumeráveis e retorno ao login; suporte é alternativa.

**Critérios de aceite verificáveis:**

- Usuário com acesso ao canal cadastrado redefine credencial; token único/expirado não funciona; sessões antigas tratadas conforme política.
- Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados.
- Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha.
- Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados.

**Cenários de teste:** Solicitação, reenvio, token consumido, expiração, abuso, senha inválida; sem enviar e-mails reais nesta auditoria.

**Dependências:** Serviço de e-mail homologado e política de credenciais.

**Evidência de conclusão exigida:** resultado do teste na fronteira real afetada, captura quando houver interface, verificação de persistência quando houver mutação, e vínculo com finding. Não encerrar pela presença de código ou por teste de domínio de outra fronteira.

