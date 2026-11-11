# ClyoNutri v54.17

Base: ZIP v54.16 fornecido em 03/11/2026. Esta entrega implementa NI2-651, NI2-653 e NI2-654 e acrescenta a matriz técnica reproduzível da NI2-656. **A homologação clínica e os aceites de QA, PM e UX permanecem pendentes.** Nenhum protocolo clínico foi aprovado em nome de um profissional ou ativado em produção.

## Mudanças

- Pré-consulta: perguntas aprovadas copiadas com versão, somente para paciente, deduplicadas; declaração opcional de condições; revisão explícita pelo profissional. Convite adaptativo com troca única por credencial de sessão, expiração, verificação de nascimento, aceite de privacidade, etapas, retomada no mesmo navegador e conflitos explícitos. As respostas não alteram a anamnese vigente.
- Resumo transversal: perfil, prontuário, anamnese, consulta, plano, exames, lista de compras e resposta do serviço de resumo para prescrição. Últimas conclusões profissionais por módulo são preservadas, mesmo com nova versão em rascunho ou protocolo aposentado. Relatos/suspeitas/documentação mantêm origem. A trava existente de alergias específicas continua funcionando.
- Editor sem código: perguntas tipadas, grupos, ordem, condições, limites e prévia pelo motor real; templates privados, rascunhos e publicação com revisão independente. Alterar publicação cria nova versão. Compartilhamento exige plano ativo com `features_json.clinical_protocol_sharing = true` (booleano); somente o modelo aprovado é compartilhado, e a clínica destinatária recebe uma cópia em rascunho para revisão local. Não compartilha respostas.
- Gravação transacional dos módulos com conflito de revisão; proteção de referências no editor; sinal de interrogação mantém sugestão incerta.

## Instalação / atualização

1. Faça backup completo dos arquivos e do banco. Ative manutenção antes de trocar código e estrutura. Preserve o `.env`, uploads e permissões de `storage/` existentes.
2. Confira a base com `php bin/adaptive_preflight.php`. Ele exige a estrutura anterior, incluindo a migração 78 e tabelas InnoDB. **Não continue se houver issues.**
3. Aplique a **migração 79** por `php bin/migrate.php`. Em HostGator sem terminal, importe `database/manual/atualizacao-v54-17-ni2-651-653-654-656.sql` no banco correto pelo phpMyAdmin. O SQL recusa alterações se a base anterior estiver incompleta e pode ser repetido.
4. Confira `php bin/adaptive_preflight.php --ready` ou o resultado final do SQL (`MIGRAÇÃO 79 OK`). Só então libere o tráfego.
5. Não altere a chave `feature_flag_clinical_protocols` para aprovar esta entrega. Ela continua governando a ativação existente; o pacote não liga a chave. Protocolos e modelos novos exigem escopo, responsável, referências, revisão por outra pessoa com CRN e data de revisão. A ativação por clínica continua separada da aprovação.

PHP >= 8.1. Testes técnicos locais executados em PHP 8.5.11, SQLite e MySQL 8.0.46; não houve execução na infraestrutura HostGator, MySQL 5.7 ou MariaDB nesta entrega.

### Estrutura acrescentada

- `clinical_protocols.clinic_id INT NULL`: nulo é catálogo global; preenchido é propriedade privada da clínica.
- `preconsultation_questionnaires.access_hash VARCHAR(64) NULL`: credencial de retomada de convite adaptativo. O token do convite não pode criar outra sessão após a primeira troca.
- `preconsultation_questionnaires.answer_revision INT NOT NULL DEFAULT 0`: detecção de edição concorrente.
- `clinic_protocol_shares`: autorização de cópia de uma versão aprovada para uma clínica destinatária. Não contém respostas ou dados de pacientes.

Não há remoção de tabelas, respostas ou colunas. Exportações LGPD omitem a nova credencial; anonimização revoga convites ainda não concluídos.

## Reversão

Durante manutenção, desligue a chave global e desabilite os protocolos recém-ativados, se houver. Para voltar ao código v54.16, mantenha as colunas e a tabela novas: são aditivas e preservam o histórico. **Não remova respostas nem protocolos privados criados pela v54.17.** A v54.16 não entende a propriedade privada: antes de voltar a ligá-la, bloqueie a ativação da chave global e conclua uma avaliação de compatibilidade. Convites adaptativos em andamento devem ser revogados e reemitidos depois da escolha de versão; não reutilize credenciais ou sessões da v54.17 no código anterior. Restaurar backup antigo após novas coletas perde essas coletas; prefira rollback do código com a chave desligada.

## Evidências e limites

Relatórios e escopo de validação: `docs/delivery/ni2-651-653-654-656/`. Reprodução: `tests/README-adaptive.md`. As verificações de 375 px, zoom 200%, foco de teclado e ausência de erros JS/CSP não representam certificação integral WCAG 2.2 AA. Referências e conteúdo clínico reais continuam sujeitos à curadoria especializada; os testes usam apenas formulários sintéticos. A suíte histórica completa não veio no ZIP v54.16; foram executados os testes novos e regressões explícitas de anamnese, alergias e lista de compras incluídas nesta entrega.
