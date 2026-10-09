# ClyoNutri II — Findings de jornadas UX

Data de avaliação: **08/10/2026 (America/Sao_Paulo)**. Fontes: árvore de trabalho existente, navegação real em `127.0.0.1:8080` e instância isolada `localhost:8081`, banco SQLite novo com as 21 migrações. Nenhuma alteração de código da aplicação ou de registros no Jira.

## Método e limites

Confirmado significa observado na interface/API/persistência ou rastreado na fonte, conforme evidência individual. Risco fundamentado não é incidente comprovado. Dados do paciente de auditoria são fixtures explícitas de teste em banco separado; não representam pacientes nem resultados clínicos. Nenhum dado clínico real foi criado ou alterado na instância original. As consultas GET da aplicação podem produzir efeitos internos existentes (por exemplo, progresso de onboarding e catálogo).

## Jornadas verificadas

| Jornada | Execução | Resultado e limite |
|---|---|---|
| Login profissional | Navegador, credencial isolada | Entra e mantém sessão; recuperação não é autosserviço |
| Onboarding cinco passos | Navegador, POSTs reais | Concluído; paciente salvo com biografia default indevida |
| Cadastro comum | Modal original inspecionado, sem gravar | Nome obrigatório, opcionais e foco inicial; validação de persistência coberta pelo onboarding e testes, não pelo modal |
| Anamnese | Navegador: rascunho → finalização → histórico | v1 e v2 persistidas; leitura histórica selecionada falha |
| Plano | Perfil → criar → refeição → alimento → revisar → publicar | Versão publicada chega à API real do paciente; atualização/reversão não exercidas em browser |
| Consulta/evolução | Agendar → confirmar → iniciar → concluir → perfil | Agenda completed e registro clínico persistidos |
| Antropometria | Tela, formulário e vazio; testes de domínio no atendimento | Séries preenchidas e compartilhamento não exercidos em browser |
| Receitas | Lista, vazio, filtros e formulário; suíte domínio | Criação/prescrição persistem na suíte; jornada web de receita preenchida não homologada |
| Paciente | HTTP real: login forte → home/plano → água/diário → evolução → logout | Home/água/diário/logout funcionam; evolução falha; app nativo não executado |
| Chat | Lista/vazio; revisão estática | Iniciar pelo perfil não está disponível; não enviadas mensagens a pessoas |
| Teleconsulta | Rotas/fonte e teste de estados | Provedor real, câmera/microfone, reconexão e dois participantes não homologados |
| Ajuda/financeiro/configurações | Navegação real | Telas auditadas, sem abrir chamados ou movimentar valores |

## Interações observadas e fluxo recomendado

Contagem manual a partir das ações registradas; clique de confirmação e submissão conta uma interação, preencher campo conta uma interação, navegação direta de auditoria fica fora. Tempo humano não foi cronometrado: duração entre ferramentas inclui inspeção e não representa produtividade de um nutricionista.

| Tarefa / origem | Fluxo atual observado | Contagem | Fluxo recomendado e ganho a validar |
|---|---|---:|---|
| Criar plano, perfil já aberto | Novo Plano → título → Abrir Editor → Adicionar Refeição → nome → hora → Salvar → Alimento → selecionar → quantidade → Adicionar → Revisar → Confirmar | 13 | Editor mantém paciente e permite refeição/item em sequência, teclado e revisão com pendências; medir redução sem retirar confirmação clínica |
| Anamnese, tela já aberta | Preencher objetivo/alergias/restrições → salvar rascunho → finalizar | 5 | Seções clínicas com progresso, rascunho seguro e revisão antes de finalizar; reduzir risco de perda e versões desnecessárias |
| Consulta, agenda aberta | Nova Consulta → paciente → Confirmar Agendamento → Confirmar → Iniciar → anotar → Concluir | 7 | Data e contexto corretos; rótulos traduzidos; evolução com rascunho e resumo de conclusão |
| Consultar v1 da anamnese | Visualizar v1 → tela permanece v2 | 1, sem sucesso | Histórico somente leitura da versão escolhida, com volta preservada |
| Enviar orientação sem conversa | Chat vazio → instrução para ficha → ficha sem ação | Bloqueado | Mensagem no paciente abre conversa autorizada e contextual |

## Matriz detalhada

### PX-01 — Cliente móvel não consegue autenticar com seu contrato atual

- **Rota/módulo:** API / app paciente.
- **Perfil impactado:** Paciente.
- **Descrição:** Cliente móvel não consegue autenticar com seu contrato atual.
- **Evidência verificável:** [mobile/src/services/api.ts:165](/Users/moliveira/Nutri_II/mobile/src/services/api.ts:165); [public/index.php:265](/Users/moliveira/Nutri_II/public/index.php:265); [HTTP real](evidence/runtime-api-and-persistence.json): somente e-mail → 401; e-mail+senha → 200.
- **Estado da evidência:** Confirmado.
- **Causa provável:** login(credentials) tipado somente com email/cpf; senha/PIN/OTP não fazem parte da interface pública.
- **Severidade de produto:** P0.
- **Impacto na experiência:** Bloqueia entrada pelo cliente entregue; prejudica ativação e continuidade de tratamento.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Alinhar cliente e servidor em autenticação forte, com entrada e recuperação de credencial reais; jamais relaxar API para aceitar identificador.
- **Critérios de aceite:** Cliente permite credencial forte; paciente ativo entra, acessa plano vigente e encerra sessão; entrada inválida não gera token.
- **Dependências:** Definição de provisionamento e recuperação de credenciais.
- **Testes necessários:** E2E HTTP com cliente real compilado; senha/PIN/OTP conforme mecanismo escolhido; token expirado; logout; falha de rede.

### PX-02 — Evolução falha após instalação limpa

- **Rota/módulo:** /api/v1/patient/evolution.
- **Perfil impactado:** Paciente.
- **Descrição:** Evolução falha após instalação limpa.
- **Evidência verificável:** [src/Domain/PatientPortal/PatientPortalService.php:236](/Users/moliveira/Nutri_II/src/Domain/PatientPortal/PatientPortalService.php:236); [resposta real](evidence/runtime-api-and-persistence.json): no such table: patient_self_metrics; 21 migrações aplicadas.
- **Estado da evidência:** Resolvido (Migração 22 criada e validada em instalação limpa e upgrade via tests/px_02_patient_evolution_and_clean_install_test.php).
- **Causa provável:** Serviço depende de tabela ausente nas migrações atuais.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Impede consultar evolução; cliente oculta falha e pode mostrar ausência de avaliações.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Criar migração compatível e contrato de evolução; validar instalação limpa e upgrade.
- **Critérios de aceite:** Após migrar banco vazio, endpoint retorna JSON válido com listas vazias; registro de autorrelato persiste; sem DDL de teste fora das migrações.
- **Dependências:** Contrato de métricas e origem clínica/autorrelato.
- **Testes necessários:** Instalação limpa; upgrade; GET vazio e preenchido; POST self-metrics; tenant cruzado.

### PX-03 — Onboarding grava nascimento e sexo não informados

- **Rota/módulo:** /onboarding?passo=4.
- **Perfil impactado:** Nutricionista e paciente.
- **Descrição:** Onboarding grava nascimento e sexo não informados.
- **Evidência verificável:** [src/Application/Controllers/OnboardingController.php:366](/Users/moliveira/Nutri_II/src/Application/Controllers/OnboardingController.php:366); [src/Domain/Onboarding/OnboardingService.php:220](/Users/moliveira/Nutri_II/src/Domain/Onboarding/OnboardingService.php:220); [persistência](evidence/persistence-final.json): 1995-04-10/male, sem edição desses campos.
- **Estado da evidência:** Resolvido (NI2-296) — Eliminação de campos fixos e fallbacks sintéticos; campos iniciam vazios com opção em branco 'Não informado (opcional)' e atributo max; validação restritiva impede datas futuras ou malformadas; persistência estrita de NULL no banco relacional comprovada na suíte tests/px_03_onboarding_birthdate_gender_optional_test.php (7/7 gates).
- **Causa provável:** Data fixa no formulário e primeira opção selecionada; fallback female no serviço.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Dados biográficos falsos podem contaminar idade, protocolos e confiança clínica; correção exige retrabalho.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Iniciar campos vazios; distinguir sexo relevante ao cálculo de identidade de gênero; exigir somente onde necessário e explicar finalidade.
- **Critérios de aceite:** Nome isolado não grava data/sexo presumidos; campos opcionais persistem null/não informado; cálculo dependente exige confirmação.
- **Dependências:** Nenhuma.
- **Testes necessários:** Cadastro por onboarding e modal sem dados opcionais; datas futuras/inválidas; cálculo com informação ausente.

### PX-04 — Adesão fictícia de 94% em ausência de check-ins

- **Rota/módulo:** /dashboard.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Adesão fictícia de 94% em ausência de check-ins.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:3249](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:3249); dashboard de tenant novo com zero pacientes também exibe 94.0%.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Fallback ilustrativo em indicador clínico operacional.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Cria confiança em desempenho inexistente e distorce prioridades de acompanhamento.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Representar Sem dados; informar período, número de respostas e população elegível.
- **Critérios de aceite:** Zero check-ins → Sem dados; valores só derivam dos registros elegíveis; período e amostra visíveis.
- **Dependências:** Nenhuma.
- **Testes necessários:** Tenant vazio; todos null; poucos check-ins; limites de período; arredondamento; isolamento.

### PX-05 — Consultas do dia divergem entre indicador e tabela

- **Rota/módulo:** /dashboard e /agenda.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Consultas do dia divergem entre indicador e tabela.
- **Evidência verificável:** [src/Domain/Appointment/AppointmentService.php:588](/Users/moliveira/Nutri_II/src/Domain/Appointment/AppointmentService.php:588); listByDay recebe data PHP enquanto estatística usa CURRENT_DATE; observado 0 no indicador e 1 concluída na tabela em 08/10.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Fronteiras de dia em fusos diferentes: PHP America/Sao_Paulo versus data UTC do SQLite.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Profissional não sabe qual leitura representa sua agenda; perde confiança e pode ignorar atendimento.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Compartilhar intervalo temporal calculado no fuso do consultório em todas as consultas; evitar funções dependentes do fuso do banco.
- **Critérios de aceite:** Indicador, agenda e pendências concordam inclusive entre 21h e meia-noite de São Paulo.
- **Dependências:** Nenhuma.
- **Testes necessários:** Relógio controlado em virada UTC/local; SQLite e banco de produção; consulta agendada/concluída/cancelada.

### PX-06 — Visualizar versão histórica abre a versão mais recente

- **Rota/módulo:** /pacientes/{id}/anamnese.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Visualizar versão histórica abre a versão mais recente.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:1376](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:1376); controller sempre getLatestAnamnesis; link view_version=1 observado mostra Última Versão v2; editor usa ainda parâmetro version=2.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Parâmetros históricos emitidos por links não são consumidos como seleção da versão.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Revisão clínica incorreta; profissional pensa consultar documento anterior e vê o atual.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Contrato único para versão; leitura histórica explícita e somente leitura, com ação separada para nova revisão.
- **Critérios de aceite:** v1 e v2 diferentes exibem conteúdo correto, data/autor/estado; versão inexistente tem erro claro; links do plano preservam origem.
- **Dependências:** Nenhuma.
- **Testes necessários:** Duas versões com textos distintos; acesso por tabela e plano; comparação; tenant cruzado.

### PX-07 — Campos clínicos sem nome acessível associado

- **Rota/módulo:** Anamnese e editores.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Campos clínicos sem nome acessível associado.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:1493](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:1493); inspeção DOM: dez campos de coleta e dois selects comparativos sem labels associados nem aria-label.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Rótulos visuais sem for/id; sem associação semântica.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Leitor de tela não identifica campo; preenchimento clínico depende de contexto visual.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Componente Field com label/id, instruções e erro descritos; agrupar seções com fieldset/legend quando aplicável.
- **Critérios de aceite:** Cada controle tem nome acessível único e erro associado; ordem de leitura acompanha tarefa.
- **Dependências:** Componente de formulário.
- **Testes necessários:** Inspeção DOM automatizada; teclado e leitor de tela em anamnese, plano, receita e agenda.

### PX-08 — Modais clínicos não gerenciam foco de modo consistente

- **Rota/módulo:** Perfil, prontuário e planos.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Modais clínicos não gerenciam foco de modo consistente.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:247](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:247); ao abrir Novo Plano e +Alimento o foco permanece no acionador; sem role dialog/aria-modal no runtime inspecionado. Cadastro de paciente possui foco inicial, mas não prova contenção.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Implementações locais alternam classList e style.display; sem primitiva comum.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Teclado percorre conteúdo de fundo; decisão crítica perde contexto e aumenta erro.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Unificar diálogo com nome, foco inicial, contenção, Escape seguro, retorno ao acionador e fundo inerte.
- **Critérios de aceite:** Todos os diálogos seguem o mesmo contrato; fechar formulário alterado informa consequência; foco sempre retorna.
- **Dependências:** Primitiva de diálogo acessível.
- **Testes necessários:** Tab/Shift+Tab/Escape; leitor de tela; scroll móvel; submissão inválida e fechamento com alteração.

### PX-09 — Dashboard transborda e posterga tarefas no celular

- **Rota/módulo:** /dashboard a 390px.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Dashboard transborda e posterga tarefas no celular.
- **Evidência verificável:** [Captura 390px](evidence/dashboard-390.jpg); medição DOM: viewport 390px, documento 461px. [src/Application/Controllers/DashboardController.php:1552](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:1552); shell ocupa cerca de 257px no topo da captura.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Grid local fixo e navegação extensa em linhas; regra móvel não cobre toda composição.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Rolagem horizontal e grande distância até consultas/prioridades; rotina móvel ineficiente.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Shell compacto com menu acessível; agenda primeiro; empilhar grid; manter tabelas com overflow local justificado.
- **Critérios de aceite:** Sem overflow global em 320/390/768/1024/1440; próxima ação acessível sem atravessar métricas; foco não encoberto.
- **Dependências:** Shell responsivo.
- **Testes necessários:** Screenshots e DOM em larguras; 200% zoom; orientação; teclado virtual; tabela extensa.

### PX-10 — Chat rompe identidade e não tem entrada funcional para iniciar contato

- **Rota/módulo:** /chat e perfil do paciente.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Chat rompe identidade e não tem entrada funcional para iniciar contato.
- **Evidência verificável:** [src/Application/Controllers/ChatController.php:197](/Users/moliveira/Nutri_II/src/Application/Controllers/ChatController.php:197); [captura](evidence/chat-desktop.jpg); sidebar oficial não inclui Chat; vazio pede iniciar pela ficha, mas perfil inspecionado não oferece ação.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Shell próprio com emoji, fonte/paleta locais; ligação entre perfil e conversa ausente na interface observada.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Comunicação fica escondida e fluxo termina sem próxima ação executável.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Usar shell compartilhado e ação Mensagem no paciente que resolve/cria conversa com ownership; exibir canal na navegação.
- **Critérios de aceite:** De perfil sem conversa, ação abre thread correta; vazio tem CTA útil; identidade preservada.
- **Dependências:** Contrato de criação de conversa.
- **Testes necessários:** Paciente novo/existente; criação idempotente; envio/retry; permissão; não enviar mensagens reais em homologação.

### PX-11 — Configurações são somente texto declarativo

- **Rota/módulo:** /configuracoes.
- **Perfil impactado:** Nutricionista / dono da clínica.
- **Descrição:** Configurações são somente texto declarativo.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:3204](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:3204); UI exibe perfil, códigos hex e LGPD Ativa com Auditoria Criptográfica; nenhum campo ou ação.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Tela implementada como resumo estático, confundindo governança com estado operacional.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Não permite gerenciar consultório, equipe, privacidade ou conta; promete mais controle do que entrega.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Separar perfil, consultório, acesso/equipe, preferências e dados/privacidade; mostrar apenas funções implementadas e afirmações comprováveis.
- **Critérios de aceite:** Perfil e preferências editáveis com validação; matriz de permissões explícita; solicitações de dados têm canal rastreável; sem selo genérico de conformidade.
- **Dependências:** Escopo real de gestão de conta e revisão de governança.
- **Testes necessários:** Salvar/reabrir; erros; RBAC; trilha; solicitações e estados pendentes.

### PX-12 — Financeiro vazio não explica como começar

- **Rota/módulo:** /financeiro.
- **Perfil impactado:** Nutricionista empreendedor.
- **Descrição:** Financeiro vazio não explica como começar.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:3143](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:3143); estado Nenhum recebível financeiro registrado sem CTA.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Listagem sem entrada operacional no runtime web.
- **Severidade de produto:** P2.
- **Impacto na experiência:** Profissional não consegue converter atendimento em operação financeira pela tela; valor percebido inferior ao título.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Definir mínimo real de recebíveis e recibos ou informar limite funcional com próximo passo legítimo.
- **Critérios de aceite:** Vazio explica origem dos lançamentos; ação leva a fluxo real; status/valor/vencimento traduzidos; nenhuma simulação.
- **Dependências:** Decisão do escopo financeiro; nenhum pagamento real nesta auditoria.
- **Testes necessários:** Primeiro lançamento, edição, baixa/reversão conforme escopo; moeda; permissões; vazio.

### PX-13 — Jargão técnico e enums chegam ao usuário

- **Rota/módulo:** Dashboard, agenda, prontuário e perfil.
- **Perfil impactado:** Nutricionista e paciente.
- **Descrição:** Jargão técnico e enums chegam ao usuário.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:1072](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:1072); first_visit/completed/male/published; NI2-64 e hashes em headings observados.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Modelo de implementação usado diretamente como conteúdo de interface.
- **Severidade de produto:** P2.
- **Impacto na experiência:** Eleva carga cognitiva, parece ambiente de desenvolvimento e enfraquece profissionalismo.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Mapa central de rótulos; Registro preservado, autoria e data como linguagem principal; detalhes de integridade disponíveis sob demanda.
- **Critérios de aceite:** Nenhum enum/código de ticket em texto operacional; estados claros e consistentes em português.
- **Dependências:** Nenhuma.
- **Testes necessários:** Varredura de strings renderizadas; cada estado de agenda/plano; traduções e fallbacks.

### PX-14 — Gestão de pacientes não tem busca, filtros ou paginação visíveis

- **Rota/módulo:** /pacientes.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Gestão de pacientes não tem busca, filtros ou paginação visíveis.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:3451](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:3451); lista inspecionada tem apenas Cadastrar Paciente e tabela.
- **Estado da evidência:** Confirmado.
- **Causa provável:** ListAll renderizado diretamente sem instrumentos de recuperação.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Escala com carteira extensa exige varredura manual e aumenta seleção errada.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Busca por nome e identificador autorizado; filtros ativos/arquivados; paginação; preservação de consulta ao retornar.
- **Critérios de aceite:** Localizar paciente pelo nome; retorno mantém busca e posição; nomes iguais têm desambiguação adequada sem exposição excessiva.
- **Dependências:** Nenhuma.
- **Testes necessários:** 100+ pacientes sintéticos; acentos; duplicados; nenhuma correspondência; tenant; teclado.

### PX-15 — Seleção alimentar não escala ao catálogo

- **Rota/módulo:** /alimentos e /planos/{id}.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Seleção alimentar não escala ao catálogo.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:2568](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:2568); editor usa select; catálogo é tabela sem busca; lista do ambiente existente apresenta nomes duplicados.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Limite arbitrário, ausência de busca contextual e governança de fontes.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Alimento fora dos primeiros 250 fica inacessível; prescrições repetitivas demandam varredura.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Combobox com busca de servidor, origem/preparo/unidade, resultados paginados e identidade estável.
- **Critérios de aceite:** Item depois da posição 250 é localizável; duplicatas identificadas por fonte/preparo; teclado e ausência de resultados resolvidos.
- **Dependências:** Catálogo com proveniência e índices.
- **Testes necessários:** Catálogo >250; acentos e sinônimos; lenta resposta; paginação; seleção por teclado.

### PX-16 — Tabela confunde porção e referência nutricional

- **Rota/módulo:** /alimentos.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Tabela confunde porção e referência nutricional.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:3626](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:3626); [DOM](evidence/foods-dom.txt): Aveia 30 colher de sopa /394 kcal; leite 200 ml /35 kcal; valores apresentados são referência do catálogo por 100g.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Massa de porção concatenada com nome da medida; energia não escalada nem rotulada por 100g.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Leitura pode induzir quantidade/energia incorretas; reduz segurança da interpretação.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Distinguir referência por 100g, massa/volume e medida caseira; calcular porção quando exibida; evitar conversão g/ml sem densidade válida.
- **Critérios de aceite:** Cada valor tem base explícita; medida caseira tem quantidade e equivalência coerentes; dados desconhecidos não inferidos.
- **Dependências:** Contrato de unidades e catálogo.
- **Testes necessários:** Aveia/leite/azeite; 100g versus porção; densidade ausente; validação por nutricionista.

### PX-17 — Editar item do plano exige remover e reinserir

- **Rota/módulo:** /planos/{id}.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Editar item do plano exige remover e reinserir.
- **Evidência verificável:** [public/index.php:172](/Users/moliveira/Nutri_II/public/index.php:172); editor observado mostra +Substituição e remover, sem editar quantidade/unidade/nota.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Rotas de inserção/remoção sem fluxo de atualização do item.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Revisão frequente demanda retrabalho e risco de perder substituições ou observações.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Edição contextual de item do rascunho; publicado gera nova versão preservando linhagem.
- **Critérios de aceite:** Alterar porção conserva identificador/contexto/substituições e recalcula; versão publicada não muda retroativamente.
- **Dependências:** Contrato de atualização e controle de versão.
- **Testes necessários:** Editar porção e nota; falha de persistência; duas abas; rascunho versus publicado.

### PX-18 — Formulário clínico longo sem recuperação de alterações não salvas

- **Rota/módulo:** Anamnese e evolução.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Formulário clínico longo sem recuperação de alterações não salvas.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:1670](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:1670); submissão por formulário completo; inspeção não encontrou autosave/beforeunload/recuperação no fluxo.
- **Estado da evidência:** Risco fundamentado em fonte; perda específica não provocada no banco vivo.
- **Causa provável:** Estado reside no DOM até POST; erros redirecionam sem repor payload na lógica revisada.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Risco de perda de trabalho por sessão expirada, navegação e validação; impacto ainda não quantificado com usuários.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Rascunho persistido seguro, estado Salvo/Salvando/Falha, prevenção de saída e preservação do payload em erro.
- **Critérios de aceite:** Falha e sessão expirada preservam entrada sem vazamento; trocar paciente não mistura rascunhos; finalização é explícita.
- **Dependências:** Modelo de rascunho e decisão de armazenamento protegido.
- **Testes necessários:** Falha de rede, POST inválido, expiração, duas abas, retorno, usuário/tenant distintos.

### PX-19 — Ausência de anamnese parece ausência de alergias

- **Rota/módulo:** Editor alimentar.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Ausência de anamnese parece ausência de alergias.
- **Evidência verificável:** [src/Application/Controllers/DashboardController.php:2635](/Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php:2635); paciente sem anamnese aparece ALERGIAS [Nenhuma relatada].
- **Estado da evidência:** Confirmado.
- **Causa provável:** Estado desconhecido e ausência confirmada compartilham representação.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Falsa tranquilidade ao prescrever; não prova prescrição danosa ou falha de detector.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Estados explícitos Não avaliado / Nega alergias / Alergia registrada; origem/data e revisão antes da publicação.
- **Critérios de aceite:** Sem coleta → Não avaliado; negativo somente com registro explícito; revisão aponta pendências sem inventar diagnóstico.
- **Dependências:** Contrato estruturado de segurança clínica.
- **Testes necessários:** Sem anamnese; rascunho; negativa explícita; alergia positiva; atualização após publicação.

### PX-20 — Meta hídrica exibida ao paciente diverge da coleta clínica

- **Rota/módulo:** API home e anamnese.
- **Perfil impactado:** Paciente e nutricionista.
- **Descrição:** Meta hídrica exibida ao paciente diverge da coleta clínica.
- **Evidência verificável:** [public/index.php:357](/Users/moliveira/Nutri_II/public/index.php:357); [persistência](evidence/persistence-final.json): water_intake_ml=2000; [API](evidence/runtime-api-and-persistence.json): water_goal_ml=2500.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Valor fixo no payload de home, sem referência ao plano/objetivo clínico.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Orientações conflitantes entre consultório e paciente; quebra continuidade de cuidado.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Definir semântica de ingestão habitual versus meta prescrita e fonte única para meta; ausência é ausência.
- **Critérios de aceite:** Meta informada pelo profissional é a mesma na API/UI; se campo for ingestão habitual, não o rotular como meta; sem default clínico silencioso.
- **Dependências:** Decisão clínica sobre semântica do campo.
- **Testes necessários:** Meta ausente/alterada; plano vigente; unidades; zero/inválido; paciente/tenant.

### PX-21 — Tokens e variantes divergem da referência e entre módulos

- **Rota/módulo:** Design System.
- **Perfil impactado:** Todos.
- **Descrição:** Tokens e variantes divergem da referência e entre módulos.
- **Evidência verificável:** [CLYONUTRI_DESIGN_SYSTEM_PREMIUM.md:1](/Users/moliveira/Nutri_II/CLYONUTRI_DESIGN_SYSTEM_PREMIUM.md:1); [public/assets/css/clinical-botanical.css:8](/Users/moliveira/Nutri_II/public/assets/css/clinical-botanical.css:8); referência #0F2747/#E67A3F versus CSS #0F2A44/#E67E3A; btn-outline e badge-danger sem definição no CSS compartilhado.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Referência com status Proposta, sem governança de aprovação; estilos locais dominam composição.
- **Severidade de produto:** P2.
- **Impacto na experiência:** Ações e estados parecem de produtos distintos; manutenção de consistência fica cara.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Ratificar paleta com comparação documentada; aliases semânticos versionados; registrar variantes e remover divergências por migração.
- **Critérios de aceite:** Uma fonte de tokens/variantes por plataforma; todas classes usadas existem; diferenças intencionais documentadas.
- **Dependências:** Ratificação da referência de marca.
- **Testes necessários:** Inventário de tokens/classes; regressão visual em estados e módulos; contraste.

### PX-22 — Testes homologam mais do que efetivamente verificam

- **Rota/módulo:** Qualidade / teleconsulta / API.
- **Perfil impactado:** Equipe e usuários.
- **Descrição:** Testes homologam mais do que efetivamente verificam.
- **Evidência verificável:** [tests/teleconsultation_online_clinic_e2e_test.php:301](/Users/moliveira/Nutri_II/tests/teleconsultation_online_clinic_e2e_test.php:301); log diz integração funcional com Daily comprovada usando classe simulada; teste mobile registra rotas próprias no harness.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Fronteira testada diferente da integração implantada; nomes E2E ampliam interpretação.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Go-live pode liberar quebra de instalação, login e mídia apesar de testes verdes.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Separar domínio, contrato HTTP do entrypoint real e E2E de navegador/provedor; relatório mostra blocked/skipped sem sucesso substituto.
- **Critérios de aceite:** Pipeline de banco limpo cobre public/index.php; mídia só aprovada com provedor real e dois participantes; mocks não contam como homologação.
- **Dependências:** Ambiente de homologação e credenciais autorizadas.
- **Testes necessários:** Banco limpo; API real; browser; provedor indisponível; run com credenciais sandbox.

### PX-23 — Revogação de profissional não invalida sessão existente

- **Rota/módulo:** Autorização web.
- **Perfil impactado:** Clínica / pacientes.
- **Descrição:** Revogação de profissional não invalida sessão existente.
- **Evidência verificável:** [public/index.php:39](/Users/moliveira/Nutri_II/public/index.php:39); [public/index.php:111](/Users/moliveira/Nutri_II/public/index.php:111); revisão Codex Security: severidade técnica média.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Autorização reconstruída da sessão sem revalidar user/clinic_members.status.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Profissional suspenso pode conservar acesso a dados e operações; confiança e controle de equipe comprometidos.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Revalidar identidade/vínculo/clínica e versionar ou revogar sessões ao alterar acesso.
- **Critérios de aceite:** Após suspensão/remover vínculo, próxima leitura e mutação falham 401/403; usuário permitido mantém sessão válida.
- **Dependências:** Política de sessão e RBAC.
- **Testes necessários:** Dois usuários, vínculo suspenso, clínica inativa, sessão prévia, autorização concorrente.

### PX-24 — Token de paciente permanece após inativação/anonimização

- **Rota/módulo:** Autorização API / LGPD.
- **Perfil impactado:** Paciente / clínica.
- **Descrição:** Token de paciente permanece após inativação/anonimização.
- **Evidência verificável:** [public/index.php:231](/Users/moliveira/Nutri_II/public/index.php:231); [src/Domain/Privacy/LgpdService.php:69](/Users/moliveira/Nutri_II/src/Domain/Privacy/LgpdService.php:69); revisão técnica média.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Consulta Bearer não filtra status; transição de privacidade não revoga tokens.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Janela de acesso até expiração de 30 dias; estado de privacidade não corresponde ao acesso efetivo.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Revalidar status paciente/clínica e revogar tokens atomicamente na transição.
- **Critérios de aceite:** Paciente inativo/anonimizado não usa token anterior; falha transacional não deixa estado parcial.
- **Dependências:** Contrato de retenção e transições de privacidade.
- **Testes necessários:** Token antes/depois de inativação e anonimização; rollback; token expirado.

### PX-25 — Paciente pode falsificar recibo de leitura de outra conversa da clínica

- **Rota/módulo:** API chat/read.
- **Perfil impactado:** Paciente e profissional.
- **Descrição:** Paciente pode falsificar recibo de leitura de outra conversa da clínica.
- **Evidência verificável:** [public/index.php:509](/Users/moliveira/Nutri_II/public/index.php:509); [src/Domain/Chat/ChatService.php:357](/Users/moliveira/Nutri_II/src/Domain/Chat/ChatService.php:357); revisão técnica baixa: sem leitura de conteúdo ou escape de tenant.
- **Estado da evidência:** Confirmado.
- **Causa provável:** UPDATE verifica clínica, mas não participante/readerId.
- **Severidade de produto:** P2.
- **Impacto na experiência:** Mensagens parecem lidas por destinatário errado; prejudica acompanhamento e expectativa de resposta.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Validar participante antes de atualizar recibos; identidade vem do token.
- **Critérios de aceite:** Paciente A não marca conversa de B no mesmo tenant; própria conversa funciona.
- **Dependências:** Ownership uniforme de conversa.
- **Testes necessários:** Dois pacientes na mesma clínica; outra clínica; ID inexistente; repetição idempotente.

### PX-26 — Falha de API devolve HTML com HTTP 200 e cliente a oculta

- **Rota/módulo:** Evolução / mobile.
- **Perfil impactado:** Paciente e operação.
- **Descrição:** Falha de API devolve HTML com HTTP 200 e cliente a oculta.
- **Evidência verificável:** [mobile/src/screens/PatientHomeScreen.tsx:47](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:47); resposta evolution 200 com Fatal error e caminho interno em [evidência](evidence/runtime-api-and-persistence.json).
- **Estado da evidência:** Confirmado.
- **Causa provável:** Sem handler global de exceções; cliente ignora falhas de diário/evolução.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Erro é interpretado como falta de dados; suporte perde sinal e usuário recebe feedback enganoso.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Envelope JSON e status corretos, identificador de correlação, logs restritos; falhas parciais locais com repetir e data de atualização.
- **Critérios de aceite:** Exceção gera 5xx JSON sem stack; app distingue vazio, desatualizado, offline e falha parcial.
- **Dependências:** Middleware de erros e estados por recurso.
- **Testes necessários:** Falha SQL/rede; JSON inválido; 401; diário falha com home válida; leitor de tela.

### PX-27 — Biblioteca não oferece edição de receita no runtime web

- **Rota/módulo:** /receitas.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Biblioteca não oferece edição de receita no runtime web.
- **Evidência verificável:** [public/index.php:214](/Users/moliveira/Nutri_II/public/index.php:214); rotas atuais: lista/criar/ver/prescrever/duplicar, sem editar; receita nova usa selects sem busca.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Serviço e versões não se convertem em jornada de manutenção pela interface.
- **Severidade de produto:** P2.
- **Impacto na experiência:** Corrigir preparo/ingrediente exige duplicação ou caminho inexistente; biblioteca se fragmenta.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Edição versionada contextual, preservando receitas já prescritas; busca de ingredientes compartilhada.
- **Critérios de aceite:** Editar receita privada gera versão auditada; prescrição anterior mantém snapshot; receita sem acesso não é alterada.
- **Dependências:** Decisão de versionamento e componente de alimento.
- **Testes necessários:** Criar/editar/duplicar/prescrever; alergênicos e rendimento; receita pública e privada.

### PX-28 — Ajuda descreve recursos e entradas não disponíveis no fluxo observado

- **Rota/módulo:** /ajuda.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Ajuda descreve recursos e entradas não disponíveis no fluxo observado.
- **Evidência verificável:** [src/Domain/Helpdesk/KnowledgeBaseService.php:1](/Users/moliveira/Nutri_II/src/Domain/Helpdesk/KnowledgeBaseService.php:1); categorias citam recuperação, convites, assinatura e personalização; várias com zero artigos; ficha não oferece convite.
- **Estado da evidência:** Confirmado.
- **Causa provável:** Conteúdo editorial desacoplado do inventário de capacidades publicado.
- **Severidade de produto:** P2.
- **Impacto na experiência:** Usuário procura funções sem caminho real; suporte vira compensação de descoberta incompleta.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Inventário de capacidades para ajuda contextual; artigos com passos executáveis e limites por versão.
- **Critérios de aceite:** Cada ação documentada corresponde a rota/UI disponível; categoria vazia tem alternativa útil; beta explícito.
- **Dependências:** Inventário runtime.
- **Testes necessários:** Percorrer artigos por tarefas e papel; links; pesquisa sem resultado; revisão por versão.

### PX-29 — Recuperação de acesso depende de e-mail ao suporte

- **Rota/módulo:** /login.
- **Perfil impactado:** Nutricionista.
- **Descrição:** Recuperação de acesso depende de e-mail ao suporte.
- **Evidência verificável:** [views/auth/login.php:100](/Users/moliveira/Nutri_II/views/auth/login.php:100); login inspecionado oferece Ajuda com acesso por mailto; nenhuma rota de recuperação no inventário runtime.
- **Estado da evidência:** Confirmado.
- **Causa provável:** password_resets existe como tabela sem jornada HTTP operacional identificada.
- **Severidade de produto:** P1.
- **Impacto na experiência:** Bloqueio de trabalho depende de atendimento humano e prazo desconhecido.
- **Impacto na produtividade:** o efeito operacional descrito acima é qualitativo; minutos perdidos e impacto em retenção ainda não medidos.
- **Solução recomendada e orientação técnica:** Recuperação segura por token expirável, com mensagens não enumeráveis e retorno ao login; suporte é alternativa.
- **Critérios de aceite:** Usuário com acesso ao canal cadastrado redefine credencial; token único/expirado não funciona; sessões antigas tratadas conforme política.
- **Dependências:** Serviço de e-mail homologado e política de credenciais.
- **Testes necessários:** Solicitação, reenvio, token consumido, expiração, abuso, senha inválida; sem enviar e-mails reais nesta auditoria.

