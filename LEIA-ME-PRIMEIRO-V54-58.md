# ClyoNutri II — v54.58 candidata

Base: v54.57 recebida em 11/11/2026, preservada. Painel e menu adaptados à organização e à clareza visual observadas no Talui, mantendo a identidade verde Botanical e os dados/fluxos nutricionais do ClyoNutri. Nenhum código ou dado do Talui foi incorporado.

## O que mudou

- Menu lateral estável no desktop, com destinos diretos em Atendimento, Biblioteca nutricional, Gestão e Suporte e conteúdo. Planos alimentares passam a Atendimento. Não há acordeões que se fecham quando a página muda.
- “Exames” da biblioteca agora se chama “Catálogo de exames” e marca o item correto. Solicitações, resultados e laudos continuam na aba Exames de cada paciente.
- Menu móvel como painel modal: fechar, toque fora, Escape, foco contido, retorno ao acionador e fundo inerte. Trocar de página ou voltar ao desktop encerra o modo modal. Destinos continuam sujeitos às permissões no servidor; equipe administrativa não ganha acesso clínico.
- Quatro indicadores principais em uma linha no desktop: consultas hoje, pacientes ativos, adesão e planos a revisar. O volume/comparação semanal permanece junto da agenda, sem perder os dados do período.
- Cartões, espaçamentos e hierarquia mais leves; busca e Nova consulta no topo. Atalhos de atendimento nutricional antecedem a lista longa de prioridades. Pendências, rascunhos, não comparecimento, alertas e suas ações reais permanecem.
- Contraste de “Sem consultas” corrigido no dia atual. No editor de refeições do celular, etapas duplicadas acima da tarefa ficam disponíveis no disclosure já existente “Contexto clínico e etapas”, com identidade, alergia e retorno mantidos. O campo de refeição volta à primeira tela no cenário testado.

Sem migrations ou dependências novas. Não substituir .env, banco, uploads, chaves clínicas ou sessões por material de testes. O guia v54.57 fica no pacote como histórico; RELEASE_INFO.json e este guia descrevem a candidata atual.

## Validação e uso

Dados exclusivamente sintéticos em SQLite, Chrome e WebKit nas suítes herdadas. Firefox ficou bloqueado por incompatibilidade do executor; não se concluiu que o produto falha nesse navegador. As respostas de pré-consulta e o snapshot de plano publicado foram preparados em fixtures; não equivalem a envio real ao paciente nem aprovação da cadeia integral de publicação/entrega.

CANDIDATA LOCAL. Homologação em MySQL/HostGator, aparelhos físicos, leitores de tela reais e revisão humana permanecem pendentes. Nenhum deploy foi realizado. Gate oficial de produção NÃO EXECUTADO / NÃO APROVADO; os resultados de gate NO-GO informados no pacote anterior não foram reinterpretados como resolvidos.

Antes de aplicar no destino: conferir o build e a integridade do arquivo; manter backup de arquivos e banco; validar menu em desktop/celular e com recepção; busca, nova consulta, agenda, contexto A/B, Pré-consulta → perfil, rascunhos, avaliação, plano e erros; conferir os dados e permissões reais do consultório.

Reversão: restaurar os arquivos preservados da v54.57 e atualizar o cache de código/estáticos conforme o ambiente. Não sobrescrever gravações novas com um banco antigo. Não há mudança de esquema.

Relatório, testes, evidências, prévias e diferença de arquivos: implementacao-ux-v54-58-2026-11-11, fora da área pública do pacote.
