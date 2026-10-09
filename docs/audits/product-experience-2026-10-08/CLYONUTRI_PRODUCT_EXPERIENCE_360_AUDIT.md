# ClyoNutri II — Auditoria 360° de Product Experience

Data de avaliação: **08/10/2026 (America/Sao_Paulo)**. Fontes: árvore de trabalho existente, navegação real em `127.0.0.1:8080` e instância isolada `localhost:8081`, banco SQLite novo com as 21 migrações. Nenhuma alteração de código da aplicação ou de registros no Jira.

## Veredito executivo

**Não aprovaria o lançamento comercial desta experiência hoje.** O sistema executa etapas centrais com persistência real, mas ainda compromete confiança, recuperação e continuidade: cliente móvel incompatível com autenticação, evolução quebrada em instalação limpa, dados presumidos no onboarding, adesão fictícia, histórico que não respeita versão e revogação de acesso incompleta.

**Score heurístico provisório: 47,4/100 no escopo pontuado (90% de peso).** Paciente e performance/responsividade completos permanecem não avaliados. Não há nota global definitiva, pesquisa com usuários ou comprovação de vídeo real. A auditoria documental está entregue; a homologação integral de todas as jornadas/papéis permanece aberta.

## Escopo e método

104 registros de rotas no entrypoint `public/index.php`, com inventário completo ao final. Inspeção de fontes, templates, CSS, mobile e referência de design. Navegação na sessão existente, seguida de instância de teste isolada com banco novo, migrações oficiais e catálogo. Nesta instância, login, onboarding, anamnese, plano e consulta foram percorridos com POSTs reais; persistência e API foram verificadas.

Fixtures de teste explícitas não substituíram funcionalidades. Não foram criadas telas falsas ou resultados clínicos simulados como reais. O plano de teste não é prescrição clínica. Nenhuma mutação de paciente real, envio a pessoas, câmera/microfone, pagamento ou Jira. O teste legado de teleconsulta usa mock em um ramo; seu resultado foi excluído como prova de integração real e identificado como problema de qualidade de evidência.

A árvore já continha alterações em CSS, autenticação, landing, shell, legais e logotipos antes da auditoria. Foram preservadas. `release/` e `replatform/laravel13/` são artefatos paralelos; o comportamento observado em 8080 corresponde ao entrypoint PHP atual. Não se presume equivalência com release implantada ou Laravel em produção. Segurança tem relatório independente de cobertura parcial.

## Mapa de áreas, papéis e permissões

| Área | Capacidade no runtime | Evidência e limite |
|---|---|---|
| Público | Landing, login, cadastro, termos e privacidade | Login navegado; cadastro/legal inspecionados, sem aceitar contrato ou criar conta pela UI |
| Profissional/clínica | Dashboard, pacientes, perfil, anamnese, prontuário, antropometria, agenda/consulta, planos, alimentos, receitas | Núcleo testado; antropometria preenchida/receitas web não homologadas end-to-end |
| Comunicação | Chat/lista/thread/poll/anexos | Entrada ausente no shell; ownership parcial revisado; sem mensagem externa |
| Ajuda | Categorias/artigos/chamados/anexos | Navegação real e estados; abertura de chamado não exercida |
| Operação | Financeiro/configuração | Telas existem, operação incompleta na interface |
| Suporte | Fila, ticket, resposta/status | Rotas e RBAC em fonte; papel de suporte não exercido em navegador |
| Paciente | Login/logout API, home/plano, água/checkin/confirmação, diário, evolução, chat, receitas/compras | API real amostrada; evolução falha; app nativo não tem bootstrap/entrypoint identificado no diretório entregue |
| Teleconsulta paciente | Convite URL, dispositivos, consentimento, espera, sala/status | Modelo e rotas; mídia/reconexão não homologadas |

Papel global `nutritionist`, vínculo de clínica `owner` e papéis admin/operator/support_agent usados no shell e suporte aparecem em fonte. Não são equivalentes. Na reconstrução de TenantContext, o entrypoint copia id/nome da sessão sem transportar/revalidar toda a autorização. O paciente é contextualizado por Bearer com role patient. Uma matriz completa de leitura/edição/exportação/compartilhamento por recurso ainda precisa de homologação para cada papel, não apenas visibilidade do menu.

## Testes e qualidade da evidência

| Verificação executada | Resultado | O que comprova |
|---|---|---|
| Migrações em SQLite novo | 21 aplicadas | Schema oficial é instalável; não garante todas as tabelas esperadas pelo runtime |
| Jornada canônica de domínio | 32/32 | Persistência/serviços e isolamento nos cenários testados; não é browser E2E |
| Anamnese/prontuário domínio | Exit 0 | Append-only, revisão e contratos testados; link de versão da UI continua errado |
| Plano/prescrição domínio | 47/47 | Publicação, clonagem e cenários de tenant; edição UX não fica comprovada |
| Receitas domínio | Exit 0 | Persistência e prescrição da suíte, sem homologação visual de criação/preenchimento |
| Onboarding domínio/controller | Exit 0 | Passos e idempotência testados; defaults biográficos errados continuam presentes |
| Teleconsulta suíte legada | Exit 0, evidência externa excluída | Estados locais e fail-closed; ramo ativo usa mock, não comprova Daily real |
| Navegador na instância isolada | Concluído núcleo | Onboarding, paciente, anamnese v1/v2, plano publicado e consulta completed |
| HTTP do entrypoint real | Parcial/falhas | Login forte, plano, água/diário/logout; cliente login 401 e evolução HTML 200 por tabela ausente |
| Responsividade | Falha observada em 390px | Documento 461px e tarefas postergadas; não cobre todos os breakpoints |

Logs em `evidence/`; saída triunfal de suíte não foi tomada como prova além da fronteira efetivamente exercida. A primeira tentativa de consulta HTTP sofreu parse de erro HTML; repetição preservou o corpo e identificou o problema. A primeira inicialização do servidor isolado roteava assets pelo PHP; foi corrigida antes da avaliação visual e a aparência sem CSS não foi registrada como finding do produto.

## Segurança e confiabilidade

Revisão Codex Security: duas falhas técnicas médias de revogação e uma baixa de recibo de leitura, mapeadas para PX-23/24/25. Controles na fonte incluem bcrypt, rotação de sessão no login, CSRF web, tokens com hash/expiração, verificações de tenant e anexos chat/helpdesk privados com MIME real. Esses controles não equivalem a certificação ou conformidade LGPD/CFN.

Pendências: OTP de uso único, proteção contra abuso no gateway, DTO de exportação sem hashes/PIN/OTP, retenção/anonimização de textos clínicos, storage clínico durável e concorrência de sincronização offline. Não foram promovidas a vulnerabilidades confirmadas sem alcance runtime validado. Banco MySQL, produção, backup/restauração, integração externa e testes completos de concorrência ficaram fora da comprovação desta rodada.

## Dez problemas de maior impacto no valor percebido

1. Acesso móvel incompatível com a API (PX-01).
2. Evolução quebra após migração limpa e falha é ocultada (PX-02/26).
3. Dados biográficos presumidos no onboarding (PX-03).
4. Adesão fictícia e agenda divergente por data (PX-04/05).
5. Histórico clínico selecionado não corresponde ao exibido (PX-06).
6. Porções/referências e meta hídrica ambíguas ou incoerentes (PX-16/20).
7. Revisar plano exige remover/reinserir itens (PX-17).
8. Chat escondido e com outra identidade, sem começo de conversa na ficha (PX-10).
9. Configuração sem operação e recuperação dependente do suporte (PX-11/29).
10. Campos/modais/móvel inconsistentes e acesso não revogado adequadamente (PX-07/08/09/23/24).

## Dez melhorias de maior impacto esperado

1. Login forte e provisão/recuperação de acesso do paciente comprovados.
2. Instalação limpa com todos os contratos reais funcionando e erros JSON corretos.
3. Eliminar todo dado clínico/biográfico default sem fonte.
4. Indicadores e datas com fonte/período/fuso consistentes.
5. Histórico somente leitura correto por versão, com origem rastreável.
6. Workspace de paciente com ações e navegação contextual.
7. Busca escalável de pacientes/alimentos e edição contextual de porção.
8. Salvamento recuperável, feedback específico e revisão clínica antes de publicar.
9. Shell/Field/Dialog/Status unificados e reflow acessível.
10. Gestão real de conta/acesso e canais de comunicação coerentes com permissões.

Impacto em retenção e satisfação é hipótese de produto; ainda não existe mensuração causal. Priorizar integridade e bloqueios antes do acabamento.

## Respostas obrigatórias do comitê

**1. Teria prazer em utilizar todos os dias?** Ainda não como experiência madura. Consegui finalizar atendimento e plano, mas busca ausente, revisão histórica incorreta e retrabalho de itens tornam a rotina incerta. É juízo heurístico, não entrevista de nutricionista.

**2. Teria orgulho de mostrar aos pacientes?** Mostraria partes da organização clínica, com reservas; não apresentaria o ecossistema como experiência premium pronta. Login/evolução e meta hídrica incoerentes afetam justamente a continuidade com o paciente.

**3. Recomendaria espontaneamente?** Não neste estado para operação comercial dependente do app. Indicação real não foi medida; resposta é decisão hipotética fundamentada nos bloqueios.

**4. Transmite a mesma sofisticação da marca?** Parcialmente no shell navy/cobre, não consistentemente: chat com emojis/fonte própria, jargão e configuração estática contradizem a promessa de clareza e contexto.

**5. É comparável aos melhores SaaS?** Não no conjunto observado. Comparação é com princípios de consistência, descoberta e confiabilidade, não teste controlado de desempenho contra Linear/Stripe ou concorrentes.

**6. Dez problemas?** Listados acima com IDs e evidências detalhadas na matriz.

**7. Dez melhorias?** Listadas acima, com backlog NI2 pronto para refinamento e critérios de teste.

**8. O que impede 90/100?** Verdade dos dados, acesso/versão corretos, jornadas recuperáveis, unidade/meta coerentes, busca/edição eficientes, unificação de interação e prova em todos os estados/papéis. Estética isolada não resolve essas lacunas.

**9. Antes do go-live versus incremental?** Antes: corrigir P0 e P1 de acesso, schema, biografia, indicadores, fuso, histórico, unidades, meta, revogação e erros; provar núcleo em banco limpo. Acessibilidade dos formulários críticos e reflow são gate. Depois, com escopo comercial honesto: refinamento de densidade, navegação contextual ampliada, receitas/financeiro/configuração e otimização medida. Vídeo/app só devem ser anunciados disponíveis quando homologados em fronteira real.

**10. Aprovaria lançamento hoje?** Não aprovaria lançamento comercial desta experiência. Um piloto controlado só após correções de integridade/acesso e definição explícita de capacidades, com acompanhamento e critérios de interrupção. Não é recomendação de expor dados reais a risco conhecido.

## Entregáveis

- [Findings e jornadas](CLYONUTRI_UX_JOURNEY_FINDINGS.md)
- [UI e Design System](CLYONUTRI_UI_DESIGN_SYSTEM_AUDIT.md)
- [Direção premium](CLYONUTRI_PREMIUM_DESIGN_DIRECTION.md)
- [Scorecard](CLYONUTRI_PRODUCT_EXPERIENCE_SCORECARD.md)
- [Blueprint](CLYONUTRI_REDESIGN_BLUEPRINT.md)
- [Backlog NI2](CLYONUTRI_UX_UI_IMPLEMENTATION_BACKLOG.md)
- [Plano de validação com usuários](CLYONUTRI_USER_VALIDATION_PLAN.md)

## Inventário completo do runtime

Inventário extraído de public/index.php, e não de rotas declarativas desconectadas. Linha indica registro; autenticação e autorização também podem ocorrer dentro do handler/controller/service. “Sessão” não é prova de RBAC suficiente. Rotas de sala-espera/sala usam token URL e precisam de cuidado com transmissão/expiração; token não é exposto neste relatório.

| Método | Rota | Registro | Fronteira identificada |
|---|---|---:|---|
| GET | `/login` | 57 | Pública ou sessão no logout |
| POST | `/login` | 58 | Pública ou sessão no logout |
| GET | `/cadastro` | 59 | Pública ou sessão no logout |
| POST | `/cadastro` | 60 | Pública ou sessão no logout |
| GET | `/logout` | 61 | Pública ou sessão no logout |
| GET | `/up` | 64 | Pública ou sessão no logout |
| GET | `/health` | 100 | Pública ou sessão no logout |
| GET | `/` | 122 | Pública ou sessão no logout |
| GET | `/termos` | 123 | Pública ou sessão no logout |
| GET | `/privacidade` | 124 | Pública ou sessão no logout |
| GET | `/onboarding` | 127 | Sessão profissional; autorização de recurso a verificar |
| POST | `/onboarding/passo` | 128 | Sessão profissional; autorização de recurso a verificar |
| GET | `/api/v1/onboarding/status` | 129 | Sessão profissional |
| GET | `/dashboard` | 131 | Sessão profissional; autorização de recurso a verificar |
| GET | `/pacientes` | 132 | Sessão profissional; autorização de recurso a verificar |
| POST | `/pacientes` | 133 | Sessão profissional; autorização de recurso a verificar |
| GET | `/pacientes/{id}` | 134 | Sessão profissional; autorização de recurso a verificar |
| GET | `/pacientes/{id}/prontuario` | 135 | Sessão profissional; autorização de recurso a verificar |
| POST | `/pacientes/{id}/prontuario` | 136 | Sessão profissional; autorização de recurso a verificar |
| POST | `/pacientes/{id}/prontuario/{recId}/retificar` | 137 | Sessão profissional; autorização de recurso a verificar |
| GET | `/pacientes/{id}/prontuario/exportar` | 138 | Sessão profissional; autorização de recurso a verificar |
| GET | `/pacientes/{id}/anamnese` | 139 | Sessão profissional; autorização de recurso a verificar |
| POST | `/pacientes/{id}/anamnese` | 140 | Sessão profissional; autorização de recurso a verificar |
| GET | `/pacientes/{id}/resumo-prescricao` | 141 | Sessão profissional; autorização de recurso a verificar |
| GET | `/pacientes/{id}/antropometria` | 142 | Sessão profissional; autorização de recurso a verificar |
| POST | `/pacientes/{id}/antropometria` | 143 | Sessão profissional; autorização de recurso a verificar |
| POST | `/pacientes/{id}/antropometria/{evalId}/compartilhar` | 144 | Sessão profissional; autorização de recurso a verificar |
| GET | `/pacientes/{id}/antropometria/comparar` | 145 | Sessão profissional; autorização de recurso a verificar |
| GET | `/alimentos` | 146 | Sessão profissional; autorização de recurso a verificar |
| GET | `/agenda` | 147 | Sessão profissional; autorização de recurso a verificar |
| POST | `/agenda` | 148 | Sessão profissional; autorização de recurso a verificar |
| POST | `/agenda/{id}/editar` | 149 | Sessão profissional; autorização de recurso a verificar |
| POST | `/agenda/{id}/status` | 150 | Sessão profissional; autorização de recurso a verificar |
| GET | `/consulta/{id}` | 151 | Sessão profissional; autorização de recurso a verificar |
| POST | `/consulta/{id}/concluir` | 152 | Sessão profissional; autorização de recurso a verificar |
| GET | `/teleconsulta/sessao-agenda/{appointmentId}` | 155 | Sessão profissional; autorização de recurso a verificar |
| GET | `/teleconsulta/{id}/atendimento` | 156 | Sessão profissional; autorização de recurso a verificar |
| POST | `/teleconsulta/{id}/admitir` | 157 | Sessão profissional; autorização de recurso a verificar |
| POST | `/teleconsulta/{id}/rejeitar` | 158 | Sessão profissional; autorização de recurso a verificar |
| POST | `/teleconsulta/{id}/evoluir` | 159 | Sessão profissional; autorização de recurso a verificar |
| POST | `/teleconsulta/{id}/encerrar` | 160 | Sessão profissional; autorização de recurso a verificar |
| GET | `/teleconsulta/sala-espera/{token}` | 161 | Token URL paciente |
| POST | `/teleconsulta/sala-espera/{token}/dispositivo` | 162 | Token URL paciente |
| POST | `/teleconsulta/sala-espera/{token}/consentir` | 163 | Token URL paciente |
| POST | `/teleconsulta/sala-espera/{token}/entrar` | 164 | Token URL paciente |
| GET | `/teleconsulta/sala-espera/{token}/status` | 165 | Token URL paciente |
| GET | `/teleconsulta/sala/{token}` | 166 | Token URL paciente |
| GET | `/planos` | 167 | Sessão profissional; autorização de recurso a verificar |
| POST | `/planos/novo` | 168 | Sessão profissional; autorização de recurso a verificar |
| GET | `/planos/{id}` | 169 | Sessão profissional; autorização de recurso a verificar |
| POST | `/planos/{id}/refeicoes` | 170 | Sessão profissional; autorização de recurso a verificar |
| POST | `/planos/{id}/refeicoes/{mealId}/remover` | 171 | Sessão profissional; autorização de recurso a verificar |
| POST | `/planos/{id}/itens` | 172 | Sessão profissional; autorização de recurso a verificar |
| POST | `/planos/{id}/itens/{itemId}/remover` | 173 | Sessão profissional; autorização de recurso a verificar |
| POST | `/planos/{id}/substituicoes` | 174 | Sessão profissional; autorização de recurso a verificar |
| POST | `/planos/{id}/substituicoes/{subId}/remover` | 175 | Sessão profissional; autorização de recurso a verificar |
| POST | `/planos/{id}/publicar` | 176 | Sessão profissional; autorização de recurso a verificar |
| POST | `/planos/{id}/duplicar` | 177 | Sessão profissional; autorização de recurso a verificar |
| GET | `/financeiro` | 178 | Sessão profissional; autorização de recurso a verificar |
| GET | `/configuracoes` | 179 | Sessão profissional; autorização de recurso a verificar |
| GET | `/ajuda` | 182 | Sessão profissional; autorização de recurso a verificar |
| GET | `/ajuda/categoria/{slug}` | 183 | Sessão profissional; autorização de recurso a verificar |
| GET | `/ajuda/artigo/{slug}` | 184 | Sessão profissional; autorização de recurso a verificar |
| GET | `/ajuda/abrir-chamado` | 185 | Sessão profissional; autorização de recurso a verificar |
| POST | `/ajuda/abrir-chamado` | 186 | Sessão profissional; autorização de recurso a verificar |
| GET | `/ajuda/meus-chamados` | 187 | Sessão profissional; autorização de recurso a verificar |
| GET | `/ajuda/chamado/{protocol}` | 188 | Sessão profissional; autorização de recurso a verificar |
| POST | `/ajuda/chamado/{protocol}/responder` | 189 | Sessão profissional; autorização de recurso a verificar |
| POST | `/ajuda/chamado/{protocol}/resolver` | 190 | Sessão profissional; autorização de recurso a verificar |
| POST | `/ajuda/chamado/{protocol}/csat` | 191 | Sessão profissional; autorização de recurso a verificar |
| GET | `/ajuda/anexo/{id}` | 192 | Sessão profissional; autorização de recurso a verificar |
| GET | `/suporte/fila` | 195 | Sessão + papel de suporte no controller |
| GET | `/suporte/chamado/{id}` | 196 | Sessão + papel de suporte no controller |
| POST | `/suporte/chamado/{id}/responder` | 197 | Sessão + papel de suporte no controller |
| POST | `/suporte/chamado/{id}/status` | 198 | Sessão + papel de suporte no controller |
| GET | `/chat` | 201 | Sessão profissional; autorização de recurso a verificar |
| GET | `/chat/{id}` | 202 | Sessão profissional; autorização de recurso a verificar |
| POST | `/chat/{id}/enviar` | 203 | Sessão profissional; autorização de recurso a verificar |
| GET | `/chat/{id}/poll` | 204 | Sessão profissional; autorização de recurso a verificar |
| GET | `/chat/anexo/{id}` | 205 | Sessão profissional; autorização de recurso a verificar |
| GET | `/receitas` | 208 | Sessão profissional; autorização de recurso a verificar |
| GET | `/receitas/nova` | 209 | Sessão profissional; autorização de recurso a verificar |
| POST | `/receitas` | 210 | Sessão profissional; autorização de recurso a verificar |
| GET | `/receitas/{id}` | 211 | Sessão profissional; autorização de recurso a verificar |
| GET | `/receitas/{id}/prescrever` | 212 | Sessão profissional; autorização de recurso a verificar |
| POST | `/receitas/{id}/prescrever` | 213 | Sessão profissional; autorização de recurso a verificar |
| POST | `/receitas/{id}/duplicar` | 214 | Sessão profissional; autorização de recurso a verificar |
| POST | `/api/v1/auth/patient-login` | 254 | Login público ou Bearer no logout |
| POST | `/api/v1/auth/patient-logout` | 330 | Login público ou Bearer no logout |
| GET | `/api/v1/patient/home` | 340 | Bearer paciente + tenant; ownership depende do handler |
| POST | `/api/v1/patient/hydration` | 364 | Bearer paciente + tenant; ownership depende do handler |
| POST | `/api/v1/patient/appointment/confirm` | 383 | Bearer paciente + tenant; ownership depende do handler |
| GET | `/api/v1/patient/food-diary` | 396 | Bearer paciente + tenant; ownership depende do handler |
| POST | `/api/v1/patient/food-diary` | 407 | Bearer paciente + tenant; ownership depende do handler |
| GET | `/api/v1/patient/evolution` | 419 | Bearer paciente + tenant; ownership depende do handler |
| POST | `/api/v1/patient/self-metrics` | 430 | Bearer paciente + tenant; ownership depende do handler |
| GET | `/api/v1/patient/chat/messages` | 447 | Bearer paciente + tenant; ownership depende do handler |
| POST | `/api/v1/patient/chat/send` | 465 | Bearer paciente + tenant; ownership depende do handler |
| POST | `/api/v1/patient/chat/read` | 509 | Bearer paciente + tenant; ownership depende do handler |
| GET | `/api/v1/patient/chat/attachment/{id}` | 531 | Bearer paciente + tenant; ownership depende do handler |
| GET | `/api/v1/patient/recipes` | 555 | Bearer paciente + tenant; ownership depende do handler |
| GET | `/api/v1/patient/recipes/{id}` | 565 | Bearer paciente + tenant; ownership depende do handler |
| POST | `/api/v1/patient/recipes/{id}/favorite` | 580 | Bearer paciente + tenant; ownership depende do handler |
| GET | `/api/v1/patient/recipes-shopping-list` | 591 | Bearer paciente + tenant; ownership depende do handler |

## Encerramento e pendências de cobertura

Esta entrega vai além do dashboard e inclui jornadas completas reais, mas **não declara homologação integral do ecossistema**. Paciente nativo, vídeo real, recuperação/cadastro com entrega de e-mail, suporte como operador, receitas preenchidas no browser, séries antropométricas e todos os estados de erro/concorrência exigem sessões adicionais após corrigir bloqueios. O plano de validação especifica como fechar esses gaps sem simulação.

Relatório independente de segurança: [Codex Security — cobertura parcial](/Users/moliveira/.codex/state/plugins/codex-security/scans/Nutri_II/e09e5dfdec17d6557551b9665ea47eee0c9fb70f_20261009T015821Z_nh1fz45n/report.md). Scan `1556c18e-041d-4c06-84fc-2cc2f754b59e`, duas severidades médias e uma baixa. O finalizador registrou alteração da árvore durante o scan (artefatos de auditoria); fontes foram preservadas e hashes relevantes estão em `evidence/source-sha256.json`.
