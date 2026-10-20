# ClyoNutri na HostGator: passo a passo só pelo cPanel (versão 21)

Versão 21 (20/10/2026), commit `f9c6b8c`. Ela traz tudo o que as versões v13 a v19 já traziam. Novidades:

- **Autoagendamento (NI2-433 / NI2-434, migration 42):** página pública `/agendar` com reserva temporária de horário, confirmação, cancelamento e reagendamento pelo paciente.
- **Planos alimentares, correções do code review NI2-9 (migration 43):**
  - **Peso em gramas gravado:** o peso de cada item e de cada substituição fica salvo. Quando a medida caseira não está cadastrada para o alimento, o plano mostra "peso estimado".
  - **Publicação atômica com auditoria:** publicar um plano que esbarra em alergia agora exige justificativa (mínimo de 10 caracteres).
  - **Trava de alérgenos fail-closed:** alimento sem informação de alérgenos conta como "não verificado".
  - **Limite de 5 kg por porção.**
  - **App do paciente:** lê exatamente a versão publicada do plano.
- **Dobras cutâneas (NI2-439, migration 44):** `/pacientes/{id}/antropometria/dobras`, com protocolos versionados, faixas de validade, %GC e exportação LGPD.
- **Pré-consulta (NI2-437, migration 45):**
  - **Envio:** em Anamnese → Pré-consulta, o nutricionista envia ao paciente um link pessoal com as perguntas sugeridas pelo objetivo.
  - **Paciente:** confirma a data de nascimento, salva e continua depois, e aceita o termo de uso das respostas.
  - **Revisão:** as respostas viram rascunho da anamnese.
  - ⚠ **O banco de perguntas ainda aguarda revisão clínica.** Não use com pacientes reais antes disso (NI2-435).
- **Central de Ajuda:** os 7 artigos padrão passam a ser garantidos um a um. Numa instalação nova, a v19 criava só os 3 de teleconsulta.
- **Anamnese:** finalizar um rascunho promove o próprio rascunho, sem criar uma versão extra.

---

## 0. Antes de começar
- O **HTTPS precisa estar válido** em `clyonutri.com`.
- Tenha à mão o **zip da v19**. Ele é o seu plano de volta.

## 1. Backup (obrigatório)
1. No **File Manager**, abra a pasta `/home3/maic6824/clyonutri.com` e baixe o `.env`.
2. Compacte a pasta `storage/` (clique direito → Compress) e baixe o zip.
3. No **phpMyAdmin**, abra o banco `maic6824_Clyonutri2`, vá em **Exportar** → Rápido → SQL → Executar e guarde o arquivo.

## 2. Banco (obrigatório, ANTES de subir os arquivos)
No **phpMyAdmin**, selecione `maic6824_Clyonutri2` e abra a aba **SQL**. Rode os scripts **na ordem abaixo**: cole o conteúdo do arquivo e clique em **Executar**. Todos os scripts estão em `database/manual/` dentro do zip.

Cada preflight é **somente leitura**. Rode a migração do mesmo bloco só se o preflight disser que está pronto.

| # | Preflight (somente leitura) | Esperado | Migração | Esperado |
|---|---|---|---|---|
| 42 | `preflight-2026-10-16-autoagendamento-v20.sql` | `PREFLIGHT_OK…` e `estrutura_antiga = 0` | `migracao-2026-10-16-autoagendamento-v20.sql` | `OK — migração 42 aplicada` |
| 43 | `preflight-2026-10-19-planos-ni2-9.sql` | `OK — PRONTO PARA MIGRAR`, `versoes_repetidas = 0` | `migracao-2026-10-19-planos-ni2-9.sql` | `OK — migração 43 aplicada` |
| 44 | `preflight-2026-10-19-dobras-ni2-439.sql` | `OK — PRONTO PARA MIGRAR` | `migracao-2026-10-19-dobras-ni2-439.sql` | `OK — migração 44 aplicada` |
| 45 | `preflight-2026-10-19-pre-consulta-ni2-437.sql` | `OK — PRONTO PARA MIGRAR` | `migracao-2026-10-19-pre-consulta-ni2-437.sql` | `OK — migração 45 aplicada` |

**Como os scripts se comportam:**
- **Se já rodou antes:** se o preflight mostrar `migracao_ja_aplicada = 1`, pule a migração daquele bloco. Rodar de novo também é seguro, porque todos os scripts são idempotentes.
- **O que eles nunca fazem:** nenhum script usa `DELETE`, `DROP` ou `TRUNCATE`.
- **Se aparecer FALHA:** pare e não suba os arquivos. Em especial, `estrutura_antiga = 1` no bloco 42 significa que a versão antiga e defeituosa do script v20 chegou a rodar. Nesse caso fale com o suporte técnico antes de seguir.
- **Se o banco ainda não estiver na v19:** rode primeiro `preflight-2026-10-16-ux-v19.sql` + `migracao-2026-10-16-ux-v19.sql`.

Os scripts foram testados em MySQL 8.0 sobre um banco no estado da v19. O resultado ficou igual a uma instalação feita pelas migrations, e o `migrate.php` não encontrou nada pendente.

## 3. Arquivos
1. Envie `clyonutri-hostgator-2026-10-20-v21.zip` para `/home3/maic6824/clyonutri.com`, clique em **Extract** (confirme sobrescrever) e depois apague o zip.
2. Confira que o **`.env` e a pasta `storage/` continuam lá**. O zip **não** traz `.env`, banco nem senhas.
3. Os dois `.htaccess` (raiz e `public/`) precisam ser sobrescritos.
4. **Segurança:** apague o `.env.hostgator` que ficou na pasta. Ele é só um modelo.

## 4. `.env`
Não há variável nova. A pré-consulta usa as que já existem:
- `APP_URL=https://clyonutri.com`, para o link do e-mail sair completo.
- O e-mail (`MAIL_*`) já configurado. Sem e-mail, o link aparece na tela para copiar e mandar por outro canal.

## 5. Checklist de teste (aba anônima, Ctrl+F5)
1. `/`, `/login` e o login funcionam. `/up` mostra `{"status":"ok"}`.
2. **Planos:** abra um plano e adicione um alimento em medida caseira. Ao lado deve aparecer `(~…g)`, ou `peso estimado` quando a medida for genérica. Publique e imprima.
3. **Plano com alergia:** com um paciente com alergia registrada, publicar um plano com alimento suspeito deve pedir justificativa.
4. **Dobras:** na ficha do paciente, vá em Antropometria → Dobras cutâneas, registre uma avaliação e confira o %GC.
5. **Pré-consulta:** em Anamnese → Pré-consulta, marque um objetivo e gere o link. Abra o link numa aba anônima, confirme a data de nascimento, responda e envie. De volta ao painel, revise e leve as respostas para o rascunho da anamnese.
6. **Autoagendamento:** abra `/agendar` e confira os horários disponíveis.
7. **Central de Ajuda:** confira que aparecem os artigos de planos, antropometria, anamnese, convite, app e LGPD, e não só os de teleconsulta.

## 6. Se algo der errado (voltar)
- **Arquivos:** suba o zip da v19 e extraia por cima. O `.env` continua igual.
- **Banco:** não precisa desfazer nada, porque as tabelas e colunas novas não atrapalham a v19.
- **Em último caso:** restaure o backup do passo 1 (phpMyAdmin → Importar).
