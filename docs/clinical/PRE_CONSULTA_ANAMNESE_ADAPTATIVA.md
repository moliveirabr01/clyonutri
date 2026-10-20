# Pré-consulta e anamnese adaptativa — NI2-437

**Status:** implementado e testado (domínio, E2E por HTTP, acessibilidade automática); **aguardando revisão clínica do banco de perguntas** e piloto com profissionais. Não marcar como Feita antes disso (regra do épico NI2-435).

## Fluxo

1. **Nutricionista**: em Anamnese → Pré-consulta (`/pacientes/{id}/pre-consulta`), marca os objetivos que o paciente declarou (clínico, esportivo, comportamental) e, se quiser, vincula a consulta agendada (NI2-433).
2. **Proposta**: o sistema sugere as perguntas gerais + as ligadas aos objetivos, com o motivo de cada uma. O nutricionista inclui, retira ou muda a obrigatoriedade. Também pode **ignorar a sugestão** e começar só com as perguntas gerais. Uma pergunta condicional sempre leva junto a pergunta de que depende.
3. **Envio**: gera um link pessoal (token de 256 bits, guardado só como hash SHA-256). A validade vai até o horário da consulta vinculada ou dura 1 a 30 dias (padrão 7). Canal padrão: **WhatsApp** — o sistema monta a mensagem pronta (primeiro nome do paciente, quem envia, link e validade) e abre `wa.me` com o celular do cadastro; número com DDI explícito (`+`/`00`) é usado como está, sem DDI só vale número brasileiro com DDD. Também há e-mail e "só gerar o link". O link aparece uma vez, com botão "Copiar link". Sem `APP_URL` absoluta, WhatsApp e e-mail são recusados (o link sairia relativo). Um novo envio invalida o link anterior, e a revogação cancela o link.
4. **Paciente**: abre o link, que é trocado por sessão e leva a uma URL limpa, sem `Referer` e sem cache. Antes de ver as perguntas, confirma a data de nascimento cadastrada; 5 erros bloqueiam o link por 30 minutos. Pode salvar e continuar depois, inclusive em outro aparelho pelo mesmo link. As perguntas condicionais aparecem só quando se aplicam. Para enviar, aceita o termo de uso das respostas (registrado em `patient_consents` com a versão do termo e o IP).
5. **Revisão**: o nutricionista lê as respostas (relato do paciente, sem interpretação automática), anota a revisão e pode **levar as respostas para um rascunho da anamnese** (NI2-6/NI2-220). O rascunho é marcado como "relato da pré-consulta" e complementa um rascunho existente. Quem finaliza é o nutricionista.

## Decisões

- **Banco versionado** (`QuestionBank::BANK_VERSION = 2026.10-r1`, 42 perguntas). Cada questionário guarda a cópia das perguntas que o paciente viu.
- **Sem diagnóstico automatizado**: a sugestão segue uma regra fixa por objetivo, nada é pontuado e as respostas vão como relato. As perguntas comportamentais usam linguagem neutra.
- **Respostas auditáveis**: cada rascunho e o envio final viram uma linha append-only com SHA-256. A trilha de auditoria registra criação, edição (o que saiu e o que entrou em relação à sugestão), link, abertura, identidade confirmada ou falha, rascunho, envio, revisão e importação, **sem copiar respostas, data de nascimento ou token**.
- **Paciente sem data de nascimento cadastrada**: o link abre só com o token. Recomenda-se cadastrar a data antes do envio.
- **Alergia**: "Não" na pergunta-filtro vira `allergy_assessment = denied` no rascunho; o detalhe só vai para o texto quando a resposta é "Sim". Isso conversa com a trava de alérgenos da NI2-9.

## Testes

- `tests/ni2_437_preconsultation_test.php` (67 verificações): três perfis (glicemia + emagrecimento; desempenho + massa muscular; relação com a comida), perguntas relevantes e ausentes, editar e ignorar sugestão, link, identidade e bloqueio, rascunho e retomada, obrigatórias condicionais, consentimento, imutabilidade após envio, exposição cruzada (outro paciente, outra clínica), reenvio, revogação, expiração, importação e auditoria.
- `tests/ni2_437_real_http_test.cjs` (39 verificações por HTTP real, mais 7 em navegador): `public/index.php` em `php -S` com SQLite isolado. Com `PLAYWRIGHT_MODULE`, `AXE_MODULE` e `CHROMIUM_PATH`, roda também no Chromium: axe-core sem violações WCAG 2.1 A/AA nas três telas do paciente, condicionais, foco no resumo de erros e ausência de rolagem horizontal no celular.

## Checklist para a revisão clínica (Rafael)

- [ ] Texto, ordem e obrigatoriedade de cada pergunta; objetivos que faltam (por exemplo pediatria, idosos, pós-bariátrica, doença renal).
- [ ] Perguntas comportamentais (sensíveis): linguagem, se devem ser opcionais e orientação quando o paciente relata perda de controle.
- [ ] Mapeamento resposta → seção da anamnese.
- [ ] Texto do termo de consentimento (jurídico/LGPD).
- [ ] Piloto com profissionais, sem dados reais antes de contrato e autorização (NI2-435).

## Implantação

1. HostGator, pelo phpMyAdmin: primeiro `database/manual/preflight-2026-10-19-pre-consulta-ni2-437.sql`, depois `migracao-2026-10-19-pre-consulta-ni2-437.sql`, sempre depois das migrações 43 e 44.
2. Ambiente local: `php bin/migrate.php`.
3. Para o e-mail sair com link absoluto, `APP_URL` e o servidor de e-mail precisam estar configurados. Sem isso, o link aparece para copiar.
