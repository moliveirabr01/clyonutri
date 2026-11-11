# Validação técnica — 03/11/2026

Build: `v54-17-ni2-651-653-654-656-20261103`.

| Verificação executada | Resultado |
|---|---|
| Integração em SQLite (todas as migrations, serviços reais, fixtures sintéticas) | 57/57 |
| Integração em MySQL 8.0.46 (adaptador real da aplicação) | 57/57 |
| Concorrência MySQL com dois processos/conexões simultâneos | 1 salvamento + 1 conflito; passou |
| Chrome real, login, criação/preview/edição, isolamento, convite, identidade, etapas, retomada e envio | 30/30 verificações |
| Resumo no navegador: perfil, prontuário, anamnese, exames, plano, compras e consulta | Passou |
| 375 px, zoom 200%, foco de teclado | Sem rolagem horizontal nas telas verificadas; foco presente |
| Erros JavaScript / recusas de recursos / CSP no roteiro | 0 |
| Migração PHP 79 em SQLite e MySQL | Passou; reaplicação idempotente |
| SQL manual sobre schema da v54.16, duas aplicações | Passou |
| SQL manual em schema incompleto | Bloqueou; zero tabelas criadas |
| Sintaxe PHP | Relatório `syntax-results.json`, sem falhas |

Cobertura da matriz: diabetes+hipertensão, Crohn+celíaca, renal+diabetes, cinco módulos simultâneos, perguntas compartilhadas, condições em cadeia, negações, histórico familiar, dúvida explícita, pacientes em duas clínicas, v1 concluída com v2 em rascunho, relato com proveniência e aceite profissional, versão privada, referências e concorrência, revisão independente, licenciamento e cópia em rascunho, limite de resposta, origem da alergia e alerta específico na lista de compras.

Os dados são fictícios. Os modelos de teste contêm perguntas genéricas e referências declaradas como sintéticas; não são conteúdo clínico validado. Os arquivos JSON são os resultados efetivos das execuções. O manifesto contém os hashes do código correspondente.

Durante a validação foram corrigidos: perda do sinal de dúvida na pontuação, nomes de seções adaptativas no resumo profissional e referência a uma folha de estilo inexistente na lista de compras. As rodadas finais passaram após as correções. Sessões e bancos de teste não entram no ZIP.

**Pendente:** homologação clínica formal e aceites QA/PM/UX, revisão das referências reais, avaliação integral WCAG 2.2 AA, ensaio de hospedagem HostGator, MySQL 5.7 e MariaDB. Os testes técnicos não concedem esses aceites. A suíte histórica completa referenciada pela base não veio no ZIP; não é alegada execução dessa suíte. O pacote inclui os testes novos e a documentação de reprodução.

Nenhuma alteração foi publicada no servidor, nenhum banco de produção foi migrado e nenhum protocolo clínico real foi ativado. NI2-656 possui a matriz técnica entregue, com homologação clínica ainda pendente.
