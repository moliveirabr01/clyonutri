# NI2-281 — execução local e critérios pendentes

09/10/2026, America/Sao_Paulo. Épico **Fazendo**. Implementação parcial validada; as histórias filhas não foram marcadas como concluídas. Trabalho aplicado em `/Users/moliveira/Nutri_II`, preservando alterações existentes. Sem publicação ou alteração de dados clínicos da instância de uso.

## Resultado implementado

- Cadastro comum e primeiro paciente do onboarding deixam de fabricar aceite a partir de `lgpd_consent`. Situação inicial “não coletado”; aceite e recusa exigem escolha expressa e termo ativo para a finalidade de atendimento. Paciente, manifestação e auditoria são gravados na mesma transação. Uma chave por formulário evita duplicação do cadastro por reenvio na mesma sessão. Aceite audita versão, finalidade, horário, canal e usuário; recusa mantém referência ao termo e profissional. O canal é declaração do profissional, não assinatura eletrônica do titular. Nenhum termo jurídico foi inventado ou ativado em produção. Sem termo ativo, o cadastro permanece disponível e não registra aceite.
- Configurações permitem editar nome profissional, especialidade e apresentação; somente gestão altera o nome da clínica. Revisão do formulário detecta conflito entre abas. IDs enviados pelo navegador não escolhem o usuário ou a clínica. E-mail e CRN permanecem identificados como dados de leitura; esta tela não implementa alteração de credenciais.
- Busca incremental no editor e nas substituições: debounce de 250 ms, cancelamento de requisição antiga, busca sem acentos, paginação de 25 resultados, estados de busca/vazio/erro e tentativa novamente. Seleção por teclado nativo e manutenção da porção fora da busca. Alimentos de outra clínica são excluídos tanto da leitura quanto dos caminhos de seleção do domínio.
- Menu mobile apresenta Pacientes, Agenda e Planos e expande as demais opções. Escape fecha e devolve foco. Link para pular navegação, página atual e foco visível. Diálogos ganham identificação, contenção de Tab, Escape e retorno de foco. Ajustados contraste, tamanho do botão de remoção e nome acessível do seletor de evolução.
- Anamnese e antropometria preservam o preenchimento enviado em caso de falha, na sessão do mesmo profissional/clínica/paciente, por até uma hora. A recuperação não sobrescreve versões históricas nem cria registro clínico automaticamente; gravação bem sucedida limpa o preenchimento de recuperação. Não usa armazenamento persistente no navegador. Formulários avisam sobre saída com alterações ainda não enviadas.
- Detalhes de verificação técnica do prontuário ficam restritos à gestão, mantendo os controles de integridade existentes.

## Evidências verificadas

47 verificações HTTP/navegador aprovadas contra o `public/index.php` real, banco SQLite descartável, dois tenants e três papéis. Sem respostas de domínio simuladas. [Resultados](evidence/http-results.json), [persistência sintética](evidence/persistence.json), [resumo](evidence/summary.json).

Seis suítes de regressão aprovadas: onboarding, demográficos opcionais, primeira consulta (32 asserções), edição de paciente, ciclo de prescrição (47 asserções), prontuário/anamnese. [Resultados](evidence/regression-results.json). Regressões HTTP da NI2-310 e NI2-312 também aprovadas. Sintaxe de 15 arquivos PHP e três JavaScript verificada; `git diff --check` passou.

Configurações verificadas a 320, 375, 768 e 1280 pixels; sete jornadas a 375 pixels sem rolagem horizontal da página. [320 px](evidence/settings-320.png), [1280 px](evidence/settings-1280.png). A inspeção visual encontrou e levou à correção da altura do cabeçalho mobile.

axe-core 4.14.0: zero violações automáticas nas cinco páginas verificadas (Configurações, Pacientes, Anamnese, Antropometria, Plano), com regras WCAG A/AA disponíveis no motor. [Auditoria completa, inclusive verificações inconclusivas](evidence/accessibility.json). Isso não confirma conformidade WCAG 2.2 AA integral: faltam leitor de tela, zoom/reflow, demais estados, conteúdo preenchido e revisão manual das verificações inconclusivas.

## Cobertura e trabalho ainda necessário

| História | Situação local e critérios ainda pendentes |
|---|---|
| NI2-282 | Cinco passos, retomada e isolamento cobertos por regressão. Medição de primeiro valor e aceitação em homologação pendentes. |
| NI2-283 | Vocabulário clínico e detalhes técnicos restritos à gestão; avaliação de compreensão com participantes e inventário final de termos pendentes. |
| NI2-284 | Manifestação explícita/auditável implementada. Termos e bases legais precisam da revisão de privacidade; falta jornada completa de gestão e revogação na interface. Serviço existente de revogação passou em regressão. |
| NI2-285 | Perfil e nome da clínica editáveis. Preferências continuam no onboarding; notificações, conta e aplicação efetiva das preferências em todas as jornadas ainda precisam implementação/verificação. |
| NI2-286 | Busca sem acentos, paginação e teclado implementados. Sinônimos, estratégia de indexação e medição no catálogo representativo de homologação pendentes. Não se alegou ganho de desempenho a partir das fixtures pequenas. |
| NI2-287 | Menu e páginas verificadas conforme evidências. Restam estados preenchidos, todos os diálogos e revisão manual de acessibilidade. |
| NI2-288 | Recuperação após envio com falha implementada. Não há autosave contínuo: saída antes do envio só apresenta aviso. Autosave com revisão concorrente, resumo de revisão e simplificação completa dos grupos ainda pendentes. |
| NI2-289 | Protocolo e planilha de coleta preparados. Homologação e pelo menos cinco nutricionistas reais ainda indisponíveis; nenhum SEQ/SUS foi gerado. |
| NI2-290 | Arquitetura de informação depende de primeiro clique/cardsort com participantes. Não concluída. |
| NI2-291 | Feedback e preservação nos fluxos modificados, bloqueio visual de reenvio e idempotência do cadastro. Inventário de todas as mutações, retenção e idempotência dos demais fluxos ainda pendentes. |

[Jornadas J01–J20](journeys.csv) são um mapeamento operacional desta execução, pois a auditoria fornecida não enumera esses códigos; confirmar os códigos com o responsável antes de tratá-los como matriz canônica. “Cobertura parcial” não significa jornada aceita.

A meta >=85/100 não foi atribuída: não há nova avaliação heurística completa, e 68/100 continua sendo somente estimativa subjetiva anterior. TSR, tempo por tarefa, taxa de erro, fricção, SEQ e SUS de pessoas reais estão **não coletados**, não zero. Critérios de ausência de P0/P1 e E2E em homologação permanecem não atendidos. O épico não pode ser encerrado com estas evidências locais.

## Reprodução

Banco de teste e servidor são criados e removidos pela suíte. Pré-requisitos: PHP com SQLite/mbstring, dependências Composer, Node, Chrome e Playwright. A suíte usa Playwright em `/tmp/cfit276-browser/node_modules/playwright` e axe-core em `/tmp/ni281-a11y/node_modules/axe-core`; ajustar os caminhos para o ambiente de CI. Execute `node tests/ni2_281_real_http_test.cjs` na raiz do repositório. Para as suítes PHP que utilizam `Connection`, definir `DB_DATABASE` para arquivo descartável e rodar as migrações antes — nunca apontar a suíte para o banco de uso.
