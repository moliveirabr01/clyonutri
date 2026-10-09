# ClyoNutri II — Blueprint de redesign incremental

Data de avaliação: **08/10/2026 (America/Sao_Paulo)**. Fontes: árvore de trabalho existente, navegação real em `127.0.0.1:8080` e instância isolada `localhost:8081`, banco SQLite novo com as 21 migrações. Nenhuma alteração de código da aplicação ou de registros no Jira.

## Application Shell

Desktop: sidebar de 240–256px com Agenda, Pacientes e Mensagens como trabalho recorrente; Planos e Biblioteca (alimentos/receitas) disponíveis; Financeiro e Configurações em operação; Ajuda persistente. O agrupamento é proposta a validar por card sorting e tarefas, não remoção de módulos.

Header: consultório ativo e conta. Conteúdo: breadcrumb contextual, título, estado e uma ação primária. Paciente em área de contexto persistente nas jornadas clínicas. Link ativo com peso/indicador e `aria-current`; skip link e landmarks. Não repetir nome do profissional em várias posições sem finalidade.

Em 768px ou menos: header compacto e menu acionável; sem nove itens ocupando várias linhas antes do conteúdo. Foco retorna ao acionador ao fechar navegação. Área clínica mantém identidade do paciente em formato compacto, sem cobrir ações.

## Dashboard orientado ao trabalho

Ordem: próxima consulta / agenda do dia; pendências acionáveis com origem e data; acesso a busca de paciente; indicadores secundários contextualizados. Cada pendência leva ao documento específico, não só ao perfil geral. “Revisar prescrição” deve abrir o rascunho alvo.

Nenhum check-in → “Sem check-ins no período”, com informação sobre como obter dados reais. “Zero planos” não equivale a “Todos publicados” para quem nunca começou. “Carteira regular” não pode ser selo derivado apenas de quantidade de pacientes.

## Patient Workspace

Cabeçalho com identidade, idade somente se conhecida, status, contato autorizado e alertas clínicos com origem/data. Ações contextualizadas: iniciar atendimento, registrar avaliação, criar/revisar plano, mensagem, agendar. Navegação local persistente: Visão geral, Anamnese, Prontuário, Avaliações, Planos e Acompanhamento. Ajustar nomes após teste de compreensão; não transformar cada seção em card independente.

Histórico: linha do tempo com tipo, autor, data e versão. Documento histórico somente leitura, banner “Você está vendo v1 de [data]” e ação “Criar revisão”. Voltar mantém paciente, filtro e posição. Não expor dados de contato desnecessários em telas compartilhadas.

## Clinical Workflows

| Fluxo | Estrutura proposta | Salvaguarda |
|---|---|---|
| Anamnese | Seções de objetivos, segurança alimentar, rotina/hábitos e histórico; índice e revisão | Rascunho separado da versão final; desconhecido explícito; payload preservado em erro |
| Antropometria | Formulário com unidades, protocolo, origem e data; comparação longitudinal | Dados necessários por cálculo; sem valores presumidos; compartilhamento confirmado conforme política |
| Plano | Refeições como lista operacional; busca lateral/contextual; edição de item e substituições | Rascunho mutável e publicado versionado; revisão com alertas e destino; nenhuma mudança retroativa |
| Consulta | Contexto do paciente + anotação central + resumo de plano e medidas | Não perder nota ao navegar; pausa explicita estado; concluir confirma registro e acompanhamento |
| Receita | Ingredientes e preparo em grupos claros; rendimento e cálculo por porção | Versões de receita e prescrição; alergênicos rastreáveis; nenhuma conversão silenciosa |
| Comunicação | Thread vinculada ao paciente, indicadores claros e expectativas de resposta | Ownership, recibos autorizados, retry idempotente; sem promessa de disponibilidade contínua |

## Interaction Design

Salvar rascunho: estado local de edição → salvando → salvo às HH:mm → falha com repetição. Em SSR, confirmação específica após POST com retenção do contexto; não exigir mudança para SPA. Finalizar/publicar: revisão explícita do objeto, paciente, pendências e efeito. Erro: texto junto ao campo e resumo navegável; servidor preserva dados submetidos de modo protegido. Busca: consulta preservada, resultados com desambiguação e estado sem resultado.

Modais: apenas decisões ou tarefas curtas; formulários longos em página/workspace. Dialog tem nome, foco contido, fundo inerte, Escape e política de alterações. Remover item do rascunho: confirmação proporcional e possibilidade de desfazer quando tecnicamente segura. Registro clínico não é apagado para simplificar UI.

## Component Architecture

| Componente | Responsabilidade | Primeiros consumidores |
|---|---|---|
| AppShell / PageHeader / PatientContext | Navegação, conta, título, breadcrumb e paciente | Dashboard, perfil, chat, configurações |
| Field / FieldError / Section | Labels, ajuda, requisito, erro e agrupamento | Anamnese, receita, agenda |
| Dialog / ConfirmAction | Foco, fechamento, scroll e consequência | Planos, cadastro, prontuário |
| StatusBadge / ResultMessage | Mapeamento de estado e feedback acessível | Agenda, plano, suporte |
| SearchCombobox / FilterToolbar | Busca de entidades e recuperação | Pacientes, alimentos, receitas |
| ClinicalSummary / VersionHistory | Fonte, segurança, versão e autoria | Perfil, consulta, plano |
| DataTable / EmptyState / ResourceError | Lista, densidade, ausência e falha | Todos os módulos |
| MealEditor / PortionField | Item, unidade, substituição e total | Plano e receita conforme semântica |

Começar com PHP/templates/CSS existentes. Nenhuma nova biblioteca é requisito presumido. Extrair queries para read models e lógica para serviços sem alterar contrato primeiro. Definir DTOs de APIs sem SELECT * exposto e versão de schema; consolidar rotas em fonte única antes de considerar migração tecnológica.

## Responsividade

320/390: uma coluna, ações importantes de 44px como padrão de conforto, menu compacto; tabela com resumo por linha e detalhes acessíveis quando necessário. 768: workspace empilhado e ferramentas recolhíveis. 1024: duas áreas quando conteúdo sustenta largura; 1440: área clínica central com painel contextual; formulários de leitura ficam em 640–760px. Nenhum overflow global; overflow local de tabela precisa de affordance e teclado.

Validar zoom 200%, nomes extensos, mensagens de erro, teclado virtual, viewport baixo e leitor de tela. Colapsar somente apresentação; informação e ações permanecem acessíveis.

## Migração e custo/risco

1. **Estabilizar verdade e acesso:** corrigir PX-01–06, 16, 19, 20, 23–26, 29. Risco de schema/contrato exige migrações, backups e teste de upgrade; não mudar visual junto.
2. **Fundação reutilizável:** ratificar tokens, Field, Dialog, StatusBadge e shell; migrar duas telas piloto (pacientes/anamnese) antes das demais. Custo moderado de extração, baixo risco de regra clínica se preservado contrato.
3. **Workspace e produtividade:** paciente, consulta, editor de planos, busca; migrar verticalmente por jornada. Maior risco em estado de edição/conflito de versão; testar persistência e rollback.
4. **Cobertura lateral:** chat, receitas, suporte, financeiro, configuração e paciente, com matriz de capacidades e papéis.
5. **Pesquisa e otimização:** dispositivos reais, acessibilidade e desempenho; medir ganhos antes de escalar.

Não há estimativa numérica responsável sem refinamento e capacidade da equipe. Não se justifica reconstruir tudo: publicação/versionamento/consulta já persistem e há serviços reaproveitáveis. Separar entrega do replatform Laravel de correções urgentes evita acoplar a qualidade a uma migração ampla.

## Gates de lançamento

Todos os P0 resolvidos; P1 de integridade, acesso, histórico, unidades, meta e erros comprovados; jornada central completa em banco limpo e upgrade; zero regressão de tenant e versões; todos os formulários críticos acessíveis por teclado e nomeados; reflow sem overflow global. Vídeo exige dois participantes e falhas reais; app exige build funcional, login forte e erro parcial visível. Se componente não for liberado, disponibilidade e restrições devem ser claras e alinhadas ao contrato comercial.

## Padrão de 90/100

Qualidade sustentada em todas as rotas, todos os estados e papéis; busca e revisão clínica sem retrabalho; origem dos dados visível; salvamento recuperável; experiência móvel comprovada; uso real com baixa assistência. A nota só será reavaliada após evidência; backlog concluído não equivale automaticamente a 90.
