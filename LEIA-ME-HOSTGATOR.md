# ClyoNutri na HostGator: passo a passo só pelo cPanel — Versão 19

Versão 19 (16/10/2026): **v18 + Remediação Completa da Auditoria por Navegação Assistida (P01 a P13)**. Tudo o que as versões anteriores (v13 a v18) traziam continua valendo. Novidades e correções homologadas nesta versão:
- **P01 / P07 (Onboarding → Configurações e Disponibilidade da Agenda):**
  - A etapa 3 do onboarding agora salva a disponibilidade semanal (`weekly_availability`) a partir dos dias e turnos selecionados, populando automaticamente o perfil em `/configuracoes` e a disponibilidade da agenda.
  - Seletor dedicado de região do Conselho Regional de Nutricionistas (CRN-1 a CRN-11).
- **P02 (Teleconsulta: Modo Somente Áudio e Bypass de Dispositivos):**
  - Sala de espera do paciente (`/teleconsulta/sala-espera`) com botões "Entrar apenas com áudio" e "Continuar com aviso" para dispositivos sem webcam ou com bloqueio de mídia.
  - Mensagens de erro de permissão e hardware 100% traduzidas em português.
  - Nutricionista visualiza no lobby se o paciente ingressou em modo normal, somente áudio ou com aviso ignorado.
- **P03 (Convite do App do Paciente e Portal Web `/app`):**
  - Tela `/pacientes/{id}/convite-app` modernizada com botões de 1 clique para Copiar Link e Copiar Código de Primeiro Acesso, link direto de WhatsApp e QR Code offline de alta resolução com fallback SVG (sem dependências externas de CDN, compatível com CSP estrito).
  - Nova rota amigável `/app` com fluxo responsivo de primeiro acesso, criação de senha e login do paciente.
- **P04 (LGPD e Termos de Uso no Cadastro):**
  - Checkbox obrigatório de consentimento expresso dos Termos de Uso e Política de Privacidade da ClyoNutri em `/cadastro`, com validação estrita no frontend e backend.
- **P08 / P09 (Ficha da Consulta e Anamnese sem Inflação de Versões):**
  - Cartão de atalho direto para "Anamnese Nutricional" na tela da consulta (`/consulta/{id}`).
  - Botão "Pausar e Voltar à Agenda" preservando o dia consultado (`/agenda?date=YYYY-MM-DD`).
  - Salvamento de rascunhos de anamnese atualizados *in-place* sem duplicar registros ou inflar versões legadas, com mensagens de feedback distintas entre rascunho salvo e anamnese concluída.
- **P10 / P12 / P13 (Antropometria, Preços e Modal de Paciente Duplicado):**
  - Campo de altura no formulário de antropometria aceita metros (ex: `1,70` ou `1.70`) e centímetros (`170`) com `min="0.5"` e `step="any"` sem alertas em inglês do navegador.
  - Resumo de evolução antropométrica acessível em pt-BR com vírgula decimal e unidades explícitas (`kg`, `kg/m²`, `%`).
  - Campo de valor padrão de consulta com `step="any"` para centavos flexíveis.
  - Modal de paciente duplicado exibe alerta de atenção com destaque visual proeminente dentro do corpo do modal.
- **P11 (Equivalência em Gramas no Plano Alimentar):**
  - Editor e impressão do plano alimentar exibem equivalência estimada em gramas (`(~...g)`) ao lado de medidas caseiras que não estejam originalmente em gramas.
- **Migração 41 (`migracao-2026-10-16-ux-v19.sql` e `preflight-2026-10-16-ux-v19.sql`):**
  - Termo de consentimento ativo para atendimento clínico (`clinical_care`, v2026.10-r1);
  - Colunas de branding e endereço em `clinics` (`logo_path`, `address_street`, etc.);
  - Coluna `device_check_mode` em `teleconsultation_sessions`;
  - Medidas caseiras padrão em `food_household_measures` (banana, pão, ovo, arroz, feijão, etc.);
  - Artigos da central de ajuda sobre teleconsulta e app do paciente.

---

## 0. Antes de começar
- **HTTPS válido** em `clyonutri.com` (necessário para câmera/microfone da teleconsulta e segurança de cookies).
- Tenha à mão o zip da versão que está no ar hoje (v10 a v18). Ele é o seu plano de volta.

## 1. Backup (obrigatório)
1. **File Manager** → pasta `/home3/maic6824/clyonutri.com` → baixe o `.env`.
2. Compacte a pasta `storage/` (clique direito → Compress) e baixe o zip.
3. **phpMyAdmin** → banco `maic6824_Clyonutri2` → **Exportar** → Rápido → SQL → Executar. Guarde o arquivo.

## 2. Banco (obrigatório, antes de subir os arquivos)
1. **Passo 2A — Preflight somente-leitura (recomendado):** no **phpMyAdmin**, selecione `maic6824_Clyonutri2`, aba **SQL**, cole o conteúdo de `database/manual/preflight-2026-10-16-ux-v19.sql` e clique em **Executar**. Confira que:
   - `preflight_status = OK — PRONTO PARA MIGRAR`
   - `pronto_para_migrar = 1`
   - `tipos_colunas_40_compativeis = 1`
   - `rascunhos_orfaos_40 = 0`
2. **Passo 2B — Migração Fail-Fast com Rotação Zero-Gap:** na aba **SQL**, cole todo o conteúdo de `database/manual/migracao-2026-10-16-ux-v19.sql` e clique em **Executar**.
3. O resultado tem que ser **`resultado = OK`**, **`preflight_ok = 1`** e **`dados_rascunho_preservados = 1`**.
   - É **cumulativa, idempotente e fail-fast**: se qualquer pré-condição falhar (`@preflight_ok = 0`), o script interrompe antes de executar qualquer DDL/DML.
   - **Não contém nenhum comando `DELETE`, `DROP TABLE` ou `TRUNCATE`.**
   - **Garantia de zero perda de dados:** `@drafts_count_before = @drafts_count_after`.
4. Confira as colunas do resultado final:
   - `resultado` = **OK**
   - `tabela_rascunho_consulta` = **1**
   - `fks_rascunho_consulta` = **2** (ambas com `DELETE_RULE = RESTRICT`)
   - `rascunhos_orfaos_40` = **0**
   - `dados_rascunho_preservados` = **1**
   - `artigo_reagendar` = **1**

## 3. Arquivos
1. Envie `clyonutri-hostgator-2026-10-16-v19.zip` para `/home3/maic6824/clyonutri.com`, clique em **Extract** (confirme sobrescrever) e apague o zip.
2. Confira que o **`.env` e a pasta `storage/` continuam lá**. O zip **não** traz `.env`, banco nem senhas.
3. Os dois `.htaccess` (raiz e `public/`) precisam ser sobrescritos.
4. **Segurança:** apague o `.env.hostgator` que ficou na pasta (é só modelo). Se ainda não fez, troque a senha do banco e a do e-mail `nao-responda@` (os pacotes v7 a v11 traziam senhas nesse arquivo).

## 4. `.env`
Nada novo na v19. Valem as variáveis da v12/v13 (`APP_KEY`, `TELECONSULT_PROVIDER=webrtc_p2p` etc.).

## 5. Checklist de teste (aba anônima, Ctrl+F5)
1. `/`, `/login` e o login funcionam; `/health` mostra só `{"status":"ok"}`.
2. **Readiness CLI (via SSH ou Terminal cPanel):** `php bin/healthcheck.php --readiness` exibe `SAUDÁVEL (Healthy) ✓ Pronto para operação e release`.
3. **P04 (Cadastro & LGPD):** abra `/cadastro` → confirme o checkbox obrigatório de Termos e Privacidade.
4. **P01 / P07 (Onboarding & Configurações):** no perfil em `/configuracoes`, confira seletor de região do CRN e horários/dias preenchidos.
5. **P03 (Convite App & Portal `/app`):** abra `/pacientes/{id}/convite-app` → botões Copiar Link, Copiar Código e QR Code; teste rota `/app`.
6. **P08 / P09 (Consulta & Anamnese):** abra uma consulta → confira atalho "Anamnese Nutricional" e botão "Pausar e Voltar à Agenda". Salve um rascunho de anamnese e confira a mensagem de rascunho salvo.
7. **P10 (Altura & Antropometria):** na avaliação física, digite `1,70` na altura → salva sem rejeição nem erro em inglês.
8. **P11 (Equivalência em Gramas):** no editor de plano alimentar, confira a exibição de gramas estimadas ao lado da medida caseira.
9. **P02 (Teleconsulta: Áudio e Bypass):** na sala de espera da teleconsulta (`/teleconsulta/sala-espera`), confira os botões de entrar apenas com áudio e continuar com aviso.
10. **Teleconsulta WebRTC:** fluxo completo entre dois navegadores/aparelhos (sala de espera, aceite de termo, admissão, vídeo e áudio bidirecionais).

## 6. Se algo der errado (voltar)
- **Voltar para a versão anterior:** suba o zip da versão anterior (`v18.zip` ou `v17.zip`) e extraia por cima. O `.env` continua igual.
- **O banco não precisa ser desfeito.** As tabelas e colunas adicionadas são retrocompatíveis com as versões anteriores.
- **Em último caso**, restaure o backup do banco do passo 1 (phpMyAdmin → Importar).
