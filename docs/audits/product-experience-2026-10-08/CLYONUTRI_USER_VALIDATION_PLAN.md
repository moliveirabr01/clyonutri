# ClyoNutri II — Plano de validação com usuários

Data de avaliação: **08/10/2026 (America/Sao_Paulo)**. Fontes: árvore de trabalho existente, navegação real em `127.0.0.1:8080` e instância isolada `localhost:8081`, banco SQLite novo com as 21 migrações. Nenhuma alteração de código da aplicação ou de registros no Jira.

## Objetivo e desenho

Verificar se profissionais executam tarefas clínicas com clareza, segurança e esforço aceitável, e se pacientes entendem orientações sem culpa ou dependência de suporte. Nenhum teste com usuários foi realizado nesta auditoria; o documento é protocolo proposto.

Rodada formativa: 12 nutricionistas, quatro recém-formados, quatro autônomos experientes e quatro responsáveis por clínicas. Separar uso clínico de permissões administrativas. Incluir diversidade de idade, familiaridade digital, dispositivos e necessidades de acessibilidade. Amostra qualitativa não estima taxas populacionais com precisão. Segunda rodada após correções com novos participantes e algumas tarefas equivalentes.

Paciente: seis a oito participantes com diferentes graus de familiaridade digital, incluindo baixa visão ou uso de tecnologia assistiva quando possível. Dados inteiramente sintéticos e ambiente isolado. Participação voluntária, consentimento de pesquisa e minimização de gravações; não utilizar dados de saúde pessoais. A condução jurídica/ética de pesquisa é responsabilidade da organização.

## Tarefas do nutricionista

| Tarefa e cenário | Sucesso observável | Risco/erro a observar |
|---|---|---|
| Cadastrar primeiro paciente | Pessoa correta persistida, opcionais ausentes permanecem ausentes | Nascimento/sexo presumidos; confusão gênero/sexo |
| Realizar anamnese com interrupção | Rascunho recuperado e finalização explícita | Perda de texto; alergia desconhecida tratada como negativa |
| Consultar histórico anterior | Abre versão pedida e identifica autor/data | Consulta da última versão sem perceber |
| Criar plano com refeições e substituição | Publica versão correta para paciente correto | Unidade/porção errada; alimento inacessível |
| Alterar prescrição publicada | Nova versão com linhagem e anterior preservada | Editar retroativamente ou perder substituições |
| Registrar evolução | Registro persistido com conteúdo/autoria corretos | Conclusão sem feedback ou duplicação |
| Agendar e reagendar consulta | Horário/estado corretos sem conflito indevido | Fuso, sobreposição e cancelamento involuntário |
| Enviar orientação | Mensagem na conversa correta e status inteligível | Canal não descoberto ou destinatário incorreto |
| Identificar quem precisa de atenção | Encontra pendência real e resolve documento certo | Indicador fictício ou link genérico |
| Gerir equipe e recuperar acesso | Entende autorização e retoma sessão por fluxo válido | Acesso persiste após revogação; suporte é único caminho |

Usar tarefas narradas sem nomear botão/rota. Observar primeiro sem assistência. Se participante travar, registrar tempo e assistência antes de intervir. Não pedir ao usuário que trate uma função quebrada como funcionando.

## Tarefas do paciente

Entrar com credencial forte; encontrar refeição e porção; entender substituição; registrar consumo e água; identificar origem da meta; consultar evolução e distinguir autorrelato de medição clínica; encontrar orientação; confirmar consulta. Receitas/lista de compras/mensagens/teleconsulta somente após UI executável correspondente estar disponível. Registrar ausência de funcionalidade como cobertura pendente, sem telas simuladas.

## Métricas e coleta

- **Conclusão:** sucesso sem ajuda, com ajuda, falha ou desistência; validar também persistência e destinatário.
- **Tempo:** início após leitura do cenário até resultado confirmado; separar tempo de sistema, interrupção e pesquisa. Medir mediana e distribuição por tarefa, sem esconder falhas na média.
- **Erros:** quantidade, recuperabilidade e gravidade clínica; qualquer seleção de paciente ou versão errada é relevante mesmo se tarefa termina.
- **Assistência:** número e tipo de intervenções do moderador.
- **SEQ:** 1–7 após cada tarefa, acompanhado de justificativa curta.
- **SUS:** questionário padrão após conjunto de tarefas; não alterar itens e chamar de SUS. Interpretar amostra pequena como indicativa.
- **Confiança e qualidade:** escala de 1–7 com motivo e trecho da experiência; sem converter automaticamente em comportamento.
- **Recomendação:** pergunta declarativa e, posteriormente, evento real de indicação com consentimento. Não chamar intenção declarada de indicação comprovada.

## Hipóteses e metas propostas

Hipóteses: contexto persistente reduz retornos; edição de porção reduz retrabalho; estados de salvamento aumentam confiança; busca melhora localização. Não há baseline ainda. Após rodada inicial, estabelecer meta por tarefa e validar com produto/clínica.

Metas iniciais de gate sugeridas: nenhuma falha de integridade, destinatário ou versão; conclusão sem assistência de pelo menos 90% em tarefas centrais numa rodada confirmatória adequadamente dimensionada; SEQ mediano ≥5/7. Esses valores são critérios propostos, não resultados atuais nem referência estatística universal. Tempos alvo e SUS devem ser definidos após baseline, sem prometer redução percentual inventada.

## Sessão e análise

Sessão de 60–75 minutos como planejamento, ajustável à fadiga; conversa inicial, tarefas, SEQ, SUS e entrevista final. Alternar ordem de tarefas quando não dependentes. Para comparar redesign, usar cenários equivalentes e controlar aprendizado; não concluir causalidade de antes/depois com participantes e dados distintos sem desenho adequado.

Registrar problema com vídeo/tempo apenas quando autorizado, evidência, perfil, severidade e relação com PX. Relatório separa observado, fala do participante, interpretação e hipótese. Revisar com designer, engenharia e nutricionista; priorizar integridade acima de preferência estética. Defeitos graves interrompem tarefa e entram em correção antes de novo teste.

## Acessibilidade e operação

Teste dedicado com teclado e leitor de tela, reflow/zoom, contraste real e foco em modais; dispositivo físico para paciente e cenário de conexão lenta/perda de rede. Vídeo: profissional/paciente, dispositivo negado, conexão perdida, reconexão, encerramento e acesso expirado. Não conceder câmera/microfone de pessoas nem usar dados reais sem autorização específica.

## Acompanhamento pós-lançamento

Medir ativação por primeiro resultado útil, tempo de criação/revisão de plano, erros de salvamento, falhas de login e contato de suporte por tarefa; retenção por coorte e indicação real separadas. Minimizar telemetria e não registrar textos clínicos, tokens ou conteúdo de mensagens. Analisar qualitativamente primeiro, depois tendências; estabelecer denominadores e período em toda métrica.
