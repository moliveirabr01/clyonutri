# Implementação e contrato — v54.17

## Rastreabilidade

| Item | Entrega técnica | Aceite restante |
|---|---|---|
| NI2-651 | Snapshot imutável de módulos aprovados no questionário existente; só perguntas da audiência paciente; declaração opcional; convite adaptativo de troca única; identidade, privacidade, etapas, revisão e conflitos | Homologação clínica e validação no ambiente de destino |
| NI2-653 | Resumo derivado das conclusões profissionais e condições revisadas, preservando fonte e versão; integração no cabeçalho compartilhado, resumo de prescrição e compras; trava de alérgenos existente coberta por regressão | Aceites clínico/QA/PM/UX |
| NI2-654 | Builder tipado, preview/validação pelo servidor, versões privadas, isolamento por clínica, revisão independente, compartilhamento licenciado de cópias em rascunho | Curadoria dos modelos reais e aceite de UX |
| NI2-656 | Matriz técnica com diabetes+hipertensão, Crohn+celíaca, renal+diabetes, cinco módulos, duas clínicas/pacientes, versão concluída/rascunho, pré-consulta, concorrência, migrações, navegador e capturas | Homologação clínica formal; auditoria WCAG completa; ensaio HostGator; aceites QA/PM/UX |

As combinações são nomes de fixtures sintéticas e não carregam perguntas ou condutas clínicas reais. Não representam protocolos clínicos validados. Nenhum ticket deve ser considerado clinicamente homologado por estes testes.

## Endpoints

As rotas já existentes de pré-consulta continuam vigentes. A criação inclui as perguntas dos módulos aprovados e ativados para o paciente, mais um campo opcional de condições relatadas. Editar a seleção da anamnese-base antes do envio preserva a cópia versionada dos módulos. Perguntas exclusivas do profissional não saem no formulário do paciente. O envio é um relato pendente, nunca grava `protocol_responses` concluídas nem modifica `anamneses`.

- `POST /pacientes/{id}/pre-consulta/{qid}/condicoes`: profissional autorizado pede sugestões a partir do relato enviado. As sugestões são confirmadas ou descartadas nas rotas existentes da anamnese. As dúvidas e o histórico familiar passam pelo detector declarativo.
- `GET /configuracoes/protocolos/editor?version_id=...`: cria modelo ou abre versão da própria clínica. Não lê uma versão privada de outra clínica.
- `POST /configuracoes/protocolos/editor`: formulário com CSRF e ação `preview`, `save`, `submit`, `approve`, `share` ou `import_shared`. O servidor converte os controles tipados para o contrato existente de `ProtocolDefinition`. Não aceita HTML, extensão executável ou propriedades arbitrárias. Textos de perguntas são dados literais; não são enviados a LLMs nem executados como instruções.
- `getClinicalSummaryForPrescription`: preserva os campos anteriores e acrescenta `adaptive_summary`. O novo campo contém `base` (última anamnese concluída), `conditions` com proveniência/revisão e `modules` com versão/data/resposta profissional concluída. Dados em rascunho não substituem conclusões; a proteção de alergias preexistente pode continuar somando sinais de rascunhos por segurança.

`preview` usa as mesmas definições, condições e limites da persistência e mostra campos para exercitar respostas. Reordenar pergunta torna dependências futuras inválidas e exige correção antes de salvar. Templates sem escopo/responsável não são salvos; sem referências não seguem para revisão; autores não aprovam a própria versão. Não há avaliação automática de veracidade de referência clínica: ela é responsabilidade dos revisores humanos.

## Autenticação e concorrência

Convites adaptativos trocam o token original, cujo hash permanece no banco, por uma nova credencial guardada na sessão. Outra sessão não pode reutilizar o convite; o mesmo navegador retoma pelo endereço `/pre-consulta` ou pelo convite original enquanto a sessão for válida. Reenvio, expiração e revogação invalidam o acesso anterior. Sessão perdida exige novo convite, preservando respostas salvas. Convites legados sem módulos adaptativos mantêm o comportamento anterior.

A página exige nascimento cadastrado e termo ativo antes de emitir um convite adaptativo. Aceite é obrigatório para gravar rascunho ou enviar; o envio grava o consentimento do termo ativo. As rotas públicas já aplicam CSRF, cabeçalhos sem cache e limitação de tentativas; gravações receberam limitação adicional. A sessão profissional não é usada para definir a clínica do paciente no lado público.

`answer_revision` retorna no formulário. Uma revisão desatualizada não substitui respostas e devolve as alterações digitadas, ao lado das respostas atuais. Revisões de editor usam hash do conteúdo, referências e estado; alterações de referências também entram no controle de concorrência. Gravações de módulos clínicos bloqueiam a linha do paciente no MySQL, conferem a revisão sob bloqueio e salvam todos os módulos em uma transação. Estado editorial também é revalidado em transação.

As respostas recuperadas após erro dos módulos profissionais são vinculadas à clínica e ao paciente na sessão. A nova credencial não é exibida em DTOs, logs ou exportações LGPD. Logs registram quantidades e eventos, não diagnósticos ou respostas. Conteúdo de protocolo é tratado como dado, nunca como instrução de execução.

## Ativação e reversão

Consulte `LEIA-ME-HOSTGATOR-v54-17.md`. Não houve ativação, migração ou publicação em produção. O estado clínico real e os aceites humanos não foram presumidos.
