# Auditoria de jornadas e UX mobile — ClyoNutri

Data: 09/10/2026. Avaliação independente por inspeção de código, heurísticas e execução com dados fictícios. Referência Git: `2ad8228`, **working tree com alterações locais**, não uma release certificada. Implementação não foi alterada nesta etapa; os arquivos deste diretório são entregáveis da auditoria.

## A. Parecer executivo

**Recomendação: não iniciar um piloto clínico amplo antes de corrigir a fidelidade das prescrições, duplicação de registros e comunicação de erros/confiança.** Um piloto exploratório moderado, com dados fictícios e observação do pesquisador, pode validar a arquitetura de informação desde já. A decisão de piloto real deve considerar também QA nativo e governança de privacidade.

O app oferece um núcleo real: login nos dois perfis, convite de primeiro acesso, plano publicado, hidratação, receita prescrita, registro básico de refeição, leitura de evolução compartilhada, confirmação de consulta, feedback e conversa. **Isso não significa que as jornadas estejam completas.** O paciente perde instruções e porções de substituições, não consegue abrir a teleconsulta anunciada e recebe conteúdo de receita diferente da versão prescrita. O nutricionista vê lista limitada silenciosamente a 100 pacientes e uma agenda que afirma estar livre mesmo quando a API falha.

Há fundamentos positivos: marca identificável e logotipos sem distorção aparente, login com labels e autocomplete, convite com clínica/profissional, separação de avaliação compartilhada e autorrelato na API, meta hídrica não inventada quando ausente, recuperação em receitas/diário/evolução e revogação de sessão ao sair. Nos experimentos, hidratação de 250 ml, confirmação de consulta, duas mensagens e feedback profissional persistiram e foram recuperados. O rascunho de chat sobreviveu à troca de abas na sessão.

**22 findings:** falhas implementadas, lacunas documentadas e hipóteses estão distinguidas em cada registro. Nenhum P0 demonstrado nesta avaliação; isso não comprova ausência de risco de segurança. Não é auditoria de segurança nem validação clínica de nutrientes. Não há entrevistas, taxa de sucesso de usuários, SUS, NPS ou métricas de produção nesta entrega.

## Método e limites da evidência

- **E — Execução:** Expo Web exportado do código atual em servidor temporário 8099, API PHP isolada 8098, SQLite exclusivamente fictício em `/tmp/ni2-281-mobile-ux-audit`. Nenhuma mutação no banco real; nenhuma sala externa acessada. Capturas só mostram nomes, contatos e dados clínicos fictícios.
- **C — Código:** arquivos do cliente, API, domínio, fixtures e documentos de design/escopo. Um endpoint existente não foi classificado como tela funcional.
- **H — Hipótese:** efeito sobre compreensão, abandono e esforço que exige pesquisa; não é observação de usuário.
- **Não houve execução nativa:** `adb` não disponível; `xcrun simctl` não disponível. Não foram validados Android/iOS, TalkBack/VoiceOver, teclado de dispositivo, recortes, barra de gestos, permissões, suspensão/retomada nativa ou push.
- Expo/TypeScript exportou com sucesso e `npm --prefix mobile run typecheck` passou. Isso verifica build/tipos, não a usabilidade nem todas as integrações.
- Larguras **efetivas CSS**, lidas na página: 341×738 no início, 375×812 depois e 320×640 no final. O zoom de navegador pré-existente afetou o primeiro override nominal de 375; os quatro primeiros nomes de arquivo foram ajustados para 341. Não confundir screenshot com resolução de dispositivo. DOM da navegação: texto 11 px, alvos arredondados ~44×44 px, Evolucao ~60×44. Isso não é medição em dp nativo.
- Falhas 503 de leitura de chat/receitas/dashboard foram injetadas por proxy de teste. Atraso **de API** de 10 s em receitas foi executado; não é simulação de largura de banda/cobertura celular. Falta total de rede e pedido infinito não foram executados.
- Cadastro: etapas de convite inválido/válido e formulário foram executadas na UI; ativação final foi preparada pelo serviço na fixture. **Não classificar onboarding integral como E2E de UI validado.** Credenciais sintéticas existentes foram usadas nos logins.
- Mudança de versão de receita foi realizada pelo serviço em base de teste; leitura posterior foi verificada por API e UI. Não foi uma edição da receita de um paciente real.
- Evidências anteriores ao trabalho não foram tratadas como testes atuais. Datas/horas exibidas são as próprias observadas no ambiente; divergências de fuso constam em MOB-UX-019.

## B. Tecnologia, inventário e referências

| Camada | Implementação identificada | Limite |
|---|---|---|
| Mobile | React Native 0.74.2, Expo ~51, React 18.2, TypeScript ~5.3; react-native-web ~0.19 | App paciente e profissional; execução desta auditoria somente web |
| Navegação | `App.tsx` condiciona login/onboarding/sessão; abas via `useState` | Sem React Navigation/Expo Router; rota web permanece `/`; deep links e voltar nativo não implementados nesse caminho |
| Componentes | BrandLogo, ResourceNotice local, SafeAreaView/ScrollView/TextInput/TouchableOpacity, styles locais | Não há biblioteca única para cards/estados/compositor nos dois perfis |
| Integração | fetch JSON Bearer para `/api/v1`; PHP MVC próprio e serviços de domínio; SQLite no teste | JWT não deve ser presumido: token opaco; persistência de sessão somente em memória no cliente |
| Desenvolvimento web | Metro proxy `/api/v1` para API 8080; timeout upstream de 15 s | Produção/export exige configurar origem/API; não confundir proxy dev com garantia do cliente nativo |
| Recursos nativos | Código usa componentes RN; app.json define pacote Android/EAS project | Câmera, push, armazenamento seguro e links de convite não têm fluxo implementado identificado no cliente |

### Telas e ações realmente encontradas

| Perfil | Tela/estado | Componentes e chamadas | Real / limite funcional |
|---|---|---|---|
| Ambos | Login e seletor de perfil | App, BrandLogo, patient-login / professional-login | Real; e-mail/senha; sem recuperação autônoma |
| Paciente | Primeiro acesso etapa 1, etapa 2, sucesso | PatientOnboardingScreen; preview/complete | Convite real de 48 h, vínculo, cadastro e senha; ativação parcial na UI nesta auditoria |
| Paciente | Hoje | getHome, logHydration, confirmAppointment, logMeal | Plano/refeições, atendimento, água; atalhos para receita/chat; sem cálculo da próxima refeição |
| Paciente | Plano | getRecipes/getRecipe, active_plan de home | Receitas, ingredientes/preparo e plano; falta fidelidade de versão e detalhes do plano |
| Paciente | Diário | getFoodDiary | Histórico e feedback real; criação só no botão da refeição em Hoje |
| Paciente | Chat | getChatMessages/sendChatMessage/markChatAsRead | Mensagens persistidas; polling 3 s; sem upload/anexo UI; falsas promessas e erros ocultos |
| Paciente | Evolução | getEvolution | Avaliações compartilhadas e autorrelatos quando existentes; sem entrada para metas/check-in/autorrelato |
| Paciente | Perfil | dados da sessão, logout | Somente identificação e saída; nome promete Privacidade |
| Nutricionista | Agenda | getProfessionalDashboard | Resumo e consultas de hoje; não agenda/edita/muda atendimento |
| Nutricionista | Pacientes | getProfessionalPatients, createPatientInvitation | Lista/contato/convite; sem busca, perfil clínico ou paginação |
| Nutricionista | Diários | getProfessionalFoodDiaries, submitFoodDiaryFeedback | Leitura e orientação persistida; últimos 50 via backend, sem paginação do histórico |
| Nutricionista | Chat/lista/conversa | conversations/messages/send/read | Mensagens e voltar interno; falha de leitura silenciosa; sem composição de prescrição |
| Nutricionista | Perfil | dados da sessão, logoutProfessional | Leitura de conta/clínica/papel e saída |

APIs não ligadas a uma tarefa da UI: lista de compras/favoritos de receitas, check-in e autorrelato de métricas; backend de documentos/notificações/privacidade não equivale a acesso no cliente auditado. Criar/editar/publicar plano, anamnese e avaliações são jornadas do **portal profissional** encontradas no produto, não tarefas implementadas neste app. Definir promessa de app profissional como companheiro de rotina; não classificar toda ausência de desktop como defeito.

### Fonte de verdade visual: divergência registrada, não resolvida por preferência

1. `blueprint_extracted.txt:74` — Clinical Botanical: Forest #0F3E17, Clyo Green #39734A, Cream #FAF9F4, Inter.
2. `CLYONUTRI_DESIGN_SYSTEM_PREMIUM.md` — documento diz **Proposta**; navy #0F2747, cobre #E67A3F/#B94F1F, operacional #F4F5F6, Inter.
3. `public/assets/css/clinical-botanical.css:8` — declara Premium oficial, navy #0F2A44, cobre #E67E3A, cream #F8F4ED.
4. `mobile/src/theme/tokens.ts:1` espelha o CSS; telas paciente usam alguns hex anteriores e chat usa verde como ação. Inter não é carregada pelo aplicativo; tipografia do sistema não é automaticamente ruim, mas diverge da referência.

Preservar marca navy/cobre/logotipo atual durante correções; ratificar a referência antes de mudanças amplas. O problema de verde aqui é semântico/contraste/promessa de presença, não preferência estética.

## Mapa de jornadas e resultados

Cada linha registra objetivo, entrada/pré-requisito, decisões, sequência e recuperação. Resultado esperado é a intenção de tarefa, não uma promessa de implementação inexistente.

| ID | Perfil / objetivo | Entrada e pré-requisito | Ações / decisões | Esperado → observado | Atrito e recuperação |
|---|---|---|---|---|---|
| J01 | Paciente: receber vínculo | Profissional autenticado, paciente sem credencial | Pacientes → gerar convite → entregar código privado → Primeiro acesso | Convite válido → backend/preview funcionam; entrega externa manual | Código longo e troca de canal; não há compartilhar/copiar dedicado; paciente já ativo recebe erro genérico |
| J02 | Paciente: ativar acesso | Código válido, clínica/paciente ativos | Conferir → conferir clínica/profissional → cadastro/senha → ativar → começar | Conta vinculada → preview UI e ativação por serviço confirmados | Código inválido tem instrução útil; cadastro não explica privacidade; voltar para login desmonta formulário |
| J03 | Ambos: entrar | Conta existente | Escolher perfil → e-mail/senha → Entrar | Sessão → ambos funcionaram | Campos obrigatórios e erro; sem esqueci senha; e-mail preservado em expiração |
| J04 | Ambos: sair/voltar | Sessão ativa | Perfil → sair; recarregar/reabrir | Revogar / retorno conforme política → logout profissional e expiração paciente funcionaram; reload web perde sessão | Logout exige rede para revogar; mensagem de retry prevista; reabertura nativa não testada |
| J05 | Paciente: seguir plano | Plano publicado | Hoje → refeição ou Plano → ler porção/orientação → substituição | Entender refeição → alimento/base exibidos; alternativa sem dose e notas omitidas | Precisa deduzir detalhes; sem tela de refeição; refresh recarrega dados, não recupera conteúdo omitido |
| J06 | Paciente: preparar receita | Prescrição existente | Atalho/Plano → receita → ver ingredientes/preparo | Receita prescrita e dose → UI/API funcionam, mas entregam versão atual da biblioteca | Cálculo do lote manual; erro tem retry; nova versão modifica prescrição antiga |
| J07 | Paciente: registrar água | Sessão ativa; meta opcional | Hoje → +250/+500 | Registro e total → 250 ml persistidos e recuperados | Meta ausente é explicitada; desfazer/valor livre não existentes; sem rede real testada |
| J08 | Paciente: registrar consumo | Refeição do plano | Hoje → Registrar Consumo → Diário | Consumo real contextual → registro mínimo persiste; duplo toque duplica | Sem composição/correção/foto; texto automático; alternativa para refeição fora do plano ausente |
| J09 | Paciente: ler evolução | Avaliação compartilhada/autorrelato existente | Evolução → datas/medidas/origem | Contexto confiável → avaliação fictícia compartilhada exibida | Texto presencial sem dado; sem tendência/metas/check-in; vazio não orienta próximos passos |
| J10 | Paciente: consulta/teleconsulta | Consulta vinculada futura, meeting_url opcional | Hoje → confirmar → entrar na sala | Confirmação e acesso → confirmação persistiu; sala sem ação | Data crua; sem agendamento/reagendamento; contato/sala externa são alternativa fora do app |
| J11 | Ambos: conversar | Vínculo e conversa autorizados | Chat → escrever → enviar → outro perfil responde | Comunicação persistida → ida e volta confirmadas | Presença/E2EE falsas, falha invisível; rascunho sobrevive troca de abas; idempotência nova a cada retry de envio |
| J12 | Profissional: localizar paciente | Clínica com pacientes | Pacientes → procurar contato/convite | Encontrar cadastro → com 102, apenas 100 aparecem | Sem busca e perfil clínico; nenhum caminho para os demais |
| J13 | Profissional: revisar diário | Paciente registrou refeição | Diários → Dar feedback → digitar → salvar → paciente lê | Orientação persistida → concluída e recebida | Histórico limitado 50; alternar formulário pode zerar rascunho; aviso global permanece em outras abas |
| J14 | Profissional: consultar agenda | Dashboard disponível | Agenda → ler horário/status | Estado confiável → vazio real funciona; 503 afirma agenda livre | Falha recuperável por Recarregar, mas vazio enganoso; sem gerenciamento de atendimento |
| J15 | Paciente: direitos/ajuda | Sessão | Perfil → procurar opção | Canal claro → só identificação/logout | Sem ação de conta/privacidade/ajuda; escopo documentado incompleto |

## C. Matriz de cobertura

Legenda: **Testada** = caminho descrito executado na versão web; **Parcial** = apenas trecho/UI ou contrato; **Bloqueada** = tarefa pretendida não conclui no caminho existente; **Não avaliada** = sem execução/evidência suficiente. Ausente não é automaticamente defeito: classificação adicional distingue escopo/oportunidade.

| Jornada/condição | Estado | Evidência e limite |
|---|---|---|
| Login paciente/profissional e validação de campo vazio | Testada web | Entrada nas duas contas sintéticas; campos vazios geram mensagem |
| Convite inválido/válido, vínculo e cadastro | Parcial | UI etapas 1/2; ativação preparada pelo serviço; sem cadastro final E2E UI |
| Consentimentos/avisos de privacidade no primeiro acesso | Bloqueada como escopo | Sem entrada; zero manifestações na fixture; base legal a definir, não parecer jurídico |
| Recuperação de senha | Bloqueada como escopo | Contato externo sugerido, sem ação no cliente |
| Logout profissional | Testada web | Volta ao login; novas requisições exigem autenticação |
| Expiração da sessão paciente | Testada web | Expiração sintética → atualizar Diário → login com aviso e senha limpa |
| Reload web / retorno à sessão | Testada web | Login é exigido novamente; não extrapolar para suspensão nativa |
| Plano/refeições/orientações/substituições | Parcial com falha | Nome/quantidade base; notas e alternativa completas não chegam à UI |
| Receita prescrita, preparo, falha e recuperação | Testada web com falha | Conteúdo abre; 503 informa retry; versão prescrita não é preservada |
| Lista de compras/favoritos | Não avaliada como UI | Endpoints existem; não há fluxo cliente; oportunidade salvo escopo adicional |
| Registrar/ler água sem meta | Testada web | 250 ml persistidos; meta ausente corretamente informada |
| Registrar/ler consumo e duplo toque | Testada web com falha | 2 registros persistidos; sem correção |
| Foto, descrição editável, refeição fora do plano | Bloqueada como cobertura do diário | UI não implementada; não validar upload por simples photo_path |
| Evolução vazia e avaliação compartilhada | Testada web | Vazio inicial, depois medida fictícia; sem análise de grande histórico |
| Metas/autorrelato/check-in | Não avaliada / lacuna | Métodos de domínio não ligados à tarefa cliente |
| Consulta futura e confirmação | Testada web | scheduled → confirmed persistido |
| Entrar teleconsulta | Bloqueada | meeting_url existe; só texto Link disponível; câmera/microfone não solicitados |
| Chat paciente↔profissional e leitura | Testada web | Duas mensagens persistidas; controle Enviar profissional sem papel acessível explícito |
| Falha 503 chat | Testada web com falha | Sem aviso no chat paciente; histórico e Online permanecem |
| Rascunho de chat / troca de abas | Testada web | Texto preservado; remount/kill não testado |
| Pacientes 102 / limite 100 | Testada web com falha | Contagem truncada, sem busca/página; não é benchmark de desempenho |
| Feedback profissional e recebimento paciente | Testada web | Persistido e exibido depois do login/atualização |
| Falha 503 resumo profissional | Testada web com falha | Banner de erro + agenda livre simultâneos |
| API lenta 10 s em receitas | Parcial | Spinner e ação desabilitada; navegação permaneceu utilizável; conclusão recuperou |
| Ausência total de conexão / resposta nunca termina | Não avaliada na execução | Timeout ausente no cliente identificado por código |
| Permissão negada de câmera/notificação | Não avaliada | Não há captura/push no fluxo auditado; dispositivo indisponível |
| Duplo toque chat/hidratação/confirmar | Não avaliada integralmente | Guards de chat/água inspecionados; idempotência e confirmação requerem cenários extras |
| 320×640, 341×738, 375×812 CSS | Parcial | Telas web e nomes longos fictícios; não simula safe area/teclado nativo |
| Fonte ampliada 200%, TalkBack e VoiceOver | Não avaliada | Sem inferir conformidade pela existência de props |
| Interrupção/kill/reabertura nativa e gesto voltar | Não avaliada | Sessão/state e ausência de navigator identificados em código |
| Push/deep link ao destino | Não avaliada / sem implementação encontrada | Ausência de recepção/roteamento no App; logs backend não provam entrega |
| Documentos, suporte e edição de perfil | Não avaliada como execução | Sem controle no cliente; direitos/privacidade são lacuna documentada; documentos/support dependem de promessa contratual |
| Criar/editar/publicar prescrição e avaliar no app profissional | Não avaliada / escopo portal | Não existe no app; oportunidade se produto prometer paridade, não defeito automático |

## Acessibilidade: critérios e medição

WCAG 2.2 AA foi usada como referência para a versão **web**, não como certificação do app nativo. [Contraste 1.4.3](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html): 4,5:1 para texto normal e 3:1 para texto grande. [Alvo mínimo 2.5.8](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html): 24×24 CSS px ou exceções de espaçamento; adotar 44–48 como meta de conforto mobile não significa que AA exige 44 em todos os controles. [Redimensionamento 1.4.4](https://www.w3.org/WAI/WCAG22/Understanding/resize-text.html): 200% sem perda de conteúdo/função; não executado nesta auditoria.

- **Medido no código:** fórmula WCAG de luminância sRGB; branco/#059669 3,768:1, placeholder #94A3B8/branco 2,564:1, branco/#B94F1F 4,999:1, #667085/branco 4,975:1 e branco/#0F2A44 14,634:1. Só esses pares; composição/alpha precisam medição adicional.
- **Medido na página:** seis abas, texto 11 px e alvos ~44 CSS px; nenhuma conclusão de corte com fontScale nativo. Letras pequenas são risco de conforto, não reprovação automática de WCAG por tamanho absoluto.
- **Observado na árvore web:** paciente possui labels nos principais campos/ações; compositor profissional não nomeia campo e envio adequadamente; abas com estado selected; erro de chat não é anunciado porque não é exibido.
- **Visual:** logotipo contain, cartões com quebra de nome longo; cabeçalho de sincronização compete com conteúdo; uso de cor geralmente acompanhado por texto, exceto ponto de presença que reforça falsa informação.
- **Não avaliado:** ordem real no TalkBack, foco/alerts nativos, swipe system, teclado cobrindo compositor/formulário, aumento de fonte do sistema e alcance com uma mão. SafeAreaView é evidência de intenção, não prova de ausência de sobreposição.

## D. Findings rastreáveis

Severidade usa impacto na tarefa, não beleza. Esforços são faixas iniciais de dias de trabalho de uma pessoa experiente, excluem revisão clínica, pesquisa, dependências externas e estabilização; não são compromissos de prazo. Reprodução ocorre sempre em fixture isolada. Ausência de screenshot significa código/escopo ou que a imagem não acrescenta prova.

### MOB-UX-001 — Orientações e quantidades das substituições não chegam à leitura do plano

- **Perfil / jornada:** Paciente · Consultar plano e substituir alimento.
- **Tela / rota / componente:** Hoje e Plano / PatientHomeScreen; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha implementada · fidelidade clínica.
- **Severidade:** P1 — A tarefa principal exige inferir a quantidade de um substituto e perde instruções específicas.
- **Tipo de evidência:** Execução web + resposta da API + código. **Confiança:** Alta.
- **Reprodução:** Entrar com plano publicado de 100 g de arroz, nota de item e alternativa de 150 g com orientação; ler Hoje e Plano.
- **Atual:** Hoje mostra só o nome do substituto; Plano não mostra substituições; notas de refeição/item não são renderizadas nesses blocos. A API entrega amount=150, measure_unit=g e notes=Alternativa preservada.
- **Esperado:** Exibir quantidade, unidade e orientação profissional em ambos os caminhos, com prioridade sobre informação genérica.
- **Impacto usuário/produto:** Paciente pode aplicar a porção original ao alimento alternativo; prescrições detalhadas tornam-se incompletas no ponto de consumo.
- **Código:** [mobile/src/screens/PatientHomeScreen.tsx:472](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:472). Trecho: `item.substitutions.map(s => s.food_name)`.
- **Recomendação:** Criar uma apresentação de refeição reutilizada em Hoje/Plano, incluindo instruções e substituições com porção autorizada.
- **Critérios de aceite:** A fixture de 150 g mostra 150 g e sua nota em ambas as abas; notas de item/refeição permanecem legíveis; nenhuma conversão arbitrária.
- **Dependências / esforço / incertezas:** Frontend + contrato tipado; 2–4 dias, depende de casos de medidas caseiras.
- **Screenshot:** [03-patient-home-341.png](evidence/03-patient-home-341.png).

### MOB-UX-002 — Receita prescrita muda quando a biblioteca é editada

- **Perfil / jornada:** Paciente e nutricionista · Prescrever e consultar receita.
- **Tela / rota / componente:** Plano / API patient/recipes e recipes/{id}; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha implementada · versionamento clínico.
- **Severidade:** P1 — O conteúdo entregue não corresponde à versão escolhida na prescrição.
- **Tipo de evidência:** Execução web + experimento de API/base isolada + código. **Confiança:** Alta.
- **Reprodução:** Prescrever receita v1 com 1,5 porção; editar biblioteca para v2 com 4 porções; atualizar receitas no app.
- **Atual:** recipe_version_id continua 1; título/preparo passam a v2; energia prescrita cai de 432,75 para 216,38 kcal. Valores são apenas da fixture, não métricas de uso.
- **Esperado:** A prescrição mantém conteúdo e nutrientes da versão vinculada até nova decisão explícita do profissional.
- **Impacto usuário/produto:** Perda de confiança e divergência entre orientação prescrita e refeição preparada.
- **Código:** [src/Domain/Recipe/RecipeService.php:770](/Users/moliveira/Nutri_II/src/Domain/Recipe/RecipeService.php:770). Trecho: `JOIN recipes r ON r.id = rp.recipe_id`.
- **Recomendação:** Resolver listagem e detalhe pela versão prescrita e completar snapshots necessários; tratar versões legadas explicitamente.
- **Critérios de aceite:** Editar biblioteca não altera receita já prescrita; nova prescrição permite nova versão; detalhe corresponde ao prescription_id e autoriza o paciente; teste de ingredientes, rendimento, preparo e nutrientes.
- **Dependências / esforço / incertezas:** Backend + migração de snapshots incompletos + frontend; 4–8 dias, levantamento das versões legadas pode ampliar.
- **Screenshot:** [18-recipe-version-drift.png](evidence/18-recipe-version-drift.png).

### MOB-UX-003 — Duplo toque duplica registro alimentar

- **Perfil / jornada:** Paciente · Registrar consumo.
- **Tela / rota / componente:** Hoje → Diário; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha implementada · prevenção de erros.
- **Severidade:** P1 — Corrompe o histórico de uma tarefa frequente e não há correção pelo paciente no app.
- **Tipo de evidência:** Execução web + contagem persistida + código. **Confiança:** Alta.
- **Reprodução:** Dar duplo clique/toque em Registrar Consumo da mesma refeição; abrir Diário.
- **Atual:** Dois registros de Almoço com o mesmo segundo foram persistidos e exibidos.
- **Esperado:** Uma ação em andamento produz um registro; repetição legítima exige nova ação distinguível.
- **Impacto usuário/produto:** Aumenta adesão aparente e trabalho de revisão; paciente não consegue desfazer o engano.
- **Código:** [mobile/src/screens/PatientHomeScreen.tsx:251](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:251). Trecho: `const handleRegisterMeal = async (mealName: string) =>`.
- **Recomendação:** Bloquear a ação durante envio, usar idempotência estável por tentativa e oferecer correção controlada do registro.
- **Critérios de aceite:** Duplo toque e retry de resposta perdida geram um único registro; tentativas distintas podem ser registradas; sucesso e erro são visíveis também na web.
- **Dependências / esforço / incertezas:** Frontend + API/idempotência; 2–4 dias, decidir política de desfazer.
- **Screenshot:** [05-diary-double-tap.png](evidence/05-diary-double-tap.png).

### MOB-UX-004 — Teleconsulta é anunciada sem ação para entrar

- **Perfil / jornada:** Paciente · Acessar atendimento remoto.
- **Tela / rota / componente:** Hoje / próximo atendimento; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha implementada · jornada bloqueada.
- **Severidade:** P1 — Uma consulta com link existente não pode ser aberta pelo fluxo implementado.
- **Tipo de evidência:** Execução web + código. **Confiança:** Alta.
- **Reprodução:** Criar consulta fictícia futura com meeting_url; entrar em Hoje e procurar acesso à sala.
- **Atual:** A interface só diz Teleconsulta: Link disponível; não há botão nem link navegável. Não acessamos a sala externa fictícia.
- **Esperado:** Disponibilizar ação Entrar na consulta com horário, estados da sala e recuperação.
- **Impacto usuário/produto:** Paciente precisa buscar outro canal para cumprir o atendimento.
- **Código:** [mobile/src/screens/PatientHomeScreen.tsx:381](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:381). Trecho: `<Text style={styles.telehealthLink}>Teleconsulta: Link disponível</Text>`.
- **Recomendação:** Adicionar abertura segura do link, estado de disponibilidade e alternativa de contato.
- **Critérios de aceite:** URL autorizada abre por ação explícita; sala ausente/expirada informa próximo passo; retomada retorna ao app; testar Android/iOS e permissão de câmera separadamente.
- **Dependências / esforço / incertezas:** Frontend + provedor de teleconsulta; 1–3 dias para acesso, QA nativo adicional.
- **Screenshot:** [14-teleconsultation-no-link.png](evidence/14-teleconsultation-no-link.png).

### MOB-UX-005 — Chat promete presença e criptografia ponta a ponta sem suporte correspondente

- **Perfil / jornada:** Ambos · Comunicação e confiança.
- **Tela / rota / componente:** Chat paciente; cabeçalho e Chat profissional; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha implementada · informação enganosa.
- **Severidade:** P1 — A promessa influencia expectativas de resposta e exposição de informação sensível.
- **Tipo de evidência:** Execução web + código de interface e persistência. **Confiança:** Alta.
- **Reprodução:** Abrir Chat; simular falha 503; observar Online e texto de criptografia; inspecionar caminho sendMessage.
- **Atual:** Online é literal. O paciente lê Criptografia ponta-a-ponta; o servidor recebe e grava message em texto legível. Não foi localizado protocolo E2EE nesse caminho. HTTPS/controle de acesso não equivalem a E2EE.
- **Esperado:** Descrever apenas a proteção realmente implementada e a disponibilidade real, sem sugerir atendimento imediato.
- **Impacto usuário/produto:** Cria confiança indevida e pressão por resposta; compromete credibilidade do produto.
- **Código:** [mobile/src/screens/PatientHomeScreen.tsx:621](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:621). Trecho: `Criptografia ponta-a-ponta`.
- **Recomendação:** Retirar promessa não comprovada; definir copy com segurança e produto; usar presença real ou canal assíncrono com expectativas claras.
- **Critérios de aceite:** Nenhuma afirmação E2EE sem validação técnica; Online não aparece fixo em falha; informar que mensagens não substituem canal de urgência conforme política clínica.
- **Dependências / esforço / incertezas:** Produto + segurança + clínica; 1–2 dias para corrigir promessa; presença/E2EE têm escopo próprio não estimado.
- **Screenshot:** [07-chat-api-failure.png](evidence/07-chat-api-failure.png).

### MOB-UX-006 — Falha de leitura do chat não é apresentada ao usuário

- **Perfil / jornada:** Ambos · Receber mensagem e recuperar erro.
- **Tela / rota / componente:** Chat / loadChat e fetchActiveMessages; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha implementada · feedback de erro.
- **Severidade:** P1 — O usuário não sabe que a comunicação está desatualizada.
- **Tipo de evidência:** Execução web paciente + código de ambos os perfis. **Confiança:** Alta.
- **Reprodução:** Com mensagem carregada, devolver 503 em patient/chat/messages e reentrar no Chat.
- **Atual:** chatState.error é armazenado mas não renderizado; mensagens antigas e Online permanecem; profissional também captura falhas de leitura sem feedback.
- **Esperado:** Distinguir carregando, vazio, desatualizado e erro, com retry e estado do canal.
- **Impacto usuário/produto:** Usuário interpreta ausência de atualização como ausência de resposta; pode repetir mensagens.
- **Código:** [mobile/src/screens/PatientHomeScreen.tsx:104](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:104). Trecho: `setChatState(prev => ({ ...prev, loading: false, error: resourceError(err) }))`.
- **Recomendação:** Usar aviso por recurso no chat e recuperação contextual, mantendo histórico identificado como desatualizado.
- **Critérios de aceite:** 503 inicial e durante polling geram aviso; vazio só com GET bem-sucedido; restaurar API remove erro; rascunho permanece.
- **Dependências / esforço / incertezas:** Frontend dos dois perfis; 1–3 dias.
- **Screenshot:** [07-chat-api-failure.png](evidence/07-chat-api-failure.png).

### MOB-UX-007 — Falha da agenda profissional afirma que a agenda está livre

- **Perfil / jornada:** Nutricionista · Consultar atendimentos.
- **Tela / rota / componente:** Agenda / ProfessionalHomeScreen; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha implementada · falso estado vazio.
- **Severidade:** P1 — A decisão operacional pode ser baseada em uma consulta que não foi concluída.
- **Tipo de evidência:** Execução web + código. **Confiança:** Alta.
- **Reprodução:** Responder 503 em professional/dashboard; entrar com profissional.
- **Atual:** Banner diz falha; simultaneamente aparece Nenhum atendimento para hoje e Sua agenda está livre.
- **Esperado:** Não concluir que não existem consultas quando o resumo não foi carregado.
- **Impacto usuário/produto:** Pode provocar desorganização de atendimentos e falsa disponibilidade.
- **Código:** [mobile/src/screens/ProfessionalHomeScreen.tsx:322](/Users/moliveira/Nutri_II/mobile/src/screens/ProfessionalHomeScreen.tsx:322). Trecho: `Nenhum atendimento para hoje`.
- **Recomendação:** Estados independentes de recurso e timestamp de última atualização; vazio somente após sucesso.
- **Critérios de aceite:** 503 sem cache mostra erro recuperável, sem agenda livre; cache aparece como desatualizado; falha em pacientes não oculta estado da agenda.
- **Dependências / esforço / incertezas:** Frontend + contrato dos recursos; 1–3 dias.
- **Screenshot:** [13-professional-api-failure.png](evidence/13-professional-api-failure.png).

### MOB-UX-008 — Lista profissional omite pacientes acima do limite sem busca ou paginação

- **Perfil / jornada:** Nutricionista · Encontrar paciente e convidar.
- **Tela / rota / componente:** Pacientes / API professional/patients; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha implementada · encontrabilidade.
- **Severidade:** P1 — Parte dos pacientes cadastrados é inacessível na tarefa disponível pelo app.
- **Tipo de evidência:** Execução web + base isolada + código. **Confiança:** Alta.
- **Reprodução:** Usar clínica fictícia com 102 pacientes; atualizar Pacientes e procurar o paciente fora dos 100 primeiros.
- **Atual:** Cabeçalho conta 100; backend limita 100 por nome; não há busca, próxima página ou aviso de truncamento.
- **Esperado:** Permitir localizar qualquer paciente autorizado sem varrer centenas de cartões.
- **Impacto usuário/produto:** Impede convite/acesso rápido e comunica contagem incompleta como total.
- **Código:** [public/index.php:1034](/Users/moliveira/Nutri_II/public/index.php:1034). Trecho: `LIMIT 100`.
- **Recomendação:** Busca por nome/contato no servidor, paginação e total real; virtualizar apresentação conforme necessidade medida.
- **Critérios de aceite:** Paciente fora dos primeiros 100 encontrado por consulta; resultados e total claros; sem vazamento entre clínicas; lista permanece utilizável com 1.000 registros sintéticos.
- **Dependências / esforço / incertezas:** Backend + frontend; 3–5 dias, índice e desempenho a medir.
- **Screenshot:** [12-professional-100-limit.png](evidence/12-professional-100-limit.png).

### MOB-UX-009 — Retorno após recarregar exige novo login

- **Perfil / jornada:** Ambos · Voltar ao aplicativo.
- **Tela / rota / componente:** App / sessão em memória; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Fricção implementada · continuidade.
- **Severidade:** P2 — Não bloqueia acesso com credencial, mas adiciona esforço à rotina frequente.
- **Tipo de evidência:** Execução web + código; reabertura nativa não testada. **Confiança:** Alta para web; média para reabertura nativa.
- **Reprodução:** Autenticar e recarregar a versão web.
- **Atual:** Volta à entrada, sem sessão restaurada. Token e sessão vivem em variáveis/useState; não há armazenamento seguro/restauração identificados.
- **Esperado:** Política explícita de manter sessão, revalidar acesso e proteger retomada; não presumir permanência ilimitada.
- **Impacto usuário/produto:** Aumenta abandono e dependência da memória da senha; rascunhos locais se perdem em remount.
- **Código:** [mobile/App.tsx:13](/Users/moliveira/Nutri_II/mobile/App.tsx:13). Trecho: `useState<PatientAuthData | null>(null)`.
- **Recomendação:** Definir política de sessão por plataforma, armazenamento seguro e revalidação; preservar somente rascunhos adequados.
- **Critérios de aceite:** Retorno dentro do prazo segue política documentada; revogação/expiração volta ao login e limpa dados; logout revoga token; QA de kill/reopen no dispositivo.
- **Dependências / esforço / incertezas:** Produto + segurança + frontend; 3–5 dias; persistência web/nativa distinta.
- **Screenshot:** não disponível/aplicável; não inventado.

### MOB-UX-010 — Requisições do cliente não têm prazo nem cancelamento

- **Perfil / jornada:** Ambos · Carregar e recuperar dados.
- **Tela / rota / componente:** services/api.ts / request; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Risco identificado no código · resiliência.
- **Severidade:** P1 — Uma conexão que nunca termina pode prender acesso inicial e ações, sem recuperação dentro da tela.
- **Tipo de evidência:** Código; API lenta de 10 s executada, ausência de término não simulada. **Confiança:** Alta no código; média no impacto nativo.
- **Reprodução:** Inspecionar fetch sem signal; em dispositivo direto à API, atrasar indefinidamente home/login e observar recuperação.
- **Atual:** fetch não usa AbortController/timeout. Atraso de 10 s em receitas manteve loading, mas outras abas continuaram acessíveis e o pedido concluiu. O proxy Metro possui timeout de 15 s; o cliente direto não possui.
- **Esperado:** Prazo controlado, cancelamento adequado e retry com idempotência para operações de escrita.
- **Impacto usuário/produto:** Pode deixar spinner/botão ocupado até timeout de infraestrutura, com incerteza sobre gravação.
- **Código:** [mobile/src/services/api.ts:282](/Users/moliveira/Nutri_II/mobile/src/services/api.ts:282). Trecho: `response = await fetch(url, { ...options, headers })`.
- **Recomendação:** Implementar política de prazo por operação, erro de timeout distinto e cancelamento no ciclo de vida.
- **Critérios de aceite:** API pendente gera saída recuperável no prazo documentado; retry não duplica escrita; navegação não aplica resposta a sessão revogada.
- **Dependências / esforço / incertezas:** Cliente + contratos de escrita; 2–4 dias.
- **Screenshot:** não disponível/aplicável; não inventado.

### MOB-UX-011 — Primeiro acesso não apresenta contexto de privacidade previsto na jornada

- **Perfil / jornada:** Paciente · Onboarding e consentimentos.
- **Tela / rota / componente:** PatientOnboardingScreen / complete; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Lacuna do escopo documentado · confiança.
- **Severidade:** P2 — Blueprint inclui Consentimentos e Perfil/Privacidade, mas o fluxo de ativação não esclarece o tratamento de dados.
- **Tipo de evidência:** Execução parcial da UI + ativação por serviço em base isolada + código. **Confiança:** Alta para ausência; média para prioridade de governança.
- **Reprodução:** Conferir convite válido; ler cadastro; ativar conta na fixture e verificar manifestação persistida.
- **Atual:** Mostra clínica e profissional, mas não aviso de privacidade ou manifestação; fixture possui termo ativo e termina com zero patient_consents. Não concluir que todo tratamento exige consentimento nem emitir parecer jurídico.
- **Esperado:** Explicar finalidade, responsável e canais de direitos; quando a base legal exigir consentimento, coletar manifestação específica versionada.
- **Impacto usuário/produto:** Primeiro vínculo clínico começa sem explicação acessível sobre informações sensíveis.
- **Código:** [mobile/src/screens/PatientOnboardingScreen.tsx:32](/Users/moliveira/Nutri_II/mobile/src/screens/PatientOnboardingScreen.tsx:32). Trecho: `Etapa 2 de 2 · Cadastro e senha`.
- **Recomendação:** Alinhar base legal com responsável de privacidade e inserir informações/contextos necessários, sem checkbox genérico obrigatório para tudo.
- **Critérios de aceite:** Política acessível antes do envio; decisões aplicáveis versionadas e persistidas; recusa/retirada e erro não dão falsa confirmação.
- **Dependências / esforço / incertezas:** Produto + privacidade + backend; 3–6 dias após definição de política.
- **Screenshot:** [02-onboarding-341.png](evidence/02-onboarding-341.png).

### MOB-UX-012 — Diário não permite descrever o que foi consumido

- **Perfil / jornada:** Paciente · Registrar alimentação real.
- **Tela / rota / componente:** Hoje → Diário; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Lacuna funcional · acompanhamento nutricional.
- **Severidade:** P1 — A jornada existente registra apenas nome da refeição e texto automático, dificultando acompanhamento além do plano.
- **Tipo de evidência:** Execução web + código + contrato backend. **Confiança:** Alta.
- **Reprodução:** Registrar Almoço e procurar campos de descrição, quantidade, foto, observação ou refeição fora do plano.
- **Atual:** Um toque grava texto automático; não há formulário de consumo/foto/observação, nem criação a partir de Diário. Backend aceita description, hunger_level, satiety_level, symptoms e photo_path, mas isso não equivale a captura/upload funcional no app.
- **Esperado:** Capturar relato breve e opcional, distinguindo seguir plano de informar consumo diferente.
- **Impacto usuário/produto:** Diário fornece pouco contexto ao nutricionista e deixa o paciente sem registro quando não há plano.
- **Código:** [mobile/src/screens/PatientHomeScreen.tsx:253](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:253). Trecho: `description: `Consumo registrado via aplicativo móvel`.
- **Recomendação:** Introduzir registro rápido com confirmação e edição opcional; foto requer fluxo privado real de upload, não só campo de caminho.
- **Critérios de aceite:** Registrar refeição fora do plano, descrição e observações sem dados inventados; distinguir consumido/planejado; persistência e revisão profissional verificadas.
- **Dependências / esforço / incertezas:** Produto + frontend + backend/upload; 4–8 dias, foto pode ser fase separada.
- **Screenshot:** [05-diary-double-tap.png](evidence/05-diary-double-tap.png).

### MOB-UX-013 — Senha esquecida termina em contato genérico sem canal

- **Perfil / jornada:** Ambos, principalmente paciente · Recuperar acesso.
- **Tela / rota / componente:** Login / footerNote; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Lacuna funcional · recuperação.
- **Severidade:** P2 — Há alternativa assistida sugerida, mas não acionável e sem acesso autônomo.
- **Tipo de evidência:** Execução web + código. **Confiança:** Alta.
- **Reprodução:** Na entrada, procurar Esqueci minha senha e o canal indicado.
- **Atual:** Paciente recebe Contate seu nutricionista; não há ação/contato; profissional também não tem recuperação nesta tela. Convite de primeiro acesso não reseta credencial existente.
- **Esperado:** Recuperação segura e clara ou canal assistido acionável com política explícita.
- **Impacto usuário/produto:** Usuário depende de meio externo que pode não conhecer, dificultando retorno.
- **Código:** [mobile/App.tsx:184](/Users/moliveira/Nutri_II/mobile/App.tsx:184). Trecho: `Ainda não recebeu um convite ou esqueceu a senha? Contate seu nutricionista.`.
- **Recomendação:** Definir recuperação autenticada por canal validado e alternativa assistida identificada, sem reutilizar convite inicial.
- **Critérios de aceite:** Senha esquecida tem ação encontrável; resposta não enumera contas; código expira; paciente ativado não depende de novo onboarding.
- **Dependências / esforço / incertezas:** Autenticação + comunicação + produto; 3–6 dias.
- **Screenshot:** [01-login-341.png](evidence/01-login-341.png).

### MOB-UX-014 — Tela Hoje coloca atalhos antes da tarefa alimentar do dia

- **Perfil / jornada:** Paciente · Identificar próxima refeição.
- **Tela / rota / componente:** Hoje / ordem dos cartões; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Avaliação heurística + hipótese de comportamento.
- **Severidade:** P2 — A tarefa diária exige rolagem e comparação manual, especialmente em tela pequena.
- **Tipo de evidência:** Execução visual + código; impacto em tempo/abandono exige usuários. **Confiança:** Alta para ordem visual; média para efeito de uso.
- **Reprodução:** Entrar em 320/341/375 px e procurar próxima refeição.
- **Atual:** Receitas, chat, atendimento e hidratação antecedem Prescrição do Dia; todas as refeições são listadas sem seleção por horário/consumo.
- **Esperado:** Próxima ação clínica relevante compreensível, sem ocultar serviços secundários.
- **Impacto usuário/produto:** Aumenta esforço de busca; magnitude e prioridade real dependem dos objetivos do paciente.
- **Código:** [mobile/src/screens/PatientHomeScreen.tsx:454](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:454). Trecho: `Prescrição do Dia`.
- **Recomendação:** Testar hierarquia centrada na próxima refeição/ação, com atalhos secundários compactos; permitir desvio de rotina sem julgamento.
- **Critérios de aceite:** Em teste moderado, identificar próxima refeição sem ajuda; horário/fuso e plano ausente têm comportamento definido; ações secundárias permanecem encontráveis.
- **Dependências / esforço / incertezas:** Design + pesquisa + frontend; 2–4 dias de implementação depois de validar alternativa.
- **Screenshot:** [17-patient-home-320.png](evidence/17-patient-home-320.png).

### MOB-UX-015 — Plano vigente não identifica publicação ou versão para o paciente

- **Perfil / jornada:** Paciente · Confiar no plano atual.
- **Tela / rota / componente:** Plano / activePlan; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha de clareza · continuidade clínica.
- **Severidade:** P2 — Publicado é mostrado em inglês; atualização local da API pode ser confundida com atualização da prescrição.
- **Tipo de evidência:** Execução web + código e API. **Confiança:** Alta.
- **Reprodução:** Abrir Plano e comparar Última atualização do resumo com dados da prescrição.
- **Atual:** Mostra Status: published, sem data de publicação e versão; cabeçalho de atualização indica fetch, não autoria da mudança. API home lê tabelas vivas, não o snapshot publicado; não demonstramos edição posterior de plano publicado por rota autorizada.
- **Esperado:** Identificar profissional, versão/publicação e distinguir sincronização da data da orientação.
- **Impacto usuário/produto:** Dificulta saber se o paciente segue o plano vigente e relatar divergências ao profissional.
- **Código:** [mobile/src/screens/PatientHomeScreen.tsx:534](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:534). Trecho: `(Status: {activePlan.status})`.
- **Recomendação:** Usar metadados clínicos explícitos e contrato de plano publicado; revisar fonte imutável sem afirmar falha de edição não reproduzida.
- **Critérios de aceite:** Plano exibe versão/data coerentes com publicação; atualizar API não muda essa data; estados traduzidos; plano revogado não permanece como vigente.
- **Dependências / esforço / incertezas:** Backend + frontend; 2–4 dias; levantamento de compatibilidade de versões.
- **Screenshot:** [04-plan-recipe-341.png](evidence/04-plan-recipe-341.png).

### MOB-UX-016 — Porção prescrita exige cálculo manual a partir da receita completa

- **Perfil / jornada:** Paciente · Preparar receita.
- **Tela / rota / componente:** Plano / detalhes de receita; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha de interpretação · medidas de nutrição.
- **Severidade:** P2 — Há rótulo de receita completa, mas a pessoa ainda precisa converter o rendimento para o consumo recomendado.
- **Tipo de evidência:** Execução web + código. **Confiança:** Alta para apresentação; média para erro humano.
- **Reprodução:** Abrir receita de 2 porções prescrita em 1,5 porção; comparar ingredientes e porção.
- **Atual:** Mostra 1,5 porção(ões) e ingredientes de 2 porções; não calcula ingredientes da porção prescrita. Alergênicos estruturados não são renderizados pelo tipo/componente; na fixture há alerta genérico no resumo, não lista contextual.
- **Esperado:** Distinguir preparo do lote, medida por porção e quantidade prescrita; alertas profissionais relevantes acessíveis.
- **Impacto usuário/produto:** Pode provocar preparo/consumo incorreto por confundir quantidade do lote com quantidade recomendada.
- **Código:** [mobile/src/screens/PatientHomeScreen.tsx:523](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:523). Trecho: `Ingredientes • receita completa`.
- **Recomendação:** Mostrar rendimento do preparo e equivalente autorizado da porção; preservar orientações e sinalização de alergênicos sem criar aprovação inexistente.
- **Critérios de aceite:** Para 1,5/2 porções, equivalência é consistente; arredondamento e unidade definidos; receita completa não é confundida com consumo; verificar com usuários.
- **Dependências / esforço / incertezas:** Nutrição + dados + frontend; 3–5 dias, depende de resolver MOB-UX-002.
- **Screenshot:** [04-plan-recipe-341.png](evidence/04-plan-recipe-341.png).

### MOB-UX-017 — Contrastes de controles e mensagens do chat não atendem ao critério de texto

- **Perfil / jornada:** Ambos · Escrever e ler mensagens.
- **Tela / rota / componente:** Chat / estilos locais; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha medida no código · acessibilidade.
- **Severidade:** P2 — Dificulta leitura de texto pequeno para pessoas com baixa visão.
- **Tipo de evidência:** Cálculo de luminância dos hex do código; sem TalkBack nativo. **Confiança:** Alta.
- **Reprodução:** Comparar branco sobre #059669 nas bolhas/envio profissional e placeholder #94A3B8 sobre branco.
- **Atual:** Contrastes calculados: 3,768:1 e 2,564:1; abaixo de 4,5:1 para texto normal. Navy/branco=14,634; cobre #B94F1F/branco=4,999; muted/branco=4,975.
- **Esperado:** Textos normais atingem 4,5:1 e mantêm significado sem depender de cor.
- **Impacto usuário/produto:** Prejudica compreensão de orientações e descoberta do campo; pode excluir usuários.
- **Código:** [mobile/src/screens/ProfessionalHomeScreen.tsx:527](/Users/moliveira/Nutri_II/mobile/src/screens/ProfessionalHomeScreen.tsx:527). Trecho: `backgroundColor: isProf ? '#059669' : '#F1F5F9'`.
- **Recomendação:** Ajustar tokens semânticos do chat respeitando a marca e validar todas as combinações efetivas, inclusive transparência.
- **Critérios de aceite:** Medição com cores computadas comprova AA nas combinações; controles de estado e foco são identificáveis; não declarar certificação de acessibilidade geral.
- **Dependências / esforço / incertezas:** Design system + frontend; 1–2 dias, QA ampliado adicional.
- **Screenshot:** [11-professional-chat.png](evidence/11-professional-chat.png).

### MOB-UX-018 — Compositor profissional não expõe nome e papel acessíveis suficientes

- **Perfil / jornada:** Nutricionista · Responder paciente.
- **Tela / rota / componente:** Chat / TextInput e envio profissional; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha observada na árvore web · acessibilidade.
- **Severidade:** P2 — Tecnologia assistiva tem pouca informação para identificar tarefa e controle.
- **Tipo de evidência:** Árvore acessível web + código; TalkBack não executado. **Confiança:** Alta para web/código; média para efeito nativo.
- **Reprodução:** Abrir conversa profissional e navegar pela árvore acessível até campo e Enviar.
- **Atual:** Campo aparece como text entry area sem nome; envio aparece como texto/container, sem accessibilityRole button no código. Paciente possui labels/role explícitos.
- **Esperado:** Campo e ação nomeados e operáveis na ordem adequada por tecnologia assistiva.
- **Impacto usuário/produto:** Aumenta esforço e pode impedir resposta autônoma com leitor de tela; impacto nativo precisa teste.
- **Código:** [mobile/src/screens/ProfessionalHomeScreen.tsx:547](/Users/moliveira/Nutri_II/mobile/src/screens/ProfessionalHomeScreen.tsx:547). Trecho: `<TextInput`.
- **Recomendação:** Uniformizar semântica dos compositores; anunciar envio/erro; manter foco no rascunho sem interromper leitura.
- **Critérios de aceite:** Árvore web e TalkBack/VoiceOver identificam destinatário, campo e Enviar; foco/estado disabled/busy corretos; mensagem de erro anunciada.
- **Dependências / esforço / incertezas:** Frontend + QA assistivo real; 1–3 dias.
- **Screenshot:** [11-professional-chat.png](evidence/11-professional-chat.png).

### MOB-UX-019 — Datas, estados e origem clínica exigem interpretação ou afirmam além do dado

- **Perfil / jornada:** Ambos · Entender atendimento, evolução e conversa.
- **Tela / rota / componente:** Hoje, Plano, Evolução, Pacientes, Perfil, Chat; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Falha de linguagem/contexto.
- **Severidade:** P2 — Informações operacionais e clínicas perdem consistência temporal e semântica.
- **Tipo de evidência:** Execução web + código. **Confiança:** Alta.
- **Reprodução:** Ler consulta em ISO, Status active/published e papel nutritionist; comparar mesma mensagem recém-enviada e após recarregamento do chat.
- **Atual:** Mensagem apareceu 19:01 no retorno de envio e 22:01 na leitura persistida; substring não normaliza fuso. Evolução mostra 24.2 e afirma Aferido presencialmente, embora o contrato marque origem profissional sem modalidade.
- **Esperado:** Datas/fuso consistentes, números pt-BR e origem limitada ao que foi registrado.
- **Impacto usuário/produto:** Dificulta acompanhamento da cronologia e pode atribuir modalidade clínica indevida.
- **Código:** [mobile/src/screens/PatientHomeScreen.tsx:729](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:729). Trecho: `Aferido presencialmente pelo profissional`.
- **Recomendação:** Contrato temporal ISO com offset; formatadores compartilhados; mapas de rótulos; origem clínica sem modalidade presumida.
- **Critérios de aceite:** Uma mensagem mantém horário ao atualizar; consulta em pt-BR com fuso definido; published/active/nutritionist não vazam ao usuário; modalidade só aparece se registrada.
- **Dependências / esforço / incertezas:** Backend + frontend + conteúdo; 2–5 dias, legado de timestamps exige análise.
- **Screenshot:** [15-evolution.png](evidence/15-evolution.png).

### MOB-UX-020 — Perfil e Privacidade não oferece ações correspondentes ao nome

- **Perfil / jornada:** Ambos · Atualizar cadastro, preferências e exercer direitos.
- **Tela / rota / componente:** Perfil / PatientHomeScreen e ProfessionalHomeScreen; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Lacuna documentada · arquitetura de informação.
- **Severidade:** P2 — Rótulo promete controles que não são encontráveis no app; sair funciona.
- **Tipo de evidência:** Execução web + código + blueprint. **Confiança:** Alta.
- **Reprodução:** Abrir Perfil em ambos os perfis e procurar atualização cadastral, preferências, privacidade ou ajuda.
- **Atual:** Paciente vê nome, e-mail e logout; profissional nome/e-mail/papel/clínica/logout. Não há edição, gestão de privacidade ou ajuda acionável.
- **Esperado:** Nomes aderentes ao escopo e caminho claro para ações disponíveis no produto.
- **Impacto usuário/produto:** Frustra expectativa e aumenta dependência de contato externo; ausência de todas as ações não é automaticamente bug.
- **Código:** [mobile/src/screens/PatientHomeScreen.tsx:755](/Users/moliveira/Nutri_II/mobile/src/screens/PatientHomeScreen.tsx:755). Trecho: `Perfil & Privacidade`.
- **Recomendação:** Definir mínimo de conta/privacidade e suporte, ou ajustar nome e fornecer acesso seguro ao canal/portal correspondente.
- **Critérios de aceite:** Opções prometidas existem e possuem backend; suporte encontrável; alterações refletem dados persistidos; dados sensíveis não são expostos fora da sessão.
- **Dependências / esforço / incertezas:** Produto + privacidade + frontend/backend; 3–7 dias conforme escopo mínimo.
- **Screenshot:** não disponível/aplicável; não inventado.

### MOB-UX-021 — Check-in documentado não tem entrada no aplicativo

- **Perfil / jornada:** Paciente · Acompanhar hábitos e adesão.
- **Tela / rota / componente:** Hoje e Evolução / API disponível; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Lacuna em relação ao blueprint.
- **Severidade:** P2 — Reduz cobertura do acompanhamento previsto, sem quebrar hidratação e leitura que existem.
- **Tipo de evidência:** Código + referência de escopo; jornada não executável. **Confiança:** Alta para lacuna; média para prioridade.
- **Reprodução:** Buscar ação de check-in nas telas paciente e mapear chamadas ApiService.
- **Atual:** Controller possui submitCheckin e backend de engajamento; não há método/controle correspondente no app. Evolução & Metas apresenta medições, sem definir metas/hábitos além da água prescrita.
- **Esperado:** Escopo de check-in mínimo explícito ou promessa ajustada antes do piloto.
- **Impacto usuário/produto:** Profissional recebe menos contexto longitudinal; paciente não consegue cumprir essa parte da jornada documentada.
- **Código:** [src/Application/Api/PatientApiController.php:89](/Users/moliveira/Nutri_II/src/Application/Api/PatientApiController.php:89). Trecho: `public function submitCheckin`.
- **Recomendação:** Validar check-in curto com pesquisa e equipe clínica; integrar somente campos necessários e feedback acolhedor.
- **Critérios de aceite:** Entrada encontrável, resultados persistidos e visíveis ao profissional; resposta não confundida com medição clínica; plano ausente e offline têm orientação.
- **Dependências / esforço / incertezas:** Produto/clínica + frontend + integração; 4–7 dias após definição; opcional para piloto restrito.
- **Screenshot:** não disponível/aplicável; não inventado.

### MOB-UX-022 — Referências visuais conflitantes e tokens dispersos geram inconsistência

- **Perfil / jornada:** Ambos · Reconhecer marca e ações.
- **Tela / rota / componente:** Login, Hoje, Chat e componentes; rota cliente `/` com estado local (endpoints citados quando aplicável).
- **Categoria:** Refinamento de consistência · governança.
- **Severidade:** P3 — Não bloqueia tarefas; aumenta custo de manutenção e inconsistência do significado das cores.
- **Tipo de evidência:** Código + documentos + inspeção visual. **Confiança:** Alta.
- **Reprodução:** Comparar blueprint Clinical Botanical, proposta Premium, CSS e tokens mobile; navegar do login ao chat.
- **Atual:** Blueprint usa Forest/Clyo Green; documento Premium é proposta navy/cobre; CSS chama foundations oficiais e usa navy diferente da proposta. Tokens mobile acompanham CSS, mas PatientHome repete navy/sand antigos e Chat introduz verde como CTA. Inter está documentada mas não é carregada no app; fonte de sistema não é defeito por si.
- **Esperado:** Uma referência ratificada e componentes que usam cor e tipografia com significado consistente.
- **Impacto usuário/produto:** Ações de leitura ficam vermelhas por reutilizar estilo de retry; verde sugere Online fixo; divergência não deve ser resolvida por gosto pessoal.
- **Código:** [mobile/src/theme/tokens.ts:1](/Users/moliveira/Nutri_II/mobile/src/theme/tokens.ts:1). Trecho: `Mirrors the ratified foundations`.
- **Recomendação:** Registrar decisão de design system, consolidar tokens e semântica; manter navy/cobre atual até ratificação explícita, sem redesenho arbitrário.
- **Critérios de aceite:** Documento aponta fonte de verdade e versões; CTAs não reutilizam cor de erro; tokens compartilhados aplicados; contraste e comportamento prevalecem sobre ornamentação.
- **Dependências / esforço / incertezas:** Design/branding + frontend; 2–4 dias, depende de ratificação.
- **Screenshot:** não disponível/aplicável; não inventado.

## E. Scorecard heurístico

Notas não equivalem a satisfação, sucesso medido, maturidade clínica nem conformidade. Critério: 0 = tarefa sem caminho utilizável; 50 = caminho parcial com atritos/falhas relevantes; 100 = caminho claro e recuperável nas condições cobertas. As notas agregam paciente e companheiro profissional, sem exigir paridade com desktop. **Não há nota geral**: cobertura nativa ausente tornaria o agregado enganoso.

| Dimensão | Nota /100 | Evidência / justificativa | Cobertura |
|---|---:|---|---|
| Primeiro uso | 60 | Marca, labels e vínculo do convite claros; senha esquecida/privacidade sem caminho; conclusão onboarding UI não validada | Login ambos + etapas do convite + serviço |
| Navegação e encontrabilidade | 55 | Abas explícitas, mas receita precede plano, seis destinos pequenos; profissional perde pacientes após 100 | Abas web; voltar/gestos nativos não avaliados |
| Execução das tarefas principais | 45 | Água/consulta/feedback/chat persistem; substituição incompleta, receita muda, diário duplica, sala não abre | Happy paths web e falhas específicas |
| Clareza das informações | 42 | Meta ausente correta, mas versão/status/tempos/origem e promessa de segurança inadequados | Texto, respostas API e datas fictícias |
| Consistência visual | 68 | Logo e marca consistentes no acesso; tokens/paletas locais, CTA vermelho de leitura e chat destoam em significado | Inspeção 320/341/375 web; sem fontes ampliadas |
| Acessibilidade | 55 | Labels paciente e alvos web razoáveis; contrastes reprovados e semântica profissional incompleta | Pares de cor + árvore web; TalkBack/VoiceOver **não avaliados** |
| Feedback e recuperação | 42 | Retry de receita/diário/evolução e expiração claros; chat silencioso, agenda falso-vazia, duplicação sem desfazer | 503, sessão expirada, dados vazios e duplo toque |
| Experiência em condições reais | 35 | Estado parcial contraditório e sessão volátil; 10 s tolerados em receitas; sem timeout de cliente | Apenas falhas/atraso sintéticos; rede celular/offline/kill/permissões **não avaliados** |
| Adequação à rotina de nutrição | 43 | Leitura básica e canal profissional reais; doses alternativas, contexto do consumo, versão e próxima ação frágeis | Uma prescrição fictícia, receita e avaliação; entendimento humano não medido |

## F. Dez correções prioritárias

Ordem combina risco de interpretação clínica, tarefa bloqueada e alcance. Não corresponde a ordem puramente estética nem ao número do finding.

| Ordem | IDs | Correção e benefício | Gate de liberação |
|---|---|---|---|
| 1 | MOB-UX-002 | Congelar receita na versão prescrita; manter orientação confiável | Alterar biblioteca não muda prescrição existente |
| 2 | MOB-UX-001 | Preservar doses e orientações em Hoje/Plano | Alternativa de 150 g e todas as notas visíveis |
| 3 | MOB-UX-003 | Idempotência e bloqueio de registro alimentar | Duplo toque/retry produz uma única gravação |
| 4 | MOB-UX-005 | Corrigir promessa de E2EE/presença | Informação coincide com capacidade comprovada |
| 5 | MOB-UX-006, 007 | Estados de erro por recurso e ausência de falsos vazios | 503 nunca significa sem mensagens/agenda livre |
| 6 | MOB-UX-004 | Tornar acesso à sala acionável | Consulta com link pode ser aberta de forma explícita |
| 7 | MOB-UX-010 | Prazo/cancelamento e escrita segura em conexão instável | Pedido pendente sai por timeout recuperável documentado |
| 8 | MOB-UX-008 | Buscar e paginar pacientes | Paciente após o 100º pode ser encontrado |
| 9 | MOB-UX-012, 016 | Registro de consumo contextual e interpretação de porções | Paciente registra desvio do plano e entende lote/dose |
| 10 | MOB-UX-017, 018 | Contraste e semântica do compositor | Leitura/entrada/envio acessíveis em web e QA assistivo real |

MOB-UX-011 (privacidade/base legal) é **gate de governança paralelo**, não deve aguardar fila visual. Prioridade depende das finalidades e políticas ratificadas. MOB-UX-013 exige alternativa assistida real para piloto mesmo se autosserviço for postergado. Esta ordem deve ser ajustada pela definição do piloto, sem alterar severidades para acomodar cronograma.

## G. Plano de melhorias

### Bloqueios para piloto clínico amplo

1. Fidelidade de prescrição: MOB-UX-001/002; vínculo, origem e conteúdo testados em versões antigas/novas e múltiplas clínicas.
2. Registro confiável: MOB-UX-003; escrita idempotente com resposta perdida, não apenas botão disabled.
3. Confiança e recuperação: MOB-UX-005/006/007/010; mensagens verdadeiras e estados independentes.
4. Se teleconsulta integrar o piloto, MOB-UX-004 é bloqueio dessa jornada. Se app profissional convidar clínicas maiores, MOB-UX-008 bloqueia esse uso.
5. Revisão de privacidade (011) e canal de recuperação (013), mais QA nativo de leitores de tela, teclado, safe areas, fontes grandes e retomada. Não aprovar por aparência da versão web.

### Fricções seguintes

- MOB-UX-009: continuidade de sessão com política segura.
- MOB-UX-012/014/015/016: consumo real, próxima refeição, publicação clara e dose/lote compreensíveis.
- MOB-UX-019: linguagem, fuso e origem clínica sem inferência.
- MOB-UX-020/021: mínimo de conta/privacidade/suporte e check-in conforme escopo ratificado.
- Convite: selecionar/copiar/compartilhar código por canal privado com cuidado, indicar acesso já ativado e recuperação; não gerar automaticamente novo convite em retry de dashboard.
- Listas: históricos e conversas com paginação/virtualização quando volume e desempenho justificarem; polling suspenso/adaptado fora de foco (código atual mantém intervalo da aba, não trata AppState).

### Refinamentos vinculados a problemas

- MOB-UX-022: fonte única de tokens, cor semântica e componentes reutilizáveis; não trocar marca por gosto.
- Abas de 11 px e seis destinos: prototipar legibilidade/alcançabilidade; não afirmar que precisam de ícones ou cinco abas sem testar tarefas.
- Estados vazios: explicar quem disponibiliza plano/avaliação e oferecer próxima ação real; Diário não limitar linguagem a hoje se exibe histórico de todos os dias.
- Ajuda contextual: explicar porção prescrita versus receita completa e registro real versus plano; evitar culpa por desvio de rotina.

### Oportunidades que não são defeitos automaticamente

Lista de compras, favoritos, documentos compartilhados, push, agenda completa, anexos/fotos, edição de perfil e criação de prescrições pelo profissional precisam de definição de escopo por canal. Existem serviços/endpoints em alguns casos; **não há implementação cliente comprovada**. Separar backlog de expansão da correção das tarefas já anunciadas. Roteamento de push/deep links precisa projeto específico e teste no dispositivo, não um botão fictício.

## H. Plano de validação com usuários

**Objetivo:** verificar entendimento e esforço em tarefas clínicas comuns; a auditoria atual não informa taxa de satisfação. Fazer duas rodadas moderadas, inicialmente com protótipo/fixture sem dados reais. Sugestão de recrutamento exploratório: 6–8 pacientes com diversidade de idade, experiência digital, uso de medidas caseiras e necessidades de acessibilidade; 4–6 nutricionistas com clínica pequena e carteira >100; incluir Android de entrada e iPhone. Quantidades são proposta, não amostra já realizada nem poder estatístico.

| Tarefa sem instruir o caminho | Hipótese / métrica a coletar | Critério proposto antes do teste |
|---|---|---|
| Recebeu convite: vincule-se e diga com quem seus dados serão compartilhados | Sucesso sem ajuda, erros de código, dúvidas, compreensão de vínculo/privacidade | ≥80% concluem sem ajuda; ninguém associa a clínica errada; compreensão de privacidade registrada qualitativamente |
| Esqueceu senha: retome acompanhamento | Descoberta do caminho, dependência de canal externo, tempo/abandono | Todos encontram alternativa real; tempo e satisfação SEQ coletados, sem meta inventada a priori |
| É hora da refeição: diga o que e quanto consumir | Tempo para localizar, leitura correta, confiança 1–7 | Todos interpretam a dose corretamente; zero erro crítico de unidade/porção |
| Falta alimento principal: escolha a alternativa prescrita | Dose/unidade/orientação compreendidas | Zero troca usando quantidade incorreta por informação omitida |
| Prepare sua receita de 1,5 porção | Compreensão lote × consumo e versão | Zero confusão crítica; ≥80% explicam diferença sem ajuda |
| Você comeu algo diferente: registre e depois corrija um toque duplicado | Etapas, tempo, abandono, erros persistidos | Consumo fiel persistido; duplicação impedida; correção encontrável |
| Confirme atendimento e entre na sala | Sucesso, canal alternativo, permissão negada | Tarefa concluída em Android/iOS; negação tem recuperação clara |
| Pergunte ao nutricionista e reconheça falha de conexão | Entendimento de envio/recebimento/disponibilidade | Ninguém interpreta Online fixo como resposta garantida; erro e retry identificados |
| Profissional: encontre paciente fora da primeira página e revise diário | Tempo, busca, feedback persistido, rascunho | Paciente encontrado sem rolar toda carteira; feedback entregue; rascunho não aplicado a outro paciente |
| Abra com fonte 200% e TalkBack/VoiceOver | Leitura, foco, alvos, teclado, ordem | Tarefas de login/plano/chat concluídas sem perda de função |

Registrar conclusão por tarefa (com/sem ajuda), erro crítico/não crítico, tempo mediano, intervenções, abandonos, SEQ pós-tarefa e SUS ao final apenas se instrumento usado corretamente. Não usar SUS para provar segurança clínica. Metas acima são **propostas** a ratificar com clínica/produto, não resultados. Fazer teste de cinco a sete dias de rotina após remover bloqueios para observar retomada, variabilidade de horário, conexão e registro real; base legal, consentimento de pesquisa e canal de suporte devem ser definidos antes do estudo.

QA técnico complementar: Android/iOS reais; cold start/kill/background; fontes do sistema; keyboardAvoiding; back gesture; sessão expirada durante envio; API 4xx/5xx/timeout/body inválido; reenvio com mesma idempotency key; plano revogado/atualizado; receita legada; 1.000 pacientes e históricos; nenhum dado de outro paciente/tenant. Mocks de estado devem ser identificados e removidos do produto final.

## Respostas finais

- **O usuário entende o primeiro acesso?** O convite e a clínica são explicados, mas recuperação/privacidade/próxima ação são incompletas. Não houve usuário real nem conclusão integral do cadastro pela UI, portanto não afirmar compreensão comprovada.
- **Consegue concluir tarefas principais sem ajuda?** Água, consulta, feedback e conversa concluíram na web por um avaliador; substituição, versão da receita e teleconsulta apresentam limitações concretas. Autonomia de usuários ainda não foi medida.
- **Facilita a rotina nutricional?** Parcialmente: núcleo conectado útil, com falhas de fidelidade e captura de contexto que limitam acompanhamento. Benefício longitudinal exige uso real.
- **O que impede um piloto?** Prescrição inconsistente, diário duplicável, promessas de segurança/presença e erros escondidos; teleconsulta/limite de pacientes conforme escopo. Governança de privacidade e QA nativo continuam gates pendentes. Piloto exploratório fictício moderado é viável.
- **O que depende de testes reais?** Encontrabilidade, tempo/esforço, compreensão de porções, linguagem acolhedora, fonte ampliada, TalkBack, teclado/gestos/safe areas, retomada, rede celular, notificações e retenção. Nenhum desses resultados deve ser inferido do build ou da aparência.

## Evidências e rastreabilidade

- [Findings estruturados](findings.json) — IDs e campos para triagem.
- [Observações API/SQLite](evidence/api-observations.json) — doses, notas, versão e nutrientes da fixture, contagens e persistência.
- [DOM do plano](evidence/plan-dom.txt) e [DOM do chat](evidence/chat-dom.txt).
- [Manifesto do código avaliado](evidence/source-manifest.json) — hashes de arquivos relevantes; o código pode continuar mudando fora desta auditoria.
- Screenshots: 01–18, com 341/375/320 CSS conforme protocolo; imagens não demonstram TalkBack ou emulador nativo.

